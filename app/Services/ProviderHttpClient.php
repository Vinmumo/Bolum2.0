<?php

namespace App\Services;

use App\Exceptions\ProviderUnavailable;
use App\Models\ProviderCall;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ProviderHttpClient
{
    public function get(string $source, string $operation, string $url, array $headers, array $query, Closure $normalize, int $ttl = 300, ?string $cacheContext = null): array
    {
        $key = 'football:http:'.hash('sha256', json_encode([$url, $query, $headers, $cacheContext]));
        if (($cached = Cache::get($key)) !== null) {
            $this->record($source, $operation, 'cached', null, 0, true, 1);

            return $cached;
        }
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $start = hrtime(true);
            $status = null;
            $category = 'network_error';
            $retry = true;
            try {
                $response = Http::acceptJson()->withHeaders($headers)->connectTimeout(3)->timeout(8)->get($url, $query);
                $status = $response->status();
                $category = $status === 429 ? 'rate_limited' : 'http_error';
                $retry = $status === 429 || $status >= 500;
                if ($response->successful()) {
                    $category = 'invalid_response';
                    $retry = false;
                    $data = $normalize($response->json());
                    Cache::put($key, $data, $ttl);
                    $this->record($source, $operation, 'success', $status, (int) ((hrtime(true) - $start) / 1e6), false, $attempt);

                    return $data;
                }
            } catch (\Throwable) {
                // Operational records intentionally exclude URLs, headers, bodies and exception messages.
            }
            $this->record($source, $operation, $category, $status, (int) ((hrtime(true) - $start) / 1e6), false, $attempt);
            if (! $retry || $attempt === 2) {
                break;
            }
            usleep(200000);
        }
        throw new ProviderUnavailable('Football provider unavailable or returned invalid data.');
    }

    private function record(string $source, string $operation, string $status, ?int $httpStatus, int $duration, bool $cached, int $attempt): void
    {
        ProviderCall::create(['source' => $source, 'operation' => $operation, 'status' => $status, 'http_status' => $httpStatus, 'duration_ms' => $duration, 'cache_hit' => $cached, 'attempt' => $attempt]);
    }
}
