/* global React, I, INTAKE_SECTIONS, FILLED_INTAKE, SubHeader, StatusBar, HomeIndicator */
// CustomerIntake.jsx — mobile screens the paying customer goes through.
// The intake is filled in entirely by the customer in the Equinova app
// after they upgrade to a Protocol package.

const { useState: useCS } = React;

/* Tiny inline icons used in section list */
const SectionIcon = ({ name }) => {
  const map = {
    horse: (
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M14 4 19 3l-1 4 2 3-2 2v3l-2 5h-3l1-5-3-2-3 2 1 5H6l-1-6 2-4-2-3a4 4 0 0 1 4-4Z"/></svg>
    ),
    alert: I.alert, history: I.history, heart: I.heart, leaf: I.leaf,
    droplet: I.droplet, home: I.home, sparkles: I.sparkles,
    camera: I.camera, send: I.send,
  };
  return map[name] || I.clipboard;
};

/* ============================================================
   01 · WELCOME — first time the customer opens "Protocol-intake"
   ============================================================ */

function MobileWelcome() {
  return (
    <div className="phone" data-screen-label="01 Klant · Welkom intake">
      <StatusBar tone="deep" />
      <div className="screen dark">
        <div className="subhdr" style={{ paddingTop: 4 }}>
          <button className="iconbtn on-deep">{I.close}</button>
          <div className="title" style={{ color: 'rgba(255,255,255,0.8)' }}>Protocol-intake</div>
          <div style={{ width: 36 }}></div>
        </div>

        <div className="body" style={{ padding: '8px 28px 24px', color: 'white', display: 'flex', flexDirection: 'column' }}>
          <div style={{ flex: 1, display: 'flex', flexDirection: 'column', justifyContent: 'center' }}>
            <div style={{ width: 56, height: 56, borderRadius: 16, background: 'var(--mint-500)', display: 'grid', placeItems: 'center', marginBottom: 22 }}>
              <img src="assets/logo-horse-white.png" alt="" style={{ width: 32 }} />
            </div>

            <div style={{ fontFamily: 'var(--font-display)', fontStyle: 'italic', color: 'var(--mint-200)', fontWeight: 600, fontSize: 13, marginBottom: 10, letterSpacing: '0.02em' }}>
              Hi Marit — leuk dat je er bent.
            </div>
            <h1 style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 32, lineHeight: 1.1, margin: 0, marginBottom: 16, letterSpacing: '-0.01em' }}>
              Vertel me over <em style={{ color: 'var(--mint-300)', fontStyle: 'normal' }}>Nova</em>.
            </h1>
            <p style={{ fontSize: 14.5, lineHeight: 1.6, color: 'rgba(255,255,255,0.78)', margin: 0, marginBottom: 22 }}>
              Tien korte secties — samen ongeveer <em style={{ color: 'white', fontStyle: 'normal' }}>30&nbsp;minuten</em>. Tussentijds opslaan kan altijd; je hoeft het niet in één keer af.
            </p>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 12, marginTop: 8 }}>
              {[
                { ic: I.clipboard, t: 'Open vragen — ik lees ze persoonlijk terug', d: 'Hoe meer detail, hoe beter het advies.' },
                { ic: I.camera,   t: 'Foto’s helpen mij meekijken',                  d: 'Huid, hoeven, mest — ik vraag het op zijn tijd.' },
                { ic: I.heart,    t: 'Binnen 3 werkdagen jouw protocol',             d: 'Ik bouw het zelf op basis van wat jij vertelt.' },
              ].map((it, i) => (
                <div key={i} style={{ display: 'flex', gap: 12, alignItems: 'flex-start' }}>
                  <div style={{ width: 30, height: 30, borderRadius: 9, background: 'rgba(24,186,176,0.18)', color: 'var(--mint-300)', display: 'grid', placeItems: 'center', flexShrink: 0 }}>
                    <span style={{ width: 16, height: 16, display: 'grid', placeItems: 'center' }}>{it.ic}</span>
                  </div>
                  <div>
                    <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 13.5, lineHeight: 1.3 }}>{it.t}</div>
                    <div style={{ fontSize: 12, color: 'rgba(255,255,255,0.6)', marginTop: 2, lineHeight: 1.4 }}>{it.d}</div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>

        <div className="sticky-cta" style={{ background: 'linear-gradient(to top, var(--app-deep-strong) 60%, rgba(11,42,41,0))', padding: '20px 24px 28px' }}>
          <button className="btn-primary">Beginnen</button>
          <button className="btn-ghost" style={{ background: 'transparent', borderColor: 'rgba(255,255,255,0.15)', color: 'white' }}>
            Stuur me eerst voorbeeldvragen
          </button>
        </div>
        <HomeIndicator tone="deep" />
      </div>
    </div>
  );
}

/* ============================================================
   02 · SECTION OVERVIEW — the "hub" of the intake
   ============================================================ */

function MobileSectionList() {
  const total = INTAKE_SECTIONS.length;
  const done  = INTAKE_SECTIONS.filter((s) => s.status === 'done').length;
  const pct   = Math.round((done / total) * 100);
  return (
    <div className="phone" data-screen-label="02 Klant · Sectie-overzicht">
      <StatusBar />
      <div className="screen">
        <div className="subhdr">
          <button className="iconbtn">{I.back}</button>
          <div className="title">Jouw intake</div>
          <button className="iconbtn">{I.more}</button>
        </div>

        <div className="body" style={{ padding: '0 16px 100px' }}>
          {/* Top card: progress + auto-save */}
          <div style={{ background: 'var(--app-deep)', color: 'white', borderRadius: 18, padding: '18px 18px 16px', margin: '0 0 18px', position: 'relative', overflow: 'hidden' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
              <div>
                <div style={{ fontSize: 11, letterSpacing: '0.16em', textTransform: 'uppercase', color: 'var(--mint-200)', fontWeight: 700, marginBottom: 6 }}>Protocol-intake · Nova</div>
                <div style={{ fontFamily: 'var(--font-display)', fontSize: 24, fontWeight: 700, lineHeight: 1.1 }}>
                  {done} van {total} <span style={{ color: 'var(--mint-300)' }}>klaar</span>
                </div>
              </div>
              <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 22, color: 'var(--mint-300)' }}>{pct}%</div>
            </div>
            <div style={{ marginTop: 14, height: 4, background: 'rgba(255,255,255,0.15)', borderRadius: 999, overflow: 'hidden' }}>
              <div style={{ width: `${pct}%`, height: '100%', background: 'var(--mint-400)' }}></div>
            </div>
            <div style={{ marginTop: 10, fontSize: 12, color: 'rgba(255,255,255,0.65)', display: 'flex', alignItems: 'center', gap: 6 }}>
              <span style={{ width: 7, height: 7, borderRadius: '50%', background: 'var(--mint-400)' }}></span>
              Automatisch opgeslagen · 2 min geleden
            </div>
          </div>

          {/* Section list */}
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            {INTAKE_SECTIONS.map((s) => (
              <SectionRow key={s.id} s={s} />
            ))}
          </div>

          <div style={{ marginTop: 18, padding: 14, background: 'var(--app-accent-soft)', borderRadius: 14, fontSize: 12.5, color: 'var(--app-ink-2)', lineHeight: 1.5 }}>
            <span style={{ fontWeight: 700, color: 'var(--app-accent-strong)' }}>Tip ·</span>{' '}
            Doe één sectie per dag als je het rustig wilt aanpakken. Alles wordt automatisch opgeslagen — je kunt later verder.
          </div>
        </div>
        <HomeIndicator />
      </div>
    </div>
  );
}

function SectionRow({ s }) {
  const stateMap = {
    done:   { bg: 'var(--app-accent-soft)', ic: 'var(--app-accent)',     fg: 'var(--app-accent-strong)', chip: '✓ Klaar' },
    active: { bg: 'white',                  ic: 'var(--app-deep)',       fg: 'var(--app-deep)',          chip: 'Verder' },
    todo:   { bg: 'white',                  ic: 'var(--app-ink-3)',      fg: 'var(--app-ink-3)',          chip: `~${s.minutes} min` },
  };
  const st = stateMap[s.status];
  const active = s.status === 'active';
  return (
    <div
      className="card flat"
      style={{
        padding: '14px 14px',
        background: st.bg,
        border: active ? '2px solid var(--app-accent)' : '1px solid var(--app-border)',
        gap: 14,
        flexDirection: 'row',
        alignItems: 'center',
      }}>
      <div style={{ width: 38, height: 38, borderRadius: 12, background: s.status === 'done' ? 'var(--app-accent)' : 'var(--app-accent-soft)', color: s.status === 'done' ? 'white' : 'var(--app-accent-strong)', display: 'grid', placeItems: 'center', flexShrink: 0 }}>
        <span style={{ width: 18, height: 18, display: 'grid', placeItems: 'center' }}>
          {s.status === 'done' ? I.check : <SectionIcon name={s.ic} />}
        </span>
      </div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ display: 'flex', alignItems: 'baseline', gap: 6 }}>
          <span style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 11, color: 'var(--app-ink-3)' }}>{String(s.nr).padStart(2, '0')}</span>
          <span style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 15, color: 'var(--app-ink)' }}>{s.title}</span>
        </div>
        <div style={{ fontSize: 12, color: 'var(--app-ink-3)', marginTop: 2, lineHeight: 1.3 }}>{s.sub}</div>
      </div>
      <div style={{ fontSize: 11, fontWeight: 700, color: st.fg, padding: '4px 10px', borderRadius: 999, background: active ? 'var(--app-accent)' : 'transparent', color: active ? 'white' : st.fg, whiteSpace: 'nowrap' }}>
        {st.chip}
      </div>
    </div>
  );
}

/* ============================================================
   03 · SECTION IN PROGRESS — "Voer & ruwvoer"
   Shows the mix of input types: chips, open text, sliders, ...
   ============================================================ */

function MobileSectionVoer() {
  const [hooi, setHooi] = useCS('onverpakt');
  const [aanbod, setAanbod] = useCS(new Set(['slowfeeder', 'los']));
  const [supl, setSupl]     = useCS(new Set(['magnesium', 'zeewier']));

  const toggle = (set, setSet, v) => {
    const n = new Set(set);
    if (n.has(v)) n.delete(v); else n.add(v);
    setSet(n);
  };

  return (
    <div className="phone" data-screen-label="03 Klant · Sectie invullen">
      <StatusBar />
      <div className="screen">
        <div className="subhdr">
          <button className="iconbtn">{I.back}</button>
          <div style={{ flex: 1, textAlign: 'center' }}>
            <div style={{ fontFamily: 'var(--font-display)', fontSize: 11, color: 'var(--app-ink-3)', letterSpacing: '0.12em', textTransform: 'uppercase', fontWeight: 700 }}>Sectie 5 van 10</div>
            <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 15, color: 'var(--app-ink)' }}>Voer & ruwvoer</div>
          </div>
          <button className="iconbtn" title="Pauzeer / opslaan">{I.close}</button>
        </div>

        <div style={{ padding: '0 20px 6px' }}>
          <div className="progress-bar"><i style={{ width: '48%' }} /></div>
        </div>

        <div className="body" style={{ padding: '14px 20px 110px' }}>
          {/* Hooi type — chip toggle */}
          <FieldLabel n="01" lbl="Krijgt Nova verpakt of onverpakt hooi?" />
          <div style={{ display: 'flex', gap: 8, marginBottom: 22 }}>
            {[
              { id: 'onverpakt', t: 'Onverpakt' },
              { id: 'verpakt', t: 'Verpakt (baal)' },
              { id: 'mix', t: 'Mix' },
            ].map((o) => (
              <button key={o.id} className={hooi === o.id ? 'bigchip active' : 'bigchip'} onClick={() => setHooi(o.id)} style={{ flex: 1, justifyContent: 'center', padding: '12px 6px', textAlign: 'center' }}>
                <span style={{ fontWeight: 700, fontSize: 13.5 }}>{o.t}</span>
              </button>
            ))}
          </div>

          {/* Hooi kg — numeric */}
          <FieldLabel n="02" lbl="Hoeveel kg ruwvoer per dag (ongeveer)?" />
          <div className="field">
            <input defaultValue="9" type="number" inputMode="decimal" style={{ fontSize: 18, fontFamily: 'var(--font-display)', fontWeight: 600 }} />
          </div>

          {/* Omschrijving — multi-chip + freeform */}
          <FieldLabel n="03" lbl="Hoe zou je het hooi omschrijven?" hint="Vink wat past — meerdere mogelijk." />
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginBottom: 10 }}>
            {['grof', 'stengelig', 'groenig', 'gelig', 'zacht', 'arm', 'rijk', 'stoffig'].map((tag) => (
              <button key={tag} className="chip outline" style={{ cursor: 'pointer', padding: '8px 14px', fontWeight: 600, background: (tag === 'rijk' || tag === 'groenig') ? 'var(--app-accent-soft)' : 'white', color: (tag === 'rijk' || tag === 'groenig') ? 'var(--app-accent-strong)' : 'var(--app-ink-2)', borderColor: (tag === 'rijk' || tag === 'groenig') ? 'var(--app-accent)' : 'var(--app-border)' }}>
                {tag}
              </button>
            ))}
          </div>
          <div className="field"><textarea rows={2} placeholder="Iets nog toe te voegen? (optioneel)" defaultValue="Soms wisselend per baal." /></div>

          {/* Kwaliteit — slider/scale */}
          <FieldLabel n="04" lbl="Constant of wisselend per baal?" />
          <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 22, padding: '12px 14px', background: 'white', border: '1px solid var(--app-border)', borderRadius: 14 }}>
            <span style={{ fontSize: 12, color: 'var(--app-ink-3)' }}>constant</span>
            <div style={{ flex: 1, position: 'relative', height: 4, background: 'var(--ink-08)', borderRadius: 999 }}>
              <div style={{ position: 'absolute', left: 0, top: 0, height: '100%', width: '70%', background: 'var(--app-accent)', borderRadius: 999 }}></div>
              <div style={{ position: 'absolute', left: '70%', top: '50%', transform: 'translate(-50%, -50%)', width: 20, height: 20, borderRadius: '50%', background: 'white', border: '2px solid var(--app-accent)', boxShadow: 'var(--shadow-sm)' }}></div>
            </div>
            <span style={{ fontSize: 12, color: 'var(--app-ink)', fontWeight: 600 }}>wisselend</span>
          </div>

          {/* Hoe aangeboden — multi-select */}
          <FieldLabel n="05" lbl="Hoe wordt het hooi aangeboden?" />
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginBottom: 22 }}>
            {[
              { id: 'los',         t: 'Los op de grond' },
              { id: 'slowfeeder',  t: 'Slowfeeder / hooinet' },
              { id: 'autosys',     t: 'Automatisch voersysteem' },
              { id: 'ruif',        t: 'Ruif' },
            ].map((o) => (
              <button key={o.id} className={aanbod.has(o.id) ? 'bigchip active' : 'bigchip'} onClick={() => toggle(aanbod, setAanbod, o.id)} style={{ padding: '12px 14px' }}>
                <div style={{ width: 22, height: 22, borderRadius: 6, border: '2px solid var(--app-border)', background: aanbod.has(o.id) ? 'var(--app-accent)' : 'white', borderColor: aanbod.has(o.id) ? 'var(--app-accent)' : 'var(--app-border)', display: 'grid', placeItems: 'center', flexShrink: 0 }}>
                  {aanbod.has(o.id) && <span style={{ width: 14, height: 14, color: 'white' }}>{I.check}</span>}
                </div>
                <span style={{ fontWeight: 600, fontSize: 14, color: 'var(--app-ink)' }}>{o.t}</span>
              </button>
            ))}
          </div>

          {/* Max zonder ruwvoer — chip */}
          <FieldLabel n="06" lbl="Maximale pauze zonder ruwvoer?" hint="Op een gemiddelde dag." />
          <div style={{ display: 'flex', gap: 6, marginBottom: 22 }}>
            {['<2u', '2–4u', '4–6u', '>6u'].map((o, i) => (
              <button key={o} className={i === 1 ? 'chip outline' : 'chip outline'} style={{ flex: 1, padding: '12px 4px', textAlign: 'center', justifyContent: 'center', background: i === 1 ? 'var(--app-accent-soft)' : 'white', color: i === 1 ? 'var(--app-accent-strong)' : 'var(--app-ink-2)', border: i === 1 ? '1px solid var(--app-accent)' : '1px solid var(--app-border)' }}>
                {o}
              </button>
            ))}
          </div>

          {/* Supplementen */}
          <FieldLabel n="07" lbl="Welke supplementen krijgt Nova nu?" />
          <div style={{ display: 'flex', flexDirection: 'column', gap: 6, marginBottom: 14 }}>
            {['Magnesium', 'Zeewier', 'Mariadistel', 'Brandnetel', 'Lijnzaad'].map((s) => {
              const active = supl.has(s.toLowerCase());
              return (
                <button key={s} className="bigchip" style={{ padding: '10px 14px', borderColor: active ? 'var(--app-accent)' : 'var(--app-border)', background: active ? 'var(--app-accent-soft)' : 'white' }} onClick={() => toggle(supl, setSupl, s.toLowerCase())}>
                  <div style={{ width: 22, height: 22, borderRadius: 6, border: '2px solid', borderColor: active ? 'var(--app-accent)' : 'var(--app-border)', background: active ? 'var(--app-accent)' : 'white', display: 'grid', placeItems: 'center', flexShrink: 0 }}>
                    {active && <span style={{ width: 14, height: 14, color: 'white' }}>{I.check}</span>}
                  </div>
                  <span style={{ fontWeight: 600, fontSize: 14 }}>{s}</span>
                </button>
              );
            })}
            <button className="bigchip" style={{ borderStyle: 'dashed', padding: '10px 14px' }}>
              <div className="ic" style={{ width: 22, height: 22, borderRadius: 6, background: 'transparent' }}>{I.plus}</div>
              <span style={{ fontWeight: 600, fontSize: 13, color: 'var(--app-ink-2)' }}>Iets anders</span>
            </button>
          </div>
        </div>

        <div className="sticky-cta">
          <div style={{ display: 'flex', gap: 8 }}>
            <button className="btn-ghost" style={{ flex: '0 0 auto', width: 'auto', padding: '14px 16px' }}>
              <span style={{ width: 18, height: 18 }}>{I.back}</span>
            </button>
            <button className="btn-primary" style={{ flex: 1 }}>Volgende · Water →</button>
          </div>
          <div style={{ fontSize: 11, color: 'var(--app-ink-3)', textAlign: 'center', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
            <span style={{ width: 6, height: 6, borderRadius: '50%', background: 'var(--app-accent)' }}></span>
            Automatisch opgeslagen
          </div>
        </div>
        <HomeIndicator />
      </div>
    </div>
  );
}

function FieldLabel({ n, lbl, hint }) {
  return (
    <div style={{ marginBottom: 10 }}>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 8 }}>
        <span style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 11, color: 'var(--app-ink-3)', letterSpacing: '0.04em' }}>{n}</span>
        <span style={{ fontFamily: 'var(--font-display)', fontWeight: 600, fontSize: 14.5, color: 'var(--app-ink)', lineHeight: 1.35 }}>{lbl}</span>
      </div>
      {hint && <div style={{ fontSize: 12, color: 'var(--app-ink-3)', marginTop: 2, paddingLeft: 18 }}>{hint}</div>}
    </div>
  );
}

/* ============================================================
   04 · OPEN TEXT — for sections like "klacht & hulpvraag"
   ============================================================ */

function MobileSectionKlacht() {
  return (
    <div className="phone" data-screen-label="04 Klant · Open vragen (klacht)">
      <StatusBar />
      <div className="screen">
        <div className="subhdr">
          <button className="iconbtn">{I.back}</button>
          <div style={{ flex: 1, textAlign: 'center' }}>
            <div style={{ fontFamily: 'var(--font-display)', fontSize: 11, color: 'var(--app-ink-3)', letterSpacing: '0.12em', textTransform: 'uppercase', fontWeight: 700 }}>Sectie 2 van 10</div>
            <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 15, color: 'var(--app-ink)' }}>Klacht & hulpvraag</div>
          </div>
          <button className="iconbtn">{I.close}</button>
        </div>

        <div style={{ padding: '0 20px 6px' }}>
          <div className="progress-bar"><i style={{ width: '18%' }} /></div>
        </div>

        <div className="body" style={{ padding: '14px 20px 110px' }}>
          {/* Coach prompt */}
          <div style={{ background: 'var(--app-deep)', color: 'white', borderRadius: 14, padding: 14, display: 'flex', gap: 10, marginBottom: 22 }}>
            <div style={{ width: 30, height: 30, borderRadius: '50%', background: 'var(--mint-500)', display: 'grid', placeItems: 'center', flexShrink: 0, fontWeight: 700, fontSize: 13 }}>S</div>
            <div style={{ fontSize: 13, lineHeight: 1.5 }}>
              Wees zo open mogelijk — ook gevoelens of vermoedens helpen. Ik lees alles persoonlijk.
            </div>
          </div>

          <FieldLabel n="01" lbl="Wat is de klacht of hulpvraag?" hint="In je eigen woorden — geen jargon nodig." />
          <div className="field">
            <textarea rows={4} defaultValue="Aanhoudende jeuk op manen en staartwortel. Schuurt zich aan paddockhek. Verergert in mei/juni." />
          </div>

          <FieldLabel n="02" lbl="Wat is je wens van dit consult?" />
          <div className="field">
            <textarea rows={3} defaultValue="Begrijpen waar het vandaan komt en een natuurlijk protocol om het van binnenuit aan te pakken — geen prik of pillen." />
          </div>

          <FieldLabel n="03" lbl="Wanneer is dit begonnen?" />
          <div className="field"><input defaultValue="Vorig voorjaar voor het eerst opgevallen" /></div>

          <FieldLabel n="04" lbl="Stressfactoren of veranderingen recent?" hint="Verhuizing, nieuwe stalgenoten, voerwissel, training-verandering…" />
          <div className="field">
            <textarea rows={3} defaultValue="Verhuisd in januari, nieuwe stal en nieuwe paddock-genoten." />
          </div>

          {/* "Tag" suggesties — een trick voor structuur zonder de klant te beperken */}
          <FieldLabel n="05" lbl="Welke thema's spelen mee?" hint="Optioneel — kies wat past." />
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginBottom: 14 }}>
            {[
              { t: 'Jeuk', sel: true },
              { t: 'Darmen', sel: true },
              { t: 'Staakgedrag', sel: false },
              { t: 'Hoeven', sel: false },
              { t: 'Voeding', sel: true },
              { t: 'Spierspanning', sel: false },
              { t: 'Ademhaling', sel: false },
              { t: 'Houding & balans', sel: false },
            ].map((c) => (
              <span key={c.t} className="chip" style={{ background: c.sel ? 'var(--app-accent)' : 'white', color: c.sel ? 'white' : 'var(--app-ink-2)', border: c.sel ? 'none' : '1px solid var(--app-border)', padding: '8px 14px', fontWeight: 600 }}>
                {c.sel && <span style={{ width: 12, height: 12 }}>{I.check}</span>}
                {c.t}
              </span>
            ))}
          </div>
        </div>

        <div className="sticky-cta">
          <button className="btn-primary">Volgende → Geschiedenis</button>
        </div>
        <HomeIndicator />
      </div>
    </div>
  );
}

/* ============================================================
   05 · PHOTO SECTION — "Fysiek & foto's"
   ============================================================ */

function MobileSectionFysiek() {
  const photos = [
    { t: 'Hele paard · zijaanzicht', d: 'Vanaf de zijkant, recht op afstand 3m.', ok: true },
    { t: 'Manen / staartwortel', d: 'Waar de jeukklacht zit.', ok: true },
    { t: 'Hoeven (4× — voor & achter)', d: 'Per hoef vanaf voor + zijaanzicht.', ok: false },
    { t: 'Slijmvlies (oogwit / tandvlees)', d: 'Til de bovenlip op, foto van tandvlees.', ok: false },
    { t: 'Mest (laatste 24u)', d: '1 hoopje op een schone ondergrond.', ok: false },
  ];

  return (
    <div className="phone" data-screen-label="05 Klant · Foto's">
      <StatusBar />
      <div className="screen">
        <div className="subhdr">
          <button className="iconbtn">{I.back}</button>
          <div style={{ flex: 1, textAlign: 'center' }}>
            <div style={{ fontFamily: 'var(--font-display)', fontSize: 11, color: 'var(--app-ink-3)', letterSpacing: '0.12em', textTransform: 'uppercase', fontWeight: 700 }}>Sectie 9 van 10</div>
            <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 15, color: 'var(--app-ink)' }}>Fysiek & foto’s</div>
          </div>
          <button className="iconbtn">{I.close}</button>
        </div>

        <div style={{ padding: '0 20px 6px' }}>
          <div className="progress-bar"><i style={{ width: '85%' }} /></div>
        </div>

        <div className="body" style={{ padding: '14px 16px 110px' }}>
          <div style={{ background: 'var(--app-accent-soft)', borderRadius: 14, padding: 14, marginBottom: 18 }}>
            <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 13, color: 'var(--app-accent-strong)', marginBottom: 4, letterSpacing: '0.04em', textTransform: 'uppercase' }}>Geen haast</div>
            <div style={{ fontSize: 13, color: 'var(--app-ink-2)', lineHeight: 1.45 }}>
              Doe deze foto's bij daglicht. Geen perfecte foto — ik zoek vooral details (kleur, glans, vorm).
            </div>
          </div>

          {/* Photo grid */}
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginBottom: 22 }}>
            {photos.map((p, i) => (
              <div key={i} style={{
                background: 'white', border: '1px solid var(--app-border)', borderRadius: 14,
                padding: 12, display: 'flex', gap: 12, alignItems: 'center',
              }}>
                <div style={{
                  width: 64, height: 64, borderRadius: 10,
                  background: p.ok ? 'linear-gradient(135deg, var(--mint-400), var(--bg-green-700))' : 'var(--canvas-2)',
                  border: p.ok ? 'none' : '1.5px dashed var(--app-border)',
                  display: 'grid', placeItems: 'center',
                  color: p.ok ? 'white' : 'var(--app-ink-3)', flexShrink: 0,
                }}>
                  {p.ok ? <span style={{ width: 22, height: 22 }}>{I.check}</span> : <span style={{ width: 24, height: 24 }}>{I.camera}</span>}
                </div>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 14, color: 'var(--app-ink)' }}>{p.t}</div>
                  <div style={{ fontSize: 12, color: 'var(--app-ink-3)', marginTop: 2, lineHeight: 1.35 }}>{p.d}</div>
                </div>
                <button className="iconbtn" style={{ background: p.ok ? 'var(--app-accent-soft)' : 'var(--app-accent)', color: p.ok ? 'var(--app-accent-strong)' : 'white', width: 32, height: 32, borderRadius: 8 }}>
                  <span style={{ width: 16, height: 16 }}>{p.ok ? I.history : I.plus}</span>
                </button>
              </div>
            ))}
          </div>

          {/* Quick assessment by owner */}
          <FieldLabel n="·" lbl="Hoe ziet Nova’s huid eruit?" />
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginBottom: 16 }}>
            {[
              { t: 'droog', sel: false }, { t: 'vet', sel: false }, { t: 'rood', sel: false },
              { t: 'schilfers', sel: true }, { t: 'kale plekken', sel: true }, { t: 'normaal', sel: false },
            ].map((tag) => (
              <span key={tag.t} className="chip" style={{ background: tag.sel ? 'var(--app-accent-soft)' : 'white', color: tag.sel ? 'var(--app-accent-strong)' : 'var(--app-ink-2)', border: tag.sel ? '1px solid var(--app-accent)' : '1px solid var(--app-border)', fontWeight: 600 }}>
                {tag.t}
              </span>
            ))}
          </div>

          <FieldLabel n="·" lbl="Hoe ziet haar haar / vacht eruit?" />
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginBottom: 16 }}>
            {[
              { t: 'glanzend', sel: true }, { t: 'dof', sel: true }, { t: 'kruinen', sel: false },
              { t: 'verkleurd', sel: false }, { t: 'wisselt met seizoen', sel: false },
            ].map((tag) => (
              <span key={tag.t} className="chip" style={{ background: tag.sel ? 'var(--app-accent-soft)' : 'white', color: tag.sel ? 'var(--app-accent-strong)' : 'var(--app-ink-2)', border: tag.sel ? '1px solid var(--app-accent)' : '1px solid var(--app-border)', fontWeight: 600 }}>
                {tag.t}
              </span>
            ))}
          </div>

          <FieldLabel n="·" lbl="Nog iets fysieks dat je opvalt?" hint="Vrije tekst — voor wat niet in vakjes past." />
          <div className="field">
            <textarea rows={3} defaultValue="Bespiering lijkt links iets meer dan rechts. Misschien iets om te checken." />
          </div>
        </div>

        <div className="sticky-cta">
          <button className="btn-primary" disabled={true} style={{ opacity: 0.5 }}>3 foto's nog te uploaden</button>
          <button className="btn-ghost">Sla deze sectie op &amp; ga later verder</button>
        </div>
        <HomeIndicator />
      </div>
    </div>
  );
}

/* ============================================================
   06 · SUBMIT / SUMMARY
   ============================================================ */

function MobileSubmit() {
  return (
    <div className="phone" data-screen-label="06 Klant · Controleren & versturen">
      <StatusBar />
      <div className="screen">
        <div className="subhdr">
          <button className="iconbtn">{I.back}</button>
          <div className="title">Klaar om te versturen</div>
          <div style={{ width: 36 }}></div>
        </div>

        <div className="body" style={{ padding: '0 20px 110px' }}>
          <h2 style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 26, lineHeight: 1.15, margin: '0 0 8px' }}>
            Tien van tien. <span style={{ color: 'var(--app-accent-strong)' }}>Mooi werk.</span>
          </h2>
          <p style={{ fontSize: 14, color: 'var(--app-ink-2)', lineHeight: 1.55, marginBottom: 22 }}>
            Ik (Shelley) lees jouw intake binnen 3 werkdagen helemaal door en stuur Nova’s eerste protocol terug via de app. Je krijgt een notificatie.
          </p>

          {/* Summary cards per section */}
          <div style={{ background: 'white', border: '1px solid var(--app-border)', borderRadius: 16, marginBottom: 14 }}>
            {INTAKE_SECTIONS.slice(0, 10).map((s, i) => (
              <div key={s.id} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '12px 14px', borderBottom: i < 9 ? '1px solid var(--app-border)' : 'none' }}>
                <div style={{ width: 28, height: 28, borderRadius: '50%', background: 'var(--app-accent)', color: 'white', display: 'grid', placeItems: 'center', flexShrink: 0 }}>
                  <span style={{ width: 14, height: 14 }}>{I.check}</span>
                </div>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontFamily: 'var(--font-display)', fontWeight: 600, fontSize: 13.5, color: 'var(--app-ink)' }}>{s.title}</div>
                </div>
                <span style={{ fontSize: 12, color: 'var(--app-accent-strong)', fontWeight: 600 }}>Bewerk</span>
              </div>
            ))}
          </div>

          {/* Photos preview */}
          <div className="section-title"><h2>5 foto's bijgevoegd</h2></div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 6, marginBottom: 22 }}>
            {[0, 1, 2, 3, 4].map((i) => (
              <div key={i} style={{ aspectRatio: '1', borderRadius: 10, background: i < 5 ? 'linear-gradient(135deg, var(--mint-300), var(--bg-green-700))' : 'var(--canvas-2)', display: 'grid', placeItems: 'center', color: 'white' }}>
                <span style={{ width: 18, height: 18, opacity: 0.6 }}>{I.camera}</span>
              </div>
            ))}
          </div>

          <div style={{ background: 'var(--app-accent-soft)', borderRadius: 14, padding: 14, fontSize: 13, lineHeight: 1.5, color: 'var(--app-ink-2)', marginBottom: 8 }}>
            <span style={{ fontWeight: 700, color: 'var(--app-accent-strong)' }}>Even checken ·</span>{' '}
            Klopt alles? Na verzenden kun je nog wel <em>aanvullen</em>, maar antwoorden aanpassen kost mij wel een 'rerun'.
          </div>
        </div>

        <div className="sticky-cta">
          <button className="btn-primary deep" style={{ background: 'var(--app-accent)' }}>
            <span style={{ width: 18, height: 18 }}>{I.send}</span>
            Verzenden naar Shelley
          </button>
        </div>
        <HomeIndicator />
      </div>
    </div>
  );
}

/* ============================================================
   07 · WAIT / CONFIRMATION
   ============================================================ */

function MobileWaiting() {
  return (
    <div className="phone" data-screen-label="07 Klant · Verzonden">
      <StatusBar />
      <div className="screen">
        <div className="body" style={{ padding: '60px 24px 24px', display: 'flex', flexDirection: 'column', alignItems: 'center', textAlign: 'center' }}>
          <div style={{ position: 'relative', marginBottom: 28 }}>
            <div style={{ width: 120, height: 120, borderRadius: '50%', background: 'var(--app-accent-soft)', display: 'grid', placeItems: 'center' }}>
              <div style={{ width: 84, height: 84, borderRadius: '50%', background: 'var(--app-accent)', display: 'grid', placeItems: 'center', color: 'white' }}>
                <span style={{ width: 44, height: 44 }}>{I.check}</span>
              </div>
            </div>
          </div>

          <div style={{ fontFamily: 'var(--font-display)', fontStyle: 'italic', color: 'var(--app-accent-strong)', fontWeight: 600, fontSize: 13, marginBottom: 8, letterSpacing: '0.02em' }}>
            Dankjewel, Marit.
          </div>
          <h1 style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 28, lineHeight: 1.15, margin: 0, marginBottom: 14 }}>
            Verzonden. Ik ga ermee aan de slag.
          </h1>
          <p style={{ fontSize: 14, color: 'var(--app-ink-2)', lineHeight: 1.55, maxWidth: '32ch', margin: 0, marginBottom: 28 }}>
            Binnen 3 werkdagen krijg je een notificatie zodra Nova's eerste protocol klaar staat in de app.
          </p>

          {/* Status timeline */}
          <div style={{ alignSelf: 'stretch', background: 'white', border: '1px solid var(--app-border)', borderRadius: 16, padding: '16px 18px', marginBottom: 14 }}>
            <div className="timeline" style={{ paddingLeft: 22 }}>
              <div className="step done">
                <div className="when">vandaag · 21:48</div>
                <div className="what">Intake verzonden naar Shelley</div>
              </div>
              <div className="step now">
                <div className="when">verwacht binnen 24u</div>
                <div className="what">Shelley leest jouw intake door</div>
              </div>
              <div className="step">
                <div className="when">binnen 3 werkdagen</div>
                <div className="what">Nova's eerste protocol staat klaar in de app</div>
              </div>
              <div className="step" style={{ paddingBottom: 0 }}>
                <div className="when">vanaf publicatie</div>
                <div className="what">Dagelijks plan loopt — Shelley volgt mee via de app</div>
              </div>
            </div>
          </div>
        </div>
        <div className="sticky-cta">
          <button className="btn-primary">Terug naar de app</button>
        </div>
        <HomeIndicator />
      </div>
    </div>
  );
}

/* Expose */
Object.assign(window, {
  MobileWelcome, MobileSectionList, MobileSectionVoer,
  MobileSectionKlacht, MobileSectionFysiek, MobileSubmit, MobileWaiting,
});
