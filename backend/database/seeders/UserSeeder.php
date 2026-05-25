<?php

namespace Database\Seeders;

use App\Models\AccountSetting;
use App\Models\DataExport;
use App\Models\NotificationPreference;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $plans = Plan::all()->keyBy('slug');

        // Anchor user matching the frontend.
        $marit = User::create([
            'name' => 'Shelley Meeuwsen',
            'email' => 'shelleymeeuwsen@gmail.com',
            'password' => Hash::make('password'),
            'avatar_initial' => 'SM',
            'locale' => 'nl-NL',
            'units_system' => 'metric',
            'notifications_on' => true,
            'onboarded_at' => now()->subWeeks(3),
            'created_at' => now()->subWeeks(7),
        ]);
        $this->bootstrapUser($marit, $plans['plus']);

        // 24 random users.
        for ($i = 0; $i < 24; $i++) {
            $name = fake()->firstName() . ' ' . fake()->lastName();
            $user = User::create([
                'name' => $name,
                'email' => fake()->unique()->safeEmail(),
                'password' => Hash::make('password'),
                'avatar_initial' => mb_strtoupper(mb_substr($name, 0, 1)),
                'locale' => 'nl-NL',
                'units_system' => 'metric',
                'notifications_on' => fake()->boolean(80),
                'onboarded_at' => fake()->boolean(85) ? fake()->dateTimeBetween('-6 months', '-1 week') : null,
                'created_at' => fake()->dateTimeBetween('-12 months', '-1 week'),
            ]);
            $planSlug = fake()->randomElement(['free', 'free', 'plus', 'plus', 'plus', 'bundle']);
            $this->bootstrapUser($user, $plans[$planSlug]);
        }
    }

    private function bootstrapUser(User $user, Plan $plan): void
    {
        NotificationPreference::create([
            'user_id' => $user->id,
            'reminder_protocol' => fake()->boolean(85),
            'reminder_community' => fake()->boolean(60),
            'reminder_seasonal_tips' => fake()->boolean(70),
            'active_reminders_count' => fake()->numberBetween(0, 5),
        ]);

        foreach ([
            ['bell', 'Meldingen', '3 reminders aan', ''],
            ['messageCircle', 'Community', 'Vraag & deel met paardenmensen', '/(tabs)/account/community'],
            ['download', 'Exporteer mijn data', 'CSV of PDF dagboek', ''],
            ['settings', 'Voorkeuren', 'Eenheden, taal', ''],
            ['heart', 'Steun De Paardentherapeut', '', ''],
        ] as $i => [$icon, $title, $sub, $route]) {
            AccountSetting::create([
                'user_id' => $user->id,
                'icon_key' => $icon,
                'title' => $title,
                'subtitle' => $sub,
                'route' => $route,
                'order' => $i,
            ]);
        }

        if ($plan->slug === 'free') return;

        $started = fake()->dateTimeBetween('-12 months', '-2 weeks');
        $sub = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'price_cents' => $plan->price_cents,
            'currency' => $plan->currency,
            'interval' => $plan->interval,
            'started_at' => $started,
            'started_label' => 'Sinds ' . $this->dutchMonth($started) . ' ' . $started->format('Y'),
            'renews_at' => $plan->interval === 'monthly' ? now()->addDays(fake()->numberBetween(1, 28)) : null,
            'renews_label' => $plan->interval === 'monthly' ? 'Verlengt ' . now()->addDays(7)->format('j') . ' ' . $this->dutchMonth(now()->addDays(7)) : null,
            'max_horses' => $plan->slug === 'plus' ? 3 : 5,
        ]);

        if ($plan->interval === 'monthly') {
            $monthsBack = max(1, $started->diff(now())->m + ($started->diff(now())->y * 12));
            for ($m = 0; $m < min(12, $monthsBack); $m++) {
                $date = now()->subMonths($m)->subDays(2);
                Payment::create([
                    'subscription_id' => $sub->id,
                    'date' => $date,
                    'date_label' => $date->format('j') . ' ' . $this->dutchMonth($date, true) . ' ' . $date->format('Y'),
                    'amount_cents' => $plan->price_cents,
                    'amount_label' => '€ ' . number_format($plan->price_cents / 100, 2, ',', ''),
                    'status' => 'paid',
                    'order' => $m,
                ]);
            }
        } else {
            Payment::create([
                'subscription_id' => $sub->id,
                'date' => $started,
                'date_label' => $started->format('j') . ' ' . $this->dutchMonth($started, true) . ' ' . $started->format('Y'),
                'amount_cents' => $plan->price_cents,
                'amount_label' => '€ ' . number_format($plan->price_cents / 100, 2, ',', ''),
                'status' => 'paid',
                'order' => 0,
            ]);
        }

        if (fake()->boolean(20)) {
            DataExport::create([
                'user_id' => $user->id,
                'format' => fake()->randomElement(['csv', 'pdf']),
                'requested_at' => fake()->dateTimeBetween('-3 months', 'now'),
                'completed_at' => fake()->boolean(70) ? fake()->dateTimeBetween('-3 months', 'now') : null,
            ]);
        }
    }

    private function dutchMonth(\DateTimeInterface $dt, bool $short = false): string
    {
        $long = ['januari','februari','maart','april','mei','juni','juli','augustus','september','oktober','november','december'];
        $shortL = ['jan','feb','mrt','apr','mei','jun','jul','aug','sep','okt','nov','dec'];
        $idx = (int) $dt->format('n') - 1;
        return ($short ? $shortL : $long)[$idx];
    }
}
