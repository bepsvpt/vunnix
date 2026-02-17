<?php

use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->backupDir = storage_path('backups/test_'.uniqid());
});

afterEach(function (): void {
    // Clean up test backup directory
    if (is_dir($this->backupDir)) {
        foreach (glob("{$this->backupDir}/*") as $file) {
            unlink($file);
        }
        rmdir($this->backupDir);
    }
});

it('creates backup directory if it does not exist', function (): void {
    Process::fake([
        '*pg_dump*' => Process::result(output: ''),
    ]);

    $this->artisan('backup:database', ['--path' => $this->backupDir])
        ->assertSuccessful();

    expect(is_dir($this->backupDir))->toBeTrue();
});

it('runs pg_dump with correct connection parameters', function (): void {
    Process::fake([
        '*pg_dump*' => Process::result(output: ''),
    ]);

    $this->artisan('backup:database', ['--path' => $this->backupDir])
        ->assertSuccessful();

    $host = config('database.connections.pgsql.host');
    $port = config('database.connections.pgsql.port');
    $username = config('database.connections.pgsql.username');
    $database = config('database.connections.pgsql.database');

    Process::assertRan(function ($process) use ($host, $port, $username, $database): bool {
        $command = $process->command;

        return str_contains($command, "pg_dump -h {$host} -p {$port} -U {$username} -Z 9")
            && str_contains($command, $database);
    });
});

it('targets a gzipped backup file via pg_dump --file flag', function (): void {
    Process::fake([
        '*pg_dump*' => Process::result(output: ''),
    ]);

    $this->artisan('backup:database', ['--path' => $this->backupDir])
        ->assertSuccessful();

    Process::assertRan(function ($process): bool {
        return str_contains($process->command, '--file=')
            && str_contains($process->command, '.sql.gz');
    });
});

it('outputs failure message on pg_dump error', function (): void {
    Process::fake([
        '*pg_dump*' => Process::result(errorOutput: 'connection refused', exitCode: 1),
    ]);

    $this->artisan('backup:database', ['--path' => $this->backupDir])
        ->assertFailed();
});

it('prunes backups older than retention period', function (): void {
    Process::fake([
        '*pg_dump*' => Process::result(output: ''),
    ]);

    // Create the directory and old backup files
    mkdir($this->backupDir, 0755, true);

    $oldFile = "{$this->backupDir}/vunnix_2025-01-01_020000.sql.gz";
    file_put_contents($oldFile, 'old backup');
    touch($oldFile, now()->subDays(31)->timestamp);

    $recentFile = "{$this->backupDir}/vunnix_2026-02-14_020000.sql.gz";
    file_put_contents($recentFile, 'recent backup');
    touch($recentFile, now()->subDays(1)->timestamp);

    $this->artisan('backup:database', ['--path' => $this->backupDir, '--retention' => 30])
        ->assertSuccessful();

    // Old file should be pruned, recent file + new backup should remain
    expect(file_exists($oldFile))->toBeFalse();
    expect(file_exists($recentFile))->toBeTrue();
});

it('uses default retention of 30 days', function (): void {
    Process::fake([
        '*pg_dump*' => Process::result(output: ''),
    ]);

    mkdir($this->backupDir, 0755, true);

    $oldFile = "{$this->backupDir}/vunnix_2025-06-01_020000.sql.gz";
    file_put_contents($oldFile, 'old backup');
    touch($oldFile, now()->subDays(35)->timestamp);

    $this->artisan('backup:database', ['--path' => $this->backupDir])
        ->assertSuccessful();

    expect(file_exists($oldFile))->toBeFalse();
});

it('does not pass password on command line', function (): void {
    Process::fake([
        '*pg_dump*' => Process::result(output: ''),
    ]);

    $this->artisan('backup:database', ['--path' => $this->backupDir])
        ->assertSuccessful();

    Process::assertRan(function ($process): bool {
        // Password should be via PGPASSWORD env var, not --password flag
        return ! str_contains($process->command, '--password')
            && ! str_contains($process->command, '-W');
    });
});

it('cleans up partial file on failure', function (): void {
    Process::fake([
        '*pg_dump*' => Process::result(errorOutput: 'disk full', exitCode: 1),
    ]);

    $this->artisan('backup:database', ['--path' => $this->backupDir])
        ->assertFailed();

    $files = glob("{$this->backupDir}/*.sql.gz");
    expect($files)->toHaveCount(0);
});
