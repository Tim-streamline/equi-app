<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_bookings', function (Blueprint $table) {
            $table->uuid('therapist_id')->nullable()->change();
            $table->timestamp('scheduled_at')->nullable()->change();
            $table->string('intake_status', 16)->default('draft');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->jsonb('accepted_triggers')->default('[]');
            $table->text('review_notes')->nullable();
            $table->timestamp('review_updated_at')->nullable();
        });
        Schema::table('intake_answers', fn (Blueprint $table) => $table->dropForeign(['response_id']));
        // Preserve every answer. Only merge an old response into an unambiguous
        // booking for the same owner and horse; otherwise retain its UUID as a booking.
        foreach (DB::table('intake_responses')->orderBy('created_at')->get() as $response) {
            $candidates = DB::table('intake_bookings')->where('user_id', $response->user_id)
                ->where('horse_id', $response->horse_id)->whereNull('started_at')->whereNull('submitted_at')->get();
            $id = $candidates->count() === 1 ? $candidates->first()->id : $response->id;
            $values = ['intake_status' => $response->status, 'started_at' => $response->started_at ?? $response->created_at,
                'submitted_at' => $response->submitted_at, 'updated_at' => $response->updated_at];
            if ($candidates->count() === 1) {
                DB::table('intake_bookings')->where('id', $id)->update($values);
            } else {
                DB::table('intake_bookings')->insert($values + ['id' => $id, 'user_id' => $response->user_id,
                    'horse_id' => $response->horse_id, 'created_at' => $response->created_at]);
            }
            DB::table('intake_answers')->where('response_id', $response->id)->update(['response_id' => $id]);
        }
        Schema::drop('intake_responses');
        Schema::table('intake_answers', fn (Blueprint $table) => $table->foreign('response_id')->references('id')->on('intake_bookings')->cascadeOnDelete());
        Schema::create('intake_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_id')->constrained('intake_bookings')->cascadeOnDelete();
            $table->string('section_key', 64);
            $table->string('field_key', 64);
            $table->string('name');
            $table->string('path');
            $table->string('mime');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_attachments');
        Schema::table('intake_answers', fn (Blueprint $table) => $table->dropForeign(['response_id']));
        Schema::create('intake_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('horse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('draft');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'horse_id']);
        });
        DB::statement('INSERT INTO intake_responses (id,user_id,horse_id,status,started_at,submitted_at,created_at,updated_at) SELECT id,user_id,horse_id,intake_status,started_at,submitted_at,created_at,updated_at FROM intake_bookings');
        Schema::table('intake_answers', fn (Blueprint $table) => $table->foreign('response_id')->references('id')->on('intake_responses')->cascadeOnDelete());
        Schema::table('intake_bookings', fn (Blueprint $table) => $table->dropColumn(['intake_status', 'started_at', 'submitted_at', 'accepted_triggers', 'review_notes', 'review_updated_at']));
        // Unscheduled intakes remain valid bookings; do not invent an appointment on rollback.
    }
};
