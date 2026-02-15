<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExternalProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $projects = $request->user()->accessibleProjects();

        return response()->json(['data' => $projects]);
    }
}
