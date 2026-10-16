# EquiApp full data model

Schema snapshot after the 2026-10-15 migrations: **101 tables**. Metadata only, no application data. Verified against the migrated test database; existing environments migrate during deployment.

Every table is listed once in its domain. External parent tables reappear in diagrams as references. PK = primary key, FK = foreign key, UQ = single-column unique constraint. `protocols.horse_id` is unique only where status is active. The protocol/intake relationship is exactly 1:1, enforced by a required unique FK plus deferred triggers. Other edges reflect declared database foreign keys. Polymorphic and JSON references are described separately below.

## Accounts and horses

```mermaid
erDiagram
    users {
        uuid id PK
        varchar name
        varchar email UK
        timestamp email_verified_at
        varchar password
        varchar remember_token
        timestamp created_at
        timestamp updated_at
        varchar avatar_initial
        varchar avatar_url
        varchar locale
        varchar units_system
        bool notifications_on
        timestamp onboarded_at
        timestamp disabled_at
        varchar admin_note
    }
    horses {
        uuid id PK
        uuid owner_id FK
        varchar name
        varchar breed
        int2 age
        varchar sex
        int4 weight_kg
        varchar stable
        varchar photo_url
        varchar status
        timestamp archived_at
        varchar archived_note
        timestamp created_at
        timestamp updated_at
    }
    horse_shares {
        uuid id PK
        uuid horse_id FK
        uuid grantee_user_id FK
        uuid therapist_id FK
        varchar role
        date since
        timestamp created_at
        timestamp updated_at
    }
    horse_stats {
        uuid id PK
        uuid horse_id FK
        date measured_at
        int4 weight_kg
        int2 energy
        varchar stool_score
        varchar label
        varchar value_label
        varchar trend
        int4 order
        timestamp created_at
        timestamp updated_at
    }
    observations {
        uuid id PK
        uuid horse_id FK
        uuid author_id FK
        date date
        text note
        int2 mood
        varchar stool_score
        timestamp created_at
        timestamp updated_at
    }
    observation_photos {
        uuid id PK
        uuid observation_id FK
        varchar url
        varchar caption
        varchar kind
        timestamp created_at
        timestamp updated_at
    }
    timeline_events {
        uuid id PK
        uuid horse_id FK
        timestamp occurred_at
        varchar when_label
        varchar kind
        text message
        varchar ref_type
        uuid ref_id
        bool is_now
        int4 order
        timestamp created_at
        timestamp updated_at
    }
    account_settings {
        uuid id PK
        uuid user_id FK
        varchar icon_key
        varchar title
        varchar subtitle
        varchar route
        int4 order
        timestamp created_at
        timestamp updated_at
    }
    user_home_preferences {
        uuid id PK, FK
        bool seasonal_tips_enabled
        json dismissed_tip_ids
        timestamp created_at
        timestamp updated_at
    }
    notification_preferences {
        uuid id PK
        uuid user_id FK, UK
        bool reminder_protocol
        bool reminder_community
        bool reminder_seasonal_tips
        int4 active_reminders_count
        varchar push_token
        timestamp created_at
        timestamp updated_at
        varchar timezone
    }
    pending_registrations {
        varchar token_hash PK
        varchar email
        varchar name
        varchar password_hash
        timestamp expires_at
        timestamp created_at
        varchar uid_hash UK
        timestamp confirmed_at
        uuid user_id FK
    }
    data_exports {
        uuid id PK
        uuid user_id FK
        uuid horse_id FK
        varchar format
        timestamp requested_at
        timestamp completed_at
        varchar file_url
        timestamp created_at
        timestamp updated_at
    }
    users ||--o{ horses : "owner_id"
    users o|--o{ horse_shares : "grantee_user_id"
    horses ||--o{ horse_shares : "horse_id"
    therapists o|--o{ horse_shares : "therapist_id"
    horses ||--o{ horse_stats : "horse_id"
    users ||--o{ observations : "author_id"
    horses ||--o{ observations : "horse_id"
    observations ||--o{ observation_photos : "observation_id"
    horses ||--o{ timeline_events : "horse_id"
    users ||--o{ account_settings : "user_id"
    users ||--o| user_home_preferences : "id"
    users ||--o| notification_preferences : "user_id"
    users o|--o{ pending_registrations : "user_id"
    horses o|--o{ data_exports : "horse_id"
    users ||--o{ data_exports : "user_id"
```

### users

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| name | character varying(255) | No |  |
| email | character varying(255) | No | UQ |
| email_verified_at | timestamp(0) without time zone | Yes |  |
| password | character varying(255) | No |  |
| remember_token | character varying(100) | Yes |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| avatar_initial | character varying(4) | Yes |  |
| avatar_url | character varying(255) | Yes |  |
| locale | character varying(16) | No |  |
| units_system | character varying(16) | No |  |
| notifications_on | boolean | No |  |
| onboarded_at | timestamp(0) without time zone | Yes |  |
| disabled_at | timestamp(0) without time zone | Yes |  |
| admin_note | character varying(255) | Yes |  |

### horses

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| owner_id | uuid | No | FK |
| name | character varying(255) | No |  |
| breed | character varying(255) | Yes |  |
| age | smallint | Yes |  |
| sex | character varying(16) | Yes |  |
| weight_kg | integer | Yes |  |
| stable | character varying(255) | Yes |  |
| photo_url | character varying(255) | Yes |  |
| status | character varying(16) | No |  |
| archived_at | timestamp(0) without time zone | Yes |  |
| archived_note | character varying(255) | Yes |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- owner_id → users(id) · on delete: cascade

### horse_shares

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| horse_id | uuid | No | FK |
| grantee_user_id | uuid | Yes | FK |
| therapist_id | uuid | Yes | FK |
| role | character varying(16) | No |  |
| since | date | Yes |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- grantee_user_id → users(id) · on delete: set null

- horse_id → horses(id) · on delete: cascade

- therapist_id → therapists(id) · on delete: set null

### horse_stats

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| horse_id | uuid | No | FK |
| measured_at | date | No |  |
| weight_kg | integer | Yes |  |
| energy | smallint | Yes |  |
| stool_score | character varying(8) | Yes |  |
| label | character varying(255) | Yes |  |
| value_label | character varying(255) | Yes |  |
| trend | character varying(255) | Yes |  |
| order | integer | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- horse_id → horses(id) · on delete: cascade

### observations

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| horse_id | uuid | No | FK |
| author_id | uuid | No | FK |
| date | date | No |  |
| note | text | Yes |  |
| mood | smallint | Yes |  |
| stool_score | character varying(8) | Yes |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- author_id → users(id) · on delete: cascade

- horse_id → horses(id) · on delete: cascade

### observation_photos

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| observation_id | uuid | No | FK |
| url | character varying(255) | No |  |
| caption | character varying(255) | Yes |  |
| kind | character varying(32) | Yes |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- observation_id → observations(id) · on delete: cascade

### timeline_events

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| horse_id | uuid | No | FK |
| occurred_at | timestamp(0) without time zone | No |  |
| when_label | character varying(255) | Yes |  |
| kind | character varying(32) | No |  |
| message | text | No |  |
| ref_type | character varying(255) | Yes |  |
| ref_id | uuid | Yes |  |
| is_now | boolean | No |  |
| order | integer | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- horse_id → horses(id) · on delete: cascade

### account_settings

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| user_id | uuid | No | FK |
| icon_key | character varying(32) | No |  |
| title | character varying(255) | No |  |
| subtitle | character varying(255) | Yes |  |
| route | character varying(255) | Yes |  |
| order | integer | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- user_id → users(id) · on delete: cascade

### user_home_preferences

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK, FK |
| seasonal_tips_enabled | boolean | No |  |
| dismissed_tip_ids | json | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- id → users(id) · on delete: cascade

### notification_preferences

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| user_id | uuid | No | FK, UQ |
| reminder_protocol | boolean | No |  |
| reminder_community | boolean | No |  |
| reminder_seasonal_tips | boolean | No |  |
| active_reminders_count | integer | No |  |
| push_token | character varying(255) | Yes |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| timezone | character varying(255) | No |  |

- user_id → users(id) · on delete: cascade

### pending_registrations

| Column | Type | Nullable | Key |
|---|---|---|---|
| token_hash | character varying(64) | No | PK |
| email | character varying(255) | No |  |
| name | character varying(255) | No |  |
| password_hash | character varying(255) | No |  |
| expires_at | timestamp(0) without time zone | No |  |
| created_at | timestamp(0) without time zone | No |  |
| uid_hash | character varying(64) | No | UQ |
| confirmed_at | timestamp(0) without time zone | Yes |  |
| user_id | uuid | Yes | FK |

- user_id → users(id) · on delete: cascade

### data_exports

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| user_id | uuid | No | FK |
| horse_id | uuid | Yes | FK |
| format | character varying(8) | No |  |
| requested_at | timestamp(0) without time zone | No |  |
| completed_at | timestamp(0) without time zone | Yes |  |
| file_url | character varying(255) | Yes |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- horse_id → horses(id) · on delete: set null

- user_id → users(id) · on delete: cascade

## Protocols and intake

```mermaid
erDiagram
    protocols {
        uuid id PK
        uuid horse_id FK
        uuid therapist_id FK
        varchar title
        varchar subtitle_analyse
        varchar subtitle_protocol
        varchar subtitle_calendar
        int2 total_weeks
        int2 current_week
        date started_at
        varchar status
        timestamp created_at
        timestamp updated_at
        uuid protocol_template_id FK
        timestamp published_at
        varchar protocol_template_name
        json customer_settings
        varchar archived_previous_status
    }
    intake_bookings {
        uuid id PK
        uuid user_id FK
        uuid horse_id FK
        uuid therapist_id FK
        timestamp scheduled_at
        varchar slot_label
        int2 duration_minutes
        varchar status
        text notes
        timestamp created_at
        timestamp updated_at
        varchar intake_status
        timestamp started_at
        timestamp submitted_at
        jsonb accepted_triggers
        text review_notes
        timestamp review_updated_at
        varchar answers_email_status
        varchar answers_email_to
        timestamptz answers_email_claimed_at
        timestamptz answers_email_sent_at
        text answers_email_error
        varchar submission_email_status
        timestamptz submission_email_claimed_at
        timestamptz submission_email_sent_at
        text submission_email_error
        timestamptz last_activity_at
        varchar last_section
        varchar last_field
        timestamptz last_reminder_at
        uuid protocol_id FK, UK
    }
    intake_answers {
        uuid id PK
        uuid response_id FK
        varchar section_id
        varchar field_id
        text value
        timestamp created_at
        timestamp updated_at
    }
    intake_attachments {
        uuid id PK
        uuid booking_id FK
        varchar section_key
        varchar field_key
        varchar name
        varchar path
        varchar mime
        timestamp created_at
        timestamp updated_at
    }
    protocol_analyses {
        uuid id PK
        uuid protocol_id FK, UK
        text cause
        timestamp created_at
        timestamp updated_at
        text summary
        json focus_points
        json observations
    }
    protocol_advice {
        uuid id PK
        uuid analysis_id FK
        varchar icon_key
        varchar title
        text body
        int4 order
        timestamp created_at
        timestamp updated_at
    }
    protocol_phases {
        uuid id PK
        uuid protocol_id FK
        int4 order
        varchar title
        varchar state
        int2 week_start
        int2 week_end
        varchar chip_label
        timestamp created_at
        timestamp updated_at
        uuid protocol_template_phase_id FK
        text description
        bool required
        int2 start_after_previous_phase_weeks
    }
    protocol_phase_weeks {
        uuid id PK
        uuid protocol_phase_id FK
        uuid protocol_template_phase_week_id FK
        int4 number
        int4 protocol_week_number
        timestamp created_at
        timestamp updated_at
    }
    protocol_phase_supplements {
        uuid id PK
        uuid protocol_phase_id FK
        uuid supplement_id FK
        timestamp created_at
        timestamp updated_at
        varchar name
        text description
        varchar supplement_type
        varchar dosage
        int4 aantal_per_week
        text instructions
        varchar dosis_type
        float8 dosis
        varchar unit
        bool add_by_default
        int4 max_aantal_in_fase
        int4 min_aantal_per_week
        int4 rust_periode_in_weken
        varchar dosage_mode
    }
    protocol_phase_supplement_weeks {
        uuid id PK
        uuid protocol_phase_supplement_id FK
        uuid protocol_phase_week_id FK
        timestamp created_at
        timestamp updated_at
    }
    protocol_supplement_intakes {
        uuid id PK
        uuid protocol_phase_supplement_id FK
        uuid horse_id FK
        date date
        varchar dosage
        bool done
        timestamp taken_at
        timestamp created_at
        timestamp updated_at
    }
    protocol_day_snapshots {
        int8 id PK
        uuid protocol_id FK
        date date
        json items
        timestamp created_at
        timestamp updated_at
    }
    protocol_weekly_updates {
        int8 id PK
        uuid protocol_id FK
        uuid user_id FK
        int4 week_number
        text note
        int2 mood
        timestamp created_at
        timestamp updated_at
    }
    horses ||--o{ protocols : "horse_id"
    protocol_templates o|--o{ protocols : "protocol_template_id"
    therapists o|--o{ protocols : "therapist_id"
    horses ||--o{ intake_bookings : "horse_id"
    protocols ||--|| intake_bookings : "protocol_id"
    therapists o|--o{ intake_bookings : "therapist_id"
    users ||--o{ intake_bookings : "user_id"
    intake_bookings ||--o{ intake_answers : "response_id"
    intake_bookings ||--o{ intake_attachments : "booking_id"
    protocols ||--o| protocol_analyses : "protocol_id"
    protocol_analyses ||--o{ protocol_advice : "analysis_id"
    protocols ||--o{ protocol_phases : "protocol_id"
    protocol_template_phases ||--o{ protocol_phases : "protocol_template_phase_id"
    protocol_phases ||--o{ protocol_phase_weeks : "protocol_phase_id"
    protocol_template_phase_weeks o|--o{ protocol_phase_weeks : "protocol_template_phase_week_id"
    protocol_phases ||--o{ protocol_phase_supplements : "protocol_phase_id"
    supplements o|--o{ protocol_phase_supplements : "supplement_id"
    protocol_phase_supplements ||--o{ protocol_phase_supplement_weeks : "protocol_phase_supplement_id"
    protocol_phase_weeks ||--o{ protocol_phase_supplement_weeks : "protocol_phase_week_id"
    horses ||--o{ protocol_supplement_intakes : "horse_id"
    protocol_phase_supplements ||--o{ protocol_supplement_intakes : "protocol_phase_supplement_id"
    protocols ||--o{ protocol_day_snapshots : "protocol_id"
    protocols ||--o{ protocol_weekly_updates : "protocol_id"
    users ||--o{ protocol_weekly_updates : "user_id"
```

### protocols

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| horse_id | uuid | No | FK |
| therapist_id | uuid | Yes | FK |
| title | character varying(255) | No |  |
| subtitle_analyse | character varying(255) | Yes |  |
| subtitle_protocol | character varying(255) | Yes |  |
| subtitle_calendar | character varying(255) | Yes |  |
| total_weeks | smallint | Yes |  |
| current_week | smallint | Yes |  |
| started_at | date | Yes |  |
| status | character varying(16) | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| protocol_template_id | uuid | Yes | FK |
| published_at | timestamp(0) without time zone | Yes |  |
| protocol_template_name | character varying(255) | Yes |  |
| customer_settings | json | Yes |  |
| archived_previous_status | character varying(16) | Yes |  |

- horse_id → horses(id) · on delete: cascade

- protocol_template_id → protocol_templates(id) · on delete: restrict

- therapist_id → therapists(id) · on delete: set null

### intake_bookings

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| user_id | uuid | No | FK |
| horse_id | uuid | No | FK |
| therapist_id | uuid | Yes | FK |
| scheduled_at | timestamp(0) without time zone | Yes |  |
| slot_label | character varying(255) | Yes |  |
| duration_minutes | smallint | No |  |
| status | character varying(16) | No |  |
| notes | text | Yes |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| intake_status | character varying(16) | No |  |
| started_at | timestamp(0) without time zone | Yes |  |
| submitted_at | timestamp(0) without time zone | Yes |  |
| accepted_triggers | jsonb | No |  |
| review_notes | text | Yes |  |
| review_updated_at | timestamp(0) without time zone | Yes |  |
| answers_email_status | character varying(255) | Yes |  |
| answers_email_to | character varying(255) | Yes |  |
| answers_email_claimed_at | timestamp(0) with time zone | Yes |  |
| answers_email_sent_at | timestamp(0) with time zone | Yes |  |
| answers_email_error | text | Yes |  |
| submission_email_status | character varying(255) | Yes |  |
| submission_email_claimed_at | timestamp(0) with time zone | Yes |  |
| submission_email_sent_at | timestamp(0) with time zone | Yes |  |
| submission_email_error | text | Yes |  |
| last_activity_at | timestamp(0) with time zone | Yes |  |
| last_section | character varying(64) | Yes |  |
| last_field | character varying(100) | Yes |  |
| last_reminder_at | timestamp(0) with time zone | Yes |  |
| protocol_id | uuid | No | FK, UQ |

- horse_id → horses(id) · on delete: cascade

- protocol_id → protocols(id) · on delete: cascade

- therapist_id → therapists(id) · on delete: restrict

- user_id → users(id) · on delete: cascade

- protocol_id, horse_id → protocols(id, horse_id) · on delete: cascade

### intake_answers

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| response_id | uuid | No | FK |
| section_id | character varying(64) | No |  |
| field_id | character varying(64) | No |  |
| value | text | Yes |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- response_id → intake_bookings(id) · on delete: cascade

### intake_attachments

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| booking_id | uuid | No | FK |
| section_key | character varying(64) | No |  |
| field_key | character varying(64) | No |  |
| name | character varying(255) | No |  |
| path | character varying(255) | No |  |
| mime | character varying(255) | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- booking_id → intake_bookings(id) · on delete: cascade

### protocol_analyses

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| protocol_id | uuid | No | FK, UQ |
| cause | text | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| summary | text | Yes |  |
| focus_points | json | Yes |  |
| observations | json | Yes |  |

- protocol_id → protocols(id) · on delete: cascade

### protocol_advice

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| analysis_id | uuid | No | FK |
| icon_key | character varying(16) | No |  |
| title | character varying(255) | No |  |
| body | text | No |  |
| order | integer | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- analysis_id → protocol_analyses(id) · on delete: cascade

### protocol_phases

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| protocol_id | uuid | No | FK |
| order | integer | No |  |
| title | character varying(255) | No |  |
| state | character varying(16) | No |  |
| week_start | smallint | Yes |  |
| week_end | smallint | Yes |  |
| chip_label | character varying(255) | Yes |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| protocol_template_phase_id | uuid | No | FK |
| description | text | Yes |  |
| required | boolean | No |  |
| start_after_previous_phase_weeks | smallint | Yes |  |

- protocol_id → protocols(id) · on delete: cascade

- protocol_template_phase_id → protocol_template_phases(id) · on delete: restrict

### protocol_phase_weeks

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| protocol_phase_id | uuid | No | FK |
| protocol_template_phase_week_id | uuid | Yes | FK |
| number | integer | No |  |
| protocol_week_number | integer | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- protocol_phase_id → protocol_phases(id) · on delete: cascade

- protocol_template_phase_week_id → protocol_template_phase_weeks(id) · on delete: set null

### protocol_phase_supplements

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| protocol_phase_id | uuid | No | FK |
| supplement_id | uuid | Yes | FK |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| name | character varying(255) | Yes |  |
| description | text | Yes |  |
| supplement_type | character varying(255) | Yes |  |
| dosage | character varying(255) | Yes |  |
| aantal_per_week | integer | Yes |  |
| instructions | text | Yes |  |
| dosis_type | character varying(255) | Yes |  |
| dosis | double precision | Yes |  |
| unit | character varying(255) | Yes |  |
| add_by_default | boolean | No |  |
| max_aantal_in_fase | integer | Yes |  |
| min_aantal_per_week | integer | No |  |
| rust_periode_in_weken | integer | No |  |
| dosage_mode | character varying(255) | No |  |

- protocol_phase_id → protocol_phases(id) · on delete: cascade

- supplement_id → supplements(id) · on delete: set null

### protocol_phase_supplement_weeks

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| protocol_phase_supplement_id | uuid | No | FK |
| protocol_phase_week_id | uuid | No | FK |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- protocol_phase_supplement_id → protocol_phase_supplements(id) · on delete: cascade

- protocol_phase_week_id → protocol_phase_weeks(id) · on delete: cascade

### protocol_supplement_intakes

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| protocol_phase_supplement_id | uuid | No | FK |
| horse_id | uuid | No | FK |
| date | date | No |  |
| dosage | character varying(255) | Yes |  |
| done | boolean | No |  |
| taken_at | timestamp(0) without time zone | Yes |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- horse_id → horses(id) · on delete: cascade

- protocol_phase_supplement_id → protocol_phase_supplements(id) · on delete: cascade

### protocol_day_snapshots

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | bigint | No | PK |
| protocol_id | uuid | No | FK |
| date | date | No |  |
| items | json | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- protocol_id → protocols(id) · on delete: cascade

### protocol_weekly_updates

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | bigint | No | PK |
| protocol_id | uuid | No | FK |
| user_id | uuid | No | FK |
| week_number | integer | No |  |
| note | text | No |  |
| mood | smallint | Yes |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- protocol_id → protocols(id) · on delete: cascade

- user_id → users(id) · on delete: cascade

## Clinical templates and questionnaire

```mermaid
erDiagram
    protocol_templates {
        uuid id PK
        varchar name UK
        timestamp created_at
        timestamp updated_at
    }
    protocol_template_phases {
        uuid id PK
        uuid protocol_template_id FK
        int4 order
        varchar name
        text description
        bool required
        timestamp created_at
        timestamp updated_at
        int2 start_after_previous_phase_weeks
    }
    protocol_template_phase_weeks {
        uuid id PK
        uuid protocol_template_phase_id FK
        int2 number
        timestamp created_at
        timestamp updated_at
    }
    supplements {
        uuid id PK
        uuid protocol_template_phase_id FK
        varchar name
        text description
        varchar supplement_type
        bool add_by_default
        int4 max_aantal_in_fase
        int4 min_aantal_per_week
        int4 rust_periode_in_weken
        timestamp created_at
        timestamp updated_at
        varchar dosis_type
        float8 dosis
        varchar unit
        text instructions
    }
    supplement_weeks {
        uuid id PK
        uuid supplement_id FK
        uuid protocol_template_phase_week_id FK
        timestamp created_at
        timestamp updated_at
    }
    voeding_adviezen {
        uuid id PK
        varchar title
        text description
        timestamp created_at
        timestamp updated_at
        varchar layout
        varchar icon
    }
    management_adviezen {
        uuid id PK
        varchar title
        text description
        timestamp created_at
        timestamp updated_at
        varchar layout
        varchar icon
    }
    beweging_adviezen {
        uuid id PK
        varchar title
        text description
        timestamp created_at
        timestamp updated_at
        varchar layout
        varchar icon
    }
    protocol_voeding_adviezen {
        uuid id PK
        uuid protocol_id FK
        uuid voeding_advies_id FK
        varchar title
        text description
        timestamp created_at
        timestamp updated_at
        varchar layout
    }
    protocol_management_adviezen {
        uuid id PK
        uuid protocol_id FK
        uuid management_advies_id FK
        varchar title
        text description
        timestamp created_at
        timestamp updated_at
        varchar layout
    }
    protocol_beweging_adviezen {
        uuid id PK
        uuid protocol_id FK
        uuid beweging_advies_id FK
        varchar title
        text description
        timestamp created_at
        timestamp updated_at
        varchar layout
    }
    intake_questionnaires {
        uuid id PK
        varchar slug UK
        varchar name
        text disclaimer_short
        text disclaimer_long
        bool active
        timestamp created_at
        timestamp updated_at
        jsonb none_options
    }
    intake_sections {
        uuid id PK
        uuid questionnaire_id FK
        varchar key
        int4 order
        varchar title
        text intro
        int4 minutes
        varchar icon
        varchar subtitle
        bool active
        timestamp created_at
        timestamp updated_at
    }
    intake_fields {
        uuid id PK
        uuid section_id FK
        varchar key
        int4 order
        varchar label
        varchar type
        text hint
        bool required
        bool optional
        varchar unit
        float8 step
        bool tall
        int2 lines
        text placeholder
        jsonb link
        jsonb options
        jsonb show_if
        jsonb flag_if
        jsonb critical_if
        jsonb protocol_if
        jsonb repeater_sub
        bool active
        timestamp created_at
        timestamp updated_at
        varchar answer_section_key
    }
    therapists {
        uuid id PK
        varchar name
        varchar title
        text bio
        varchar avatar_url
        varchar avatar_initial
        varchar avatar_color
        bool verified
        timestamp created_at
        timestamp updated_at
        timestamp archived_at
    }
    protocol_templates ||--o{ protocol_template_phases : "protocol_template_id"
    protocol_template_phases ||--o{ protocol_template_phase_weeks : "protocol_template_phase_id"
    protocol_template_phases ||--o{ supplements : "protocol_template_phase_id"
    protocol_template_phase_weeks ||--o{ supplement_weeks : "protocol_template_phase_week_id"
    supplements ||--o{ supplement_weeks : "supplement_id"
    protocols ||--o{ protocol_voeding_adviezen : "protocol_id"
    voeding_adviezen ||--o{ protocol_voeding_adviezen : "voeding_advies_id"
    management_adviezen ||--o{ protocol_management_adviezen : "management_advies_id"
    protocols ||--o{ protocol_management_adviezen : "protocol_id"
    beweging_adviezen ||--o{ protocol_beweging_adviezen : "beweging_advies_id"
    protocols ||--o{ protocol_beweging_adviezen : "protocol_id"
    intake_questionnaires ||--o{ intake_sections : "questionnaire_id"
    intake_sections ||--o{ intake_fields : "section_id"
```

### protocol_templates

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| name | character varying(255) | No | UQ |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

### protocol_template_phases

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| protocol_template_id | uuid | No | FK |
| order | integer | No |  |
| name | character varying(255) | No |  |
| description | text | Yes |  |
| required | boolean | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| start_after_previous_phase_weeks | smallint | Yes |  |

- protocol_template_id → protocol_templates(id) · on delete: cascade

### protocol_template_phase_weeks

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| protocol_template_phase_id | uuid | No | FK |
| number | smallint | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- protocol_template_phase_id → protocol_template_phases(id) · on delete: cascade

### supplements

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| protocol_template_phase_id | uuid | No | FK |
| name | character varying(255) | No |  |
| description | text | Yes |  |
| supplement_type | character varying(255) | No |  |
| add_by_default | boolean | No |  |
| max_aantal_in_fase | integer | Yes |  |
| min_aantal_per_week | integer | No |  |
| rust_periode_in_weken | integer | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| dosis_type | character varying(255) | Yes |  |
| dosis | double precision | Yes |  |
| unit | character varying(255) | Yes |  |
| instructions | text | Yes |  |

- protocol_template_phase_id → protocol_template_phases(id) · on delete: cascade

### supplement_weeks

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| supplement_id | uuid | No | FK |
| protocol_template_phase_week_id | uuid | No | FK |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- protocol_template_phase_week_id → protocol_template_phase_weeks(id) · on delete: cascade

- supplement_id → supplements(id) · on delete: cascade

### voeding_adviezen

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| title | character varying(255) | No |  |
| description | text | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| layout | character varying(32) | No |  |
| icon | character varying(255) | Yes |  |

### management_adviezen

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| title | character varying(255) | No |  |
| description | text | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| layout | character varying(32) | No |  |
| icon | character varying(255) | Yes |  |

### beweging_adviezen

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| title | character varying(255) | No |  |
| description | text | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| layout | character varying(32) | No |  |
| icon | character varying(255) | Yes |  |

### protocol_voeding_adviezen

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| protocol_id | uuid | No | FK |
| voeding_advies_id | uuid | No | FK |
| title | character varying(255) | No |  |
| description | text | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| layout | character varying(32) | No |  |

- protocol_id → protocols(id) · on delete: cascade

- voeding_advies_id → voeding_adviezen(id) · on delete: restrict

### protocol_management_adviezen

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| protocol_id | uuid | No | FK |
| management_advies_id | uuid | No | FK |
| title | character varying(255) | No |  |
| description | text | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| layout | character varying(32) | No |  |

- management_advies_id → management_adviezen(id) · on delete: restrict

- protocol_id → protocols(id) · on delete: cascade

### protocol_beweging_adviezen

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| protocol_id | uuid | No | FK |
| beweging_advies_id | uuid | No | FK |
| title | character varying(255) | No |  |
| description | text | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| layout | character varying(32) | No |  |

- beweging_advies_id → beweging_adviezen(id) · on delete: restrict

- protocol_id → protocols(id) · on delete: cascade

### intake_questionnaires

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| slug | character varying(255) | No | UQ |
| name | character varying(255) | No |  |
| disclaimer_short | text | Yes |  |
| disclaimer_long | text | Yes |  |
| active | boolean | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| none_options | jsonb | No |  |

### intake_sections

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| questionnaire_id | uuid | No | FK |
| key | character varying(64) | No |  |
| order | integer | No |  |
| title | character varying(255) | No |  |
| intro | text | Yes |  |
| minutes | integer | No |  |
| icon | character varying(32) | Yes |  |
| subtitle | character varying(255) | Yes |  |
| active | boolean | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- questionnaire_id → intake_questionnaires(id) · on delete: cascade

### intake_fields

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| section_id | uuid | No | FK |
| key | character varying(64) | No |  |
| order | integer | No |  |
| label | character varying(255) | No |  |
| type | character varying(32) | No |  |
| hint | text | Yes |  |
| required | boolean | No |  |
| optional | boolean | No |  |
| unit | character varying(32) | Yes |  |
| step | double precision | Yes |  |
| tall | boolean | No |  |
| lines | smallint | Yes |  |
| placeholder | text | Yes |  |
| link | jsonb | Yes |  |
| options | jsonb | Yes |  |
| show_if | jsonb | Yes |  |
| flag_if | jsonb | Yes |  |
| critical_if | jsonb | Yes |  |
| protocol_if | jsonb | Yes |  |
| repeater_sub | jsonb | Yes |  |
| active | boolean | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| answer_section_key | character varying(64) | Yes |  |

- section_id → intake_sections(id) · on delete: cascade

### therapists

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| name | character varying(255) | No |  |
| title | character varying(255) | Yes |  |
| bio | text | Yes |  |
| avatar_url | character varying(255) | Yes |  |
| avatar_initial | character varying(4) | Yes |  |
| avatar_color | character varying(16) | Yes |  |
| verified | boolean | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| archived_at | timestamp(0) without time zone | Yes |  |

## Plans payments and credits

```mermaid
erDiagram
    plans {
        uuid id PK
        varchar slug UK
        varchar label
        varchar name
        int4 price_cents
        varchar currency
        varchar interval
        varchar price_suffix
        text description
        bool is_recommended
        int4 order
        timestamp created_at
        timestamp updated_at
    }
    plan_benefits {
        uuid id PK
        uuid plan_id FK
        varchar label
        int4 order
        timestamp created_at
        timestamp updated_at
    }
    subscriptions {
        uuid id PK
        uuid user_id FK
        uuid plan_id FK
        varchar status
        int4 price_cents
        varchar currency
        varchar interval
        date started_at
        varchar started_label
        date renews_at
        varchar renews_label
        date cancelled_at
        int2 max_horses
        timestamp created_at
        timestamp updated_at
        timestamptz paid_through
        timestamptz cancel_requested_at
        timestamptz ended_at
    }
    payments {
        uuid id PK
        uuid subscription_id FK
        date date
        varchar date_label
        int4 amount_cents
        varchar amount_label
        varchar currency
        varchar status
        varchar receipt_url
        int4 order
        timestamp created_at
        timestamp updated_at
    }
    protocol_purchases {
        uuid id PK
        uuid user_id FK
        uuid horse_id FK
        uuid protocol_id FK, UK
        uuid request_key
        varchar payment_reference UK
        int4 amount_cents
        bpchar currency
        timestamptz fulfilled_at
        timestamp created_at
        timestamp updated_at
    }
    plus_intake_entitlements {
        uuid user_id PK, FK
        uuid horse_id FK
        uuid booking_id UK
        uuid subscription_id FK
        bool test_bypass
        timestamp created_at
        timestamp updated_at
    }
    plus_upgrades {
        uuid id PK
        uuid user_id FK
        uuid horse_id FK
        varchar payment_status
        timestamp created_at
        timestamp updated_at
    }
    plus_pages {
        varchar id PK
        json content
        varchar hero_path
        varchar portrait_path
        timestamp created_at
        timestamp updated_at
        int4 protocol_price_cents
    }
    credit_bundles {
        uuid id PK
        varchar label
        int4 credits
        int4 price_cents
        varchar currency
        bool active
        int4 order
        timestamptz created_at
        timestamptz updated_at
    }
    credit_grants {
        uuid id PK
        uuid user_id FK
        varchar source
        uuid subscription_id FK
        uuid order_id FK, UK
        timestamptz expires_at
        timestamptz created_at
        timestamptz updated_at
    }
    credit_orders {
        uuid id PK
        uuid user_id FK
        uuid bundle_id FK
        uuid subscription_id FK
        uuid item_id FK
        varchar kind
        varchar status
        int4 credits
        int4 price_cents
        varchar currency
        int4 validity_months
        varchar provider
        varchar payment_id UK
        text checkout_url
        uuid request_key
        timestamptz paid_at
        timestamptz period_end
        timestamptz reversed_at
        int4 spent_before_reversal
        timestamptz created_at
        timestamptz updated_at
    }
    credit_reminders {
        uuid grant_id PK, FK
        int4 days PK
        timestamptz sent_at
        varchar ticket_id
        varchar push_token
        timestamptz receipt_checked_at
        varchar receipt_error
    }
    credit_settings {
        int4 id PK
        int4 monthly_credits
        int4 membership_cap
        int4 purchase_validity_months
    }
    credit_transactions {
        uuid id PK
        uuid user_id FK
        uuid grant_id FK
        int4 amount
        varchar type
        varchar source
        timestamptz expires_at
        varchar payment_id
        uuid item_id FK
        varchar description
        text reason
        timestamptz created_at
    }
    library_credit_balances {
        uuid user_id PK, FK
        int4 balance
        timestamp created_at
        timestamp updated_at
    }
    library_unlocks {
        uuid user_id PK, FK
        uuid item_id PK, FK
        timestamp created_at
        timestamp updated_at
    }
    plans ||--o{ plan_benefits : "plan_id"
    plans ||--o{ subscriptions : "plan_id"
    users ||--o{ subscriptions : "user_id"
    subscriptions ||--o{ payments : "subscription_id"
    horses o|--o{ protocol_purchases : "horse_id"
    protocols o|--o| protocol_purchases : "protocol_id"
    users ||--o{ protocol_purchases : "user_id"
    horses o|--o{ plus_intake_entitlements : "horse_id"
    subscriptions o|--o{ plus_intake_entitlements : "subscription_id"
    users ||--o| plus_intake_entitlements : "user_id"
    horses ||--o{ plus_upgrades : "horse_id"
    users ||--o{ plus_upgrades : "user_id"
    credit_orders o|--o| credit_grants : "order_id"
    subscriptions o|--o{ credit_grants : "subscription_id"
    users ||--o{ credit_grants : "user_id"
    credit_bundles o|--o{ credit_orders : "bundle_id"
    library_items o|--o{ credit_orders : "item_id"
    subscriptions o|--o{ credit_orders : "subscription_id"
    users ||--o{ credit_orders : "user_id"
    credit_grants ||--o{ credit_reminders : "grant_id"
    credit_grants ||--o{ credit_transactions : "grant_id"
    library_items o|--o{ credit_transactions : "item_id"
    users ||--o{ credit_transactions : "user_id"
    users ||--o| library_credit_balances : "user_id"
    library_items ||--o{ library_unlocks : "item_id"
    users ||--o{ library_unlocks : "user_id"
```

### plans

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| slug | character varying(255) | No | UQ |
| label | character varying(255) | Yes |  |
| name | character varying(255) | No |  |
| price_cents | integer | No |  |
| currency | character varying(8) | No |  |
| interval | character varying(16) | No |  |
| price_suffix | character varying(255) | Yes |  |
| description | text | Yes |  |
| is_recommended | boolean | No |  |
| order | integer | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

### plan_benefits

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| plan_id | uuid | No | FK |
| label | character varying(255) | No |  |
| order | integer | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- plan_id → plans(id) · on delete: cascade

### subscriptions

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| user_id | uuid | No | FK |
| plan_id | uuid | No | FK |
| status | character varying(16) | No |  |
| price_cents | integer | No |  |
| currency | character varying(8) | No |  |
| interval | character varying(16) | No |  |
| started_at | date | Yes |  |
| started_label | character varying(255) | Yes |  |
| renews_at | date | Yes |  |
| renews_label | character varying(255) | Yes |  |
| cancelled_at | date | Yes |  |
| max_horses | smallint | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| paid_through | timestamp(0) with time zone | Yes |  |
| cancel_requested_at | timestamp(0) with time zone | Yes |  |
| ended_at | timestamp(0) with time zone | Yes |  |

- plan_id → plans(id) · on delete: restrict

- user_id → users(id) · on delete: cascade

### payments

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| subscription_id | uuid | No | FK |
| date | date | No |  |
| date_label | character varying(255) | Yes |  |
| amount_cents | integer | No |  |
| amount_label | character varying(255) | Yes |  |
| currency | character varying(8) | No |  |
| status | character varying(16) | No |  |
| receipt_url | character varying(255) | Yes |  |
| order | integer | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- subscription_id → subscriptions(id) · on delete: cascade

### protocol_purchases

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| user_id | uuid | No | FK |
| horse_id | uuid | Yes | FK |
| protocol_id | uuid | Yes | FK, UQ |
| request_key | uuid | No |  |
| payment_reference | character varying(255) | Yes | UQ |
| amount_cents | integer | No |  |
| currency | character(3) | No |  |
| fulfilled_at | timestamp(0) with time zone | Yes |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- horse_id → horses(id) · on delete: set null

- protocol_id → protocols(id) · on delete: set null

- user_id → users(id) · on delete: cascade

### plus_intake_entitlements

| Column | Type | Nullable | Key |
|---|---|---|---|
| user_id | uuid | No | PK, FK |
| horse_id | uuid | Yes | FK |
| booking_id | uuid | No | UQ |
| subscription_id | uuid | Yes | FK |
| test_bypass | boolean | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- horse_id → horses(id) · on delete: set null

- subscription_id → subscriptions(id) · on delete: set null

- user_id → users(id) · on delete: cascade

### plus_upgrades

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| user_id | uuid | No | FK |
| horse_id | uuid | No | FK |
| payment_status | character varying(255) | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- horse_id → horses(id) · on delete: cascade

- user_id → users(id) · on delete: cascade

### plus_pages

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | character varying(255) | No | PK |
| content | json | No |  |
| hero_path | character varying(255) | Yes |  |
| portrait_path | character varying(255) | Yes |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| protocol_price_cents | integer | No |  |

### credit_bundles

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| label | character varying(255) | Yes |  |
| credits | integer | No |  |
| price_cents | integer | No |  |
| currency | character varying(3) | No |  |
| active | boolean | No |  |
| order | integer | No |  |
| created_at | timestamp(0) with time zone | Yes |  |
| updated_at | timestamp(0) with time zone | Yes |  |

### credit_grants

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| user_id | uuid | No | FK |
| source | character varying(255) | No |  |
| subscription_id | uuid | Yes | FK |
| order_id | uuid | Yes | FK, UQ |
| expires_at | timestamp(0) with time zone | Yes |  |
| created_at | timestamp(0) with time zone | Yes |  |
| updated_at | timestamp(0) with time zone | Yes |  |

- order_id → credit_orders(id) · on delete: set null

- subscription_id → subscriptions(id) · on delete: set null

- user_id → users(id) · on delete: cascade

### credit_orders

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| user_id | uuid | No | FK |
| bundle_id | uuid | Yes | FK |
| subscription_id | uuid | Yes | FK |
| item_id | uuid | Yes | FK |
| kind | character varying(255) | No |  |
| status | character varying(255) | No |  |
| credits | integer | No |  |
| price_cents | integer | No |  |
| currency | character varying(3) | No |  |
| validity_months | integer | No |  |
| provider | character varying(255) | Yes |  |
| payment_id | character varying(255) | Yes | UQ |
| checkout_url | text | Yes |  |
| request_key | uuid | No |  |
| paid_at | timestamp(0) with time zone | Yes |  |
| period_end | timestamp(0) with time zone | Yes |  |
| reversed_at | timestamp(0) with time zone | Yes |  |
| spent_before_reversal | integer | No |  |
| created_at | timestamp(0) with time zone | Yes |  |
| updated_at | timestamp(0) with time zone | Yes |  |

- bundle_id → credit_bundles(id) · on delete: set null

- item_id → library_items(id) · on delete: set null

- subscription_id → subscriptions(id) · on delete: set null

- user_id → users(id) · on delete: cascade

### credit_reminders

| Column | Type | Nullable | Key |
|---|---|---|---|
| grant_id | uuid | No | PK, FK |
| days | integer | No | PK |
| sent_at | timestamp(0) with time zone | No |  |
| ticket_id | character varying(255) | Yes |  |
| push_token | character varying(255) | Yes |  |
| receipt_checked_at | timestamp(0) with time zone | Yes |  |
| receipt_error | character varying(255) | Yes |  |

- grant_id → credit_grants(id) · on delete: cascade

### credit_settings

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | integer | No | PK |
| monthly_credits | integer | No |  |
| membership_cap | integer | No |  |
| purchase_validity_months | integer | No |  |

### credit_transactions

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| user_id | uuid | No | FK |
| grant_id | uuid | No | FK |
| amount | integer | No |  |
| type | character varying(255) | No |  |
| source | character varying(255) | No |  |
| expires_at | timestamp(0) with time zone | Yes |  |
| payment_id | character varying(255) | Yes |  |
| item_id | uuid | Yes | FK |
| description | character varying(255) | No |  |
| reason | text | Yes |  |
| created_at | timestamp(0) with time zone | No |  |

- grant_id → credit_grants(id) · on delete: cascade

- item_id → library_items(id) · on delete: set null

- user_id → users(id) · on delete: cascade

### library_credit_balances

| Column | Type | Nullable | Key |
|---|---|---|---|
| user_id | uuid | No | PK, FK |
| balance | integer | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- user_id → users(id) · on delete: cascade

### library_unlocks

| Column | Type | Nullable | Key |
|---|---|---|---|
| user_id | uuid | No | PK, FK |
| item_id | uuid | No | PK, FK |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- item_id → library_items(id) · on delete: cascade

- user_id → users(id) · on delete: cascade

## Library

```mermaid
erDiagram
    library_items {
        uuid id PK
        varchar slug UK
        varchar format
        varchar title
        text description
        text body
        varchar hero_image_url
        varchar duration_label
        int4 duration_sec
        uuid author_therapist_id FK
        varchar views_label
        timestamp published_at
        bool is_plus
        bool is_featured
        int4 order
        timestamp created_at
        timestamp updated_at
        int4 credit_cost
        varchar thumbnail_mode
        json featured_suggestion_ids
    }
    library_contents {
        uuid id PK, FK
        text body
        jsonb chapters
        jsonb attachments
        timestamp updated_at
    }
    library_categories {
        uuid id PK
        varchar slug UK
        varchar label
        int4 order
        bool is_default
        timestamp created_at
        timestamp updated_at
        bool is_quick_filter
    }
    library_item_categories {
        uuid id PK
        uuid item_id FK
        uuid category_id FK
        timestamp created_at
        timestamp updated_at
    }
    library_chapters {
        uuid id PK
        uuid item_id FK
        int4 order
        varchar title
        varchar start_label
        int4 start_sec
        timestamp created_at
        timestamp updated_at
    }
    library_article_sections {
        uuid id PK
        uuid item_id FK
        int4 order
        varchar heading
        text body
        timestamp created_at
        timestamp updated_at
    }
    library_attachments {
        uuid id PK
        uuid library_item_id FK
        varchar title
        varchar name
        varchar path
        int8 size_bytes
        int4 order
        timestamp created_at
        timestamp updated_at
    }
    library_item_access {
        uuid id PK
        uuid user_id FK
        uuid item_id FK
        varchar reason
        timestamp expires_at
        timestamp updated_at
    }
    library_bookmarks {
        uuid id PK
        uuid user_id FK
        uuid item_id FK
        timestamp created_at
        timestamp updated_at
    }
    library_progress {
        uuid id PK
        uuid user_id FK
        uuid item_id FK
        int4 position_sec
        numeric progress
        bool completed
        timestamp last_viewed_at
        timestamp created_at
        timestamp updated_at
    }
    seasonal_tips {
        uuid id PK
        varchar month
        int2 month_order
        text body
        uuid cta_item_id FK
        bool active
        date active_from
        date active_to
        timestamp created_at
        timestamp updated_at
        varchar title
    }
    therapists o|--o{ library_items : "author_therapist_id"
    library_items ||--o| library_contents : "id"
    library_categories ||--o{ library_item_categories : "category_id"
    library_items ||--o{ library_item_categories : "item_id"
    library_items ||--o{ library_chapters : "item_id"
    library_items ||--o{ library_article_sections : "item_id"
    library_items ||--o{ library_attachments : "library_item_id"
    library_items ||--o{ library_item_access : "item_id"
    users ||--o{ library_item_access : "user_id"
    library_items ||--o{ library_bookmarks : "item_id"
    users ||--o{ library_bookmarks : "user_id"
    library_items ||--o{ library_progress : "item_id"
    users ||--o{ library_progress : "user_id"
    library_items o|--o{ seasonal_tips : "cta_item_id"
```

### library_items

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| slug | character varying(255) | No | UQ |
| format | character varying(16) | No |  |
| title | character varying(255) | No |  |
| description | text | Yes |  |
| body | text | Yes |  |
| hero_image_url | character varying(255) | Yes |  |
| duration_label | character varying(255) | Yes |  |
| duration_sec | integer | Yes |  |
| author_therapist_id | uuid | Yes | FK |
| views_label | character varying(255) | Yes |  |
| published_at | timestamp(0) without time zone | Yes |  |
| is_plus | boolean | No |  |
| is_featured | boolean | No |  |
| order | integer | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| credit_cost | integer | No |  |
| thumbnail_mode | character varying(16) | No |  |
| featured_suggestion_ids | json | Yes |  |

- author_therapist_id → therapists(id) · on delete: set null

### library_contents

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK, FK |
| body | text | Yes |  |
| chapters | jsonb | No |  |
| attachments | jsonb | No |  |
| updated_at | timestamp(0) without time zone | No |  |

- id → library_items(id) · on delete: cascade

### library_categories

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| slug | character varying(255) | No | UQ |
| label | character varying(255) | No |  |
| order | integer | No |  |
| is_default | boolean | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| is_quick_filter | boolean | No |  |

### library_item_categories

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| item_id | uuid | No | FK |
| category_id | uuid | No | FK |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- category_id → library_categories(id) · on delete: cascade

- item_id → library_items(id) · on delete: cascade

### library_chapters

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| item_id | uuid | No | FK |
| order | integer | No |  |
| title | character varying(255) | No |  |
| start_label | character varying(16) | Yes |  |
| start_sec | integer | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- item_id → library_items(id) · on delete: cascade

### library_article_sections

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| item_id | uuid | No | FK |
| order | integer | No |  |
| heading | character varying(255) | Yes |  |
| body | text | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- item_id → library_items(id) · on delete: cascade

### library_attachments

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| library_item_id | uuid | No | FK |
| title | character varying(255) | No |  |
| name | character varying(255) | No |  |
| path | character varying(255) | No |  |
| size_bytes | bigint | No |  |
| order | integer | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- library_item_id → library_items(id) · on delete: cascade

### library_item_access

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| user_id | uuid | No | FK |
| item_id | uuid | No | FK |
| reason | character varying(16) | No |  |
| expires_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | No |  |

- item_id → library_items(id) · on delete: cascade

- user_id → users(id) · on delete: cascade

### library_bookmarks

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| user_id | uuid | No | FK |
| item_id | uuid | No | FK |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- item_id → library_items(id) · on delete: cascade

- user_id → users(id) · on delete: cascade

### library_progress

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| user_id | uuid | No | FK |
| item_id | uuid | No | FK |
| position_sec | integer | No |  |
| progress | numeric(5,4) | No |  |
| completed | boolean | No |  |
| last_viewed_at | timestamp(0) without time zone | Yes |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- item_id → library_items(id) · on delete: cascade

- user_id → users(id) · on delete: cascade

### seasonal_tips

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| month | character varying(16) | No |  |
| month_order | smallint | No |  |
| body | text | No |  |
| cta_item_id | uuid | Yes | FK |
| active | boolean | No |  |
| active_from | date | Yes |  |
| active_to | date | Yes |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| title | character varying(255) | Yes |  |

- cta_item_id → library_items(id) · on delete: set null

## Community and moderation

```mermaid
erDiagram
    community_posts {
        uuid id PK
        uuid author_user_id FK
        varchar author_name
        varchar author_initial
        varchar author_avatar_color
        text body
        timestamp created_at
        timestamp updated_at
        varchar when_label
        int4 likes_count
        int4 replies_count
        bool has_expert_reply
        uuid category_id FK
        int4 order
        varchar moderation_status
        timestamp reviewed_at
        timestamp edited_at
    }
    community_replies {
        uuid id PK
        uuid post_id FK
        uuid author_user_id FK
        uuid author_therapist_id FK
        varchar author_name
        varchar author_initial
        varchar author_avatar_color
        bool author_is_expert
        text body
        varchar when_label
        int4 likes_count
        int4 replies_count
        int4 order
        timestamp created_at
        timestamp updated_at
        varchar moderation_status
        timestamp reviewed_at
        uuid parent_reply_id FK
        timestamp edited_at
    }
    community_reactions {
        uuid id PK
        uuid user_id FK
        varchar target_type
        uuid target_id
        varchar kind
        timestamp created_at
        timestamp updated_at
    }
    community_categories {
        uuid id PK
        varchar slug UK
        varchar label
        int4 order
        bool is_default
        timestamp created_at
        timestamp updated_at
    }
    community_tags {
        uuid id PK
        varchar slug UK
        varchar label
        timestamp created_at
        timestamp updated_at
    }
    community_post_tags {
        uuid id PK
        uuid post_id FK
        uuid tag_id FK
        int4 order
        timestamp created_at
        timestamp updated_at
    }
    community_media {
        uuid id PK
        uuid post_id FK
        varchar path
        varchar mime_type
        int8 size
        int4 order
        timestamp created_at
        timestamp updated_at
    }
    community_bookmarks {
        uuid user_id PK, FK
        uuid post_id PK, FK
        timestamp created_at
    }
    community_mutes {
        uuid user_id PK, FK
        varchar target_type PK
        uuid target_id PK
        timestamp created_at
    }
    moderation_reports {
        uuid id PK
        varchar subject_type
        uuid subject_id
        uuid reporter_user_id FK
        varchar reason
        text detail
        varchar status
        uuid resolved_by FK
        timestamp resolved_at
        timestamp created_at
        timestamp updated_at
    }
    user_restrictions {
        uuid id PK
        uuid user_id FK
        varchar type
        text reason
        timestamp expires_at
        uuid created_by FK
        timestamp lifted_at
        timestamp created_at
        timestamp updated_at
    }
    users o|--o{ community_posts : "author_user_id"
    community_categories o|--o{ community_posts : "category_id"
    therapists o|--o{ community_replies : "author_therapist_id"
    users o|--o{ community_replies : "author_user_id"
    community_replies o|--o{ community_replies : "parent_reply_id"
    community_posts ||--o{ community_replies : "post_id"
    users ||--o{ community_reactions : "user_id"
    community_posts ||--o{ community_post_tags : "post_id"
    community_tags ||--o{ community_post_tags : "tag_id"
    community_posts ||--o{ community_media : "post_id"
    community_posts ||--o{ community_bookmarks : "post_id"
    users ||--o{ community_bookmarks : "user_id"
    users ||--o{ community_mutes : "user_id"
    users o|--o{ moderation_reports : "reporter_user_id"
    admin_users o|--o{ moderation_reports : "resolved_by"
    admin_users o|--o{ user_restrictions : "created_by"
    users ||--o{ user_restrictions : "user_id"
```

### community_posts

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| author_user_id | uuid | Yes | FK |
| author_name | character varying(255) | Yes |  |
| author_initial | character varying(4) | Yes |  |
| author_avatar_color | character varying(16) | Yes |  |
| body | text | No |  |
| created_at | timestamp(0) without time zone | No |  |
| updated_at | timestamp(0) without time zone | No |  |
| when_label | character varying(255) | Yes |  |
| likes_count | integer | No |  |
| replies_count | integer | No |  |
| has_expert_reply | boolean | No |  |
| category_id | uuid | Yes | FK |
| order | integer | No |  |
| moderation_status | character varying(16) | No |  |
| reviewed_at | timestamp(0) without time zone | Yes |  |
| edited_at | timestamp(0) without time zone | Yes |  |

- author_user_id → users(id) · on delete: set null

- category_id → community_categories(id) · on delete: set null

### community_replies

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| post_id | uuid | No | FK |
| author_user_id | uuid | Yes | FK |
| author_therapist_id | uuid | Yes | FK |
| author_name | character varying(255) | Yes |  |
| author_initial | character varying(4) | Yes |  |
| author_avatar_color | character varying(16) | Yes |  |
| author_is_expert | boolean | No |  |
| body | text | No |  |
| when_label | character varying(255) | Yes |  |
| likes_count | integer | No |  |
| replies_count | integer | No |  |
| order | integer | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| moderation_status | character varying(16) | No |  |
| reviewed_at | timestamp(0) without time zone | Yes |  |
| parent_reply_id | uuid | Yes | FK |
| edited_at | timestamp(0) without time zone | Yes |  |

- author_therapist_id → therapists(id) · on delete: set null

- author_user_id → users(id) · on delete: set null

- parent_reply_id → community_replies(id) · on delete: set null

- post_id → community_posts(id) · on delete: cascade

### community_reactions

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| user_id | uuid | No | FK |
| target_type | character varying(16) | No |  |
| target_id | uuid | No |  |
| kind | character varying(16) | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- user_id → users(id) · on delete: cascade

### community_categories

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| slug | character varying(255) | No | UQ |
| label | character varying(255) | No |  |
| order | integer | No |  |
| is_default | boolean | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

### community_tags

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| slug | character varying(255) | No | UQ |
| label | character varying(255) | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

### community_post_tags

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| post_id | uuid | No | FK |
| tag_id | uuid | No | FK |
| order | integer | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- post_id → community_posts(id) · on delete: cascade

- tag_id → community_tags(id) · on delete: cascade

### community_media

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| post_id | uuid | No | FK |
| path | character varying(255) | No |  |
| mime_type | character varying(255) | No |  |
| size | bigint | No |  |
| order | integer | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- post_id → community_posts(id) · on delete: cascade

### community_bookmarks

| Column | Type | Nullable | Key |
|---|---|---|---|
| user_id | uuid | No | PK, FK |
| post_id | uuid | No | PK, FK |
| created_at | timestamp(0) without time zone | No |  |

- post_id → community_posts(id) · on delete: cascade

- user_id → users(id) · on delete: cascade

### community_mutes

| Column | Type | Nullable | Key |
|---|---|---|---|
| user_id | uuid | No | PK, FK |
| target_type | character varying(16) | No | PK |
| target_id | uuid | No | PK |
| created_at | timestamp(0) without time zone | No |  |

- user_id → users(id) · on delete: cascade

### moderation_reports

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| subject_type | character varying(16) | No |  |
| subject_id | uuid | No |  |
| reporter_user_id | uuid | Yes | FK |
| reason | character varying(64) | No |  |
| detail | text | Yes |  |
| status | character varying(16) | No |  |
| resolved_by | uuid | Yes | FK |
| resolved_at | timestamp(0) without time zone | Yes |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- reporter_user_id → users(id) · on delete: set null

- resolved_by → admin_users(id) · on delete: set null

### user_restrictions

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| user_id | uuid | No | FK |
| type | character varying(16) | No |  |
| reason | text | Yes |  |
| expires_at | timestamp(0) without time zone | Yes |  |
| created_by | uuid | Yes | FK |
| lifted_at | timestamp(0) without time zone | Yes |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- created_by → admin_users(id) · on delete: set null

- user_id → users(id) · on delete: cascade

## Products scans and chat

```mermaid
erDiagram
    products {
        uuid id PK
        varchar brand
        varchar name
        varchar barcode UK
        varchar category
        timestamp created_at
        timestamp updated_at
        bool needs_review
    }
    ingredients {
        uuid id PK
        varchar name UK
        text description
        varchar default_tag
        timestamp created_at
        timestamp updated_at
        bool needs_review
    }
    ingredient_product {
        uuid product_id PK, FK
        uuid ingredient_id PK, FK
        int4 order
        varchar amount
        timestamp created_at
        timestamp updated_at
    }
    scan_results {
        uuid id PK
        uuid user_id FK
        uuid horse_id FK
        uuid product_id FK
        varchar product_name
        varchar brand
        timestamp scanned_at
        varchar when_label
        int2 score
        varchar rating
        text advice
        varchar photo_url
        bool bookmarked
        timestamp created_at
        timestamp updated_at
        bool flagged
    }
    scan_ingredients {
        uuid id PK
        uuid scan_id FK
        uuid ingredient_id FK
        varchar name
        varchar tag
        text description
        int4 order
        timestamp created_at
        timestamp updated_at
    }
    chat_sessions {
        uuid id PK
        uuid user_id FK
        uuid horse_id FK
        timestamp started_at
        timestamp created_at
        timestamp updated_at
    }
    chat_messages {
        uuid id PK
        uuid session_id FK
        varchar role
        text body
        int4 order
        timestamp created_at
        timestamp updated_at
    }
    nova_fallback_replies {
        uuid id PK
        text body
        int4 order
        timestamp created_at
        timestamp updated_at
    }
    ingredients ||--o{ ingredient_product : "ingredient_id"
    products ||--o{ ingredient_product : "product_id"
    horses o|--o{ scan_results : "horse_id"
    products o|--o{ scan_results : "product_id"
    users ||--o{ scan_results : "user_id"
    ingredients o|--o{ scan_ingredients : "ingredient_id"
    scan_results ||--o{ scan_ingredients : "scan_id"
    horses o|--o{ chat_sessions : "horse_id"
    users ||--o{ chat_sessions : "user_id"
    chat_sessions ||--o{ chat_messages : "session_id"
```

### products

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| brand | character varying(255) | No |  |
| name | character varying(255) | No |  |
| barcode | character varying(255) | Yes | UQ |
| category | character varying(255) | Yes |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| needs_review | boolean | No |  |

### ingredients

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| name | character varying(255) | No | UQ |
| description | text | Yes |  |
| default_tag | character varying(16) | Yes |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| needs_review | boolean | No |  |

### ingredient_product

| Column | Type | Nullable | Key |
|---|---|---|---|
| product_id | uuid | No | PK, FK |
| ingredient_id | uuid | No | PK, FK |
| order | integer | No |  |
| amount | character varying(255) | Yes |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- ingredient_id → ingredients(id) · on delete: cascade

- product_id → products(id) · on delete: cascade

### scan_results

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| user_id | uuid | No | FK |
| horse_id | uuid | Yes | FK |
| product_id | uuid | Yes | FK |
| product_name | character varying(255) | Yes |  |
| brand | character varying(255) | Yes |  |
| scanned_at | timestamp(0) without time zone | No |  |
| when_label | character varying(255) | Yes |  |
| score | smallint | No |  |
| rating | character varying(16) | No |  |
| advice | text | Yes |  |
| photo_url | character varying(255) | Yes |  |
| bookmarked | boolean | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| flagged | boolean | No |  |

- horse_id → horses(id) · on delete: set null

- product_id → products(id) · on delete: set null

- user_id → users(id) · on delete: cascade

### scan_ingredients

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| scan_id | uuid | No | FK |
| ingredient_id | uuid | Yes | FK |
| name | character varying(255) | No |  |
| tag | character varying(16) | No |  |
| description | text | Yes |  |
| order | integer | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- ingredient_id → ingredients(id) · on delete: set null

- scan_id → scan_results(id) · on delete: cascade

### chat_sessions

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| user_id | uuid | No | FK |
| horse_id | uuid | Yes | FK |
| started_at | timestamp(0) without time zone | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- horse_id → horses(id) · on delete: set null

- user_id → users(id) · on delete: cascade

### chat_messages

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| session_id | uuid | No | FK |
| role | character varying(16) | No |  |
| body | text | No |  |
| order | integer | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- session_id → chat_sessions(id) · on delete: cascade

### nova_fallback_replies

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| body | text | No |  |
| order | integer | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

## Notifications administration and infrastructure

```mermaid
erDiagram
    web_push_subscriptions {
        varchar endpoint_hash PK
        uuid user_id FK
        text subscription
        timestamp created_at
        timestamp updated_at
    }
    intake_reminder_deliveries {
        int8 id PK
        uuid booking_id FK
        varchar cycle
        varchar destination
        timestamptz sent_at
    }
    protocol_phase_reminders {
        int8 id PK
        uuid protocol_phase_id FK
        uuid user_id FK
        date available_on
        varchar ticket_id
        timestamp sent_at
    }
    browser_reminder_deliveries {
        int8 id PK
        uuid user_id FK
        varchar event_key
        varchar destination
        timestamptz sent_at
    }
    admin_users {
        uuid id PK
        varchar name
        varchar email UK
        timestamp email_verified_at
        varchar password
        varchar role
        bool active
        varchar remember_token
        timestamp created_at
        timestamp updated_at
    }
    audit_logs {
        uuid id PK
        uuid admin_user_id FK
        varchar actor_name
        varchar action
        varchar target_type
        uuid target_id
        varchar target_label
        json before
        json after
        text reason
        varchar ip_address
        varchar user_agent
        timestamp created_at
    }
    media_assets {
        uuid id PK
        uuid library_item_id FK
        uuid uploaded_by FK
        varchar type
        varchar disk
        varchar path
        varchar url
        varchar original_name
        varchar mime_type
        int8 size_bytes
        int4 width
        int4 height
        timestamp created_at
        timestamp updated_at
        varchar thumbnail_path
        varchar thumbnail_url
    }
    sessions {
        varchar id PK
        uuid user_id
        varchar ip_address
        text user_agent
        text payload
        int4 last_activity
    }
    password_reset_tokens {
        varchar email PK
        varchar token
        timestamp created_at
    }
    cache {
        varchar key PK
        text value
        int8 expiration
    }
    cache_locks {
        varchar key PK
        varchar owner
        int8 expiration
    }
    jobs {
        int8 id PK
        varchar queue
        text payload
        int2 attempts
        int4 reserved_at
        int4 available_at
        int4 created_at
    }
    job_batches {
        varchar id PK
        varchar name
        int4 total_jobs
        int4 pending_jobs
        int4 failed_jobs
        text failed_job_ids
        text options
        int4 cancelled_at
        int4 created_at
        int4 finished_at
    }
    failed_jobs {
        int8 id PK
        varchar uuid UK
        varchar connection
        varchar queue
        text payload
        text exception
        timestamp failed_at
    }
    migrations {
        int4 id PK
        varchar migration
        int4 batch
    }
    users ||--o{ web_push_subscriptions : "user_id"
    intake_bookings ||--o{ intake_reminder_deliveries : "booking_id"
    protocol_phases ||--o{ protocol_phase_reminders : "protocol_phase_id"
    users ||--o{ protocol_phase_reminders : "user_id"
    users ||--o{ browser_reminder_deliveries : "user_id"
    admin_users o|--o{ audit_logs : "admin_user_id"
    library_items o|--o{ media_assets : "library_item_id"
    admin_users o|--o{ media_assets : "uploaded_by"
```

### web_push_subscriptions

| Column | Type | Nullable | Key |
|---|---|---|---|
| endpoint_hash | character varying(64) | No | PK |
| user_id | uuid | No | FK |
| subscription | text | No |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

- user_id → users(id) · on delete: cascade

### intake_reminder_deliveries

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | bigint | No | PK |
| booking_id | uuid | No | FK |
| cycle | character varying(64) | No |  |
| destination | character varying(64) | No |  |
| sent_at | timestamp(0) with time zone | No |  |

- booking_id → intake_bookings(id) · on delete: cascade

### protocol_phase_reminders

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | bigint | No | PK |
| protocol_phase_id | uuid | No | FK |
| user_id | uuid | No | FK |
| available_on | date | No |  |
| ticket_id | character varying(255) | Yes |  |
| sent_at | timestamp(0) without time zone | No |  |

- protocol_phase_id → protocol_phases(id) · on delete: cascade

- user_id → users(id) · on delete: cascade

### browser_reminder_deliveries

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | bigint | No | PK |
| user_id | uuid | No | FK |
| event_key | character varying(64) | No |  |
| destination | character varying(64) | No |  |
| sent_at | timestamp(0) with time zone | No |  |

- user_id → users(id) · on delete: cascade

### admin_users

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| name | character varying(255) | No |  |
| email | character varying(255) | No | UQ |
| email_verified_at | timestamp(0) without time zone | Yes |  |
| password | character varying(255) | No |  |
| role | character varying(32) | No |  |
| active | boolean | No |  |
| remember_token | character varying(100) | Yes |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |

### audit_logs

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| admin_user_id | uuid | Yes | FK |
| actor_name | character varying(255) | Yes |  |
| action | character varying(64) | No |  |
| target_type | character varying(255) | Yes |  |
| target_id | uuid | Yes |  |
| target_label | character varying(255) | Yes |  |
| before | json | Yes |  |
| after | json | Yes |  |
| reason | text | Yes |  |
| ip_address | character varying(45) | Yes |  |
| user_agent | character varying(255) | Yes |  |
| created_at | timestamp(0) without time zone | No |  |

- admin_user_id → admin_users(id) · on delete: set null

### media_assets

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | uuid | No | PK |
| library_item_id | uuid | Yes | FK |
| uploaded_by | uuid | Yes | FK |
| type | character varying(8) | No |  |
| disk | character varying(32) | No |  |
| path | character varying(255) | No |  |
| url | character varying(255) | No |  |
| original_name | character varying(255) | No |  |
| mime_type | character varying(128) | No |  |
| size_bytes | bigint | No |  |
| width | integer | Yes |  |
| height | integer | Yes |  |
| created_at | timestamp(0) without time zone | Yes |  |
| updated_at | timestamp(0) without time zone | Yes |  |
| thumbnail_path | character varying(255) | Yes |  |
| thumbnail_url | character varying(255) | Yes |  |

- library_item_id → library_items(id) · on delete: set null

- uploaded_by → admin_users(id) · on delete: set null

### sessions

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | character varying(255) | No | PK |
| user_id | uuid | Yes |  |
| ip_address | character varying(45) | Yes |  |
| user_agent | text | Yes |  |
| payload | text | No |  |
| last_activity | integer | No |  |

### password_reset_tokens

| Column | Type | Nullable | Key |
|---|---|---|---|
| email | character varying(255) | No | PK |
| token | character varying(255) | No |  |
| created_at | timestamp(0) without time zone | Yes |  |

### cache

| Column | Type | Nullable | Key |
|---|---|---|---|
| key | character varying(255) | No | PK |
| value | text | No |  |
| expiration | bigint | No |  |

### cache_locks

| Column | Type | Nullable | Key |
|---|---|---|---|
| key | character varying(255) | No | PK |
| owner | character varying(255) | No |  |
| expiration | bigint | No |  |

### jobs

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | bigint | No | PK |
| queue | character varying(255) | No |  |
| payload | text | No |  |
| attempts | smallint | No |  |
| reserved_at | integer | Yes |  |
| available_at | integer | No |  |
| created_at | integer | No |  |

### job_batches

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | character varying(255) | No | PK |
| name | character varying(255) | No |  |
| total_jobs | integer | No |  |
| pending_jobs | integer | No |  |
| failed_jobs | integer | No |  |
| failed_job_ids | text | No |  |
| options | text | Yes |  |
| cancelled_at | integer | Yes |  |
| created_at | integer | No |  |
| finished_at | integer | Yes |  |

### failed_jobs

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | bigint | No | PK |
| uuid | character varying(255) | No | UQ |
| connection | character varying(255) | No |  |
| queue | character varying(255) | No |  |
| payload | text | No |  |
| exception | text | No |  |
| failed_at | timestamp(0) without time zone | No |  |

### migrations

| Column | Type | Nullable | Key |
|---|---|---|---|
| id | integer | No | PK |
| migration | character varying(255) | No |  |
| batch | integer | No |  |

## Application-level references

Legacy entitlement `booking_id` deliberately retains the consumed intake identifier without a foreign key, including after deletion. Intake answer `section_id` and `field_id` refer to stable questionnaire keys. Polymorphic type/id pairs and JSON payloads resolve in application code; they are not shown as enforced database relationships.
