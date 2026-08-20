// PowerSync client-side SQLite schema. Each table mirrors a Postgres table
// from the Laravel backend — the column subset PowerSync should materialize
// in the on-device SQLite database.
//
// Notes:
//   - PowerSync auto-creates an `id` TEXT primary key on every table. Don't
//     declare it. PowerSync converts integer IDs from Postgres to strings.
//   - Booleans and timestamps come across as integers / ISO strings, so use
//     `column.integer` / `column.text` accordingly.
//   - Numeric Postgres columns map to `column.real`.

import { column, Schema, Table } from '@powersync/react-native';

const users = new Table({
  email: column.text,
  name: column.text,
  avatar_initial: column.text,
  avatar_url: column.text,
  locale: column.text,
  units_system: column.text,
  notifications_on: column.integer,
  onboarded_at: column.text,
  created_at: column.text,
  updated_at: column.text,
});

const therapists = new Table({
  name: column.text,
  title: column.text,
  bio: column.text,
  avatar_url: column.text,
  avatar_initial: column.text,
  avatar_color: column.text,
  verified: column.integer,
  created_at: column.text,
  updated_at: column.text,
});

const focus_topics = new Table({
  slug: column.text,
  icon: column.text,
  title: column.text,
  description: column.text,
  order: column.integer,
});

const horses = new Table({
  owner_id: column.text,
  name: column.text,
  breed: column.text,
  age: column.integer,
  sex: column.text,
  weight_kg: column.integer,
  stable: column.text,
  photo_url: column.text,
  status: column.text,
  archived_at: column.text,
  archived_note: column.text,
  created_at: column.text,
  updated_at: column.text,
}, { indexes: { byOwner: ['owner_id'] } });

const horse_focus = new Table({
  horse_id: column.text,
  focus_topic_id: column.text,
  extra_label: column.text,
  added_at: column.text,
}, { indexes: { byHorse: ['horse_id'] } });

const horse_shares = new Table({
  horse_id: column.text,
  grantee_user_id: column.text,
  therapist_id: column.text,
  role: column.text,
  since: column.text,
}, { indexes: { byHorse: ['horse_id'] } });

const horse_stats = new Table({
  horse_id: column.text,
  measured_at: column.text,
  weight_kg: column.integer,
  energy: column.integer,
  stool_score: column.text,
  label: column.text,
  value_label: column.text,
  trend: column.text,
  order: column.integer,
}, { indexes: { byHorse: ['horse_id'] } });

const timeline_events = new Table({
  horse_id: column.text,
  occurred_at: column.text,
  when_label: column.text,
  kind: column.text,
  message: column.text,
  ref_type: column.text,
  ref_id: column.text,
  is_now: column.integer,
  order: column.integer,
}, { indexes: { byHorse: ['horse_id'] } });

const protocols = new Table({
  horse_id: column.text,
  therapist_id: column.text,
  title: column.text,
  subtitle_analyse: column.text,
  subtitle_protocol: column.text,
  subtitle_calendar: column.text,
  total_weeks: column.integer,
  current_week: column.integer,
  started_at: column.text,
  status: column.text,
}, { indexes: { byHorse: ['horse_id'] } });

const protocol_phases = new Table({
  protocol_id: column.text,
  order: column.integer,
  title: column.text,
  state: column.text,
  week_start: column.integer,
  week_end: column.integer,
  chip_label: column.text,
}, { indexes: { byProtocol: ['protocol_id'] } });

const protocol_phase_items = new Table({
  phase_id: column.text,
  order: column.integer,
  label: column.text,
}, { indexes: { byPhase: ['phase_id'] } });

const protocol_analyses = new Table({
  protocol_id: column.text,
  cause: column.text,
});

const protocol_advice = new Table({
  analysis_id: column.text,
  icon_key: column.text,
  title: column.text,
  body: column.text,
  order: column.integer,
}, { indexes: { byAnalysis: ['analysis_id'] } });

const protocol_tasks = new Table({
  protocol_id: column.text,
  phase_id: column.text,
  label: column.text,
  meta: column.text,
  kind: column.text,
  order: column.integer,
  active_from: column.text,
  active_until: column.text,
  reference_item_id: column.text,
}, { indexes: { byProtocol: ['protocol_id'] } });

const protocol_task_completions = new Table({
  task_id: column.text,
  horse_id: column.text,
  date: column.text,
  done: column.integer,
  done_at: column.text,
}, { indexes: { byTaskDate: ['task_id', 'date'], byHorseDate: ['horse_id', 'date'] } });

const observations = new Table({
  horse_id: column.text,
  author_id: column.text,
  date: column.text,
  note: column.text,
  mood: column.integer,
  stool_score: column.text,
  protocol_task_id: column.text,
}, { indexes: { byHorse: ['horse_id'] } });

const observation_photos = new Table({
  observation_id: column.text,
  url: column.text,
  caption: column.text,
  kind: column.text,
});

const products = new Table({
  brand: column.text,
  name: column.text,
  barcode: column.text,
  category: column.text,
});

const ingredients = new Table({
  name: column.text,
  description: column.text,
  default_tag: column.text,
});

const scan_results = new Table({
  user_id: column.text,
  horse_id: column.text,
  product_id: column.text,
  product_name: column.text,
  brand: column.text,
  scanned_at: column.text,
  when_label: column.text,
  score: column.integer,
  rating: column.text,
  advice: column.text,
  photo_url: column.text,
  bookmarked: column.integer,
}, { indexes: { byUser: ['user_id'] } });

const scan_ingredients = new Table({
  scan_id: column.text,
  ingredient_id: column.text,
  name: column.text,
  tag: column.text,
  description: column.text,
  order: column.integer,
}, { indexes: { byScan: ['scan_id'] } });

const library_items = new Table({
  slug: column.text,
  format: column.text,
  title: column.text,
  description: column.text,
  body: column.text,
  hero_image_url: column.text,
  duration_label: column.text,
  duration_sec: column.integer,
  author_therapist_id: column.text,
  views_label: column.text,
  published_at: column.text,
  is_plus: column.integer,
  is_featured: column.integer,
  order: column.integer,
});

const library_chapters = new Table({
  item_id: column.text,
  order: column.integer,
  title: column.text,
  start_label: column.text,
  start_sec: column.integer,
}, { indexes: { byItem: ['item_id'] } });

const library_article_sections = new Table({
  item_id: column.text,
  order: column.integer,
  heading: column.text,
  body: column.text,
}, { indexes: { byItem: ['item_id'] } });

const library_categories = new Table({
  slug: column.text,
  label: column.text,
  order: column.integer,
  is_default: column.integer,
});

const library_item_categories = new Table({
  item_id: column.text,
  category_id: column.text,
});

const library_item_focus = new Table({
  item_id: column.text,
  focus_topic_id: column.text,
});

const library_bookmarks = new Table({
  user_id: column.text,
  item_id: column.text,
}, { indexes: { byUser: ['user_id'] } });

const library_progress = new Table({
  user_id: column.text,
  item_id: column.text,
  position_sec: column.integer,
  progress: column.real,
  completed: column.integer,
  last_viewed_at: column.text,
}, { indexes: { byUser: ['user_id'] } });

const seasonal_tips = new Table({
  month: column.text,
  month_order: column.integer,
  body: column.text,
  cta_item_id: column.text,
  active: column.integer,
  active_from: column.text,
  active_to: column.text,
});

const community_categories = new Table({
  slug: column.text,
  label: column.text,
  order: column.integer,
  is_default: column.integer,
});

const community_tags = new Table({
  slug: column.text,
  label: column.text,
});

const community_posts = new Table({
  author_user_id: column.text,
  author_name: column.text,
  author_initial: column.text,
  author_avatar_color: column.text,
  body: column.text,
  when_label: column.text,
  likes_count: column.integer,
  replies_count: column.integer,
  has_expert_reply: column.integer,
  category_id: column.text,
  order: column.integer,
  created_at: column.text,
  updated_at: column.text,
});

const community_post_tags = new Table({
  post_id: column.text,
  tag_id: column.text,
  order: column.integer,
}, { indexes: { byPost: ['post_id'] } });

const community_replies = new Table({
  post_id: column.text,
  author_user_id: column.text,
  author_therapist_id: column.text,
  author_name: column.text,
  author_initial: column.text,
  author_avatar_color: column.text,
  author_is_expert: column.integer,
  body: column.text,
  when_label: column.text,
  likes_count: column.integer,
  replies_count: column.integer,
  order: column.integer,
  created_at: column.text,
  updated_at: column.text,
}, { indexes: { byPost: ['post_id'] } });

const community_reactions = new Table({
  user_id: column.text,
  target_type: column.text,
  target_id: column.text,
  kind: column.text,
});

const plans = new Table({
  slug: column.text,
  label: column.text,
  name: column.text,
  price_cents: column.integer,
  currency: column.text,
  interval: column.text,
  price_suffix: column.text,
  description: column.text,
  is_recommended: column.integer,
  order: column.integer,
});

const plan_benefits = new Table({
  plan_id: column.text,
  label: column.text,
  order: column.integer,
}, { indexes: { byPlan: ['plan_id'] } });

const subscriptions = new Table({
  user_id: column.text,
  plan_id: column.text,
  status: column.text,
  price_cents: column.integer,
  currency: column.text,
  interval: column.text,
  started_at: column.text,
  started_label: column.text,
  renews_at: column.text,
  renews_label: column.text,
  cancelled_at: column.text,
  max_horses: column.integer,
}, { indexes: { byUser: ['user_id'] } });

const payments = new Table({
  subscription_id: column.text,
  date: column.text,
  date_label: column.text,
  amount_cents: column.integer,
  amount_label: column.text,
  currency: column.text,
  status: column.text,
  receipt_url: column.text,
  order: column.integer,
}, { indexes: { bySub: ['subscription_id'] } });

const notification_preferences = new Table({
  user_id: column.text,
  reminder_protocol: column.integer,
  reminder_community: column.integer,
  reminder_seasonal_tips: column.integer,
  active_reminders_count: column.integer,
  push_token: column.text,
});

const account_settings = new Table({
  user_id: column.text,
  icon_key: column.text,
  title: column.text,
  subtitle: column.text,
  route: column.text,
  order: column.integer,
}, { indexes: { byUser: ['user_id'] } });

const data_exports = new Table({
  user_id: column.text,
  horse_id: column.text,
  format: column.text,
  requested_at: column.text,
  completed_at: column.text,
  file_url: column.text,
});

const chat_sessions = new Table({
  user_id: column.text,
  horse_id: column.text,
  started_at: column.text,
}, { indexes: { byUser: ['user_id'] } });

const chat_messages = new Table({
  session_id: column.text,
  role: column.text,
  body: column.text,
  order: column.integer,
  created_at: column.text,
  updated_at: column.text,
}, { indexes: { bySession: ['session_id'] } });

const nova_fallback_replies = new Table({
  body: column.text,
  order: column.integer,
});

const intake_bookings = new Table({
  user_id: column.text,
  horse_id: column.text,
  therapist_id: column.text,
  scheduled_at: column.text,
  slot_label: column.text,
  duration_minutes: column.integer,
  status: column.text,
  notes: column.text,
}, { indexes: { byUser: ['user_id'] } });

// Protocol-intake form: one response row per user, with one answer row per
// answered question. `value` holds the JSON-encoded answer (scalar, multi
// array, or repeater rows) — see lib/intake/store.tsx.
const intake_responses = new Table({
  user_id: column.text,
  horse_id: column.text,
  status: column.text,
  started_at: column.text,
  submitted_at: column.text,
  created_at: column.text,
  updated_at: column.text,
}, { indexes: { byUser: ['user_id'] } });

const intake_answers = new Table({
  response_id: column.text,
  section_id: column.text,
  field_id: column.text,
  value: column.text,
  created_at: column.text,
  updated_at: column.text,
}, { indexes: { byResponse: ['response_id'] } });

export const AppSchema = new Schema({
  users,
  therapists,
  focus_topics,
  horses,
  horse_focus,
  horse_shares,
  horse_stats,
  timeline_events,
  protocols,
  protocol_phases,
  protocol_phase_items,
  protocol_analyses,
  protocol_advice,
  protocol_tasks,
  protocol_task_completions,
  observations,
  observation_photos,
  products,
  ingredients,
  scan_results,
  scan_ingredients,
  library_items,
  library_chapters,
  library_article_sections,
  library_categories,
  library_item_categories,
  library_item_focus,
  library_bookmarks,
  library_progress,
  seasonal_tips,
  community_categories,
  community_tags,
  community_posts,
  community_post_tags,
  community_replies,
  community_reactions,
  plans,
  plan_benefits,
  subscriptions,
  payments,
  notification_preferences,
  account_settings,
  data_exports,
  chat_sessions,
  chat_messages,
  nova_fallback_replies,
  intake_bookings,
  intake_responses,
  intake_answers,
});
