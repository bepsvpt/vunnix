# Vunnix Executor Docker Image
#
# Runs on GitLab Runners to execute AI-powered code review, feature development,
# UI adjustment, and issue discussion tasks using the Claude CLI.
#
# Includes:
# - claude CLI (via npm)
# - Playwright + headless Chromium (for UI adjustment screenshots, D131)
# - Code quality tools: eslint, PHPStan, stylelint
# - Result posting scripts
#
# Built from executor/ in the Vunnix repo, pushed to GitLab Container Registry.
# Target projects pull this image via their CI/CD pipeline (D150).
#
# @see §3.4 Task Dispatcher & Task Executor
# @see §6.7 Executor Image

FROM node:24-bookworm-slim

LABEL maintainer="Vunnix <noreply@vunnix.dev>"
LABEL description="Vunnix AI Task Executor — Claude CLI + Playwright + code quality tools"

# Avoid interactive prompts during package installation
ENV DEBIAN_FRONTEND=noninteractive

# ── System dependencies ──────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    ca-certificates \
    git \
    jq \
    php-cli \
    php-xml \
    php-mbstring \
    php-tokenizer \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# ── PHP Composer (for PHPStan) ───────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── Global npm packages ─────────────────────────────────────────────
# Claude CLI — AI runtime for code review, feature dev, issue discussion
# eslint — JavaScript/TypeScript linting (frontend-review strategy)
# stylelint — CSS/SCSS linting (frontend-review strategy)
RUN npm install -g \
    @anthropic-ai/claude-code@latest \
    eslint@latest \
    stylelint@latest \
    && npm cache clean --force

# ── PHPStan (backend-review strategy) ────────────────────────────────
RUN composer global require phpstan/phpstan --no-interaction --no-progress \
    && composer global clear-cache
ENV PATH="/root/.composer/vendor/bin:${PATH}"

# ── Playwright + headless Chromium (D131 — UI adjustment screenshots) ─
RUN npx playwright install chromium --with-deps \
    && npx playwright install-deps chromium

# ── Copy executor files ─────────────────────────────────────────────
WORKDIR /vunnix-executor

# Cache-busting: Force rebuild of COPY layers when scripts change.
# Docker caches COPY layers based on file content, but doesn't detect
# changes in some environments. Use build-time ARG to invalidate cache.
ARG CACHE_BUST=unknown
RUN echo "Build cache bust: ${CACHE_BUST}"

# Entrypoint and scripts
COPY entrypoint.sh /vunnix-executor/entrypoint.sh
COPY scripts/ /vunnix-executor/scripts/
COPY schemas/ /vunnix-executor/schemas/

# Claude configuration (CLAUDE.md, skills, settings)
# T20-T27 will populate .claude/CLAUDE.md and .claude/skills/
COPY .claude/ /vunnix-executor/.claude/

# MCP server configurations
COPY mcp/ /vunnix-executor/mcp/

# Make scripts executable
RUN chmod +x /vunnix-executor/entrypoint.sh \
    && chmod +x /vunnix-executor/scripts/*.sh 2>/dev/null || true \
    && chmod +x /vunnix-executor/scripts/*.js 2>/dev/null || true

# ── Runtime configuration ───────────────────────────────────────────
# GitLab CI job timeout is the hard limit (20 min, D34).
# The entrypoint validates the task-scoped token TTL (D127) before starting.
ENV VUNNIX_EXECUTOR_VERSION="2.1.0"

ENTRYPOINT ["/vunnix-executor/entrypoint.sh"]
