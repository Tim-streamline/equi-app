/* global React, INTAKE_FORM_SCHEMA, THERAPIST_FLAG_RULES, PROTOCOL_DECISION_TREE */
// IntakeSpec.jsx, full developer-spec view of the intake form.
// Renders every section, every field, with type & validation. The
// developer can read top→bottom and implement exactly what's here.

function IntakeSpecDoc() {
  const total = INTAKE_FORM_SCHEMA.reduce((acc, s) => acc + s.fields.filter((f) => f.type !== 'sectionhead').length, 0);
  const mins  = INTAKE_FORM_SCHEMA.reduce((acc, s) => acc + (s.minutes || 0), 0);

  return (
    <div className="spec-doc">
      <header className="spec-head">
        <div className="spec-kicker">Intake-formulier · Spec voor developer</div>
        <h1>Het Holistisch Herstelplan: intake</h1>
        <p className="spec-sub">
          Wat de klant invult na betalen van Het Holistisch Herstelplan (€97). 10 secties, alle vragen hieronder uitgewerkt met veldtype, validatie en flag-regels.
        </p>
        <div className="spec-stats">
          <div><span className="v">{INTAKE_FORM_SCHEMA.length}</span><span className="l">secties</span></div>
          <div><span className="v">{total}</span><span className="l">velden</span></div>
          <div><span className="v">~{mins}</span><span className="l">min totaal</span></div>
          <div><span className="v">{THERAPIST_FLAG_RULES.length}</span><span className="l">flag-regels</span></div>
        </div>

        <div className="spec-legend">
          <h4>Veldtypes</h4>
          <div className="legend-grid">
            <Legend type="text"     desc="Korte single-line invoer" />
            <Legend type="textarea" desc="Vrije tekst, meerregelig" />
            <Legend type="number"   desc="Numeriek + unit (kg, jr, cm…)" />
            <Legend type="date"     desc="Datum-picker" />
            <Legend type="radio"    desc="Eén keuze uit opties" />
            <Legend type="multi"    desc="Meerdere keuzes uit opties (chips)" />
            <Legend type="file"     desc="Document-upload (PDF, bv. bloeduitslag)" />
            <Legend type="photo"    desc="Foto-upload (camera of bestand)" />
            <Legend type="repeater" desc="Dynamische lijst (bv. supplementen)" />
          </div>
        </div>

        <div className="spec-rules">
          <h4>Logica & state</h4>
          <ul>
            <li><b>Auto-save</b> per veld bij blur of na 1.5s typen-stil. Toon stille "Opgeslagen 2 min geleden" indicator.</li>
            <li><b>showIf</b> = veld alleen tonen als andere veld een bepaalde waarde heeft (zie per veld).</li>
            <li><b>flagIf</b> = waarde triggert "aandachtspunt" voor Shelley's review (geen blokkade voor de klant).</li>
            <li><b>criticalIf</b> = waarde blokkeert protocol-start tot Shelley het beoordeeld heeft (drachtig, koorts, koliek…).</li>
            <li><b>required</b> velden moeten ingevuld zijn voor 'volgende sectie'. Optionele velden mogen leeg blijven.</li>
            <li><b>Pause &amp; resume</b>: klant kan op elk moment stoppen, terugkomt waar ze gebleven was.</li>
            <li><b>Wijzigen na verzenden</b>: aanvullen mag (foto bv); antwoorden wijzigen vereist 'rerun'-mail naar Shelley.</li>
          </ul>
        </div>
      </header>

      {/* SECTIONS */}
      {INTAKE_FORM_SCHEMA.map((sec) => (
        <SectionBlock key={sec.id} sec={sec} />
      ))}

      {/* DECISION TREE, wat triggert wat in het protocol */}
      <section className="spec-section decision-section">
        <header className="sec-head">
          <div className="nr">A</div>
          <div>
            <h2>Auto-protocol beslisboom</h2>
            <div className="sub">Wat het systeem bij verzenden van de intake automatisch klaarzet: vóór Shelley reviewt.</div>
            <div className="meta">{PROTOCOL_DECISION_TREE.length} regels · 6 soorten effect</div>
          </div>
        </header>
        <div className="spec-legend">
          <h4>Effect-types</h4>
          <div className="legend-grid">
            <Legend type="block"   desc="Protocol mag NIET starten" />
            <Legend type="warn"    desc="Waarschuwing tonen: mag wel starten" />
            <Legend type="modify"  desc="Vast item wijzigen of weglaten" />
            <Legend type="addon"   desc="Conditioneel item toevoegen" />
            <Legend type="phase"   desc="Hele fase activeren/skippen" />
            <Legend type="compute" desc="Numerieke herberekening (bv. doseergewicht)" />
          </div>
        </div>
        <div className="decision-tree">
          {PROTOCOL_DECISION_TREE.map((d, i) => (
            <div key={i} className="decision-row">
              <span className={`kind ${d.kind}`}>{d.kind}</span>
              <code>{d.als}</code>
              <span className="arr">→</span>
              <span className="effect">{d.dan}</span>
            </div>
          ))}
        </div>
      </section>

      {/* FLAG RULES */}
      <section className="spec-section flag-section">
        <header className="sec-head">
          <div className="nr">F</div>
          <div>
            <h2>Auto-flag regels</h2>
            <div className="sub">Welke antwoorden surfacen op Shelley's review-pane?</div>
          </div>
        </header>
        <table className="ruletbl">
          <thead><tr><th>Aandachtspunt</th><th>Bron-veld</th><th>Trigger-conditie</th></tr></thead>
          <tbody>
            {THERAPIST_FLAG_RULES.map((r) => (
              <tr key={r.name}>
                <td><b>{r.name}</b></td>
                <td><code>{r.source}</code></td>
                <td>{r.when || 'flagIf op veld'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>

      <footer className="spec-foot">
        <div>Document gegenereerd uit <code>IntakeFormSchema.jsx</code>, single source of truth. Bij wijziging: pas alleen die file aan, dit document én de mobile screens updaten automatisch.</div>
        <div className="versie">v1 · {new Date().toISOString().slice(0, 10)}</div>
      </footer>
    </div>
  );
}

function Legend({ type, desc }) {
  return (
    <div className="legend">
      <span className={`pill-type t-${type}`}>{type}</span>
      <span className="d">{desc}</span>
    </div>
  );
}

function SectionBlock({ sec }) {
  return (
    <section className="spec-section">
      <header className="sec-head">
        <div className="nr">{String(sec.nr).padStart(2, '0')}</div>
        <div>
          <h2>{sec.title}</h2>
          <div className="sub">{sec.intro}</div>
          <div className="meta">~{sec.minutes} min · {sec.fields.filter((f) => f.type !== 'sectionhead').length} velden</div>
        </div>
      </header>

      <div className="fields">
        {sec.fields.map((f, i) => (
          <FieldRow key={f.id} f={f} sec={sec} />
        ))}
      </div>
    </section>
  );
}

function FieldRow({ f, sec }) {
  if (f.type === 'sectionhead') {
    return <div className="field-divider">{f.label}</div>;
  }
  return (
    <div className="field-row" data-type={f.type}>
      <div className="fid">
        <code>{sec.id}.{f.id}</code>
        {f.required && <span className="req">VERPLICHT</span>}
      </div>
      <div className="fbody">
        <div className="fq">{f.label}</div>
        {f.hint && <div className="fhint">{f.hint}</div>}

        <div className="fmeta">
          <span className={`pill-type t-${f.type}`}>{f.type}</span>
          {f.unit && <span className="pill-meta">unit: {f.unit}</span>}
          {f.showIf && <span className="pill-meta show">showIf: {Object.entries(f.showIf).map(([k, v]) => `${k}=${Array.isArray(v) ? v.join('|') : v}`).join(', ')}</span>}
          {f.flagIf && <span className="pill-meta flag">flagIf: {Array.isArray(f.flagIf) ? f.flagIf.join(' · ') : f.flagIf}</span>}
          {f.criticalIf && <span className="pill-meta critical">criticalIf: {Array.isArray(f.criticalIf) ? f.criticalIf.join(' · ') : f.criticalIf}</span>}
        </div>

        {(f.options || f.labels) && (
          <div className="foptions">
            {f.options && f.options.map((o) => (
              <span key={o} className="opt-chip">{o}</span>
            ))}
            {f.labels && (
              <span className="slider-labels">{f.labels[0]} ↔ {f.labels[1]}</span>
            )}
          </div>
        )}

        {f.sub && (
          <div className="repeater-sub">
            <div className="rk">Per item:</div>
            {f.sub.map((s) => (
              <div key={s.id} className="rsub"><code>{s.id}</code><span>{s.label}</span><span className={`pill-type t-${s.type}`}>{s.type}</span></div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

window.IntakeSpecDoc = IntakeSpecDoc;
