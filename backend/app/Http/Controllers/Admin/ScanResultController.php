<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScanResult;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ScanResultController extends Controller
{
    public function index(Request $request): Response
    {
        $scans = ScanResult::query()
            ->when($request->string('q')->toString(), fn ($query, $q) => $query
                ->where(fn ($w) => $w->where('product_name', 'ilike', "%{$q}%")->orWhere('brand', 'ilike', "%{$q}%")))
            ->when($request->string('rating')->toString(), fn ($query, $r) => $query->where('rating', $r))
            ->when($request->boolean('flagged'), fn ($query) => $query->where('flagged', true))
            ->with('user:id,name', 'horse:id,name')
            ->latest('scanned_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Scans/Index', [
            'scans' => $scans,
            'filters' => $request->only('q', 'rating', 'flagged'),
            'flaggedCount' => ScanResult::where('flagged', true)->count(),
        ]);
    }

    public function show(ScanResult $scan): Response
    {
        $scan->load('user:id,name,email', 'horse:id,name', 'product:id,brand,name,barcode', 'ingredients');

        return Inertia::render('Scans/Show', ['scan' => $scan]);
    }

    public function flag(Request $request, ScanResult $scan): RedirectResponse
    {
        $scan->update(['flagged' => ! $scan->flagged]);
        AuditLogger::log($scan->flagged ? 'flag' : 'unflag', $scan, reason: $request->input('reason'));

        return back()->with('success', $scan->flagged ? 'Scan flagged for review.' : 'Flag cleared.');
    }

    public function destroy(ScanResult $scan): RedirectResponse
    {
        AuditLogger::deleted($scan);
        $scan->delete();

        return redirect()->route('admin.scans.index')->with('success', 'Scan deleted.');
    }
}
