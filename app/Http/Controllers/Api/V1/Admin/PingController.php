<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PingController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'roles' => $request->user()->getRoleNames()->values(),
        ]);
    }
}
