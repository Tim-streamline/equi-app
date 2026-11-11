<?php

use App\Support\EquiBoostStandardAdvice;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(EquiBoostStandardAdvice::class)->install();
    }

    public function down(): void
    {
        // Keep library advice: admins may have edited it or selected it in a protocol.
    }
};
