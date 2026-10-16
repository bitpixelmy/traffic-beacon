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
 * beacon cannot slow down or break the site.
 */
class RecordTrafficBeacon
{
    /**
     * Methods the dashboard accepts; anything else (WebDAV, TRACE, ...) is skipped
     * so one odd request can never get a whole batch rejected.
     */
    private const array METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly BeaconBuffer $buffer,
        private readonly array $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
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

        $startedAt = defined('LARAVEL_START') ? LARAVEL_START : (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));

        $beacon = [
            'method' => $request->getMethod(),
            'status' => $response->getStatusCode(),
            'path' => $this->pathGroup($request),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'ip' => $request->ip(),
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

        $sampleRate = (float) $this->config['sample_rate'];

        return $sampleRate >= 1 || mt_rand() / mt_getrandmax() < $sampleRate;
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
