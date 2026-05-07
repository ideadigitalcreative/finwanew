<?php

namespace App\Http\Controllers;

use App\Services\DebtReceivable\DebtReceivableLedgerService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DebtReceivableController extends Controller
{
    public function index(Request $request, DebtReceivableLedgerService $ledger): Response
    {
        $tenantId = (int) ($request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id);

        $summary = $ledger->summarize($tenantId);

        return Inertia::render('DebtReceivable/Index', [
            'summary' => $summary,
        ]);
    }
}
