<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('protocols', function (Blueprint $table) {
            $table->foreignUuid('protocol_type_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();
        });

        if (DB::table('protocols')->whereNull('protocol_type_id')->exists()) {
            $protocolTypeId = DB::table('protocol_types')
                ->orderBy('created_at')
                ->value('id');

            if (! $protocolTypeId) {
                $protocolTypeId = (string) Str::uuid();
                DB::table('protocol_types')->insert([
                    'id' => $protocolTypeId,
                    'name' => 'Algemeen',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('protocols')
                ->whereNull('protocol_type_id')
                ->update(['protocol_type_id' => $protocolTypeId]);
        }

        DB::statement('ALTER TABLE protocols ALTER COLUMN protocol_type_id SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('protocols', function (Blueprint $table) {
            $table->dropConstrainedForeignId('protocol_type_id');
        });
    }
};
