<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductVersion;
use Illuminate\Support\Str;

class FeedCompositionMatcher
{
    private static function reference(string $text): string
    {
        // Do not cut away later additive sections from the reviewed reference.
        return preg_replace('/^\s*(?:samenstelling|composition|ingredients|ingrediënten|ingredienten)\s*:\s*/iu', '', trim($text));
    }

    public static function canonical(string $text): string
    {
        $text = Str::lower(Str::ascii($text));
        preg_match_all('/[a-z]+|\d+(?:[.,]\d+)?|[%+\/]/u', $text, $tokens);

        return implode(' ', array_map(function ($word) {
            if (preg_match('/^\d+[.,]\d+$/', $word)) {
                return rtrim(rtrim(str_replace(',', '.', $word), '0'), '.');
            }

            return $word;
        }, $tokens[0]));
    }

    /** Require both a heading and the next section: a cropped list is not evidence of completeness. */
    public static function section(string $text): ?string
    {
        $heading = '/(?:^|\n)\s*(?:samenstelling|composition|ingredients(?: of[^\n:]*)?|ingrediënten|ingredienten)\s*(?::|\n)/iu';
        if (! preg_match($heading, $text, $start, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $tail = substr($text, $start[0][1] + strlen($start[0][0]));
        if (! preg_match('/(?:^|\n)\s*(?:analytische|analytical|analyse\b|analysis\b|voedingswaarden|nutritional|gebruiksadvies|voedingsadvies|feeding|dosering|bewaren|storage)\b/iu', $tail, $end, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return trim(substr($tail, 0, $end[0][1]));
    }

    public function match(array $ocr, ?string $referenceId = null): array
    {
        $section = self::section($ocr['text']);
        $reliable = $section !== null && $ocr['confidence'] >= 90 && $ocr['words'] >= 8 && $ocr['low_confidence_words'] <= 1;
        $key = self::canonical($section ?? '');
        $products = Product::where('catalog_status', 'published')->whereNotNull('published_version_id')->get();
        $versions = ProductVersion::whereIn('id', $products->pluck('published_version_id'))->where('status', 'approved')->get()->keyBy('id');
        $exact = [];
        $identity = [];
        $fullText = ' '.self::canonical($ocr['text']).' ';
        foreach ($products as $product) {
            $version = $versions[$product->published_version_id] ?? null;
            if (! $version) {
                continue;
            }
            $data = $version->data;
            $hasIdentity = $referenceId === $product->id ||
                (strlen(self::canonical($data['brand'] ?? '')) >= 3 && str_contains($fullText, ' '.self::canonical($data['brand']).' ') &&
                 strlen(self::canonical($data['name'] ?? '')) >= 4 && str_contains($fullText, ' '.self::canonical($data['name']).' '));
            if ($hasIdentity) {
                $identity[$product->id] = $version;
            }
            $reference = self::canonical(self::reference($data['composition_text'] ?? ''));
            $complete = true;
            foreach ($data['ingredients'] ?? [] as $ingredient) {
                if (($ingredient['added_selenium'] ?? '') === 'confirmed' || in_array($ingredient['selenium_form'] ?? '', ['organic', 'inorganic'])) {
                    $evidence = self::canonical($ingredient['evidence'] ?? '');
                    if ($evidence === '' || ! str_contains(' '.$reference.' ', ' '.$evidence.' ')) {
                        $complete = false;
                    }
                }
            }
            if ($reliable && $complete && strlen($key) >= 25 && ($data['recognition_enabled'] ?? false) && $key === $reference) {
                $exact[$product->id] = $version;
            }
        }
        // Shared recipes and uncertain identity always need the extra evidence.
        if (count($exact) === 1 && ((count($identity) === 0 && ! $referenceId) || isset($identity[array_key_first($exact)]))) {
            $version = reset($exact);

            return ['decision' => 'candidate', 'product_id' => $version->product_id, 'version_id' => $version->id, 'name' => $version->data['name'], 'brand' => $version->data['brand'], 'composition_text' => $section, 'reference_composition' => $version->data['composition_text']];
        }
        $version = count($identity) === 1 ? reset($identity) : null;

        return ['decision' => $version && $reliable && $key !== self::canonical(self::reference($version->data['composition_text'] ?? '')) ? 'changed' : ($reliable ? 'unknown' : 'uncertain'),
            'product_id' => $version?->product_id ?? $referenceId, 'version_id' => $version?->id, 'composition_text' => $section];
    }
}
