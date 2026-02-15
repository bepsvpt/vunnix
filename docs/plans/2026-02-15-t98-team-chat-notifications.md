# T98: Team Chat Notifications — Webhook Integration

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Enable admins to configure a team chat webhook (Slack/Mattermost/Google Chat/generic) so Vunnix can push task completion and admin alert notifications to a shared channel.

**Architecture:** A `TeamChatNotificationService` orchestrates sending notifications via HTTP POST to a configured webhook URL. Platform-specific formatter classes produce the correct JSON payload (Slack Block Kit, Mattermost attachments, Google Chat cards, generic plain text). Settings are stored via `GlobalSetting` (existing model). The admin settings Vue form already has webhook URL and platform fields — this task adds the enabled toggle, test-webhook endpoint, and notification category toggles. T99 will wire actual events to this service.

**Tech Stack:** Laravel 11 (HTTP Client, Service classes), Pest (unit + feature tests), Vue 3 + Pinia (admin settings form), verify_m5.py structural checks.

**Spec references:** vunnix.md §18.2, D110, D116, D120. M5 Verification: "Chat formatters" unit test row.

---

### Task 1: Create TeamChatNotificationService with formatter interface

**Files:**
- Create: `app/Services/TeamChat/TeamChatNotificationService.php`
- Create: `app/Services/TeamChat/ChatFormatterInterface.php`
- Create: `app/Services/TeamChat/SlackFormatter.php`
- Create: `app/Services/TeamChat/MattermostFormatter.php`
- Create: `app/Services/TeamChat/GoogleChatFormatter.php`
- Create: `app/Services/TeamChat/GenericFormatter.php`
- Test: `tests/Unit/Services/TeamChat/SlackFormatterTest.php`
- Test: `tests/Unit/Services/TeamChat/MattermostFormatterTest.php`
- Test: `tests/Unit/Services/TeamChat/GoogleChatFormatterTest.php`
- Test: `tests/Unit/Services/TeamChat/GenericFormatterTest.php`

**Step 1: Write ChatFormatterInterface**

```php
<?php

namespace App\Services\TeamChat;

interface ChatFormatterInterface
{
    /**
     * Format a notification into the platform-specific webhook payload.
     *
     * @param  string  $type     Notification type: 'task_completed', 'task_failed', 'alert'
     * @param  string  $message  Plain text summary (always included as fallback)
     * @param  array   $context  Additional data: urgency, project, links, etc.
     * @return array   JSON-encodable payload for HTTP POST to webhook URL
     */
    public function format(string $type, string $message, array $context = []): array;
}
```

**Step 2: Write SlackFormatter**

Slack uses Block Kit JSON. Reference: https://api.slack.com/reference/block-kit

```php
<?php

namespace App\Services\TeamChat;

class SlackFormatter implements ChatFormatterInterface
{
    private const URGENCY_COLORS = [
        'high' => '#dc2626',    // red
        'medium' => '#f59e0b',  // amber
        'low' => '#22c55e',     // green
        'info' => '#3b82f6',    // blue
    ];

    public function format(string $type, string $message, array $context = []): array
    {
        $urgency = $context['urgency'] ?? 'info';
        $color = self::URGENCY_COLORS[$urgency] ?? self::URGENCY_COLORS['info'];

        $blocks = [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $message,
                ],
            ],
        ];

        // Add action links as buttons if present
        if (! empty($context['links'])) {
            $elements = [];
            foreach ($context['links'] as $link) {
                $elements[] = [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => $link['label'],
                    ],
                    'url' => $link['url'],
                ];
            }
            $blocks[] = [
                'type' => 'actions',
                'elements' => $elements,
            ];
        }

        return [
            'text' => $message,
            'attachments' => [
                [
                    'color' => $color,
                    'blocks' => $blocks,
                ],
            ],
        ];
    }
}
```

**Step 3: Write MattermostFormatter**

Mattermost uses Slack-compatible attachments with some differences.

```php
<?php

namespace App\Services\TeamChat;

class MattermostFormatter implements ChatFormatterInterface
{
    private const URGENCY_COLORS = [
        'high' => '#dc2626',
        'medium' => '#f59e0b',
        'low' => '#22c55e',
        'info' => '#3b82f6',
    ];

    public function format(string $type, string $message, array $context = []): array
    {
        $urgency = $context['urgency'] ?? 'info';
        $color = self::URGENCY_COLORS[$urgency] ?? self::URGENCY_COLORS['info'];

        $fields = [];
        if (! empty($context['project'])) {
            $fields[] = ['short' => true, 'title' => 'Project', 'value' => $context['project']];
        }
        if (! empty($context['urgency'])) {
            $fields[] = ['short' => true, 'title' => 'Urgency', 'value' => ucfirst($context['urgency'])];
        }

        $attachment = [
            'color' => $color,
            'text' => $message,
            'fields' => $fields,
        ];

        // Add action links as text footer
        if (! empty($context['links'])) {
            $linkTexts = array_map(fn ($l) => "[{$l['label']}]({$l['url']})", $context['links']);
            $attachment['text'] .= "\n\n" . implode(' | ', $linkTexts);
        }

        return [
            'text' => $message,
            'attachments' => [$attachment],
        ];
    }
}
```

**Step 4: Write GoogleChatFormatter**

Google Chat uses Cards v2.

```php
<?php

namespace App\Services\TeamChat;

class GoogleChatFormatter implements ChatFormatterInterface
{
    public function format(string $type, string $message, array $context = []): array
    {
        $header = match ($type) {
            'alert' => 'Vunnix Alert',
            'task_failed' => 'Task Failed',
            default => 'Vunnix Notification',
        };

        $widgets = [
            [
                'textParagraph' => [
                    'text' => $message,
                ],
            ],
        ];

        // Add action links as buttons
        if (! empty($context['links'])) {
            $buttons = [];
            foreach ($context['links'] as $link) {
                $buttons[] = [
                    'text' => $link['label'],
                    'onClick' => [
                        'openLink' => ['url' => $link['url']],
                    ],
                ];
            }
            $widgets[] = [
                'buttonList' => [
                    'buttons' => $buttons,
                ],
            ];
        }

        return [
            'text' => $message,
            'cardsV2' => [
                [
                    'cardId' => 'vunnix-notification',
                    'card' => [
                        'header' => [
                            'title' => $header,
                        ],
                        'sections' => [
                            ['widgets' => $widgets],
                        ],
                    ],
                ],
            ],
        ];
    }
}
```

**Step 5: Write GenericFormatter**

```php
<?php

namespace App\Services\TeamChat;

class GenericFormatter implements ChatFormatterInterface
{
    public function format(string $type, string $message, array $context = []): array
    {
        $text = $message;

        if (! empty($context['links'])) {
            $linkTexts = array_map(fn ($l) => "{$l['label']}: {$l['url']}", $context['links']);
            $text .= "\n\n" . implode("\n", $linkTexts);
        }

        return ['text' => $text];
    }
}
```

**Step 6: Write TeamChatNotificationService**

```php
<?php

namespace App\Services\TeamChat;

use App\Models\GlobalSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TeamChatNotificationService
{
    private const FORMATTERS = [
        'slack' => SlackFormatter::class,
        'mattermost' => MattermostFormatter::class,
        'google_chat' => GoogleChatFormatter::class,
        'generic' => GenericFormatter::class,
    ];

    /**
     * Send a notification to the configured team chat webhook.
     *
     * Returns true if sent successfully, false if disabled/unconfigured/failed.
     */
    public function send(string $type, string $message, array $context = []): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $webhookUrl = GlobalSetting::get('team_chat_webhook_url', '');
        if (empty($webhookUrl)) {
            return false;
        }

        // Check notification category toggle
        $category = $context['category'] ?? $type;
        if (! $this->isCategoryEnabled($category)) {
            return false;
        }

        $platform = GlobalSetting::get('team_chat_platform', 'generic');
        $formatter = $this->resolveFormatter($platform);
        $payload = $formatter->format($type, $message, $context);

        try {
            $response = Http::timeout(10)->post($webhookUrl, $payload);

            if ($response->failed()) {
                Log::warning('Team chat notification failed', [
                    'status' => $response->status(),
                    'type' => $type,
                    'platform' => $platform,
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Team chat notification error', [
                'error' => $e->getMessage(),
                'type' => $type,
                'platform' => $platform,
            ]);

            return false;
        }
    }

    /**
     * Send a test notification to verify webhook connectivity.
     */
    public function sendTest(string $webhookUrl, string $platform): bool
    {
        $formatter = $this->resolveFormatter($platform);
        $payload = $formatter->format('test', '✅ Vunnix webhook test — connection successful!', [
            'urgency' => 'info',
        ]);

        try {
            $response = Http::timeout(10)->post($webhookUrl, $payload);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function isEnabled(): bool
    {
        return (bool) GlobalSetting::get('team_chat_enabled', false);
    }

    public function isCategoryEnabled(string $category): bool
    {
        $categories = GlobalSetting::get('team_chat_categories', []);

        // If no categories configured, all are enabled by default (§18.2)
        if (empty($categories)) {
            return true;
        }

        return ! empty($categories[$category]);
    }

    public function resolveFormatter(string $platform): ChatFormatterInterface
    {
        $class = self::FORMATTERS[$platform] ?? GenericFormatter::class;

        return new $class();
    }
}
```

**Step 7: Write unit tests for all four formatters**

Each formatter test verifies: correct structure, urgency colors, action links, and plain text fallback.

File: `tests/Unit/Services/TeamChat/SlackFormatterTest.php`
```php
<?php

use App\Services\TeamChat\SlackFormatter;

it('formats basic notification with text fallback', function () {
    $formatter = new SlackFormatter();
    $payload = $formatter->format('task_completed', '🤖 Review complete on **project-x**');

    expect($payload)->toHaveKey('text', '🤖 Review complete on **project-x**');
    expect($payload)->toHaveKey('attachments');
    expect($payload['attachments'][0])->toHaveKey('color');
    expect($payload['attachments'][0])->toHaveKey('blocks');
});

it('uses correct color for high urgency', function () {
    $formatter = new SlackFormatter();
    $payload = $formatter->format('alert', 'API outage', ['urgency' => 'high']);

    expect($payload['attachments'][0]['color'])->toBe('#dc2626');
});

it('uses correct color for medium urgency', function () {
    $formatter = new SlackFormatter();
    $payload = $formatter->format('alert', 'Queue depth', ['urgency' => 'medium']);

    expect($payload['attachments'][0]['color'])->toBe('#f59e0b');
});

it('uses correct color for info urgency', function () {
    $formatter = new SlackFormatter();
    $payload = $formatter->format('task_completed', 'Done', ['urgency' => 'info']);

    expect($payload['attachments'][0]['color'])->toBe('#3b82f6');
});

it('includes action link buttons', function () {
    $formatter = new SlackFormatter();
    $payload = $formatter->format('task_completed', 'Review done', [
        'links' => [
            ['label' => 'View MR', 'url' => 'https://gitlab.example.com/mr/1'],
            ['label' => 'Dashboard', 'url' => 'https://vunnix.example.com'],
        ],
    ]);

    $blocks = $payload['attachments'][0]['blocks'];
    $actionsBlock = $blocks[1];
    expect($actionsBlock['type'])->toBe('actions');
    expect($actionsBlock['elements'])->toHaveCount(2);
    expect($actionsBlock['elements'][0]['url'])->toBe('https://gitlab.example.com/mr/1');
});
```

File: `tests/Unit/Services/TeamChat/MattermostFormatterTest.php`
```php
<?php

use App\Services\TeamChat\MattermostFormatter;

it('formats notification with text and attachments', function () {
    $formatter = new MattermostFormatter();
    $payload = $formatter->format('task_completed', 'Review complete');

    expect($payload)->toHaveKey('text', 'Review complete');
    expect($payload)->toHaveKey('attachments');
    expect($payload['attachments'][0])->toHaveKey('color');
    expect($payload['attachments'][0])->toHaveKey('text');
});

it('uses correct color for urgency levels', function () {
    $formatter = new MattermostFormatter();

    $high = $formatter->format('alert', 'Error', ['urgency' => 'high']);
    expect($high['attachments'][0]['color'])->toBe('#dc2626');

    $medium = $formatter->format('alert', 'Warning', ['urgency' => 'medium']);
    expect($medium['attachments'][0]['color'])->toBe('#f59e0b');
});

it('includes project and urgency fields', function () {
    $formatter = new MattermostFormatter();
    $payload = $formatter->format('alert', 'Queue depth growing', [
        'urgency' => 'medium',
        'project' => 'my-app',
    ]);

    $fields = $payload['attachments'][0]['fields'];
    expect($fields)->toHaveCount(2);
    expect($fields[0]['title'])->toBe('Project');
    expect($fields[0]['value'])->toBe('my-app');
});

it('appends action links as markdown', function () {
    $formatter = new MattermostFormatter();
    $payload = $formatter->format('task_completed', 'Done', [
        'links' => [['label' => 'View MR', 'url' => 'https://example.com/mr/1']],
    ]);

    expect($payload['attachments'][0]['text'])->toContain('[View MR](https://example.com/mr/1)');
});
```

File: `tests/Unit/Services/TeamChat/GoogleChatFormatterTest.php`
```php
<?php

use App\Services\TeamChat\GoogleChatFormatter;

it('formats notification with text and cardsV2', function () {
    $formatter = new GoogleChatFormatter();
    $payload = $formatter->format('task_completed', 'Review done');

    expect($payload)->toHaveKey('text', 'Review done');
    expect($payload)->toHaveKey('cardsV2');
    expect($payload['cardsV2'][0]['card']['header']['title'])->toBe('Vunnix Notification');
});

it('uses Alert header for alert type', function () {
    $formatter = new GoogleChatFormatter();
    $payload = $formatter->format('alert', 'API outage detected');

    expect($payload['cardsV2'][0]['card']['header']['title'])->toBe('Vunnix Alert');
});

it('uses Task Failed header for task_failed type', function () {
    $formatter = new GoogleChatFormatter();
    $payload = $formatter->format('task_failed', 'Task failed');

    expect($payload['cardsV2'][0]['card']['header']['title'])->toBe('Task Failed');
});

it('includes action link buttons', function () {
    $formatter = new GoogleChatFormatter();
    $payload = $formatter->format('task_completed', 'Done', [
        'links' => [['label' => 'View MR', 'url' => 'https://example.com/mr/1']],
    ]);

    $widgets = $payload['cardsV2'][0]['card']['sections'][0]['widgets'];
    expect($widgets)->toHaveCount(2);
    $buttons = $widgets[1]['buttonList']['buttons'];
    expect($buttons[0]['text'])->toBe('View MR');
    expect($buttons[0]['onClick']['openLink']['url'])->toBe('https://example.com/mr/1');
});
```

File: `tests/Unit/Services/TeamChat/GenericFormatterTest.php`
```php
<?php

use App\Services\TeamChat\GenericFormatter;

it('formats notification as plain text', function () {
    $formatter = new GenericFormatter();
    $payload = $formatter->format('task_completed', 'Review done');

    expect($payload)->toBe(['text' => 'Review done']);
});

it('appends action links as plain text URLs', function () {
    $formatter = new GenericFormatter();
    $payload = $formatter->format('task_completed', 'Done', [
        'links' => [
            ['label' => 'View MR', 'url' => 'https://example.com/mr/1'],
            ['label' => 'Dashboard', 'url' => 'https://vunnix.example.com'],
        ],
    ]);

    expect($payload['text'])->toContain('View MR: https://example.com/mr/1');
    expect($payload['text'])->toContain('Dashboard: https://vunnix.example.com');
});
```

**Step 8: Run unit tests to verify formatters pass**

Run: `php artisan test tests/Unit/Services/TeamChat/ --parallel`
Expected: All formatter tests pass.

**Step 9: Commit**

```bash
git add app/Services/TeamChat/ tests/Unit/Services/TeamChat/
git commit --no-gpg-sign -m "$(cat <<'EOF'
T98.1: Add team chat formatters (Slack, Mattermost, Google Chat, generic) and notification service

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Write TeamChatNotificationService feature test

**Files:**
- Test: `tests/Feature/Services/TeamChatNotificationServiceTest.php`

**Step 1: Write the feature test**

This tests the full service with `Http::fake()` and `GlobalSetting` persistence.

```php
<?php

use App\Models\GlobalSetting;
use App\Services\TeamChat\TeamChatNotificationService;
use Illuminate\Support\Facades\Http;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Http::fake([
        'hooks.slack.com/*' => Http::response('ok', 200),
        'mattermost.example.com/*' => Http::response('ok', 200),
        'chat.googleapis.com/*' => Http::response('ok', 200),
        'generic.example.com/*' => Http::response('ok', 200),
        '*' => Http::response('ok', 200),
    ]);
});

it('returns false when team chat is disabled', function () {
    GlobalSetting::set('team_chat_enabled', false, 'boolean');
    GlobalSetting::set('team_chat_webhook_url', 'https://hooks.slack.com/services/T/B/x', 'string');

    $service = new TeamChatNotificationService();
    $result = $service->send('task_completed', 'Review done');

    expect($result)->toBeFalse();
    Http::assertNothingSent();
});

it('returns false when webhook URL is empty', function () {
    GlobalSetting::set('team_chat_enabled', true, 'boolean');
    GlobalSetting::set('team_chat_webhook_url', '', 'string');

    $service = new TeamChatNotificationService();
    $result = $service->send('task_completed', 'Review done');

    expect($result)->toBeFalse();
    Http::assertNothingSent();
});

it('sends Slack-formatted notification when enabled', function () {
    GlobalSetting::set('team_chat_enabled', true, 'boolean');
    GlobalSetting::set('team_chat_webhook_url', 'https://hooks.slack.com/services/T/B/x', 'string');
    GlobalSetting::set('team_chat_platform', 'slack', 'string');

    $service = new TeamChatNotificationService();
    $result = $service->send('task_completed', '🤖 Review complete', [
        'urgency' => 'info',
    ]);

    expect($result)->toBeTrue();
    Http::assertSent(function ($request) {
        return $request->url() === 'https://hooks.slack.com/services/T/B/x'
            && $request['text'] === '🤖 Review complete'
            && isset($request['attachments']);
    });
});

it('sends Mattermost-formatted notification', function () {
    GlobalSetting::set('team_chat_enabled', true, 'boolean');
    GlobalSetting::set('team_chat_webhook_url', 'https://mattermost.example.com/hooks/abc', 'string');
    GlobalSetting::set('team_chat_platform', 'mattermost', 'string');

    $service = new TeamChatNotificationService();
    $result = $service->send('alert', 'API outage', ['urgency' => 'high']);

    expect($result)->toBeTrue();
    Http::assertSent(function ($request) {
        return $request->url() === 'https://mattermost.example.com/hooks/abc'
            && $request['text'] === 'API outage'
            && isset($request['attachments']);
    });
});

it('sends Google Chat-formatted notification', function () {
    GlobalSetting::set('team_chat_enabled', true, 'boolean');
    GlobalSetting::set('team_chat_webhook_url', 'https://chat.googleapis.com/v1/spaces/x/messages?key=y', 'string');
    GlobalSetting::set('team_chat_platform', 'google_chat', 'string');

    $service = new TeamChatNotificationService();
    $result = $service->send('task_completed', 'Done');

    expect($result)->toBeTrue();
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'chat.googleapis.com')
            && $request['text'] === 'Done'
            && isset($request['cardsV2']);
    });
});

it('sends generic plain text notification', function () {
    GlobalSetting::set('team_chat_enabled', true, 'boolean');
    GlobalSetting::set('team_chat_webhook_url', 'https://generic.example.com/webhook', 'string');
    GlobalSetting::set('team_chat_platform', 'generic', 'string');

    $service = new TeamChatNotificationService();
    $result = $service->send('task_completed', 'Done');

    expect($result)->toBeTrue();
    Http::assertSent(function ($request) {
        return $request->url() === 'https://generic.example.com/webhook'
            && $request['text'] === 'Done';
    });
});

it('falls back to generic formatter for unknown platform', function () {
    GlobalSetting::set('team_chat_enabled', true, 'boolean');
    GlobalSetting::set('team_chat_webhook_url', 'https://generic.example.com/webhook', 'string');
    GlobalSetting::set('team_chat_platform', 'unknown_platform', 'string');

    $service = new TeamChatNotificationService();
    $result = $service->send('task_completed', 'Done');

    expect($result)->toBeTrue();
    Http::assertSent(function ($request) {
        return $request['text'] === 'Done'
            && ! isset($request['attachments'])
            && ! isset($request['cardsV2']);
    });
});

it('returns false and logs warning on HTTP failure', function () {
    Http::fake([
        '*' => Http::response('error', 500),
    ]);

    GlobalSetting::set('team_chat_enabled', true, 'boolean');
    GlobalSetting::set('team_chat_webhook_url', 'https://hooks.slack.com/fail', 'string');
    GlobalSetting::set('team_chat_platform', 'slack', 'string');

    $service = new TeamChatNotificationService();
    $result = $service->send('alert', 'Test');

    expect($result)->toBeFalse();
});

it('respects notification category toggles', function () {
    GlobalSetting::set('team_chat_enabled', true, 'boolean');
    GlobalSetting::set('team_chat_webhook_url', 'https://hooks.slack.com/services/T/B/x', 'string');
    GlobalSetting::set('team_chat_platform', 'slack', 'string');
    GlobalSetting::set('team_chat_categories', [
        'task_completed' => true,
        'alert' => false,
    ], 'json');

    $service = new TeamChatNotificationService();

    // task_completed enabled
    $result = $service->send('task_completed', 'Review done', ['category' => 'task_completed']);
    expect($result)->toBeTrue();

    // alert disabled
    $result = $service->send('alert', 'Queue depth', ['category' => 'alert']);
    expect($result)->toBeFalse();
});

it('enables all categories by default when none configured', function () {
    GlobalSetting::set('team_chat_enabled', true, 'boolean');
    GlobalSetting::set('team_chat_webhook_url', 'https://hooks.slack.com/services/T/B/x', 'string');
    GlobalSetting::set('team_chat_platform', 'slack', 'string');
    // No team_chat_categories setting

    $service = new TeamChatNotificationService();
    expect($service->isCategoryEnabled('task_completed'))->toBeTrue();
    expect($service->isCategoryEnabled('alert'))->toBeTrue();
});

it('sendTest posts to the provided URL without checking GlobalSetting', function () {
    Http::fake([
        '*' => Http::response('ok', 200),
    ]);

    $service = new TeamChatNotificationService();
    $result = $service->sendTest('https://hooks.slack.com/test', 'slack');

    expect($result)->toBeTrue();
    Http::assertSent(function ($request) {
        return $request->url() === 'https://hooks.slack.com/test'
            && str_contains($request['text'], 'Vunnix webhook test');
    });
});

it('sendTest returns false on failure', function () {
    Http::fake([
        '*' => Http::response('error', 403),
    ]);

    $service = new TeamChatNotificationService();
    $result = $service->sendTest('https://hooks.slack.com/bad', 'slack');

    expect($result)->toBeFalse();
});
```

**Step 2: Run feature tests**

Run: `php artisan test tests/Feature/Services/TeamChatNotificationServiceTest.php`
Expected: All tests pass.

**Step 3: Commit**

```bash
git add tests/Feature/Services/TeamChatNotificationServiceTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T98.2: Add TeamChatNotificationService feature tests

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Add team_chat_enabled and team_chat_categories settings + test webhook endpoint

**Files:**
- Modify: `app/Models/GlobalSetting.php` — add defaults for new settings
- Modify: `app/Http/Controllers/Api/AdminSettingsController.php` — add `testWebhook` method
- Modify: `routes/api.php` — add test-webhook route
- Modify: `resources/js/components/AdminGlobalSettings.vue` — add enabled toggle, categories, test button
- Modify: `resources/js/stores/admin.js` — add `testWebhook` action
- Test: `tests/Feature/Http/Controllers/Api/AdminSettingsControllerWebhookTest.php`

**Step 1: Add defaults in GlobalSetting**

In `app/Models/GlobalSetting.php`, add to the `defaults()` array:

```php
'team_chat_enabled' => false,
'team_chat_webhook_url' => '',
'team_chat_platform' => 'slack',
'team_chat_categories' => [
    'task_completed' => true,
    'task_failed' => true,
    'alert' => true,
],
```

**Step 2: Add testWebhook method to AdminSettingsController**

```php
public function testWebhook(Request $request): JsonResponse
{
    $this->authorizeSettingsAdmin($request);

    $request->validate([
        'webhook_url' => ['required', 'url', 'max:1000'],
        'platform' => ['required', 'string', 'in:slack,mattermost,google_chat,generic'],
    ]);

    $service = new \App\Services\TeamChat\TeamChatNotificationService();
    $success = $service->sendTest($request->input('webhook_url'), $request->input('platform'));

    return response()->json([
        'success' => $success,
        'message' => $success ? 'Test notification sent successfully.' : 'Failed to send test notification. Check the webhook URL.',
    ]);
}
```

Add `use App\Services\TeamChat\TeamChatNotificationService;` import at top.

**Step 3: Add route in api.php**

Inside the `admin/settings` section, add:

```php
Route::post('/admin/settings/test-webhook', [AdminSettingsController::class, 'testWebhook'])
    ->name('api.admin.settings.test-webhook');
```

**Step 4: Update AdminGlobalSettings.vue**

Add to `form` ref:
```js
team_chat_enabled: false,
team_chat_categories: {
    task_completed: true,
    task_failed: true,
    alert: true,
},
```

Add to `handleSave` settingsList:
```js
{ key: 'team_chat_enabled', value: form.value.team_chat_enabled, type: 'boolean' },
{ key: 'team_chat_categories', value: form.value.team_chat_categories, type: 'json' },
```

Add test webhook functionality:
```js
const testingWebhook = ref(false);
const testWebhookResult = ref(null);

async function handleTestWebhook() {
    testingWebhook.value = true;
    testWebhookResult.value = null;

    const result = await admin.testWebhook(
        form.value.team_chat_webhook_url,
        form.value.team_chat_platform
    );

    testingWebhook.value = false;
    testWebhookResult.value = result;
    setTimeout(() => { testWebhookResult.value = null; }, 5000);
}
```

Update template — add enabled toggle, category checkboxes, and test button to the Team Chat Notifications section:

```html
<!-- Team Chat Notifications -->
<div class="mb-6 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-testid="section-team-chat">
  <h3 class="text-sm font-medium mb-3">Team Chat Notifications</h3>
  <div class="space-y-3">
    <!-- Enabled toggle -->
    <div class="flex items-center gap-2">
      <input type="checkbox" v-model="form.team_chat_enabled" id="team-chat-enabled" class="rounded border-zinc-300 dark:border-zinc-600" data-testid="setting-team_chat_enabled" />
      <label for="team-chat-enabled" class="text-xs font-medium text-zinc-600 dark:text-zinc-400">Enable team chat notifications</label>
    </div>
    <div>
      <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Webhook URL</label>
      <input v-model="form.team_chat_webhook_url" type="url" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" placeholder="https://hooks.slack.com/services/..." data-testid="setting-team_chat_webhook_url" />
    </div>
    <div>
      <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Platform</label>
      <select v-model="form.team_chat_platform" class="w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800" data-testid="setting-team_chat_platform">
        <option v-for="opt in platformOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
      </select>
    </div>
    <!-- Notification categories -->
    <div>
      <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400 mb-1">Notification Categories</label>
      <div class="flex flex-wrap gap-3" data-testid="setting-team_chat_categories">
        <label class="flex items-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-400">
          <input type="checkbox" v-model="form.team_chat_categories.task_completed" class="rounded border-zinc-300 dark:border-zinc-600" /> Task completed
        </label>
        <label class="flex items-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-400">
          <input type="checkbox" v-model="form.team_chat_categories.task_failed" class="rounded border-zinc-300 dark:border-zinc-600" /> Task failed
        </label>
        <label class="flex items-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-400">
          <input type="checkbox" v-model="form.team_chat_categories.alert" class="rounded border-zinc-300 dark:border-zinc-600" /> Admin alerts
        </label>
      </div>
    </div>
    <!-- Test webhook button -->
    <div class="flex items-center gap-2">
      <button
        class="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-100 disabled:opacity-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-700"
        data-testid="test-webhook-btn"
        :disabled="testingWebhook || !form.team_chat_webhook_url"
        @click="handleTestWebhook"
      >
        {{ testingWebhook ? 'Testing...' : 'Test Webhook' }}
      </button>
      <span v-if="testWebhookResult !== null" class="text-xs" :class="testWebhookResult.success ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'" data-testid="test-webhook-result">
        {{ testWebhookResult.message }}
      </span>
    </div>
  </div>
</div>
```

**Step 5: Add testWebhook action to admin store**

In `resources/js/stores/admin.js`, add:

```js
async testWebhook(webhookUrl, platform) {
    try {
        const response = await axios.post('/api/v1/admin/settings/test-webhook', {
            webhook_url: webhookUrl,
            platform: platform,
        });
        return response.data;
    } catch (error) {
        return {
            success: false,
            message: error.response?.data?.message || 'Failed to test webhook.',
        };
    }
},
```

**Step 6: Write API test for test-webhook endpoint**

File: `tests/Feature/Http/Controllers/Api/AdminSettingsControllerWebhookTest.php`

```php
<?php

use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake([
        '*' => Http::response('ok', 200),
    ]);

    if (! Schema::hasTable('agent_conversations')) {
        Schema::create('agent_conversations', function ($table) {
            $table->string('id', 36)->primary();
            $table->foreignId('user_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('title');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });
    } elseif (! Schema::hasColumn('agent_conversations', 'project_id')) {
        Schema::table('agent_conversations', function ($table) {
            $table->unsignedBigInteger('project_id')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
    }

    if (! Schema::hasTable('agent_conversation_messages')) {
        Schema::create('agent_conversation_messages', function ($table) {
            $table->string('id', 36)->primary();
            $table->string('conversation_id', 36)->index();
            $table->foreignId('user_id');
            $table->string('agent');
            $table->string('role', 25);
            $table->text('content');
            $table->text('attachments');
            $table->text('tool_calls');
            $table->text('tool_results');
            $table->text('usage');
            $table->text('meta');
            $table->timestamps();
        });
    }
});

function createWebhookAdmin(Project $project): User
{
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);
    $role = Role::factory()->create(['project_id' => $project->id, 'name' => 'admin']);
    $perm = Permission::firstOrCreate(
        ['name' => 'admin.global_config'],
        ['description' => 'Can edit global settings', 'group' => 'admin']
    );
    $role->permissions()->attach($perm);
    $user->assignRole($role, $project);

    return $user;
}

it('sends test webhook successfully', function () {
    $project = Project::factory()->create();
    $user = createWebhookAdmin($project);

    $response = $this->actingAs($user)->postJson('/api/v1/admin/settings/test-webhook', [
        'webhook_url' => 'https://hooks.slack.com/services/T/B/x',
        'platform' => 'slack',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://hooks.slack.com/services/T/B/x'
            && str_contains($request['text'], 'Vunnix webhook test');
    });
});

it('returns failure for bad webhook URL', function () {
    Http::fake([
        '*' => Http::response('not found', 404),
    ]);

    $project = Project::factory()->create();
    $user = createWebhookAdmin($project);

    $response = $this->actingAs($user)->postJson('/api/v1/admin/settings/test-webhook', [
        'webhook_url' => 'https://hooks.slack.com/bad',
        'platform' => 'slack',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', false);
});

it('validates webhook_url is required', function () {
    $project = Project::factory()->create();
    $user = createWebhookAdmin($project);

    $response = $this->actingAs($user)->postJson('/api/v1/admin/settings/test-webhook', [
        'platform' => 'slack',
    ]);

    $response->assertUnprocessable();
});

it('validates platform must be valid', function () {
    $project = Project::factory()->create();
    $user = createWebhookAdmin($project);

    $response = $this->actingAs($user)->postJson('/api/v1/admin/settings/test-webhook', [
        'webhook_url' => 'https://hooks.slack.com/x',
        'platform' => 'invalid_platform',
    ]);

    $response->assertUnprocessable();
});

it('returns 403 for non-admin', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    $project->users()->attach($user->id, ['gitlab_access_level' => 30, 'synced_at' => now()]);

    $response = $this->actingAs($user)->postJson('/api/v1/admin/settings/test-webhook', [
        'webhook_url' => 'https://hooks.slack.com/x',
        'platform' => 'slack',
    ]);

    $response->assertForbidden();
});
```

**Step 7: Run all tests**

Run: `php artisan test tests/Feature/Http/Controllers/Api/AdminSettingsControllerWebhookTest.php`
Expected: All tests pass.

**Step 8: Commit**

```bash
git add app/Models/GlobalSetting.php app/Http/Controllers/Api/AdminSettingsController.php routes/api.php resources/js/components/AdminGlobalSettings.vue resources/js/stores/admin.js tests/Feature/Http/Controllers/Api/AdminSettingsControllerWebhookTest.php
git commit --no-gpg-sign -m "$(cat <<'EOF'
T98.3: Add team_chat_enabled toggle, category settings, test-webhook endpoint, and updated admin UI

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Update Vue component test and add verify_m5.py structural checks

**Files:**
- Modify: `resources/js/components/AdminGlobalSettings.test.js` — add tests for new fields
- Modify: `verify/verify_m5.py` — add T98 structural checks

**Step 1: Add tests to AdminGlobalSettings.test.js**

Add test cases for: enabled toggle renders, platform select renders, test webhook button renders, category checkboxes render.

```js
it('renders team chat enabled toggle', () => {
    // Mount component with store pre-populated
    // Verify data-testid="setting-team_chat_enabled" checkbox exists
});

it('renders test webhook button', () => {
    // Verify data-testid="test-webhook-btn" button exists
});

it('renders notification category checkboxes', () => {
    // Verify data-testid="setting-team_chat_categories" contains checkboxes
});

it('disables test webhook button when URL is empty', () => {
    // Set form.team_chat_webhook_url = ''
    // Verify button is disabled
});
```

(Exact implementation follows existing patterns in AdminGlobalSettings.test.js — read the current test file and match the mount/store pattern.)

**Step 2: Add T98 structural checks to verify_m5.py**

Append the following section before `checker.summary()`:

```python
# ============================================================
#  T98: Team chat notifications — webhook integration
# ============================================================
section("T98: Team Chat Notifications — Webhook Integration")

# Service files
checker.check(
    "TeamChatNotificationService exists",
    file_exists("app/Services/TeamChat/TeamChatNotificationService.php"),
)
checker.check(
    "ChatFormatterInterface exists",
    file_exists("app/Services/TeamChat/ChatFormatterInterface.php"),
)
checker.check(
    "SlackFormatter exists",
    file_exists("app/Services/TeamChat/SlackFormatter.php"),
)
checker.check(
    "MattermostFormatter exists",
    file_exists("app/Services/TeamChat/MattermostFormatter.php"),
)
checker.check(
    "GoogleChatFormatter exists",
    file_exists("app/Services/TeamChat/GoogleChatFormatter.php"),
)
checker.check(
    "GenericFormatter exists",
    file_exists("app/Services/TeamChat/GenericFormatter.php"),
)

# Service methods
checker.check(
    "Service has send method",
    file_contains("app/Services/TeamChat/TeamChatNotificationService.php", "function send"),
)
checker.check(
    "Service has sendTest method",
    file_contains("app/Services/TeamChat/TeamChatNotificationService.php", "function sendTest"),
)
checker.check(
    "Service has isEnabled method",
    file_contains("app/Services/TeamChat/TeamChatNotificationService.php", "function isEnabled"),
)
checker.check(
    "Service has isCategoryEnabled method",
    file_contains("app/Services/TeamChat/TeamChatNotificationService.php", "function isCategoryEnabled"),
)
checker.check(
    "Service checks GlobalSetting for webhook URL",
    file_contains("app/Services/TeamChat/TeamChatNotificationService.php", "team_chat_webhook_url"),
)
checker.check(
    "Service checks GlobalSetting for platform",
    file_contains("app/Services/TeamChat/TeamChatNotificationService.php", "team_chat_platform"),
)

# Formatters have correct structure
checker.check(
    "SlackFormatter uses Block Kit attachments",
    file_contains("app/Services/TeamChat/SlackFormatter.php", "attachments"),
)
checker.check(
    "MattermostFormatter uses attachments",
    file_contains("app/Services/TeamChat/MattermostFormatter.php", "attachments"),
)
checker.check(
    "GoogleChatFormatter uses cardsV2",
    file_contains("app/Services/TeamChat/GoogleChatFormatter.php", "cardsV2"),
)
checker.check(
    "GenericFormatter uses plain text",
    file_contains("app/Services/TeamChat/GenericFormatter.php", "'text'"),
)

# Settings defaults
checker.check(
    "GlobalSetting defaults include team_chat_enabled",
    file_contains("app/Models/GlobalSetting.php", "team_chat_enabled"),
)
checker.check(
    "GlobalSetting defaults include team_chat_categories",
    file_contains("app/Models/GlobalSetting.php", "team_chat_categories"),
)

# Controller endpoint
checker.check(
    "AdminSettingsController has testWebhook method",
    file_contains("app/Http/Controllers/Api/AdminSettingsController.php", "testWebhook"),
)
checker.check(
    "Test-webhook route registered",
    file_contains("routes/api.php", "test-webhook"),
)

# Vue component
checker.check(
    "AdminGlobalSettings has enabled toggle",
    file_contains("resources/js/components/AdminGlobalSettings.vue", "team_chat_enabled"),
)
checker.check(
    "AdminGlobalSettings has test webhook button",
    file_contains("resources/js/components/AdminGlobalSettings.vue", "test-webhook-btn"),
)
checker.check(
    "AdminGlobalSettings has notification categories",
    file_contains("resources/js/components/AdminGlobalSettings.vue", "team_chat_categories"),
)

# Admin store
checker.check(
    "Admin store has testWebhook action",
    file_contains("resources/js/stores/admin.js", "testWebhook"),
)

# Tests
checker.check(
    "SlackFormatter unit test exists",
    file_exists("tests/Unit/Services/TeamChat/SlackFormatterTest.php"),
)
checker.check(
    "MattermostFormatter unit test exists",
    file_exists("tests/Unit/Services/TeamChat/MattermostFormatterTest.php"),
)
checker.check(
    "GoogleChatFormatter unit test exists",
    file_exists("tests/Unit/Services/TeamChat/GoogleChatFormatterTest.php"),
)
checker.check(
    "GenericFormatter unit test exists",
    file_exists("tests/Unit/Services/TeamChat/GenericFormatterTest.php"),
)
checker.check(
    "TeamChatNotificationService feature test exists",
    file_exists("tests/Feature/Services/TeamChatNotificationServiceTest.php"),
)
checker.check(
    "Webhook API test exists",
    file_exists("tests/Feature/Http/Controllers/Api/AdminSettingsControllerWebhookTest.php"),
)
```

**Step 3: Run verification**

Run: `python3 verify/verify_m5.py`
Expected: All checks pass including new T98 section.

**Step 4: Run full test suite**

Run: `php artisan test --parallel`
Expected: All existing + new tests pass.

**Step 5: Commit**

```bash
git add resources/js/components/AdminGlobalSettings.test.js verify/verify_m5.py
git commit --no-gpg-sign -m "$(cat <<'EOF'
T98.4: Add T98 structural checks to verify_m5.py and update Vue component tests

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Final verification, progress.md update, and completion commit

**Step 1: Run full verification**

```bash
php artisan test --parallel
python3 verify/verify_m5.py
```

Both must pass.

**Step 2: Update progress.md**

- Check T98 box: `[x]`
- Update milestone count: `11/18`
- Bold T99 as next task
- Update summary: `Current Task: T99`
- Update `Last Verified: T98`

**Step 3: Update handoff.md**

Promote any learnings to CLAUDE.md if applicable, then clear handoff.md.

**Step 4: Commit**

```bash
git add progress.md handoff.md CLAUDE.md
git commit --no-gpg-sign -m "$(cat <<'EOF'
T98: Add team chat notifications — webhook integration

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
EOF
)"
```
