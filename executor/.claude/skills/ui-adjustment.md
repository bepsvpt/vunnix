---
version: "1.0"
updated: "2026-02-14"
---
# UI Adjustment Skill

You are implementing a targeted UI adjustment requested by a Designer. Your goal is to make the specific visual change described in the task parameters with **minimal scope** — change only what is needed, preserve everything else. This is not a refactoring opportunity.

After making changes, you will capture a screenshot of the modified page so the Designer can verify the result directly in chat without waiting for deployment.

## Implementation Steps

### 1. Understand the Request

- Read the task parameters carefully: the description specifies exactly what visual change is needed (e.g., "increase card padding to 24px", "change header font to Inter", "fix alignment on mobile breakpoint")
- Identify the target component(s) and file(s) — read them fully before making changes
- If the request references a specific page or URL, note it for the screenshot step
- If the request is ambiguous, interpret it conservatively — make the smallest change that satisfies the description

### 2. Read Before Writing

- Read the target file(s) and their immediate dependencies (parent components, shared styles, design tokens)
- Understand the existing styling approach: scoped CSS, utility classes, CSS custom properties, or a design system
- Check for responsive breakpoints already in use — follow the same breakpoint values
- Identify design tokens (CSS custom properties like `--spacing-md`, `--color-primary`) — use them instead of hardcoding values

### 3. Make the Change

- **Minimize scope:** Change only the properties and elements described in the request. Do not refactor adjacent code, rename variables, reorganize imports, or "improve" unrelated styles.
- **Preserve existing styles:** Do not remove or modify CSS properties unrelated to the request. If adding a new property, place it logically within the existing rule order.
- **Use design tokens:** If the project defines CSS custom properties or theme variables, reference them rather than hardcoding color values, spacing, font sizes, or shadows. If no tokens exist for the needed value, use a literal value — do not create new tokens.
- **Scoped styles:** Keep styles in `<style scoped>` unless the change intentionally targets a global element. Never add `!important` unless overriding a third-party library where no other option exists.
- **Component boundaries:** If the change affects a child component's appearance, prefer modifying the child component's own styles over using deep selectors (`:deep()`, `::v-deep`). Only use deep selectors when you cannot modify the child (e.g., third-party component).

### 4. Check Responsive Breakpoints

After making the change, verify it works across breakpoints:

- **Desktop** (default): The primary target — ensure the change looks correct at standard viewport widths (1280px+)
- **Tablet** (768px–1279px): Check that the change does not break tablet layout. If the request specifies a tablet adjustment, implement it within the existing tablet media query.
- **Mobile** (< 768px): Check that the change does not break mobile layout. If the request specifies a mobile adjustment, implement it within the existing mobile media query.

If the project uses different breakpoint values, follow the project's convention (check existing media queries or a breakpoints config file).

Only add new responsive rules if the request explicitly mentions a specific breakpoint or if your change visibly breaks an existing breakpoint. Do not speculatively add responsive overrides.

### 5. Capture Screenshots (D131)

After implementing the change, capture a screenshot of the modified page for visual verification.

**Screenshot capture flow:**

1. Identify the target page URL from the task parameters or the project's CLAUDE.md configuration
2. Run `capture-screenshot.js` to capture the current state:

```bash
# Capture with dev server auto-start
node /executor/scripts/capture-screenshot.js \
  <page-url> \
  /tmp/screenshot-after.png \
  --start-server \
  --full-page \
  --wait 3000

# Capture at mobile viewport (if responsive change)
node /executor/scripts/capture-screenshot.js \
  <page-url> \
  /tmp/screenshot-after-mobile.png \
  --start-server \
  --width 375 \
  --height 812 \
  --full-page \
  --wait 3000
```

3. The script outputs JSON to stdout with the screenshot path and metadata
4. Read the screenshot PNG and encode it as base64 for inclusion in the result

**Capture options reference:**

| Option | Default | Description |
|---|---|---|
| `--start-server` | off | Start `npm run dev` and wait for it to be ready before capturing |
| `--server-port <n>` | 5173 | Port to wait for when starting the dev server |
| `--width <n>` | 1280 | Viewport width in pixels |
| `--height <n>` | 720 | Viewport height in pixels |
| `--full-page` | off | Capture the entire scrollable page, not just the viewport |
| `--wait <ms>` | 2000 | Wait time after page load for animations and lazy content |
| `--timeout <ms>` | 30000 | Navigation timeout |

**Graceful fallback:** If `capture-screenshot.js` fails (dev server cannot start, missing dependencies, Playwright error, page URL unreachable), **do not fail the entire task**. Log the error, skip the screenshot, and set `screenshot: null` in the result. The Designer will verify the change on the deployed dev site instead. The code change itself is the primary deliverable — the screenshot is a convenience.

### 6. Verify Before Committing

Before finalizing:

- Re-read the changed file(s) to confirm only the intended properties were modified
- If the project has a linter (eslint, stylelint), run it on the changed files and fix any errors introduced by your change
- Do not fix pre-existing linter warnings unrelated to your change

## What NOT to Do

- **Do not refactor:** Even if adjacent code is messy, leave it alone. The Designer requested a specific visual change, not a cleanup.
- **Do not add features:** If you notice missing functionality while making the change, ignore it. Stay within the task scope.
- **Do not change behavior:** If a button has a click handler, do not modify it. Only change visual properties.
- **Do not restructure HTML:** Unless the visual change requires it (e.g., adding a wrapper for flexbox layout), do not reorganize the template structure.
- **Do not modify tests:** Unless your change breaks an existing test, do not touch test files. If a snapshot test needs updating due to your CSS change, update only that snapshot.
- **Do not create new files:** Unless the change absolutely requires a new component extraction (rare for UI adjustments). Prefer modifying existing files.

## Output

Produce a JSON object matching the feature development / UI adjustment schema. The result must include:

```json
{
  "version": "1.0",
  "branch": "ai/fix-card-padding",
  "mr_title": "Fix card padding on dashboard",
  "mr_description": "Increased card padding from 16px to 24px on the dashboard overview cards as requested. Verified at desktop and mobile breakpoints.",
  "files_changed": [
    {
      "path": "src/components/DashboardCard.vue",
      "action": "modified",
      "summary": "Updated padding from 16px to 24px in .card-container"
    }
  ],
  "tests_added": false,
  "screenshot": "<base64-encoded-png or null if capture failed>",
  "screenshot_mobile": "<base64-encoded-png or null if not applicable>",
  "notes": "Used existing --spacing-lg token (24px) instead of hardcoded value."
}
```

**Field guidance:**

- `branch`: Use the branch name created for this task (provided in task parameters or created by the entrypoint script)
- `mr_title`: Short, descriptive title for the merge request (imperative mood)
- `mr_description`: Brief explanation of what was changed and why, written for the Engineer who will review and merge
- `files_changed`: List every file you modified with a one-line summary of the change
- `tests_added`: `false` for most UI adjustments; `true` only if you added or updated visual regression tests
- `screenshot`: Base64-encoded PNG of the page after your change, or `null` if capture failed
- `screenshot_mobile`: Base64-encoded PNG at mobile viewport if the change involved responsive behavior, or `null`
- `notes`: Any additional context — design token usage, responsive considerations, edge cases, or why you chose a particular approach
