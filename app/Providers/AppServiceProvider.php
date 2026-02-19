<?php

namespace App\Providers;

use App\Contracts\HealthAnalyzerContract;
use App\Models\ApiKey;
use App\Models\Conversation;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Modules\GitLabIntegration\Application\Contracts\GitLabPort;
use App\Modules\GitLabIntegration\Infrastructure\Adapters\GitLabIssueAdapter;
use App\Modules\GitLabIntegration\Infrastructure\Adapters\GitLabMergeRequestAdapter;
use App\Modules\GitLabIntegration\Infrastructure\Adapters\GitLabPipelineAdapter;
use App\Modules\GitLabIntegration\Infrastructure\Adapters\GitLabPortAdapter;
use App\Modules\GitLabIntegration\Infrastructure\Adapters\GitLabRepoAdapter;
use App\Modules\TaskOrchestration\Application\Handlers\CodeReviewIntentTaskHandler;
use App\Modules\TaskOrchestration\Application\Handlers\FeatureDevelopmentIntentTaskHandler;
use App\Modules\TaskOrchestration\Application\Handlers\IssueDiscussionIntentTaskHandler;
use App\Modules\TaskOrchestration\Application\Publishers\AskCommandResultPublisher;
use App\Modules\TaskOrchestration\Application\Publishers\CodeReviewResultPublisher;
use App\Modules\TaskOrchestration\Application\Publishers\FeatureDevelopmentResultPublisher;
use App\Modules\TaskOrchestration\Application\Publishers\IssueDiscussionResultPublisher;
use App\Modules\TaskOrchestration\Application\Publishers\PrdCreationResultPublisher;
use App\Modules\TaskOrchestration\Application\Publishers\ReviewPatternExtractionPublisher;
use App\Modules\TaskOrchestration\Application\Registries\IntentClassifierRegistry;
use App\Modules\TaskOrchestration\Application\Registries\ResultPublisherRegistry;
use App\Modules\TaskOrchestration\Application\Registries\TaskHandlerRegistry;
use App\Modules\TaskOrchestration\Infrastructure\Outbox\OutboxWriter;
use App\Modules\WebhookIntake\Application\Classifiers\IssueLabelClassifier;
use App\Modules\WebhookIntake\Application\Classifiers\IssueNoteClassifier;
use App\Modules\WebhookIntake\Application\Classifiers\MergeRequestLifecycleClassifier;
use App\Modules\WebhookIntake\Application\Classifiers\MergeRequestNoteClassifier;
use App\Modules\WebhookIntake\Application\Classifiers\PushToMergeRequestClassifier;
use App\Observers\TaskObserver;
use App\Policies\ConversationPolicy;
use App\Services\Health\ComplexityAnalyzer;
use App\Services\Health\CoverageAnalyzer;
use App\Services\Health\DependencyAnalyzer;
use App\Services\Health\HealthAlertService;
use App\Services\Health\HealthAnalysisService;
use App\Services\ProjectConfigService;
use App\Services\ProjectMemoryService;
use App\Services\TaskTokenService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TaskTokenService::class, function (\Illuminate\Foundation\Application $app): \App\Services\TaskTokenService {
            $appKey = $app['config']['app.key'];

            // Strip the base64: prefix if present
            if (Str::startsWith($appKey, 'base64:')) {
                $appKey = base64_decode(Str::after($appKey, 'base64:'), true);
            }

            return new TaskTokenService(
                appKey: $appKey,
                budgetMinutes: (int) $app['config']['vunnix.task_budget_minutes'],
            );
        });

        $this->app->bind(GitLabRepoAdapter::class);
        $this->app->bind(GitLabIssueAdapter::class);
        $this->app->bind(GitLabMergeRequestAdapter::class);
        $this->app->bind(GitLabPipelineAdapter::class);
        $this->app->singleton(GitLabPortAdapter::class);
        $this->app->singleton(GitLabPort::class, GitLabPortAdapter::class);
        $this->app->singleton(OutboxWriter::class);

        $this->app->bind(CoverageAnalyzer::class);
        $this->app->bind(DependencyAnalyzer::class);
        $this->app->bind(ComplexityAnalyzer::class);

        $this->app->tag([
            CoverageAnalyzer::class,
            DependencyAnalyzer::class,
            ComplexityAnalyzer::class,
        ], 'health.analyzers');

        $this->app->singleton(HealthAnalysisService::class, function (\Illuminate\Foundation\Application $app): HealthAnalysisService {
            /** @var iterable<HealthAnalyzerContract> $analyzers */
            $analyzers = $app->tagged('health.analyzers');

            return new HealthAnalysisService(
                analyzers: $analyzers,
                alertService: $app->make(HealthAlertService::class),
                projectConfigService: $app->make(ProjectConfigService::class),
                projectMemoryService: $app->make(ProjectMemoryService::class),
            );
        });

        $this->app->singleton(IntentClassifierRegistry::class, function (\Illuminate\Foundation\Application $app): IntentClassifierRegistry {
            return new IntentClassifierRegistry(
                classifiers: $app->tagged('task_orchestration.intent_classifiers'),
            );
        });

        $this->app->bind(MergeRequestLifecycleClassifier::class);
        $this->app->bind(MergeRequestNoteClassifier::class);
        $this->app->bind(IssueNoteClassifier::class);
        $this->app->bind(IssueLabelClassifier::class);
        $this->app->bind(PushToMergeRequestClassifier::class);

        $this->app->tag([
            MergeRequestLifecycleClassifier::class,
            MergeRequestNoteClassifier::class,
            IssueNoteClassifier::class,
            IssueLabelClassifier::class,
            PushToMergeRequestClassifier::class,
        ], 'task_orchestration.intent_classifiers');

        $this->app->singleton(TaskHandlerRegistry::class, function (\Illuminate\Foundation\Application $app): TaskHandlerRegistry {
            return new TaskHandlerRegistry(
                handlers: $app->tagged('task_orchestration.task_handlers'),
            );
        });

        $this->app->bind(CodeReviewIntentTaskHandler::class);
        $this->app->bind(IssueDiscussionIntentTaskHandler::class);
        $this->app->bind(FeatureDevelopmentIntentTaskHandler::class);

        $this->app->tag([
            CodeReviewIntentTaskHandler::class,
            IssueDiscussionIntentTaskHandler::class,
            FeatureDevelopmentIntentTaskHandler::class,
        ], 'task_orchestration.task_handlers');

        $this->app->singleton(ResultPublisherRegistry::class, function (\Illuminate\Foundation\Application $app): ResultPublisherRegistry {
            return new ResultPublisherRegistry(
                publishers: $app->tagged('task_orchestration.result_publishers'),
            );
        });

        $this->app->bind(CodeReviewResultPublisher::class);
        $this->app->bind(AskCommandResultPublisher::class);
        $this->app->bind(IssueDiscussionResultPublisher::class);
        $this->app->bind(FeatureDevelopmentResultPublisher::class);
        $this->app->bind(PrdCreationResultPublisher::class);
        $this->app->bind(ReviewPatternExtractionPublisher::class);

        $this->app->tag([
            CodeReviewResultPublisher::class,
            AskCommandResultPublisher::class,
            IssueDiscussionResultPublisher::class,
            FeatureDevelopmentResultPublisher::class,
            PrdCreationResultPublisher::class,
            ReviewPatternExtractionPublisher::class,
        ], 'task_orchestration.result_publishers');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->make(\Illuminate\Foundation\Vite::class)->useBuildDirectory('assets');

        Task::observe(TaskObserver::class);

        Gate::policy(Conversation::class, ConversationPolicy::class);

        $this->registerPermissionGates();
        $this->registerRateLimiters();
    }

    /**
     * Register API key rate limiter (per-key, 60 req/min).
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('api_key', function (\Illuminate\Http\Request $request) {
            $bearer = $request->bearerToken();
            $clientIp = $request->ip() ?? 'unknown';

            if ($bearer === null || $bearer === '') {
                return Limit::perMinute(60)->by('api_key:ip:'.$clientIp);
            }

            $tokenHash = hash('sha256', $bearer);
            $isValidApiKey = ApiKey::active()->where('key', $tokenHash)->exists();

            if (! $isValidApiKey) {
                return Limit::perMinute(60)->by('api_key:ip:'.$clientIp);
            }

            return Limit::perMinute(60)->by('api_key:key:'.$tokenHash.':ip:'.$clientIp);
        });
    }

    /**
     * Register a Gate for each permission in the database.
     *
     * Each gate receives a User and a Project, then checks whether the user
     * holds that permission on the given project (resolved through roles).
     */
    private function registerPermissionGates(): void
    {
        try {
            if (! Schema::hasTable('permissions')) {
                return;
            }

            $permissions = Permission::all();
        } catch (Throwable) {
            // Database not available (e.g., during tests, migrations, or before DB setup)
            return;
        }

        foreach ($permissions as $permission) {
            Gate::define($permission->name, function (User $user, Project $project) use ($permission): bool {
                return $user->hasPermission($permission->name, $project);
            });
        }
    }
}
