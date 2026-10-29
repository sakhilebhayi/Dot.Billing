<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Billing\CompanyRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/companies: InfoDot tells Billing a company exists, was renamed or was deleted (docs/company-registry.md).
 * Authenticated by EnsureInfoDotCaller before anything here runs. Creates no team and no user, and a deletion only
 * flags: it never cancels or changes a subscription, invoice or payment. Idempotent.
 */
class CompanyRegistryController extends Controller
{
    public function store(Request $request, CompanyRegistry $registry): JsonResponse
    {
        $data = $request->validate([
            'company_uuid' => ['required', 'string', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'deleted' => ['nullable', 'boolean'],
        ]);

        $status = $registry->apply($data['company_uuid'], trim($data['name']), (bool) ($data['deleted'] ?? false));

        return response()->json(['status' => $status]);
    }
}
