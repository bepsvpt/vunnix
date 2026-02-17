<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\User;
use App\Services\GitLabClient;
use App\Services\ProjectEnablementService;
use Database\Seeders\RbacSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Throwable;

class VunnixSetup extends Command
{
    protected $signature = 'vunnix:setup
        {gitlab_project_path : GitLab project path (e.g. mygroup/myproject)}
        {--admin-email= : Email of user to grant admin role (must exist via OAuth)}
        {--force : Skip confirmations}';

    protected $description = 'Bootstrap Vunnix: seed permissions, register a GitLab project, and assign the first admin';

    public function __construct(
        private readonly GitLabClient $gitLab,
        private readonly ProjectEnablementService $enablement,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('');
        $this->info('  Vunnix Setup');
        $this->info('  ============');
        $this->info('');

        // 1. Validate prerequisites
        if (! $this->validatePrerequisites()) {
            return self::FAILURE;
        }

        // 2. Seed RBAC permissions
        $this->seedPermissions();

        // 3. Resolve GitLab project
        $gitlabProject = $this->resolveGitLabProject();
        if ($gitlabProject === null) {
            return self::FAILURE;
        }

        // 4. Create project record
        $project = $this->createProjectRecord($gitlabProject);

        // 5. Enable project (webhook, labels, bot check)
        if (! $this->enableProject($project)) {
            return self::FAILURE;
        }

        // 6. Create default roles
        $this->createDefaultRoles($project);

        // 7. Assign admin (optional)
        if ($this->option('admin-email') !== null && ! $this->assignAdmin($project)) {
            return self::FAILURE;
        }

        $this->info('');
        $this->info('  Setup complete.');
        $this->info('');

        return self::SUCCESS;
    }

    private function validatePrerequisites(): bool
    {
        $botToken = config('services.gitlab.bot_token');
        $appUrl = config('app.url');

        if ($botToken === null || $botToken === '') {
            $this->error('GITLAB_BOT_TOKEN is not set in .env');

            return false;
        }

        if (in_array($appUrl, [null, '', 'http://localhost'], true)) {
            $this->error('APP_URL is not configured. Set it to your public URL (e.g. https://your-tunnel.trycloudflare.com)');

            return false;
        }

        $this->info('  [✓] Prerequisites validated');

        return true;
    }

    private function seedPermissions(): void
    {
        Artisan::call('db:seed', [
            '--class' => RbacSeeder::class,
            '--force' => true,
        ]);

        $this->info('  [✓] RBAC permissions seeded');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveGitLabProject(): ?array
    {
        $path = $this->argument('gitlab_project_path');

        $this->info("  [ ] Looking up GitLab project: {$path}");

        try {
            $project = $this->gitLab->getProjectByPath($path);
        } catch (Throwable $e) {
            $this->error("  Failed to look up GitLab project '{$path}': {$e->getMessage()}");
            $this->error('  Check that GITLAB_BOT_TOKEN has access to this project.');

            return null;
        }

        $this->info("  [✓] Found: {$project['path_with_namespace']} (ID: {$project['id']})");

        return $project;
    }

    /**
     * @param  array<string, mixed>  $gitlabProject
     */
    private function createProjectRecord(array $gitlabProject): Project
    {
        $project = Project::firstOrCreate(
            ['gitlab_project_id' => $gitlabProject['id']],
            [
                'name' => $gitlabProject['name'],
                'slug' => Str::slug($gitlabProject['path_with_namespace']),
                'description' => $gitlabProject['description'] ?? '',
                'enabled' => false,
            ],
        );

        if ($project->wasRecentlyCreated) {
            $this->info("  [✓] Project record created (Vunnix ID: {$project->id})");
        } else {
            $this->info("  [✓] Project record exists (Vunnix ID: {$project->id})");
        }

        return $project;
    }

    private function enableProject(Project $project): bool
    {
        if ($project->enabled && ! $this->option('force')) {
            $this->info('  [✓] Project already enabled — skipping');

            return true;
        }

        $this->info('  [ ] Enabling project (bot check, webhook, labels)...');

        $result = $this->enablement->enable($project);

        if (! $result['success']) {
            $this->error('  Failed: '.($result['error'] ?? 'Unknown error'));

            return false;
        }

        foreach ($result['warnings'] as $warning) {
            $this->warn("  [!] {$warning}");
        }

        $this->info('  [✓] Project enabled');

        return true;
    }

    private function createDefaultRoles(Project $project): void
    {
        RbacSeeder::createDefaultRolesForProject($project);

        $roleNames = $project->roles()->pluck('name')->implode(', ');
        $this->info("  [✓] Default roles created: {$roleNames}");
    }

    private function assignAdmin(Project $project): bool
    {
        $email = $this->option('admin-email');
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("  User with email '{$email}' not found. They must log in via GitLab OAuth first.");

            return false;
        }

        // Attach user to project if not already
        if (! $user->projects()->where('projects.id', $project->id)->exists()) {
            $user->projects()->attach($project->id, [
                'gitlab_access_level' => 50,
                'synced_at' => now(),
            ]);
        }

        // Assign admin role
        $adminRole = $project->roles()->where('name', 'admin')->first();

        if ($adminRole === null) {
            $this->error('  Admin role not found — roles may not have been created correctly.');

            return false;
        }

        if (! $user->hasRole('admin', $project)) {
            $user->assignRole($adminRole, $project);
        }

        $permCount = $user->permissionsForProject($project)->count();
        $this->info("  [✓] Admin role assigned to {$user->name} ({$email}) — {$permCount} permissions");

        return true;
    }
}
