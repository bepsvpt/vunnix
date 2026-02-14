#!/usr/bin/env python3
"""Vunnix M1 — Core Infrastructure verification.

Checks that all 11 M1 tasks (T1–T11) are correctly implemented.
Run from project root: python3 verify/verify_m1.py

Static checks (file existence, content patterns) always run.
Runtime checks (artisan commands, tests) run only when services are available.
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
    count_migrations_matching,
)

checker = Check()

print("=" * 60)
print("  VUNNIX M1 — Core Infrastructure Verification")
print("=" * 60)

# ============================================================
#  T1: Scaffold Laravel project
# ============================================================
section("T1: Scaffold Laravel Project")

checker.check("composer.json exists", file_exists("composer.json"))
checker.check("artisan exists", file_exists("artisan"))
checker.check("app/ directory exists", dir_exists("app"))
checker.check("config/ directory exists", dir_exists("config"))
checker.check("routes/ directory exists", dir_exists("routes"))
checker.check(
    "Laravel Octane in composer.json",
    file_contains("composer.json", "laravel/octane"),
)
checker.check(
    "Laravel AI SDK in composer.json",
    file_contains("composer.json", "laravel/ai"),
)
checker.check(
    "Laravel Socialite in composer.json",
    file_contains("composer.json", "laravel/socialite"),
)
checker.check(
    "Laravel Reverb in composer.json",
    file_contains("composer.json", "laravel/reverb"),
)
checker.check(
    "FrankenPHP configured in Octane config",
    file_exists("config/octane.php")
    and file_contains("config/octane.php", "frankenphp"),
)
checker.check(
    "Vite configured",
    file_exists("vite.config.js") or file_exists("vite.config.ts"),
)

# ============================================================
#  T2: Docker Compose
# ============================================================
section("T2: Docker Compose")

has_compose = file_exists("docker-compose.yml") or file_exists("compose.yml")
compose_file = "docker-compose.yml" if file_exists("docker-compose.yml") else "compose.yml"

checker.check("Docker Compose file exists", has_compose)

if has_compose:
    checker.check(
        "PostgreSQL service defined",
        file_contains(compose_file, "postgres"),
    )
    checker.check(
        "Redis service defined",
        file_contains(compose_file, "redis"),
    )
    checker.check(
        "Reverb service or config present",
        file_contains(compose_file, "reverb")
        or file_contains(compose_file, "REVERB"),
    )
    checker.check(
        "FrankenPHP or app service defined",
        file_contains(compose_file, "frankenphp")
        or file_contains(compose_file, "app"),
    )

# ============================================================
#  T3: Migrations — auth & RBAC tables
# ============================================================
section("T3: Migrations — Auth & RBAC Tables")

checker.check(
    "migrations/ directory exists",
    dir_exists("database/migrations"),
)
checker.check(
    "users migration exists",
    count_migrations_matching(r"create_users_table") > 0,
)
checker.check(
    "projects migration exists",
    count_migrations_matching(r"create_projects_table") > 0,
)
checker.check(
    "roles migration exists",
    count_migrations_matching(r"role") > 0,
)
checker.check(
    "permissions migration exists",
    count_migrations_matching(r"permission") > 0,
)

# ============================================================
#  T4: Migrations — task & conversation tables
# ============================================================
section("T4: Migrations — Task & Conversation Tables")

checker.check(
    "tasks migration exists",
    count_migrations_matching(r"create_tasks_table") > 0,
)
checker.check(
    "conversations or agent_conversations migration exists",
    count_migrations_matching(r"conversation") > 0,
)

# ============================================================
#  T5: Migrations — operational tables
# ============================================================
section("T5: Migrations — Operational Tables")

checker.check(
    "audit_logs migration exists",
    count_migrations_matching(r"audit") > 0,
)
checker.check(
    "dead_letter_queue migration exists",
    count_migrations_matching(r"dead_letter") > 0,
)
checker.check(
    "global_settings migration exists",
    count_migrations_matching(r"global_setting") > 0,
)
checker.check(
    "api_keys migration exists",
    count_migrations_matching(r"api_key") > 0,
)
checker.check(
    "project_configs migration exists",
    count_migrations_matching(r"project_config") > 0,
)

# ============================================================
#  T6: Health check endpoint
# ============================================================
section("T6: Health Check Endpoint")

checker.check(
    "Health route defined",
    (file_exists("routes/api.php") and file_contains("routes/api.php", "health"))
    or (file_exists("routes/web.php") and file_contains("routes/web.php", "health")),
)

# ============================================================
#  T7: GitLab OAuth
# ============================================================
section("T7: GitLab OAuth")

checker.check(
    "Socialite GitLab provider configured",
    file_exists("config/services.php")
    and file_contains("config/services.php", "gitlab"),
)
checker.check(
    "Auth routes defined",
    (file_exists("routes/web.php") and file_contains("routes/web.php", "auth"))
    or file_exists("app/Http/Controllers/Auth/GitLabController.php")
    or file_exists("app/Http/Controllers/AuthController.php"),
)

# ============================================================
#  T8: User model + membership sync
# ============================================================
section("T8: User Model + Membership Sync")

checker.check(
    "User model exists",
    file_exists("app/Models/User.php"),
)
checker.check(
    "User model has GitLab fields",
    file_exists("app/Models/User.php")
    and file_contains("app/Models/User.php", "gitlab"),
)

# ============================================================
#  T9: RBAC system
# ============================================================
section("T9: RBAC System")

checker.check(
    "Role model exists",
    file_exists("app/Models/Role.php"),
)
checker.check(
    "Permission model exists",
    file_exists("app/Models/Permission.php"),
)
checker.check(
    "Authorization middleware or policy exists",
    file_exists("app/Http/Middleware/CheckPermission.php")
    or file_exists("app/Http/Middleware/RbacMiddleware.php")
    or dir_exists("app/Policies"),
)

# ============================================================
#  T10: Global configuration model
# ============================================================
section("T10: Global Configuration Model")

checker.check(
    "GlobalSetting model exists",
    file_exists("app/Models/GlobalSetting.php"),
)

# ============================================================
#  T11: GitLab HTTP client service
# ============================================================
section("T11: GitLab HTTP Client Service")

checker.check(
    "GitLab client service exists",
    file_exists("app/Services/GitLabClient.php")
    or file_exists("app/Services/GitLab/GitLabClient.php")
    or file_exists("app/Services/GitLab/Client.php"),
)

# ============================================================
#  Runtime checks (require Docker services)
# ============================================================
section("Runtime: Laravel Tests")

success, stdout, stderr = run_command("php artisan test 2>&1")
if success:
    checker.check("php artisan test passes", True, stdout.split("\n")[-1] if stdout else "")
else:
    # Distinguish "tests fail" from "artisan not available"
    if "not found" in stderr or "No such file" in stderr:
        checker.check(
            "php artisan test passes",
            False,
            "artisan not available (Laravel not yet scaffolded?)",
        )
    else:
        last_line = stdout.split("\n")[-1] if stdout else stderr.split("\n")[-1] if stderr else "unknown"
        checker.check("php artisan test passes", False, last_line)

section("Runtime: Migrations")

success, stdout, stderr = run_command("php artisan migrate:status 2>&1")
checker.check(
    "php artisan migrate:status succeeds",
    success,
    "" if success else "services may not be running",
)

# ============================================================
#  Summary
# ============================================================
checker.summary()
