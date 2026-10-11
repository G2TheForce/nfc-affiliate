<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Commissions\ReconciliationService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Commissions\ReconciliationRunResource;
use App\Models\ReconciliationRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReconciliationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ReconciliationRunResource::collection(ReconciliationRun::query()->with('trigger')->latest('id')->paginate(30));
    }

    /** Runs a check now. Read-only: it records results and never changes balances. */
    public function store(Request $request, ReconciliationService $service): JsonResponse
    {
        return (new ReconciliationRunResource($service->run($request->user())->load('trigger')))->response()->setStatusCode(201);
    }
}
