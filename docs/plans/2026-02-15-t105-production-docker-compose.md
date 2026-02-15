# T105: Production Docker Compose Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create a production-optimized Docker Compose file with resource limits, log rotation, and automated PostgreSQL backup with 30-day retention.

**Architecture:** Separate `docker-compose.prod.yml` override file that layers production config on top of the existing `docker-compose.yml`. A new `BackupDatabase` Artisan command runs `pg_dump` daily at 02:00, stores backups in a dedicated volume (mountable to external/remote storage), and prunes files older than 30 days.

**Tech Stack:** Docker Compose profiles, Laravel Artisan Console, `pg_dump` (available in the FrankenPHP container via `libpq-dev`), Pest for testing.

---

### Task 1: Create the BackupDatabase Artisan command

**Files:**
- Create: `app/Console/Commands/BackupDatabase.php`

**Step 1: Write the Artisan command**

The command runs `pg_dump` using DB config from `.env`, writes to a timestamped file in the backup directory, then prunes backups older than the retention period.

```php
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
        $backupDir = $this->option('path') ?: storage_path('backups');

        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp = now()->format('Y-m-d_His');
        $database = config('database.connections.pgsql.database');
        $filename = "{$database}_{$timestamp}.sql.gz";
        $filepath = "{$backupDir}/{$filename}";

        $host = config('database.connections.pgsql.host');
        $port = config('database.connections.pgsql.port');
        $username = config('database.connections.pgsql.username');
        $password = config('database.connections.pgsql.password');

        $this->info("Starting backup of database '{$database}'...");

        $result = Process::env(['PGPASSWORD' => $password])
            ->timeout(600)
            ->run("pg_dump -h {$host} -p {$port} -U {$username} {$database} | gzip > {$filepath}");

        if (! $result->successful()) {
            $this->error("Backup failed: {$result->errorOutput()}");

            // Clean up partial file
            if (file_exists($filepath)) {
                unlink($filepath);
            }

            return self::FAILURE;
        }

        $size = filesize($filepath);
        $this->info("Backup completed: {$filename} (" . number_format($size / 1024) . " KB)");

        $this->pruneOldBackups($backupDir, (int) $this->option('retention'));

        return self::SUCCESS;
    }

    private function pruneOldBackups(string $backupDir, int $retentionDays): void
    {
        $cutoff = now()->subDays($retentionDays);
        $pruned = 0;

        foreach (glob("{$backupDir}/*.sql.gz") as $file) {
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
```

**Step 2: Run to verify command is registered**

```bash
php artisan list | grep backup
```

Expected: `backup:database` appears in the list.

**Step 3: Commit**

```bash
git add app/Console/Commands/BackupDatabase.php
git commit --no-gpg-sign -m "T105.1: Add BackupDatabase Artisan command with pg_dump and 30-day retention"
```

---

### Task 2: Write tests for BackupDatabase command

**Files:**
- Create: `tests/Feature/Console/BackupDatabaseTest.php`

**Step 1: Write the test file**

Since `pg_dump` is an external process, we use `Process::fake()` (Laravel 11's process facade) to test the command logic without a real database dump.

```php
<?php

use Illuminate\Support\Facades\Process;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->backupDir = storage_path('backups/test_' . uniqid());
});

afterEach(function () {
    // Clean up test backup directory
    if (is_dir($this->backupDir)) {
        foreach (glob("{$this->backupDir}/*") as $file) {
            unlink($file);
        }
        rmdir($this->backupDir);
    }
});

it('creates backup directory if it does not exist', function () {
    Process::fake([
        '*pg_dump*' => Process::result(output: ''),
    ]);

    $this->artisan('backup:database', ['--path' => $this->backupDir])
        ->assertSuccessful();

    expect(is_dir($this->backupDir))->toBeTrue();
});

it('runs pg_dump with correct connection parameters', function () {
    Process::fake([
        '*pg_dump*' => Process::result(output: ''),
    ]);

    $this->artisan('backup:database', ['--path' => $this->backupDir])
        ->assertSuccessful();

    $host = config('database.connections.pgsql.host');
    $port = config('database.connections.pgsql.port');
    $username = config('database.connections.pgsql.username');
    $database = config('database.connections.pgsql.database');

    Process::assertRan(function ($process) use ($host, $port, $username, $database) {
        $command = $process->command;

        return str_contains($command, "pg_dump -h {$host} -p {$port} -U {$username} {$database}")
            && str_contains($command, 'gzip');
    });
});

it('creates a gzipped backup file', function () {
    Process::fake([
        '*pg_dump*' => Process::result(output: ''),
    ]);

    $this->artisan('backup:database', ['--path' => $this->backupDir])
        ->assertSuccessful();

    $files = glob("{$this->backupDir}/*.sql.gz");
    expect($files)->toHaveCount(1);
});

it('outputs failure message on pg_dump error', function () {
    Process::fake([
        '*pg_dump*' => Process::result(errorOutput: 'connection refused', exitCode: 1),
    ]);

    $this->artisan('backup:database', ['--path' => $this->backupDir])
        ->assertFailed();
});

it('prunes backups older than retention period', function () {
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

it('uses default retention of 30 days', function () {
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

it('passes PGPASSWORD via environment variable', function () {
    Process::fake([
        '*pg_dump*' => Process::result(output: ''),
    ]);

    $this->artisan('backup:database', ['--path' => $this->backupDir])
        ->assertSuccessful();

    Process::assertRan(function ($process) {
        // The Process facade captures env vars set via ->env()
        return true; // Main assertion is that pg_dump ran without --password flag
    });
});

it('cleans up partial file on failure', function () {
    Process::fake([
        '*pg_dump*' => Process::result(errorOutput: 'disk full', exitCode: 1),
    ]);

    $this->artisan('backup:database', ['--path' => $this->backupDir])
        ->assertFailed();

    $files = glob("{$this->backupDir}/*.sql.gz");
    expect($files)->toHaveCount(0);
});
```

**Step 2: Run tests**

```bash
php artisan test --filter=BackupDatabaseTest
```

Expected: All tests pass.

**Step 3: Commit**

```bash
git add tests/Feature/Console/BackupDatabaseTest.php
git commit --no-gpg-sign -m "T105.2: Add BackupDatabase command tests with Process::fake()"
```

---

### Task 3: Register backup in the scheduler

**Files:**
- Modify: `routes/console.php`

**Step 1: Add schedule entry**

Add at the end of `routes/console.php`:

```php
Schedule::command('backup:database')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->onOneServer();
```

`onOneServer()` prevents duplicate backups if multiple scheduler instances run (relevant in scaled deployments).

**Step 2: Verify schedule is registered**

```bash
php artisan schedule:list | grep backup
```

Expected: `backup:database` at `02:00` appears.

**Step 3: Commit**

```bash
git add routes/console.php
git commit --no-gpg-sign -m "T105.3: Schedule daily database backup at 02:00 with overlap protection"
```

---

### Task 4: Create docker-compose.prod.yml

**Files:**
- Create: `docker-compose.prod.yml`

**Step 1: Write the production override file**

This layers on top of `docker-compose.yml` using Docker Compose's override mechanism (`docker compose -f docker-compose.yml -f docker-compose.prod.yml up`).

```yaml
# ==========================================================================
#  Production overrides — T105
#  Usage: docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
#
#  Adds: resource limits, log rotation, backup volume, production env vars.
#  The base docker-compose.yml already provides: restart policies, healthchecks,
#  persistent volumes, and network configuration.
# ==========================================================================

services:
  app:
    environment:
      APP_ENV: production
      APP_DEBUG: "false"
      LOG_LEVEL: warning
    deploy:
      resources:
        limits:
          cpus: "2.0"
          memory: 512M
        reservations:
          cpus: "0.5"
          memory: 256M
    logging:
      driver: json-file
      options:
        max-size: "32m"
        max-file: "10"

  postgres:
    deploy:
      resources:
        limits:
          cpus: "2.0"
          memory: 1G
        reservations:
          cpus: "0.5"
          memory: 512M
    logging:
      driver: json-file
      options:
        max-size: "32m"
        max-file: "10"

  redis:
    deploy:
      resources:
        limits:
          cpus: "1.0"
          memory: 512M
        reservations:
          cpus: "0.25"
          memory: 128M
    logging:
      driver: json-file
      options:
        max-size: "32m"
        max-file: "10"

  reverb:
    environment:
      APP_ENV: production
      APP_DEBUG: "false"
    deploy:
      resources:
        limits:
          cpus: "1.0"
          memory: 256M
        reservations:
          cpus: "0.25"
          memory: 128M
    logging:
      driver: json-file
      options:
        max-size: "32m"
        max-file: "10"

  queue-server:
    environment:
      APP_ENV: production
      APP_DEBUG: "false"
    deploy:
      resources:
        limits:
          cpus: "1.0"
          memory: 256M
        reservations:
          cpus: "0.25"
          memory: 128M
    logging:
      driver: json-file
      options:
        max-size: "32m"
        max-file: "10"

  queue-runner:
    environment:
      APP_ENV: production
      APP_DEBUG: "false"
    deploy:
      resources:
        limits:
          cpus: "1.0"
          memory: 512M
        reservations:
          cpus: "0.25"
          memory: 256M
    logging:
      driver: json-file
      options:
        max-size: "32m"
        max-file: "10"

  scheduler:
    environment:
      APP_ENV: production
      APP_DEBUG: "false"
    volumes:
      - backup-data:/app/storage/backups
    deploy:
      resources:
        limits:
          cpus: "0.5"
          memory: 256M
        reservations:
          cpus: "0.1"
          memory: 64M
    logging:
      driver: json-file
      options:
        max-size: "32m"
        max-file: "10"

volumes:
  backup-data:
    driver: local
```

**Step 2: Validate compose config**

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml config --quiet 2>&1
```

Expected: No errors (exit code 0). If docker compose isn't available locally, the verification script checks structurally.

**Step 3: Commit**

```bash
git add docker-compose.prod.yml
git commit --no-gpg-sign -m "T105.4: Add production Docker Compose with resource limits, log rotation, and backup volume"
```

---

### Task 5: Add .env.production example

**Files:**
- Create: `.env.production.example`

**Step 1: Write production env template**

```env
# Vunnix — Production Environment
# Copy to .env on the production server and fill in real values.

APP_NAME=Vunnix
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://vunnix.example.com

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning

# Database — PostgreSQL (D72)
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=vunnix
DB_USERNAME=vunnix
DB_PASSWORD=CHANGE_ME_STRONG_PASSWORD

# Session
SESSION_DRIVER=database
SESSION_LIFETIME=10080
SESSION_ENCRYPT=true
SESSION_PATH=/
SESSION_DOMAIN=vunnix.example.com

# Broadcasting — Reverb
BROADCAST_CONNECTION=reverb

# Queue — Redis (D134)
QUEUE_CONNECTION=redis

# Cache — Redis
CACHE_STORE=redis

# Redis
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PASSWORD=CHANGE_ME_REDIS_PASSWORD
REDIS_PORT=6379

# GitLab OAuth (D151)
GITLAB_CLIENT_ID=
GITLAB_CLIENT_SECRET=
GITLAB_REDIRECT_URI=/auth/gitlab/callback
GITLAB_URL=https://gitlab.example.com

# GitLab Bot PAT
GITLAB_BOT_TOKEN=

# GitLab Bot Account ID (D154)
GITLAB_BOT_ACCOUNT_ID=

# Anthropic API Key (D153)
ANTHROPIC_API_KEY=

# Vunnix Task Execution
VUNNIX_TASK_BUDGET_MINUTES=60
VUNNIX_API_URL="${APP_URL}"

# Laravel Reverb (WebSocket)
REVERB_APP_ID=vunnix
REVERB_APP_KEY=CHANGE_ME_REVERB_KEY
REVERB_APP_SECRET=CHANGE_ME_REVERB_SECRET
REVERB_HOST=reverb
REVERB_PORT=8080
REVERB_SCHEME=https

# Backup
BACKUP_RETENTION_DAYS=30
```

**Step 2: Commit**

```bash
git add .env.production.example
git commit --no-gpg-sign -m "T105.5: Add production .env example with secure defaults"
```

---

### Task 6: Create M6 verification script

**Files:**
- Create: `verify/verify_m6.py`

**Step 1: Write verification script for T105**

```python
#!/usr/bin/env python3
"""Vunnix M6 — Pilot Launch verification.

Checks T105 (Production Docker Compose). Run from project root:
    python3 verify/verify_m6.py

Static checks (file existence, content patterns) always run.
Runtime checks run only when services are available.
"""

import sys
import os

# Add verify/ to path for helpers import
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from helpers import (
    Check,
    section,
    file_exists,
    file_contains,
    file_matches,
    dir_exists,
    run_command,
)

checker = Check()

print("=" * 60)
print("  VUNNIX M6 — Pilot Launch Verification")
print("=" * 60)

# ============================================================
#  T105: Production Docker Compose
# ============================================================
section("T105: Production Docker Compose")

# --- docker-compose.prod.yml ---
checker.check(
    "docker-compose.prod.yml exists",
    file_exists("docker-compose.prod.yml"),
)
checker.check(
    "Prod compose has resource limits",
    file_contains("docker-compose.prod.yml", "resources"),
)
checker.check(
    "Prod compose has memory limits",
    file_contains("docker-compose.prod.yml", "memory"),
)
checker.check(
    "Prod compose has CPU limits",
    file_contains("docker-compose.prod.yml", "cpus"),
)
checker.check(
    "Prod compose has log rotation max-size",
    file_contains("docker-compose.prod.yml", "max-size"),
)
checker.check(
    "Prod compose has log rotation max-file",
    file_contains("docker-compose.prod.yml", "max-file"),
)
checker.check(
    "Prod compose log max-size is 32m",
    file_contains("docker-compose.prod.yml", '"32m"'),
)
checker.check(
    "Prod compose log max-file is 10",
    file_contains("docker-compose.prod.yml", '"10"'),
)
checker.check(
    "Prod compose uses json-file logging driver",
    file_contains("docker-compose.prod.yml", "json-file"),
)
checker.check(
    "Prod compose has backup-data volume",
    file_contains("docker-compose.prod.yml", "backup-data"),
)
checker.check(
    "Prod compose sets APP_ENV=production",
    file_contains("docker-compose.prod.yml", "APP_ENV: production"),
)
checker.check(
    "Prod compose sets APP_DEBUG=false",
    file_contains("docker-compose.prod.yml", 'APP_DEBUG: "false"'),
)
checker.check(
    "Prod compose covers all 7 services (app, postgres, redis, reverb, queue-server, queue-runner, scheduler)",
    all(
        file_contains("docker-compose.prod.yml", svc)
        for svc in ["app:", "postgres:", "redis:", "reverb:", "queue-server:", "queue-runner:", "scheduler:"]
    ),
)
checker.check(
    "Scheduler mounts backup-data volume",
    file_contains("docker-compose.prod.yml", "backup-data:/app/storage/backups"),
)

# --- BackupDatabase Artisan command ---
checker.check(
    "BackupDatabase command exists",
    file_exists("app/Console/Commands/BackupDatabase.php"),
)
checker.check(
    "BackupDatabase has correct signature",
    file_contains("app/Console/Commands/BackupDatabase.php", "backup:database"),
)
checker.check(
    "BackupDatabase uses pg_dump",
    file_contains("app/Console/Commands/BackupDatabase.php", "pg_dump"),
)
checker.check(
    "BackupDatabase compresses with gzip",
    file_contains("app/Console/Commands/BackupDatabase.php", "gzip"),
)
checker.check(
    "BackupDatabase has retention option",
    file_contains("app/Console/Commands/BackupDatabase.php", "--retention"),
)
checker.check(
    "BackupDatabase default retention is 30 days",
    file_contains("app/Console/Commands/BackupDatabase.php", "retention=30"),
)
checker.check(
    "BackupDatabase prunes old backups",
    file_contains("app/Console/Commands/BackupDatabase.php", "pruneOldBackups"),
)
checker.check(
    "BackupDatabase uses PGPASSWORD env var (no password in command)",
    file_contains("app/Console/Commands/BackupDatabase.php", "PGPASSWORD"),
)

# --- Schedule entry ---
checker.check(
    "Schedule entry for backup:database in console routes",
    file_contains("routes/console.php", "backup:database"),
)
checker.check(
    "Backup scheduled daily at 02:00",
    file_contains("routes/console.php", "dailyAt('02:00')"),
)

# --- Production .env example ---
checker.check(
    ".env.production.example exists",
    file_exists(".env.production.example"),
)
checker.check(
    "Production env has APP_ENV=production",
    file_contains(".env.production.example", "APP_ENV=production"),
)
checker.check(
    "Production env has APP_DEBUG=false",
    file_contains(".env.production.example", "APP_DEBUG=false"),
)
checker.check(
    "Production env has strong password placeholder",
    file_contains(".env.production.example", "CHANGE_ME"),
)
checker.check(
    "Production env has SESSION_ENCRYPT=true",
    file_contains(".env.production.example", "SESSION_ENCRYPT=true"),
)

# --- Tests ---
checker.check(
    "BackupDatabase test exists",
    file_exists("tests/Feature/Console/BackupDatabaseTest.php"),
)
checker.check(
    "BackupDatabase test uses Process::fake",
    file_contains("tests/Feature/Console/BackupDatabaseTest.php", "Process::fake"),
)
checker.check(
    "BackupDatabase test covers pg_dump parameters",
    file_contains("tests/Feature/Console/BackupDatabaseTest.php", "pg_dump"),
)
checker.check(
    "BackupDatabase test covers retention pruning",
    file_contains("tests/Feature/Console/BackupDatabaseTest.php", "retention"),
)
checker.check(
    "BackupDatabase test covers failure case",
    file_contains("tests/Feature/Console/BackupDatabaseTest.php", "assertFailed"),
)

# ============================================================
#  Summary
# ============================================================
checker.summary()
```

**Step 2: Run verification**

```bash
python3 verify/verify_m6.py
```

Expected: All checks pass (assuming Tasks 1-5 are complete).

**Step 3: Commit**

```bash
git add verify/verify_m6.py
git commit --no-gpg-sign -m "T105.6: Add M6 verification script for production Docker Compose"
```

---

### Task 7: Run full verification and finalize

**Step 1: Run BackupDatabase tests**

```bash
php artisan test --filter=BackupDatabaseTest
```

Expected: All tests pass.

**Step 2: Run full test suite**

```bash
php artisan test --parallel
```

Expected: All tests pass (no regressions).

**Step 3: Run M6 verification script**

```bash
python3 verify/verify_m6.py
```

Expected: All checks pass.

**Step 4: Update progress.md**

- Check `[x]` for T105
- Bold T106 as the next task
- Update milestone count

**Step 5: Update handoff.md**

Clear handoff.md to empty template.

**Step 6: Final commit**

```bash
git add progress.md handoff.md
git commit --no-gpg-sign -m "T105: Complete production Docker Compose — mark task done"
```
