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

    /** JWT claims for the capabilities in a token request; unknown values are ignored. */
    public static function claims(Request $request): array
    {
        $requested = $request->input('capabilities');

        return is_array($requested) && in_array(self::PROTOCOL_DOSES, $requested, true)
            ? [self::PROTOCOL_DOSES_CLAIM => 1] : [];
    }

    /** Whether the authenticated app tracks protocol doses separately. */
    public static function protocolDoses(Request $request): bool
    {
        $claims = $request->attributes->get('powersync_claims', []);

        return (int) ($claims[self::PROTOCOL_DOSES_CLAIM] ?? 0) === 1;
    }
}
