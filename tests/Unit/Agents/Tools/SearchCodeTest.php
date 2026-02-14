<?php

use App\Agents\Tools\SearchCode;
use App\Exceptions\GitLabApiException;
use App\Services\GitLabClient;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $this->gitLab = Mockery::mock(GitLabClient::class);
    $this->tool = new SearchCode($this->gitLab);
});

// ─── Description ────────────────────────────────────────────────

it('has a description', function () {
    expect($this->tool->description())->toBeString()->not->toBeEmpty();
});

// ─── Schema ─────────────────────────────────────────────────────

it('defines the expected schema parameters', function () {
    $schema = new JsonSchemaTypeFactory;
    $result = $this->tool->schema($schema);

    expect($result)->toHaveKeys(['project_id', 'search']);
});

// ─── Handle — success ───────────────────────────────────────────

it('returns formatted search results with file paths and snippets', function () {
    $this->gitLab
        ->shouldReceive('searchCode')
        ->with(42, 'authenticate')
        ->once()
        ->andReturn([
            [
                'basename' => 'AuthService.php',
                'data' => "    public function authenticate(\$credentials)\n    {\n        return Auth::attempt(\$credentials);\n    }",
                'path' => 'src/Services/AuthService.php',
                'filename' => 'AuthService.php',
                'ref' => 'main',
                'startline' => 15,
                'project_id' => 42,
            ],
            [
                'basename' => 'LoginController.php',
                'data' => "    \$this->authService->authenticate(\$request->validated());",
                'path' => 'src/Http/Controllers/LoginController.php',
                'filename' => 'LoginController.php',
                'ref' => 'main',
                'startline' => 28,
                'project_id' => 42,
            ],
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'search' => 'authenticate',
    ]));

    expect($result)
        ->toContain('src/Services/AuthService.php')
        ->toContain(':L15')
        ->toContain('Auth::attempt')
        ->toContain('src/Http/Controllers/LoginController.php')
        ->toContain(':L28');
});

it('passes the query string correctly to GitLab client', function () {
    $this->gitLab
        ->shouldReceive('searchCode')
        ->with(42, 'class PaymentGateway')
        ->once()
        ->andReturn([
            [
                'basename' => 'PaymentGateway.php',
                'data' => 'class PaymentGateway implements Gateway',
                'path' => 'src/Payment/PaymentGateway.php',
                'filename' => 'PaymentGateway.php',
                'ref' => 'main',
                'startline' => 5,
                'project_id' => 42,
            ],
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'search' => 'class PaymentGateway',
    ]));

    expect($result)->toContain('src/Payment/PaymentGateway.php');
});

// ─── Handle — long snippets truncated ───────────────────────────

it('truncates long code snippets to 500 characters', function () {
    $longSnippet = str_repeat('a', 600);

    $this->gitLab
        ->shouldReceive('searchCode')
        ->once()
        ->andReturn([
            [
                'basename' => 'huge.php',
                'data' => $longSnippet,
                'path' => 'src/huge.php',
                'filename' => 'huge.php',
                'ref' => 'main',
                'startline' => 1,
                'project_id' => 42,
            ],
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'search' => 'something',
    ]));

    // Snippet should be truncated — total output smaller than original snippet
    expect($result)->toContain('…');
    // The snippet portion should be ~500 chars + the ellipsis, not the full 600
    expect($result)->not->toContain(str_repeat('a', 600));
});

// ─── Handle — empty ─────────────────────────────────────────────

it('returns a message when no matches are found', function () {
    $this->gitLab
        ->shouldReceive('searchCode')
        ->once()
        ->andReturn([]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'search' => 'nonexistent_function_xyz',
    ]));

    expect($result)->toContain('No code matches found');
});

// ─── Handle — error ─────────────────────────────────────────────

it('returns error message instead of throwing on GitLab API failure', function () {
    $this->gitLab
        ->shouldReceive('searchCode')
        ->once()
        ->andThrow(new GitLabApiException(
            message: 'GitLab API error (searchCode test): 403 {"message":"403 Forbidden"}',
            statusCode: 403,
            responseBody: '{"message":"403 Forbidden"}',
            context: 'searchCode test',
        ));

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'search' => 'test',
    ]));

    expect($result)->toContain('Error searching code');
});
