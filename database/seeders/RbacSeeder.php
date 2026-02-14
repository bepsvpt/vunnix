<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    /**
     * Default permissions grouped by area.
     * These are global — they exist once and are assigned to roles per-project.
     */
    private const PERMISSIONS = [
        'chat' => [
            ['name' => 'chat.access', 'description' => 'Can use the conversational chat UI'],
            ['name' => 'chat.dispatch_task', 'description' => 'Can trigger AI actions from chat'],
        ],
        'review' => [
            ['name' => 'review.view', 'description' => 'Can view AI review results on the dashboard'],
            ['name' => 'review.trigger', 'description' => 'Can trigger on-demand review via @ai in GitLab'],
        ],
        'config' => [
            ['name' => 'config.manage', 'description' => 'Can edit project-level Vunnix configuration'],
        ],
        'admin' => [
            ['name' => 'admin.roles', 'description' => 'Can create/edit roles and assign permissions'],
            ['name' => 'admin.global_config', 'description' => 'Can edit global Vunnix settings'],
        ],
    ];

    /**
     * Default role templates.
     *
     * These are NOT created in the seeder (roles are per-project), but define
     * the templates that get created when a new project is registered.
     * The seeder only creates the global permissions.
     *
     * Role templates are stored here as a reference and used by the
     * project registration flow (T88) to bootstrap roles for new projects.
     */
    public const ROLE_TEMPLATES = [
        'admin' => [
            'description' => 'Full access to all Vunnix features for this project',
            'is_default' => false,
            'permissions' => [
                'chat.access',
                'chat.dispatch_task',
                'review.view',
                'review.trigger',
                'config.manage',
                'admin.roles',
                'admin.global_config',
            ],
        ],
        'developer' => [
            'description' => 'Can use chat, view and trigger reviews',
            'is_default' => true,
            'permissions' => [
                'chat.access',
                'chat.dispatch_task',
                'review.view',
                'review.trigger',
            ],
        ],
        'viewer' => [
            'description' => 'Read-only access to review results',
            'is_default' => false,
            'permissions' => [
                'review.view',
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $group => $permissions) {
            foreach ($permissions as $permData) {
                Permission::firstOrCreate(
                    ['name' => $permData['name']],
                    [
                        'description' => $permData['description'],
                        'group' => $group,
                    ]
                );
            }
        }
    }

    /**
     * Create default roles for a project using the role templates.
     * Called when a new project is registered in Vunnix.
     */
    public static function createDefaultRolesForProject(\App\Models\Project $project): void
    {
        foreach (self::ROLE_TEMPLATES as $roleName => $template) {
            $role = $project->roles()->firstOrCreate(
                ['name' => $roleName],
                [
                    'description' => $template['description'],
                    'is_default' => $template['is_default'],
                ]
            );

            $permissionIds = Permission::whereIn('name', $template['permissions'])->pluck('id');
            $role->permissions()->syncWithoutDetaching($permissionIds);
        }
    }
}
