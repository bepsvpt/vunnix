<?php

use App\Exceptions\GitLabApiException;
use App\Services\GitLabClient;
use App\Services\VunnixTomlService;

// Uses TestCase because error-path tests trigger Log::warning() which needs the facade root.
uses(Tests\TestCase::class);

afterEach(function (): void {
    Mockery::close();
});

it('parses valid .vunnix.toml and returns flattened settings', function (): void {
    $tomlContent = <<<'TOML'
[general]
model = "sonnet"
max_tokens = 4096
timeout_minutes = 5

[code_review]
auto_review = false
severity_threshold = "critical"

[feature_dev]
enabled = true
branch_prefix = "bot/"
TOML;

    $gitLabClient = Mockery::mock(GitLabClient::class);
    $gitLabClient->shouldReceive('getFile')
        ->with(100, '.vunnix.toml', 'abc123')
        ->once()
        ->andReturn([
            'content' => base64_encode($tomlContent),
            'encoding' => 'base64',
        ]);

    $service = new VunnixTomlService($gitLabClient);
    $result = $service->read(100, 'abc123');

    expect($result)->toBe([
        'ai_model' => 'sonnet',
        'max_tokens' => 4096,
        'timeout_minutes' => 5,
        'code_review.auto_review' => false,
        'code_review.severity_threshold' => 'critical',
        'feature_dev.enabled' => true,
        'feature_dev.branch_prefix' => 'bot/',
    ]);
});

it('maps [general] keys to top-level config keys', function (): void {
    $tomlContent = <<<'TOML'
[general]
model = "haiku"
language = "ja"
TOML;

    $gitLabClient = Mockery::mock(GitLabClient::class);
    $gitLabClient->shouldReceive('getFile')
        ->once()
        ->andReturn([
            'content' => base64_encode($tomlContent),
            'encoding' => 'base64',
        ]);

    $service = new VunnixTomlService($gitLabClient);
    $result = $service->read(100, 'main');

    // [general].model → ai_model, [general].language → ai_language
    expect($result)->toBe([
        'ai_model' => 'haiku',
        'ai_language' => 'ja',
    ]);
});

it('ignores unknown keys not in settingKeys()', function (): void {
    $tomlContent = <<<'TOML'
[general]
model = "opus"
unknown_key = "should be ignored"

[code_review]
auto_review = true
nonexistent_field = 42

[totally_unknown_section]
foo = "bar"
TOML;

    $gitLabClient = Mockery::mock(GitLabClient::class);
    $gitLabClient->shouldReceive('getFile')
        ->once()
        ->andReturn([
            'content' => base64_encode($tomlContent),
            'encoding' => 'base64',
        ]);

    $service = new VunnixTomlService($gitLabClient);
    $result = $service->read(100, 'main');

    expect($result)->toBe([
        'ai_model' => 'opus',
        'code_review.auto_review' => true,
    ]);
});

it('returns empty array when file does not exist (404)', function (): void {
    $gitLabClient = Mockery::mock(GitLabClient::class);
    $gitLabClient->shouldReceive('getFile')
        ->once()
        ->andThrow(new GitLabApiException('File not found', 404, '', 'getFile .vunnix.toml'));

    $service = new VunnixTomlService($gitLabClient);
    $result = $service->read(100, 'main');

    expect($result)->toBe([]);
});

it('returns empty array when TOML is malformed', function (): void {
    $gitLabClient = Mockery::mock(GitLabClient::class);
    $gitLabClient->shouldReceive('getFile')
        ->once()
        ->andReturn([
            'content' => base64_encode('this is [not valid toml ==='),
            'encoding' => 'base64',
        ]);

    $service = new VunnixTomlService($gitLabClient);
    $result = $service->read(100, 'main');

    expect($result)->toBe([]);
});

it('returns empty array on GitLab API error (non-404)', function (): void {
    $gitLabClient = Mockery::mock(GitLabClient::class);
    $gitLabClient->shouldReceive('getFile')
        ->once()
        ->andThrow(new GitLabApiException('Server error', 500, '', 'getFile .vunnix.toml'));

    $service = new VunnixTomlService($gitLabClient);
    $result = $service->read(100, 'main');

    expect($result)->toBe([]);
});

it('handles all supported setting keys from TOML sections', function (): void {
    $tomlContent = <<<'TOML'
[general]
model = "opus"
language = "en"
timeout_minutes = 10
max_tokens = 8192

[code_review]
auto_review = true
auto_review_on_push = false
severity_threshold = "major"

[feature_dev]
enabled = true
branch_prefix = "ai/"
auto_create_mr = true

[conversation]
enabled = true
max_history_messages = 100
tool_use_gitlab = true

[ui_adjustment]
dev_server_command = "npm run dev"
screenshot_base_url = "http://localhost:3000"
screenshot_wait_ms = 3000

[labels]
auto_label = true
risk_labels = true
TOML;

    $gitLabClient = Mockery::mock(GitLabClient::class);
    $gitLabClient->shouldReceive('getFile')
        ->once()
        ->andReturn([
            'content' => base64_encode($tomlContent),
            'encoding' => 'base64',
        ]);

    $service = new VunnixTomlService($gitLabClient);
    $result = $service->read(100, 'main');

    expect($result)->toHaveCount(18)
        ->and($result['ai_model'])->toBe('opus')
        ->and($result['ai_language'])->toBe('en')
        ->and($result['code_review.auto_review'])->toBe(true)
        ->and($result['feature_dev.branch_prefix'])->toBe('ai/')
        ->and($result['conversation.enabled'])->toBe(true)
        ->and($result['ui_adjustment.screenshot_wait_ms'])->toBe(3000)
        ->and($result['labels.auto_label'])->toBe(true);
});

it('returns empty array when base64 content is invalid', function (): void {
    $gitLabClient = Mockery::mock(GitLabClient::class);
    $gitLabClient->shouldReceive('getFile')
        ->once()
        ->andReturn([
            'content' => '%%invalid-base64%%',
            'encoding' => 'base64',
        ]);

    $service = new VunnixTomlService($gitLabClient);
    $result = $service->read(100, 'main');

    expect($result)->toBe([]);
});

it('parses non-base64 raw TOML content', function (): void {
    $tomlContent = <<<'TOML'
[general]
model = "sonnet"
TOML;

    $gitLabClient = Mockery::mock(GitLabClient::class);
    $gitLabClient->shouldReceive('getFile')
        ->once()
        ->andReturn([
            'content' => $tomlContent,
            'encoding' => 'text',
        ]);

    $service = new VunnixTomlService($gitLabClient);
    $result = $service->read(100, 'main');

    expect($result)->toBe(['ai_model' => 'sonnet']);
});

it('ignores non-table TOML sections while parsing', function (): void {
    $tomlContent = <<<'TOML'
general = "invalid"

[code_review]
auto_review = true
TOML;

    $gitLabClient = Mockery::mock(GitLabClient::class);
    $gitLabClient->shouldReceive('getFile')
        ->once()
        ->andReturn([
            'content' => base64_encode($tomlContent),
            'encoding' => 'base64',
        ]);

    $service = new VunnixTomlService($gitLabClient);
    $result = $service->read(100, 'main');

    expect($result)->toBe(['code_review.auto_review' => true]);
});
