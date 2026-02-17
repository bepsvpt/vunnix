<?php

namespace App\Services;

use App\Exceptions\GitLabApiException;
use Illuminate\Support\Facades\Log;
use Throwable;
use Yosymfony\Toml\Toml;

class VunnixTomlService
{
    private const FILE_PATH = '.vunnix.toml';

    /**
     * Map from TOML [general] keys to flat config keys.
     * Keys in other sections map as "{section}.{key}".
     */
    private const GENERAL_KEY_MAP = [
        'model' => 'ai_model',
        'language' => 'ai_language',
        'timeout_minutes' => 'timeout_minutes',
        'max_tokens' => 'max_tokens',
    ];

    public function __construct(
        private readonly GitLabClient $gitLabClient,
    ) {}

    /**
     * Read and parse .vunnix.toml from a GitLab project repo.
     *
     * Returns a flat key→value map using dot-notation matching
     * ProjectConfigService::settingKeys(). Unknown keys are ignored.
     * Returns empty array if file is missing, unreadable, or malformed.
     *
     * @return array<string, mixed>
     */
    public function read(int $gitlabProjectId, string $ref = 'main'): array
    {
        try {
            $fileData = $this->gitLabClient->getFile($gitlabProjectId, self::FILE_PATH, $ref);
        } catch (GitLabApiException $e) {
            if ($e->getCode() !== 404) {
                Log::warning('VunnixTomlService: failed to read .vunnix.toml', [
                    'project_id' => $gitlabProjectId,
                    'ref' => $ref,
                    'error' => $e->getMessage(),
                ]);
            }

            return [];
        }

        $content = $this->decodeContent($fileData);
        if ($content === null) {
            return [];
        }

        return $this->parseAndFlatten($content, $gitlabProjectId);
    }

    private function decodeContent(array $fileData): ?string
    {
        $encoding = $fileData['encoding'] ?? 'base64';
        $raw = $fileData['content'] ?? '';

        if ($encoding === 'base64') {
            $decoded = base64_decode($raw, true);

            return $decoded !== false ? $decoded : null;
        }

        return $raw;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseAndFlatten(string $tomlContent, int $gitlabProjectId): array
    {
        try {
            $parsed = Toml::parse($tomlContent);
        } catch (Throwable $e) {
            Log::warning('VunnixTomlService: malformed .vunnix.toml', [
                'project_id' => $gitlabProjectId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (! is_array($parsed)) {
            return [];
        }

        $allowedKeys = array_keys(ProjectConfigService::settingKeys());
        $result = [];

        foreach ($parsed as $section => $values) {
            if (! is_array($values)) {
                continue;
            }

            if ($section === 'general') {
                foreach ($values as $key => $value) {
                    $mappedKey = self::GENERAL_KEY_MAP[$key] ?? null;
                    if ($mappedKey !== null && in_array($mappedKey, $allowedKeys, true)) {
                        $result[$mappedKey] = $value;
                    }
                }
            } else {
                foreach ($values as $key => $value) {
                    $flatKey = "{$section}.{$key}";
                    if (in_array($flatKey, $allowedKeys, true)) {
                        $result[$flatKey] = $value;
                    }
                }
            }
        }

        return $result;
    }
}
