<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class BackupDatabase extends Command
{
    protected $signature = 'backup:database
        {--path= : Override backup directory (default: storage/backups)}
        {--retention=30 : Days to retain backups}';

    protected $description = 'Run pg_dump backup and prune old backups beyond retention period';

    public function handle(): int
    {
        $backupDir = $this->option('path') ?? storage_path('backups');

        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp = now()->format('Y-m-d_His');
        $database = config('database.connections.pgsql.database');
        $safeDbName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $database);
        $filename = "{$safeDbName}_{$timestamp}.sql.gz";
        $filepath = "{$backupDir}/{$filename}";

        $host = config('database.connections.pgsql.host');
        $port = config('database.connections.pgsql.port');
        $username = config('database.connections.pgsql.username');
        $password = config('database.connections.pgsql.password');

        $this->info("Starting backup of database '{$database}'...");

        $result = Process::env(['PGPASSWORD' => $password])
            ->timeout(600)
            ->run("pg_dump -h {$host} -p {$port} -U {$username} -Z 9 --file={$filepath} {$database}");

        if (! $result->successful()) {
            $this->error("Backup failed: {$result->errorOutput()}");

            // Clean up partial file
            if (file_exists($filepath)) {
                unlink($filepath);
            }

            return self::FAILURE;
        }

        $size = file_exists($filepath) ? filesize($filepath) : 0;
        if ($size === false) {
            $size = 0;
        }
        $this->info("Backup completed: {$filename} (".number_format($size / 1024).' KB)');

        $this->pruneOldBackups($backupDir, (int) $this->option('retention'));

        return self::SUCCESS;
    }

    private function pruneOldBackups(string $backupDir, int $retentionDays): void
    {
        $cutoff = now()->subDays($retentionDays);
        $pruned = 0;

        $files = glob("{$backupDir}/*.sql.gz");
        foreach ($files !== false ? $files : [] as $file) {
            if (filemtime($file) < $cutoff->timestamp) {
                unlink($file);
                $pruned++;
            }
        }

        if ($pruned > 0) {
            $this->info("Pruned {$pruned} backup(s) older than {$retentionDays} days.");
        }
    }
}
