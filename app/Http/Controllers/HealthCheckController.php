<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
            return ['status' => 'fail', 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private function checkRedis(): array
    {
        try {
            /** @var \Illuminate\Cache\RedisStore $store */
            $store = Cache::store('redis');
            $result = $store->getRedis()->connection()->ping();

            if ($result) {
                return ['status' => 'ok'];
            }

            return ['status' => 'fail', 'error' => 'Redis ping returned falsy'];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private function checkQueueWorker(): array
    {
        try {
            $cacheKey = 'health:queue_worker:heartbeat';
            $heartbeat = Cache::get($cacheKey);

            if ($heartbeat === null) {
                return ['status' => 'fail', 'error' => 'No queue worker heartbeat detected'];
            }

            $lastBeat = \Carbon\Carbon::parse($heartbeat);
            $staleThreshold = now()->subMinutes(5);

            if ($lastBeat->lt($staleThreshold)) {
                return ['status' => 'fail', 'error' => 'Queue worker heartbeat is stale'];
            }

            return ['status' => 'ok'];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'error' => $e->getMessage()];
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

            return ['status' => 'fail', 'error' => "Reverb returned HTTP {$response->status()}"];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'error' => $e->getMessage()];
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
                return ['status' => 'fail', 'error' => 'Unable to read disk space'];
            }

            $usedPercent = round((1 - $freeBytes / $totalBytes) * 100, 1);
            $threshold = (float) config('health.disk_usage_threshold', 90);

            if ($usedPercent >= $threshold) {
                return [
                    'status' => 'fail',
                    'error' => "Disk usage at {$usedPercent}% (threshold: {$threshold}%)",
                ];
            }

            return [
                'status' => 'ok',
                'usage_percent' => $usedPercent,
            ];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'error' => $e->getMessage()];
        }
    }
}
