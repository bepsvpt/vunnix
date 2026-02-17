<?php

use App\Enums\ReviewStrategy;
use App\Services\StrategyResolver;

beforeEach(function (): void {
    $this->resolver = new StrategyResolver;
});

// ─── Frontend files ─────────────────────────────────────────────

it('selects frontend-review for .vue files', function (): void {
    $result = $this->resolver->resolve([
        'src/components/Header.vue',
        'src/pages/Dashboard.vue',
    ]);

    expect($result)->toBe(ReviewStrategy::FrontendReview);
});

it('selects frontend-review for .tsx files', function (): void {
    $result = $this->resolver->resolve(['src/App.tsx']);

    expect($result)->toBe(ReviewStrategy::FrontendReview);
});

it('selects frontend-review for .css files', function (): void {
    $result = $this->resolver->resolve(['src/styles/main.css']);

    expect($result)->toBe(ReviewStrategy::FrontendReview);
});

it('selects frontend-review for .scss files', function (): void {
    $result = $this->resolver->resolve(['src/styles/variables.scss']);

    expect($result)->toBe(ReviewStrategy::FrontendReview);
});

it('selects frontend-review for .js files', function (): void {
    $result = $this->resolver->resolve(['resources/js/utils.js']);

    expect($result)->toBe(ReviewStrategy::FrontendReview);
});

// ─── Backend files ──────────────────────────────────────────────

it('selects backend-review for .php files', function (): void {
    $result = $this->resolver->resolve([
        'app/Models/User.php',
        'app/Services/TaskService.php',
    ]);

    expect($result)->toBe(ReviewStrategy::BackendReview);
});

it('selects backend-review for migration files', function (): void {
    $result = $this->resolver->resolve([
        'database/migrations/2024_01_01_000001_create_users_table.php',
    ]);

    expect($result)->toBe(ReviewStrategy::BackendReview);
});

// ─── Mixed files ────────────────────────────────────────────────

it('selects mixed-review for frontend + backend files', function (): void {
    $result = $this->resolver->resolve([
        'app/Http/Controllers/UserController.php',
        'resources/js/components/UserForm.vue',
    ]);

    expect($result)->toBe(ReviewStrategy::MixedReview);
});

it('selects mixed-review for .php + .tsx files', function (): void {
    $result = $this->resolver->resolve([
        'app/Models/Task.php',
        'frontend/src/TaskList.tsx',
    ]);

    expect($result)->toBe(ReviewStrategy::MixedReview);
});

it('selects mixed-review for migration + vue files', function (): void {
    $result = $this->resolver->resolve([
        'database/migrations/2024_01_01_000001_create_tasks_table.php',
        'resources/js/pages/Tasks.vue',
    ]);

    expect($result)->toBe(ReviewStrategy::MixedReview);
});

// ─── Security-sensitive files ───────────────────────────────────

it('selects security-audit for .env files', function (): void {
    $result = $this->resolver->resolve(['.env', 'app/Models/User.php']);

    expect($result)->toBe(ReviewStrategy::SecurityAudit);
});

it('selects security-audit for auth directory files', function (): void {
    $result = $this->resolver->resolve(['app/Http/Controllers/auth/LoginController.php']);

    expect($result)->toBe(ReviewStrategy::SecurityAudit);
});

it('selects security-audit for middleware files', function (): void {
    $result = $this->resolver->resolve(['app/Http/middleware/AuthMiddleware.php']);

    expect($result)->toBe(ReviewStrategy::SecurityAudit);
});

it('selects security-audit for files with password in path', function (): void {
    $result = $this->resolver->resolve(['app/Services/PasswordResetService.php']);

    expect($result)->toBe(ReviewStrategy::SecurityAudit);
});

it('selects security-audit for files with secret in path', function (): void {
    $result = $this->resolver->resolve(['config/secrets.php']);

    expect($result)->toBe(ReviewStrategy::SecurityAudit);
});

it('selects security-audit for files with token in path', function (): void {
    $result = $this->resolver->resolve(['app/Services/TokenValidator.php']);

    expect($result)->toBe(ReviewStrategy::SecurityAudit);
});

it('selects security-audit for config/auth.php', function (): void {
    $result = $this->resolver->resolve(['config/auth.php']);

    expect($result)->toBe(ReviewStrategy::SecurityAudit);
});

it('selects security-audit for Dockerfile', function (): void {
    $result = $this->resolver->resolve(['Dockerfile']);

    expect($result)->toBe(ReviewStrategy::SecurityAudit);
});

it('selects security-audit for docker-compose.yml', function (): void {
    $result = $this->resolver->resolve(['docker-compose.yml']);

    expect($result)->toBe(ReviewStrategy::SecurityAudit);
});

it('security-audit overrides frontend strategy', function (): void {
    $result = $this->resolver->resolve([
        'src/components/Login.vue',
        '.env',
    ]);

    expect($result)->toBe(ReviewStrategy::SecurityAudit);
});

it('security-audit overrides mixed strategy', function (): void {
    $result = $this->resolver->resolve([
        'app/Models/User.php',
        'src/components/Login.vue',
        'config/auth.php',
    ]);

    expect($result)->toBe(ReviewStrategy::SecurityAudit);
});

// ─── Edge cases ─────────────────────────────────────────────────

it('defaults to backend-review for empty file list', function (): void {
    $result = $this->resolver->resolve([]);

    expect($result)->toBe(ReviewStrategy::BackendReview);
});

it('defaults to backend-review for unrecognized extensions', function (): void {
    $result = $this->resolver->resolve([
        'README.md',
        'docs/architecture.txt',
    ]);

    expect($result)->toBe(ReviewStrategy::BackendReview);
});

it('handles mixed recognized and unrecognized extensions', function (): void {
    $result = $this->resolver->resolve([
        'README.md',
        'app/Models/User.php',
    ]);

    expect($result)->toBe(ReviewStrategy::BackendReview);
});
