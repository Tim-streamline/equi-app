/* global React, Header, Hero, CourseGrid, Testimonial, BenefitsList, IntakeForm, Footer */
const { useState: useAppState } = React;

const COURSES = [
  { id: 'gezondpaard', kind: 'Programma', title: 'Gezond Paard Programma',
    desc: 'Voor passievolle eigenaren die de gezondheid van hun paard op natuurlijke wijze willen ondersteunen.',
    duration: '8 maanden', format: 'Online + 1-op-1', price: '€ 1.797', startDate: 'Doorlopende start' },
  { id: 'darmen',      kind: 'Cursus',     title: 'Darmen Cursus',
    desc: 'Dé online cursus voor paardeneigenaren die de darmspecialist willen worden voor hun eigen paard.',
    duration: '6 weken',   format: 'Online (zelf-tempo)', price: '€ 397',  startDate: 'Direct beschikbaar' },
  { id: 'locatie',     kind: 'Cursus',     title: 'In balans bij locatiewissel',
    desc: 'Vóór, tijdens en ná iedere verplaatsing — fysiek én mentaal — natuurlijk ondersteunen.',
    duration: '4 weken',   format: 'Online',              price: '€ 247',  startDate: 'Direct beschikbaar' },
  { id: 'hb',          kind: 'Behandeling',title: '1-op-1 Behandeltraject',
    desc: 'Holistische therapie op locatie, voor jeuk, staakgedrag en darmproblemen.',
    duration: '3 sessies', format: 'Op locatie',          price: 'v.a. € 145', startDate: 'Op afspraak' },
  { id: 'opleiding',   kind: 'Opleiding',  title: 'Opleiding Paardentherapeut',
    desc: 'Word dé expert in holistische paardentherapie. Biomechanica, voeding, stofwisseling, gedragsleer.',
    duration: '8 maanden', format: 'Hybride',             price: '€ 4.997', startDate: 'September 2026' },
  { id: 'intake',      kind: 'Gratis',     title: 'Gratis intakegesprek',
    desc: 'We bekijken samen of mijn behandelmethode geschikt is voor jou en je paard.',
    duration: '30 min',    format: 'Telefonisch',         price: 'Gratis', startDate: 'Plan in agenda' },
];

const HOMEPAGE_BENEFITS = [
  { kind: 'check', text: 'Je hebt een enorme liefde voor paarden, maar voelt dat je <em>nog niet genoeg kennis</em> hebt om hun gezondheid optimaal te ondersteunen.' },
  { kind: 'check', text: 'Je gelooft dat er <em>natuurlijkere, gezondere</em> manieren zijn om voor je paarden te zorgen — maar je weet nog niet precies hoe.' },
  { kind: 'check', text: 'Je voelt dat traditionele methoden niet altijd het meest optimaal zijn en je wil meer leren over natuurlijke behandelmethoden.' },
  { kind: 'arrow', text: 'Je leert door alle reclamepraatjes heen prikken en zelfverzekerd de beste keuzes maken voor jou en je paard.' },
];

/* ---------- pages ---------- */

function HomePage({ setRoute, openCourse }) {
  return (
    <main>
      <Hero setRoute={setRoute} />

      <section className="section section--alt">
        <div className="container">
          <div className="section-head center">
            <span className="eyebrow">Voor wie is dit?</span>
            <h2>HERKEN JE JEZELF<br/>HIERIN?</h2>
          </div>
          <div style={{ maxWidth: 720, margin: '0 auto' }}>
            <BenefitsList items={HOMEPAGE_BENEFITS} />
          </div>
        </div>
      </section>

      <section className="section">
        <div className="container">
          <div className="section-head">
            <span className="eyebrow">Werk met mij</span>
            <h2>CURSUSSEN &amp; PROGRAMMA'S</h2>
            <p className="lead">Van een snelle online cursus tot een volledige 8-maandse opleiding — kies de manier die bij jou en je paard past.</p>
          </div>
          <CourseGrid courses={COURSES.slice(0, 3)} onOpen={openCourse} />
          <div style={{ display: 'flex', justifyContent: 'center', marginTop: 40 }}>
            <button className="btn btn--ghost" onClick={() => setRoute('cursussen')}>Alle cursussen bekijken »</button>
          </div>
        </div>
      </section>

      <section className="section section--deep">
        <div className="container" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 64, alignItems: 'center' }}>
          <div>
            <span className="eyebrow" style={{ color: 'var(--mint-200)' }}>Reviews</span>
            <h2 style={{ marginTop: 12 }}>WAT EIGENAREN<br/>ZEGGEN.</h2>
            <p className="lead" style={{ color: 'var(--mint-100)', marginTop: 16 }}>
              Ze zit in haar werk met aandacht en geduld — en dat voel je terug bij je paard.
            </p>
          </div>
          <div>
            <Testimonial
              quote="Naast dat ze rustig en vriendelijk te werk gaat, leveren haar behandelingen ook écht resultaat. Mijn merrie had ontzettend last van onverklaarbare jeuk — Shelley heeft mij begeleid in de juiste keuzes voor voeding en ruwvoer."
              attribution="Lisanne · merrie Pink Lady"
            />
          </div>
        </div>
      </section>

      <section className="section">
        <div className="container">
          <IntakeForm />
        </div>
      </section>
    </main>
  );
}

function CursussenPage({ openCourse }) {
  return (
    <main>
      <section className="section">
        <div className="container">
          <div className="section-head">
            <span className="eyebrow">Werk met mij</span>
            <h2>ALLE CURSUSSEN<br/>&amp; PROGRAMMA'S</h2>
            <p className="lead">Voor elke fase: van een eerste stap richting holistische paardenzorg tot dé opleiding voor toekomstige therapeuten.</p>
          </div>
          <CourseGrid courses={COURSES} onOpen={openCourse} />
        </div>
      </section>
    </main>
  );
}

function CoursePage({ course, setRoute }) {
  const c = course || COURSES[0];
  return (
    <main className="course-page">
      <div className="container">
        <div className="crumbs">
          <a onClick={() => setRoute('home')}>Home</a> · <a onClick={() => setRoute('cursussen')}>Cursussen</a> · <span>{c.title}</span>
        </div>
        <div className="course-hero">
          <div>
            <div className="meta-row">
              <span className="pill">{c.kind}</span>
              <span className="pill" style={{ background: 'var(--canvas-2)', color: 'var(--ink-70)' }}>{c.format}</span>
              <span className="pill" style={{ background: 'var(--canvas-2)', color: 'var(--ink-70)' }}>{c.duration}</span>
            </div>
            <h1>{c.title.toUpperCase()}</h1>
            <p className="lead">{c.desc}</p>

            <h3 style={{ marginTop: 48, marginBottom: 16 }}>Voor wie?</h3>
            <BenefitsList items={HOMEPAGE_BENEFITS.slice(0, 3)} />

            <h3 style={{ marginTop: 48, marginBottom: 16 }}>Wat krijg je?</h3>
            <BenefitsList items={[
              { kind: 'arrow', text: 'Persoonlijke feedback op 4 praktijkcasussen, zodat je je kennis in de praktijk kunt toetsen en verbeteren.' },
              { kind: 'arrow', text: '8 maanden lang premium toegang tot alles wat De Paardentherapeut organiseert.' },
              { kind: 'arrow', text: 'Een complete benadering — biomechanica, voeding, stofwisseling, natuurgeneeskunde en gedragsleer.' },
            ]} />
          </div>

          <aside className="price-card">
            <span className="eyebrow" style={{ color: 'var(--mint-200)' }}>Investering</span>
            <div className="price" style={{ marginTop: 8 }}>{c.price} <small>incl. BTW</small></div>
            <div className="row"><span className="k">Duur</span><span>{c.duration}</span></div>
            <div className="row"><span className="k">Format</span><span>{c.format}</span></div>
            <div className="row"><span className="k">Start</span><span>{c.startDate}</span></div>
            <button className="btn btn--primary" style={{ width: '100%', marginTop: 20 }} onClick={() => setRoute('contact')}>
              Ik meld me aan →
            </button>
            <p style={{ color: 'var(--mint-200)', fontSize: 13, marginTop: 12, fontStyle: 'italic', fontWeight: 600 }}>
              Twijfels? Plan eerst een gratis intake.
            </p>
          </aside>
        </div>

        <Testimonial
          quote="Shelley is een aantal keer langs geweest en heeft een aantal aanpassingen geadviseerd — met name geen suikers en onverpakt hooi. Een half jaar later is het allemaal zo bijzonder."
          attribution="Eigenaar · pony"
        />
      </div>
    </main>
  );
}

function ContactPage() {
  return (
    <main>
      <section className="section">
        <div className="container">
          <IntakeForm />
        </div>
      </section>
    </main>
  );
}

/* ---------- app shell ---------- */

function App() {
  const [route, setRoute] = useAppState('home');
  const [course, setCourse] = useAppState(null);
  const openCourse = (c) => { setCourse(c); setRoute('course'); };

  let page;
  if (route === 'home')         page = <HomePage setRoute={setRoute} openCourse={openCourse} />;
  else if (route === 'cursussen') page = <CursussenPage openCourse={openCourse} />;
  else if (route === 'course')  page = <CoursePage course={course} setRoute={setRoute} />;
  else                          page = <ContactPage />;

  return (
    <div id="root">
      <Header route={route} setRoute={setRoute} />
      {page}
      <Footer />
    </div>
  );
}

const root = ReactDOM.createRoot(document.getElementById('app'));
root.render(<App />);
