<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plus_pages', fn (Blueprint $table) => $table->unsignedInteger('protocol_price_cents')->default(14700));
        // Preserve authored content and assets while updating obsolete product terminology.
        foreach (DB::table('plus_pages')->get() as $page) {
            $content = json_decode($page->content, true) ?? [];
            array_walk_recursive($content, function (&$value) {
                if (is_string($value)) {
                    $value = str_replace(['EquiApp Plus', 'Plus-member', 'Plus', 'PLUS', 'Onbeperkt voer & supplementen scannen'], ['persoonlijk protocol', 'protocolklant', 'persoonlijk protocol', 'PROTOCOL', 'Een bijbehorende intake voor jouw paard'], $value);
                }
            });
            $content['period'] = 'per protocol';
            $content['priceNote'] = 'Een losse aankoop voor je paard. Je abonnement staat hier los van.';
            $content['confirmationPlan'] = 'Eenmalige aankoop';
            $content['confirmationTitle'] = 'Persoonlijk protocol';
            $content['confirmationButton'] = 'Protocol kopen';
            $content['heroButton'] = 'Protocol kopen';
            foreach ($content['faqs'] ?? [] as $i => $faq) {
                if (str_contains($faq['answer'], 'stopt automatisch') || str_contains($faq['answer'], '10% korting')) {
                    $content['faqs'][$i]['answer'] = 'Je abonnement en je protocol zijn afzonderlijk. Overleg met je behandelaar over een vervolgprotocol. Eerdere protocollen blijven bij je paard bewaard.';
                }
            }
            DB::table('plus_pages')->where('id', $page->id)->update(['content' => json_encode($content)]);
        }
    }

    public function down(): void
    {
        Schema::table('plus_pages', fn (Blueprint $table) => $table->dropColumn('protocol_price_cents'));
    }
};
