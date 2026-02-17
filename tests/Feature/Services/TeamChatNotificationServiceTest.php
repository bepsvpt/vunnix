<?php

use App\Models\GlobalSetting;
use App\Services\TeamChat\TeamChatNotificationService;
use Illuminate\Support\Facades\Http;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    Http::fake([
        'hooks.slack.com/*' => Http::response('ok', 200),
        'mattermost.example.com/*' => Http::response('ok', 200),
        'chat.googleapis.com/*' => Http::response('ok', 200),
        'generic.example.com/*' => Http::response('ok', 200),
    ]);
});

it('returns false when team chat is disabled', function (): void {
    GlobalSetting::set('team_chat_enabled', false, 'boolean');
    GlobalSetting::set('team_chat_webhook_url', 'https://hooks.slack.com/services/T/B/x', 'string');

    $service = new TeamChatNotificationService;
    $result = $service->send('task_completed', 'Review done');

    expect($result)->toBeFalse();
    Http::assertNothingSent();
});

it('returns false when webhook URL is empty', function (): void {
    GlobalSetting::set('team_chat_enabled', true, 'boolean');
    GlobalSetting::set('team_chat_webhook_url', '', 'string');

    $service = new TeamChatNotificationService;
    $result = $service->send('task_completed', 'Review done');

    expect($result)->toBeFalse();
    Http::assertNothingSent();
});

it('sends Slack-formatted notification when enabled', function (): void {
    GlobalSetting::set('team_chat_enabled', true, 'boolean');
    GlobalSetting::set('team_chat_webhook_url', 'https://hooks.slack.com/services/T/B/x', 'string');
    GlobalSetting::set('team_chat_platform', 'slack', 'string');

    $service = new TeamChatNotificationService;
    $result = $service->send('task_completed', '🤖 Review complete', [
        'urgency' => 'info',
    ]);

    expect($result)->toBeTrue();
    Http::assertSent(function (array $request): bool {
        return $request->url() === 'https://hooks.slack.com/services/T/B/x'
            && $request['text'] === '🤖 Review complete'
            && isset($request['attachments']);
    });
});

it('sends Mattermost-formatted notification', function (): void {
    GlobalSetting::set('team_chat_enabled', true, 'boolean');
    GlobalSetting::set('team_chat_webhook_url', 'https://mattermost.example.com/hooks/abc', 'string');
    GlobalSetting::set('team_chat_platform', 'mattermost', 'string');

    $service = new TeamChatNotificationService;
    $result = $service->send('alert', 'API outage', ['urgency' => 'high']);

    expect($result)->toBeTrue();
    Http::assertSent(function (array $request): bool {
        return $request->url() === 'https://mattermost.example.com/hooks/abc'
            && $request['text'] === 'API outage'
            && isset($request['attachments']);
    });
});

it('sends Google Chat-formatted notification', function (): void {
    GlobalSetting::set('team_chat_enabled', true, 'boolean');
    GlobalSetting::set('team_chat_webhook_url', 'https://chat.googleapis.com/v1/spaces/x/messages?key=y', 'string');
    GlobalSetting::set('team_chat_platform', 'google_chat', 'string');

    $service = new TeamChatNotificationService;
    $result = $service->send('task_completed', 'Done');

    expect($result)->toBeTrue();
    Http::assertSent(function (array $request): bool {
        return str_contains($request->url(), 'chat.googleapis.com')
            && $request['text'] === 'Done'
            && isset($request['cardsV2']);
    });
});

it('sends generic plain text notification', function (): void {
    GlobalSetting::set('team_chat_enabled', true, 'boolean');
    GlobalSetting::set('team_chat_webhook_url', 'https://generic.example.com/webhook', 'string');
    GlobalSetting::set('team_chat_platform', 'generic', 'string');

    $service = new TeamChatNotificationService;
    $result = $service->send('task_completed', 'Done');

    expect($result)->toBeTrue();
    Http::assertSent(function (array $request): bool {
        return $request->url() === 'https://generic.example.com/webhook'
            && $request['text'] === 'Done';
    });
});

it('falls back to generic formatter for unknown platform', function (): void {
    GlobalSetting::set('team_chat_enabled', true, 'boolean');
    GlobalSetting::set('team_chat_webhook_url', 'https://generic.example.com/webhook', 'string');
    GlobalSetting::set('team_chat_platform', 'unknown_platform', 'string');

    $service = new TeamChatNotificationService;
    $result = $service->send('task_completed', 'Done');

    expect($result)->toBeTrue();
    Http::assertSent(function (array $request): bool {
        return $request['text'] === 'Done'
            && ! isset($request['attachments'])
            && ! isset($request['cardsV2']);
    });
});

it('returns false and logs warning on HTTP failure', function (): void {
    Http::fake([
        'failing-webhook.example.com/*' => Http::response('error', 500),
    ]);

    GlobalSetting::set('team_chat_enabled', true, 'boolean');
    GlobalSetting::set('team_chat_webhook_url', 'https://failing-webhook.example.com/hook', 'string');
    GlobalSetting::set('team_chat_platform', 'slack', 'string');

    $service = new TeamChatNotificationService;
    $result = $service->send('alert', 'Test');

    expect($result)->toBeFalse();
});

it('respects notification category toggles', function (): void {
    GlobalSetting::set('team_chat_enabled', true, 'boolean');
    GlobalSetting::set('team_chat_webhook_url', 'https://hooks.slack.com/services/T/B/x', 'string');
    GlobalSetting::set('team_chat_platform', 'slack', 'string');
    GlobalSetting::set('team_chat_categories', [
        'task_completed' => true,
        'alert' => false,
    ], 'json');

    $service = new TeamChatNotificationService;

    // task_completed enabled
    $result = $service->send('task_completed', 'Review done', ['category' => 'task_completed']);
    expect($result)->toBeTrue();

    // alert disabled
    $result = $service->send('alert', 'Queue depth', ['category' => 'alert']);
    expect($result)->toBeFalse();
});

it('enables all categories by default when none configured', function (): void {
    GlobalSetting::set('team_chat_enabled', true, 'boolean');
    GlobalSetting::set('team_chat_webhook_url', 'https://hooks.slack.com/services/T/B/x', 'string');
    GlobalSetting::set('team_chat_platform', 'slack', 'string');
    // No team_chat_categories setting

    $service = new TeamChatNotificationService;
    expect($service->isCategoryEnabled('task_completed'))->toBeTrue();
    expect($service->isCategoryEnabled('alert'))->toBeTrue();
});

it('sendTest posts to the provided URL without checking GlobalSetting', function (): void {
    Http::fake([
        '*' => Http::response('ok', 200),
    ]);

    $service = new TeamChatNotificationService;
    $result = $service->sendTest('https://hooks.slack.com/test', 'slack');

    expect($result)->toBeTrue();
    Http::assertSent(function (array $request): bool {
        return $request->url() === 'https://hooks.slack.com/test'
            && str_contains($request['text'], 'Vunnix webhook test');
    });
});

it('sendTest returns false on failure', function (): void {
    Http::fake([
        'failing-webhook.example.com/*' => Http::response('error', 403),
    ]);

    $service = new TeamChatNotificationService;
    $result = $service->sendTest('https://failing-webhook.example.com/bad', 'slack');

    expect($result)->toBeFalse();
});
