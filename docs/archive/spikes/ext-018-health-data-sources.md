# Spike: ext-018 Health Data Sources

Date: 2026-02-19
Owner: Vunnix engineering
Question: Can Vunnix obtain meaningful health metrics from GitLab and public advisory APIs without requiring external services?

## Summary

Go for all three launch dimensions:
- Coverage: feasible from GitLab pipeline API `coverage` field.
- Dependencies: feasible from lock files + Packagist advisories API.
- Complexity: feasible from GitLab repository tree + file content heuristics.

No SonarQube, Codecov, or additional user setup is required.

## Findings

### 1) Coverage from GitLab pipeline API

Endpoint:
- `GET /api/v4/projects/:id/pipelines?ref=<default_branch>&status=success&per_page=1`

Observed/expected response shape includes:
- `id` (pipeline ID)
- `web_url` (pipeline URL)
- `coverage` (nullable numeric/string percentage, e.g. `87.5`)

Edge case:
- If `.gitlab-ci.yml` does not define coverage parsing, `coverage` is `null`.

Decision:
- Treat `coverage = null` as "dimension unavailable" for that project/run.
- Do not fail the whole analysis run.

### 2) Dependency vulnerabilities from lock files + advisories APIs

Data source:
- `composer.lock` from GitLab file API
- optional `package-lock.json` from GitLab file API

PHP advisory API:
- `GET https://packagist.org/api/security-advisories/?packages[]=vendor/package`

Response shape:
- `advisories` object keyed by package name, each containing advisory entries
- advisories include identifiers, title/description, optional CVE references, and metadata fields

Edge cases:
- Private packages may not have public advisories.
- Network/API outage should degrade gracefully (dimension unavailable).

Decision:
- Parse `composer.lock` first (highest signal and reliable API).
- Score from advisory count/severity; no AI calls.

### 3) Complexity heuristics from GitLab repository APIs

Endpoints:
- `GET /api/v4/projects/:id/repository/tree?path=<dir>&recursive=true`
- `GET /api/v4/projects/:id/repository/files/:file_path?ref=<branch>`

Approach:
- Analyze top-N source files by size from configured directories (`app/`, `resources/js/`).
- Compute LOC and function/method count using regex heuristics.

Performance:
- Tree query is lightweight.
- File reads are bounded by `max_file_reads` (default 20), keeping runtime predictable.

Edge cases:
- Large monorepos: cap reads to avoid rate-limit pressure.
- Non-standard layouts: configurable analysis directories.

Decision:
- Proceed with heuristic complexity scoring for v1.

## Rate Limits and Reliability

- GitLab API calls are bounded per run:
  - 1 project metadata request (optional)
  - 1 pipeline list request
  - tree requests for configured directories
  - max 20 file reads for complexity
  - lock file reads (1-2)
- Advisory API calls can be batched and retried.
- All analyzer failures are non-blocking for other analyzers.

## Go/No-Go Gate

- Coverage: GO
- Dependencies: GO
- Complexity: GO

Proceed with ext-018 full implementation (T277+).
