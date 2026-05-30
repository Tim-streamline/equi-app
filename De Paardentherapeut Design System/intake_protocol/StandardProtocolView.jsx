/* global React, PROTOCOL_DARMEN */
// StandardProtocolView.jsx, full reference document for the standard
// "Darmrevalidatie" protocol, beautifully laid out so the developer
// can read top→bottom and see exactly what the engine needs to drive.

function StandardProtocolDoc() {
  const p = PROTOCOL_DARMEN;
  return (
    <div className="proto-doc">
      <header className="proto-head">
        <div className="proto-kicker">Standaard-protocol · Voor het Holistisch Herstelplan</div>
        <h1>{p.naam}</h1>
        <p className="proto-sub">
          De basis-template die het systeem als concept-protocol klaar zet zodra een intake binnenkomt. Shelley reviewt en past aan voor het individuele paard. Totaalduur: <b>{p.totaalDuur}</b>.
        </p>
        <div className="proto-base-on">Bron: <em>{p.basedOn}</em></div>
      </header>

      {/* SAFETY CHECKS */}
      <section className="proto-section danger">
        <h2><span className="emoji">⛔</span>Veiligheidschecks vóór start</h2>
        <p className="lede">
          Het systeem evalueert deze tegen de intake-antwoorden. <b>Block</b> = protocol mag niet starten · <b>Modify</b> = item toevoegen/weglaten · <b>Warn</b> = waarschuwing tonen, protocol mag wel starten.
        </p>
        <table className="ptbl">
          <thead><tr><th>Als…</th><th>Dan…</th><th>Level</th></tr></thead>
          <tbody>
            {p.veiligheidsChecks.map((c, i) => (
              <tr key={i} data-level={c.level}>
                <td><b>{c.als}</b></td>
                <td>
                  {c.dan}
                  {c.melding && <div className="msg">"{c.melding}"</div>}
                </td>
                <td><span className={`lvl lvl-${c.level}`}>{c.level}</span></td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>

      {/* BASIS */}
      <section className="proto-section">
        <h2><span className="emoji">📐</span>Basis-regels: altijd, voor elk paard</h2>
        <table className="ptbl two-col">
          <tbody>
            {p.basis.map((b, i) => (
              <tr key={i}><th>{b.regel}</th><td>{b.waarde}</td></tr>
            ))}
          </tbody>
        </table>
      </section>

      {/* ZWARTE LIJST */}
      <section className="proto-section">
        <h2><span className="emoji">🚫</span>Zwarte lijst: verboden tijdens protocol</h2>
        <p className="lede">Wat sowieso uit het voer moet zolang het protocol loopt. App toont een rode banner als ingredient uit deze lijst gescand wordt.</p>
        <div className="zwart-grid">
          <div className="zwart-col">
            <h4>Voeding</h4>
            <ul>{p.zwarteLijst.voeding.map((v, i) => <li key={i}>{v}</li>)}</ul>
          </div>
          <div className="zwart-col">
            <h4>Supplementen</h4>
            <ul>{p.zwarteLijst.supplementen.map((v, i) => <li key={i}>{v}</li>)}</ul>
          </div>
          <div className="zwart-col">
            <h4>Snacks</h4>
            <ul>{p.zwarteLijst.snacks.map((v, i) => <li key={i}>{v}</li>)}</ul>
          </div>
        </div>
      </section>

      {/* PHASES */}
      {p.fases.map((f) => (
        <PhaseSection key={f.id} f={f} />
      ))}

      {/* SIGNALEN */}
      <section className="proto-section signals">
        <h2><span className="emoji">🚨</span>Signaalmomenten: auto-flags op dashboard</h2>
        <p className="lede">
          Wat Shelley automatisch ziet zodra de klant check-ins doet in de app. Het systeem moet deze patronen detecteren op de wekelijkse check-in data.
        </p>
        <div className="signal-grid">
          {p.signalen.map((s, i) => (
            <div key={i} className={`sig sig-${s.level}`}>
              <div className="sig-level">{s.level}</div>
              <div className="sig-trigger">{s.trigger}</div>
              <div className="sig-actie">{s.actie}</div>
            </div>
          ))}
        </div>
      </section>

      {/* VERWACHTINGEN */}
      <section className="proto-section expect">
        <h2><span className="emoji">💬</span>Wat de gebruiker mag verwachten: app-berichten</h2>
        <p className="lede">Verschijnt automatisch in de app als motivatie & verwachtingsmanagement op het juiste moment in het traject.</p>
        <div className="expect-list">
          {p.verwachtingen.map((v, i) => (
            <div key={i} className="ex-row">
              <div className="ex-when">{v.wanneer}</div>
              <div className="ex-msg">"{v.bericht}"</div>
            </div>
          ))}
        </div>
      </section>

      <footer className="proto-foot">
        Document gegenereerd uit <code>ProtocolTemplate.jsx</code>. Voor alternatieve klacht-protocollen (jeuk/zomereczeem, staakgedrag, hoefbevangenheid…): clone deze structuur, vervang fasen.
      </footer>
    </div>
  );
}

function PhaseSection({ f }) {
  if (f.kind === 'evaluation') {
    return (
      <section className="proto-section eval">
        <h2><span className="emoji">📋</span>{f.naam}</h2>
        <div className="eval-questions">
          {f.vragen.map((v, i) => (
            <div key={i} className="eq">
              <span className="nr">{String(i + 1).padStart(2, '0')}</span>
              <span className="q">{v}</span>
              <span className="ans">ja · soms · nee · bezorgd</span>
            </div>
          ))}
        </div>
        <h4>Logica</h4>
        <table className="ptbl">
          <thead><tr><th>Als…</th><th>Dan…</th></tr></thead>
          <tbody>
            {f.uitkomsten.map((u, i) => (
              <tr key={i}><td>{u.als}</td><td>{u.dan}</td></tr>
            ))}
          </tbody>
        </table>
      </section>
    );
  }

  return (
    <section className={`proto-section phase phase-${f.id}`}>
      <div className="phase-head">
        <div>
          <h2>{f.naam}</h2>
          <div className="phase-meta">
            <span><b>Duur:</b> {f.duur}</span>
            {f.activeWhen && <span><b>Start:</b> {f.activeWhen}</span>}
            {f.optional && <span className="opt-tag">optioneel</span>}
          </div>
          {f.goal && <div className="phase-goal">{f.goal}</div>}
        </div>
      </div>

      {/* Items table */}
      {f.items && (
        <>
          <h4>Dagelijkse to do's: basis</h4>
          <table className="ptbl items">
            <thead><tr><th>#</th><th>To do</th><th>Hoeveelheid</th><th>Tijdstip</th><th>Waarom</th></tr></thead>
            <tbody>
              {f.items.map((it) => (
                <tr key={it.nr}>
                  <td className="nr-cell">{it.nr}</td>
                  <td>
                    <b>{it.t}</b>
                    {it.stopAfter && <div className="warn-line">⏱ {it.stopAfter}</div>}
                    {it.alternateIf && <div className="warn-line">↔ {it.alternateIf}</div>}
                    {it.conditional && <div className="warn-line">▸ {it.conditional}</div>}
                  </td>
                  <td className="dos">{it.dosis}</td>
                  <td>{it.freq}</td>
                  <td className="waarom">{it.waarom}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </>
      )}

      {/* Oneven/even ritme for fase 2 */}
      {f.oneven && (
        <>
          <h4>Ritme: oneven weken (actief)</h4>
          <table className="ptbl items">
            <thead><tr><th>#</th><th>To do</th><th>Hoeveelheid</th><th>Tijdstip</th><th>Waarom</th></tr></thead>
            <tbody>
              {f.oneven.items.map((it) => (
                <tr key={it.nr}>
                  <td className="nr-cell">{it.nr}</td>
                  <td>
                    <b>{it.t}</b>
                    {it.skipIf && <div className="warn-line">↳ skip als: {it.skipIf}</div>}
                  </td>
                  <td className="dos">{it.dosis}</td>
                  <td>{it.freq}</td>
                  <td className="waarom">{it.waarom}</td>
                </tr>
              ))}
            </tbody>
          </table>

          <h4>Ritme: even weken (stopweek)</h4>
          <table className="ptbl items">
            <thead><tr><th>#</th><th>To do</th><th>Hoeveelheid</th><th>Tijdstip</th><th>Waarom</th></tr></thead>
            <tbody>
              {f.even.items.map((it) => (
                <tr key={it.nr}>
                  <td className="nr-cell">{it.nr}</td>
                  <td>
                    <b>{it.t}</b>
                    {it.warning && <div className="warn-line warn-strong">⚠ {it.warning}</div>}
                  </td>
                  <td className="dos">{it.dosis}</td>
                  <td>{it.freq}</td>
                  <td className="waarom">{it.waarom}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </>
      )}

      {/* Conditional add-ons */}
      {f.conditionalAddOns && f.conditionalAddOns.length > 0 && (
        <>
          <h4>Voorwaardelijke toevoegingen</h4>
          <table className="ptbl">
            <thead><tr><th>Als intake…</th><th>Voeg toe</th><th>Dosering · timing</th></tr></thead>
            <tbody>
              {f.conditionalAddOns.map((a, i) => (
                <tr key={i}>
                  <td>{a.als}</td>
                  <td><b>{a.add}</b></td>
                  <td>
                    {a.dosis && <div>{a.dosis}</div>}
                    {a.freq && <div>{a.freq}</div>}
                    {a.duur && <div>{a.duur}</div>}
                    {a.timing && <div className="warn-line">{a.timing}</div>}
                    {a.extra && <div className="warn-line">+ {a.extra}</div>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </>
      )}

      {/* Stop regels */}
      {f.stops && f.stops.length > 0 && (
        <>
          <h4>Stop-regels</h4>
          <ul className="stops">
            {f.stops.map((s, i) => <li key={i}>{s}</li>)}
          </ul>
        </>
      )}

      {f.evaluatie && (
        <div className="evaluatie-line">📋 <b>Evaluatie:</b> {f.evaluatie}</div>
      )}

      {/* Regels voor 1b uitkomsten */}
      {f.uitkomsten && (
        <>
          <h4>Vervolgregels</h4>
          <table className="ptbl">
            <thead><tr><th>Als…</th><th>Dan…</th></tr></thead>
            <tbody>
              {f.uitkomsten.map((u, i) => (
                <tr key={i}><td>{u.als}</td><td>{u.dan}</td></tr>
              ))}
            </tbody>
          </table>
        </>
      )}
    </section>
  );
}

window.StandardProtocolDoc = StandardProtocolDoc;
