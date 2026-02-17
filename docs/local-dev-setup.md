# Vunnix Local Development Setup

Run Vunnix locally with Docker Compose and a reverse proxy tunnel for GitLab connectivity.

## Prerequisites

| Requirement | Details |
|---|---|
| **Docker Desktop** | Running with at least 4 GB RAM allocated |
| **GitLab instance** | gitlab.com or self-hosted, with access to create OAuth apps |
| **Anthropic API key** | For AI chat (Conversation Engine); executor uses a separate key set in GitLab CI/CD variables |
| **GitLab bot account** | Dedicated user with a Personal Access Token (`api` scope) |

## Architecture Overview

```
Browser ──► Tunnel ──► FrankenPHP (app:80) ──► Laravel Octane
                                                 ├── PostgreSQL 18
                                                 ├── Redis (cache/session/queue)
                                                 ├── Reverb (WebSocket)
                                                 ├── queue-server (immediate tasks)
                                                 ├── queue-runner (CI pipeline tasks)
                                                 └── scheduler (cron)

GitLab ──webhook──► Tunnel ──► /webhook ──► EventRouter ──► Queue ──► TaskDispatcher
                                                                          │
GitLab ◄──trigger pipeline──────────────────────────────────────────────────┘
```

## 1. Start Services

```bash
# Clone and enter the project
cd /path/to/vunnix

# Back up existing .env if present
cp .env .env.backup 2>/dev/null || true

# Install PHP dependencies (one-off container — writes to host vendor/ via bind mount)
docker compose run --rm app composer install

# Start all services
docker compose up -d --build

# First-time setup only
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
docker compose exec app php artisan storage:link
```

Verify all 7 services are running:

```bash
docker compose ps
```

Expected: `app`, `postgres`, `redis`, `reverb`, `queue-server`, `queue-runner`, `scheduler`.

## 2. Start Reverse Proxy Tunnel

The tunnel provides an HTTPS URL that GitLab can reach for webhooks and OAuth callbacks.

### Option A: Cloudflared (recommended)

```bash
brew install cloudflared

# Quick tunnel — no account needed
cloudflared tunnel --url http://localhost:8000
```

Produces a URL like `https://random-words.trycloudflare.com`.

### Option B: ngrok

```bash
brew install ngrok
ngrok http 8000
```

Produces a URL like `https://xxxx-xx-xx.ngrok-free.app`.

> **Note:** The tunnel URL changes on every restart. Update the GitLab OAuth redirect URI and `.env` accordingly.

## 3. Configure Environment

Edit the **existing** `.env` — only change these values:

```env
APP_URL=https://<TUNNEL_URL>
SESSION_DOMAIN=<TUNNEL_HOSTNAME>

# GitLab OAuth (create app in step 4 first)
GITLAB_CLIENT_ID=<from GitLab OAuth app>
GITLAB_CLIENT_SECRET=<from GitLab OAuth app>
GITLAB_URL=https://gitlab.com
GITLAB_REDIRECT_URI=/auth/gitlab/callback

# GitLab Bot Account
GITLAB_BOT_TOKEN=<bot PAT with api scope>
GITLAB_BOT_ACCOUNT_ID=<bot numeric user ID>

# Vunnix API URL (must match tunnel URL — used by executor callbacks)
VUNNIX_API_URL=https://<TUNNEL_URL>

# Anthropic
ANTHROPIC_API_KEY=<your key>
```

Leave all other values (`APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, Reverb keys, etc.) as-is.

Reload config:

```bash
docker compose exec app php artisan config:clear
```

> **Important:** `docker compose restart` does NOT re-read `.env`. Use `docker compose up -d` to recreate containers with updated environment.

## 4. Create GitLab OAuth Application

1. Go to **Preferences > Applications** (user-level) or **Admin Area > Applications** (instance-level)
2. Create a new application:
   - **Name:** Vunnix
   - **Redirect URI:** `https://<TUNNEL_URL>/auth/gitlab/callback`
   - **Scopes:** `read_user`, `api`
   - **Confidential:** Yes
3. Copy **Application ID** → `GITLAB_CLIENT_ID`
4. Copy **Secret** → `GITLAB_CLIENT_SECRET`
5. Run `docker compose exec app php artisan config:clear`

### Bot Account

1. Create a dedicated GitLab user (e.g., `vunnix-bot`)
2. Generate a **Personal Access Token** with `api` scope → `GITLAB_BOT_TOKEN`
3. Note the user's **numeric ID** (not username) → `GITLAB_BOT_ACCOUNT_ID`
4. Add the bot as **Maintainer** on projects you want Vunnix to manage

## 5. Register Your Project

1. Visit `https://<TUNNEL_URL>` and log in via GitLab OAuth
2. Run the setup command:

```bash
docker compose exec app php artisan vunnix:setup <GROUP/PROJECT> --admin-email=<YOUR_EMAIL>
```

Example:

```bash
docker compose exec app php artisan vunnix:setup mygroup/myproject --admin-email=you@example.com
```

This command:
- Seeds RBAC permissions
- Looks up the GitLab project and creates it in Vunnix
- Verifies bot membership and creates the webhook
- Creates a CI pipeline trigger token
- Pre-creates `ai::*` labels
- Creates default roles (admin, developer, viewer)
- Assigns you the admin role

Verify:

```bash
curl -f https://<TUNNEL_URL>/up
```

## 6. Development Workflow

### Code Changes

FrankenPHP (Octane) and queue workers cache the application in memory. After modifying PHP code:

```bash
# Reload the web server
docker compose exec app php artisan octane:reload

# Restart queue workers (they don't support hot-reload)
docker compose restart queue-server queue-runner
```

### Frontend Development

```bash
# Watch mode (inside container)
docker compose exec app npm run dev

# Production build
docker compose exec app npm run build
```

### Running Tests

```bash
# Full suite (requires --parallel to avoid OOM)
docker compose exec app php artisan test --parallel

# Single file
docker compose exec app php artisan test tests/Feature/Services/TaskDispatcherTest.php

# Filter by name
docker compose exec app php artisan test --filter="dispatches pipeline"
```

### Useful Commands

```bash
# View application logs
docker compose exec app tail -f storage/logs/laravel.log

# Queue status
docker compose exec app php artisan queue:monitor vunnix-server,vunnix-runner-high,vunnix-runner-normal,vunnix-runner-low

# Tinker (REPL)
docker compose exec app php artisan tinker

# Run migrations
docker compose exec app php artisan migrate

# Clear all caches
docker compose exec app php artisan optimize:clear
```

### Checking Logs

```bash
# Application
docker compose logs app --tail=50

# Queue workers
docker compose logs queue-server --tail=20
docker compose logs queue-runner --tail=20

# All services
docker compose logs --tail=20
```

## 7. WebSocket for Live Updates (Optional)

Dashboard live updates use Reverb (WebSocket) on port 8080. For full real-time functionality, start a second tunnel:

```bash
# In a second terminal
cloudflared tunnel --url http://localhost:8080
```

Then update `.env`:

```env
VITE_REVERB_HOST=<SECOND_TUNNEL_HOSTNAME>
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

Rebuild frontend assets:

```bash
docker compose exec app npm run build
```

> For a quick demo, this is optional. Chat SSE streaming goes through the main app port, and page refreshes show updated data.

## 8. Teardown

```bash
# Stop services (preserves data)
docker compose down

# Stop services and delete all data
docker compose down -v

# Restore original .env
cp .env.backup .env
```
