/* global React, I, CONCEPT_PROTOCOL, CLIENT_MONITORING, WEEK6_EVAL, DashShell, Quickstat */
// ConceptProtocolView.jsx — drie schermen voor het therapeut-portaal:
//   01 · DashConceptProtocol  — auto-gegenereerd protocol klaarzetten & publiceren
//   02 · DashMonitoring       — lopende protocollen live volgen
//   03 · DashWeek6Eval        — wekelijkse / 6-wk evaluatie reviewen

const { useState: useCS } = React;

/* ============================================================
   DashConceptProtocol — pronkstuk
   ============================================================ */

function DashConceptProtocol() {
  const p = CONCEPT_PROTOCOL;
  const [openPhase, setOpenPhase] = useCS('f0');
  const [previewWeek, setPreviewWeek] = useCS(1);

  return (
    <DashShell
      active="inbox"
      sublabel="Nieuwe intakes · INT-2026-051 · Concept-protocol"
      label="Concept-protocol voor Nova"
      right={
        <div style={{ display: 'flex', gap: 8 }}>
          <button className="dash-btn ghost"><span style={{ width: 14, height: 14 }}>{I.clipboard}</span>Bron-intake</button>
          <button className="dash-btn ghost">Opslaan als concept</button>
          <button className="dash-btn primary"><span style={{ width: 16, height: 16 }}>{I.send}</span>Publiceer naar Nova in app</button>
        </div>
      }
    >
      {/* HERO + GENESIS */}
      <div className="cp-hero">
        <div className="cp-hero-meta">
          <div className="k">Auto-gegenereerd · template Darmrevalidatie</div>
          <h2>Nova · {p.template.split('(')[0].trim()}</h2>
          <p className="lede">{p.summary}</p>
          <div className="cause">
            <div className="k">Waarschijnlijkste oorzaak</div>
            <p>{p.cause}</p>
            <span className="conf">Vertrouwen <em>{p.confidence}</em> · op basis van intake + standaard-protocol</span>
          </div>
        </div>
        <div className="cp-hero-stats">
          <CPStat l="Veiligheids­checks" v={`${p.veiligheidsChecks.filter((c) => c.status === 'ok').length}/${p.veiligheidsChecks.length}`} sub="ok" tone="ok" />
          <CPStat l="Modificaties" v={p.modifications.length} sub="auto-toegepast" tone="warn" />
          <CPStat l="Doseergewicht" v={`× ${p.doseergewicht.factor}`} sub={`${p.doseergewicht.intake} kg / ${p.doseergewicht.template} kg basis`} tone="ok" />
          <CPStat l="Totale duur" v="24+ wk" sub="fase 0 + 1 + 2" tone="ok" />
        </div>
      </div>

      {/* GRID: LEFT = bericht/notities · RIGHT = builder */}
      <div className="cp-grid">

        {/* ============ LEFT — bericht + bron + verwachtingen ============ */}
        <aside className="cp-left">

          <div className="dash-card">
            <div className="card-head"><span className="t">Bericht aan Marit</span><span className="hint">verschijnt bovenaan protocol in app</span></div>
            <textarea className="notes-area" rows={6} defaultValue={p.shelleysNote} />
            <div className="card-foot">Persoonlijke noot — verandert per paard.</div>
          </div>

          <div className="dash-card">
            <div className="card-head"><span className="t">Verwachtings­berichten</span><span className="hint">verschijnen automatisch per periode</span></div>
            <div className="vw-list">
              {p.verwachtingen.map((v) => (
                <div key={v.wanneer} className="vw">
                  <div className="vw-w">{v.wanneer}</div>
                  <div className="vw-b">{v.bericht}</div>
                </div>
              ))}
            </div>
          </div>

          <div className="dash-card">
            <div className="card-head"><span className="t">Signaal­momenten</span><span className="hint">triggeren melding bij jou</span></div>
            <ul className="sig-list">
              {p.signalen.map((s, i) => (
                <li key={i} data-level={s.level}>
                  <span className="lvl" />
                  <div>
                    <div className="trg">{s.trigger}</div>
                    <div className="act">{s.actie}</div>
                  </div>
                </li>
              ))}
            </ul>
          </div>

          <div className="dash-card">
            <div className="card-head"><span className="t">Bron-intake</span></div>
            <div className="src-list">
              <a><span style={{ width: 14, height: 14 }}>{I.clipboard}</span>Volledige intake (10 secties)</a>
              <a><span style={{ width: 14, height: 14 }}>{I.camera}</span>8 foto's bekijken</a>
              <a><span style={{ width: 14, height: 14 }}>{I.alert}</span>11 aandachtspunten</a>
              <a><span style={{ width: 14, height: 14 }}>{I.download}</span>Exporteer als PDF voor Marit</a>
            </div>
          </div>
        </aside>

        {/* ============ RIGHT — builder ============ */}
        <section className="cp-right">

          {/* Veiligheidschecks */}
          <div className="dash-card sv-card">
            <div className="card-head">
              <span className="ic">{I.heart}</span>
              <span className="t">Veiligheids­checks</span>
              <span className="hint">automatisch afgewogen vanuit intake · {p.veiligheidsChecks.length} checks</span>
            </div>
            <div className="sv-grid">
              {p.veiligheidsChecks.map((c) => (
                <div key={c.id} className="sv-cell" data-status={c.status}>
                  <span className="sv-ic">
                    {c.status === 'ok'     && <span style={{ width: 12, height: 12 }}>{I.check}</span>}
                    {c.status === 'warn'   && <span style={{ width: 12, height: 12 }}>{I.alert}</span>}
                    {c.status === 'block'  && <span style={{ width: 12, height: 12 }}>{I.close}</span>}
                    {c.status === 'modify' && <span style={{ width: 12, height: 12 }}>{I.sparkles}</span>}
                  </span>
                  <div>
                    <div className="sv-c">{c.check}</div>
                    <div className="sv-b">{c.bewijs}</div>
                  </div>
                </div>
              ))}
            </div>
          </div>

          {/* Auto-toegepaste modificaties */}
          <div className="dash-card mod-card">
            <div className="card-head">
              <span className="ic">{I.sparkles}</span>
              <span className="t">Auto-toegepaste aanpassingen</span>
              <span className="hint">{p.modifications.length} regels vanuit intake</span>
            </div>
            <ul className="mod-list">
              {p.modifications.map((m) => (
                <li key={m.id}>
                  <div className="mod-trg">
                    <span className="k">Trigger</span>
                    <code>{m.trigger}</code>
                  </div>
                  <span className="arr">→</span>
                  <div className="mod-eff">
                    <span className="k">Effect</span>
                    <span>{m.effect}</span>
                  </div>
                </li>
              ))}
            </ul>
          </div>

          {/* Phases */}
          <div className="dash-card">
            <div className="card-head">
              <span className="t">Fase-opbouw</span>
              <span className="hint">klap open om items te bewerken · doseringen al herberekend op {p.doseergewicht.intake} kg</span>
              <button className="dash-btn small ghost" style={{ marginLeft: 'auto' }}>
                <span style={{ width: 14, height: 14 }}>{I.plus}</span>Fase
              </button>
            </div>

            <div className="cp-phases">
              {p.phases.map((ph, i) => (
                <CPPhase
                  key={ph.id}
                  ph={ph}
                  idx={i}
                  open={openPhase === ph.id}
                  onClick={() => setOpenPhase(openPhase === ph.id ? null : ph.id)}
                />
              ))}
            </div>
          </div>

          {/* Live preview */}
          <div className="dash-card">
            <div className="card-head">
              <span className="t">Hoe Marit dit ziet in de app</span>
              <span className="hint">live-preview · week-selector rechts</span>
              <div className="wk-select" style={{ marginLeft: 'auto' }}>
                {[1, 3, 7, 13].map((w) => (
                  <button key={w} className={previewWeek === w ? 'active' : ''} onClick={() => setPreviewWeek(w)}>wk {w}</button>
                ))}
              </div>
            </div>
            <CPPhonePreview week={previewWeek} protocol={p} />
          </div>
        </section>
      </div>
    </DashShell>
  );
}

function CPStat({ l, v, sub, tone }) {
  return (
    <div className="cp-stat" data-tone={tone}>
      <div className="l">{l}</div>
      <div className="v">{v}</div>
      <div className="s">{sub}</div>
    </div>
  );
}

function CPPhase({ ph, idx, open, onClick }) {
  const totalItems = ph.items
    ? ph.items.length
    : (ph.ritmeOneven ? ph.ritmeOneven.items.length + (ph.ritmeEven ? ph.ritmeEven.items.length : 0) : 0);
  return (
    <div className={`cp-phase ${open ? 'open' : ''}`} data-state={ph.state || 'todo'}>
      <div className="cp-phase-row" onClick={onClick}>
        <span className="cp-grip">⋮⋮</span>
        <span className="cp-nr">{idx === 0 ? 'PRE' : `FASE ${idx}`}</span>
        <div className="cp-phase-name">
          <span className="n">{ph.name}</span>
          {ph.activated && <span className="cp-trig">geactiveerd via intake · {ph.activated}</span>}
          {ph.starts && <span className="cp-trig">{ph.starts}</span>}
        </div>
        <span className="cp-dur">{ph.duration}</span>
        <span className="cp-count">{totalItems} {ph.kind === 'evaluation' ? 'vragen' : 'items'}</span>
        <span className="cp-chev" style={{ transform: open ? 'rotate(90deg)' : 'rotate(0)' }}>{I.chevron}</span>
      </div>

      {open && (
        <div className="cp-phase-body">
          {ph.items && ph.items.map((it) => (
            <CPItem key={it.id} it={it} />
          ))}
          {ph.ritmeOneven && (
            <>
              <div className="cp-sublabel">{ph.ritmeOneven.label}</div>
              {ph.ritmeOneven.items.map((it) => <CPItem key={it.id} it={it} />)}
              <div className="cp-sublabel" style={{ marginTop: 8 }}>{ph.ritmeEven.label}</div>
              {ph.ritmeEven.items.map((it) => <CPItem key={it.id} it={it} />)}
            </>
          )}
          {ph.noten && (
            <div className="cp-noten">
              <div className="k">Noten</div>
              <ul>{ph.noten.map((n, i) => <li key={i}>{n}</li>)}</ul>
            </div>
          )}
          <button className="pe-add">
            <span style={{ width: 14, height: 14 }}>{I.plus}</span>
            Item toevoegen
          </button>
        </div>
      )}
    </div>
  );
}

function CPItem({ it }) {
  return (
    <div className="cp-item">
      <span className="cb"><span style={{ width: 12, height: 12, opacity: 0.4 }}>{I.check}</span></span>
      <div className="cp-item-body">
        <span className="t" contentEditable suppressContentEditableWarning>{it.t}</span>
        {it.note && <span className="note">{it.note}</span>}
        {it.warning && <span className="warn">⚠ {it.warning}</span>}
      </div>
      <span className={`pe-tag tag-${it.tag}`}>{it.tag}</span>
      <button className="pe-del" title="Verwijderen">{I.close}</button>
    </div>
  );
}

/* Phone preview — toont hoe protocol eruitziet per week */
function CPPhonePreview({ week, protocol }) {
  /* eenvoudige map: welke fase / wat verschijnt per week */
  const phaseLabel =
    week <= 1 ? 'Voorbereiding'
    : week <= 7 ? 'Fase 0 — Maagondersteuning'
    : week <= 9 ? 'Overgang fase 0 → fase 1'
    : week <= 15 ? 'Fase 1 — Darmrevalidatie'
    : 'Fase 2 — Lever & nieren';

  const verwachting =
    week <= 2 ? protocol.verwachtingen[0]
    : week <= 4 ? protocol.verwachtingen[1]
    : week <= 6 ? protocol.verwachtingen[2]
    : protocol.verwachtingen[3];

  const todos =
    week <= 1 ? [
      { ic: I.heart, c: '#A55A1F', bg: '#FFF1E6', t: 'Slowfeeder ophangen', d: 'Geen ruwvoer-pauze >2 uur' },
      { ic: I.leaf,  c: '#2E5B33', bg: '#E9F7E6', t: 'Mg + zeewier pauzeren', d: 'Zodat protocol-basis kan landen' },
      { ic: I.camera,c: '#127A79', bg: '#D8F0EF', t: 'Foto manen elke 3 dagen', d: 'Stuur via app' },
    ] : week <= 7 ? [
      { ic: I.leaf,  c: '#2E5B33', bg: '#E9F7E6', t: 'Bio lijnzaad · 72–90 gr', d: '2× daags, lauwwarm' },
      { ic: I.leaf,  c: '#2E5B33', bg: '#E9F7E6', t: 'Kaasjeskruid + Heemstwortel', d: '14 gr/kruid · 2× daags' },
    ] : [
      { ic: I.leaf,  c: '#2E5B33', bg: '#E9F7E6', t: 'Salie 9 gr', d: '1× daags · STOP na 4 wk' },
      { ic: I.leaf,  c: '#2E5B33', bg: '#E9F7E6', t: 'Lapachoschors 9–18 gr', d: '1× daags' },
      { ic: I.leaf,  c: '#2E5B33', bg: '#E9F7E6', t: 'Smalle weegbree 27–45 gr', d: '1× daags' },
    ];

  return (
    <div className="app-preview">
      <div className="preview-phone">
        <div className="ph-status" />
        <div className="ph-head">
          <div className="ttl">Nova's plan</div>
          <div className="sub">Week {week} · {phaseLabel}</div>
          <div className="tabs">
            <span className="tab active">Vandaag</span>
            <span className="tab">Protocol</span>
            <span className="tab">Kalender</span>
            <span className="tab">Analyse</span>
          </div>
        </div>
        <div className="ph-body">
          <div className="ph-cause">
            <div className="k">Verwachting · {verwachting.wanneer}</div>
            <div className="d">{verwachting.bericht}</div>
          </div>
          <div className="ph-section">Vandaag · {todos.length} acties</div>
          {todos.map((t, i) => (
            <div className="ph-row" key={i}>
              <span className="ic" style={{ background: t.bg, color: t.c }}>{t.ic}</span>
              <div><div className="t">{t.t}</div><div className="d">{t.d}</div></div>
            </div>
          ))}
        </div>
      </div>

      <div className="preview-note">
        <h4>Wat verandert er voor Marit bij publiceren?</h4>
        <ul>
          <li>Notificatie: <em>"Nova's protocol staat klaar"</em></li>
          <li>Home toont jouw <em>persoonlijke bericht</em> als eerste Coach-card</li>
          <li>Kalender vult zich automatisch met alle to-do's, 12+ weken vooruit</li>
          <li>Wekelijkse check-in elke maandag — antwoorden landen bij jou</li>
          <li>Signaal-momenten staan actief: 5 regels die bij jou notificeren</li>
        </ul>
      </div>
    </div>
  );
}

/* ============================================================
   DashMonitoring — lopende protocollen
   ============================================================ */

function DashMonitoring() {
  const [signal, setSignal] = useCS('alle');
  const rows = signal === 'alle'
    ? CLIENT_MONITORING
    : CLIENT_MONITORING.filter((r) => r.signal === signal);

  const cnt = {
    rood:   CLIENT_MONITORING.filter((r) => r.signal === 'rood').length,
    oranje: CLIENT_MONITORING.filter((r) => r.signal === 'oranje').length,
    geel:   CLIENT_MONITORING.filter((r) => r.signal === 'geel').length,
  };

  return (
    <DashShell
      active="monitoring"
      sublabel="Lopende protocollen"
      label="Live overzicht · 8 paarden in traject"
      right={
        <div style={{ display: 'flex', gap: 8 }}>
          <button className="dash-btn ghost"><span style={{ width: 16, height: 16 }}>{I.download}</span>Export rapport</button>
          <button className="dash-btn ghost">Klantenlijst</button>
        </div>
      }
    >
      {/* Top KPI's */}
      <div className="mon-kpi">
        <div className="mon-kpi-card">
          <div className="l">Actieve protocollen</div>
          <div className="v">8</div>
          <div className="s">7 Herstelplan · 1 in evaluatie</div>
        </div>
        <div className="mon-kpi-card" data-tone="rood">
          <div className="l">Rood signaal</div>
          <div className="v">{cnt.rood}</div>
          <div className="s">{cnt.rood === 0 ? 'Niets dringends' : 'Reageer binnen 24u'}</div>
        </div>
        <div className="mon-kpi-card" data-tone="oranje">
          <div className="l">Oranje signaal</div>
          <div className="v">{cnt.oranje}</div>
          <div className="s">Aandacht deze week</div>
        </div>
        <div className="mon-kpi-card" data-tone="geel">
          <div className="l">Geel · auto-reminders</div>
          <div className="v">{cnt.geel}</div>
          <div className="s">Geen check-in &gt;5 dgn</div>
        </div>
        <div className="mon-kpi-card">
          <div className="l">Evaluaties wachtend</div>
          <div className="v">2</div>
          <div className="s">1 × wk 6 · 1 × eind</div>
        </div>
      </div>

      {/* Filters */}
      <div className="filter-row">
        {[
          { id: 'alle',   t: 'Alle',          c: CLIENT_MONITORING.length },
          { id: 'rood',   t: 'Rood',          c: cnt.rood,   accent: 'rood' },
          { id: 'oranje', t: 'Oranje',        c: cnt.oranje, accent: 'oranje' },
          { id: 'geel',   t: 'Geel',          c: cnt.geel,   accent: 'geel' },
        ].map((f) => (
          <button key={f.id} className={signal === f.id ? 'filter active' : 'filter'} onClick={() => setSignal(f.id)} data-sig={f.accent}>
            {f.accent && <span className="sig-dot" data-l={f.accent} />}
            {f.t}<span className="ct">{f.c}</span>
          </button>
        ))}
        <div style={{ marginLeft: 'auto' }}>
          <div className="dash-search">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            <input placeholder="Zoek op paard, eigenaar…" />
          </div>
        </div>
      </div>

      {/* Monitoring grid */}
      <div className="mon-grid">
        {rows.map((r) => (
          <div key={r.id} className={`mon-card ${r.signal ? 'sig-' + r.signal : ''}`}>
            <div className="mon-head">
              <div className="av">{r.av}</div>
              <div className="who">
                <div className="hn">{r.horse}</div>
                <div className="on">{r.owner}</div>
              </div>
              {r.signal && <span className="sig-pill" data-l={r.signal}>● {r.signal}</span>}
            </div>

            <div className="mon-phase">{r.phase} · {r.week}</div>
            <div className="mon-bar"><i style={{ width: `${r.pct}%` }} /></div>

            <div className="mon-row">
              <span className="k">Laatste check-in</span>
              <span className="v">{r.checkin}</span>
            </div>
            <div className="mon-row">
              <span className="k">Stemming eigenaar</span>
              <span className="v mood">
                {r.mood === 'top'    && <>😊 top</>}
                {r.mood === 'goed'   && <>🙂 goed</>}
                {r.mood === 'ok'     && <>😐 ok</>}
                {r.mood === 'minder' && <>😕 minder</>}
                {r.mood === '?'      && <>· geen check-in</>}
              </span>
            </div>
            <div className="mon-note">{r.note}</div>

            <div className="mon-actions">
              <button className="dash-btn small">Open protocol</button>
              {r.signal && <button className="dash-btn small primary">Reageer</button>}
            </div>
          </div>
        ))}
      </div>
    </DashShell>
  );
}

/* ============================================================
   DashWeek6Eval — wekelijkse evaluatie reviewen
   ============================================================ */

function DashWeek6Eval() {
  const e = WEEK6_EVAL;
  return (
    <DashShell
      active="evaluaties"
      sublabel={`Evaluaties · ${e.id} · ${e.horse}`}
      label={`Evaluatie week ${e.weeks} · ${e.horse}`}
      right={
        <div style={{ display: 'flex', gap: 8 }}>
          <button className="dash-btn ghost">Bekijk volledig protocol</button>
          <button className="dash-btn ghost">Override → Fase 1b</button>
          <button className="dash-btn primary"><span style={{ width: 16, height: 16 }}>{I.send}</span>Bevestig → start Fase 2</button>
        </div>
      }
    >
      {/* Hero */}
      <div className="ev-hero">
        <div className="ev-meta">
          <div className="k">Evaluatie · {e.evalDate}</div>
          <h2>{e.horse} · {e.owner}</h2>
          <p className="lede">Fase 1 voltooid · 6 weken darmrevalidatie. Marit heeft de evaluatievragen ingevuld en de wekelijkse observaties zijn binnen.</p>
        </div>
        <div className="ev-decision">
          <div className="k">Auto-beoordeling</div>
          <div className="v">{e.decision}</div>
          <p>{e.reasoning}</p>
        </div>
      </div>

      <div className="ev-grid">

        {/* Evaluatievragen */}
        <div className="dash-card">
          <div className="card-head">
            <span className="t">6 evaluatievragen</span>
            <span className="hint">ingevuld door Marit · {e.evalDate}</span>
          </div>
          <div className="ev-answers">
            {e.answers.map((a, i) => (
              <div key={i} className="ev-ans" data-state={a.state}>
                <span className="ev-ic">
                  {a.state === 'ok'    && <span style={{ width: 12, height: 12 }}>{I.check}</span>}
                  {a.state === 'mild'  && <span style={{ width: 12, height: 12 }}>{I.alert}</span>}
                  {a.state === 'fail'  && <span style={{ width: 12, height: 12 }}>{I.close}</span>}
                </span>
                <div className="ev-qa">
                  <div className="q">{a.q}</div>
                  <div className="a">{a.a}</div>
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* Observatie-tijdlijn */}
        <div className="dash-card">
          <div className="card-head">
            <span className="t">Wekelijkse observaties</span>
            <span className="hint">6 weken aan signalen · jeuk 8/10 → 3/10</span>
          </div>
          <div className="ev-timeline">
            <div className="ev-tl-head">
              <div>Week</div>
              <div>Mest</div>
              <div>Jeuk</div>
              <div>Energie</div>
              <div>Notities</div>
            </div>
            {e.observaties.map((o) => (
              <div key={o.wk} className="ev-tl-row">
                <div className="wk">wk {o.wk}</div>
                <div><span className={`grade g-${o.mest.replace('+', 'plus').replace('-', 'min')}`}>{o.mest}</span></div>
                <div className="sc">{o.jeuk}</div>
                <div className="sc">{o.energie}</div>
                <div className="nt">{o.notes}</div>
              </div>
            ))}
          </div>
        </div>

        {/* Vergelijking */}
        <div className="dash-card ev-summary">
          <div className="card-head">
            <span className="ic">{I.sparkles}</span>
            <span className="t">Wat het systeem voorstelt</span>
            <span className="hint">jij kunt het altijd overschrijven</span>
          </div>
          <div className="ev-summary-body">
            <div className="ev-row">
              <div className="ev-col">
                <div className="k">Beslisregel</div>
                <p>Bij 5/6 antwoorden stabiel + 1 mild: <b>door naar Fase 2</b>. Bij &gt;1 niet-stabiel: Fase 1b. Twijfel: Shelley besluit.</p>
              </div>
              <div className="ev-col">
                <div className="k">Wat dit doet</div>
                <p>Bij bevestigen wordt <b>Fase 2 · Lever & nieren</b> per direct geactiveerd. Marit krijgt notificatie in de app, dagplan en kalender worden voor 12 weken bijgewerkt.</p>
              </div>
              <div className="ev-col">
                <div className="k">Bij twijfel</div>
                <p>Klik <em>"Override → Fase 1b"</em> om eerst 4 wk extra darmondersteuning te geven. Of <em>"Stuur Marit bericht"</em> voor één extra check-foto.</p>
              </div>
            </div>
          </div>
        </div>

      </div>
    </DashShell>
  );
}

/* Expose */
Object.assign(window, { DashConceptProtocol, DashMonitoring, DashWeek6Eval });
