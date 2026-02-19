# Vunnix Project Setup Guide

Enable AI-powered code reviews and task execution for your GitLab project.

## Prerequisites

Before integrating Vunnix, verify the following:

| Requirement | Details |
|---|---|
| **Vunnix instance** | A running Vunnix server with a configured Anthropic API key |
| **Bot account** | A dedicated Vunnix bot GitLab user with **Maintainer** role on your project |
| **Bot PAT** | The bot account needs a Personal Access Token with `api` scope, configured in Vunnix's `.env` |
| **CI pipeline trigger** | A pipeline trigger token for your project, configured in Vunnix admin |
| **GitLab Runner** | Your project must have a CI runner capable of pulling Docker images |

## Step 1: Add the Bot Account

The Vunnix bot account needs Maintainer access to post review comments, create labels, set commit statuses, and create merge requests.

1. Navigate to your project in GitLab
2. Go to **Manage > Members**
3. Invite the Vunnix bot user with **Maintainer** role

## Step 2: Create a Pipeline Trigger Token

Vunnix uses the Pipeline Triggers API to start executor jobs in your project.

1. Navigate to your project in GitLab
2. Go to **Settings > CI/CD > Pipeline trigger tokens**
3. Click **Add trigger** and give it a description (e.g., "Vunnix AI Reviews")
4. Copy the generated token
5. In the Vunnix admin panel, add this token to your project configuration

## Step 3: Include the CI Template

Add the Vunnix CI template to your project's `.gitlab-ci.yml`:

```yaml
include:
  - remote: 'https://raw.githubusercontent.com/bepsvpt/vunnix/main/ci-template/vunnix.gitlab-ci.yml'

# Your existing CI/CD configuration continues below
stages:
  - build
  - test
  - deploy

# ... your other jobs ...
```

The executor image is hosted on GitHub Container Registry (public) and requires no authentication to pull.

## Step 4: Enable the Project in Vunnix

1. Log in to the Vunnix admin panel
2. Navigate to **Projects > Add Project**
3. Enter your GitLab project ID
4. Configure the pipeline trigger token (from Step 2)
5. Save — Vunnix will automatically create the webhook on your project

## Step 5: Verify the Integration

After setup, verify everything works:

1. **Webhook:** Check your project's **Settings > Webhooks** — a Vunnix webhook should be listed
2. **Test pipeline:** Create a test merge request. Vunnix should:
   - Receive the webhook event
   - Post a "Review in progress..." placeholder comment
   - Trigger a CI pipeline with the `vunnix-review` job
   - Post the AI review as comments on the MR

## Customization

### Override Job Settings

After including the template, you can override any setting:

```yaml
include:
  - remote: 'https://raw.githubusercontent.com/bepsvpt/vunnix/main/ci-template/vunnix.gitlab-ci.yml'

# Override timeout for large repositories
vunnix-review:
  timeout: 30 minutes
```

### Pin Executor Version

By default, the template uses the latest executor version. To pin a specific version:

```yaml
include:
  - remote: 'https://raw.githubusercontent.com/bepsvpt/vunnix/main/ci-template/vunnix.gitlab-ci.yml'

vunnix-review:
  variables:
    VUNNIX_EXECUTOR_VERSION: "2.0.7"
```

### Add Extra Stages

The Vunnix job runs in the `test` stage. If your pipeline doesn't have a `test` stage, add it:

```yaml
stages:
  - build
  - test      # Required for vunnix-review job
  - deploy
```

## Variable Reference

### Set by Vunnix Task Dispatcher (Automatic)

These variables are set automatically when Vunnix triggers the pipeline. **Do not set these manually.**

| Variable | Description |
|---|---|
| `VUNNIX_TASK_ID` | Task ID in the Vunnix database |
| `VUNNIX_TASK_TYPE` | Task type: `code_review`, `feature_dev`, `ui_adjustment`, `issue_discussion`, `security_audit` |
| `VUNNIX_INTENT` | Task intent (matches type or custom) |
| `VUNNIX_STRATEGY` | Review strategy: `frontend-review`, `backend-review`, `mixed-review`, `security-audit`, `ui-adjustment`, `issue-discussion`, `feature-dev` |
| `VUNNIX_SKILLS` | Comma-separated skill names to activate |
| `VUNNIX_TOKEN` | Task-scoped HMAC-SHA256 bearer token |
| `VUNNIX_API_URL` | Vunnix API base URL |
| `VUNNIX_QUESTION` | (Optional) Question text for `@ai ask` commands |
| `VUNNIX_ISSUE_IID` | (Optional) Issue IID for issue discussion tasks |

## Troubleshooting

## Local Runtime Profiles (ext-019)

When testing integration changes locally:

```bash
composer dev:fast    # fastest inner loop
composer dev:parity  # queue/reverb parity validation
```

Use `dev:parity` when validating webhook-to-task execution and real-time updates.

### Image Pull Failure

**Symptom:** CI job fails with `image pull failed` or `manifest unknown`.

**Causes:**
1. **Runner cannot reach ghcr.io** — Verify your GitLab Runner has outbound network access to `ghcr.io`
2. **Version mismatch** — The pinned `VUNNIX_EXECUTOR_VERSION` may not exist; check available tags at `ghcr.io/bepsvpt/vunnix/executor`

### Job Not Triggered

**Symptom:** Creating an MR doesn't start the `vunnix-review` job.

**Causes:**
1. **Webhook not configured** — Check your project's webhook settings
2. **Pipeline trigger token invalid** — Regenerate and update in Vunnix admin
3. **Template not included** — Verify your `.gitlab-ci.yml` includes the Vunnix template
4. **`test` stage missing** — Ensure your pipeline defines the `test` stage

### Job Runs But No Review Posted

**Symptom:** The `vunnix-review` job completes but no review comments appear on the MR.

**Causes:**
1. **Bot account lacks permissions** — Ensure Maintainer role on your project
2. **Token expired** — If the job was queued too long, the task-scoped token expires (20 min TTL). Vunnix will mark the task as `scheduling_timeout`
3. **API connectivity** — The runner must be able to reach the Vunnix API URL. Check firewall rules

### Debugging Executor Failures

1. Open the failed CI job in GitLab
2. Check the **job log** for `[vunnix-executor]` prefixed messages
3. Download **artifacts** — the `vunnix-artifacts/` directory contains:
   - `executor.log` — Full execution log
   - `claude-output.json` — Raw Claude CLI output
   - `formatted-result.json` — Formatted API payload
4. Common error codes in the log:
   - `missing_variables` — Required pipeline variables not set
   - `scheduling_timeout` — Task token expired before execution started
   - `cli_error` — Claude CLI failed (check API key, network)
   - `format_error` — Output parsing failed (executor bug)
   - `post_error` — Failed to POST results to Vunnix API
