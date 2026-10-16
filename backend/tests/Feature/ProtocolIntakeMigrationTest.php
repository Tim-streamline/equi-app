<?php

namespace Tests\Feature;

use App\Models\Horse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProtocolIntakeMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_pair_is_linked_and_intake_only_history_gets_a_draft(): void
    {
        $enforce = require database_path('migrations/2026_10_14_000002_enforce_protocol_intake_invariants.php');
        $link = require database_path('migrations/2026_10_14_000001_link_intakes_to_protocols.php');
        $enforce->down();
        $link->down();
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Legacy']);
        $other = Horse::create(['owner_id' => $user->id, 'name' => 'Intake only']);
        $protocolId = (string) Str::uuid();
        $intakeId = (string) Str::uuid();
        $otherId = (string) Str::uuid();
        DB::table('protocols')->insert(['id' => $protocolId, 'horse_id' => $horse->id, 'title' => 'History', 'status' => 'completed']);
        foreach ([[$intakeId, $horse->id], [$otherId, $other->id]] as [$id, $horseId]) {
            DB::table('intake_bookings')->insert(['id' => $id, 'horse_id' => $horseId, 'user_id' => $user->id]);
        }
        DB::table('intake_answers')->insert(['id' => (string) Str::uuid(), 'response_id' => $intakeId, 'section_id' => 'paard', 'field_id' => 'gewicht', 'value' => '540']);
        $link->up();
        $enforce->up();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertDatabaseHas('intake_bookings', ['id' => $intakeId, 'protocol_id' => $protocolId]);
        $this->assertDatabaseHas('intake_answers', ['response_id' => $intakeId, 'value' => '540']);
        $this->assertDatabaseHas('protocols', ['id' => DB::table('intake_bookings')->where('id', $otherId)->value('protocol_id'), 'horse_id' => $other->id, 'status' => 'draft']);
    }

    public function test_ambiguous_history_stops_enforcement_without_choosing_or_deleting_records(): void
    {
        $enforce = require database_path('migrations/2026_10_14_000002_enforce_protocol_intake_invariants.php');
        $link = require database_path('migrations/2026_10_14_000001_link_intakes_to_protocols.php');
        $enforce->down();
        $link->down();
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Ambiguous']);
        foreach (range(1, 2) as $n) {
            DB::table('protocols')->insert(['id' => (string) Str::uuid(), 'horse_id' => $horse->id, 'title' => 'History '.$n, 'status' => 'active']);
        }
        DB::table('intake_bookings')->insert(['id' => (string) Str::uuid(), 'horse_id' => $horse->id, 'user_id' => $user->id]);
        $link->up();
        try {
            $enforce->up();
            $this->fail('Expected reviewed legacy repair requirement');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('horses_with_multiple_active_protocols', $e->getMessage());
            $this->assertStringContainsString('protocols_without_intake', $e->getMessage());
        }
        $this->assertDatabaseCount('protocols', 2);
        $this->assertSame(1, DB::table('intake_bookings')->whereNull('protocol_id')->count());
    }

    public function test_staging_inventory_is_reconciled_without_changing_existing_content_or_entitlements(): void
    {
        [$link, $enforce] = $this->legacySchema();
        $this->novaFixture();
        $owner = User::findOrFail('019ff161-da01-72fa-b1f2-c9e587ec8d5f');
        $r = Horse::create(['owner_id' => $owner->id, 'name' => 'R']);
        $rProtocol = (string) Str::uuid();
        DB::table('protocols')->insert(['id' => $rProtocol, 'horse_id' => $r->id, 'title' => 'R', 'status' => 'paused']);
        $rBooking = (string) Str::uuid();
        DB::table('intake_bookings')->insert(['id' => $rBooking, 'horse_id' => $r->id, 'user_id' => $owner->id,
            'intake_status' => 'submitted', 'submitted_at' => '2026-09-29 22:25:45', 'created_at' => '2026-09-29 20:15:28']);
        DB::table('intake_answers')->insert(['id' => (string) Str::uuid(), 'response_id' => $rBooking, 'section_id' => 'paard', 'field_id' => 'gewicht', 'value' => '540']);
        DB::table('intake_attachments')->insert(['id' => (string) Str::uuid(), 'booking_id' => $rBooking,
            'section_key' => 'photos', 'field_key' => 'photo', 'name' => 'photo.jpg', 'path' => 'intakes/existing.jpg', 'mime' => 'image/jpeg']);
        DB::table('plus_intake_entitlements')->insert(['user_id' => $owner->id, 'horse_id' => $r->id, 'booking_id' => $rBooking]);
        foreach (range(1, 7) as $n) {
            $user = User::factory()->create();
            $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Intake '.$n]);
            DB::table('intake_bookings')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'horse_id' => $horse->id]);
        }
        $before = $this->contentSnapshot();
        $link->up();
        $enforce->up();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertDatabaseCount('protocols', 10);
        $this->assertDatabaseCount('intake_bookings', 10);
        $this->assertSame(7, DB::table('protocols')->where('status', 'draft')->count());
        $this->assertDatabaseHas('protocols', ['id' => '01a0639e-e9d5-7179-af49-2c2b8ac6b73c', 'status' => 'archived', 'archived_previous_status' => 'active']);
        $this->assertDatabaseHas('protocols', ['id' => '01a07784-f09e-7322-a90c-69ea51f17864', 'status' => 'active']);
        $this->assertDatabaseHas('intake_bookings', ['id' => '019ff162-1f74-7079-bc9d-b1e04a183b48', 'protocol_id' => '01a0639e-e9d5-7179-af49-2c2b8ac6b73c', 'status' => 'cancelled']);
        $this->assertDatabaseHas('intake_bookings', ['id' => $rBooking, 'protocol_id' => $rProtocol]);
        $newIntake = DB::table('intake_bookings')->where('protocol_id', '01a07784-f09e-7322-a90c-69ea51f17864')->first();
        $this->assertSame('draft', $newIntake->intake_status);
        $this->assertNull($newIntake->submitted_at);
        $this->assertSame(0, DB::table('intake_answers')->where('response_id', $newIntake->id)->count());
        $this->assertSame($before, $this->contentSnapshot(array_keys($before['intake_bookings'])));
        $this->assertSame(18, DB::table('audit_logs')->where('action', 'protocol_intake_migrated')->count());
        $this->artisan('protocols:audit-intakes')->assertSuccessful();

        // Retrying after removing only enforcement must not create duplicate history.
        $enforce->down();
        $enforce->up();
        $this->assertDatabaseCount('protocols', 10);
        $this->assertDatabaseCount('intake_bookings', 10);
        $this->assertSame(18, DB::table('audit_logs')->where('action', 'protocol_intake_migrated')->count());
    }

    public function test_changed_reviewed_intake_aborts_without_archiving_or_linking(): void
    {
        [$link, $enforce] = $this->legacySchema();
        $this->novaFixture();
        DB::table('intake_answers')->insert(['id' => (string) Str::uuid(), 'response_id' => '019ff162-1f74-7079-bc9d-b1e04a183b48',
            'section_id' => 'paard', 'field_id' => 'gewicht', 'value' => '540']);
        $link->up();
        try {
            $enforce->up();
            $this->fail('Changed history must require review');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Reviewed Nova protocol/intake history has changed', $e->getMessage());
        }
        $this->assertSame(2, DB::table('protocols')->where('status', 'active')->count());
        $this->assertSame(1, DB::table('intake_bookings')->whereNull('protocol_id')->count());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_unexpected_conflict_rolls_back_all_repairs_and_allows_a_retry(): void
    {
        [$link, $enforce] = $this->legacySchema();
        $this->novaFixture();
        $user = User::factory()->create();
        // A deleted legacy horse cannot safely be inferred from its intake.
        $orphan = (string) Str::uuid();
        DB::table('intake_bookings')->insert(['id' => $orphan, 'user_id' => $user->id]);
        $link->up();
        try {
            $enforce->up();
            $this->fail('Orphan intake should block the entire repair');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('intakes_without_horse', $e->getMessage());
        }
        $this->assertSame(2, DB::table('protocols')->where('status', 'active')->count());
        $this->assertDatabaseCount('intake_bookings', 2);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame(2, DB::table('intake_bookings')->whereNull('protocol_id')->count());
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Explicitly repaired']);
        DB::table('intake_bookings')->where('id', $orphan)->update(['horse_id' => $horse->id]);
        $enforce->up();
        $this->artisan('protocols:audit-intakes')->assertSuccessful();
    }

    public function test_protocol_only_and_cancelled_intake_only_history_is_preserved(): void
    {
        [$link, $enforce] = $this->legacySchema();
        $user = User::factory()->create();
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Historical protocols']);
        foreach (['completed', 'completed', 'active'] as $status) {
            DB::table('protocols')->insert(['id' => (string) Str::uuid(), 'horse_id' => $horse->id, 'title' => 'History', 'status' => $status]);
        }
        $other = Horse::create(['owner_id' => $user->id, 'name' => 'Cancelled intake']);
        $booking = (string) Str::uuid();
        DB::table('intake_bookings')->insert(['id' => $booking, 'horse_id' => $other->id, 'user_id' => $user->id, 'status' => 'cancelled']);
        $link->up();
        $enforce->up();
        $this->assertDatabaseCount('protocols', 4);
        $this->assertDatabaseCount('intake_bookings', 4);
        $this->assertSame(2, DB::table('protocols')->where('status', 'completed')->count());
        $this->assertDatabaseHas('protocols', ['horse_id' => $other->id, 'status' => 'archived']);
        $this->assertDatabaseCount('intake_answers', 0);
        $this->assertDatabaseCount('plus_intake_entitlements', 0);
        $this->artisan('protocols:audit-intakes')->assertSuccessful();
    }

    private function legacySchema(): array
    {
        $enforce = require database_path('migrations/2026_10_14_000002_enforce_protocol_intake_invariants.php');
        $link = require database_path('migrations/2026_10_14_000001_link_intakes_to_protocols.php');
        $enforce->down();
        $link->down();

        return [$link, $enforce];
    }

    private function novaFixture(): void
    {
        $user = User::factory()->create(['id' => '019ff161-da01-72fa-b1f2-c9e587ec8d5f']);
        $horse = Horse::forceCreate(['id' => '019ff161-ea6a-70b7-9a47-b69dafe74975', 'owner_id' => $user->id, 'name' => 'Nova']);
        foreach (['01a0639e-e9d5-7179-af49-2c2b8ac6b73c', '01a07784-f09e-7322-a90c-69ea51f17864'] as $id) {
            DB::table('protocols')->insert(['id' => $id, 'horse_id' => $horse->id, 'title' => 'Nova', 'status' => 'active']);
        }
        DB::table('intake_bookings')->insert(['id' => '019ff162-1f74-7079-bc9d-b1e04a183b48', 'horse_id' => $horse->id,
            'user_id' => $user->id, 'status' => 'cancelled', 'intake_status' => 'draft']);
    }

    private function contentSnapshot(?array $bookingIds = null): array
    {
        $bookings = DB::table('intake_bookings')->when($bookingIds, fn ($q) => $q->whereIn('id', $bookingIds))->orderBy('id')->get()->keyBy('id')->map(function ($row) {
            $values = (array) $row;
            unset($values['protocol_id']);

            return $values;
        })->all();

        return ['intake_bookings' => $bookings, 'intake_answers' => DB::table('intake_answers')->orderBy('id')->get()->toJson(),
            'intake_attachments' => DB::table('intake_attachments')->orderBy('id')->get()->toJson(),
            'plus_intake_entitlements' => DB::table('plus_intake_entitlements')->orderBy('user_id')->get()->toJson()];
    }
}
