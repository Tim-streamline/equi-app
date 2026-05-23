<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('horse_shares', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('horse_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('grantee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('therapist_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role', 16); // full | read_only
            $table->date('since')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('horse_shares');
    }
};
