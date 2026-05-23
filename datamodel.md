# EquiNova — Data Model

Derived from the Expo frontend in `expo-app/`. Each screen / mock structure was mapped to one or more persisted entities. Field names are suggestions; types are indicative. All entities have implicit `id`, `createdAt`, `updatedAt` unless noted.

---

## 1. Identity & Account

### `User`
The owner of the account (e.g. Marit in the prototype).

| Field            | Type         | Notes                                                   |
|------------------|--------------|---------------------------------------------------------|
| id               | uuid (PK)    |                                                         |
| email            | string       | unique                                                  |
| name             | string       | "Marit van der Berg"                                    |
| avatarInitial    | string(1)    | shown in AppHeader / Avatar                             |
| avatarUrl        | string?      | optional uploaded image                                 |
| locale           | string       | i18n (`nl-NL`)                                          |
| unitsSystem      | enum         | `metric` / `imperial` (Voorkeuren)                      |
| notificationsOn  | boolean      | toggled in Meldingen settings                           |
| onboardedAt      | timestamp?   | mirrors AsyncStorage `equinova:onboarded` flag          |
| createdAt        | timestamp    | "Sinds april 2026"                                      |

### `Therapist`
Experts that can be linked to a horse (Shelley = built-in therapist; community badge "Therapeut").

| Field         | Type     | Notes                       |
|---------------|----------|-----------------------------|
| id            | uuid     |                             |
| name          | string   | "Shelley"                   |
| title         | string   | "De Paardentherapeut"       |
| bio           | text     |                             |
| avatarUrl     | string?  |                             |
| verified      | boolean  | shows "Therapeut" pill      |

---

## 2. Horse Domain

### `Horse`
Created in onboarding (`add-horse.tsx`) and listed in `my-horses.tsx` / `horse-profile.tsx`.

| Field        | Type      | Notes                                              |
|--------------|-----------|----------------------------------------------------|
| id           | uuid      |                                                    |
| ownerId      | FK→User   |                                                    |
| name         | string    | "Nova"                                             |
| breed        | string    | "Friese kruising"                                  |
| birthYear    | int       | derived from age input                             |
| sex          | enum      | `merrie` / `ruin` / `hengst`                       |
| weightKg     | int       | 540                                                |
| stable       | string    | "Manege De Hoeve · Box 4"                          |
| photoUrl     | string?   |                                                    |
| status       | enum      | `active` / `archived`                              |
| archivedAt   | timestamp?| "in 2024 overleden"                                |
| archivedNote | string?   | "Gearchiveerd · in 2024 overleden"                 |

### `HorseFocus` (many-to-many `Horse` ↔ `FocusTopic`)
Selected on the onboarding `focus.tsx` screen and shown on horse profile / library filters.

| Field        | Type        |
|--------------|-------------|
| horseId      | FK→Horse    |
| focusTopicId | FK→FocusTopic |
| addedAt      | timestamp   |

### `FocusTopic` (reference table)
From `FOCUS_OPTIONS`.

| id      | icon | title           | description                  |
|---------|------|-----------------|------------------------------|
| jeuk    | 🌿   | Jeukklachten    | Huid, manen, staart          |
| staak   | 🐎   | Staakgedrag     | Onder zadel of in stal       |
| darm    | 💧   | Darmproblemen   | Mest, kolieken, gas          |
| prev    | ✨   | Preventief      | Geen klachten, wel meer weten|
| and     | ✦    | Iets anders     | Vrij veld                    |

### `HorseShare`
"Gedeeld met" rows on `my-horses.tsx` — gives another user/therapist access.

| Field         | Type       | Notes                                  |
|---------------|------------|----------------------------------------|
| horseId       | FK→Horse   |                                        |
| granteeUserId | FK→User?   | regular co-carer (Lisanne)             |
| therapistId   | FK→Therapist? | links Shelley                       |
| role          | enum       | `full` (therapist) / `read_only`       |
| since         | timestamp  | "sinds maart"                          |

### `HorseStat` (timeseries snapshot)
Top-of-profile stats card (gewicht / energie / mest-score) — needs history to render trend ("↑ +1 deze week").

| Field      | Type      | Notes                       |
|------------|-----------|-----------------------------|
| horseId    | FK→Horse  |                             |
| measuredAt | date      |                             |
| weightKg   | int?      |                             |
| energy     | int?      | 1–10 scale                  |
| stoolScore | enum      | A / B+ / B / C / D          |

---

## 3. Protocol & Daily Plan

### `Protocol`
A multi-phase plan for a horse (`PROTOCOL_META`, `PROTOCOL_PHASES`).

| Field          | Type      | Notes                                            |
|----------------|-----------|--------------------------------------------------|
| id             | uuid      |                                                  |
| horseId        | FK→Horse  |                                                  |
| therapistId    | FK→Therapist? | author                                       |
| title          | string    | "Jeuk / Zomereczeem"                             |
| subtitle       | string    | "KWPN merrie · Jeuk / Zomereczeem"               |
| totalWeeks     | int       | 8                                                |
| currentWeek    | int       | derived                                          |
| startedAt      | date      |                                                  |
| status         | enum      | `active` / `paused` / `completed`                |

### `ProtocolPhase`
From `PROTOCOL_PHASES`.

| Field      | Type           | Notes                                  |
|------------|----------------|----------------------------------------|
| id         | uuid           |                                        |
| protocolId | FK→Protocol    |                                        |
| order      | int            | 0..n                                   |
| title      | string         | "Fase 1 — Darmen"                      |
| state      | enum           | `done` / `active` / `upcoming`         |
| weekStart  | int            | 1                                      |
| weekEnd    | int            | 4                                      |
| chipLabel  | string         | "Actief · wk 1–4"                      |

### `ProtocolAnalysis`
One-to-one with `Protocol`. Powers the "analyse" tab (`PROTOCOL_ANALYSE`).

| Field     | Type        | Notes                                       |
|-----------|-------------|---------------------------------------------|
| protocolId| FK→Protocol |                                             |
| cause     | text        | "Nova heeft tekenen van …"                  |

### `ProtocolAdvice`
Children of `ProtocolAnalysis` — Voeding / Management / Training cards.

| Field      | Type     | Notes                                  |
|------------|----------|----------------------------------------|
| analysisId | FK       |                                        |
| iconKey    | enum     | `leaf` / `run` / `horse`               |
| title      | string   | "Voeding"                              |
| body       | text     |                                        |
| order      | int      |                                        |

### `ProtocolTask` (recurring instruction)
A repeating instruction that belongs to a phase (e.g. "1 el brandnetel door ruwvoer"). Items in `PROTOCOL_PHASES[].items` and `TODAY_PROTOCOL`.

| Field         | Type       | Notes                                                  |
|---------------|------------|--------------------------------------------------------|
| id            | uuid       |                                                        |
| phaseId       | FK→ProtocolPhase |                                                  |
| label         | string     | "1 el brandnetel door ruwvoer"                         |
| meta          | string     | timing slot, e.g. "Ochtendvoer", "Vóór beweging"       |
| kind          | enum       | `feeding` / `observation` / `care` / `other`           |
| order         | int        |                                                        |
| activeFrom    | date       |                                                        |
| activeUntil   | date?      |                                                        |
| referenceId   | FK→LibraryItem? | optional link to article that introduced this    |

### `ProtocolTaskCompletion`
A per-day check-off (calendar `PROTOCOL_CALENDAR` + `home.tsx` checklist). Drives "done/partial/today" cell colouring.

| Field      | Type            | Notes                          |
|------------|-----------------|--------------------------------|
| taskId     | FK→ProtocolTask |                                |
| horseId    | FK→Horse        | denormalised for query speed   |
| date       | date            | YYYY-MM-DD                     |
| done       | boolean         |                                |
| doneAt     | timestamp?      |                                |
| Unique key | (taskId, date)  |                                |

---

## 4. Observations & Journal

### `Observation`
Created via `log-entry.tsx` and rendered on the horse profile timeline (`TIMELINE`).

| Field      | Type       | Notes                                                  |
|------------|------------|--------------------------------------------------------|
| id         | uuid       |                                                        |
| horseId    | FK→Horse   |                                                        |
| authorId   | FK→User    |                                                        |
| date       | date       |                                                        |
| note       | text       | free text                                              |
| mood       | int        | 1–5 (😞 .. 😊)                                          |
| stoolScore | enum?      | A / B / C / D                                          |
| protocolTaskId | FK?    | optional link to the task this observation is about    |

### `ObservationPhoto`
Photo attachments (1..n per observation).

| Field        | Type       | Notes                          |
|--------------|------------|--------------------------------|
| observationId| FK         |                                |
| url          | string     |                                |
| caption      | string?    |                                |
| kind         | enum       | `skin` / `stool` / `feed` / `other` |

### `TimelineEvent` (derived / event log)
Generic activity feed for the horse profile timeline. May be a materialised view over Observations + system events ("Nova toegevoegd aan EquiNova", "Intake met Shelley").

| Field      | Type       | Notes                                                 |
|------------|------------|-------------------------------------------------------|
| horseId    | FK→Horse   |                                                       |
| occurredAt | timestamp  |                                                       |
| kind       | enum       | `horse_added` / `intake` / `protocol_change` / `observation` / `scan` |
| message    | text       | "Brandnetel toegevoegd aan protocol …"                |
| refType    | string?    | polymorphic                                           |
| refId      | uuid?      |                                                       |

---

## 5. Scanner

### `ScanResult`
A scanned product, listed in `SCAN_HISTORY` and detailed in `SCAN_RESULT`.

| Field      | Type       | Notes                                  |
|------------|------------|----------------------------------------|
| id         | uuid       |                                        |
| userId     | FK→User    |                                        |
| horseId    | FK→Horse?  | "Advies voor Nova" implies a horse ctx |
| productId  | FK→Product?| optional link to product catalog       |
| scannedAt  | timestamp  |                                        |
| score      | int        | 0–100, drives ScoreRing                |
| rating     | enum       | `Goed` / `Matig` / `Slecht`            |
| advice     | text       | per-horse advice block                 |
| photoUrl   | string?    | captured image                         |
| bookmarked | boolean    | bookmark icon in result                |

### `Product`
Catalog entry — denormalised so multiple users scanning Pavo Care 4 Life share one product row.

| Field    | Type    | Notes                       |
|----------|---------|-----------------------------|
| id       | uuid    |                             |
| brand    | string  | "Pavo"                      |
| name     | string  | "Pavo Care 4 Life — supplement" |
| barcode  | string? | EAN/UPC                     |
| category | string? | "supplement"                |

### `Ingredient`
Master list of known ingredients.

| Field      | Type    | Notes                       |
|------------|---------|-----------------------------|
| id         | uuid    |                             |
| name       | string  | "Lijnzaad"                  |
| description| text    | default explanation         |
| defaultTag | enum    | `good` / `warn` / `danger`  |

### `ScanIngredient` (join with override)
Per-scan ingredient assessment (`SCAN_RESULT.ingredients`).

| Field        | Type       | Notes                                |
|--------------|------------|--------------------------------------|
| scanId       | FK→ScanResult |                                   |
| ingredientId | FK→Ingredient |                                   |
| tag          | enum       | `good` / `warn` / `danger`           |
| description  | text       | possibly overridden for this scan    |
| order        | int        |                                      |

---

## 6. Library / Content

### `LibraryItem`
Articles, videos, courses, programs (`LIBRARY_FEATURED`, `LIBRARY_LIST`, article + video screens).

| Field        | Type     | Notes                                            |
|--------------|----------|--------------------------------------------------|
| id           | uuid     | slug like "brandnetel"                           |
| kind         | enum     | `Kruid` / `Voeding` / `Diagnose` / `Cursus` / `Locatie` / `Symptoom` |
| format       | enum     | `article` / `video` / `course` / `program`       |
| title        | string   |                                                  |
| description  | text     |                                                  |
| body         | richtext | for articles                                     |
| videoUrl     | string?  |                                                  |
| heroImageUrl | string?  |                                                  |
| durationLabel| string   | "5 min · Video", "4 weken · Programma"           |
| durationSec  | int?     | parsed where possible                            |
| authorId     | FK→Therapist? | "Door Shelley"                              |
| viewsCount   | int      | "1.2k gezien"                                    |
| publishedAt  | timestamp|                                                  |
| isPlus       | boolean  | gated by subscription                            |

### `LibraryChapter`
For video items (`CHAPTERS` array on the video screen).

| Field      | Type      | Notes                       |
|------------|-----------|-----------------------------|
| itemId     | FK→LibraryItem |                        |
| order      | int       |                             |
| title      | string    |                             |
| startSec   | int       | "0:00" → 0, "1:14" → 74     |

### `LibraryCategory` (reference)
The horizontal chip filters ("Aanbevolen", "Voor jeuk", …). Many-to-many to `LibraryItem`.

### `LibraryItemFocusTag` (m2m)
`LibraryItem` ↔ `FocusTopic` — drives "Voor jou · op basis van protocol" feed.

### `LibraryBookmark`
The bookmark icon on article/video screens.

| Field     | Type           |
|-----------|----------------|
| userId    | FK→User        |
| itemId    | FK→LibraryItem |
| createdAt | timestamp      |

### `LibraryProgress`
Optional, supports the video scrubber state ("12%" progress).

| Field        | Type    |
|--------------|---------|
| userId       | FK→User |
| itemId       | FK→LibraryItem |
| positionSec  | int     |
| completed    | boolean |
| lastViewedAt | timestamp |

### `SeasonalTip`
The Coach card on home (`SEASONAL`).

| Field    | Type     | Notes                       |
|----------|----------|-----------------------------|
| id       | uuid     |                             |
| month    | string   | "mei"                       |
| body     | text     |                             |
| ctaItemId| FK→LibraryItem? | "brandnetel"         |
| activeFrom| date    |                             |
| activeTo  | date    |                             |

---

## 7. Community

### `CommunityPost`
Top-level threads on `community/index.tsx`.

| Field          | Type     | Notes                                          |
|----------------|----------|------------------------------------------------|
| id             | uuid     |                                                |
| authorUserId   | FK→User  | "Esther M."                                    |
| body           | text     |                                                |
| createdAt      | timestamp| "2 u", "gisteren" — relative on display        |
| likesCount     | int      | denorm                                         |
| repliesCount   | int      | denorm                                         |
| hasExpertReply | boolean  | denorm — drives "Shelley antwoordde" pill      |
| categoryId     | FK→CommunityCategory | filter chips ("Vraag Shelley" etc.)|

### `CommunityCategory`
The filter chips: Alles / Mijn focus / Vraag Shelley / Reviews / Diensten.

### `CommunityTag` (m2m)
Tags shown on thread detail ("jeukklachten", "voeding").

### `CommunityReply`
Replies on `community/thread/[id].tsx`.

| Field         | Type     | Notes                                  |
|---------------|----------|----------------------------------------|
| id            | uuid     |                                        |
| postId        | FK→CommunityPost |                                |
| authorUserId  | FK→User? | regular user reply                     |
| authorTherapistId | FK→Therapist? | expert reply (Shelley)         |
| body          | text     |                                        |
| createdAt     | timestamp|                                        |
| likesCount    | int      | denorm                                 |

### `CommunityReaction`
Polymorphic like / reaction on post or reply (so the same user can't double-like).

| Field        | Type      | Notes                                |
|--------------|-----------|--------------------------------------|
| userId       | FK→User   |                                      |
| targetType   | enum      | `post` / `reply`                     |
| targetId     | uuid      |                                      |
| kind         | enum      | `like` (extendable)                  |
| createdAt    | timestamp |                                      |

---

## 8. Subscription & Billing

### `Subscription`
Single active subscription per user (`subscription.tsx`).

| Field           | Type     | Notes                                  |
|-----------------|----------|----------------------------------------|
| id              | uuid     |                                        |
| userId          | FK→User  |                                        |
| plan            | enum     | `free` / `plus` / `bundle`             |
| status          | enum     | `active` / `cancelled` / `past_due`    |
| priceCents      | int      | 1200                                   |
| currency        | string   | "EUR"                                  |
| interval        | enum     | `monthly` / `one_time`                 |
| startedAt       | date     | "Sinds april 2026"                     |
| renewsAt        | date?    | "Verlengt 22 mei"                      |
| cancelledAt     | date?    |                                        |
| maxHorses       | int      | 3 for Plus                             |

### `Payment`
Payment history list on `subscription.tsx`.

| Field          | Type      | Notes                       |
|----------------|-----------|-----------------------------|
| id             | uuid      |                             |
| subscriptionId | FK        |                             |
| date           | date      | "22 apr 2026"               |
| amountCents    | int       | 1200                        |
| currency       | string    | "EUR"                       |
| status         | enum      | `paid` / `failed` / `refunded` |
| receiptUrl     | string?   |                             |

### `Plan` (reference catalog)
Plus, Opleiding bundel, etc.

| Field   | Type   | Notes                              |
|---------|--------|------------------------------------|
| id      | string | `plus`, `bundle`                   |
| name    | string | "EquiNova Plus"                    |
| price   | int    | cents                              |
| interval| enum   |                                    |
| benefits| string[] | "Onbeperkte scans + AI-advies", … |

---

## 9. Notifications, Settings, Exports

### `NotificationPreference`
"Meldingen" row in account (`ROWS` on `account/index.tsx`).

| Field          | Type    |
|----------------|---------|
| userId         | FK→User |
| reminderProtocol | boolean |
| reminderCommunity | boolean |
| reminderSeasonalTips | boolean |
| pushToken      | string? |

### `DataExport`
"Exporteer mijn data — CSV of PDF dagboek".

| Field      | Type      | Notes              |
|------------|-----------|--------------------|
| id         | uuid      |                    |
| userId     | FK→User   |                    |
| horseId    | FK→Horse? |                    |
| format     | enum      | `csv` / `pdf`      |
| requestedAt| timestamp |                    |
| completedAt| timestamp?|                    |
| fileUrl    | string?   |                    |

---

## 10. Nova AI Chat

### `ChatSession`
Each opening of the Nova modal can be one session (or one rolling thread per horse).

| Field    | Type      | Notes                       |
|----------|-----------|-----------------------------|
| id       | uuid      |                             |
| userId   | FK→User   |                             |
| horseId  | FK→Horse  | "What's up with Nova?"      |
| startedAt| timestamp |                             |

### `ChatMessage`

| Field    | Type      | Notes                                  |
|----------|-----------|----------------------------------------|
| id       | uuid      |                                        |
| sessionId| FK→ChatSession |                                   |
| role     | enum      | `assistant` (Nova) / `user`            |
| body     | text      |                                        |
| createdAt| timestamp |                                        |

---

## 11. Intake (Shelley) — optional

### `IntakeBooking`
Connect screen ("Plan een gratis intake met Shelley · 30 min").

| Field        | Type      | Notes                          |
|--------------|-----------|--------------------------------|
| id           | uuid      |                                |
| userId       | FK→User   |                                |
| horseId      | FK→Horse  |                                |
| therapistId  | FK→Therapist |                             |
| scheduledAt  | timestamp | "Vrijdag 18 mei · 14:30"       |
| status       | enum      | `pending` / `confirmed` / `done` / `cancelled` |
| notes        | text?     |                                |

---

## Relationship Overview

```
User ─┬─< Horse ─┬─< HorseFocus >─ FocusTopic
      │          ├─< HorseShare >─ User / Therapist
      │          ├─< HorseStat
      │          ├─< Observation ─< ObservationPhoto
      │          ├─< TimelineEvent
      │          ├─< Protocol ─┬─< ProtocolPhase ─< ProtocolTask ─< ProtocolTaskCompletion
      │          │             └── ProtocolAnalysis ─< ProtocolAdvice
      │          ├─< ScanResult ─< ScanIngredient >─ Ingredient
      │          │      └── Product
      │          ├─< ChatSession ─< ChatMessage
      │          └─< IntakeBooking >─ Therapist
      │
      ├─< Subscription ─< Payment
      ├─< LibraryBookmark >─ LibraryItem ─┬─< LibraryChapter
      │                                   ├─< LibraryItemFocusTag >─ FocusTopic
      │                                   └─< LibraryCategory (m2m)
      ├─< LibraryProgress >─ LibraryItem
      ├─< CommunityPost ─< CommunityReply
      │       └─< CommunityReaction (poly)
      ├─< NotificationPreference (1:1)
      └─< DataExport

Therapist ─< Protocol (author)
Therapist ─< LibraryItem (author)
Therapist ─< CommunityReply (expert)
SeasonalTip ─> LibraryItem (cta)
```

---

## Reference / Lookup tables (seed data)

- `FocusTopic` — `jeuk`, `staak`, `darm`, `prev`, `and`
- `Plan` — `free`, `plus`, `bundle`
- `LibraryCategory` — `Aanbevolen`, `Voor jeuk`, `Voor darmen`, `Voeding`, `Kruiden`, `Cursussen`
- `CommunityCategory` — `Alles`, `Mijn focus`, `Vraag Shelley`, `Reviews`, `Diensten`
- `Ingredient` — seeded from known scans (Lijnzaad, Bierdrab, Mout-extract, Vit. C synth., Saccharose, …)

## Notes / open questions

- **`HORSE.age` vs `birthYear`**: the UI captures "9 jaar"; store the birth year (or birth date) so age stays accurate over time.
- **`HORSE.focus`** is stored as free text in the mock (`['Jeukklachten', 'Spijsvertering']`). Some entries (e.g. `Spijsvertering`) don't map 1:1 to `FocusTopic` ids — consider a free-text "extra focus" string alongside the linked topics.
- **Protocol calendar**: `done` / `today` / `upcoming` / `empty` is purely derived from `ProtocolTaskCompletion` rows + the current date, so no separate calendar table is needed.
- **TimelineEvent** could be a true table or a query/view over Observation + Protocol + Scan + IntakeBooking events. Either works; a view keeps writes simpler.
- **Nova chat** today is one-shot mock. If conversations should persist across opens, scope `ChatSession` per `horseId` instead of per modal open.
- **Multi-tenant access**: `HorseShare.role = full` for therapists implies they can read all child entities (Observation, Protocol, ScanResult). The auth layer must enforce this beyond the FK structure.
