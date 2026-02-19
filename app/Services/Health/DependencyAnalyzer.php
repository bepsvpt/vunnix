<?php

namespace App\Services\Health;

use App\Contracts\HealthAnalyzerContract;
use App\DTOs\HealthAnalysisResult;
use App\Enums\HealthDimension;
use App\Services\GitLabClient;
use Illuminate\Support\Facades\Http;
use Throwable;

class DependencyAnalyzer implements HealthAnalyzerContract
{
    public function __construct(
        private readonly GitLabClient $gitLab,
    ) {}

    public function dimension(): HealthDimension
    {
        return HealthDimension::Dependency;
    }

    public function analyze(\App\Models\Project $project): ?HealthAnalysisResult
    {
        $ref = $this->resolveDefaultBranch($project->gitlab_project_id);

        $composerLock = $this->readFile($project->gitlab_project_id, 'composer.lock', $ref);
        $packageLock = $this->readFile($project->gitlab_project_id, 'package-lock.json', $ref);

        if ($composerLock === null && $packageLock === null) {
            return null;
        }

        $phpVulnerabilities = [];
        $jsVulnerabilities = [];
        $packagesScanned = 0;

        if ($composerLock !== null) {
            $composerPayload = json_decode($composerLock, true);
            if (is_array($composerPayload)) {
                $packageNames = $this->extractComposerPackageNames($composerPayload);
                $packagesScanned += count($packageNames);
                if ($packageNames !== []) {
                    $phpVulnerabilities = $this->fetchPackagistVulnerabilities($packageNames);
                }
            }
        }

        if ($packageLock !== null) {
            $packagePayload = json_decode($packageLock, true);
            if (is_array($packagePayload)) {
                $dependencies = $packagePayload['packages'] ?? $packagePayload['dependencies'] ?? [];
                if (is_array($dependencies)) {
                    $packagesScanned += count($dependencies);
                }
            }
        }

        $allVulnerabilities = [...$phpVulnerabilities, ...$jsVulnerabilities];
        $penalty = 0;
        foreach ($allVulnerabilities as $vulnerability) {
            $penalty += $this->severityPenalty($vulnerability['severity']);
        }

        $score = max(0.0, 100.0 - $penalty);

        return new HealthAnalysisResult(
            dimension: $this->dimension(),
            score: $score,
            details: [
                'php_vulnerabilities' => $phpVulnerabilities,
                'js_vulnerabilities' => $jsVulnerabilities,
                'total_count' => count($allVulnerabilities),
                'packages_scanned' => $packagesScanned,
            ],
            sourceRef: $ref,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function extractComposerPackageNames(array $payload): array
    {
        $packages = [];
        $packageGroups = [$payload['packages'] ?? [], $payload['packages-dev'] ?? []];

        foreach ($packageGroups as $group) {
            if (! is_array($group)) {
                continue;
            }

            foreach ($group as $package) {
                if (! is_array($package)) {
                    continue;
                }

                $name = $package['name'] ?? null;
                if (is_string($name) && $name !== '') {
                    $packages[] = $name;
                }
            }
        }

        return array_values(array_unique($packages));
    }

    /**
     * @param  list<string>  $packageNames
     * @return list<array{package: string, advisory: string, severity: string, cve: string|null}>
     */
    private function fetchPackagistVulnerabilities(array $packageNames): array
    {
        $vulnerabilities = [];

        foreach (array_chunk($packageNames, 40) as $chunk) {
            try {
                $response = Http::retry(2, 250)
                    ->acceptJson()
                    ->get('https://packagist.org/api/security-advisories/', [
                        'packages' => $chunk,
                    ]);
            } catch (Throwable) {
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                continue;
            }

            $advisories = $payload['advisories'] ?? [];
            if (! is_array($advisories)) {
                continue;
            }

            foreach ($advisories as $package => $items) {
                if (! is_array($items)) {
                    continue;
                }

                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $severity = $this->normalizeSeverity($item['severity'] ?? $item['cvss_severity'] ?? null);
                    $vulnerabilities[] = [
                        'package' => (string) $package,
                        'advisory' => (string) ($item['title'] ?? $item['advisoryId'] ?? 'Security advisory'),
                        'severity' => $severity,
                        'cve' => $this->extractCve($item),
                    ];
                }
            }
        }

        return $vulnerabilities;
    }

    private function severityPenalty(string $severity): int
    {
        return match ($this->normalizeSeverity($severity)) {
            'critical' => 25,
            'high' => 15,
            'medium' => 5,
            default => 2,
        };
    }

    private function normalizeSeverity(mixed $severity): string
    {
        $normalized = strtolower((string) $severity);

        return match ($normalized) {
            'critical', 'high', 'medium', 'low' => $normalized,
            default => 'medium',
        };
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function extractCve(array $item): ?string
    {
        $cve = $item['cve'] ?? $item['cve_id'] ?? null;
        if (is_string($cve) && $cve !== '') {
            return $cve;
        }

        $identifiers = $item['cve'] ?? $item['identifiers'] ?? null;
        if (is_array($identifiers)) {
            foreach ($identifiers as $identifier) {
                if (is_string($identifier) && str_starts_with(strtoupper($identifier), 'CVE-')) {
                    return $identifier;
                }
            }
        }

        return null;
    }

    private function readFile(int $gitlabProjectId, string $path, string $ref): ?string
    {
        try {
            $payload = $this->gitLab->getFile($gitlabProjectId, $path, $ref);
        } catch (Throwable) {
            return null;
        }

        $content = $payload['content'];
        if ($content === '') {
            return null;
        }

        $encoding = strtolower($payload['encoding']);
        if ($encoding === 'base64') {
            $decoded = base64_decode($content, true);

            return is_string($decoded) ? $decoded : null;
        }

        return $content;
    }

    private function resolveDefaultBranch(int $gitlabProjectId): string
    {
        try {
            $project = $this->gitLab->getProject($gitlabProjectId);
            $defaultBranch = $project['default_branch'] ?? null;
            if (is_string($defaultBranch) && $defaultBranch !== '') {
                return $defaultBranch;
            }
        } catch (Throwable) {
            // Fallback branch when metadata read fails.
        }

        return 'main';
    }
}
