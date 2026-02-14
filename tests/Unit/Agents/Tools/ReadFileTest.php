<?php

use App\Agents\Tools\ReadFile;
use App\Exceptions\GitLabApiException;
use App\Services\GitLabClient;
use App\Services\ProjectAccessChecker;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $this->gitLab = Mockery::mock(GitLabClient::class);
    $this->accessChecker = Mockery::mock(ProjectAccessChecker::class);
    $this->accessChecker->shouldReceive('check')->andReturn(null);
    $this->tool = new ReadFile($this->gitLab, $this->accessChecker);
});

// ─── Description ────────────────────────────────────────────────

it('has a description', function () {
    expect($this->tool->description())->toBeString()->not->toBeEmpty();
});

// ─── Schema ─────────────────────────────────────────────────────

it('defines the expected schema parameters', function () {
    $schema = new JsonSchemaTypeFactory;
    $result = $this->tool->schema($schema);

    expect($result)->toHaveKeys(['project_id', 'file_path', 'ref']);
});

// ─── Handle — success ───────────────────────────────────────────

it('returns decoded file content', function () {
    $content = "<?php\n\nclass AuthService\n{\n    // ...\n}\n";

    $this->gitLab
        ->shouldReceive('getFile')
        ->with(42, 'src/AuthService.php', 'main')
        ->once()
        ->andReturn([
            'file_name' => 'AuthService.php',
            'file_path' => 'src/AuthService.php',
            'content' => base64_encode($content),
            'encoding' => 'base64',
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'file_path' => 'src/AuthService.php',
    ]));

    expect($result)
        ->toContain('File: AuthService.php')
        ->toContain('class AuthService');
});

it('uses correct URL path encoding for file path', function () {
    $this->gitLab
        ->shouldReceive('getFile')
        ->with(42, 'src/services/Payment Service.php', 'develop')
        ->once()
        ->andReturn([
            'file_name' => 'Payment Service.php',
            'file_path' => 'src/services/Payment Service.php',
            'content' => base64_encode('<?php // payment'),
            'encoding' => 'base64',
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'file_path' => 'src/services/Payment Service.php',
        'ref' => 'develop',
    ]));

    expect($result)->toContain('File: Payment Service.php');
});

// ─── Handle — large file truncation ─────────────────────────────

it('truncates files larger than 100KB', function () {
    $largeContent = str_repeat('x', 150_000);

    $this->gitLab
        ->shouldReceive('getFile')
        ->once()
        ->andReturn([
            'file_name' => 'big.sql',
            'file_path' => 'data/big.sql',
            'content' => base64_encode($largeContent),
            'encoding' => 'base64',
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'file_path' => 'data/big.sql',
    ]));

    expect($result)
        ->toContain('Truncated')
        ->toContain('150,000 bytes')
        ->toContain('100,000');

    // Ensure the result is actually truncated (content + header < full size)
    expect(strlen($result))->toBeLessThan(120_000);
});

// ─── Handle — binary file ───────────────────────────────────────

it('returns error for binary file with invalid base64', function () {
    $this->gitLab
        ->shouldReceive('getFile')
        ->once()
        ->andReturn([
            'file_name' => 'image.png',
            'file_path' => 'assets/image.png',
            'content' => '!!!not-valid-base64!!!',
            'encoding' => 'base64',
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'file_path' => 'assets/image.png',
    ]));

    expect($result)->toContain('Unable to decode');
});

// ─── Handle — error ─────────────────────────────────────────────

it('returns error message instead of throwing on GitLab API failure', function () {
    $this->gitLab
        ->shouldReceive('getFile')
        ->once()
        ->andThrow(new GitLabApiException(
            message: 'GitLab API error (getFile src/missing.php): 404 {"message":"404 File Not Found"}',
            statusCode: 404,
            responseBody: '{"message":"404 File Not Found"}',
            context: 'getFile src/missing.php',
        ));

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'file_path' => 'src/missing.php',
    ]));

    expect($result)->toContain('Error reading file');
});

// ─── Handle — access denied ────────────────────────────────────

it('returns rejection when access checker denies access', function () {
    $checker = Mockery::mock(ProjectAccessChecker::class);
    $checker->shouldReceive('check')
        ->with(999)
        ->once()
        ->andReturn('Access denied: you do not have access to this project.');

    $tool = new ReadFile($this->gitLab, $checker);

    $result = $tool->handle(new Request([
        'project_id' => 999,
        'file_path' => 'src/secret.php',
    ]));

    expect($result)->toContain('Access denied');
    $this->gitLab->shouldNotHaveReceived('getFile');
});
