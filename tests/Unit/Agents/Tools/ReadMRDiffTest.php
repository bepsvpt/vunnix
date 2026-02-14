<?php

use App\Agents\Tools\ReadMRDiff;
use App\Exceptions\GitLabApiException;
use App\Services\GitLabClient;
use App\Services\ProjectAccessChecker;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $this->gitLab = Mockery::mock(GitLabClient::class);
    $this->accessChecker = Mockery::mock(ProjectAccessChecker::class);
    $this->accessChecker->shouldReceive('check')->andReturn(null);
    $this->tool = new ReadMRDiff($this->gitLab, $this->accessChecker);
});

// ─── Description ────────────────────────────────────────────────

it('has a description', function () {
    expect($this->tool->description())->toBeString()->not->toBeEmpty();
});

// ─── Schema ─────────────────────────────────────────────────────

it('defines the expected schema parameters', function () {
    $schema = new JsonSchemaTypeFactory;
    $result = $this->tool->schema($schema);

    expect($result)->toHaveKeys(['project_id', 'mr_iid']);
});

// ─── Handle — success ───────────────────────────────────────────

it('returns formatted diff with file headers', function () {
    $this->gitLab
        ->shouldReceive('getMergeRequestChanges')
        ->with(42, 7)
        ->once()
        ->andReturn([
            'changes' => [
                [
                    'old_path' => 'src/auth.php',
                    'new_path' => 'src/auth.php',
                    'new_file' => false,
                    'deleted_file' => false,
                    'renamed_file' => false,
                    'diff' => "@@ -1,5 +1,7 @@\n <?php\n \n+use App\\Services\\Auth;\n+\n class AuthController\n {\n     public function login()",
                ],
                [
                    'old_path' => 'tests/AuthTest.php',
                    'new_path' => 'tests/AuthTest.php',
                    'new_file' => true,
                    'deleted_file' => false,
                    'renamed_file' => false,
                    'diff' => "@@ -0,0 +1,10 @@\n+<?php\n+\n+test('login works', function () {\n+    expect(true)->toBeTrue();\n+});",
                ],
            ],
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'mr_iid' => 7,
    ]));

    expect($result)
        ->toContain('MR diff — 2 file(s) changed')
        ->toContain('── Modified: src/auth.php ──')
        ->toContain('── New file: tests/AuthTest.php ──')
        ->toContain('@@ -1,5 +1,7 @@')
        ->toContain('use App\\Services\\Auth');
});

it('shows deleted file header', function () {
    $this->gitLab
        ->shouldReceive('getMergeRequestChanges')
        ->with(42, 3)
        ->once()
        ->andReturn([
            'changes' => [
                [
                    'old_path' => 'src/legacy.php',
                    'new_path' => 'src/legacy.php',
                    'new_file' => false,
                    'deleted_file' => true,
                    'renamed_file' => false,
                    'diff' => "@@ -1,3 +0,0 @@\n-<?php\n-// Legacy code\n-class Legacy {}",
                ],
            ],
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'mr_iid' => 3,
    ]));

    expect($result)
        ->toContain('── Deleted file: src/legacy.php ──')
        ->toContain('MR diff — 1 file(s) changed');
});

it('shows renamed file header', function () {
    $this->gitLab
        ->shouldReceive('getMergeRequestChanges')
        ->with(42, 4)
        ->once()
        ->andReturn([
            'changes' => [
                [
                    'old_path' => 'src/OldName.php',
                    'new_path' => 'src/NewName.php',
                    'new_file' => false,
                    'deleted_file' => false,
                    'renamed_file' => true,
                    'diff' => "@@ -1,3 +1,3 @@\n <?php\n \n-class OldName {}\n+class NewName {}",
                ],
            ],
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'mr_iid' => 4,
    ]));

    expect($result)->toContain('── Renamed: src/OldName.php → src/NewName.php ──');
});

it('truncates large diffs and shows remaining file count', function () {
    // First file is small enough to fit, second exceeds MAX_OUTPUT_SIZE (100KB)
    $smallDiff = "@@ -1,3 +1,5 @@\n <?php\n \n+use App\\Auth;\n+\n class Controller {}";
    $largeDiff = str_repeat("+// line of code\n", 7000); // ~112KB

    $this->gitLab
        ->shouldReceive('getMergeRequestChanges')
        ->with(42, 10)
        ->once()
        ->andReturn([
            'changes' => [
                [
                    'old_path' => 'src/small.php',
                    'new_path' => 'src/small.php',
                    'new_file' => false,
                    'deleted_file' => false,
                    'renamed_file' => false,
                    'diff' => $smallDiff,
                ],
                [
                    'old_path' => 'src/huge.php',
                    'new_path' => 'src/huge.php',
                    'new_file' => false,
                    'deleted_file' => false,
                    'renamed_file' => false,
                    'diff' => $largeDiff,
                ],
                [
                    'old_path' => 'src/third.php',
                    'new_path' => 'src/third.php',
                    'new_file' => false,
                    'deleted_file' => false,
                    'renamed_file' => false,
                    'diff' => "@@ -1,3 +1,3 @@\n <?php\n-// old\n+// new",
                ],
            ],
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'mr_iid' => 10,
    ]));

    expect($result)
        ->toContain('MR diff — 3 file(s) changed')
        ->toContain('── Modified: src/small.php ──')
        ->toContain('Output truncated')
        ->toContain('2 more file(s) not shown')
        ->not->toContain('src/huge.php')
        ->not->toContain('src/third.php');
});

// ─── Handle — empty ─────────────────────────────────────────────

it('returns a message when no changes are found', function () {
    $this->gitLab
        ->shouldReceive('getMergeRequestChanges')
        ->with(42, 1)
        ->once()
        ->andReturn([
            'changes' => [],
        ]);

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'mr_iid' => 1,
    ]));

    expect($result)->toContain('No file changes found');
});

// ─── Handle — error ─────────────────────────────────────────────

it('returns error message instead of throwing on GitLab API failure', function () {
    $this->gitLab
        ->shouldReceive('getMergeRequestChanges')
        ->once()
        ->andThrow(new GitLabApiException(
            message: 'GitLab API error (getMergeRequestChanges !999): 404 {"message":"404 Not Found"}',
            statusCode: 404,
            responseBody: '{"message":"404 Not Found"}',
            context: 'getMergeRequestChanges !999',
        ));

    $result = $this->tool->handle(new Request([
        'project_id' => 42,
        'mr_iid' => 999,
    ]));

    expect($result)->toContain('Error reading merge request diff');
});

// ─── Handle — access denied ────────────────────────────────────

it('returns rejection when access checker denies access', function () {
    $checker = Mockery::mock(ProjectAccessChecker::class);
    $checker->shouldReceive('check')
        ->with(999)
        ->once()
        ->andReturn('Access denied: you do not have access to this project.');

    $tool = new ReadMRDiff($this->gitLab, $checker);

    $result = $tool->handle(new Request([
        'project_id' => 999,
        'mr_iid' => 1,
    ]));

    expect($result)->toContain('Access denied');
    $this->gitLab->shouldNotHaveReceived('getMergeRequestChanges');
});
