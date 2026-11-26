<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Horse;
use App\Models\Protocol;
use App\Models\ProtocolEvaluationQuestion;
use App\Support\AuditLogger;
use App\Support\PdfDocument;
use App\Support\ProtocolEvaluation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ProtocolEvaluationController extends Controller
{
    public function index()
    {
        return Inertia::render('EvaluationQuestions/Index', ['questions' => ProtocolEvaluationQuestion::orderBy('order')->orderBy('id')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:2000'], 'order' => ['required', 'integer', 'between:0,10000']]);
        DB::transaction(function () use ($data) {
            $question = ProtocolEvaluationQuestion::create($data + ['active' => true]);
            AuditLogger::log('created', $question, after: $question->getAttributes(), label: Str::limit($question->label, 250));
        });
        return back()->with('success', 'Evaluatievraag toegevoegd.');
    }

    public function update(Request $request, ProtocolEvaluationQuestion $question)
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:2000'], 'order' => ['required', 'integer', 'between:0,10000'], 'active' => ['required', 'boolean']]);
        return $this->saveQuestion($question, $data);
    }

    public function destroy(ProtocolEvaluationQuestion $question)
    {
        return $this->saveQuestion($question, ['active' => false]);
    }

    private function saveQuestion(ProtocolEvaluationQuestion $question, array $data)
    {
        DB::transaction(function () use ($question, $data) {
            ProtocolEvaluationQuestion::orderBy('id')->lockForUpdate()->get();
            $question->refresh();
            $before = $question->only(['label', 'order', 'active']);
            if (! $data['active'] && ! ProtocolEvaluationQuestion::where('active', true)->whereKeyNot($question->id)->exists()) {
                throw ValidationException::withMessages(['active' => 'Behoud minimaal één actieve evaluatievraag.']);
            }
            $question->update($data);
            AuditLogger::log('updated', $question, $before, $question->only(['label', 'order', 'active']), label: Str::limit($question->label, 250));
        });
        return back()->with('success', 'Evaluatievraag opgeslagen.');
    }

    public function pdf(Horse $horse, Protocol $protocol, ProtocolEvaluation $service)
    {
        abort_unless($protocol->horse_id === $horse->id, 404);
        $evaluation = $service->admin($protocol);
        abort_unless($evaluation, 404, 'Er is nog geen verstuurde evaluatie.');
        $title = 'Evaluatie van '.$horse->name;
        $meta = $protocol->title.' | Ingediend op '.\Carbon\Carbon::parse($evaluation['submitted_at'])->timezone('Europe/Amsterdam')->format('d-m-Y');
        $html = view('intake.evaluation-pdf', ['title' => $title, 'meta' => $meta, 'questions' => $evaluation['questions'], 'answers' => $evaluation['answers']])->render();

        return PdfDocument::download(PdfDocument::render($html), 'EquiBoost_evaluatie_'.Str::slug($horse->name).'_'.$protocol->id.'.pdf');
    }
}
