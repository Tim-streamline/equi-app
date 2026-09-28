<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlusPage;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Storage};
use Inertia\Inertia;

class PlusPageController extends Controller
{
    public function index()
    {
        return Inertia::render('PlusPage/Index', PlusPage::current()->payload());
    }

    public function update(Request $request)
    {
        if (is_string($request->input('content'))) {
            $request->merge(['content' => json_decode($request->input('content'), true)]);
        }
        $defaults = PlusPage::defaults();
        $rules = ['content' => ['required', 'array:'.implode(',', array_keys($defaults))],
            'hero' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'portrait' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'remove_portrait' => ['boolean']];
        foreach ($defaults as $key => $value) {
            if (is_string($value)) $rules['content.'.$key] = ['required', 'string', 'max:5000'];
        }
        $rules['content.reviewsAreExamples'] = ['required', 'boolean'];
        foreach (['steps' => ['title', 'body'], 'reviews' => ['title', 'quote', 'name'], 'faqs' => ['question', 'answer']] as $key => $fields) {
            $rules['content.'.$key] = ['present', 'array', 'list', 'max:20'];
            $rules['content.'.$key.'.*'] = ['array:'.implode(',', $fields)];
            foreach ($fields as $field) $rules['content.'.$key.'.*.'.$field] = ['required', 'string', 'max:5000'];
        }
        $rules['content.benefits'] = ['present', 'array', 'list', 'max:20'];
        $rules['content.benefits.*'] = ['required', 'string', 'max:500'];
        $data = $request->validate($rules);
        $created = []; $removed = [];
        try {
            foreach (['hero', 'portrait'] as $kind) {
                if ($request->hasFile($kind)) $created[$kind.'_path'] = $request->file($kind)->store('plus-page', 'local');
            }
            DB::transaction(function () use ($data, $created, &$removed) {
                // Seed once, then serialize edits to the singleton row.
                PlusPage::query()->insertOrIgnore(['id' => PlusPage::PAGE_ID, 'content' => json_encode(PlusPage::defaults()), 'created_at' => now(), 'updated_at' => now()]);
                $page = PlusPage::whereKey(PlusPage::PAGE_ID)->lockForUpdate()->firstOrFail();
                $before = $page->only(['content', 'hero_path', 'portrait_path']);
                $page->content = $data['content'];
                foreach (['hero_path', 'portrait_path'] as $field) {
                    if (isset($created[$field]) || ($field === 'portrait_path' && ($data['remove_portrait'] ?? false))) {
                        if ($page->$field) $removed[] = $page->$field;
                        $page->$field = $created[$field] ?? null;
                    }
                }
                $page->save();
                AuditLogger::updated($page, $before);
            });
        } catch (\Throwable $error) {
            Storage::disk('local')->delete(array_values($created));
            throw $error;
        }
        Storage::disk('local')->delete($removed);
        return back()->with('success', 'Ontdek Plus bijgewerkt.');
    }
}
