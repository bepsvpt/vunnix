# Vunnix — Self-Hosted Deployment Guide

Deploy Vunnix on any Linux server with Docker. Pre-built images are pulled from GitHub Container Registry (GHCR) — no source code or build tools required.

## Prerequisites

- **Server:** Linux VPS/EC2 with 2+ CPU cores, 4+ GB RAM, 20+ GB disk
- **Docker Engine:** 24.0+ with Docker Compose v2
- **Network:** Outbound HTTPS to `ghcr.io` (image pulls), `api.anthropic.com` (AI), and your GitLab instance
- **Domain:** A domain pointing to your server (for `APP_URL` and GitLab OAuth callback)

## Quick Start

### 1. Download deployment files

Download `docker-compose.production.yml` and `.env.production.example` from the [latest GitHub Release](https://github.com/bepsvpt/vunnix/releases/latest):

```bash
curl -LO https://github.com/bepsvpt/vunnix/releases/latest/download/docker-compose.production.yml
curl -LO https://github.com/bepsvpt/vunnix/releases/latest/download/.env.production.example
```

Or download a specific version:

```bash
VERSION=1.0.0
curl -LO "https://github.com/bepsvpt/vunnix/releases/download/v${VERSION}/docker-compose.production.yml"
curl -LO "https://github.com/bepsvpt/vunnix/releases/download/v${VERSION}/.env.production.example"
```

### 2. Configure environment

```bash
cp .env.production.example .env
```

Edit `.env` and fill in the required values. See [Configuration Reference](#configuration-reference) below for details on each variable.

At minimum, you must set:

- `APP_KEY` — generated in step 4
- `APP_URL` — your server's public URL (e.g., `https://vunnix.example.com`)
- `DB_PASSWORD` — a strong database password
- `SESSION_DOMAIN` — your domain (e.g., `vunnix.example.com`)
- `REDIS_PASSWORD` — a strong Redis password
- `GITLAB_CLIENT_ID` and `GITLAB_CLIENT_SECRET` — from your GitLab OAuth application
- `GITLAB_URL` — your GitLab instance URL
- `GITLAB_BOT_TOKEN` — Personal Access Token for the bot account
- `GITLAB_BOT_ACCOUNT_ID` — numeric user ID of the bot account
- `ANTHROPIC_API_KEY` — your Anthropic API key
- `REVERB_APP_KEY` and `REVERB_APP_SECRET` — random strings for WebSocket auth

### 3. Start services

```bash
docker compose -f docker-compose.production.yml up -d
```

Wait for all services to become healthy:

```bash
docker compose -f docker-compose.production.yml ps
```

### 4. First-time setup

Generate the application key:

```bash
docker compose -f docker-compose.production.yml exec app php artisan key:generate
```

Run database migrations:

```bash
docker compose -f docker-compose.production.yml exec app php artisan migrate
```

Seed the database (creates RBAC roles and permissions):

```bash
docker compose -f docker-compose.production.yml exec app php artisan db:seed
```

### 5. Verify

Open `https://your-domain.com` in a browser. You should see the Vunnix login page with a "Sign in with GitLab" button.

## Configuration Reference

### Application

| Variable | Required | Description |
|---|---|---|
| `VUNNIX_VERSION` | No | Docker image version. Pin to a release (e.g., `1.0.0`) or use `latest`. Default: `latest` |
| `APP_KEY` | Yes | Laravel encryption key. Generated via `php artisan key:generate` |
| `APP_URL` | Yes | Public URL of your Vunnix instance (e.g., `https://vunnix.example.com`) |
| `APP_PORT` | No | HTTP port. Default: `80` |
| `APP_HTTPS_PORT` | No | HTTPS port. Default: `443` |

### Database (PostgreSQL)

| Variable | Required | Description |
|---|---|---|
| `DB_DATABASE` | No | Database name. Default: `vunnix` |
| `DB_USERNAME` | No | Database user. Default: `vunnix` |
| `DB_PASSWORD` | Yes | Database password. **Must be changed from default.** |

### Redis

| Variable | Required | Description |
|---|---|---|
| `REDIS_PASSWORD` | Yes | Redis password. **Must be changed from default.** |

### GitLab Integration

| Variable | Required | Description |
|---|---|---|
| `GITLAB_URL` | Yes | Your GitLab instance URL (e.g., `https://gitlab.example.com`) |
| `GITLAB_CLIENT_ID` | Yes | OAuth application ID (created in GitLab Admin → Applications) |
| `GITLAB_CLIENT_SECRET` | Yes | OAuth application secret |
| `GITLAB_BOT_TOKEN` | Yes | Personal Access Token for the bot account (scopes: `api`) |
| `GITLAB_BOT_ACCOUNT_ID` | Yes | **Numeric** user ID of the bot account (not username) |

### AI Provider

| Variable | Required | Description |
|---|---|---|
| `ANTHROPIC_API_KEY` | Yes | Anthropic API key for Claude. Stored in `.env` only, never in the database (D153) |
| `VUNNIX_TASK_BUDGET_MINUTES` | No | Max execution time per task. Default: `60` |

### WebSocket (Reverb)

| Variable | Required | Description |
|---|---|---|
| `REVERB_APP_KEY` | Yes | Random string for WebSocket authentication |
| `REVERB_APP_SECRET` | Yes | Random string for WebSocket signing |
| `REVERB_PORT` | No | WebSocket port. Default: `8080` |

### Mail

| Variable | Required | Description |
|---|---|---|
| `MAIL_HOST` | No | SMTP server hostname |
| `MAIL_PORT` | No | SMTP port. Default: `587` |
| `MAIL_USERNAME` | No | SMTP username |
| `MAIL_PASSWORD` | No | SMTP password |
| `MAIL_FROM_ADDRESS` | No | Sender email address |

## Upgrading

### Standard upgrade

1. Update `VUNNIX_VERSION` in `.env` to the new version number
2. Pull the new image and recreate containers:

```bash
docker compose -f docker-compose.production.yml pull
docker compose -f docker-compose.production.yml up -d
```

3. Run any new migrations:

```bash
docker compose -f docker-compose.production.yml exec app php artisan migrate
```

### Upgrading docker-compose.production.yml

If a new release includes changes to `docker-compose.production.yml` (new services, changed ports, etc.), download the updated file:

```bash
curl -LO "https://github.com/bepsvpt/vunnix/releases/download/v${VERSION}/docker-compose.production.yml"
```

Then restart:

```bash
docker compose -f docker-compose.production.yml up -d
```

## Backup and Restore

### Automated backups

The scheduler container runs `pg_dump` daily, storing compressed backups in the `backup-data` volume. Retention is controlled by `BACKUP_RETENTION_DAYS` (default: 30 days).

### Manual backup

```bash
docker compose -f docker-compose.production.yml exec postgres \
  pg_dump -U vunnix -d vunnix -Z 9 > backup_$(date +%Y%m%d).sql.gz
```

### Restore from backup

```bash
docker compose -f docker-compose.production.yml exec -T postgres \
  psql -U vunnix -d vunnix < backup_20260216.sql.gz
```

## Troubleshooting

### Check service health

```bash
docker compose -f docker-compose.production.yml ps
```

All services should show `healthy` status. If a service is `unhealthy`, check its logs.

### View logs

```bash
# All services
docker compose -f docker-compose.production.yml logs -f

# Specific service
docker compose -f docker-compose.production.yml logs -f app
docker compose -f docker-compose.production.yml logs -f queue-server
docker compose -f docker-compose.production.yml logs -f queue-runner
```

### Common issues

**App returns 502 / connection refused:**
- Check that `app` container is healthy: `docker compose -f docker-compose.production.yml ps app`
- Verify `APP_URL` matches your domain
- Check logs: `docker compose -f docker-compose.production.yml logs app`

**"DB_PASSWORD is required" error on start:**
- Set `DB_PASSWORD` in `.env`. The production compose file requires this variable to prevent accidental deployments with default credentials.

**GitLab OAuth fails:**
- Verify `GITLAB_URL`, `GITLAB_CLIENT_ID`, and `GITLAB_CLIENT_SECRET` in `.env`
- Ensure the OAuth application's redirect URI matches `${APP_URL}/auth/gitlab/callback`
- Check that your GitLab instance is reachable from the server

**Bot can't post to projects:**
- Verify `GITLAB_BOT_ACCOUNT_ID` is a **numeric user ID**, not a username
- Ensure the bot account has Maintainer role on the target project

**Queue jobs failing:**
- Check queue worker logs: `docker compose -f docker-compose.production.yml logs queue-server queue-runner`
- Verify `ANTHROPIC_API_KEY` is set (AI tasks fail without it)
- Verify `GITLAB_BOT_TOKEN` has the `api` scope

**WebSocket not connecting:**
- Ensure port `8080` (or `REVERB_PORT`) is accessible from the client browser
- If behind a reverse proxy, configure WebSocket pass-through for the Reverb port
