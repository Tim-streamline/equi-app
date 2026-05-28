/* global React, I, MOCK, Coach, SectionTitle, AppHeader, SubHeader, ProgressRing */
// Screens.jsx — onboarding (4) + 5 tabs of content + sub-pages + modals.

const { useState: useSt } = React;
const { HORSE, TODAY_PROTOCOL, FOCUS_OPTIONS, SEASONAL, LIBRARY_FEATURED, LIBRARY_LIST, COMMUNITY, SCAN_RESULT, SCAN_HISTORY, PROTOCOL_META, PROTOCOL_ANALYSE, PROTOCOL_PHASES, PROTOCOL_CALENDAR } = MOCK;

/* small inline icon set used only by the protocol/advice rows */
const PI = {
  leaf:  I.leaf,
  run:   <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="13" cy="4" r="2"/><path d="m4 22 5-9 4 3-1 6"/><path d="M10 13 8 9l4-3 3 4 4 1"/></svg>,
  horse: <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M14 4 19 3l-1 4 2 3-2 2v3l-2 5h-3l1-5-3-2-3 2 1 5H6l-1-6 2-4-2-3a4 4 0 0 1 4-4Z"/></svg>,
};

/* ============================================================
   ONBOARDING (4)
   ============================================================ */

function ScreenWelcome({ go }) {
  return (
    <div className="screen dark" data-screen-label="01 Welcome">
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column', justifyContent: 'space-between', padding: '40px 28px 28px', color: 'white' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <div style={{ width: 36, height: 36, borderRadius: 10, background: 'var(--mint-500)', display: 'grid', placeItems: 'center' }}>
              <img src="assets/logo-horse-white.png" alt="" style={{ width: 22 }} />
            </div>
            <div>
              <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 18, letterSpacing: 0.5 }}>EquiNova</div>
              <div style={{ fontSize: 10, color: 'var(--mint-200)', letterSpacing: 0.2, textTransform: 'uppercase', fontWeight: 600 }}>by De Paardentherapeut</div>
            </div>
          </div>
        </div>

        <div>
          <div style={{ fontFamily: 'var(--font-display)', fontStyle: 'italic', color: 'var(--mint-200)', fontWeight: 600, fontSize: 14, marginBottom: 12 }}>
            Paardengezondheid van de toekomst.
          </div>
          <h1 style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 42, lineHeight: 1.05, margin: 0, marginBottom: 20 }}>
            Ken je paard.<br/>Van binnenuit.
          </h1>
          <p style={{ fontSize: 15, lineHeight: 1.6, color: 'rgba(255,255,255,0.75)', maxWidth: '28ch', margin: 0 }}>
            Holistische ondersteuning — voeding, gedrag en symptomen op één plek. Begeleid door Shelley.
          </p>
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          <button className="btn-primary" onClick={() => go('onb-add-horse')}>Begin met mijn paard →</button>
          <button className="btn-ghost" style={{ color: 'white', borderColor: 'rgba(255,255,255,0.2)', background: 'transparent' }} onClick={() => go('onb-add-horse')}>Ik heb al een account</button>
        </div>
      </div>
    </div>
  );
}

function ScreenAddHorse({ go, back }) {
  const [name, setName] = useSt('Nova');
  return (
    <div className="screen" data-screen-label="02 Add horse">
      <SubHeader title="Nieuw paard" onBack={back} />
      <div className="body" style={{ padding: '0 20px 100px' }}>
        <div style={{ marginBottom: 20 }}>
          <div className="k" style={{ marginBottom: 6 }}>Stap 1 van 3</div>
          <div className="progress-bar"><i style={{ width: '33%' }} /></div>
        </div>
        <h2 style={{ fontFamily: 'var(--font-display)', fontSize: 24, fontWeight: 700, lineHeight: 1.2, margin: 0, marginBottom: 8 }}>Vertel me over je paard.</h2>
        <p style={{ color: 'var(--app-ink-3)', fontSize: 14, marginBottom: 24 }}>Deze gegevens vormen de basis voor elk advies in de app.</p>

        <div className="field"><label>NAAM</label><input value={name} onChange={(e) => setName(e.target.value)} /></div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
          <div className="field"><label>RAS</label><input defaultValue="Friese kruising" /></div>
          <div className="field"><label>LEEFTIJD</label><input defaultValue="9 jaar" /></div>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
          <div className="field"><label>GESLACHT</label>
            <select defaultValue="merrie"><option>merrie</option><option>ruin</option><option>hengst</option></select></div>
          <div className="field"><label>GEWICHT</label><input defaultValue="540 kg" /></div>
        </div>
        <div className="field"><label>STALLING</label><input defaultValue="Manege De Hoeve · Box 4" /></div>
      </div>
      <div className="sticky-cta">
        <button className="btn-primary" onClick={() => go('onb-focus')}>Volgende →</button>
      </div>
    </div>
  );
}

function ScreenFocusPick({ go, back }) {
  const [picked, setPicked] = useSt(new Set(['jeuk', 'darm']));
  const toggle = (id) => {
    const next = new Set(picked);
    if (next.has(id)) next.delete(id); else next.add(id);
    setPicked(next);
  };
  return (
    <div className="screen" data-screen-label="03 Focus pick">
      <SubHeader title="Waar focus je op?" onBack={back} />
      <div className="body" style={{ padding: '0 20px 100px' }}>
        <div style={{ marginBottom: 20 }}>
          <div className="k" style={{ marginBottom: 6 }}>Stap 2 van 3</div>
          <div className="progress-bar"><i style={{ width: '66%' }} /></div>
        </div>
        <h2 style={{ fontFamily: 'var(--font-display)', fontSize: 24, fontWeight: 700, lineHeight: 1.2, margin: 0, marginBottom: 8 }}>Wat speelt er nu?</h2>
        <p style={{ color: 'var(--app-ink-3)', fontSize: 14, marginBottom: 20 }}>Kies één of meerdere thema's. Dit bepaalt jouw eerste protocol — je kunt het later altijd aanpassen.</p>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {FOCUS_OPTIONS.map((f) => (
            <button key={f.id} className={picked.has(f.id) ? 'bigchip active' : 'bigchip'} onClick={() => toggle(f.id)}>
              <div className="ic" style={{ fontSize: 20 }}>{f.ic}</div>
              <div>
                <div className="ttl">{f.t}</div>
                <div className="ds">{f.d}</div>
              </div>
              <div style={{ marginLeft: 'auto', width: 22, height: 22, borderRadius: '50%', border: '2px solid var(--app-border)', display: 'grid', placeItems: 'center', background: picked.has(f.id) ? 'var(--app-accent)' : 'transparent', borderColor: picked.has(f.id) ? 'var(--app-accent)' : 'var(--app-border)' }}>
                {picked.has(f.id) && <span style={{ color: 'white', width: 14, height: 14 }}>{I.check}</span>}
              </div>
            </button>
          ))}
        </div>
      </div>
      <div className="sticky-cta">
        <button className="btn-primary" onClick={() => go('onb-connect')} disabled={picked.size === 0}>Volgende →</button>
      </div>
    </div>
  );
}

function ScreenConnect({ go, back }) {
  /* Vervangt de oude "Plan gratis intake" stap.
     Er komt GEEN gesprek met Shelley — de hele intake gebeurt via vragen
     in de app, alleen voor klanten met een Protocol-pakket. */
  const [picked, setPicked] = useSt('herstelplan');
  const plans = [
    {
      id: 'basis',
      naam: 'Equinova Basis',
      prijs: '€ 19',
      per: 'per maand',
      hint: 'De app voor alledag',
      bullets: [
        'Bibliotheek · 240+ artikelen & video\'s',
        'Voer-scanner & dagelijks dagboek',
        'Community — vraag mee aan Shelley',
        'Maandelijks opzegbaar',
      ],
    },
    {
      id: 'herstelplan',
      naam: 'Het Holistisch Herstelplan',
      prijs: '€ 97',
      per: 'eenmalig · incl. 3 maanden app',
      hint: '12 weken aan de wortel werken',
      featured: true,
      bullets: [
        'Volledige intake door jou — ik lees alles persoonlijk',
        'Protocol op maat in 3 fasen · darmen → lever → huid',
        'Dagelijks plan met taken, dosering & uitleg',
        'Wekelijkse bijstelling op basis van jouw observaties',
      ],
    },
  ];
  return (
    <div className="screen" data-screen-label="04 Pakket kiezen">
      <SubHeader title="Kies wat past" onBack={back} />
      <div className="body" style={{ padding: '0 20px 100px' }}>
        <div style={{ marginBottom: 20 }}>
          <div className="k" style={{ marginBottom: 6 }}>Stap 3 van 3</div>
          <div className="progress-bar"><i style={{ width: '100%' }} /></div>
        </div>

        <h2 style={{ fontFamily: 'var(--font-display)', fontSize: 24, fontWeight: 700, lineHeight: 1.2, margin: 0, marginBottom: 8 }}>
          Hoeveel begeleiding wil je?
        </h2>
        <p style={{ color: 'var(--app-ink-3)', fontSize: 14, marginBottom: 20, lineHeight: 1.5 }}>
          Je kunt later altijd upgraden — kies wat nu past.
        </p>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          {plans.map((p) => {
            const sel = picked === p.id;
            return (
              <button
                key={p.id}
                onClick={() => setPicked(p.id)}
                className="bigchip"
                style={{
                  padding: 18,
                  flexDirection: 'column',
                  alignItems: 'stretch',
                  textAlign: 'left',
                  gap: 10,
                  borderColor: sel ? 'var(--app-accent)' : 'var(--app-border)',
                  background: sel ? (p.featured ? 'var(--app-deep)' : 'var(--app-accent-soft)') : 'white',
                  color: sel && p.featured ? 'white' : 'var(--app-ink)',
                }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: 10 }}>
                  <div>
                    <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 16, color: sel && p.featured ? 'white' : 'var(--app-ink)' }}>{p.naam}</div>
                    <div style={{ fontSize: 11, color: sel && p.featured ? 'var(--mint-200)' : 'var(--app-ink-3)', marginTop: 2, letterSpacing: '0.02em', fontStyle: p.featured ? 'italic' : 'normal' }}>{p.hint}</div>
                  </div>
                  <div style={{ textAlign: 'right' }}>
                    <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 18, color: sel && p.featured ? 'var(--mint-300)' : 'var(--app-deep-strong)' }}>{p.prijs}</div>
                    <div style={{ fontSize: 10, color: sel && p.featured ? 'rgba(255,255,255,0.65)' : 'var(--app-ink-3)', letterSpacing: '0.04em' }}>{p.per}</div>
                  </div>
                </div>
                <ul style={{ margin: 0, padding: 0, listStyle: 'none', display: 'flex', flexDirection: 'column', gap: 6 }}>
                  {p.bullets.map((b) => (
                    <li key={b} style={{ display: 'flex', gap: 8, alignItems: 'flex-start', fontSize: 13, lineHeight: 1.4, color: sel && p.featured ? 'rgba(255,255,255,0.85)' : 'var(--app-ink-2)' }}>
                      <span style={{ width: 14, height: 14, color: sel && p.featured ? 'var(--mint-300)' : 'var(--app-accent-strong)', flexShrink: 0, marginTop: 2 }}>{I.check}</span>
                      {b}
                    </li>
                  ))}
                </ul>
              </button>
            );
          })}
        </div>

        <div style={{ marginTop: 14, fontSize: 11.5, color: 'var(--app-ink-3)', textAlign: 'center', lineHeight: 1.5 }}>
          {picked === 'herstelplan'
            ? 'Eenmalig € 97 voor het volledige 12-wekentraject — drie maanden app gratis inbegrepen. Daarna optioneel verlengen voor € 19/m.'
            : 'Je kunt later altijd upgraden naar Het Holistisch Herstelplan.'}
        </div>
      </div>
      <div className="sticky-cta">
        <button className="btn-primary" onClick={() => go('home')}>
          {picked === 'herstelplan' ? 'Start mijn intake →' : 'Ga naar de app →'}
        </button>
      </div>
    </div>
  );
}

/* ============================================================
   HOME TAB
   ============================================================ */

function ScreenHome({ go, openNova, scrollToProtocol }) {
  const done = TODAY_PROTOCOL.filter((p) => p.done).length;
  return (
    <div className="screen with-tabs" data-screen-label="05 Home">
      <div className="body">
        <AppHeader greet={`Goedemorgen,`} title="Marit" right={
          <button className="iconbtn">{I.bell}</button>
        } avatar="M" />

        <Coach tag={`Seizoenstip · ${SEASONAL.month}`}>
          {SEASONAL.tip}
        </Coach>

        <div className="tile-row" style={{ marginBottom: 14 }}>
          <div className="tile" onClick={() => go('scan-camera')}>
            <span className="icon">{I.scan}</span>
            <div className="label">Scanner</div>
            <div className="sub">Voer beoordelen</div>
          </div>
          <div className="tile" onClick={() => go('library')}>
            <span className="icon">{I.book}</span>
            <div className="label">Bibliotheek</div>
            <div className="sub">3 nieuwe items</div>
          </div>
        </div>

        <SectionTitle action="Open protocol" onAction={() => go('protocol-detail')}>Vandaag · {HORSE.name}</SectionTitle>
        <div className="list" style={{ marginBottom: 18 }}>
          <div className="card" onClick={() => go('protocol-detail')}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
              <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 15 }}>Dagelijks protocol</div>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <div style={{ fontSize: 12, color: 'var(--app-ink-3)' }}>{done} van {TODAY_PROTOCOL.length} gedaan</div>
                <ProgressRing value={(done / TODAY_PROTOCOL.length) * 100} size={28} stroke={3} />
              </div>
            </div>
            <div className="checklist">
              {TODAY_PROTOCOL.slice(0, 3).map((p) => (
                <div key={p.id} className={p.done ? 'checklist-item done' : 'checklist-item'}>
                  <div className={p.done ? 'checkbox done' : 'checkbox'}>
                    {p.done && <span style={{ width: 14, height: 14, color: 'white' }}>{I.check}</span>}
                  </div>
                  <div style={{ flex: 1 }}>
                    <div className="label" style={{ fontSize: 14, fontWeight: 500 }}>{p.label}</div>
                    <div style={{ fontSize: 11, color: 'var(--app-ink-3)', marginTop: 2 }}>{p.meta}</div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>

        <SectionTitle action="Alles >" onAction={() => go('library')}>Voor jou geselecteerd</SectionTitle>
        <div className="list" style={{ marginBottom: 18 }}>
          <div className="card" onClick={() => go('library-video')}>
            <div className="lib-cover" style={{ height: 140 }}>
              <span className="chip tag" style={{ background: 'rgba(255,255,255,0.9)', color: 'var(--app-deep)' }}>{LIBRARY_FEATURED.kind}</span>
              <div className="play">{I.play}</div>
              <span className="duration">{LIBRARY_FEATURED.dur}</span>
            </div>
            <div>
              <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 16, color: 'var(--app-ink)' }}>{LIBRARY_FEATURED.t}</div>
              <div style={{ fontSize: 13, color: 'var(--app-ink-3)', marginTop: 4 }}>{LIBRARY_FEATURED.desc}</div>
            </div>
          </div>
        </div>

        <SectionTitle>Vraag Nova</SectionTitle>
        <div style={{ padding: '0 16px 20px' }}>
          <button className="bigchip" onClick={openNova}>
            <div className="ic">{I.sparkles}</div>
            <div>
              <div className="ttl">Stel een vraag aan Nova</div>
              <div className="ds">AI-assistent — getraind op Shelley's werk</div>
            </div>
            <span style={{ marginLeft: 'auto', color: 'var(--app-ink-3)' }}>{I.chevron}</span>
          </button>
        </div>
      </div>
    </div>
  );
}

function ScreenHorseProfile({ back, go }) {
  return (
    <div className="screen with-tabs" data-screen-label="06 Horse profile">
      <SubHeader title="" onBack={back} right={<button className="iconbtn">{I.more}</button>} />
      <div className="body" style={{ paddingBottom: 20 }}>
        <div className="horse-hero">
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
            <div>
              <div className="name">{HORSE.name}</div>
              <div className="meta-line">
                <span>{HORSE.breed}</span><span className="dot">·</span><span>{HORSE.age} jr</span><span className="dot">·</span><span>{HORSE.sex}</span>
              </div>
            </div>
            <img src="assets/logo-horse-white.png" alt="" style={{ width: 60, opacity: 0.5 }} />
          </div>

          <div style={{ display: 'flex', gap: 6, marginTop: 16, flexWrap: 'wrap' }}>
            {HORSE.focus.map((f) => (
              <span key={f} className="chip" style={{ background: 'rgba(255,255,255,0.18)', color: 'white' }}>{f}</span>
            ))}
          </div>
        </div>

        <div className="stat-row">
          <div className="stat-card">
            <span className="l">Gewicht</span>
            <span className="v">540 kg</span>
            <span className="t">stabiel</span>
          </div>
          <div className="stat-card">
            <span className="l">Energie</span>
            <span className="v">7 / 10</span>
            <span className="t">↑ +1 deze week</span>
          </div>
          <div className="stat-card">
            <span className="l">Mest-score</span>
            <span className="v">B+</span>
            <span className="t">stabiel</span>
          </div>
        </div>

        <SectionTitle action="Volledig dagboek" onAction={() => go('protocol')}>Tijdlijn</SectionTitle>
        <div style={{ padding: '0 24px' }}>
          <div className="timeline">
            <div className="step now">
              <div className="when">vandaag</div>
              <div className="what">Brandnetel toegevoegd aan protocol (mei-seizoenstip)</div>
            </div>
            <div className="step done">
              <div className="when">3 dagen geleden</div>
              <div className="what">Foto van mest geüpload — Score B+</div>
            </div>
            <div className="step done">
              <div className="when">2 weken geleden</div>
              <div className="what">Intake met Shelley · jeukklachten + spijsvertering</div>
            </div>
            <div className="step done">
              <div className="when">3 weken geleden</div>
              <div className="what">Nova toegevoegd aan EquiNova</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

/* ============================================================
   PROTOCOL TAB
   ============================================================ */

function ScreenProtocolList({ go, openNova }) {
  const [tab, setTab] = useSt('protocol');
  const subs = {
    protocol: PROTOCOL_META.subtitleProtocol,
    kalender: PROTOCOL_META.subtitleCalendar,
    analyse:  PROTOCOL_META.subtitleAnalyse,
  };

  return (
    <div className="screen with-tabs" data-screen-label="07 Protocol">
      <SubHeader title="" onBack={() => go('home')} right={<button className="iconbtn">{I.more}</button>} />

      <div className="body" style={{ paddingBottom: 24 }}>
        {/* Plan card with title + subtabs */}
        <div className="plan-card">
          <div className="head">
            <div className="ttl">{PROTOCOL_META.horseName}’s plan</div>
            <div className="sub">{subs[tab]}</div>
          </div>
          <div className="subtabs">
            <button className={tab === 'protocol' ? 'active' : ''} onClick={() => setTab('protocol')}>Protocol</button>
            <button className={tab === 'kalender' ? 'active' : ''} onClick={() => setTab('kalender')}>Kalender</button>
            <button className={tab === 'analyse'  ? 'active' : ''} onClick={() => setTab('analyse')}>Analyse</button>
          </div>
        </div>

        {tab === 'protocol' && <ProtocolPhases />}
        {tab === 'kalender' && <ProtocolCalendar go={go} />}
        {tab === 'analyse'  && <ProtocolAnalyse />}
      </div>
    </div>
  );
}

function ProtocolAnalyse() {
  return (
    <>
      <div className="cause-card">
        <div className="k">Waarschijnlijkste oorzaak</div>
        <div className="body-text">{PROTOCOL_ANALYSE.cause}</div>
      </div>

      <div className="advice-heading">Advies</div>
      <div className="advice-list">
        {PROTOCOL_ANALYSE.advice.map((a) => (
          <div key={a.id} className="advice-row">
            <div className="ic">{PI[a.icon]}</div>
            <div>
              <div className="t">{a.t}</div>
              <div className="d">{a.d}</div>
            </div>
          </div>
        ))}
      </div>
    </>
  );
}

function ProtocolPhases() {
  return (
    <div className="phase-list">
      {PROTOCOL_PHASES.map((p) => (
        <div key={p.id} className="phase" data-state={p.state}>
          <div className="row">
            <div className="t">{p.t}</div>
            <span className="chip-state">
              {p.state === 'done' && <span style={{ width: 12, height: 12, display: 'inline-grid', placeItems: 'center' }}>{I.check}</span>}
              {p.chip}
            </span>
          </div>
          {p.items && (
            <ul className="phase-items">
              {p.items.map((it, i) => (
                <li key={i}><span className="dot" />{it}</li>
              ))}
            </ul>
          )}
        </div>
      ))}
    </div>
  );
}

function ProtocolCalendar({ go }) {
  const cal = PROTOCOL_CALENDAR;
  const ITEMS = cal.today.items;
  const todayDay = cal.todayDay;

  const initState = () => {
    const map = {};
    cal.weeks.flat().forEach((cell) => {
      if (!cell) return;
      if (cell.s === 'done')        map[cell.d] = ITEMS.map(() => true);
      else if (cell.s === 'today')  map[cell.d] = [true, true, false, false];
      else                          map[cell.d] = ITEMS.map(() => false);
    });
    return map;
  };

  const [selected, setSelected] = useSt(todayDay);
  const [checks, setChecks]     = useSt(initState);

  const toggle = (day, idx) => {
    setChecks((prev) => ({ ...prev, [day]: prev[day].map((v, i) => i === idx ? !v : v) }));
  };

  const sel        = checks[selected] || ITEMS.map(() => false);
  const doneCount  = sel.filter(Boolean).length;

  const cellState = (cell) => {
    if (!cell) return null;
    if (cell.d === selected) return 'selected';
    if (cell.d === todayDay) return 'today';
    const c = checks[cell.d];
    if (c && c.every(Boolean))   return 'done';
    if (c && c.some(Boolean))    return 'partial';
    return cell.s;
  };

  const dateLabel = `${selected} mei`;

  return (
    <>
      <div className="cal-wrap">
        <div className="cal-head">
          <button className="cal-nav">{I.back}</button>
          <div className="m">{cal.monthLabel}</div>
          <button className="cal-nav" style={{ transform: 'rotate(180deg)' }}>{I.back}</button>
        </div>
        <div className="cal-grid compact">
          {['ma','di','wo','do','vr','za','zo'].map((d) => (<div key={d} className="dow">{d}</div>))}
          {cal.weeks.flatMap((wk, wi) => wk.map((cell, ci) => {
            if (!cell) return <div key={`${wi}-${ci}`} className="cal-day placeholder" />;
            return (
              <button
                key={`${wi}-${ci}`}
                className="cal-day"
                data-state={cellState(cell)}
                onClick={() => setSelected(cell.d)}
              >{cell.d}</button>
            );
          }))}
        </div>
        <div className="cal-legend">
          <span><i className="sw done" /> Gedaan</span>
          <span><i className="sw today" /> Vandaag</span>
          <span><i className="sw partial" /> Deels</span>
        </div>
      </div>

      <div className="cal-day-detail">
        <div className="hd">
          <div>
            <div className="lbl">{selected === todayDay ? 'Vandaag · ' : ''}{dateLabel}</div>
            <div className="meta">{doneCount} van {ITEMS.length} afgevinkt</div>
          </div>
        </div>
        <div className="day-checks">
          {ITEMS.map((it, i) => (
            <button key={i} className={sel[i] ? 'day-check done' : 'day-check'} onClick={() => toggle(selected, i)}>
              <span className="cbx">{sel[i] && <span style={{ width: 12, height: 12, color: 'white' }}>{I.check}</span>}</span>
              <span className="t">{it}</span>
            </button>
          ))}
        </div>
        <button className="btn-ghost" onClick={() => go('log-entry')} style={{ marginTop: 12 }}>
          <span style={{ width: 18, height: 18 }}>{I.plus}</span>
          Voeg observatie toe
        </button>
      </div>
    </>
  );
}

function ScreenProtocolDetail({ back, go }) {
  const [items, setItems] = useSt(TODAY_PROTOCOL);
  const toggle = (id) => setItems(items.map((p) => p.id === id ? { ...p, done: !p.done } : p));
  const done = items.filter((p) => p.done).length;
  return (
    <div className="screen with-tabs" data-screen-label="08 Protocol detail">
      <SubHeader title="Vandaag · 16 mei" onBack={back} right={<button className="iconbtn">{I.more}</button>} />
      <div className="body" style={{ paddingBottom: 20 }}>
        <Coach tag="Toelichting">
          Vandaag iets minder lijnzaad, omdat de mest gisteren al iets losser was. Voeg <em>één eetlepel</em> brandnetel toe — vers is best.
        </Coach>

        <SectionTitle>Ochtend</SectionTitle>
        <div style={{ padding: '0 16px 16px' }}>
          <div className="card flat">
            <div className="checklist">
              {items.filter((p) => p.meta === 'Ochtendvoer').map((p) => (
                <div key={p.id} className={p.done ? 'checklist-item done' : 'checklist-item'}>
                  <div className={p.done ? 'checkbox done' : 'checkbox'} onClick={() => toggle(p.id)}>
                    {p.done && <span style={{ width: 14, height: 14, color: 'white' }}>{I.check}</span>}
                  </div>
                  <div style={{ flex: 1 }}>
                    <div style={{ fontSize: 15, fontWeight: 500 }}>{p.label}</div>
                  </div>
                  <button className="iconbtn" style={{ width: 30, height: 30 }}>{I.chevron}</button>
                </div>
              ))}
            </div>
          </div>
        </div>

        <SectionTitle>Observaties</SectionTitle>
        <div style={{ padding: '0 16px 16px' }}>
          <div className="card flat">
            <div className="checklist">
              {items.filter((p) => p.meta !== 'Ochtendvoer').map((p) => (
                <div key={p.id} className={p.done ? 'checklist-item done' : 'checklist-item'}>
                  <div className={p.done ? 'checkbox done' : 'checkbox'} onClick={() => toggle(p.id)}>
                    {p.done && <span style={{ width: 14, height: 14, color: 'white' }}>{I.check}</span>}
                  </div>
                  <div style={{ flex: 1 }}>
                    <div style={{ fontSize: 15, fontWeight: 500 }}>{p.label}</div>
                    <div style={{ fontSize: 11, color: 'var(--app-ink-3)' }}>{p.meta}</div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>

        <div style={{ padding: '0 16px 24px' }}>
          <button className="btn-primary" onClick={() => go('log-entry')}>{done} van {items.length} gedaan · Voeg observatie toe</button>
        </div>
      </div>
    </div>
  );
}

function ScreenLogEntry({ back }) {
  const [mood, setMood] = useSt(3);
  const [score, setScore] = useSt('B');
  return (
    <div className="screen" data-screen-label="09 Log entry">
      <SubHeader title="Nieuwe observatie" onBack={back} right={<button className="btn-text">Klaar</button>} />
      <div className="body" style={{ padding: '0 20px 100px' }}>
        <div className="field"><label>WAT MERK JE OP?</label><textarea rows={3} placeholder="Bv. minder krabben aan manen, mest losser dan gisteren..." /></div>

        <SectionTitle>Hoe voelt {HORSE.name} zich?</SectionTitle>
        <div style={{ display: 'flex', gap: 8, padding: '0 0 16px' }}>
          {[1, 2, 3, 4, 5].map((n) => (
            <button key={n} className={mood === n ? 'bigchip active' : 'bigchip'} onClick={() => setMood(n)} style={{ flexDirection: 'column', textAlign: 'center', padding: 14, flex: 1 }}>
              <div style={{ fontSize: 22 }}>{['😞','😕','😐','🙂','😊'][n - 1]}</div>
              <div style={{ fontSize: 10, color: 'var(--app-ink-3)' }}>{['slecht','minder','ok','goed','top'][n - 1]}</div>
            </button>
          ))}
        </div>

        <SectionTitle>Mest-score</SectionTitle>
        <div style={{ display: 'flex', gap: 8, padding: '0 0 20px' }}>
          {['A', 'B', 'C', 'D'].map((s) => (
            <button key={s} className={score === s ? 'bigchip active' : 'bigchip'} onClick={() => setScore(s)} style={{ justifyContent: 'center', padding: 14 }}>
              <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 20 }}>{s}</div>
            </button>
          ))}
        </div>

        <button className="bigchip" style={{ background: 'var(--app-accent-soft)', borderColor: 'transparent' }}>
          <div className="ic" style={{ background: 'white' }}>{I.camera}</div>
          <div>
            <div className="ttl">Voeg foto toe</div>
            <div className="ds">Bv. huid, mest of voer</div>
          </div>
        </button>
      </div>
      <div className="sticky-cta">
        <button className="btn-primary" onClick={back}>Observatie opslaan</button>
      </div>
    </div>
  );
}

/* ============================================================
   SCANNER TAB
   ============================================================ */

function ScreenScannerCamera({ go, back }) {
  return (
    <div className="screen dark" data-screen-label="10 Scanner camera">
      <div className="subhdr" style={{ position: 'absolute', top: 44, left: 0, right: 0, zIndex: 4 }}>
        <button className="iconbtn on-deep" onClick={back}>{I.close}</button>
        <div className="title" style={{ color: 'white' }}>Scanner</div>
        <button className="iconbtn on-deep">{I.flash}</button>
      </div>

      <div className="scanner-stage">
        <div className="grain" />
        <div className="scan-frame">
          <span className="corner tl" /><span className="corner tr" />
          <span className="corner bl" /><span className="corner br" />
          <div className="scan-line" />
        </div>
        <div className="scan-hint">Richt de camera op een verpakking, ingrediëntenlijst of voederzak.</div>
        <div style={{ position: 'absolute', bottom: 130, left: 0, right: 0, display: 'flex', justifyContent: 'center', gap: 60, alignItems: 'center', zIndex: 3, color: 'white' }}>
          <button className="iconbtn on-deep" onClick={() => go('scan-history')}>{I.history}</button>
          <div className="shutter" onClick={() => go('scan-result')} />
          <button className="iconbtn on-deep">{I.qr}</button>
        </div>
        <div style={{ position: 'absolute', bottom: 70, left: 0, right: 0, textAlign: 'center', fontSize: 11, color: 'rgba(255,255,255,0.5)', letterSpacing: 0.15, textTransform: 'uppercase', fontWeight: 600, zIndex: 3 }}>
          Tik om scan te maken
        </div>
      </div>
    </div>
  );
}

function ScreenScannerResult({ back, go }) {
  const r = SCAN_RESULT;
  const ratingClass = r.score >= 75 ? 'success' : r.score >= 50 ? 'warn' : 'danger';
  return (
    <div className="screen" data-screen-label="11 Scanner result">
      <SubHeader title="Resultaat" onBack={back} right={<button className="iconbtn">{I.bookmark}</button>} />
      <div className="body" style={{ padding: '0 0 100px' }}>
        <div style={{ padding: '0 20px 20px', display: 'flex', alignItems: 'center', gap: 16 }}>
          <div className="score-ring">
            <svg width="96" height="96" style={{ position: 'absolute', transform: 'rotate(-90deg)' }}>
              <circle cx="48" cy="48" r="42" fill="none" stroke="var(--ink-08)" strokeWidth="6" />
              <circle cx="48" cy="48" r="42" fill="none" stroke={ratingClass === 'success' ? 'var(--success)' : ratingClass === 'warn' ? 'var(--warning)' : 'var(--danger)'} strokeWidth="6" strokeLinecap="round" strokeDasharray={2 * Math.PI * 42} strokeDashoffset={(2 * Math.PI * 42) * (1 - r.score / 100)} />
            </svg>
            <div style={{ position: 'relative', display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
              <div className="num">{r.score}</div>
              <div className="out-of">van 100</div>
            </div>
          </div>
          <div style={{ flex: 1 }}>
            <div className="k">{r.brand}</div>
            <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 18, lineHeight: 1.2, marginTop: 4 }}>{r.product}</div>
            <span className={`chip ${ratingClass}`} style={{ marginTop: 8 }}>{r.rating}</span>
          </div>
        </div>

        <Coach tag="Advies voor Nova">
          {r.advice}
        </Coach>

        <SectionTitle>Ingrediënten ({r.ingredients.length})</SectionTitle>
        <div style={{ padding: '0 20px' }}>
          {r.ingredients.map((ing, i) => (
            <div key={i} className={`ingred ${ing.tag}`}>
              <span className="dot" />
              <div style={{ flex: 1 }}>
                <div className="nm">{ing.nm}</div>
                <div className="ds">{ing.ds}</div>
              </div>
            </div>
          ))}
        </div>

        <div style={{ padding: 20 }}>
          <button className="btn-ghost" onClick={() => go('library-article')}>
            <span style={{ width: 18, height: 18 }}>{I.leaf}</span>
            Bekijk natuurlijke alternatieven
          </button>
        </div>
      </div>
    </div>
  );
}

function ScreenScannerHistory({ back, go }) {
  return (
    <div className="screen with-tabs" data-screen-label="12 Scan history">
      <SubHeader title="Eerdere scans" onBack={back} />
      <div className="body">
        <div className="list">
          {SCAN_HISTORY.map((s) => {
            const cls = s.score >= 75 ? 'success' : s.score >= 50 ? 'warn' : 'danger';
            return (
              <div key={s.id} className="card flat" onClick={() => go('scan-result')}>
                <div style={{ display: 'flex', gap: 14, alignItems: 'center' }}>
                  <div className="score-ring" style={{ width: 52, height: 52 }}>
                    <svg width="52" height="52" style={{ position: 'absolute', transform: 'rotate(-90deg)' }}>
                      <circle cx="26" cy="26" r="22" fill="none" stroke="var(--ink-08)" strokeWidth="4" />
                      <circle cx="26" cy="26" r="22" fill="none" stroke={cls === 'success' ? 'var(--success)' : cls === 'warn' ? 'var(--warning)' : 'var(--danger)'} strokeWidth="4" strokeLinecap="round" strokeDasharray={2 * Math.PI * 22} strokeDashoffset={(2 * Math.PI * 22) * (1 - s.score / 100)} />
                    </svg>
                    <div style={{ position: 'relative', fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 16, color: 'var(--app-ink)' }}>{s.score}</div>
                  </div>
                  <div style={{ flex: 1 }}>
                    <div style={{ fontWeight: 600, fontSize: 14, lineHeight: 1.3 }}>{s.t}</div>
                    <div style={{ fontSize: 12, color: 'var(--app-ink-3)', marginTop: 2 }}>{s.when} · <span className={`chip ${cls}`} style={{ padding: '1px 8px', fontSize: 10 }}>{s.rating}</span></div>
                  </div>
                  <span style={{ color: 'var(--app-ink-3)' }}>{I.chevron}</span>
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}

/* ============================================================
   LIBRARY TAB
   ============================================================ */

function ScreenLibrary({ go }) {
  return (
    <div className="screen with-tabs" data-screen-label="13 Library">
      <div className="body">
        <AppHeader greet="Bibliotheek" title="Wijzer worden" avatar="M" />

        <div style={{ padding: '0 20px 12px' }}>
          <div style={{ background: 'var(--app-surface)', border: '1px solid var(--app-border)', borderRadius: 14, padding: '10px 14px', display: 'flex', alignItems: 'center', gap: 10, color: 'var(--app-ink-3)' }}>
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            <span style={{ fontSize: 14 }}>Zoek in 240+ artikelen, video's, kruiden</span>
          </div>
        </div>

        <div style={{ padding: '0 16px 16px', display: 'flex', gap: 8, overflowX: 'auto' }}>
          {['Aanbevolen', 'Voor jeuk', 'Voor darmen', 'Voeding', 'Kruiden', 'Cursussen'].map((c, i) => (
            <span key={c} className={i === 0 ? 'chip' : 'chip outline'} style={{ flexShrink: 0 }}>{c}</span>
          ))}
        </div>

        <SectionTitle>Featured · voor Nova</SectionTitle>
        <div className="list" style={{ marginBottom: 18 }}>
          <div className="card" onClick={() => go('library-video')}>
            <div className="lib-cover">
              <img src="assets/logo-horse-white.png" alt="" />
              <span className="chip tag" style={{ background: 'rgba(255,255,255,0.92)', color: 'var(--app-deep)' }}>{LIBRARY_FEATURED.kind}</span>
              <div className="play">{I.play}</div>
              <span className="duration">{LIBRARY_FEATURED.dur}</span>
            </div>
            <div>
              <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 17 }}>{LIBRARY_FEATURED.t}</div>
              <div style={{ fontSize: 13, color: 'var(--app-ink-3)', marginTop: 4 }}>{LIBRARY_FEATURED.desc}</div>
            </div>
          </div>
        </div>

        <SectionTitle>Voor jou · op basis van protocol</SectionTitle>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8, padding: '0 16px 24px' }}>
          {LIBRARY_LIST.map((a) => (
            <div key={a.id} className="lib-row" onClick={() => go('library-article')}>
              <div className="cv"><img src="assets/logo-horse-white.png" alt="" /></div>
              <div style={{ display: 'flex', flexDirection: 'column', justifyContent: 'center' }}>
                <span className="k" style={{ fontSize: 9 }}>{a.kind}</span>
                <div className="nm">{a.t}</div>
                <div className="meta">{a.dur}</div>
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

function ScreenLibraryArticle({ back }) {
  return (
    <div className="screen" data-screen-label="14 Library article">
      <SubHeader title="Artikel" onBack={back} right={<button className="iconbtn">{I.bookmark}</button>} />
      <div className="body" style={{ padding: '0 20px 100px' }}>
        <div className="lib-cover" style={{ margin: '0 0 20px', height: 200 }}>
          <img src="assets/logo-horse-white.png" alt="" />
          <span className="chip tag" style={{ background: 'rgba(255,255,255,0.9)', color: 'var(--app-deep)' }}>Kruiden</span>
        </div>
        <div className="k" style={{ marginBottom: 8 }}>Lezen · 5 min · door Shelley</div>
        <h1 style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 28, lineHeight: 1.15, margin: '0 0 16px', textTransform: 'none', letterSpacing: 0, color: 'var(--app-ink)' }}>
          Brandnetel — de juiste dosering voor jouw paard.
        </h1>
        <p style={{ fontSize: 16, lineHeight: 1.65, color: 'var(--app-ink)', marginBottom: 14 }}>
          Brandnetel is in <em>mei en juni</em> op zijn krachtigst. De jonge blaadjes bevatten silicium, ijzer en een mild ontstekingsremmende werking — perfect bij voorjaars-jeuk en milde manenklachten.
        </p>
        <h3 style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 17, color: 'var(--blue-green)', marginTop: 24, marginBottom: 8 }}>Hoeveel?</h3>
        <p style={{ fontSize: 15, lineHeight: 1.6, color: 'var(--app-ink-2)', marginBottom: 14 }}>
          Begin met <em>één eetlepel vers</em> per dag, door het ruwvoer. Bouw in vijf dagen op naar 2–3 eetlepels, afhankelijk van het gewicht.
        </p>
        <h3 style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 17, color: 'var(--blue-green)', marginTop: 24, marginBottom: 8 }}>Niet doen.</h3>
        <p style={{ fontSize: 15, lineHeight: 1.6, color: 'var(--app-ink-2)', marginBottom: 14 }}>
          Geen gedroogde brandnetel zonder broeien — dit verstoort de werking. En niet langer dan zes weken aan een stuk: bouw daarna af.
        </p>

        <div className="card" style={{ background: 'var(--app-accent-soft)', borderColor: 'transparent', marginTop: 24 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <div style={{ width: 36, height: 36, borderRadius: 10, background: 'white', display: 'grid', placeItems: 'center', color: 'var(--app-accent-strong)' }}>{I.plus}</div>
            <div>
              <div style={{ fontWeight: 700, fontSize: 14 }}>Voeg toe aan Nova's protocol</div>
              <div style={{ fontSize: 12, color: 'var(--app-ink-2)' }}>1 el vers door ruwvoer · 5 dagen</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

function ScreenLibraryVideo({ back, go }) {
  return (
    <div className="screen" data-screen-label="15 Library video">
      <SubHeader title="" onBack={back} right={<button className="iconbtn">{I.bookmark}</button>} />
      <div className="body" style={{ padding: '0 0 100px' }}>
        <div style={{ height: 260, background: 'linear-gradient(135deg, var(--bg-green-700), var(--bg-green-900))', position: 'relative', display: 'grid', placeItems: 'center', margin: '0 16px', borderRadius: 16 }}>
          <img src="assets/logo-horse-white.png" alt="" style={{ width: '40%', opacity: 0.4 }} />
          <div style={{ position: 'absolute', width: 72, height: 72, borderRadius: '50%', background: 'rgba(255,255,255,0.95)', display: 'grid', placeItems: 'center', color: 'var(--app-deep-strong)' }}>{I.play}</div>
          <div style={{ position: 'absolute', bottom: 14, left: 14, right: 14, display: 'flex', justifyContent: 'space-between', color: 'white', fontSize: 12, fontWeight: 600 }}>
            <span>0:00</span><span>5:24</span>
          </div>
          <div style={{ position: 'absolute', bottom: 30, left: 14, right: 14, height: 3, background: 'rgba(255,255,255,0.2)', borderRadius: 999 }}>
            <div style={{ width: '12%', height: '100%', background: 'var(--mint-500)', borderRadius: 999 }} />
          </div>
        </div>

        <div style={{ padding: '20px 20px 0' }}>
          <span className="chip">{LIBRARY_FEATURED.kind}</span>
          <h1 style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 24, lineHeight: 1.2, margin: '10px 0 8px', textTransform: 'none', letterSpacing: 0, color: 'var(--app-ink)' }}>
            {LIBRARY_FEATURED.t}
          </h1>
          <div style={{ display: 'flex', gap: 10, alignItems: 'center', color: 'var(--app-ink-3)', fontSize: 12 }}>
            <span>Door Shelley</span><span className="dot-sep">·</span><span>{LIBRARY_FEATURED.dur}</span><span className="dot-sep">·</span><span>1.2k gezien</span>
          </div>

          <SectionTitle>Hoofdstukken</SectionTitle>
        </div>
        <div className="list">
          {[
            { t: 'Wanneer brandnetel plukken', d: '0:00' },
            { t: 'Verse vs. gedroogde — wat werkt', d: '1:14' },
            { t: 'Doseren in vijf dagen', d: '2:32' },
            { t: 'Wanneer niet te geven', d: '4:10' },
          ].map((c, i) => (
            <div key={i} className="row-split" style={{ borderRadius: 12, background: i === 0 ? 'var(--app-accent-soft)' : 'transparent', padding: '12px 14px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                <span style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 14, color: i === 0 ? 'var(--app-accent-strong)' : 'var(--app-ink-3)', minWidth: 24 }}>{String(i + 1).padStart(2, '0')}</span>
                <span style={{ fontWeight: 500, fontSize: 14 }}>{c.t}</span>
              </div>
              <span className="v" style={{ fontVariantNumeric: 'tabular-nums', fontSize: 12 }}>{c.d}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

/* ============================================================
   COMMUNITY TAB
   ============================================================ */

function ScreenCommunity({ go }) {
  return (
    <div className="screen with-tabs" data-screen-label="16 Community">
      <div className="body">
        <AppHeader greet="Community" title="Vraag & deel" avatar="M" right={<button className="iconbtn">{I.plus}</button>} />

        <div style={{ padding: '0 16px 14px', display: 'flex', gap: 8, overflowX: 'auto' }}>
          {['Alles', 'Mijn focus', 'Vraag Shelley', 'Reviews', 'Diensten'].map((c, i) => (
            <span key={c} className={i === 0 ? 'chip' : 'chip outline'} style={{ flexShrink: 0 }}>{c}</span>
          ))}
        </div>

        <div className="list" style={{ paddingBottom: 24 }}>
          {COMMUNITY.map((t) => (
            <div key={t.id} className="thread" onClick={() => go('community-thread')}>
              <div className="who">
                <div className="av">{t.av}</div>
                <div>
                  <div className="nm">{t.name} {t.hasExpert && <span className="expert-badge">Shelley antwoordde</span>}</div>
                </div>
                <span className="when">{t.when}</span>
              </div>
              <div className="q">{t.q}</div>
              <div className="reactions">
                <span>{I.thumb} {t.reactions.likes}</span>
                <span>{I.reply} {t.reactions.replies} reacties</span>
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

function ScreenCommunityThread({ back }) {
  return (
    <div className="screen" data-screen-label="17 Community thread">
      <SubHeader title="Vraag" onBack={back} right={<button className="iconbtn">{I.more}</button>} />
      <div className="body" style={{ padding: '0 0 90px' }}>
        <div className="list" style={{ marginBottom: 18 }}>
          <div className="thread">
            <div className="who">
              <div className="av">E</div>
              <div><div className="nm">Esther M.</div><div style={{ fontSize: 11, color: 'var(--app-ink-3)' }}>2 uur geleden</div></div>
            </div>
            <div className="q">Mijn ruin krabt zijn manen al weken open. Voeding al aangepast, geen verbetering. Iemand ervaring met brandnetel-protocol?</div>
            <div className="chip-row">
              <span className="chip outline">jeukklachten</span><span className="chip outline">voeding</span>
            </div>
          </div>

          <div className="thread" style={{ background: 'var(--app-accent-soft)', borderColor: 'transparent' }}>
            <div className="who">
              <div className="av" style={{ background: 'var(--bg-green-700)' }}>S</div>
              <div><div className="nm">Shelley · <span className="expert-badge">Therapeut</span></div><div style={{ fontSize: 11, color: 'var(--app-ink-3)' }}>1 uur geleden</div></div>
            </div>
            <div className="q">
              Hi Esther — als voeding al klopt is brandnetel zeker te proberen. Belangrijk: <em>vers</em>, niet gedroogd. Bouw op in 5 dagen.
              Stuur me eens een foto van zijn manen via de app, dan kijk ik mee.
            </div>
            <div className="reactions"><span>{I.thumb} 24</span><span>{I.reply} 4 reacties</span></div>
          </div>

          <div className="thread">
            <div className="who">
              <div className="av">J</div>
              <div><div className="nm">Jolien K.</div><div style={{ fontSize: 11, color: 'var(--app-ink-3)' }}>30 min geleden</div></div>
            </div>
            <div className="q">Bij mijn merrie ook geholpen. Goed om Shelley's stappenplan te volgen — niet zelf experimenteren.</div>
            <div className="reactions"><span>{I.thumb} 5</span></div>
          </div>
        </div>
      </div>
      <div className="sticky-cta" style={{ background: 'white', borderTop: '1px solid var(--app-border)', padding: '12px 16px calc(12px + env(safe-area-inset-bottom, 0))' }}>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <input className="field input" placeholder="Schrijf een reactie..." style={{ flex: 1, background: 'var(--app-surface-2)', border: 0, borderRadius: 999, padding: '12px 16px', fontSize: 14 }} />
          <button className="iconbtn" style={{ background: 'var(--app-accent)', color: 'white' }}>{I.send}</button>
        </div>
      </div>
    </div>
  );
}

/* ============================================================
   ACCOUNT TAB
   ============================================================ */

function ScreenAccount({ go }) {
  return (
    <div className="screen with-tabs" data-screen-label="18 Account">
      <div className="body">
        <AppHeader greet="" title="Mijn account" avatar="M" />

        <div className="list" style={{ marginBottom: 18 }}>
          <div className="card" onClick={() => go('my-horses')}>
            <div className="card-row">
              <div style={{ width: 48, height: 48, borderRadius: '50%', background: 'linear-gradient(135deg, var(--mint-400), var(--bg-green-700))', display: 'grid', placeItems: 'center', color: 'white', fontWeight: 700, fontSize: 16 }}>M</div>
              <div style={{ flex: 1 }}>
                <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 17 }}>Marit van der Berg</div>
                <div style={{ fontSize: 12, color: 'var(--app-ink-3)' }}>marit@voorbeeld.nl · Sinds april 2026</div>
              </div>
              <span style={{ color: 'var(--app-ink-3)' }}>{I.chevron}</span>
            </div>
          </div>
        </div>

        <SectionTitle>Paarden</SectionTitle>
        <div className="list" style={{ marginBottom: 18 }}>
          <div className="card flat" onClick={() => go('horse-profile')}>
            <div className="card-row">
              <div style={{ width: 44, height: 44, borderRadius: '50%', background: 'var(--mint-100)', display: 'grid', placeItems: 'center' }}>
                <img src="assets/logo-horse-mark.png" alt="" style={{ width: 28 }} />
              </div>
              <div style={{ flex: 1 }}>
                <div style={{ fontWeight: 700, fontSize: 15 }}>Nova</div>
                <div style={{ fontSize: 12, color: 'var(--app-ink-3)' }}>Friese kruising · 9 jr</div>
              </div>
              <span className="chip success" style={{ padding: '2px 8px' }}>Actief</span>
            </div>
          </div>
          <button className="bigchip" style={{ borderStyle: 'dashed' }} onClick={() => go('my-horses')}>
            <div className="ic">{I.plus}</div>
            <div className="ttl" style={{ color: 'var(--app-ink-2)' }}>Voeg paard toe</div>
          </button>
        </div>

        <SectionTitle>Abonnement</SectionTitle>
        <div className="list" style={{ marginBottom: 18 }}>
          <div className="card" onClick={() => go('subscription')} style={{ background: 'var(--app-deep)', color: 'white', border: 0 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
              <div>
                <span className="chip" style={{ background: 'var(--mint-500)', color: 'white' }}>Plus</span>
                <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 20, marginTop: 10 }}>EquiNova Plus</div>
                <div style={{ fontSize: 12, color: 'rgba(255,255,255,0.7)', marginTop: 2 }}>Verlengt 22 mei · € 12 / maand</div>
              </div>
              <span style={{ color: 'rgba(255,255,255,0.7)' }}>{I.chevron}</span>
            </div>
          </div>
        </div>

        <SectionTitle>Algemeen</SectionTitle>
        <div style={{ padding: '0 16px 24px' }}>
          {[
            { ic: I.bell, t: 'Meldingen', s: '3 reminders aan' },
            { ic: I.download, t: 'Exporteer mijn data', s: 'CSV of PDF dagboek' },
            { ic: I.settings, t: 'Voorkeuren', s: 'Eenheden, taal' },
            { ic: I.heart, t: 'Steun De Paardentherapeut', s: '' },
          ].map((r, i) => (
            <div key={i} className="row-split">
              <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                <span style={{ color: 'var(--app-ink-2)', width: 20, height: 20 }}>{r.ic}</span>
                <div>
                  <div style={{ fontWeight: 500, fontSize: 14 }}>{r.t}</div>
                  {r.s && <div style={{ fontSize: 11, color: 'var(--app-ink-3)' }}>{r.s}</div>}
                </div>
              </div>
              <span style={{ color: 'var(--app-ink-3)' }}>{I.chevron}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

function ScreenMyHorses({ back, go }) {
  return (
    <div className="screen" data-screen-label="19 My horses">
      <SubHeader title="Mijn paarden" onBack={back} right={<button className="iconbtn">{I.plus}</button>} />
      <div className="body" style={{ padding: '0 16px 100px' }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          <div className="card" onClick={() => go('horse-profile')}>
            <div className="card-row" style={{ alignItems: 'flex-start' }}>
              <div style={{ width: 60, height: 60, borderRadius: 16, background: 'linear-gradient(135deg, var(--mint-400), var(--bg-green-700))', display: 'grid', placeItems: 'center' }}>
                <img src="assets/logo-horse-white.png" alt="" style={{ width: 36 }} />
              </div>
              <div style={{ flex: 1 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 19 }}>Nova</div>
                  <span className="chip success" style={{ padding: '1px 8px', fontSize: 10 }}>Actief</span>
                </div>
                <div style={{ fontSize: 12, color: 'var(--app-ink-3)', marginTop: 2 }}>Friese kruising · 9 jaar · merrie · 540 kg</div>
                <div className="chip-row" style={{ marginTop: 8 }}>
                  <span className="chip outline">Jeuk</span>
                  <span className="chip outline">Darmen</span>
                </div>
              </div>
            </div>
          </div>

          <div className="card flat" style={{ opacity: 0.7 }}>
            <div className="card-row" style={{ alignItems: 'flex-start' }}>
              <div style={{ width: 60, height: 60, borderRadius: 16, background: 'var(--ink-08)', display: 'grid', placeItems: 'center' }}>
                <img src="assets/logo-horse-mark.png" alt="" style={{ width: 36, opacity: 0.4 }} />
              </div>
              <div style={{ flex: 1 }}>
                <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 18 }}>Pip</div>
                <div style={{ fontSize: 12, color: 'var(--app-ink-3)', marginTop: 2 }}>Welsh pony · 16 jaar · ruin</div>
                <div style={{ fontSize: 11, color: 'var(--app-ink-3)', marginTop: 6, fontStyle: 'italic' }}>Gearchiveerd · in 2024 overleden</div>
              </div>
            </div>
          </div>

          <button className="bigchip" style={{ borderStyle: 'dashed', justifyContent: 'center' }}>
            <div className="ic">{I.plus}</div>
            <div className="ttl">Voeg paard toe</div>
          </button>
        </div>

        <SectionTitle>Gedeeld met</SectionTitle>
        <div style={{ padding: '0 0 16px' }}>
          <div className="row-split">
            <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
              <div style={{ width: 32, height: 32, borderRadius: '50%', background: 'var(--bg-green-700)', color: 'white', display: 'grid', placeItems: 'center', fontWeight: 700, fontSize: 12 }}>S</div>
              <div>
                <div style={{ fontWeight: 600, fontSize: 14 }}>Shelley · De Paardentherapeut</div>
                <div style={{ fontSize: 11, color: 'var(--app-ink-3)' }}>Volledige toegang · therapeut</div>
              </div>
            </div>
            <button className="btn-text">Beheer</button>
          </div>
          <div className="row-split">
            <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
              <div style={{ width: 32, height: 32, borderRadius: '50%', background: 'var(--mint-300)', color: 'white', display: 'grid', placeItems: 'center', fontWeight: 700, fontSize: 12 }}>L</div>
              <div>
                <div style={{ fontWeight: 600, fontSize: 14 }}>Lisanne (medeverzorger)</div>
                <div style={{ fontSize: 11, color: 'var(--app-ink-3)' }}>Alleen-lezen · sinds maart</div>
              </div>
            </div>
            <button className="btn-text">Beheer</button>
          </div>
        </div>
      </div>
    </div>
  );
}

function ScreenSubscription({ back }) {
  return (
    <div className="screen" data-screen-label="20 Subscription">
      <SubHeader title="Abonnement" onBack={back} />
      <div className="body" style={{ padding: '0 16px 100px' }}>
        <div className="card" style={{ background: 'var(--app-deep)', color: 'white', border: 0, marginBottom: 14 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between' }}>
            <span className="chip" style={{ background: 'var(--mint-500)', color: 'white' }}>Plus · Actief</span>
            <span style={{ fontSize: 12, color: 'rgba(255,255,255,0.7)' }}>Sinds april 2026</span>
          </div>
          <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 32, marginTop: 12 }}>€ 12 <span style={{ fontSize: 14, color: 'rgba(255,255,255,0.7)', fontWeight: 500 }}>/ maand</span></div>
          <div style={{ fontSize: 13, color: 'rgba(255,255,255,0.75)', marginTop: 4 }}>Verlengt automatisch op 22 mei</div>

          <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginTop: 18, paddingTop: 16, borderTop: '1px solid rgba(255,255,255,0.12)', fontSize: 13 }}>
            {['Onbeperkte scans + AI-advies', 'Toegang tot alle bibliotheek-content', 'Direct vragen stellen aan Shelley', 'Tot 3 paarden'].map((b) => (
              <div key={b} style={{ display: 'flex', gap: 10 }}>
                <span style={{ color: 'var(--mint-300)', width: 18, height: 18 }}>{I.check}</span>{b}
              </div>
            ))}
          </div>
        </div>

        <SectionTitle>Upgrade pad</SectionTitle>
        <div className="list" style={{ marginBottom: 18 }}>
          <div className="card flat" style={{ borderColor: 'var(--mint-300)' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
              <div>
                <span className="chip">Aanbevolen</span>
                <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 18, marginTop: 8 }}>Opleiding bundel</div>
                <div style={{ fontSize: 12, color: 'var(--app-ink-3)', marginTop: 2 }}>EquiNova Plus + 8-maands opleiding</div>
              </div>
              <div style={{ textAlign: 'right' }}>
                <div style={{ fontWeight: 700 }}>€ 4.997</div>
                <div style={{ fontSize: 11, color: 'var(--app-ink-3)' }}>eenmalig</div>
              </div>
            </div>
            <button className="btn-primary" style={{ marginTop: 12 }}>Bekijk opleiding →</button>
          </div>
        </div>

        <SectionTitle>Betalingen</SectionTitle>
        <div style={{ padding: '0' }}>
          <div className="row-split"><div>22 apr 2026</div><div className="v" style={{ color: 'var(--app-ink)' }}>€ 12,00</div></div>
          <div className="row-split"><div>22 mrt 2026</div><div className="v" style={{ color: 'var(--app-ink)' }}>€ 12,00</div></div>
          <div className="row-split"><div>22 feb 2026</div><div className="v" style={{ color: 'var(--app-ink)' }}>€ 12,00</div></div>
        </div>

        <div style={{ padding: '20px 0 0' }}>
          <button className="btn-ghost" style={{ color: 'var(--danger)' }}>Abonnement opzeggen</button>
        </div>
      </div>
    </div>
  );
}

/* ============================================================
   MODAL: Nova AI chat
   ============================================================ */

function NovaChat({ open, onClose }) {
  const [msgs, setMsgs] = useSt([
    { role: 'nova', text: 'Hi Marit! Wat speelt er bij Nova?' },
  ]);
  const [input, setInput] = useSt('');
  const [busy, setBusy] = useSt(false);

  const ask = async () => {
    if (!input.trim() || busy) return;
    const q = input.trim();
    setInput('');
    setMsgs((m) => [...m, { role: 'you', text: q }]);
    setBusy(true);
    try {
      const prompt = `Je bent Nova, een holistische AI-assistent voor De Paardentherapeut. Beantwoord in 2-3 korte zinnen, in het Nederlands, informeel ("jij"). Voor: Nova (9-jarige Friese kruising-merrie, focus: jeuk & darmen). Vraag: ${q}`;
      const text = await window.claude.complete(prompt);
      setMsgs((m) => [...m, { role: 'nova', text }]);
    } catch (e) {
      setMsgs((m) => [...m, { role: 'nova', text: 'Hmm — ik kan even niet bij mijn kennisbank. Probeer het zo nog eens.' }]);
    }
    setBusy(false);
  };

  if (!open) return null;
  return (
    <div className="sheet-overlay" onClick={onClose}>
      <div className="sheet" onClick={(e) => e.stopPropagation()} style={{ maxHeight: '78%', display: 'flex', flexDirection: 'column' }}>
        <div className="sheet-grip" />
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 16 }}>
          <div style={{ width: 38, height: 38, borderRadius: '50%', background: 'linear-gradient(135deg, var(--mint-400), var(--bg-green-700))', display: 'grid', placeItems: 'center', color: 'white' }}>{I.sparkles}</div>
          <div>
            <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 17 }}>Nova</div>
            <div style={{ fontSize: 11, color: 'var(--app-ink-3)' }}>AI-assistent · getraind op Shelley's werk</div>
          </div>
        </div>
        <div className="chat-stream" style={{ padding: 0, flex: 1, overflowY: 'auto' }}>
          {msgs.map((m, i) => (
            <div key={i} className={`chat-bubble ${m.role}`}>{m.text}</div>
          ))}
          {busy && <div className="chat-bubble nova"><span style={{ display: 'inline-flex', gap: 4 }}><i style={{ width: 6, height: 6, background: 'var(--app-ink-3)', borderRadius: '50%', animation: 'pulse 1s infinite alternate' }} /><i style={{ width: 6, height: 6, background: 'var(--app-ink-3)', borderRadius: '50%', animation: 'pulse 1s infinite alternate', animationDelay: '0.2s' }} /><i style={{ width: 6, height: 6, background: 'var(--app-ink-3)', borderRadius: '50%', animation: 'pulse 1s infinite alternate', animationDelay: '0.4s' }} /></span></div>}
        </div>
        <div style={{ display: 'flex', gap: 8, marginTop: 14 }}>
          <input
            value={input}
            onChange={(e) => setInput(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') ask(); }}
            placeholder="Vraag iets over Nova..."
            style={{ flex: 1, background: 'var(--app-surface-2)', border: 0, borderRadius: 999, padding: '12px 16px', fontSize: 14, fontFamily: 'inherit' }}
          />
          <button className="iconbtn" style={{ background: 'var(--app-accent)', color: 'white' }} onClick={ask} disabled={busy}>{I.send}</button>
        </div>
        <style>{`@keyframes pulse { from { opacity: 0.3; } to { opacity: 1; } }`}</style>
      </div>
    </div>
  );
}

Object.assign(window, {
  ScreenWelcome, ScreenAddHorse, ScreenFocusPick, ScreenConnect,
  ScreenHome, ScreenHorseProfile,
  ScreenProtocolList, ScreenProtocolDetail, ScreenLogEntry,
  ScreenScannerCamera, ScreenScannerResult, ScreenScannerHistory,
  ScreenLibrary, ScreenLibraryArticle, ScreenLibraryVideo,
  ScreenCommunity, ScreenCommunityThread,
  ScreenAccount, ScreenMyHorses, ScreenSubscription,
  NovaChat,
});
