# T100: API Versioning + External Access Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add API key authentication for external consumers, with per-key rate limiting, RBAC inheritance, and key management endpoints.

**Architecture:** API keys are SHA-256 hashed at rest (D152). A new `AuthenticateApiKey` middleware resolves Bearer tokens by hashing and looking up the `api_keys` table. The authenticated user inherits their normal RBAC permissions — no separate permission model. Rate limiting uses Laravel's built-in `throttle` middleware keyed per API key. Key management routes are session-authenticated (users manage their own keys via the SPA). External-facing routes accept both session auth and API key auth.

**Tech Stack:** Laravel 11 middleware, SHA-256 hashing, Laravel rate limiting (`RateLimiter`), Pest feature tests.

---

### Task 1: Create ApiKey Model + Factory

**Files:**
- Create: `app/Models/ApiKey.php`
- Create: `database/factories/ApiKeyFactory.php`

**Step 1: Write the failing test**

Create `tests/Feature/Models/ApiKeyTest.php`:

```php
<?php

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('belongs to a user', function () {
    $user = User::factory()->create();
    $apiKey = ApiKey::factory()->create(['user_id' => $user->id]);

    expect($apiKey->user->id)->toBe($user->id);
});

it('can check if revoked', function () {
    $key = ApiKey::factory()->create(['revoked' => false]);
    expect($key->isRevoked())->toBeFalse();

    $key->update(['revoked' => true, 'revoked_at' => now()]);
    expect($key->isRevoked())->toBeTrue();
});

it('can check if expired', function () {
    $key = ApiKey::factory()->create(['expires_at' => null]);
    expect($key->isExpired())->toBeFalse();

    $key->update(['expires_at' => now()->subDay()]);
    expect($key->isExpired())->toBeTrue();

    $key->update(['expires_at' => now()->addDay()]);
    expect($key->isExpired())->toBeFalse();
});

it('can check if active (not revoked and not expired)', function () {
    $key = ApiKey::factory()->create(['revoked' => false, 'expires_at' => null]);
    expect($key->isActive())->toBeTrue();

    $key->update(['revoked' => true, 'revoked_at' => now()]);
    expect($key->isActive())->toBeFalse();
});

it('has an active scope', function () {
    ApiKey::factory()->create(['revoked' => false, 'expires_at' => null]);
    ApiKey::factory()->create(['revoked' => true, 'revoked_at' => now()]);
    ApiKey::factory()->create(['revoked' => false, 'expires_at' => now()->subDay()]);

    expect(ApiKey::active()->count())->toBe(1);
});

it('records last used info', function () {
    $key = ApiKey::factory()->create();
    $key->recordUsage('192.168.1.1');

    $key->refresh();
    expect($key->last_ip)->toBe('192.168.1.1');
    expect($key->last_used_at)->not->toBeNull();
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Models/ApiKeyTest.php`
Expected: FAIL — ApiKey model doesn't exist yet.

**Step 3: Write ApiKey model**

Create `app/Models/ApiKey.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'key',
        'last_used_at',
        'last_ip',
        'expires_at',
        'revoked',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked' => 'boolean',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked;
    }

    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('revoked', false)
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function recordUsage(string $ip): void
    {
        $this->update([
            'last_used_at' => now(),
            'last_ip' => $ip,
        ]);
    }
}
```

Create `database/factories/ApiKeyFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApiKeyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->words(2, true),
            'key' => hash('sha256', $this->faker->uuid()),
            'last_used_at' => null,
            'last_ip' => null,
            'expires_at' => null,
            'revoked' => false,
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => [
            'revoked' => true,
            'revoked_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'expires_at' => now()->subDay(),
        ]);
    }
}
```

**Step 4: Add `apiKeys()` relationship to User model**

In `app/Models/User.php`, add:

```php
use Illuminate\Database\Eloquent\Relations\HasMany;

public function apiKeys(): HasMany
{
    return $this->hasMany(ApiKey::class);
}
```

**Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Models/ApiKeyTest.php`
Expected: All 6 tests PASS.

**Step 6: Commit**

```
T100.1: Add ApiKey model, factory, and User relationship
```

---

### Task 2: Create ApiKeyService (generation + revocation)

**Files:**
- Create: `app/Services/ApiKeyService.php`
- Create: `tests/Feature/Services/ApiKeyServiceTest.php`

**Step 1: Write the failing test**

```php
<?php

use App\Models\ApiKey;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(ApiKeyService::class);
    $this->user = User::factory()->create();
});

it('generates a new API key and returns plaintext once', function () {
    $result = $this->service->generate($this->user, 'My CI Key');

    expect($result)->toHaveKeys(['api_key', 'plaintext']);
    expect($result['api_key'])->toBeInstanceOf(ApiKey::class);
    expect($result['api_key']->name)->toBe('My CI Key');
    expect($result['api_key']->user_id)->toBe($this->user->id);
    expect($result['api_key']->revoked)->toBeFalse();

    // Plaintext is a 64-char hex string (SHA-256 input)
    expect(strlen($result['plaintext']))->toBe(64);

    // Stored key is SHA-256 hash of plaintext
    expect($result['api_key']->key)->toBe(hash('sha256', $result['plaintext']));
});

it('generates unique keys', function () {
    $result1 = $this->service->generate($this->user, 'Key 1');
    $result2 = $this->service->generate($this->user, 'Key 2');

    expect($result1['plaintext'])->not->toBe($result2['plaintext']);
    expect($result1['api_key']->key)->not->toBe($result2['api_key']->key);
});

it('resolves user from plaintext key', function () {
    $result = $this->service->generate($this->user, 'Test Key');

    $resolved = $this->service->resolveUser($result['plaintext']);
    expect($resolved->id)->toBe($this->user->id);
});

it('returns null for invalid plaintext key', function () {
    expect($this->service->resolveUser('invalid-key'))->toBeNull();
});

it('returns null for revoked key', function () {
    $result = $this->service->generate($this->user, 'Test Key');
    $result['api_key']->update(['revoked' => true, 'revoked_at' => now()]);

    expect($this->service->resolveUser($result['plaintext']))->toBeNull();
});

it('returns null for expired key', function () {
    $result = $this->service->generate($this->user, 'Test Key', now()->subDay());

    expect($this->service->resolveUser($result['plaintext']))->toBeNull();
});

it('revokes a key', function () {
    $result = $this->service->generate($this->user, 'Test Key');

    $this->service->revoke($result['api_key']);
    $result['api_key']->refresh();

    expect($result['api_key']->revoked)->toBeTrue();
    expect($result['api_key']->revoked_at)->not->toBeNull();
});

it('records usage on resolve', function () {
    $result = $this->service->generate($this->user, 'Test Key');

    $this->service->resolveUser($result['plaintext'], '10.0.0.1');
    $result['api_key']->refresh();

    expect($result['api_key']->last_ip)->toBe('10.0.0.1');
    expect($result['api_key']->last_used_at)->not->toBeNull();
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Services/ApiKeyServiceTest.php`
Expected: FAIL — ApiKeyService doesn't exist.

**Step 3: Write ApiKeyService**

```php
<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ApiKeyService
{
    /**
     * Generate a new API key for a user.
     *
     * Returns an array with ['api_key' => ApiKey, 'plaintext' => string].
     * The plaintext is shown once at creation and cannot be retrieved afterward (D152).
     */
    public function generate(User $user, string $name, ?Carbon $expiresAt = null): array
    {
        $plaintext = bin2hex(random_bytes(32)); // 64-char hex string
        $hash = hash('sha256', $plaintext);

        $apiKey = $user->apiKeys()->create([
            'name' => $name,
            'key' => $hash,
            'expires_at' => $expiresAt,
        ]);

        return [
            'api_key' => $apiKey,
            'plaintext' => $plaintext,
        ];
    }

    /**
     * Resolve a user from a plaintext API key.
     *
     * Returns null if the key is invalid, revoked, or expired.
     * Records usage (IP + timestamp) on successful resolve.
     */
    public function resolveUser(string $plaintext, ?string $ip = null): ?User
    {
        $hash = hash('sha256', $plaintext);

        $apiKey = ApiKey::active()
            ->where('key', $hash)
            ->first();

        if (! $apiKey) {
            return null;
        }

        $apiKey->recordUsage($ip ?? 'unknown');

        return $apiKey->user;
    }

    /**
     * Revoke an API key.
     */
    public function revoke(ApiKey $apiKey): void
    {
        $apiKey->update([
            'revoked' => true,
            'revoked_at' => now(),
        ]);
    }
}
```

**Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Services/ApiKeyServiceTest.php`
Expected: All 8 tests PASS.

**Step 5: Commit**

```
T100.2: Add ApiKeyService with generate, resolve, and revoke
```

---

### Task 3: Create AuthenticateApiKey Middleware

**Files:**
- Create: `app/Http/Middleware/AuthenticateApiKey.php`
- Create: `tests/Feature/Middleware/AuthenticateApiKeyTest.php`
- Modify: `bootstrap/app.php` — register middleware alias

**Step 1: Write the failing test**

```php
<?php

use App\Models\ApiKey;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Register a test route with api.key middleware
    Route::middleware('api.key')->get('/test-api-key', function () {
        return response()->json([
            'user_id' => request()->user()->id,
            'auth_via' => request()->attributes->get('auth_via'),
        ]);
    });
});

it('authenticates with a valid API key', function () {
    $service = app(ApiKeyService::class);
    $user = User::factory()->create();
    $result = $service->generate($user, 'Test Key');

    $this->getJson('/test-api-key', [
        'Authorization' => 'Bearer ' . $result['plaintext'],
    ])
        ->assertOk()
        ->assertJsonPath('user_id', $user->id)
        ->assertJsonPath('auth_via', 'api_key');
});

it('rejects request with no bearer token', function () {
    $this->getJson('/test-api-key')
        ->assertStatus(401)
        ->assertJsonPath('error', 'Missing API key.');
});

it('rejects request with invalid bearer token', function () {
    $this->getJson('/test-api-key', [
        'Authorization' => 'Bearer invalid-token-here',
    ])
        ->assertStatus(401)
        ->assertJsonPath('error', 'Invalid or expired API key.');
});

it('rejects revoked API key', function () {
    $service = app(ApiKeyService::class);
    $user = User::factory()->create();
    $result = $service->generate($user, 'Test Key');
    $service->revoke($result['api_key']);

    $this->getJson('/test-api-key', [
        'Authorization' => 'Bearer ' . $result['plaintext'],
    ])
        ->assertStatus(401)
        ->assertJsonPath('error', 'Invalid or expired API key.');
});

it('rejects expired API key', function () {
    $service = app(ApiKeyService::class);
    $user = User::factory()->create();
    $result = $service->generate($user, 'Test Key', now()->subDay());

    $this->getJson('/test-api-key', [
        'Authorization' => 'Bearer ' . $result['plaintext'],
    ])
        ->assertStatus(401)
        ->assertJsonPath('error', 'Invalid or expired API key.');
});

it('sets auth_via attribute on request', function () {
    $service = app(ApiKeyService::class);
    $user = User::factory()->create();
    $result = $service->generate($user, 'Test Key');

    $this->getJson('/test-api-key', [
        'Authorization' => 'Bearer ' . $result['plaintext'],
    ])
        ->assertOk()
        ->assertJsonPath('auth_via', 'api_key');
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Middleware/AuthenticateApiKeyTest.php`
Expected: FAIL — middleware doesn't exist.

**Step 3: Write AuthenticateApiKey middleware**

```php
<?php

namespace App\Http\Middleware;

use App\Services\ApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function __construct(
        private readonly ApiKeyService $apiKeyService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (empty($bearerToken)) {
            return response()->json(['error' => 'Missing API key.'], 401);
        }

        $user = $this->apiKeyService->resolveUser($bearerToken, $request->ip());

        if (! $user) {
            Log::warning('API key authentication failed', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Invalid or expired API key.'], 401);
        }

        // Set the authenticated user on the request
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('auth_via', 'api_key');

        return $next($request);
    }
}
```

**Step 4: Register middleware alias in `bootstrap/app.php`**

Add to the `$middleware->alias([...])` array:

```php
'api.key' => \App\Http\Middleware\AuthenticateApiKey::class,
```

**Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Middleware/AuthenticateApiKeyTest.php`
Expected: All 6 tests PASS.

**Step 6: Commit**

```
T100.3: Add AuthenticateApiKey middleware with Bearer token validation
```

---

### Task 4: Configure Per-Key Rate Limiting

**Files:**
- Modify: `app/Providers/AppServiceProvider.php` — register rate limiter
- Create: `tests/Feature/Middleware/ApiKeyRateLimitTest.php`

**Step 1: Write the failing test**

```php
<?php

use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Register a test route with api.key + throttle middleware
    Route::middleware(['api.key', 'throttle:api_key'])->get('/test-rate-limit', function () {
        return response()->json(['ok' => true]);
    });
});

it('allows requests within rate limit', function () {
    $service = app(ApiKeyService::class);
    $user = User::factory()->create();
    $result = $service->generate($user, 'Test Key');

    $this->getJson('/test-rate-limit', [
        'Authorization' => 'Bearer ' . $result['plaintext'],
    ])->assertOk();
});

it('returns 429 when rate limit exceeded', function () {
    $service = app(ApiKeyService::class);
    $user = User::factory()->create();
    $result = $service->generate($user, 'Test Key');

    $headers = ['Authorization' => 'Bearer ' . $result['plaintext']];

    // Exhaust the rate limit (default 60/min)
    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/test-rate-limit', $headers)->assertOk();
    }

    // 61st request should be throttled
    $this->getJson('/test-rate-limit', $headers)->assertStatus(429);
});

it('includes rate limit headers in response', function () {
    $service = app(ApiKeyService::class);
    $user = User::factory()->create();
    $result = $service->generate($user, 'Test Key');

    $response = $this->getJson('/test-rate-limit', [
        'Authorization' => 'Bearer ' . $result['plaintext'],
    ]);

    $response->assertOk();
    $response->assertHeader('X-RateLimit-Limit', '60');
    $response->assertHeader('X-RateLimit-Remaining');
});

it('rate limits per key not per user', function () {
    $service = app(ApiKeyService::class);
    $user = User::factory()->create();
    $key1 = $service->generate($user, 'Key 1');
    $key2 = $service->generate($user, 'Key 2');

    // Exhaust key1's limit
    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/test-rate-limit', [
            'Authorization' => 'Bearer ' . $key1['plaintext'],
        ]);
    }

    // key1 is throttled
    $this->getJson('/test-rate-limit', [
        'Authorization' => 'Bearer ' . $key1['plaintext'],
    ])->assertStatus(429);

    // key2 should still work (separate limit)
    $this->getJson('/test-rate-limit', [
        'Authorization' => 'Bearer ' . $key2['plaintext'],
    ])->assertOk();
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Middleware/ApiKeyRateLimitTest.php`
Expected: FAIL — `api_key` rate limiter not defined.

**Step 3: Register rate limiter in AppServiceProvider**

In `app/Providers/AppServiceProvider.php` `boot()` method, add:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

RateLimiter::for('api_key', function (\Illuminate\Http\Request $request) {
    // Per-key rate limit: use the SHA-256 hash of the bearer token as the key
    $bearer = $request->bearerToken();
    $keyHash = $bearer ? hash('sha256', $bearer) : $request->ip();

    return Limit::perMinute(60)->by('api_key:' . $keyHash);
});
```

**Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Middleware/ApiKeyRateLimitTest.php`
Expected: All 4 tests PASS.

**Step 5: Commit**

```
T100.4: Add per-API-key rate limiting (60 req/min default)
```

---

### Task 5: Create API Key Management Controller + Routes

**Files:**
- Create: `app/Http/Controllers/Api/ApiKeyController.php`
- Create: `app/Http/Requests/CreateApiKeyRequest.php`
- Modify: `routes/api.php` — add key management routes
- Create: `tests/Feature/Http/Controllers/Api/ApiKeyControllerTest.php`

**Step 1: Write the failing test**

```php
<?php

use App\Models\ApiKey;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

// ─── Index ─────────────────────────────────────────────────────

it('lists the authenticated user\'s API keys', function () {
    ApiKey::factory()->count(3)->create(['user_id' => $this->user->id]);
    ApiKey::factory()->create(); // another user's key

    $this->getJson('/api/v1/api-keys')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure(['data' => [['id', 'name', 'last_used_at', 'last_ip', 'expires_at', 'revoked', 'created_at']]]);
});

it('does not expose the key hash in index response', function () {
    ApiKey::factory()->create(['user_id' => $this->user->id]);

    $response = $this->getJson('/api/v1/api-keys')->assertOk();

    expect($response->json('data.0'))->not->toHaveKey('key');
});

// ─── Store ─────────────────────────────────────────────────────

it('creates a new API key and returns plaintext', function () {
    $response = $this->postJson('/api/v1/api-keys', [
        'name' => 'My CI Key',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['data' => ['id', 'name', 'created_at'], 'plaintext']);

    // Plaintext is 64 hex chars
    expect(strlen($response->json('plaintext')))->toBe(64);

    // Key is in the database
    expect(ApiKey::where('user_id', $this->user->id)->count())->toBe(1);
});

it('validates name is required', function () {
    $this->postJson('/api/v1/api-keys', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('validates name max length', function () {
    $this->postJson('/api/v1/api-keys', ['name' => str_repeat('a', 256)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

// ─── Revoke ────────────────────────────────────────────────────

it('revokes the user\'s own API key', function () {
    $apiKey = ApiKey::factory()->create(['user_id' => $this->user->id]);

    $this->deleteJson("/api/v1/api-keys/{$apiKey->id}")
        ->assertOk()
        ->assertJsonPath('message', 'API key revoked.');

    $apiKey->refresh();
    expect($apiKey->revoked)->toBeTrue();
    expect($apiKey->revoked_at)->not->toBeNull();
});

it('cannot revoke another user\'s API key', function () {
    $otherKey = ApiKey::factory()->create();

    $this->deleteJson("/api/v1/api-keys/{$otherKey->id}")
        ->assertStatus(403);
});

// ─── Admin revoke ──────────────────────────────────────────────

it('admin can revoke any user\'s API key', function () {
    // Skip if admin role setup is complex — use a simplified approach
    // This will be validated in the integration test
})->skip('Tested in integration test with full RBAC setup');

// ─── Auth required ─────────────────────────────────────────────

it('requires authentication for all endpoints', function () {
    // Logout
    auth()->logout();

    $this->getJson('/api/v1/api-keys')->assertStatus(401);
    $this->postJson('/api/v1/api-keys', ['name' => 'test'])->assertStatus(401);
    $this->deleteJson('/api/v1/api-keys/1')->assertStatus(401);
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Http/Controllers/Api/ApiKeyControllerTest.php`
Expected: FAIL — controller and routes don't exist.

**Step 3: Write CreateApiKeyRequest**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth handled by middleware
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
```

**Step 4: Write ApiKeyController**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateApiKeyRequest;
use App\Models\ApiKey;
use App\Services\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ApiKeyController extends Controller
{
    public function __construct(
        private readonly ApiKeyService $apiKeyService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $keys = $request->user()
            ->apiKeys()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ApiKey $key) => [
                'id' => $key->id,
                'name' => $key->name,
                'last_used_at' => $key->last_used_at?->toISOString(),
                'last_ip' => $key->last_ip,
                'expires_at' => $key->expires_at?->toISOString(),
                'revoked' => $key->revoked,
                'created_at' => $key->created_at->toISOString(),
            ]);

        return response()->json(['data' => $keys]);
    }

    public function store(CreateApiKeyRequest $request): JsonResponse
    {
        $expiresAt = $request->validated('expires_at')
            ? Carbon::parse($request->validated('expires_at'))
            : null;

        $result = $this->apiKeyService->generate(
            $request->user(),
            $request->validated('name'),
            $expiresAt,
        );

        return response()->json([
            'data' => [
                'id' => $result['api_key']->id,
                'name' => $result['api_key']->name,
                'created_at' => $result['api_key']->created_at->toISOString(),
            ],
            'plaintext' => $result['plaintext'],
        ], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $apiKey = ApiKey::findOrFail($id);

        // Users can revoke their own keys; authorization for admin revocation
        // is handled by a separate admin endpoint
        if ($apiKey->user_id !== $request->user()->id) {
            abort(403, 'You can only revoke your own API keys.');
        }

        $this->apiKeyService->revoke($apiKey);

        return response()->json(['message' => 'API key revoked.']);
    }
}
```

**Step 5: Add routes to `routes/api.php`**

Inside the `Route::middleware('auth')` group, add:

```php
// API key management (T100)
Route::get('/api-keys', [ApiKeyController::class, 'index'])
    ->name('api.api-keys.index');
Route::post('/api-keys', [ApiKeyController::class, 'store'])
    ->name('api.api-keys.store');
Route::delete('/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])
    ->name('api.api-keys.destroy');
```

**Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Http/Controllers/Api/ApiKeyControllerTest.php`
Expected: All tests PASS.

**Step 7: Commit**

```
T100.5: Add ApiKeyController with generate, list, and revoke endpoints
```

---

### Task 6: Add External API Routes (dual auth: session OR API key)

**Files:**
- Modify: `routes/api.php` — add external-facing route group with dual auth
- Create: `app/Http/Middleware/AuthenticateSessionOrApiKey.php`
- Create: `tests/Feature/ExternalApiAuthTest.php`

**Step 1: Write the failing test**

This tests that the external endpoints (tasks, metrics, activity, projects) accept both session auth and API key auth.

```php
<?php

use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['enabled' => true]);
    $this->project->users()->attach($this->user->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    // Give user review.view permission
    $role = Role::factory()->create(['project_id' => $this->project->id]);
    $perm = Permission::firstOrCreate(['name' => 'review.view']);
    $role->permissions()->attach($perm);
    $this->user->assignRole($role, $this->project);

    $this->service = app(ApiKeyService::class);
    $this->apiKeyResult = $this->service->generate($this->user, 'CI Key');
});

it('accesses GET /api/v1/ext/tasks via API key', function () {
    Task::factory()->create(['project_id' => $this->project->id]);

    $this->getJson('/api/v1/ext/tasks', [
        'Authorization' => 'Bearer ' . $this->apiKeyResult['plaintext'],
    ])->assertOk();
});

it('accesses GET /api/v1/ext/tasks via session auth', function () {
    Task::factory()->create(['project_id' => $this->project->id]);

    $this->actingAs($this->user)
        ->getJson('/api/v1/ext/tasks')
        ->assertOk();
});

it('accesses GET /api/v1/ext/projects via API key', function () {
    $this->getJson('/api/v1/ext/projects', [
        'Authorization' => 'Bearer ' . $this->apiKeyResult['plaintext'],
    ])->assertOk();
});

it('returns 401 without any auth', function () {
    $this->getJson('/api/v1/ext/tasks')
        ->assertStatus(401);
});

it('rate limits API key requests on external routes', function () {
    $headers = ['Authorization' => 'Bearer ' . $this->apiKeyResult['plaintext']];

    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/v1/ext/projects', $headers);
    }

    $this->getJson('/api/v1/ext/projects', $headers)->assertStatus(429);
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ExternalApiAuthTest.php`
Expected: FAIL — middleware and routes don't exist.

**Step 3: Write AuthenticateSessionOrApiKey middleware**

```php
<?php

namespace App\Http\Middleware;

use App\Services\ApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSessionOrApiKey
{
    public function __construct(
        private readonly ApiKeyService $apiKeyService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Try session auth first (Vue SPA)
        if ($request->user()) {
            $request->attributes->set('auth_via', 'session');
            return $next($request);
        }

        // Try API key auth
        $bearerToken = $request->bearerToken();

        if (empty($bearerToken)) {
            return response()->json(['error' => 'Authentication required.'], 401);
        }

        $user = $this->apiKeyService->resolveUser($bearerToken, $request->ip());

        if (! $user) {
            return response()->json(['error' => 'Invalid or expired API key.'], 401);
        }

        $request->setUserResolver(fn () => $user);
        $request->attributes->set('auth_via', 'api_key');

        return $next($request);
    }
}
```

**Step 4: Register middleware alias and add external routes**

In `bootstrap/app.php`, add to the alias array:

```php
'auth.api_key_or_session' => \App\Http\Middleware\AuthenticateSessionOrApiKey::class,
```

In `routes/api.php`, add a new route group for external endpoints:

```php
// External API (T100) — accepts session auth OR API key
// Rate-limited per API key (60 req/min). Session auth not rate-limited here.
Route::prefix('ext')
    ->middleware(['auth.api_key_or_session', 'throttle:api_key'])
    ->group(function () {
        Route::get('/tasks', [ExternalTaskController::class, 'index'])
            ->name('api.ext.tasks.index');
        Route::get('/tasks/{task}', [ExternalTaskController::class, 'show'])
            ->name('api.ext.tasks.show');
        Route::get('/metrics/summary', [ExternalMetricsController::class, 'summary'])
            ->name('api.ext.metrics.summary');
        Route::get('/activity', [ExternalActivityController::class, 'index'])
            ->name('api.ext.activity.index');
        Route::get('/projects', [ExternalProjectController::class, 'index'])
            ->name('api.ext.projects.index');
    });
```

**Note:** The actual external controllers (ExternalTaskController, etc.) are T101's responsibility. For T100, create stub controllers that return empty data to validate the auth flow. T101 will implement the full response logic.

**Step 5: Create stub external controllers**

Create `app/Http/Controllers/Api/ExternalTaskController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExternalTaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tasks = Task::whereIn('project_id', $request->user()->accessibleProjects()->pluck('id'))
            ->orderByDesc('created_at')
            ->cursorPaginate($request->integer('per_page', 25));

        return response()->json($tasks);
    }

    public function show(Request $request, Task $task): JsonResponse
    {
        // Check user has access to this task's project
        if (! $request->user()->accessibleProjects()->pluck('id')->contains($task->project_id)) {
            abort(403, 'You do not have access to this task.');
        }

        return response()->json(['data' => $task]);
    }
}
```

Create `app/Http/Controllers/Api/ExternalProjectController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExternalProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $projects = $request->user()->accessibleProjects();

        return response()->json(['data' => $projects]);
    }
}
```

Create `app/Http/Controllers/Api/ExternalMetricsController.php` and `app/Http/Controllers/Api/ExternalActivityController.php` as stubs returning empty data.

**Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/ExternalApiAuthTest.php`
Expected: All 5 tests PASS.

**Step 7: Commit**

```
T100.6: Add dual-auth middleware and external API route group with stubs
```

---

### Task 7: Add Admin API Key Revocation Endpoint

**Files:**
- Create: `app/Http/Controllers/Api/AdminApiKeyController.php`
- Modify: `routes/api.php` — add admin revoke route
- Add tests to existing `ApiKeyControllerTest.php` or create new test file

**Step 1: Write the failing test**

Add to `tests/Feature/Http/Controllers/Api/ApiKeyControllerTest.php` (or create a new `AdminApiKeyControllerTest.php`):

```php
// In a new test file: tests/Feature/Http/Controllers/Api/AdminApiKeyControllerTest.php

<?php

use App\Models\ApiKey;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createAdmin(): array
{
    $user = User::factory()->create();
    $project = Project::factory()->create(['enabled' => true]);
    $project->users()->attach($user->id, ['gitlab_access_level' => 40, 'synced_at' => now()]);

    $role = Role::factory()->create(['project_id' => $project->id]);
    $perm = Permission::firstOrCreate(['name' => 'admin.global_config']);
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    return [$user, $project];
}

it('admin can list all API keys', function () {
    [$admin] = createAdmin();
    ApiKey::factory()->count(5)->create();

    $this->actingAs($admin)
        ->getJson('/api/v1/admin/api-keys')
        ->assertOk()
        ->assertJsonCount(5, 'data');
});

it('admin can revoke any user\'s API key', function () {
    [$admin] = createAdmin();
    $otherKey = ApiKey::factory()->create();

    $this->actingAs($admin)
        ->deleteJson("/api/v1/admin/api-keys/{$otherKey->id}")
        ->assertOk()
        ->assertJsonPath('message', 'API key revoked.');

    $otherKey->refresh();
    expect($otherKey->revoked)->toBeTrue();
});

it('non-admin cannot access admin API key endpoints', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/admin/api-keys')
        ->assertStatus(403);
});
```

**Step 2: Write AdminApiKeyController**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Permission;
use App\Services\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminApiKeyController extends Controller
{
    public function __construct(
        private readonly ApiKeyService $apiKeyService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $keys = ApiKey::with('user:id,name,email')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ApiKey $key) => [
                'id' => $key->id,
                'user' => ['id' => $key->user->id, 'name' => $key->user->name, 'email' => $key->user->email],
                'name' => $key->name,
                'last_used_at' => $key->last_used_at?->toISOString(),
                'last_ip' => $key->last_ip,
                'expires_at' => $key->expires_at?->toISOString(),
                'revoked' => $key->revoked,
                'created_at' => $key->created_at->toISOString(),
            ]);

        return response()->json(['data' => $keys]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $apiKey = ApiKey::findOrFail($id);
        $this->apiKeyService->revoke($apiKey);

        return response()->json(['message' => 'API key revoked.']);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        $hasAdminPerm = $user->roles()
            ->whereHas('permissions', fn ($q) => $q->where('name', 'admin.global_config'))
            ->exists();

        if (! $hasAdminPerm) {
            abort(403, 'Admin permission required.');
        }
    }
}
```

**Step 3: Add admin routes**

In `routes/api.php`, inside the `Route::middleware('auth')` group:

```php
// Admin API key management (T100)
Route::get('/admin/api-keys', [AdminApiKeyController::class, 'index'])
    ->name('api.admin.api-keys.index');
Route::delete('/admin/api-keys/{apiKey}', [AdminApiKeyController::class, 'destroy'])
    ->name('api.admin.api-keys.destroy');
```

**Step 4: Run tests**

Run: `php artisan test tests/Feature/Http/Controllers/Api/AdminApiKeyControllerTest.php`
Expected: All 3 tests PASS.

**Step 5: Commit**

```
T100.7: Add admin API key management (list all, revoke any)
```

---

### Task 8: Update verify_m5.py with T100 Checks

**Files:**
- Modify: `verify/verify_m5.py`

**Step 1: Add T100 verification section**

Add before the `checker.summary()` call:

```python
# ============================================================
#  T100: API versioning + external access
# ============================================================
section("T100: API Versioning + External Access")

# Model
checker.check(
    "ApiKey model exists",
    file_exists("app/Models/ApiKey.php"),
)
checker.check(
    "ApiKey has isActive method",
    file_contains("app/Models/ApiKey.php", "isActive"),
)
checker.check(
    "ApiKey has isRevoked method",
    file_contains("app/Models/ApiKey.php", "isRevoked"),
)
checker.check(
    "ApiKey has isExpired method",
    file_contains("app/Models/ApiKey.php", "isExpired"),
)
checker.check(
    "ApiKey has active scope",
    file_contains("app/Models/ApiKey.php", "scopeActive"),
)
checker.check(
    "ApiKey has recordUsage method",
    file_contains("app/Models/ApiKey.php", "recordUsage"),
)
checker.check(
    "ApiKey factory exists",
    file_exists("database/factories/ApiKeyFactory.php"),
)
checker.check(
    "User model has apiKeys relationship",
    file_contains("app/Models/User.php", "apiKeys"),
)

# Service
checker.check(
    "ApiKeyService exists",
    file_exists("app/Services/ApiKeyService.php"),
)
checker.check(
    "ApiKeyService has generate method",
    file_contains("app/Services/ApiKeyService.php", "function generate"),
)
checker.check(
    "ApiKeyService has resolveUser method",
    file_contains("app/Services/ApiKeyService.php", "function resolveUser"),
)
checker.check(
    "ApiKeyService has revoke method",
    file_contains("app/Services/ApiKeyService.php", "function revoke"),
)
checker.check(
    "ApiKeyService uses SHA-256 hashing (D152)",
    file_contains("app/Services/ApiKeyService.php", "sha256"),
)

# Middleware
checker.check(
    "AuthenticateApiKey middleware exists",
    file_exists("app/Http/Middleware/AuthenticateApiKey.php"),
)
checker.check(
    "AuthenticateSessionOrApiKey middleware exists",
    file_exists("app/Http/Middleware/AuthenticateSessionOrApiKey.php"),
)
checker.check(
    "Middleware registered in bootstrap/app.php",
    file_contains("bootstrap/app.php", "api.key"),
)
checker.check(
    "Dual-auth middleware registered in bootstrap/app.php",
    file_contains("bootstrap/app.php", "auth.api_key_or_session"),
)

# Rate limiting
checker.check(
    "API key rate limiter registered",
    file_contains("app/Providers/AppServiceProvider.php", "api_key"),
)
checker.check(
    "Rate limit is per-key (60/min)",
    file_contains("app/Providers/AppServiceProvider.php", "perMinute(60)"),
)

# Controller
checker.check(
    "ApiKeyController exists",
    file_exists("app/Http/Controllers/Api/ApiKeyController.php"),
)
checker.check(
    "ApiKeyController has index method",
    file_contains("app/Http/Controllers/Api/ApiKeyController.php", "function index"),
)
checker.check(
    "ApiKeyController has store method",
    file_contains("app/Http/Controllers/Api/ApiKeyController.php", "function store"),
)
checker.check(
    "ApiKeyController has destroy method",
    file_contains("app/Http/Controllers/Api/ApiKeyController.php", "function destroy"),
)
checker.check(
    "CreateApiKeyRequest exists",
    file_exists("app/Http/Requests/CreateApiKeyRequest.php"),
)

# Admin controller
checker.check(
    "AdminApiKeyController exists",
    file_exists("app/Http/Controllers/Api/AdminApiKeyController.php"),
)
checker.check(
    "AdminApiKeyController has index method",
    file_contains("app/Http/Controllers/Api/AdminApiKeyController.php", "function index"),
)
checker.check(
    "AdminApiKeyController has destroy method",
    file_contains("app/Http/Controllers/Api/AdminApiKeyController.php", "function destroy"),
)

# External API routes
checker.check(
    "External API routes registered",
    file_contains("routes/api.php", "ext/tasks"),
)
checker.check(
    "External projects route registered",
    file_contains("routes/api.php", "ext/projects"),
)
checker.check(
    "API key routes registered",
    file_contains("routes/api.php", "api-keys"),
)
checker.check(
    "Admin API key routes registered",
    file_contains("routes/api.php", "admin/api-keys"),
)

# External stub controllers
checker.check(
    "ExternalTaskController exists",
    file_exists("app/Http/Controllers/Api/ExternalTaskController.php"),
)
checker.check(
    "ExternalProjectController exists",
    file_exists("app/Http/Controllers/Api/ExternalProjectController.php"),
)

# Tests
checker.check(
    "ApiKey model test exists",
    file_exists("tests/Feature/Models/ApiKeyTest.php"),
)
checker.check(
    "ApiKeyService test exists",
    file_exists("tests/Feature/Services/ApiKeyServiceTest.php"),
)
checker.check(
    "AuthenticateApiKey middleware test exists",
    file_exists("tests/Feature/Middleware/AuthenticateApiKeyTest.php"),
)
checker.check(
    "Rate limit test exists",
    file_exists("tests/Feature/Middleware/ApiKeyRateLimitTest.php"),
)
checker.check(
    "ApiKeyController test exists",
    file_exists("tests/Feature/Http/Controllers/Api/ApiKeyControllerTest.php"),
)
checker.check(
    "AdminApiKeyController test exists",
    file_exists("tests/Feature/Http/Controllers/Api/AdminApiKeyControllerTest.php"),
)
checker.check(
    "External API auth test exists",
    file_exists("tests/Feature/ExternalApiAuthTest.php"),
)
```

**Step 2: Run verify_m5.py**

Run: `python3 verify/verify_m5.py`
Expected: All T100 checks PASS.

**Step 3: Commit**

```
T100.8: Add T100 verification checks to verify_m5.py
```

---

### Task 9: Run Full Verification + Final Commit

**Step 1: Run all tests**

```bash
php artisan test --parallel
```

Expected: All tests PASS (existing + new T100 tests).

**Step 2: Run verify_m5.py**

```bash
python3 verify/verify_m5.py
```

Expected: All checks PASS.

**Step 3: Update progress.md**

- Check `[x]` for T100
- Bold T101 as next task
- Update milestone count: 13/18
- Update tasks complete: 101/116

**Step 4: Clear handoff.md**

Reset to empty template.

**Step 5: Final commit**

```
T100: Add API versioning and external access with SHA-256 key auth
```

---

## Files Created/Modified Summary

| File | Action | Task |
|---|---|---|
| `app/Models/ApiKey.php` | Create | 1 |
| `database/factories/ApiKeyFactory.php` | Create | 1 |
| `app/Models/User.php` | Modify (add relationship) | 1 |
| `app/Services/ApiKeyService.php` | Create | 2 |
| `app/Http/Middleware/AuthenticateApiKey.php` | Create | 3 |
| `app/Http/Middleware/AuthenticateSessionOrApiKey.php` | Create | 6 |
| `bootstrap/app.php` | Modify (aliases) | 3, 6 |
| `app/Providers/AppServiceProvider.php` | Modify (rate limiter) | 4 |
| `app/Http/Controllers/Api/ApiKeyController.php` | Create | 5 |
| `app/Http/Requests/CreateApiKeyRequest.php` | Create | 5 |
| `app/Http/Controllers/Api/AdminApiKeyController.php` | Create | 7 |
| `app/Http/Controllers/Api/ExternalTaskController.php` | Create | 6 |
| `app/Http/Controllers/Api/ExternalProjectController.php` | Create | 6 |
| `app/Http/Controllers/Api/ExternalMetricsController.php` | Create | 6 |
| `app/Http/Controllers/Api/ExternalActivityController.php` | Create | 6 |
| `routes/api.php` | Modify (3 route groups) | 5, 6, 7 |
| `verify/verify_m5.py` | Modify (T100 checks) | 8 |
| `progress.md` | Modify | 9 |
| `handoff.md` | Modify | 9 |

**Tests:**
| File | Tests |
|---|---|
| `tests/Feature/Models/ApiKeyTest.php` | 6 |
| `tests/Feature/Services/ApiKeyServiceTest.php` | 8 |
| `tests/Feature/Middleware/AuthenticateApiKeyTest.php` | 6 |
| `tests/Feature/Middleware/ApiKeyRateLimitTest.php` | 4 |
| `tests/Feature/Http/Controllers/Api/ApiKeyControllerTest.php` | 7 |
| `tests/Feature/Http/Controllers/Api/AdminApiKeyControllerTest.php` | 3 |
| `tests/Feature/ExternalApiAuthTest.php` | 5 |
| **Total** | **~39 tests** |
