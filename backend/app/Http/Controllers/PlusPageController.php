<?php

namespace App\Http\Controllers;

use App\Models\PlusPage;
use Illuminate\Support\Facades\Storage;

class PlusPageController extends Controller
{
    public function show()
    {
        return response()->json(PlusPage::current()->payload())->header('Cache-Control', 'no-cache');
    }

    public function image(string $kind)
    {
        $page = PlusPage::current();
        $path = $kind === 'hero' ? $page->hero_path : $page->portrait_path;
        if ($path) {
            abort_unless(Storage::disk('local')->exists($path), 404);
            return Storage::disk('local')->response($path, null, ['Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
        }
        abort_unless($kind === 'hero', 404);
        return response()->file(resource_path('plus/hero.jpg'), ['Cache-Control' => 'public, max-age=300']);
    }
}
