---
name: paardentherapeut-design
description: Use this skill to generate well-branded interfaces and assets for De Paardentherapeut (Dutch holistic horse-therapy practice — depaardentherapeut.nl), either for production or throwaway prototypes/mocks/etc. Contains essential design guidelines, colours, type, fonts, assets, and UI kit components for prototyping.
user-invocable: true
---

Read the README.md file within this skill, and explore the other available files.
If creating visual artifacts (slides, mocks, throwaway prototypes, etc), copy assets out and create static HTML files for the user to view. If working on production code, you can copy assets and read the rules here to become an expert in designing with this brand.
If the user invokes this skill without any other guidance, ask them what they want to build or design, ask some questions, and act as an expert designer who outputs HTML artifacts _or_ production code, depending on the need.

## Quick reference

- Brand: **De Paardentherapeut** — Shelley, Rotterdam, NL. Tagline: *"Paardengezondheid van de toekomst."*
- Voice: Dutch, first-person ("ik"), informal ("jij"), warm + decisive. Italics on the word *écht*.
- Colours: `--mint #18BAB0`, `--blue-green #127A79`, warm canvas `#FBF8F3`, deep ink `#1B2A2A`.
- Type: Source Sans 3 (libre substitute for Myriad Pro). Headlines uppercase + tracked +0.04em + Semibold. Subtitles italic Semibold.
- Pills, generous radii (16–24px, full pill on buttons), tinted blue-green shadows.
- Glyphs ✔ (criteria) and ▶ (outcomes); no emoji in product UI.
- Iconography: Lucide stroke-2 (substitute, flagged).

## What's in here

- `README.md` — full system docs (context, voice, visual foundations, iconography)
- `colors_and_type.css` — all design tokens
- `assets/` — logo variants
- `preview/` — Design-System-tab cards
- `ui_kits/marketing/` — recreation of depaardentherapeut.nl with React components

Always start by reading the README and the colors_and_type.css. Copy what you need into your output's folder rather than referencing the skill folder.
