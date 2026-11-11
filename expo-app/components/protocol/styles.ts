/**
 * The Protocol tab's text scale, matching Home and the other tabs (bold titles,
 * nothing smaller than 12px). Cards and eyebrows come from `components/ui/styles`.
 *
 * PROTOCOL_SCALE is weight, size and line height only: use it with a colour of
 * your own (`${S.section} text-teal-700`). PROTOCOL_TEXT is the same scale in
 * its default colour. Never add a second colour to a PROTOCOL_TEXT class:
 * NativeWind picks between conflicting classes by stylesheet order, not by
 * their order in className.
 */
export const PROTOCOL_SCALE = {
  /** Top-of-tab and sheet titles. */
  title: 'font-bold text-[20px] leading-[26px]',
  /** Title of a card. */
  cardTitle: 'font-semi text-[17px] leading-[23px]',
  /** Section heading and list-row title. */
  section: 'font-semi text-[16px] leading-[22px]',
  /** Introductory paragraphs. */
  lead: 'font-sans text-[16px] leading-[24px]',
  /** Long-form explanations. */
  body: 'font-sans text-[15px] leading-[24px]',
  /** Name in a compact name/value row. */
  rowTitle: 'font-semi text-[15px] leading-[22px]',
  /** Value in a compact name/value row. */
  row: 'font-sans text-[15px] leading-[22px]',
  /** Short supporting text. */
  secondary: 'font-sans text-[14px] leading-[21px]',
  /** Small heading, status or inline action. */
  label: 'font-semi text-[14px] leading-[20px]',
  /** Counts, dates and other metadata. */
  meta: 'font-sans text-[13px] leading-[20px]',
} as const;

export const PROTOCOL_TEXT = {
  title: `${PROTOCOL_SCALE.title} text-ink`,
  cardTitle: `${PROTOCOL_SCALE.cardTitle} text-ink`,
  section: `${PROTOCOL_SCALE.section} text-ink`,
  lead: `${PROTOCOL_SCALE.lead} text-ink-70`,
  body: `${PROTOCOL_SCALE.body} text-ink-70`,
  rowTitle: `${PROTOCOL_SCALE.rowTitle} text-ink`,
  row: `${PROTOCOL_SCALE.row} text-ink-70`,
  secondary: `${PROTOCOL_SCALE.secondary} text-ink-70`,
  meta: `${PROTOCOL_SCALE.meta} text-ink-70`,
  /** Inline text link or "Uitleg" action. */
  link: `${PROTOCOL_SCALE.label} text-teal-600`,
} as const;
