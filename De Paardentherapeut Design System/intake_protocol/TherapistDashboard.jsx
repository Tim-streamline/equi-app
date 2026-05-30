/* global React, I, INTAKE_INBOX, FILLED_INTAKE, CONCEPT_PROTOCOL */
// TherapistDashboard.jsx, Shelley's desktop side.
// Bevat: DashShell + DashInbox + DashIntakeDetail.
// (DashConceptProtocol, DashMonitoring, DashWeek6Eval staan in ConceptProtocolView.jsx)

const { useState: useTS } = React;

/* ============================================================
   SHARED, left rail, top bar
   ============================================================ */

function DashShell({ active = 'inbox', children, label, sublabel, right }) {
  const navItems = [
    { id: 'overzicht', t: 'Overzicht',   ic: I.home },
    { id: 'inbox',     t: 'Nieuwe intakes', ic: I.clipboard, badge: 3 },
    { id: 'monitoring',t: 'Lopende protocollen', ic: I.heart, badge: 8 },
    { id: 'evaluaties',t: 'Evaluaties',  ic: I.alert, badge: 2 },
    { id: 'klanten',   t: 'Klanten',     ic: I.users },
    { id: 'biblio',    t: 'Bibliotheek', ic: I.book },
    { id: 'community', t: 'Community',   ic: I.message },
    { id: 'agenda',    t: 'Agenda',      ic: I.calendar },
  ];
  return (
    <div className="dash">
      <aside className="dash-rail">
        <div className="dash-brand">
          <div className="mark">
            <img src="assets/logo-horse-white.png" alt="" />
          </div>
          <div>
            <div className="t">De Paardentherapeut</div>
            <div className="s">Therapeut-portaal</div>
          </div>
        </div>

        <nav className="dash-nav">
          {navItems.map((n) => (
            <a key={n.id} className={n.id === active ? 'active' : ''}>
              <span className="ic">{n.ic}</span>
              <span className="lbl">{n.t}</span>
              {n.badge && <span className="badge">{n.badge}</span>}
            </a>
          ))}
        </nav>

        <div className="dash-rail-bottom">
          <div className="dash-user">
            <div className="av">S</div>
            <div>
              <div className="nm">Shelley</div>
              <div className="role">Holistisch therapeut</div>
            </div>
            <button className="iconbtn" style={{ marginLeft: 'auto' }}>{I.settings}</button>
          </div>
        </div>
      </aside>

      <main className="dash-main">
        <header className="dash-top">
          <div>
            <div className="crumb">{sublabel}</div>
            <h1 className="dash-h1">{label}</h1>
          </div>
          <div className="dash-top-right">
            {right}
            <button className="iconbtn outline">{I.bell}</button>
          </div>
        </header>
        <div className="dash-body">{children}</div>
      </main>
    </div>
  );
}

/* ============================================================
   01 · INBOX
   ============================================================ */

function DashInbox() {
  const [filter, setFilter] = useTS('alle');
  const filtered = filter === 'alle' ? INTAKE_INBOX : INTAKE_INBOX.filter((i) => i.status === filter);
  return (
    <DashShell
      active="inbox"
      sublabel="Intakes"
      label="Binnengekomen intakes"
      right={
        <div style={{ display: 'flex', gap: 8 }}>
          <button className="dash-btn ghost"><span style={{ width: 16, height: 16 }}>{I.download}</span>Export</button>
        </div>
      }
    >
      {/* Status filter */}
      <div className="filter-row">
        {[
          { id: 'alle',           t: 'Alle',          c: 5 },
          { id: 'nieuw',          t: 'Nieuw',         c: 1, accent: true },
          { id: 'concept-klaar',  t: 'Concept klaar', c: 2 },
          { id: 'gepubliceerd',   t: 'Gepubliceerd',  c: 2 },
        ].map((f) => (
          <button key={f.id} className={filter === f.id ? 'filter active' : 'filter'} onClick={() => setFilter(f.id)}>
            {f.accent && <span className="ndot" />}
            {f.t}
            <span className="ct">{f.c}</span>
          </button>
        ))}
        <div style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
          <div className="dash-search">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            <input placeholder="Zoek op paard, eigenaar, klacht…" />
          </div>
        </div>
      </div>

      {/* Inbox table */}
      <div className="dash-card" style={{ padding: 0, overflow: 'hidden' }}>
        <div className="inbox-head">
          <div className="c1">Paard · Eigenaar</div>
          <div className="c2">Pakket</div>
          <div className="c3">Focus</div>
          <div className="c4">Status</div>
          <div className="c5">Ontvangen</div>
          <div className="c6"></div>
        </div>
        {filtered.map((it, i) => (
          <div key={it.id} className={`inbox-row ${it.status === 'nieuw' ? 'unread' : ''}`}>
            <div className="c1">
              <div className="av">{it.av}</div>
              <div>
                <div className="nm">{it.horse}
                  {it.flags > 0 && (
                    <span className="flagbadge" title={`${it.flags} aandachtspunten`}>
                      <span style={{ width: 11, height: 11 }}>{I.alert}</span>
                      {it.flags}
                    </span>
                  )}
                </div>
                <div className="sub">{it.owner}</div>
              </div>
            </div>
            <div className="c2"><span className={it.pakket === 'Protocol Plus' ? 'pakket plus' : 'pakket'}>{it.pakket}</span></div>
            <div className="c3">
              <div style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
                {it.focus.map((f) => <span key={f} className="chip outline" style={{ fontSize: 11 }}>{f}</span>)}
              </div>
            </div>
            <div className="c4"><StatusPill s={it.status} /></div>
            <div className="c5">{it.submitted}</div>
            <div className="c6">
              <button className="dash-btn small">
                {it.status === 'nieuw' ? 'Openen' : it.status === 'concept-klaar' ? 'Concept zien' : 'Bekijk'}
                <span style={{ width: 14, height: 14 }}>{I.chevron}</span>
              </button>
            </div>
          </div>
        ))}
      </div>

      {/* Tip card under list */}
      <div className="dash-tip">
        <div className="ic">{I.sparkles}</div>
        <div>
          <div className="t">Concept-protocol wordt automatisch klaargezet</div>
          <div className="d">Zodra een intake binnenkomt, genereert het systeem een concept op basis van Marit's antwoorden. Jij beslist wat je publiceert.</div>
        </div>
      </div>
    </DashShell>
  );
}

function StatusPill({ s }) {
  const map = {
    'nieuw':         { t: 'Nieuw',         cls: 'pill ny' },
    'in-review':     { t: 'In review',     cls: 'pill rv' },
    'concept-klaar': { t: 'Concept klaar', cls: 'pill ck' },
    'gepubliceerd':  { t: 'Gepubliceerd',  cls: 'pill pb' },
  };
  const v = map[s] || { t: s, cls: 'pill' };
  return <span className={v.cls}>{v.t}</span>;
}

/* ============================================================
   02 · INTAKE DETAIL (review)
   ============================================================ */

function DashIntakeDetail() {
  const intake = FILLED_INTAKE;
  const sectionsOrder = [
    { id: 'contact',       t: '00 · Contact & openheid' },
    { id: 'paard',         t: '01 · Over Nova' },
    { id: 'klacht',        t: '02 · Klacht & hulpvraag' },
    { id: 'geschiedenis',  t: '03 · Vroege jaren' },
    { id: 'medisch',       t: '04 · Medisch' },
    { id: 'voer',          t: '05 · Voer & ruwvoer' },
    { id: 'water',         t: '06 · Water & uitscheiding' },
    { id: 'huisvesting',   t: '07 · Huisvesting & weide' },
    { id: 'gedrag',        t: '08 · Gedrag & training' },
    { id: 'fysiek',        t: '09 · Fysiek & foto’s' },
  ];

  const [activeSec, setActiveSec] = useTS('medisch');

  return (
    <DashShell
      active="inbox"
      sublabel="Intakes · INT-2026-051"
      label={`${intake.meta.paard.naam} · ${intake.meta.klant.naam}`}
      right={
        <div style={{ display: 'flex', gap: 8 }}>
          <button className="dash-btn ghost"><span style={{ width: 16, height: 16 }}>{I.download}</span>PDF</button>
          <button className="dash-btn primary"><span style={{ width: 16, height: 16 }}>{I.sparkles}</span>Open concept-protocol</button>
        </div>
      }
    >
      <div className="intake-grid">
        {/* LEFT, section nav */}
        <aside className="intake-nav">
          <div className="intake-meta">
            <div className="row"><span className="k">Pakket</span><span className="v"><span className="pakket plus">Protocol Plus</span></span></div>
            <div className="row"><span className="k">Ontvangen</span><span className="v">{intake.meta.submitted}</span></div>
            <div className="row"><span className="k">Klant</span><span className="v">{intake.meta.klant.naam}</span></div>
            <div className="row"><span className="k">E-mail</span><span className="v">{intake.meta.klant.email}</span></div>
            <div className="row"><span className="k">Tel</span><span className="v">{intake.meta.klant.tel}</span></div>
          </div>

          <div className="nav-h">Secties</div>
          <nav className="intake-nav-list">
            {sectionsOrder.map((s) => {
              const flags = (intake.answers[s.id] || []).filter((a) => a.kind === 'flag').length;
              return (
                <a key={s.id} className={s.id === activeSec ? 'active' : ''} onClick={() => setActiveSec(s.id)}>
                  <span style={{ flex: 1 }}>{s.t}</span>
                  {flags > 0 && <span className="flagbadge tiny"><span style={{ width: 10, height: 10 }}>{I.alert}</span>{flags}</span>}
                </a>
              );
            })}
          </nav>
        </aside>

        {/* CENTER, answers */}
        <section className="intake-content">
          {/* Hero card with horse info */}
          <div className="dash-card hero">
            <div style={{ display: 'flex', gap: 18, alignItems: 'center' }}>
              <div className="hero-mark">
                <img src="assets/logo-horse-white.png" alt="" />
              </div>
              <div style={{ flex: 1 }}>
                <div style={{ fontSize: 11, letterSpacing: '0.16em', color: 'var(--mint-200)', fontWeight: 700, textTransform: 'uppercase', marginBottom: 4 }}>Protocol Plus · {intake.meta.id}</div>
                <h2 style={{ fontFamily: 'var(--font-display)', margin: 0, fontSize: 30, fontWeight: 700, letterSpacing: '-0.01em', color: 'white' }}>{intake.meta.paard.naam}</h2>
                <div style={{ display: 'flex', gap: 12, marginTop: 6, fontSize: 13, color: 'rgba(255,255,255,0.78)' }}>
                  <span>{intake.meta.paard.ras}</span><span>·</span>
                  <span>{intake.meta.paard.geslacht}</span><span>·</span>
                  <span>{intake.meta.paard.leeftijd}</span><span>·</span>
                  <span>{intake.meta.paard.gewicht}</span><span>·</span>
                  <span>{intake.meta.paard.stokmaat}</span>
                </div>
              </div>
              <div style={{ display: 'flex', gap: 22, alignItems: 'center' }}>
                <Quickstat l="Aandachtspunten" v="11" sub="door klant of AI" tone="warn" />
                <Quickstat l="Foto's" v="8" sub="alle aangeleverd" tone="ok" />
                <Quickstat l="Protocol-flags" v="5" sub="auto-toegepast" tone="ok" />
              </div>
            </div>
          </div>

          {/* AI summary */}
          <div className="dash-card sparkles">
            <div className="card-head">
              <span className="ic">{I.sparkles}</span>
              <span className="t">Concept-samenvatting</span>
              <span className="hint">auto-gegenereerd · bewerk handmatig</span>
            </div>
            <p className="lede">
              {CONCEPT_PROTOCOL.summary}
            </p>
            <div className="cause">
              <span className="k">Waarschijnlijkste oorzaak</span>
              <p>{CONCEPT_PROTOCOL.cause}</p>
              <span className="conf">Vertrouwen <em>{CONCEPT_PROTOCOL.confidence}</em></span>
            </div>
            <div className="actions">
              <button className="dash-btn primary">
                <span style={{ width: 16, height: 16 }}>{I.heart}</span>
                Open concept-protocol
              </button>
              <button className="dash-btn ghost">Herformuleer</button>
            </div>
          </div>

          {/* Section: answers */}
          {sectionsOrder.map((s) => (
            <SectionAnswers key={s.id} id={s.id} title={s.t} answers={intake.answers[s.id] || []} active={activeSec === s.id} />
          ))}
        </section>

        {/* RIGHT, therapist notes */}
        <aside className="intake-notes">
          <div className="dash-card sticky-notes">
            <div className="card-head"><span className="t">Mijn notities</span><span className="hint">alleen jij ziet dit</span></div>
            <textarea
              className="notes-area"
              defaultValue={`- Klacht past bij beeld histamine + dysbiose.
- Verhuisstress + wisselende ruwvoerkwaliteit zijn de aanjager.
- Eerst stal aanspreken op consistente baal-kwaliteit voordat we kruiden inzetten.
- Vraag Marit: foto van slijmvlies opnieuw, beter licht.`}
            />
            <div className="card-foot">
              <span className="ds">Laatst opgeslagen 1 min geleden</span>
            </div>
          </div>

          <div className="dash-card">
            <div className="card-head"><span className="t">Acties</span></div>
            <div className="action-list">
              <a><span style={{ width: 16, height: 16 }}>{I.message}</span>Bericht naar Marit via app</a>
              <a><span style={{ width: 16, height: 16 }}>{I.alert}</span>Vraag aanvullende foto / info</a>
              <a><span style={{ width: 16, height: 16 }}>{I.history}</span>Eerdere intakes Marit</a>
              <a><span style={{ width: 16, height: 16 }}>{I.download}</span>Exporteer als PDF</a>
            </div>
          </div>

          <div className="dash-card flag-list">
            <div className="card-head"><span className="t">Aandachtspunten</span><span className="hint">11</span></div>
            <ul>
              <li><span className="dot" />BCS 6/9, iets te zwaar</li>
              <li><span className="dot" />Stress sinds verhuizing jan 2024</li>
              <li><span className="dot" />Subklacht: mest soms losser na gras</li>
              <li><span className="dot" />Antihistaminicum kuur zomer 2025</li>
              <li><span className="dot" />Eerdere kolieken 2020 & 2023</li>
              <li><span className="dot" />Wisselende ruwvoerkwaliteit per baal</li>
              <li><span className="dot" />Geen balancer/mineralenvoeding</li>
              <li><span className="dot" />Mg + zeewier huidig, pauzeren</li>
              <li><span className="dot" />Stal 12-18u per dag</li>
              <li><span className="dot" />Weven bij voertijd</li>
              <li><span className="dot" />Soppen hooi + boos bij singel</li>
            </ul>
          </div>
        </aside>
      </div>
    </DashShell>
  );
}

function Quickstat({ l, v, sub, tone }) {
  return (
    <div className="qstat" data-tone={tone}>
      <div className="l">{l}</div>
      <div className="v">{v}</div>
      <div className="s">{sub}</div>
    </div>
  );
}

function SectionAnswers({ id, title, answers, active }) {
  const flags = answers.filter((a) => a.kind === 'flag').length;
  const photos = answers.filter((a) => a.kind === 'photo').length;
  return (
    <div className={`dash-card section-card ${active ? 'active' : ''}`} id={`sec-${id}`}>
      <div className="sec-head">
        <h3>{title}</h3>
        <div className="sec-meta">
          {flags > 0 && <span className="flagbadge"><span style={{ width: 11, height: 11 }}>{I.alert}</span>{flags}</span>}
          {photos > 0 && <span className="phbadge"><span style={{ width: 12, height: 12 }}>{I.camera}</span>{photos}</span>}
        </div>
      </div>
      <div className="qa-grid">
        {answers.map((a, i) => {
          if (a.kind === 'photo') {
            return (
              <div key={i} className="qa photo">
                <div className="q">{a.q}</div>
                <div className="ph">
                  <div className="thumb"><span style={{ width: 18, height: 18 }}>{I.camera}</span></div>
                  <button className="phbtn">Bekijk</button>
                </div>
              </div>
            );
          }
          return (
            <div key={i} className={a.kind === 'flag' ? 'qa flag' : 'qa'}>
              <div className="q">
                {a.kind === 'flag' && <span className="flag-ic"><span style={{ width: 11, height: 11 }}>{I.alert}</span></span>}
                {a.q}
              </div>
              <div className="a">{a.a}</div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

/* ============================================================
   03 · CONCEPT PROTOCOL EDITOR, OUDE VERSIE (nu vervangen door
   ConceptProtocolView.jsx · DashConceptProtocolOld blijft als fallback)
   ============================================================ */

function DashConceptProtocolOld() {
  const p = CONCEPT_PROTOCOL;
  const [openPhase, setOpenPhase] = useTS('fase1');

  return (
    <DashShell
      active="inbox"
      sublabel="Intakes · INT-2026-051 · Concept-protocol"
      label="Concept-protocol voor Nova"
      right={
        <div style={{ display: 'flex', gap: 8 }}>
          <button className="dash-btn ghost">Opslaan als concept</button>
          <button className="dash-btn primary"><span style={{ width: 16, height: 16 }}>{I.send}</span>Publiceer in app</button>
        </div>
      }
    >
      <div className="proto-grid">
        {/* LEFT, generated summary panel */}
        <aside className="proto-side">
          <div className="dash-card sparkles compact">
            <div className="card-head">
              <span className="ic">{I.sparkles}</span>
              <span className="t">Gegenereerd op basis van intake</span>
            </div>
            <p className="lede">{p.summary}</p>
            <div className="cause" style={{ marginBottom: 0 }}>
              <span className="k">Oorzaak</span>
              <p>{p.cause}</p>
            </div>
          </div>

          <div className="dash-card">
            <div className="card-head"><span className="t">Bericht aan Marit</span><span className="hint">verschijnt bovenaan protocol in app</span></div>
            <textarea className="notes-area" rows={5} defaultValue={p.shelleysNote} />
          </div>

          <div className="dash-card">
            <div className="card-head"><span className="t">Bron-intake</span></div>
            <div className="src-list">
              <a><span style={{ width: 14, height: 14 }}>{I.clipboard}</span>Volledige intake bekijken</a>
              <a><span style={{ width: 14, height: 14 }}>{I.camera}</span>5 foto's</a>
              <a><span style={{ width: 14, height: 14 }}>{I.alert}</span>9 aandachtspunten</a>
            </div>
          </div>
        </aside>

        {/* CENTER, phases editor */}
        <section className="proto-main">
          <div className="dash-card">
            <div className="card-head">
              <span className="t">Fasen</span>
              <span className="hint">sleep om volgorde aan te passen · klik om te bewerken</span>
              <button className="dash-btn small ghost" style={{ marginLeft: 'auto' }}>
                <span style={{ width: 14, height: 14 }}>{I.plus}</span>Fase
              </button>
            </div>

            <div className="phase-editor">
              {p.phases.map((ph, idx) => (
                <PhaseRow key={ph.id} ph={ph} idx={idx} open={openPhase === ph.id} onClick={() => setOpenPhase(openPhase === ph.id ? null : ph.id)} />
              ))}
            </div>
          </div>

          {/* Publication preview */}
          <div className="dash-card">
            <div className="card-head"><span className="t">Hoe Marit dit straks ziet in de app</span><span className="hint">live-preview</span></div>
            <div className="app-preview">
              <div className="preview-phone">
                <div className="ph-status"></div>
                <div className="ph-head">
                  <div className="ttl">Nova’s plan</div>
                  <div className="sub">Week 1 · Voorbereiding</div>
                  <div className="tabs">
                    <span className="tab active">Analyse</span><span className="tab">Protocol</span><span className="tab">Kalender</span>
                  </div>
                </div>
                <div className="ph-body">
                  <div className="ph-cause">
                    <div className="k">Waarschijnlijkste oorzaak</div>
                    <div className="d">{p.cause.split('.')[0]}.</div>
                  </div>
                  <div className="ph-section">Advies</div>
                  <div className="ph-row"><span className="ic" style={{ background: '#E9F7E6', color: '#2E7D44' }}>{I.leaf}</span><div><div className="t">Voeding</div><div className="d">Krachtvoer pauzeren, lijnzaad +.</div></div></div>
                  <div className="ph-row"><span className="ic" style={{ background: '#FFF1E6', color: '#A55A1F' }}>{I.heart}</span><div><div className="t">Management</div><div className="d">Slowfeeder &gt;2u pauzes vermijden.</div></div></div>
                </div>
              </div>

              <div className="preview-note">
                <h4>Wat verandert er voor Marit?</h4>
                <ul>
                  <li>Protocol-tab toont 4 fasen, Voorbereiding actief op publicatiedag</li>
                  <li>Kalender toont dagelijkse to-do's automatisch</li>
                  <li>Marit krijgt notificatie: <em>"Nova's protocol staat klaar"</em></li>
                  <li>Bericht hierboven verschijnt als eerste 'Coach card' op het home-scherm</li>
                </ul>
              </div>
            </div>
          </div>
        </section>
      </div>
    </DashShell>
  );
}

function PhaseRow({ ph, idx, open, onClick }) {
  return (
    <div className={`pe-phase ${open ? 'open' : ''}`}>
      <div className="pe-row" onClick={onClick}>
        <span className="pe-grip">⋮⋮</span>
        <span className="pe-nr">Fase {idx + 1}</span>
        <span className="pe-name" contentEditable suppressContentEditableWarning>{ph.name}</span>
        <span className="pe-dur">{ph.duration}</span>
        <span className="pe-count">{ph.items.length} items</span>
        <span className="pe-chev" style={{ transform: open ? 'rotate(90deg)' : 'rotate(0)' }}>{I.chevron}</span>
      </div>
      {open && (
        <div className="pe-items">
          {ph.items.map((it) => (
            <div key={it.id} className="pe-item">
              <span className="pe-grip">⋮⋮</span>
              <span className="cb">
                <span style={{ width: 12, height: 12, opacity: 0.4 }}>{I.check}</span>
              </span>
              <span className="t" contentEditable suppressContentEditableWarning>{it.t}</span>
              <span className={`pe-tag tag-${it.tag}`}>{it.tag}</span>
              <button className="pe-del" title="Verwijderen">{I.close}</button>
            </div>
          ))}
          <button className="pe-add">
            <span style={{ width: 14, height: 14 }}>{I.plus}</span>
            Item toevoegen
          </button>
        </div>
      )}
    </div>
  );
}

/* Expose */
Object.assign(window, { DashInbox, DashIntakeDetail, DashShell, Quickstat });
