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
        foreach (['supplements', 'protocol_phase_supplements'] as $table) {
            DB::table($table)->where(function ($query) {
                foreach (['psyllium', 'psylium', 'lijnzaad', 'celzout', 'schüsslerzout', 'schusslerzout'] as $name) {
                    $query->orWhereRaw('lower(name) like ?', ['%'.$name.'%']);
                }
            })->update(['administrations_per_day' => 2]);
        }
    }

    public function down(): void
    {
        Schema::table('supplements', fn (Blueprint $table) => $table->dropColumn('product_url'));
        Schema::table('supplements', fn (Blueprint $table) => $table->dropColumn('administrations_per_day'));
        Schema::table('protocol_phase_supplements', fn (Blueprint $table) => $table->dropColumn('administrations_per_day'));
    }
};
