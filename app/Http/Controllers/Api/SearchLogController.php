<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchLogController extends Controller
{
    public function insights(Request $request): JsonResponse
    {
        if (AccountController::user($request)?->role !== 'admin') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $today = now('Asia/Baghdad')->startOfDay()->utc();
        $tomorrow = $today->copy()->addDay();
        $searches = DB::table('search_logs')->where('created_at', '>=', $today)->where('created_at', '<', $tomorrow);
        $popular = (clone $searches)->selectRaw('LOWER(query) as query, COUNT(*) as searches')
            ->groupByRaw('LOWER(query)')->orderByDesc('searches')->limit(8)->get();
        $lost = DB::table('search_logs')->where('result_count', 0)->where('has_more', false)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('LOWER(query) as query, brand_id, COUNT(*) as searches, MAX(created_at) as last_searched_at')
            ->groupByRaw('LOWER(query), brand_id')->orderByDesc('searches')->limit(30)->get();
        $attention = Order::query()->where(function ($query): void {
            $query->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_AWAITING_DELIVERY, Order::STATUS_ON_HOLD])
                ->orWhere(function ($query): void {
                    $query->where('status', Order::STATUS_PAID)->where(function ($query): void {
                        $query->whereNull('dolibarr_synced_at')->orWhereNotIn('fulfilment_status', ['delivered', 'returned']);
                    });
                });
        });

        return response()->json(['data' => [
            'date' => now('Asia/Baghdad')->toDateString(),
            'paid_sales' => (int) Order::where('status', Order::STATUS_PAID)->where('paid_at', '>=', $today)->where('paid_at', '<', $tomorrow)->sum('total'),
            'new_orders' => Order::where('created_at', '>=', $today)->where('created_at', '<', $tomorrow)->count(),
            'attention_count' => (clone $attention)->count(),
            'attention_orders' => $attention->latest()->limit(5)->get(['reference', 'status', 'fulfilment_status']),
            'popular_searches' => $popular,
            'lost_searches' => $lost,
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        if (AccountController::user($request)?->role !== 'admin') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $input = $request->validate([
            'outcome' => ['sometimes', 'in:all,empty,found'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = DB::table('search_logs')->leftJoin('users', 'users.id', '=', 'search_logs.user_id')
            ->select('search_logs.*', 'users.name', 'users.username');
        if (($input['outcome'] ?? '') === 'empty') {
            $query->where('result_count', 0)->where('has_more', false);
        } elseif (($input['outcome'] ?? '') === 'found') {
            $query->where('result_count', '>', 0);
        }
        $logs = $query->orderByDesc('search_logs.id')->paginate(30);
        $logs->through(function (object $log): object {
            $log->results = json_decode($log->results, true);

            return $log;
        });

        return response()->json($logs);
    }
}
