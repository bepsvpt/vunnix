<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExternalMetricsController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        // Stub — full implementation in T101
        return response()->json(['data' => []]);
    }
}
