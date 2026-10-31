// Purchased protocols with their intake booking, for the intake cards on Home
// and Protocol. Two separate queries so each WHERE can use its index.
const SELECT = `
  SELECT p.id, p.title, p.status, p.horse_id, i.id AS booking_id, i.submitted_at, i.completed_at, h.name AS horse_name
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
};
