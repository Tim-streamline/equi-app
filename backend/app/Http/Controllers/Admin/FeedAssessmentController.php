<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeedAssessment;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Support\FeedAssessments;
use Illuminate\Http\Request;

class FeedAssessmentController extends Controller
{
    public function calculate(Request $request, Product $product)
    {
        $data = $request->validate(['version_id' => ['required', 'uuid']]);
        $version = ProductVersion::where('product_id', $product->id)->findOrFail($data['version_id']);
        (new FeedAssessments)->calculate($product, $version, $request->user('admin'));

        return back()->with('success', 'Beoordeling opgeslagen met de actieve regels. Open Beoordelingen voor de uitkomst.');
    }

    public function correct(Request $request, FeedAssessment $assessment)
    {
        abort_unless($request->user('admin')->hasRole('admin'), 403);
        $data = $request->validate(['score' => ['required', 'numeric', 'min:0', 'max:10', 'decimal:0,2'], 'reason' => ['required', 'string', 'min:3', 'max:10000']]);
        (new FeedAssessments)->correct($assessment, (string) $data['score'], $data['reason'], $request->user('admin'));

        return back()->with('success', 'Handmatige beoordeling opgeslagen. De automatische uitkomst en eerdere correcties blijven bewaard.');
    }
}
