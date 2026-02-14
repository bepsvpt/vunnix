<?php

namespace App\Services;

use App\Enums\ReviewStrategy;

/**
 * Analyzes changed file paths to select the appropriate review strategy.
 *
 * Strategy selection rules (from §3.4):
 * - .vue, .tsx, .css → frontend-review
 * - .php, migrations → backend-review
 * - Mixed frontend + backend → mixed-review
 * - Security-sensitive files → security-audit (overrides other strategies)
 */
class StrategyResolver
{
    /**
     * Frontend file extensions.
     */
    private const FRONTEND_EXTENSIONS = ['vue', 'tsx', 'ts', 'jsx', 'js', 'css', 'scss', 'sass', 'less'];

    /**
     * Backend file extensions.
     */
    private const BACKEND_EXTENSIONS = ['php'];

    /**
     * Patterns indicating security-sensitive files.
     * Matched against the full file path (case-insensitive).
     */
    private const SECURITY_PATTERNS = [
        '/\.env/',
        '/auth/',
        '/middleware/',
        '/password/i',
        '/secret/i',
        '/token/i',
        '/config\/auth\.php$/',
        '/config\/sanctum\.php$/',
        '/config\/cors\.php$/',
        '/config\/session\.php$/',
        '/\.htaccess$/',
        '/docker-compose.*\.yml$/',
        '/Dockerfile/',
    ];

    /**
     * Migration path pattern.
     */
    private const MIGRATION_PATTERN = '/database\/migrations\//';

    /**
     * Resolve a review strategy from a list of changed file paths.
     *
     * @param  array<int, string>  $filePaths  Changed file paths from the MR diff.
     */
    public function resolve(array $filePaths): ReviewStrategy
    {
        if (empty($filePaths)) {
            return ReviewStrategy::BackendReview;
        }

        if ($this->hasSecuritySensitiveFiles($filePaths)) {
            return ReviewStrategy::SecurityAudit;
        }

        $hasFrontend = false;
        $hasBackend = false;

        foreach ($filePaths as $path) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if (in_array($extension, self::FRONTEND_EXTENSIONS, true)) {
                $hasFrontend = true;
            }

            if (in_array($extension, self::BACKEND_EXTENSIONS, true) || $this->isMigration($path)) {
                $hasBackend = true;
            }

            if ($hasFrontend && $hasBackend) {
                return ReviewStrategy::MixedReview;
            }
        }

        if ($hasFrontend) {
            return ReviewStrategy::FrontendReview;
        }

        return ReviewStrategy::BackendReview;
    }

    /**
     * Check if any file paths match security-sensitive patterns.
     *
     * @param  array<int, string>  $filePaths
     */
    private function hasSecuritySensitiveFiles(array $filePaths): bool
    {
        foreach ($filePaths as $path) {
            foreach (self::SECURITY_PATTERNS as $pattern) {
                if (preg_match($pattern, $path)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if a file path is a database migration.
     */
    private function isMigration(string $path): bool
    {
        return (bool) preg_match(self::MIGRATION_PATTERN, $path);
    }
}
