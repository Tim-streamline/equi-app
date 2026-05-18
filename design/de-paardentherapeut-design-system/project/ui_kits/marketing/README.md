# UI kit — Marketing site

Pixel-leaning recreation of `depaardentherapeut.nl`. Single-page demo (`index.html`) clicks through:

1. Home / hero
2. Course catalogue (cursussen)
3. Single course page
4. Contact (intake) form

## Components
- `Header.jsx` — sticky top nav with logo + course menu
- `Hero.jsx` — full-bleed hero with mint plate + horse mark
- `CourseCard.jsx` + `CourseGrid.jsx` — programme/cursus tiles
- `Testimonial.jsx` — italic pull-quote, mint top border
- `BenefitsList.jsx` — ✔ / ▶ list (matches the brand voice tics)
- `IntakeForm.jsx` — contact form, the brand's primary CTA destination
- `Footer.jsx` — deep green footer with KvK / contact meta

## What's omitted
- Real auth, payment, scheduling
- The interactive opleiding sub-portal (different visual treatment — would need its own kit)
- Long-form course-content pages

## How to view
Open `index.html` directly. Tweaks: client-side routing is fake — buttons & nav set state, no real URLs.
