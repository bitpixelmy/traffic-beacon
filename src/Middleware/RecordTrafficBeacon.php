<?php

namespace Bitpixel\TrafficBeacon\Middleware;

use Bitpixel\TrafficBeacon\BeaconBuffer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records a beacon for every request once the response has been sent
 * (terminable middleware), then flushes the shared buffer if it is due.
 *
 * The path is reported as the matched route pattern (e.g. `posts/{post}`), never
 * the concrete URL, so no IDs or query strings leave the site. Cloudflare's
 * visitor location headers are forwarded when the zone has them enabled.
 *
 * Recording runs after the response has been sent and never throws, so the
 * beacon cannot slow down or break the site. Bots, uptime monitors and
 * background requests (Livewire updates, broadcasting auth) are skipped so the
 * globe shows people, not machinery.
 */
class RecordTrafficBeacon
{
    /**
     * Methods the dashboard accepts; anything else (WebDAV, TRACE, ...) is skipped
     * so one odd request can never get a whole batch rejected.
     */
    private const array METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /**
     * User agents of crawlers, link previewers, uptime monitors and HTTP libraries.
     */
    private const string BOT_PATTERN = '/bot\b|bot\/|crawl|spider|slurp|mediapartners|bingpreview|facebookexternalhit|embedly|quora link preview|whatsapp|telegram|discord|skypeuripreview|pingdom|uptime|statuscake|site24x7|monitor|headless|lighthouse|pagespeed|gtmetrix|curl\/|wget\/|python-|go-http-client|okhttp|java\/|libwww|httpclient|axios\/|node-fetch|guzzlehttp|postman|insomnia/i';

    private const string STARTED_AT = 'traffic_beacon.started_at';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly BeaconBuffer $buffer,
        private readonly array $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::STARTED_AT, microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $this->record($request, $response);
        } catch (Throwable $exception) {
            Log::warning('Traffic beacon could not record a request.', ['error' => $exception->getMessage()]);
        }
    }

    private function record(Request $request, Response $response): void
    {
        if (! $this->shouldRecord($request)) {
            return;
        }

        $beacon = [
            'method' => $request->getMethod(),
            'status' => $response->getStatusCode(),
            'path' => $this->pathGroup($request),
            'duration_ms' => (int) round((microtime(true) - $this->startedAt($request)) * 1000),
            'ip' => $this->visitorIp($request),
            'timestamp' => time(),
        ];

        if ($geo = $this->cloudflareLocation($request)) {
            $beacon['geo'] = $geo;
        }

        $this->buffer->push($beacon);
        $this->buffer->flushIfDue();
    }

    private function shouldRecord(Request $request): bool
    {
        if (! $this->config['enabled'] || ! $this->config['endpoint'] || ! $this->config['token']) {
            return false;
        }

        if (! in_array($request->getMethod(), self::METHODS, true) || $request->is(...$this->config['except'])) {
            return false;
        }

        if (($this->config['ignore_bots'] ?? true) && $this->isBot($request)) {
            return false;
        }

        $sampleRate = (float) $this->config['sample_rate'];

        return $sampleRate >= 1 || mt_rand() / mt_getrandmax() < $sampleRate;
    }

    /**
     * When the request started. PHP-FPM's REQUEST_TIME_FLOAT includes framework
     * boot time; under Octane it (and LARAVEL_START) belong to the long-lived
     * worker, so a value more than a few seconds before this middleware ran is
     * stale and the middleware's own start time is used instead.
     */
    private function startedAt(Request $request): float
    {
        $handledAt = $request->attributes->get(self::STARTED_AT);
        $handledAt = is_float($handledAt) ? $handledAt : microtime(true);
        $requestTime = (float) $request->server('REQUEST_TIME_FLOAT', 0);

        return $requestTime > 0 && $requestTime <= $handledAt && $handledAt - $requestTime < 5 ? $requestTime : $handledAt;
    }

    /**
     * The visitor's IP. By default this is Laravel's `$request->ip()`, which is only
     * the real visitor when the site trusts its proxies (TrustProxies). Sites behind
     * Cloudflare or a load balancer can instead name the header the proxy sets,
     * e.g. TRAFFIC_BEACON_IP_HEADER=CF-Connecting-IP. Only set it when every request
     * really comes through that proxy, because clients can forge headers.
     */
    private function visitorIp(Request $request): ?string
    {
        $header = $this->config['ip_header'] ?? null;

        if (is_string($header) && $header !== '') {
            $value = trim(explode(',', (string) $request->header($header))[0]);

            if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
                return $value;
            }
        }

        return $request->ip();
    }

    private function isBot(Request $request): bool
    {
        $userAgent = (string) $request->userAgent();

        return $userAgent === '' || preg_match(self::BOT_PATTERN, $userAgent) === 1;
    }

    private function pathGroup(Request $request): string
    {
        $uri = $request->route()?->uri();

        return $uri === null ? 'unmatched' : Str::limit('/'.ltrim($uri, '/'), 250, '');
    }

    /**
     * @return array<string, float|string>|null
     */
    private function cloudflareLocation(Request $request): ?array
    {
        $latitude = $request->header('CF-IPLatitude');
        $longitude = $request->header('CF-IPLongitude');

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        $city = $request->header('CF-IPCity');
        $country = $request->header('CF-IPCountry');

        return array_filter([
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
            'city' => is_string($city) ? Str::limit($city, 100, '') : null,
            'country' => is_string($country) && strlen($country) === 2 ? $country : null,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
