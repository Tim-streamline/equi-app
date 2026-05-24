<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DataExport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DataExportController extends Controller
{
    public function index(Request $request): Response
    {
        $exports = DataExport::query()
            ->when($request->string('status')->toString(), function ($query, $s) {
                match ($s) {
                    'completed' => $query->whereNotNull('completed_at'),
                    'pending' => $query->whereNull('completed_at'),
                    default => null,
                };
            })
            ->with('user:id,name,email', 'horse:id,name')
            ->latest('requested_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Exports/Index', [
            'exports' => $exports,
            'filters' => $request->only('status'),
            'counts' => [
                'pending' => DataExport::whereNull('completed_at')->count(),
                'completed' => DataExport::whereNotNull('completed_at')->count(),
            ],
        ]);
    }
}
