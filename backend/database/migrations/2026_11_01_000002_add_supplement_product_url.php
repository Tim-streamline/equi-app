<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplements', function (Blueprint $table) {
            $table->string('product_url', 2048)->nullable();
            $table->unsignedSmallInteger('administrations_per_day')->default(1);
        });
        Schema::table('protocol_phase_supplements', fn (Blueprint $table) => $table->unsignedSmallInteger('administrations_per_day')->default(1));
        // Only the known twice-daily catalog products (see database/data/protocol-templates.json).
        // Exact names: substring matches would also hit unrelated products such as "Lijnzaadolie".
        $twiceDaily = [
            'Gekookt (bio) lijnzaad',
            'Psyliumzaad (bio)',
            'Schüsslerzout nr. 10 – Natrium sulfuricum',
            'Schüsslerzout nr. 27 – Kalium bichromicum',
        ];
        foreach (['supplements', 'protocol_phase_supplements'] as $table) {
            DB::table($table)->whereIn('name', $twiceDaily)->update(['administrations_per_day' => 2]);
        }
    }

    public function down(): void
    {
        Schema::table('supplements', fn (Blueprint $table) => $table->dropColumn('product_url'));
        Schema::table('supplements', fn (Blueprint $table) => $table->dropColumn('administrations_per_day'));
        Schema::table('protocol_phase_supplements', fn (Blueprint $table) => $table->dropColumn('administrations_per_day'));
    }
};
