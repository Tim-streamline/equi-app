<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Thin helper that records admin mutations into `audit_logs`. Every admin
 * controller write should funnel through here so the trail is complete:
 * who, what, before/after, and request context.
 */
class AuditLogger
{
    public static function log(
        string $action,
        ?Model $target = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?string $label = null,
    ): void {
        $admin = Auth::guard('admin')->user();

        AuditLog::create([
            'admin_user_id' => $admin?->id,
            'actor_name' => $admin?->name,
            'action' => $action,
            'target_type' => $target ? class_basename($target) : null,
            'target_id' => $target?->getKey(),
            'target_label' => $label ?? self::label($target),
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 255),
        ]);
    }

    /** Record a model creation, capturing its attributes as the "after" state. */
    public static function created(Model $model, ?string $reason = null): void
    {
        self::log('created', $model, null, $model->getAttributes(), $reason);
    }

    /** Record a model update with its dirty before/after slices. */
    public static function updated(Model $model, array $before, ?string $reason = null): void
    {
        $after = array_intersect_key($model->getAttributes(), $before);
        self::log('updated', $model, $before, $after, $reason);
    }

    /** Record a model deletion, snapshotting its final state. */
    public static function deleted(Model $model, ?string $reason = null): void
    {
        self::log('deleted', $model, $model->getAttributes(), null, $reason);
    }

    private static function label(?Model $model): ?string
    {
        if (! $model) {
            return null;
        }

        foreach (['name', 'title', 'label', 'email', 'slug'] as $attr) {
            if (! empty($model->{$attr})) {
                return (string) $model->{$attr};
            }
        }

        return $model->getKey();
    }
}
