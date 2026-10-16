-- The projection is maintained in the same transaction as its source records,
-- including raw SQL imports, credit purchases and bulk subscription updates.
CREATE OR REPLACE FUNCTION refresh_library_access(at_time timestamp DEFAULT timezone('UTC', statement_timestamp())) RETURNS void
LANGUAGE plpgsql AS $$
BEGIN
    PERFORM pg_advisory_xact_lock(724119301);
    WITH plus_access AS (
        SELECT s.user_id,
               bool_or(s.paid_through IS NULL AND s.cancelled_at IS NULL AND s.ended_at IS NULL) AS unlimited,
               max(least(s.paid_through, s.cancelled_at::timestamp, s.ended_at)) AS expires_at
        FROM subscriptions s JOIN plans p ON p.id = s.plan_id
        WHERE p.slug <> 'free' AND s.status = 'active' AND (s.ended_at IS NULL OR s.ended_at > at_time)
          AND (s.started_at IS NULL OR s.started_at <= at_time::date)
          AND (s.paid_through IS NULL OR s.paid_through > at_time)
          AND (s.cancelled_at IS NULL OR s.cancelled_at > at_time::date)
        GROUP BY s.user_id
    ), allowed AS (
        SELECT u.id AS user_id, i.id AS item_id,
               CASE WHEN unlock.item_id IS NOT NULL THEN 'unlocked'
                    WHEN NOT i.is_plus AND i.credit_cost = 0 THEN 'free'
                    ELSE 'plus' END AS reason,
               CASE WHEN plus.unlimited
                    THEN NULL ELSE plus.expires_at END AS expires_at
        FROM users u CROSS JOIN library_items i
        LEFT JOIN library_unlocks unlock ON unlock.user_id = u.id AND unlock.item_id = i.id
        JOIN plus_access plus ON plus.user_id = u.id
        WHERE u.disabled_at IS NULL AND i.published_at <= at_time
          AND (unlock.item_id IS NOT NULL OR (NOT i.is_plus AND i.credit_cost = 0)
               OR (i.is_plus AND plus.user_id IS NOT NULL))
    ), upserted AS (
        INSERT INTO library_item_access (id, user_id, item_id, reason, expires_at, updated_at)
        SELECT md5(user_id::text || ':' || item_id::text)::uuid, user_id, item_id, reason, expires_at, at_time FROM allowed
        ON CONFLICT (user_id, item_id) DO UPDATE
          SET reason = excluded.reason, expires_at = excluded.expires_at, updated_at = excluded.updated_at
          WHERE (library_item_access.reason, library_item_access.expires_at)
                IS DISTINCT FROM (excluded.reason, excluded.expires_at)
        RETURNING id
    )
    DELETE FROM library_item_access a
    WHERE NOT EXISTS (SELECT 1 FROM allowed WHERE user_id = a.user_id AND item_id = a.item_id);
END;
$$;

