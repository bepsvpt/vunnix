<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class HealthCheckController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'postgresql' => $this->checkPostgresql(),
            'redis' => $this->checkRedis(),
            'queue_worker' => $this->checkQueueWorker(),
            'reverb' => $this->checkReverb(),
            'disk' => $this->checkDisk(),
        ];

        $healthy = collect($checks)->every(fn (array $check): bool => $check['status'] === 'ok');

        return response()->json([
            'status' => $healthy ? 'healthy' : 'unhealthy',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    /** @return array<string, mixed> */
    private function checkPostgresql(): array
    {
        try {
            DB::connection()->getPdo();
            DB::select('SELECT 1');

            return ['status' => 'ok'];
        } catch (Throwable $e) {
            return $this->failCheck('postgresql', $e);
        }
    }

    /** @return array<string, mixed> */
    private function checkRedis(): array
    {
        try {
            /** @var \Illuminate\Cache\RedisStore $store */ // @phpstan-ignore varTag.type
            $store = Cache::store('redis');
            $result = $store->getRedis()->connection()->ping();

            if ($result) {
                return ['status' => 'ok'];
            }

            return $this->failCheck('redis', 'Redis ping returned falsy');
        } catch (Throwable $e) {
            return $this->failCheck('redis', $e);
        }
    }

    /** @return array<string, mixed> */
    private function checkQueueWorker(): array
    {
        try {
            $cacheKey = 'health:queue_worker:heartbeat';
            $heartbeat = Cache::get($cacheKey);

            if ($heartbeat === null) {
                return $this->failCheck('queue_worker', 'No queue worker heartbeat detected');
            }

            $lastBeat = \Carbon\Carbon::parse($heartbeat);
            $staleThreshold = now()->subMinutes(5);

            if ($lastBeat->lt($staleThreshold)) {
                return $this->failCheck('queue_worker', 'Queue worker heartbeat is stale');
            }

            return ['status' => 'ok'];
        } catch (Throwable $e) {
            return $this->failCheck('queue_worker', $e);
        }
    }

    /** @return array<string, mixed> */
    private function checkReverb(): array
    {
        try {
            $host = config('reverb.servers.reverb.host', '0.0.0.0');
            $port = config('reverb.servers.reverb.port', 8080);

            $response = Http::timeout(3)->get("http://{$host}:{$port}/");

            if ($response->successful() || $response->status() === 426) {
                return ['status' => 'ok'];
            }

            return $this->failCheck('reverb', "Reverb returned HTTP {$response->status()}");
        } catch (Throwable $e) {
            return $this->failCheck('reverb', $e);
        }
    }

    /** @return array<string, mixed> */
    private function checkDisk(): array
    {
        try {
            $path = storage_path();
            $freeBytes = disk_free_space($path);
            $totalBytes = disk_total_space($path);

            if ($freeBytes === false || $totalBytes === false) {
                return $this->failCheck('disk', 'Unable to read disk space');
            }

            $usedPercent = round((1 - $freeBytes / $totalBytes) * 100, 1);
            $threshold = (float) config('health.disk_usage_threshold', 90);

            if ($usedPercent >= $threshold) {
                return $this->failCheck('disk', "Disk usage at {$usedPercent}% (threshold: {$threshold}%)");
            }

            return [
                'status' => 'ok',
                'usage_percent' => $usedPercent,
            ];
        } catch (Throwable $e) {
            return $this->failCheck('disk', $e);
        }
    }

    /** @return array{status: 'fail', error: 'Check failed'} */
    private function failCheck(string $check, Throwable|string $reason): array
    {
        $errorMessage = $reason instanceof Throwable ? $reason->getMessage() : $reason;

        Log::warning('Health check failed', [
            'check' => $check,
            'error' => $errorMessage,
        ]);

        return ['status' => 'fail', 'error' => 'Check failed'];
    }
}
