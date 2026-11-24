// Purchased protocols with their intake booking, for the intake cards on Home
// and Protocol. Two separate queries so each WHERE can use its index.
const SELECT = `
  SELECT p.id, p.title, p.status, p.horse_id, i.id AS booking_id, i.submitted_at, i.completed_at, h.name AS horse_name,
    EXISTS (SELECT 1 FROM intake_answers a WHERE a.response_id = i.id
      AND a.section_id != '__photos' AND a.field_id NOT LIKE 'na:%'
      AND a.value IS NOT NULL AND trim(a.value) NOT IN ('', 'null', '""', '[]', '{}')) AS has_answers,
    -- Same rule as the dashboard: a future publication date is not published yet.
    CASE WHEN p.published_at IS NOT NULL AND datetime(p.published_at) <= datetime('now') THEN 1 ELSE 0 END AS published,
    -- 1 when the Protocol tab shows exactly this plan for its horse, using the dashboard's order
    -- (Protocol::shownForHorses): an active plan first, then the latest published paused plan,
    -- with the lowest id first on equal publication times.
    -- Row values compare in that order: (not active, latest publication first, id).
    CASE WHEN p.published_at IS NOT NULL AND datetime(p.published_at) <= datetime('now') AND p.status IN ('active', 'paused')
      AND NOT EXISTS (SELECT 1 FROM protocols q WHERE q.horse_id = p.horse_id AND q.id != p.id AND q.status IN ('active', 'paused')
        AND q.published_at IS NOT NULL AND datetime(q.published_at) <= datetime('now')
        AND (q.status != 'active', -julianday(q.published_at), q.id) < (p.status != 'active', -julianday(p.published_at), p.id))
      THEN 1 ELSE 0 END AS shown_in_tab
  FROM protocols p JOIN intake_bookings i ON i.protocol_id = p.id LEFT JOIN horses h ON h.id = p.horse_id`;
const OPEN_FOR_USER = `
  AND i.user_id = ? AND p.status != 'archived' AND i.status != 'cancelled'
  ORDER BY p.created_at DESC, p.id DESC`;

/** Parameters: horse id, user id. */
export const PROTOCOLS_FOR_HORSE = `${SELECT}
  WHERE p.horse_id = ?${OPEN_FOR_USER}`;

/** Every active horse of the user. Parameters: user id. */
export const PROTOCOLS_FOR_ALL_HORSES = `${SELECT}
  WHERE h.status = 'active'${OPEN_FOR_USER}`;

export type PurchasedProtocolRow = {
  id: string; title: string; status: string; horse_id: string; booking_id: string;
  submitted_at: string | null; completed_at: string | null; horse_name: string | null;
  /** 1 once the therapist has published the plan (publication date reached). */
  published?: number | null;
  has_answers?: number | null;
  /** 1 when the Protocol tab displays this exact plan for its horse (see the query). */
  shown_in_tab?: number | null;
};
