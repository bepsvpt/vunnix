<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Override the broadcast driver to reverb so channel authorization callbacks
// are actually evaluated. The null driver (phpunit.xml default) skips them.
uses()->beforeEach(function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app-id',
        'broadcasting.connections.reverb.options.host' => 'localhost',
        'broadcasting.connections.reverb.options.port' => 8080,
        'broadcasting.connections.reverb.options.scheme' => 'http',
        'broadcasting.connections.reverb.options.useTLS' => false,
    ]);

    // Rebuild the broadcaster with reverb driver, then re-register channels.
    // Channel definitions are bound to the driver instance, so we need to
    // re-load channels.php after switching from null to reverb.
    $manager = app(\Illuminate\Broadcasting\BroadcastManager::class);
    $manager->forgetDrivers();
    require base_path('routes/channels.php');
});

test('task channel authorizes user with access to task project', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['enabled' => true]);
    $user->projects()->attach($project->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);
    $task = Task::factory()->create(['project_id' => $project->id]);

    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'channel_name' => "private-task.{$task->id}",
            'socket_id' => '1234.5678',
        ])
        ->assertOk();
});

test('task channel rejects user without access to task project', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['enabled' => true]);
    $task = Task::factory()->create(['project_id' => $project->id]);

    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'channel_name' => "private-task.{$task->id}",
            'socket_id' => '1234.5678',
        ])
        ->assertForbidden();
});

test('project activity channel authorizes project member', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['enabled' => true]);
    $user->projects()->attach($project->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'channel_name' => "private-project.{$project->id}.activity",
            'socket_id' => '1234.5678',
        ])
        ->assertOk();
});

test('project activity channel rejects non-member', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['enabled' => true]);

    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'channel_name' => "private-project.{$project->id}.activity",
            'socket_id' => '1234.5678',
        ])
        ->assertForbidden();
});

test('metrics channel authorizes project member', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['enabled' => true]);
    $user->projects()->attach($project->id, [
        'gitlab_access_level' => 30,
        'synced_at' => now(),
    ]);

    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'channel_name' => "private-metrics.{$project->id}",
            'socket_id' => '1234.5678',
        ])
        ->assertOk();
});

test('metrics channel rejects non-member', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['enabled' => true]);

    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'channel_name' => "private-metrics.{$project->id}",
            'socket_id' => '1234.5678',
        ])
        ->assertForbidden();
});

test('unauthenticated user cannot authorize any channel', function (): void {
    $task = Task::factory()->create();

    $this->postJson('/broadcasting/auth', [
        'channel_name' => "private-task.{$task->id}",
        'socket_id' => '1234.5678',
    ])->assertUnauthorized();
});
