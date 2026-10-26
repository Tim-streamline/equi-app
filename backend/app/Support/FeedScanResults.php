<?php

namespace App\Support;

use App\Models\FeedAssessment;
use App\Models\IngredientRevision;
use App\Models\ProductVersion;

class FeedScanResults
{
    public static function make(ProductVersion $version, ?FeedAssessment $assessment, array $missing = []): array
    {
        $data = $version->data;
        $result = $assessment?->result ?? ['automatic_score' => null, 'score_cap' => null, 'findings' => [], 'missing_data' => ['Er is nog geen geldige gepubliceerde beoordeling beschikbaar.']];
        $score = $assessment?->overrides()->first()?->corrected_score ?? $result['automatic_score'];
        $ingredients = [];
        foreach ($data['ingredients'] ?? [] as $row) {
            $revision = ! empty($row['ingredient_revision_id']) ? IngredientRevision::find($row['ingredient_revision_id']) : null;
            $confirmed = ($row['match_status'] ?? '') === 'confirmed';
            $ingredients[] = ['label' => $row['label'], 'match_status' => $row['match_status'] ?? 'unknown',
                'ingredient_revision_id' => $row['ingredient_revision_id'] ?? null,
                'assessment' => $confirmed ? ($row['assessment'] ?? $revision?->data['assessment'] ?? 'unrated') : 'unrated',
                'explanation' => $confirmed ? ($row['explanation'] ?? $revision?->data['explanation'] ?? null) : null,
                'exceptions' => $confirmed ? ($row['exceptions'] ?? $revision?->data['exceptions'] ?? null) : null];
            if (! $confirmed) {
                $missing[] = 'Nog geen eenduidige gepubliceerde kenniskaart voor: '.$row['label'];
            }
        }
        $provisional = $version->status !== 'approved';

        return ['name' => $data['name'], 'brand' => $data['brand'], 'provisional' => $provisional, 'score' => $score, 'score_cap' => $result['score_cap'],
            'findings' => array_merge($result['findings'], array_map(fn ($c) => ['message' => $c['message']], $result['applied_caps'] ?? [])),
            'missing_data' => array_values(array_unique(array_merge($missing, $result['missing_data']))), 'ingredients' => $ingredients,
            'nutrients' => array_map(fn ($n) => array_intersect_key($n, array_flip(['code', 'value', 'unit', 'basis', 'qualifier'])), $data['nutrients'] ?? []),
            'product_version_id' => $version->id, 'rule_version_id' => $assessment?->rule_version_id, 'calibration_pending' => $score === null,
            'message' => ($provisional ? 'Voorlopige beoordeling; dit product staat ter beoordeling. ' : 'Deze samenstelling is door ons gecontroleerd. ').
                ($score === null ? 'Er is nog geen rapportcijfer. De volledige puntenweging is nog niet gekalibreerd; er wordt geen cijfer geschat.' : 'De beoordeling gebruikt de gepubliceerde regels en kenniskaarten.')];
    }
}
