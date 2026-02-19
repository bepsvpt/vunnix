<?php

namespace App\Contracts;

use App\DTOs\HealthAnalysisResult;
use App\Enums\HealthDimension;
use App\Models\Project;

interface HealthAnalyzerContract
{
    public function dimension(): HealthDimension;

    public function analyze(Project $project): ?HealthAnalysisResult;
}
