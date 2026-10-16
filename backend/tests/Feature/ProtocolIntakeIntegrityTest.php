<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\User;
use App\Support\ProtocolNutrition;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProtocolIntakeIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function horse(): Horse
    {
        return Horse::create(['owner_id' => User::factory()->create()->id, 'name' => 'Nova']);
    }

    public function test_starting_an_intake_atomically_creates_its_own_draft_protocol(): void
    {
        $horse = $this->horse();
        $intake = IntakeBooking::create(['user_id' => $horse->owner_id, 'horse_id' => $horse->id]);
        $this->assertSame('draft', $intake->protocol->status);
        $this->assertSame($horse->id, $intake->protocol->horse_id);
        $this->assertSame($intake->id, $intake->protocol->intake->id);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_protocol_history_keeps_separate_intakes_and_nutrition_answers(): void
    {
        $horse = $this->horse();
        $old = $horse->protocols()->create(['title' => 'Finished', 'status' => 'completed']);
        $current = $horse->protocols()->create(['title' => 'Current', 'status' => 'active']);
        foreach ([[$old, 420], [$current, 560]] as [$protocol, $weight]) {
            $protocol->intake->update(['submitted_at' => now()]);
            $protocol->intake->answers()->create(['section_id' => 'paard', 'field_id' => 'gewicht', 'value' => json_encode($weight)]);
        }
        $this->assertNotSame($old->intake->id, $current->intake->id);
        $this->assertSame(420, app(ProtocolNutrition::class)->answers($old)['gewicht']);
        $this->assertSame(560, app(ProtocolNutrition::class)->answers($current)['gewicht']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_model_rejects_a_second_active_protocol_without_leaving_an_intake(): void
    {
        $horse = $this->horse();
        $horse->protocols()->create(['title' => 'Current', 'status' => 'active']);
        try {
            $horse->protocols()->create(['title' => 'Conflict', 'status' => 'active']);
            $this->fail('Expected active conflict');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
        $this->assertDatabaseCount('protocols', 1);
        $this->assertDatabaseCount('intake_bookings', 1);
    }

    public function test_database_also_rejects_bypassing_the_active_protocol_guard(): void
    {
        $horse = $this->horse();
        $horse->protocols()->create(['title' => 'Current', 'status' => 'active']);
        $paused = $horse->protocols()->create(['title' => 'Paused', 'status' => 'paused']);
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('protocols')->where('id', $paused->id)->update(['status' => 'active']));
    }

    public function test_database_rejects_a_second_intake_and_a_different_horse(): void
    {
        $horse = $this->horse();
        $protocol = $horse->protocols()->create(['title' => 'Current', 'status' => 'active']);
        $other = $this->horse();
        foreach ([['protocol_id' => $protocol->id, 'horse_id' => $horse->id], ['protocol_id' => $protocol->id, 'horse_id' => $other->id]] as $attributes) {
            try {
                DB::transaction(fn () => DB::table('intake_bookings')->insert($attributes + ['id' => (string) Str::uuid(), 'user_id' => $horse->owner_id]));
                $this->fail('Expected database constraint');
            } catch (QueryException $e) {
                $this->assertStringContainsString('violates', $e->getMessage());
            }
        }
    }

    public function test_database_rejects_an_intake_horse_that_differs_from_its_protocol(): void
    {
        $horse = $this->horse();
        $protocol = $horse->protocols()->create(['title' => 'Current', 'status' => 'active']);
        $other = $this->horse();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('intake_bookings')->where('protocol_id', $protocol->id)->update(['horse_id' => $other->id]));
    }

    public function test_database_rejects_leaving_a_protocol_without_its_intake(): void
    {
        $horse = $this->horse();
        $protocol = $horse->protocols()->create(['title' => 'Current', 'status' => 'active']);
        $this->expectException(QueryException::class);
        DB::transaction(function () use ($protocol) {
            DB::table('intake_bookings')->where('protocol_id', $protocol->id)->delete();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }

    public function test_restore_conflict_keeps_both_protocols_unchanged(): void
    {
        $this->actingAs(AdminUser::create(['name' => 'Admin', 'email' => 'integrity@test.example', 'password' => 'password', 'role' => 'admin', 'active' => true]), 'admin');
        $horse = $this->horse();
        $active = $horse->protocols()->create(['title' => 'Current', 'status' => 'active']);
        $archived = $horse->protocols()->create(['title' => 'Old', 'status' => 'archived', 'archived_previous_status' => 'active']);
        $this->post('/admin/protocols/bulk', ['action' => 'restore', 'ids' => [$archived->id]])->assertSessionHasErrors('status');
        $this->assertSame('active', $active->fresh()->status);
        $this->assertSame('archived', $archived->fresh()->status);
    }

    public function test_deleting_a_protocol_cascades_its_intake_but_preserves_other_history(): void
    {
        $horse = $this->horse();
        $old = $horse->protocols()->create(['title' => 'Finished', 'status' => 'completed']);
        $current = $horse->protocols()->create(['title' => 'Current', 'status' => 'active']);
        $oldId = $old->intake->id;
        $old->delete();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertDatabaseMissing('intake_bookings', ['id' => $oldId]);
        $this->assertDatabaseHas('intake_bookings', ['id' => $current->intake->id]);
    }
}
