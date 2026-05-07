<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Balance;
use App\Models\Transaction;
use App\Services\Transaction\TransactionParserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $tenant = $user->currentTenant();

        if (! $tenant) {
            return response()->json(['data' => []]);
        }

        $query = Transaction::where('tenant_id', $tenant->id)
            ->with(['category', 'balance']);

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('start_date')) {
            $query->whereDate('transaction_date', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->whereDate('transaction_date', '<=', $request->end_date);
        }

        $query->orderBy('transaction_date', 'desc')->orderBy('created_at', 'desc');

        $transactions = $query->paginate($request->input('limit', 20));

        return response()->json([
            'data' => $transactions->items(),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $tenant = $user->currentTenant();

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0',
            'type' => 'required|in:income,expense,transfer',
            'category_id' => 'required|exists:categories,id',
            'balance_id' => 'required|exists:balances,id',
            'transaction_date' => 'required|date',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $limitService = app(\App\Services\SubscriptionLimitService::class);
            $limitCheck = $limitService->canCreateTransaction($tenant->id);

            if (! $limitCheck['can_create']) {
                return response()->json([
                    'message' => 'Batas transaksi bulanan tercapai',
                    'limit' => $limitCheck['limit'],
                    'current' => $limitCheck['current'],
                    'plan' => $limitCheck['plan'],
                ], 403);
            }

            DB::beginTransaction();

            $transaction = Transaction::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'amount' => $request->amount,
                'type' => $request->type,
                'category_id' => $request->category_id,
                'balance_id' => $request->balance_id,
                'transaction_date' => $request->transaction_date,
                'description' => $request->description,
            ]);

            // Update Balance
            $balance = Balance::find($request->balance_id);
            if ($request->type === 'income') {
                $balance->increment('balance', $request->amount);
            } elseif ($request->type === 'expense') {
                $balance->decrement('balance', $request->amount);
            }

            DB::commit();

            return response()->json([
                'message' => 'Transaction created successfully',
                'data' => $transaction->load(['category', 'balance']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Error creating transaction', 'error' => $e->getMessage()], 500);
        }
    }

    public function parse(Request $request, TransactionParserService $parser)
    {
        $user = $request->user();
        $tenant = $user->currentTenant();

        if (! $tenant) {
            return response()->json(['success' => false, 'error' => 'Tenant tidak ditemukan'], 404);
        }

        $validator = Validator::make($request->all(), [
            'text' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'error' => 'Teks tidak valid'], 422);
        }

        $result = $parser->parse($request->text, $tenant->id);

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}
