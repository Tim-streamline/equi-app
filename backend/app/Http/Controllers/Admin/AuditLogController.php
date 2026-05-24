<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $logs = AuditLog::query()
            ->when($request->string('action')->toString(), fn ($query, $a) => $query->where('action', $a))
            ->when($request->string('q')->toString(), fn ($query, $q) => $query
                ->where(fn ($w) => $w->where('target_label', 'ilike', "%{$q}%")->orWhere('actor_name', 'ilike', "%{$q}%")))
            ->latest('created_at')
            ->paginate(40)
            ->withQueryString();

        return Inertia::render('Audit/Index', [
            'logs' => $logs,
            'filters' => $request->only('action', 'q'),
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
