<?php

namespace Tests\Feature;

use App\Http\Controllers\IntakeActivityController;
use App\Models\Horse;
use App\Models\IntakeBooking;
use App\Models\IntakeQuestionnaire;
use App\Models\Subscription;
use App\Models\User;
use App\Support\IntakeProgress;
use App\Support\IntakePush;
use App\Support\IntakeReminders;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IntakeRemindersTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\HasSubscription;

    public function test_resume_resolves_photo_metadata_and_recomputes_the_current_missing_question(): void
    {
        $request = Request::create('/');
        $request->attributes->set('powersync_user_id', $this->booking->user_id);
        $controller = app(IntakeActivityController::class);
        $progress = app(IntakeProgress::class);
        $this->booking->forceFill(['last_section' => '__photos', 'last_field' => 'weide-na'])->save();
        $result = $controller->resume($request, $this->booking->fresh(), $progress)->getData(true);
        $this->assertSame('intake-fotos', $result['section']);
        $this->assertNull($result['field']);
        $this->booking->forceFill(['last_section' => 'photos', 'last_field' => 'weide-na'])->save();
        $result = $controller->resume($request, $this->booking->fresh(), $progress)->getData(true);
        $this->assertSame('intake-fotos', $result['section']);
        $this->assertNull($result['field']);
        $this->answer(9);
        $result = $controller->resume($request, $this->booking->fresh(), $progress)->getData(true);
        $this->assertSame('almost', $result['stage']);
        $this->assertSame('q9', $result['field']);
        $this->answer(10);
        $this->assertSame('ready', $controller->resume($request, $this->booking->fresh(), $progress)->getData(true)['stage']);
        $this->booking->update(['submitted_at' => now()]);
        $this->assertTrue($controller->resume($request, $this->booking->fresh(), $progress)->getData(true)['submitted']);
        $request->attributes->set('powersync_user_id', User::factory()->create()->id);
        $this->expectException(HttpException::class);
        $controller->resume($request, $this->booking->fresh(), $progress);
    }

    private IntakeBooking $booking;

    private array $messages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $user = User::factory()->create(['notifications_on' => true]);
        $user->notificationPreferences()->create(['reminder_protocol' => true]);
        $this->subscribe($user);
        $horse = Horse::create(['owner_id' => $user->id, 'name' => 'Nova']);
        $this->booking = IntakeBooking::create(['user_id' => $user->id, 'horse_id' => $horse->id, 'started_at' => now()->subDays(3), 'status' => 'pending', 'intake_status' => 'draft']);
        $q = IntakeQuestionnaire::create(['slug' => 'protocol-intake', 'name' => 'Intake', 'active' => true]);
        $section = $q->sections()->create(['key' => 'paard', 'title' => 'Paard', 'active' => true]);
        for ($i = 0; $i < 10; $i++) {
            $section->fields()->create(['key' => 'q'.$i, 'label' => 'Question '.$i, 'type' => 'text', 'optional' => false, 'active' => true]);
        }
        $section->fields()->create(['key' => 'hidden', 'label' => 'Hidden', 'type' => 'text', 'show_if' => ['q0' => 'show'], 'active' => true]);
        $section->fields()->create(['key' => 'optional', 'label' => 'Optional', 'type' => 'text', 'optional' => true, 'active' => true]);
        $this->mock(IntakePush::class, function ($mock) {
            $mock->shouldReceive('destinations')->andReturn(['browser' => ['type' => 'web'], 'phone' => ['type' => 'native']]);
            $mock->shouldReceive('send')->andReturnUsing(function ($user, $destination, $payload) {
                $this->messages[] = $payload;

                return true;
            });
        });
    }

    private function answer(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->booking->answers()->updateOrCreate(['section_id' => 'paard', 'field_id' => 'q'.$i], ['value' => json_encode('answer')]);
        }
        $this->booking->unsetRelation('answers');
        $this->booking->forceFill(['last_activity_at' => now()->subDays(3)])->save();
    }

    public function test_exact_thresholds_and_conditionals_match_the_frontend(): void
    {
        $this->answer(8);
        $progress = app(IntakeProgress::class);
        $this->assertSame('continue', $progress->forBooking($this->booking)['stage']);
        $this->answer(9);
        $this->assertSame('almost', $progress->forBooking($this->booking)['stage']);
        $this->answer(10);
        $this->assertSame('ready', $progress->forBooking($this->booking)['stage']);
        $this->booking->answers()->where('field_id', 'q0')->update(['value' => json_encode('show')]);
        $this->booking->unsetRelation('answers');
        $this->assertSame('almost', $progress->forBooking($this->booking)['stage']);
        $this->assertSame(11, $progress->forBooking($this->booking)['total']);
    }

    public function test_three_day_inactivity_repeats_and_stops_immediately_on_submission(): void
    {
        $this->answer(9);
        $sender = app(IntakeReminders::class);
        $this->assertSame(2, $sender->send($this->booking, CarbonImmutable::now()));
        $this->assertSame('Je bent bijna klaar met je intake ✨', $this->messages[0]['title']);
        $this->assertSame(0, $sender->send($this->booking, CarbonImmutable::now()));
        $this->travel(2)->days();
        $this->assertSame(0, $sender->send($this->booking, CarbonImmutable::now()));
        $this->booking->forceFill(['last_activity_at' => now()])->save();
        $this->travel(1)->days();
        $this->assertSame(0, $sender->send($this->booking, CarbonImmutable::now()));
        $this->travel(2)->days();
        $this->assertSame(2, $sender->send($this->booking, CarbonImmutable::now()));
        $this->booking->update(['submitted_at' => now(), 'intake_status' => 'submitted']);
        $this->travel(4)->days();
        $this->assertSame(0, $sender->send($this->booking, CarbonImmutable::now()));
        $this->assertDatabaseCount('intake_reminder_deliveries', 4);
    }

    public function test_ready_message_and_cancellation_preferences_and_plus_gate(): void
    {
        $this->answer(10);
        $sender = app(IntakeReminders::class);
        $this->booking->update(['status' => 'cancelled']);
        $this->assertSame(0, $sender->send($this->booking, CarbonImmutable::now()));
        $this->booking->update(['status' => 'pending']);
        $this->booking->user->update(['notifications_on' => false]);
        $this->assertSame(0, $sender->send($this->booking, CarbonImmutable::now()));
        $this->booking->user->update(['notifications_on' => true]);
        Subscription::where('user_id', $this->booking->user_id)->update(['status' => 'cancelled']);
        $this->assertSame(0, $sender->send($this->booking, CarbonImmutable::now()));
        Subscription::where('user_id', $this->booking->user_id)->update(['status' => 'active']);
        $this->assertSame(2, $sender->send($this->booking, CarbonImmutable::now()));
        $this->assertSame('Je intake is klaar om te versturen 🎉', $this->messages[0]['title']);
    }
}
