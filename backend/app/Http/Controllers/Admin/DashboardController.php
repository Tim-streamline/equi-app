<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $now = Carbon::now();
        $weekAgo = $now->copy()->subWeek();

        $stats = [
            'users' => Models\User::count(),
            'users_onboarded' => Models\User::whereNotNull('onboarded_at')->count(),
            'users_new_week' => Models\User::where('created_at', '>=', $weekAgo)->count(),
            'horses' => Models\Horse::where('status', 'active')->count(),
            'protocols_active' => Models\Protocol::where('status', 'active')->count(),
            'scans' => Models\ScanResult::count(),
            'scans_week' => Models\ScanResult::where('scanned_at', '>=', $weekAgo)->count(),
            'community_posts' => Models\CommunityPost::count(),
            'bookings_pending' => Models\IntakeBooking::where('status', 'pending')->count(),
            'subs_active' => Models\Subscription::where('status', 'active')->count(),
            'reports_open' => Models\ModerationReport::where('status', 'open')->count(),
            'mrr_cents' => (int) Models\Subscription::where('status', 'active')
                ->where('interval', 'monthly')->sum('price_cents'),
        ];

        // 12-week signup trend.
        $signups = Models\User::query()
            ->where('created_at', '>=', $now->copy()->subWeeks(12))
            ->get(['created_at'])
            ->groupBy(fn ($u) => $u->created_at->startOfWeek()->format('Y-m-d'))
            ->map->count();

        $trend = collect(range(11, 0))->map(function ($i) use ($now, $signups) {
            $week = $now->copy()->subWeeks($i)->startOfWeek();
            return [
                'label' => $week->format('d/m'),
                'value' => $signups->get($week->format('Y-m-d'), 0),
            ];
        })->values();

        $planBreakdown = Models\Subscription::where('status', 'active')
            ->select('plan_id', DB::raw('count(*) as total'))
            ->groupBy('plan_id')
            ->with('plan:id,name')
            ->get()
            ->map(fn ($s) => ['name' => $s->plan?->name ?? 'Unknown', 'total' => (int) $s->total]);

        $recentScans = Models\ScanResult::with('user:id,name')
            ->latest('scanned_at')->limit(6)
            ->get(['id', 'user_id', 'product_name', 'brand', 'score', 'rating', 'scanned_at']);

        $recentSignups = Models\User::latest()->limit(6)
            ->get(['id', 'name', 'email', 'created_at', 'onboarded_at']);

        $recentAudit = Models\AuditLog::with('admin:id,name')
            ->latest('created_at')->limit(8)->get();

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'trend' => $trend,
            'planBreakdown' => $planBreakdown,
            'recentScans' => $recentScans,
            'recentSignups' => $recentSignups,
            'recentAudit' => $recentAudit,
        ]);
    }
}
