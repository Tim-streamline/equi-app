# De Paardentherapeut — Design System

> _"Paardengezondheid van de toekomst."_
> Holistische paardentherapie • Rotterdam, NL

---

## 1 · Brand context

**De Paardentherapeut** ("The Horse Therapist") is the practice and brand of **Shelley**, a holistic horse therapist based in Rotterdam, the Netherlands. The company helps horse owners improve the **performance, health, and happiness of their horse** through a holistic, natural approach — combining biomechanics, nutrition, metabolism, natural medicine, and behavioural science.

Specialties:

- **Jeukklachten** — itching / skin issues
- **Staakgedrag** — refusal / rearing / behavioural issues
- **Darmproblemen** — digestive / gut problems

The brand sits at the intersection of veterinary expertise and holistic, owner-empowering education. The product line is:

| Product | What it is |
| --- | --- |
| **1-op-1 Behandelingen** | In-person therapy sessions for horses (≤30 min from Rotterdam, 3068TK) |
| **Gezond Paard Programma** | Online programme for passionate owners learning natural horse-health support |
| **Darmen Cursus** | Online course — become the "darmspecialist" for your own horse |
| **Locatiewissel Cursus** | Course on supporting horses through moves / shows / travel |
| **Opleiding** | 8-month premium training to become a holistic horse therapist (`opleiding.depaardentherapeut.nl`) |

> The original brief mentioned the working name **"EquiNova: Paardengezondheid van de toekomst"** — this design system uses the established public-facing brand **De Paardentherapeut** (the brand sheet, logo, domain, and email all key to it). If EquiNova is intended as a new sub-brand, the same primitives here can be re-skinned.

### Sources used to build this system

- 📄 `uploads/Huisstijl Sheet.pdf` — official one-page brand sheet (logo, two brand colours, fonts)
- 🌐 `https://depaardentherapeut.nl` — main marketing site (Shelley's practice, courses)
- 🌐 `https://opleiding.depaardentherapeut.nl` — the holistic therapist training programme
- 🌐 `https://www.depaardentherapeut.nl/gezondpaard`, `/darmen-cursus`, `/cursussen`, `/contact`, `/over` — public copy used for tone-of-voice

---

## 2 · Index of this folder

| Path | What |
| --- | --- |
| `README.md` | This file — context, content fundamentals, visual foundations, iconography |
| `SKILL.md` | Agent-skill manifest (works inside this app and in Claude Code) |
| `colors_and_type.css` | All design tokens — colour, type, spacing, radii, shadow, semantic variables |
| `assets/` | Logo variants, photography placeholders, decorative SVGs |
| `fonts/` | Webfont substitute for Myriad Pro (Source Sans 3) |
| `preview/` | Card files surfaced in the **Design System** tab |
| `ui_kits/marketing/` | Pixel-leaning recreation of the marketing website |

---

## 3 · Content fundamentals

### Language & voice

- **Dutch first.** All public copy is Dutch. Use Dutch headlines and CTAs in any output for this brand. English may appear in admin/internal views only.
- **First person, intimate.** Shelley speaks in the **"ik"** voice ("Ik help spirituele paardeneigenaren..."). The reader is addressed as **"jij" / "je" / "jouw"** — informal, never "u" except in occasional practical/legal copy on the contact page.
- **Warm + decisive.** Sentences are short. Many begin with a verb or a feeling. Em-dashes and ellipses are common in long-form copy. Question-led headers ("Ben jij een gepassioneerde paardenliefhebber?") draw the reader in before the offer lands.
- **Italics for emphasis.** The word **_écht_** ("really / truly") appears all over the site — it's the brand's verbal tic for "actually, deeply". Use sparingly and always in italic.

### Vocabulary tells

- "**Paardengezondheid**", "**holistisch**", "**natuurlijk**", "**op lange termijn**", "**van binnenuit**", "**de basis**" / "**bij de basis aanpakken**" — root cause, foundation.
- Symptom names in plain Dutch: **jeukklachten**, **staakgedrag**, **darmproblemen**, **hoefbevangenheid**.
- The horse is **"jouw paard"** — never "the horse". Possessive framing builds intimacy.
- Avoid clinical / vet jargon in marketing copy; save it for the opleiding.

### Casing

- **Headlines:** ALL-CAPS for primary hero / section titles ("WERK 1-OP-1 MET MIJ", "GEZOND PAARD PROGRAMMA", "OPLEIDING"). This is the dominant headline mode on the site.
- **Sub-heads & body:** Sentence case. Never Title Case — that reads as English to a Dutch reader.
- **Brand name:** "De Paardentherapeut" — capital D, capital P, never lowercased mid-sentence.

### Punctuation & symbols

- **✔ (heavy check)** for benefit lists / criteria.
- **▶ (right-pointing triangle)** for outcomes / what you'll achieve.
- **·** (middle dot) as a separator in footers and meta strings.
- **»** at the end of "Lees meer »" links (ecosystem convention).
- Emoji are **not** part of the core voice. Don't add them.

### Tone examples (from live copy)

> "Werken aan jeukklachten bij paarden, staakgedrag bij paarden, darmproblemen bij paarden en nog zoveel meer op een holistische manier die op lange termijn werkt!"

> "Dé online cursus voor paardeneigenaren die de darmspecialist willen worden voor hun eigen paard."

> "We combineren biomechanica, voeding, stofwisseling, natuurgeneeskunde en gedragsleer om paarden écht te begrijpen."

> "Ik help spirituele paardeneigenaren met het verbeteren van de prestaties, gezondheid en het geluk van hun paard."

The recurring move: name the pain → name the holistic frame → invite into a free intake.

---

## 4 · Visual foundations

### Colour

Two brand colours, taken **verbatim** from the huisstijl sheet:

| Token | Hex | RGB | CMYK | Use |
| --- | --- | --- | --- | --- |
| `--mint` | `#18BAB0` | 24 / 186 / 176 | 72 / 0 / 39 / 0 | Primary surfaces, CTAs, accents |
| `--blue-green` | `#127A79` | 18 / 122 / 121 | 84 / 29 / 49 / 15 | Headlines, deep brand surfaces |

A neutral foundation extends those two: a near-black `--ink` (used for body text — never pure `#000`), a soft warm white `--canvas`, and the deep forest `--logo-green` (`#0d3b34`) sampled from the logo silhouette itself, used as an option for high-contrast wordmarks.

The full ramp lives in `colors_and_type.css` as `--mint-50 … --mint-900` and `--bg-green-50 … --bg-green-900`. **Don't invent new hues.** When you need depth, reach for the ramp first.

### Typography

- **Spec font:** Myriad Pro Semibold (titles) + Myriad Pro Semibold Italic (subtitles). Adobe-licensed; not redistributable.
- **System substitute (used in this design system):** **Source Sans 3** (Adobe's libre evolution of the same design family — closest open-source match for Myriad's metrics and humanist warmth). Loaded from Google Fonts in `colors_and_type.css`.
- **⚠ Substitution flag — please review.** If you have access to a Myriad Pro webfont licence, drop the WOFF2 files into `fonts/` and update the `@font-face` blocks at the top of `colors_and_type.css`. Until then, layouts will render in Source Sans 3.

Type rules:

- Display + section titles → Source Sans 3 **Semibold (600)**, often **uppercase + tracked +0.04em**, mirroring the all-caps headline style on site.
- Sub-titles → Source Sans 3 **Semibold Italic** (600 italic). Italic is reserved for sub-titles, pull-quotes, and the word _écht_.
- Body → Source Sans 3 Regular 400, line-height 1.55, max measure 64ch.
- Numerals → tabular figures in tables and price lists.

### Spacing & rhythm

- 4 px base unit. Scale: `4 · 8 · 12 · 16 · 24 · 32 · 48 · 64 · 96 · 128`.
- Section vertical rhythm on marketing pages: `96–128 px` desktop, `64 px` mobile.
- Cards / panels: `24–32 px` interior padding.

### Corners & shape

- **Radii are generous.** Buttons and input fields: 9999px (full pill). Cards: 16–24 px. Hero panels and image frames: 24–32 px. The brand has a soft, organic feel — square corners feel clinical.
- One distinctive motif lifted from the logo: the **horse silhouette** functions as a wordmark badge and occasionally as a giant decorative shape behind a hero — set in the deep `--logo-green` over a mint wash.

### Backgrounds & surface treatment

- Pages run on a warm off-white `--canvas` (`#FBF8F3`) — never stark white. The mint and blue-green are used as **surface blocks** (full-width bands, hero panels, callouts) rather than text colour.
- **Tints, not gradients.** The brand is solid colour, not gradient-heavy. Where depth is needed, use a low-opacity radial wash of the same hue (think: a soft halo behind the horse logo).
- **Photography is warm and natural** — sun, grass, wood, a horse in soft daylight. Black-and-white treatments are rare. Avoid stock-photo gloss and avoid cool / blue casts. Image frames carry a 24 px radius and a thin `--mint` outline on hover.
- No repeating patterns or textures. The system stays clean.

### Borders, shadows, elevation

- Borders are **either none or 1 px** in `--ink-15` (a near-transparent ink). No double borders. Mint outlines (`1 px solid var(--mint-300)`) are used to mark active state.
- Shadows are soft, low, single-layered: `0 12px 32px rgba(18, 122, 121, 0.10)`. They are **tinted with the blue-green**, not neutral grey — gives the brand a unified warmth.
- Elevation goes: `--shadow-sm` (cards) → `--shadow-md` (floating elements) → `--shadow-lg` (modals only).

### Motion

- **Calm, never bouncy.** Default ease is `cubic-bezier(0.22, 0.61, 0.36, 1)` ("ease-out-quart") at 240 ms.
- Hover: 120 ms colour & translate; press: 80 ms scale 0.98.
- No spring physics. No parallax. Scroll-triggered fades are okay at small magnitudes (`opacity 0→1, translateY 12px→0`, 320 ms).
- Page transitions are simple cross-fades.

### States

| State | Treatment |
| --- | --- |
| Hover (filled button) | Background darkens to `--mint-700`; label stays white |
| Hover (ghost / link) | Underline appears; colour deepens to `--blue-green` |
| Press | `transform: scale(0.98)` + `--shadow` reduced one step |
| Focus | 2 px outline in `--mint-300`, offset 2 px |
| Disabled | 40 % opacity, no shadow, cursor `not-allowed` |

### Layout rules

- Marketing pages: 1280 px max content width, 24 px gutter, single `--canvas` background.
- Sticky header: 72 px tall, `--canvas` with a 1 px bottom hairline, becomes solid at scroll.
- Footer: deep `--bg-green-900` (`#0E4F4D`) with `--canvas` text. The logo wordmark sits top-left.
- Transparency / blur is used **only** for sticky nav after scroll: `backdrop-filter: blur(12px)` on a 70 % `--canvas`.

---

## 5 · Iconography

The brand has **no proprietary icon set** in the supplied huisstijl. The website itself is light on iconography — it leans on photography and the horse-mark logo for visual interest.

Where icons are needed in product surfaces, this system standardises on **Lucide** (`https://unpkg.com/lucide-static`) for the following reasons:

- Same open-stroke, humanist construction as Source Sans 3.
- 24 × 24 grid, 2 px stroke — matches the calm, soft tone.
- Free + maintained.

**⚠ Substitution flag.** Lucide is a substitute, not part of the supplied brand kit. If the team prefers a different set (e.g. Phosphor regular, Tabler), swap the CDN reference at the top of any UI kit `index.html`.

Rules of use:

- Stroke icons only — no filled shapes inside marketing copy. Filled icons are reserved for tiny utility moments (status dots, a heart on a saved-item).
- Icon colour inherits `currentColor`; use `--blue-green` on light surfaces, `--canvas` on dark surfaces.
- Hit target ≥ 44 × 44 px around any icon-only button.
- **Emoji are not used in product UI**, even though the live site occasionally uses ✔ and ▶ glyphs (these are plain Unicode characters, not emoji presentation, and ARE part of the voice — see "Punctuation & symbols").
- The **horse-mark** is the one piece of brand iconography that is _ours_. It lives in `assets/logo-horse-mark.png` and `assets/logo-horse-white.png` and should appear at least once on every long-scroll marketing page.

---

## 6 · Caveats

- **Logo source is rasterised.** The huisstijl PDF embeds the horse mark as PNG, not vector. `assets/logo-horse-mark.png` is the cleanest crop available; for print or large-scale use, ask Shelley for the original `.ai` / `.svg`.
- **Myriad Pro is licensed.** Source Sans 3 is the closest libre substitute. Provide the licensed webfont if you want exact fidelity.
- **Tone-of-voice rules** were extracted from the live site (`depaardentherapeut.nl`) and the opleiding sub-brand. If the team has internal style-guide documents, add them and re-run the system.
