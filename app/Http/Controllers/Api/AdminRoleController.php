<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AdminRoleController extends Controller
{
    private function authorizeRoleAdmin(Request $request): void
    {
        $user = $request->user();

        $hasRoleAdmin = $user->projects()
            ->get()
            ->contains(fn ($project) => $user->hasPermission('admin.roles', $project));

        if (! $hasRoleAdmin) {
            abort(403, 'Role management access required.');
        }
    }
}
