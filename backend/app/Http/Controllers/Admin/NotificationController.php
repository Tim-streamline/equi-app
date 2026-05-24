<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Notification/messaging overview. Campaign sending and delivery tracking
 * need dedicated `notification_templates` / `notification_campaigns` tables
 * (see the spec); this surface reports on opt-in segments today.
 */
class NotificationController extends Controller
{
    public function index(): Response
    {
        $total = User::count();
        $optedIn = User::where('notifications_on', true)->count();

        return Inertia::render('Notifications/Index', [
            'segments' => [
                'total_users' => $total,
                'opted_in' => $optedIn,
                'opted_out' => $total - $optedIn,
                'push_tokens' => NotificationPreference::whereNotNull('push_token')->count(),
            ],
            'channels' => [
                ['name' => 'Protocol reminders', 'optedIn' => NotificationPreference::where('reminder_protocol', true)->count()],
                ['name' => 'Community replies', 'optedIn' => NotificationPreference::where('reminder_community', true)->count()],
                ['name' => 'Seasonal tips', 'optedIn' => NotificationPreference::where('reminder_seasonal_tips', true)->count()],
            ],
        ]);
    }
}
