<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\AffiliateTermsVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Publishing affiliate terms. Published versions are immutable; changing the
 * terms means publishing a new version, which members must then accept.
 */
class TermsController extends Controller
{
    public function index(): JsonResponse
    {
        $current = AffiliateTermsVersion::current();

        return response()->json([
            'data' => AffiliateTermsVersion::query()->orderByDesc('published_at')->orderByDesc('id')->get()
                ->map(fn (AffiliateTermsVersion $t) => [
                    'version' => $t->version,
                    'title' => $t->title,
                    'body' => $t->body,
                    'published_at' => $t->published_at->toIso8601String(),
                    'is_current' => $current?->is($t) ?? false,
                ]),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:affiliate_terms_versions,version'],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'min:50', 'max:100000'],
        ]);

        $terms = AffiliateTermsVersion::create($data + [
            'published_by' => $request->user()->id,
            'published_at' => now(),
        ]);

        $audit->log('affiliate.terms_published', $terms, ['version' => $terms->version]);

        return response()->json(['data' => ['version' => $terms->version]], 201);
    }
}
