# Assessment: Infrastructure Hardening (Security Audit Findings #8, #9, #10, #11, #12)

**Date:** 2026-02-19
**Requested by:** Third-party security audit (2026-02-19)
**Trigger:** Security audit identified 5 medium-severity infrastructure issues: proxy trust, rate limiter bypass, health endpoint info leak, webhook token O(n) scan, and admin webhook test SSRF

## What

Five independent infrastructure hardening items of medium severity: (8) `trustProxies('*')` accepts forwarded headers from any source; (9) API key rate limiter key is token-hash only, so rotating invalid tokens creates fresh rate limit buckets; (10) public health endpoint returns raw exception messages for infrastructure checks; (11) webhook token verification does O(n) decrypt scan; (12) admin webhook test endpoint accepts arbitrary URLs without blocking private/internal IPs (SSRF).

## Classification

**Tier:** 2
**Rationale:** 5 independent fixes across 5-6 files. Each fix is small (1-2 files), but collectively they form a hardening pass that benefits from coordinated testing. Finding #11 (O(n) webhook scan) is acceptable by design for GitLab Free's project count and is downgraded to documentation-only.

**Modifiers:**
- [ ] `breaking` — No API contract changes (health endpoint output format may change)
- [ ] `multi-repo` — Single repository
- [ ] `spike-required` — All patterns well-understood
- [ ] `deprecation` — No capability removed
- [ ] `migration` — No data migration

## Impact Analysis

### Components Affected
| Component | Impact | Files (est.) |
|---|---|---|
| bootstrap/app.php | Make proxy trust configurable via env var | 1 |
| AppServiceProvider (rate limiter) | Add IP fallback to rate limiter key for invalid tokens | 1 |
| HealthCheckController | Replace raw exceptions with generic status messages | 1 |
| AdminSettingsController | Add private IP/CIDR blocking for webhook test URL | 1 |
| TeamChatNotificationService | Add URL validation helper | 1 |
| VerifyWebhookToken | No code change — document design tradeoff | 0 |
| Tests | Add tests for each hardening fix | 4-5 |

### Relevant Decisions
| Decision | Summary | Relationship |
|---|---|---|
| D158 | Trust all proxies — required for reverse proxy/tunnel deployments | Constrains: can't remove proxy trust entirely, make configurable |
| D26 | Webhook auto-configuration — per-project secrets | Context: explains why O(n) scan exists |

### Dependencies
- **Requires first:** Nothing — all fixes are independent
- **Unblocks:** Completes the security audit remediation

## Risk Factors
- Proxy trust change could break deployments that rely on wildcard trust — make configurable
- Health endpoint change may affect monitoring systems that parse detailed error messages
- Rate limiter change needs careful testing to avoid blocking legitimate API key users
- SSRF fix needs comprehensive private IP detection (IPv4/IPv6, link-local, cloud metadata)

## Recommendation
Proceed to planning-extensions. Medium severity — implement last in remediation sequence.
