<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('credit_reminders', function (Blueprint $table) {
            $table->string('push_token')->nullable();
            $table->timestampTz('receipt_checked_at')->nullable();
            $table->string('receipt_error')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('credit_reminders', fn (Blueprint $table) => $table->dropColumn(['push_token', 'receipt_checked_at', 'receipt_error']));
    }
};
