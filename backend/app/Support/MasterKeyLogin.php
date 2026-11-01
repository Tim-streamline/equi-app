<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class MasterKeyLogin
{
    public const SESSION_SECONDS = 3600;

    public const TOKEN_SECONDS = 300;

    public function fingerprint(): ?string
    {
        $hash = config('support.master_key_hash');
        if (! app()->environment(['local', 'testing', 'staging'])
            || ! config('support.master_key_enabled')
            || ! is_string($hash) || ! preg_match('/\A[a-f0-9]{64}\z/', $hash)) {
            return null;
        }

        return hash('sha256', $hash);
    }

    public function matches(string $password): bool
    {
        return $this->fingerprint() !== null && strlen($password) >= 32
            && hash_equals(config('support.master_key_hash'), hash('sha256', $password));
    }

    public function validFingerprint(mixed $fingerprint): bool
    {
        $current = $this->fingerprint();

        return $current !== null && is_string($fingerprint) && hash_equals($current, $fingerprint);
    }

    public function audit(Request $request, User $user): void
    {
        AuditLog::create([
            'actor_name' => 'Master-key support login',
            'action' => 'support_login',
            'target_type' => 'User',
            'target_id' => $user->id,
            'target_label' => $user->email,
            'after' => ['channel' => $request->is('web-session/*') ? 'web' : 'api'],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
