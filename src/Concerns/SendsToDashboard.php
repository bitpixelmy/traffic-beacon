<?php

namespace Bitpixel\TrafficBeacon\Concerns;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts a report to one of the dashboard's ingest endpoints with the site's token.
 * A failure is logged and the report dropped: reporting must never break the site.
 *
 * @property-read array<string, mixed> $config
 */
trait SendsToDashboard
{
    /**
     * Where the report goes: the configured endpoint, or the traffic endpoint with the
     * path added (https://dashboard/ingest/jobs).
     */
    protected function endpointFor(string $key, string $path): string
    {
        $endpoint = $this->config[$key] ?? null;

        return filled($endpoint) ? (string) $endpoint : rtrim((string) $this->config['endpoint'], '/').'/'.$path;
    }

    protected function hasDashboard(): bool
    {
        return filled($this->config['endpoint'] ?? null) && filled($this->config['token'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function send(string $endpoint, array $payload, string $what): bool
    {
        try {
            $response = Http::withToken((string) $this->config['token'])
                ->acceptJson()
                ->connectTimeout(1)
                ->timeout(max(1, (int) ($this->config['timeout'] ?? 2)))
                ->post($endpoint, $payload);

            if ($response->failed()) {
                Log::warning("Traffic beacon {$what} rejected by the dashboard.", [
                    'status' => $response->status(),
                    'hint' => $response->status() === 401 ? 'Check TRAFFIC_BEACON_TOKEN.' : null,
                ]);

                return false;
            }

            return true;
        } catch (Throwable $exception) {
            Log::warning("Traffic beacon {$what} could not be sent.", ['error' => $exception->getMessage()]);

            return false;
        }
    }
}
