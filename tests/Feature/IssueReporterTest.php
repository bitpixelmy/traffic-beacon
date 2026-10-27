<?php

use Bitpixel\TrafficBeacon\IssueReporter;
use Bitpixel\TrafficBeacon\TrafficBeaconServiceProvider;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ItemNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Boot the provider as it boots in a deployed site, where the app isn't
 * running unit tests, with a fresh reporter.
 */
function bootReporterInProduction(): IssueReporter
{
    app()->forgetInstance(IssueReporter::class);
    // This package's root stands in for the site's, so paths come out relative.
    app()->setBasePath(dirname(__DIR__, 2));
    app()->instance('env', 'production');
    (new TrafficBeaconServiceProvider(app()))->boot();

    return app(IssueReporter::class);
}

function throwFromHere(): RuntimeException
{
    return new RuntimeException('Order 42 could not be paid', 7, new LogicException('Gateway said no'));
}

beforeEach(function () {
    Http::fake(['dashboard.test/*' => Http::response(['accepted' => 1], 202)]);
});

it('sends what the exception handler reports to the issues endpoint', function () {
    bootReporterInProduction();

    app(ExceptionHandler::class)->report(throwFromHere());
    app()->terminate();

    Http::assertSent(function (ClientRequest $request): bool {
        $issue = $request['issues'][0];

        return $request->url() === 'https://dashboard.test/ingest/issues'
            && $request->hasHeader('Authorization', 'Bearer bpx_test')
            && $issue['class'] === RuntimeException::class
            && $issue['message'] === 'Order 42 could not be paid'
            && $issue['code'] === '7'
            && $issue['line'] === (new ReflectionFunction('throwFromHere'))->getStartLine() + 2
            && $issue['occurrences'] === 1
            && $issue['previous'][0]['class'] === LogicException::class
            && $issue['context']['type'] === 'command'
            && $issue['environment']['app_env'] === 'production'
            && $issue['snippet']['file'] === 'tests/Feature/IssueReporterTest.php'
            && $issue['snippet']['line'] === $issue['line']
            && $issue['snippet']['lines'] !== [];
    });
});

it('still lets the handler log the exception', function () {
    Log::spy();
    bootReporterInProduction();

    app(ExceptionHandler::class)->report(throwFromHere());

    Log::shouldHaveReceived('error')->once();
});

it('marks vendor frames and keeps the stack innermost first', function () {
    $reporter = bootReporterInProduction();

    $reporter->capture(throwFromHere());

    $trace = $reporter->pending()[0]['trace'];

    expect($trace[0]['file'])->toEndWith('IssueReporterTest.php')
        ->and($trace[0]['function'])->toBe('throwFromHere')
        ->and($trace[0]['app'])->toBeTrue()
        ->and(collect($trace)->where('app', false)->isNotEmpty())->toBeTrue()
        ->and(collect($trace)->every(fn (array $frame): bool => ! array_key_exists('args', $frame)))->toBeTrue();
});

it('shows the app code that led to an exception thrown inside vendor', function () {
    $reporter = bootReporterInProduction();

    try {
        $line = __LINE__ + 1;
        collect()->firstOrFail();
    } catch (ItemNotFoundException $exception) {
        $reporter->capture($exception);
    }

    $issue = $reporter->pending()[0];

    expect($issue['file'])->toStartWith('vendor/laravel/framework/')
        ->and($issue['trace'][0]['app'])->toBeFalse()
        ->and($issue['snippet']['file'])->toBe('tests/Feature/IssueReporterTest.php')
        ->and($issue['snippet']['line'])->toBe($line)
        ->and($issue['snippet']['lines'])->toContain('        collect()->firstOrFail();');
});

it('skips exceptions the handler does not report', function () {
    bootReporterInProduction();

    app(ExceptionHandler::class)->report(new NotFoundHttpException);
    app(ExceptionHandler::class)->report(ValidationException::withMessages(['email' => 'Required']));
    app()->terminate();

    Http::assertNothingSent();
});

it('sends a repeated exception once, counted, and holds back repeats within the throttle', function () {
    $reporter = bootReporterInProduction();
    $make = fn () => new RuntimeException('Same place');

    $reporter->capture($make());
    $reporter->capture($make());
    $reporter->flush();

    // A later request inside the throttle window: counted, not sent.
    $reporter->capture($make());
    $reporter->capture($make());
    expect($reporter->flush())->toBe(0);

    $this->travel(11)->seconds();
    $reporter->capture($make());
    $reporter->flush();

    $sent = Http::recorded()->map(fn (array $pair) => $pair[0]['issues'][0]['occurrences'])->all();

    expect($sent)->toBe([2, 3]);
});

it('sends every exception when the throttle is off', function () {
    config()->set('traffic-beacon.issue_throttle', 0);
    $reporter = bootReporterInProduction();

    foreach ([1, 2] as $attempt) {
        $reporter->capture(new RuntimeException('Again'));
        $reporter->flush();
    }

    Http::assertSentCount(2);
});

it('sends from a queue worker between jobs', function () {
    $reporter = bootReporterInProduction();

    $reporter->capture(throwFromHere());
    event(new Looping('redis', 'default'));

    Http::assertSentCount(1);
});

it('derives the issues endpoint from the traffic endpoint unless one is set', function () {
    config()->set('traffic-beacon.endpoint', 'https://dashboard.test/ingest/');
    expect(bootReporterInProduction()->endpoint())->toBe('https://dashboard.test/ingest/issues');

    config()->set('traffic-beacon.issues_endpoint', 'https://errors.test/in');
    expect(bootReporterInProduction()->endpoint())->toBe('https://errors.test/in');
});

it('captures nothing when turned off', function () {
    config()->set('traffic-beacon.capture_exceptions', false);
    bootReporterInProduction();

    app(ExceptionHandler::class)->report(throwFromHere());
    app()->terminate();

    Http::assertNothingSent();
});

it('never throws when the dashboard is down', function () {
    Http::fake(fn () => throw new RuntimeException('Connection refused'));
    $reporter = bootReporterInProduction();

    $reporter->capture(throwFromHere());

    expect($reporter->flush())->toBe(0)
        ->and($reporter->pending())->toBe([]);
});

it('sends after the response, with the route and URL without the query string', function () {
    $reporter = bootReporterInProduction();

    $this->app['router']->get('orders/{order}', function () use ($reporter) {
        $reporter->capture(new RuntimeException('Boom'));

        return 'ok';
    })->name('orders.show');

    $this->get('/orders/42?secret=abc', ['User-Agent' => 'Mozilla/5.0']);

    // Sent as the request terminated.
    expect($reporter->pending())->toBe([])
        ->and(Http::recorded(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/issues'))->first()[0]['issues'][0]['context'])->toMatchArray([
            'type' => 'request',
            'method' => 'GET',
            'url' => 'http://localhost/orders/42',
            'route' => '/orders/{order}',
            'route_name' => 'orders.show',
            'user_id' => null,
            'user_agent' => 'Mozilla/5.0',
        ]);
});
