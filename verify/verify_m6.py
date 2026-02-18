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
)

checker = Check()

print("=" * 60)
print("  VUNNIX M6 — Pilot Launch Verification")
print("=" * 60)

# ============================================================
#  T105: Production Docker Compose
# ============================================================
section("T105: Production Docker Compose")

# --- docker-compose.production.yml ---
checker.check(
    "docker-compose.production.yml exists",
    file_exists("docker-compose.production.yml"),
)
checker.check(
    "Prod compose has resource limits",
    file_contains("docker-compose.production.yml", "resources"),
)
checker.check(
    "Prod compose has memory limits",
    file_contains("docker-compose.production.yml", "memory"),
)
checker.check(
    "Prod compose has CPU limits",
    file_contains("docker-compose.production.yml", "cpus"),
)
checker.check(
    "Prod compose has log rotation max-size",
    file_contains("docker-compose.production.yml", "max-size"),
)
checker.check(
    "Prod compose has log rotation max-file",
    file_contains("docker-compose.production.yml", "max-file"),
)
checker.check(
    "Prod compose log max-size is 32m",
    file_contains("docker-compose.production.yml", '"32m"'),
)
checker.check(
    "Prod compose log max-file is 10",
    file_contains("docker-compose.production.yml", '"10"'),
)
checker.check(
    "Prod compose uses json-file logging driver",
    file_contains("docker-compose.production.yml", "json-file"),
)
checker.check(
    "Prod compose has backup-data volume",
    file_contains("docker-compose.production.yml", "backup-data"),
)
checker.check(
    "Prod compose sets APP_ENV=production",
    file_contains("docker-compose.production.yml", "APP_ENV: production"),
)
checker.check(
    "Prod compose sets APP_DEBUG=false",
    file_contains("docker-compose.production.yml", 'APP_DEBUG: "false"'),
)
checker.check(
    "Prod compose covers all 7 services (app, postgres, redis, reverb, queue-server, queue-runner, scheduler)",
    all(
        file_contains("docker-compose.production.yml", svc)
        for svc in ["app:", "postgres:", "redis:", "reverb:", "queue-server:", "queue-runner:", "scheduler:"]
    ),
)
checker.check(
    "Scheduler mounts backup-data volume",
    file_contains("docker-compose.production.yml", "backup-data:/app/storage/backups"),
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
