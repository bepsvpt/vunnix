# Vunnix Project Setup Guide

Enable AI-powered code reviews and task execution for your GitLab project.

## Prerequisites

Before integrating Vunnix, verify the following:

| Requirement | Details |
|---|---|
| **Vunnix instance** | A running Vunnix server with a configured Anthropic API key |
| **Vunnix project visibility** | The Vunnix GitLab project must be set to `internal` or `public` visibility so your project's CI runners can pull the executor image from its Container Registry (D150) |
| **Bot account** | A dedicated Vunnix bot GitLab user with **Maintainer** role on your project |
| **Bot PAT** | The bot account needs a Personal Access Token with `api` scope, configured in Vunnix's `.env` |
| **CI pipeline trigger** | A pipeline trigger token for your project, configured in Vunnix admin |
| **GitLab Runner** | Your project must have a CI runner capable of pulling Docker images |

## Step 1: Verify Registry Access

The Vunnix executor image is stored in the Vunnix project's GitLab Container Registry. Your project's CI runners need to pull this image.

**How it works:** GitLab CI jobs automatically authenticate to the Container Registry using `CI_JOB_TOKEN`. This token can pull images from any `internal` or `public` project on the same GitLab instance.

**Verify Vunnix project visibility:**

1. Navigate to the Vunnix project in GitLab
2. Go to **Settings > General > Visibility, project features, permissions**
3. Confirm the project visibility is set to **Internal** (or Public)

If the Vunnix project must remain **Private**, see [Private Registry Fallback](#private-registry-fallback) below.

## Step 2: Add the Bot Account

The Vunnix bot account needs Maintainer access to post review comments, create labels, set commit statuses, and create merge requests.

1. Navigate to your project in GitLab
2. Go to **Manage > Members**
3. Invite the Vunnix bot user with **Maintainer** role

## Step 3: Create a Pipeline Trigger Token

Vunnix uses the Pipeline Triggers API to start executor jobs in your project.

1. Navigate to your project in GitLab
2. Go to **Settings > CI/CD > Pipeline trigger tokens**
3. Click **Add trigger** and give it a description (e.g., "Vunnix AI Reviews")
4. Copy the generated token
5. In the Vunnix admin panel, add this token to your project configuration

## Step 4: Include the CI Template

Add the Vunnix CI template to your project's `.gitlab-ci.yml`:

### Option A: Project Reference (Recommended)

Use this when the Vunnix project is on the same GitLab instance:

```yaml
include:
  - project: 'your-group/vunnix'
    file: '/ci-template/vunnix.gitlab-ci.yml'

# Your existing CI/CD configuration continues below
stages:
  - build
  - test
  - deploy

# ... your other jobs ...
```

Replace `your-group/vunnix` with the actual path to the Vunnix project.

### Option B: Remote URL

Use this if project references are not available:

```yaml
include:
  - remote: 'https://gitlab.example.com/your-group/vunnix/-/raw/main/ci-template/vunnix.gitlab-ci.yml'
```

### Option C: Copy the Template

Copy the contents of `ci-template/vunnix.gitlab-ci.yml` directly into your `.gitlab-ci.yml`. This requires manual updates when the template changes.

## Step 5: Configure the Registry Path

The template needs to know where to pull the executor image from. Set the `VUNNIX_PROJECT_PATH` variable:

1. Navigate to your project in GitLab
2. Go to **Settings > CI/CD > Variables**
3. Add a variable:
   - **Key:** `VUNNIX_PROJECT_PATH`
   - **Value:** The full path to the Vunnix project (e.g., `your-group/vunnix`)
   - **Protected:** No (the variable must be available on all branches)
   - **Masked:** No

The executor image is pulled as:
```
registry.gitlab.example.com/your-group/vunnix/vunnix/executor:1.0.0
```

## Step 6: Enable the Project in Vunnix

1. Log in to the Vunnix admin panel
2. Navigate to **Projects > Add Project**
3. Enter your GitLab project ID
4. Configure the pipeline trigger token (from Step 3)
5. Save — Vunnix will automatically create the webhook on your project

## Step 7: Verify the Integration

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
  - project: 'your-group/vunnix'
    file: '/ci-template/vunnix.gitlab-ci.yml'

# Override timeout for large repositories
vunnix-review:
  timeout: 30 minutes
```

### Pin Executor Version

By default, the template uses executor version `1.0.0`. To pin a specific version:

```yaml
include:
  - project: 'your-group/vunnix'
    file: '/ci-template/vunnix.gitlab-ci.yml'

vunnix-review:
  variables:
    VUNNIX_EXECUTOR_VERSION: "1.2.0"
```

### Add Extra Stages

The Vunnix job runs in the `test` stage. If your pipeline doesn't have a `test` stage, add it:

```yaml
stages:
  - build
  - test      # Required for vunnix-review job
  - deploy
```

## Private Registry Fallback

If the Vunnix project must remain **Private**, CI job tokens from other projects cannot pull the executor image. Use a deploy token instead:

1. In the **Vunnix project**, go to **Settings > Repository > Deploy tokens**
2. Create a deploy token with `read_registry` scope
3. In **your project**, go to **Settings > CI/CD > Variables** and add:
   - `VUNNIX_DEPLOY_USER` = deploy token username
   - `VUNNIX_DEPLOY_TOKEN` = deploy token value (masked)

Then override the job's `before_script` in your `.gitlab-ci.yml`:

```yaml
vunnix-review:
  before_script:
    - echo "${VUNNIX_DEPLOY_TOKEN}" | docker login "${CI_SERVER_HOST}" -u "${VUNNIX_DEPLOY_USER}" --password-stdin
```

> **Note:** This approach requires runners configured with Docker-in-Docker or shell executors. The recommended approach is to set the Vunnix project to `internal` visibility.

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

### Set by Project (Manual Configuration)

| Variable | Where to Set | Description |
|---|---|---|
| `VUNNIX_PROJECT_PATH` | CI/CD Variables | Path to Vunnix project (e.g., `my-group/vunnix`) |
| `VUNNIX_DEPLOY_USER` | CI/CD Variables | (Private registry only) Deploy token username |
| `VUNNIX_DEPLOY_TOKEN` | CI/CD Variables | (Private registry only) Deploy token value |

## Troubleshooting

### Image Pull Failure

**Symptom:** CI job fails with `image pull failed` or `manifest unknown`.

**Causes:**
1. **Vunnix project is Private** — Set it to `internal` or configure deploy token (see [Private Registry Fallback](#private-registry-fallback))
2. **`VUNNIX_PROJECT_PATH` is wrong** — Verify the variable matches the actual Vunnix project path
3. **Executor image not built** — Verify the executor image exists in the Vunnix project's Container Registry
4. **Version mismatch** — The pinned `VUNNIX_EXECUTOR_VERSION` may not exist; check available tags

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
