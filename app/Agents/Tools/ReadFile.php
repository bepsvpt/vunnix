<?php

namespace App\Agents\Tools;

use App\Exceptions\GitLabApiException;
use App\Services\GitLabClient;
use App\Services\ProjectAccessChecker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * AI SDK Tool: Read a file from a GitLab repository.
 *
 * Fetches a specific file's content (base64-decoded) from a project repository.
 * Wraps GitLabClient::getFile() for use by the Conversation Engine.
 *
 * Large files (>100KB decoded) are truncated with a notice to prevent
 * overwhelming the conversation context.
 *
 * @see §14.2 — Tool catalog: ReadFile
 */
class ReadFile implements Tool
{
    /**
     * Maximum decoded file size in bytes before truncation.
     */
    private const MAX_FILE_SIZE = 100_000;

    public function __construct(
        protected GitLabClient $gitLab,
        protected ProjectAccessChecker $accessChecker,
    ) {}

    public function description(): string
    {
        return 'Read the content of a specific file from a GitLab repository. Returns the decoded file content.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema
                ->integer()
                ->description('The GitLab project ID.')
                ->required(),
            'file_path' => $schema
                ->string()
                ->description('Full path to the file within the repository (e.g., "src/services/AuthService.php").')
                ->required(),
            'ref' => $schema
                ->string()
                ->description('Branch name, tag, or commit SHA (defaults to "main").'),
        ];
    }

    public function handle(Request $request): string
    {
        $rejection = $this->accessChecker->check($request->integer('project_id'));

        if ($rejection !== null) {
            return $rejection;
        }

        try {
            $fileData = $this->gitLab->getFile(
                projectId: $request->integer('project_id'),
                filePath: $request->string('file_path'),
                ref: $request->string('ref', 'main'),
            );
        } catch (GitLabApiException $e) {
            return "Error reading file: {$e->getMessage()}";
        }

        $content = base64_decode($fileData['content'] ?? '', true);

        if ($content === false) {
            return "Error: Unable to decode file content (binary file or invalid encoding).";
        }

        $fileName = $fileData['file_name'] ?? $request->string('file_path');

        if (strlen($content) > self::MAX_FILE_SIZE) {
            $truncated = substr($content, 0, self::MAX_FILE_SIZE);

            return "File: {$fileName}\n(Truncated — file is " . number_format(strlen($content)) . " bytes, showing first " . number_format(self::MAX_FILE_SIZE) . ")\n\n{$truncated}";
        }

        return "File: {$fileName}\n\n{$content}";
    }
}
