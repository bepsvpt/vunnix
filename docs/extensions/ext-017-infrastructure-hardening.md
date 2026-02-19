## Extension 017: Infrastructure Hardening

### Trigger
Third-party security audit (2026-02-19) identified Medium findings #8, #9, #10, #11, #12: proxy trust wildcard, rate limiter key bypass, health endpoint information disclosure, webhook token O(n) scan, and admin webhook test SSRF vulnerability.

### Scope
What it does:
- Makes proxy trust configurable via `TRUSTED_PROXIES` env var (finding #8)
- Hardens rate limiter key to include IP fallback for invalid tokens (finding #9)
- Restricts health endpoint error details to generic status messages (finding #10)
- Adds private IP/CIDR blocking to admin webhook test endpoint (finding #12)
- Documents webhook token O(n) scan as accepted design tradeoff (finding #11 — no code change)

What it does NOT do:
- Does not remove proxy trust entirely (deployment requires it per D158)
- Does not change webhook token storage/encryption (O(n) is acceptable for GitLab Free scale)
- Does not add authentication to the health endpoint (used by load balancer probes)

### Architecture Fit
- **Components affected:** Bootstrap config, AppServiceProvider, HealthCheckController, AdminSettingsController, TeamChatNotificationService
- **Extension points used:** Laravel rate limiting, middleware config, validation rules
- **New tables/endpoints/services:** One new helper class/trait for private IP validation

### New Decisions
- **D207:** Proxy trust configurable via `TRUSTED_PROXIES` env var — defaults to `'*'` for backward compatibility; production deployments should set to specific proxy IPs (softens D158)
- **D208:** API key rate limiter uses composite key: `sha256($token)` for valid tokens, `$clientIp` for missing/invalid tokens — prevents bucket rotation via random bearer values
- **D209:** Health endpoint returns coarse statuses (`pass`/`fail`) without error details to unauthenticated clients — detailed messages logged server-side only
- **D210:** Admin webhook test endpoint blocks private IPs (RFC 1918), link-local (169.254.x.x), loopback (127.x.x.x), and cloud metadata endpoints (169.254.169.254) — prevents SSRF probing of internal infrastructure

### Dependencies
- **Requires:** Nothing — all fixes are independent of each other and other extensions
- **Unblocks:** Completes the security audit remediation

### Tasks

#### T269: Make proxy trust configurable via env var
**File(s):** `bootstrap/app.php`, `.env.example`, `.env.production.example`
**Action:** Change `$middleware->trustProxies(at: '*')` to `$middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'))`. Add `TRUSTED_PROXIES=*` to `.env.example` and `.env.production.example` with a comment explaining production should use specific IPs.
**Verification:** Setting `TRUSTED_PROXIES=192.168.1.100` restricts trust to that IP

#### T270: Harden rate limiter key composition
**File(s):** `app/Providers/AppServiceProvider.php`
**Action:** Change the `api_key` rate limiter to use IP as the key when bearer token is missing or invalid. Current: `$bearer !== null ? hash('sha256', $bearer) : $request->ip()`. New: keep the same logic but also add IP as a secondary component for all requests: `$keyHash . ':' . $request->ip()`. This prevents both bucket rotation (random tokens) and IP-sharing issues.
**Verification:** Test that rotating random bearer tokens hits the same rate limit bucket (keyed by IP); valid API keys get per-key-per-IP buckets

#### T271: Restrict health endpoint error details
**File(s):** `app/Http/Controllers/HealthCheckController.php`
**Action:** Replace raw exception messages in error responses with generic status strings. Change `'error' => $e->getMessage()` to `'error' => 'Check failed'` for all health checks (PostgreSQL, Redis, Queue Worker, Reverb, Disk). Log the full exception details via `Log::warning()` for operational debugging.
**Verification:** Health endpoint returns `{"status": "fail", "error": "Check failed"}` instead of raw exception messages

#### T272: Add private IP blocking to webhook test endpoint
**File(s):** `app/Http/Controllers/Api/AdminSettingsController.php`, `app/Rules/NotPrivateUrl.php` (new)
**Action:** Create a custom Laravel validation rule `NotPrivateUrl` that:
1. Resolves the URL's hostname to IP(s) via `gethostbynamel()`
2. Rejects if any resolved IP matches: `127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16` (link-local/metadata), `0.0.0.0/8`, `::1`, `fc00::/7`
3. Rejects `localhost` hostname explicitly
Apply this rule to the `webhook_url` validation in `testWebhook()`.
**Verification:** Test that `http://127.0.0.1`, `http://169.254.169.254`, `http://10.0.0.1`, `http://localhost` are all rejected with 422

#### T273: Add tests for hardening fixes
**File(s):** `tests/Feature/Http/Controllers/Api/InfrastructureHardeningTest.php`
**Action:** Create test file covering:
- Rate limiter: rotating tokens hit same bucket (IP-based)
- Health endpoint: error response contains generic message, not raw exception
- Webhook test: private IPs rejected with 422
- Webhook test: public URLs accepted
**Verification:** `php artisan test --filter=InfrastructureHardeningTest` passes

#### T274: Document webhook token O(n) as accepted tradeoff
**File(s):** `app/Http/Middleware/VerifyWebhookToken.php`
**Action:** Add PHPDoc comment on `findProjectConfigByToken()` documenting that O(n) decrypt scan is an accepted design tradeoff for GitLab Free's limited project count. Note that encrypted columns cannot be queried in SQL, and for <100 projects the overhead is negligible.
**Verification:** Comment is accurate and informative

#### T275: Update decisions index
**File(s):** `docs/spec/decisions-index.md`
**Action:** Add D207, D208, D209, D210 entries
**Verification:** Index is up to date

### Verification
- [ ] Proxy trust configurable via env var; default unchanged
- [ ] Rate limiter resistant to token rotation attacks
- [ ] Health endpoint returns generic error messages only
- [ ] Webhook test rejects private/internal IPs
- [ ] Webhook test accepts valid public URLs
- [ ] O(n) webhook token scan documented
- [ ] All existing tests pass (no regression)
- [ ] `php artisan test --parallel` passes
- [ ] `composer analyse` passes
