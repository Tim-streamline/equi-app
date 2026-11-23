<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Features an app version declares when it requests a PowerSync token.
 *
 * Released app versions never send capabilities, so an absent claim must keep
 * their original behaviour. The claim is signed into the JWT, which makes the
 * same declaration visible to the PowerSync sync config and to the HTTP API.
 */
class ClientCapabilities
{
    /** Separate morning/evening checkmarks instead of one checkbox per product per day. */
    public const PROTOCOL_DOSES = 'protocol-doses';

    public const PROTOCOL_DOSES_CLAIM = 'protocol_doses';

    /** Shows a paused or completed plan read-only; older apps would offer its checkboxes. */
    public const READ_ONLY_PLANS = 'read-only-plans';

    public const READ_ONLY_PLANS_CLAIM = 'read_only_plans';

    private const CLAIMS = [self::PROTOCOL_DOSES => self::PROTOCOL_DOSES_CLAIM, self::READ_ONLY_PLANS => self::READ_ONLY_PLANS_CLAIM];

    /** JWT claims for the capabilities in a token request; unknown values are ignored. */
    public static function claims(Request $request): array
    {
        $requested = $request->input('capabilities');
        $claims = [];
        foreach (self::CLAIMS as $capability => $claim) {
            if (is_array($requested) && in_array($capability, $requested, true)) {
                $claims[$claim] = 1;
            }
        }

        return $claims;
    }

    /** Whether the authenticated app tracks protocol doses separately. */
    public static function protocolDoses(Request $request): bool
    {
        $claims = $request->attributes->get('powersync_claims', []);

        return (int) ($claims[self::PROTOCOL_DOSES_CLAIM] ?? 0) === 1;
    }

    /** Whether the authenticated app keeps paused and completed plans read-only. */
    public static function readOnlyPlans(Request $request): bool
    {
        $claims = $request->attributes->get('powersync_claims', []);

        return (int) ($claims[self::READ_ONLY_PLANS_CLAIM] ?? 0) === 1;
    }
}
