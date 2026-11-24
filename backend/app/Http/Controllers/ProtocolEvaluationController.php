<?php

namespace App\Http\Controllers;

use App\Models\Protocol;
use App\Support\ProtocolEvaluation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProtocolEvaluationController extends Controller
{
    private function authorizeProtocol(Request $request, Protocol $protocol): void
    {
        abort_unless($protocol->horse->owner_id === $request->attributes->get('powersync_user_id')
            && $protocol->horse->status === 'active', 404);
    }

    public function show(Request $request, Protocol $protocol, ProtocolEvaluation $service)
    {
        $this->authorizeProtocol($request, $protocol);
        $payload = $service->payload($protocol, CarbonImmutable::now());
        abort_unless($payload['available'], 422, 'De evaluatie is beschikbaar vanaf twee weken voor het einde van je herstelplan.');
        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, Protocol $protocol, ProtocolEvaluation $service)
    {
        $this->authorizeProtocol($request, $protocol);
        $questions = $service->questions($protocol);
        abort_unless($questions, 422, 'Er zijn nog geen evaluatievragen ingesteld.');
        // A draft may still be empty; a submission needs every answer (checked per question below).
        $rules = ['submit' => ['required', 'boolean'], 'revision' => ['sometimes', 'integer', 'min:0'], 'question_version' => ['required', 'string', 'size:64'], 'answers' => ['present', 'array']];
        $request->validate($rules);
        // Before the per-question rules: a form opened before the questions changed must get
        // the 'reopen' answer, not a complaint about a question it never showed.
        abort_unless(hash_equals($service->questionVersion($questions), $request->input('question_version')), 409, 'De evaluatievragen zijn aangepast. Heropen het formulier.');
        $rules['answers'] = ['present', 'array:'.implode(',', array_keys($questions))];
        foreach ($questions as $key => $question) $rules['answers.'.$key] = [$request->boolean('submit') ? 'required' : 'nullable', 'string', 'max:5000'];
        $data = $request->validate($rules);
        $payload = DB::transaction(function () use ($protocol, $service, $request, $data) {
            $protocol = Protocol::whereKey($protocol->id)->lockForUpdate()->firstOrFail();
            $this->authorizeProtocol($request, $protocol);
            abort_unless($service->timing($protocol, CarbonImmutable::now())['available'], 422);
            $questions = $service->questions($protocol);
            abort_unless(hash_equals($service->questionVersion($questions), $data['question_version']), 409, 'De evaluatievragen zijn aangepast. Heropen het formulier.');
            $old = $service->record($protocol);
            $revision = (int) ($old->revision ?? 0);
            abort_if(isset($data['revision']) && (int) $data['revision'] !== $revision, 412, 'Deze evaluatie is intussen op een ander apparaat opgeslagen.');
            abort_if($old?->submitted_at && ! $data['submit'], 422, 'Verstuur wijzigingen opnieuw om je ingestuurde evaluatie bij te werken.');
            DB::table('protocol_evaluations')->updateOrInsert(['protocol_id' => $protocol->id], [
                // timing() above refuses another owner's evaluation, so this never changes the author.
                'user_id' => $old?->user_id ?? $request->attributes->get('powersync_user_id'), 'answers' => json_encode((object) $data['answers']),
                'questions' => json_encode($questions), 'revision' => $revision + 1,
                'submitted_at' => $old?->submitted_at ?? ($data['submit'] ? now() : null),
                'created_at' => $old?->created_at ?? now(), 'updated_at' => now(),
            ]);
            return $service->payload($protocol, CarbonImmutable::now());
        });
        return response()->json($payload);
    }

}
