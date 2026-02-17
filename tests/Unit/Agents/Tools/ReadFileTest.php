<?php

use App\Agents\Tools\ReadFile;
use App\Exceptions\GitLabApiException;
use App\Services\GitLabClient;
use App\Services\ProjectAccessChecker;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    $this->gitLab = Mockery::mock(GitLabClient::class);
    $this->accessChecker = Mockery::mock(ProjectAccessChecker::class);
    $this->accessChecker->shouldReceive('check')->andReturn(null);
    $this->tool = new ReadFile($this->gitLab, $this->accessChecker);
});

// ─── Description ────────────────────────────────────────────────

it('has a description', function (): void {
    expect($this->tool->description())->toBeString()->not->toBeEmpty();
});

// ─── Schema ─────────────────────────────────────────────────────

it('defines the expected schema parameters', function (): void {
    $schema = new JsonSchemaTypeFactory;
    $result = $this->tool->schema($schema);

    expect($result)->toHaveKeys(['project_id', 'file_path', 'ref']);
});

// ─── Handle — success ───────────────────────────────────────────

it('returns decoded file content', function (): void {
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

it('uses correct URL path encoding for file path', function (): void {
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

it('truncates files larger than 100KB', function (): void {
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

it('sanitizes non-UTF-8 file content to valid UTF-8', function (): void {
    // Simulate a file with Latin-1 characters (invalid in UTF-8)
    $latin1Content = "<?php\n// Stra\xDFe (German for 'street' in Latin-1)\n";

    $this->gitLab
        ->shouldReceive('getFile')
        ->once()
        ->andReturn([
            'file_name' => 'german.php',
            'file_path' => 'src/german.php',
            'content' => base64_encode($latin1Content),
            'encoding' => 'base64',
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'file_path' => 'src/german.php',
    ]));

    // Result must be valid UTF-8 (json_encode would fail otherwise)
    expect(mb_check_encoding($result, 'UTF-8'))->toBeTrue();
    expect(json_encode($result))->not->toBeFalse();
    expect($result)->toContain('File: german.php');
});

it('truncates large files without splitting multi-byte characters', function (): void {
    // Build content just over 100KB with multi-byte characters at the boundary
    $content = str_repeat('a', 99_999).'你好'; // 99,999 + 6 bytes = 100,005

    $this->gitLab
        ->shouldReceive('getFile')
        ->once()
        ->andReturn([
            'file_name' => 'big-unicode.txt',
            'file_path' => 'data/big-unicode.txt',
            'content' => base64_encode($content),
            'encoding' => 'base64',
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'file_path' => 'data/big-unicode.txt',
    ]));

    expect($result)->toContain('Truncated');
    // Result must be valid UTF-8
    expect(mb_check_encoding($result, 'UTF-8'))->toBeTrue();
    expect(json_encode($result))->not->toBeFalse();
});

// ─── Handle — binary file ───────────────────────────────────────

it('returns error for binary file with invalid base64', function (): void {
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

it('returns error message instead of throwing on GitLab API failure', function (): void {
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

it('returns rejection when access checker denies access', function (): void {
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
