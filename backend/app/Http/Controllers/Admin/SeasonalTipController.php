<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LibraryItem;
use App\Models\SeasonalTip;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SeasonalTipController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('SeasonalTips/Index', [
            'tips' => SeasonalTip::with('ctaItem:id,title')->orderBy('month_order')->get(),
            'libraryItems' => LibraryItem::orderBy('title')->get(['id', 'title']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tip = SeasonalTip::create($this->validateData($request));
        AuditLogger::created($tip);

        return back()->with('success', 'Seasonal tip added.');
    }

    public function update(Request $request, SeasonalTip $seasonalTip): RedirectResponse
    {
        $data = $this->validateData($request);
        $before = $seasonalTip->only(array_keys($data));
        $seasonalTip->update($data);
        AuditLogger::updated($seasonalTip, $before);

        return back()->with('success', 'Seasonal tip updated.');
    }

    public function destroy(SeasonalTip $seasonalTip): RedirectResponse
    {
        AuditLogger::deleted($seasonalTip);
        $seasonalTip->delete();

        return back()->with('success', 'Seasonal tip removed.');
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'month' => ['required', 'string', 'max:16'],
            'month_order' => ['required', 'integer', 'min:1', 'max:12'],
            'body' => ['required', 'string'],
            'cta_item_id' => ['nullable', 'exists:library_items,id'],
            'active' => ['boolean'],
            'active_from' => ['nullable', 'date'],
            'active_to' => ['nullable', 'date', 'after_or_equal:active_from'],
        ]);
    }
}
