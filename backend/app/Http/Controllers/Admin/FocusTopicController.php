<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FocusTopic;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class FocusTopicController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('FocusTopics/Index', [
            'topics' => FocusTopic::withCount('horses')->orderBy('order')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $data['slug'] ??= Str::slug($data['title']);
        $topic = FocusTopic::create($data);
        AuditLogger::created($topic);

        return back()->with('success', 'Focus topic added.');
    }

    public function update(Request $request, FocusTopic $focusTopic): RedirectResponse
    {
        $data = $this->validateData($request, $focusTopic->id);
        $before = $focusTopic->only(array_keys($data));
        $focusTopic->update($data);
        AuditLogger::updated($focusTopic, $before);

        return back()->with('success', 'Focus topic updated.');
    }

    public function destroy(FocusTopic $focusTopic): RedirectResponse
    {
        AuditLogger::deleted($focusTopic);
        $focusTopic->delete();

        return back()->with('success', 'Focus topic removed.');
    }

    private function validateData(Request $request, ?string $id = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:focus_topics,slug'.($id ? ",{$id}" : '')],
            'icon' => ['nullable', 'string', 'max:8'],
            'description' => ['nullable', 'string', 'max:255'],
            'order' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
