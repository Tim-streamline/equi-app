/* global React, ReactDOM, DesignCanvas, DCSection, DCArtboard,
   MobileWelcome, MobileSectionList, MobileSectionVoer, MobileSectionKlacht,
   MobileSectionFysiek, MobileSubmit, MobileWaiting,
   DashInbox, DashIntakeDetail, DashConceptProtocol, DashMonitoring, DashWeek6Eval,
   IntakeSpecDoc, StandardProtocolDoc */

function App() {
  return (
    <div className="equinova">
      <DesignCanvas>

        {/* ============================================================
            BRIEF
           ============================================================ */}
        <DCSection
          id="brief"
          title="Het Holistisch Herstelplan — intake & protocol"
          subtitle="Volledige spec voor developer · klant vult intake zelf in, Shelley reviewt en publiceert">
          <DCArtboard id="brief-card" label="Lees mij eerst" width={680} height={700}>
            <div className="brief-card">
              <div className="brief-tag">Voor de developer</div>
              <h1>Het Holistisch Herstelplan</h1>
              <p className="lede">
                Eenmalig <em>€ 97</em> bovenop het Equinova-basisabonnement van <em>€ 19/m</em>. Een <em>3-maanden traject</em> waarin Shelley
                via de app op basis van de intake een op maat protocol bouwt en wekelijks bijstuurt. <em>Geen gesprek of videocall</em> — alle informatie waarmee Shelley werkt komt uit de in-app vragen + foto's.
              </p>

              <div className="brief-grid">
                <div>
                  <h3>① Onboarding</h3>
                  <ol>
                    <li>Welkom → paard toevoegen → focus kiezen</li>
                    <li>Pakketkeuze: Basis (€19/m) vs. Het Herstelplan (€97)</li>
                    <li>Bij Herstelplan: betaal → intake opent direct</li>
                    <li>Bij Basis: home — kan later upgraden</li>
                  </ol>
                </div>
                <div>
                  <h3>② Intake (10 secties)</h3>
                  <ol>
                    <li>Paard · Klacht · Geschiedenis · Medisch</li>
                    <li>Voer · Water · Huisvesting · Gedrag · Fysiek</li>
                    <li>Auto-save, mag in meerdere sessies</li>
                    <li>Veiligheidschecks: drachtig, koorts, IR, KPU…</li>
                    <li>Verzenden → bij Shelley in review-inbox</li>
                  </ol>
                </div>
                <div>
                  <h3>③ Shelley reviewt</h3>
                  <ol>
                    <li>Inbox toont nieuwe intakes + aandachtspunten</li>
                    <li>Detail-view alle antwoorden + foto's + auto-flags</li>
                    <li>Concept-protocol klaargezet (standaard Darm-template)</li>
                    <li>Shelley past aan op individuele paard</li>
                    <li>Klik → publiceer naar klant-app</li>
                  </ol>
                </div>
                <div>
                  <h3>④ Protocol loopt (12 wk)</h3>
                  <ol>
                    <li>Fase 0 (optioneel · maag) · Fase 1 (darm 6 wk) · Eval · Fase 1b · Fase 2 (lever/nier 12 wk)</li>
                    <li>Dagplan, wekelijkse check-ins door klant</li>
                    <li>Signaalmomenten triggeren melding aan Shelley</li>
                    <li>Evaluatie wk 6 en wk 12 — Shelley beslist vervolg</li>
                  </ol>
                </div>
              </div>

              <div className="brief-foot">
                <b>Open vragen:</b> Foto-upload via app camera of bestand? · AI-samenvatting via Claude/GPT op verzenden of zelf-gebouwd template-match? · Hoe encoderen we voor 600 kg basis en herberekenen op intake-gewicht?
              </div>
            </div>
          </DCArtboard>
        </DCSection>

        {/* ============================================================
            KLANT-ZIJDE — MOBIEL (in Equinova-app)
           ============================================================ */}
        <DCSection
          id="customer"
          title="Klant-zijde · Equinova mobiel"
          subtitle="7 schermen · gebeurt na betaling van Het Holistisch Herstelplan">
          <DCArtboard id="c1-welcome"   label="01 · Welkom" width={390} height={844}>
            <MobileWelcome />
          </DCArtboard>
          <DCArtboard id="c2-sections"  label="02 · Sectie-overzicht" width={390} height={844}>
            <MobileSectionList />
          </DCArtboard>
          <DCArtboard id="c3-klacht"    label="03 · Open vragen (klacht)" width={390} height={844}>
            <MobileSectionKlacht />
          </DCArtboard>
          <DCArtboard id="c4-voer"      label="04 · Gemengde inputs (voer)" width={390} height={844}>
            <MobileSectionVoer />
          </DCArtboard>
          <DCArtboard id="c5-fysiek"    label="05 · Foto's & fysiek" width={390} height={844}>
            <MobileSectionFysiek />
          </DCArtboard>
          <DCArtboard id="c6-submit"    label="06 · Controleren & verzenden" width={390} height={844}>
            <MobileSubmit />
          </DCArtboard>
          <DCArtboard id="c7-waiting"   label="07 · Verzonden" width={390} height={844}>
            <MobileWaiting />
          </DCArtboard>
        </DCSection>

        {/* ============================================================
            FULL FORM SPEC
           ============================================================ */}
        <DCSection
          id="spec"
          title="Volledige intake-spec voor developer"
          subtitle="Elke vraag, elk veldtype, elke validatie + auto-protocol beslisboom · single source of truth">
          <DCArtboard id="spec-full" label="Volledig intake-formulier + decision tree" width={920} height={22500}>
            <IntakeSpecDoc />
          </DCArtboard>
        </DCSection>

        {/* ============================================================
            THERAPEUT-ZIJDE — WEB DASHBOARD
           ============================================================ */}
        <DCSection
          id="therapist"
          title="Therapeut-zijde · Shelley's portaal"
          subtitle="5 schermen · inbox → intake-review → concept-protocol → monitoring → evaluatie">
          <DCArtboard id="t1-inbox"   label="01 · Inbox · binnengekomen intakes" width={1280} height={820}>
            <DashInbox />
          </DCArtboard>
          <DCArtboard id="t2-detail"  label="02 · Intake-review · Nova" width={1280} height={820}>
            <DashIntakeDetail />
          </DCArtboard>
          <DCArtboard id="t3-concept" label="03 · Auto-gegenereerd concept-protocol" width={1280} height={2400}>
            <DashConceptProtocol />
          </DCArtboard>
          <DCArtboard id="t4-monitor" label="04 · Live monitoring · alle lopende paarden" width={1280} height={1040}>
            <DashMonitoring />
          </DCArtboard>
          <DCArtboard id="t5-eval"    label="05 · Evaluatie week 6 · Storm" width={1280} height={1100}>
            <DashWeek6Eval />
          </DCArtboard>
        </DCSection>

        {/* ============================================================
            STANDARD PROTOCOL DOC
           ============================================================ */}
        <DCSection
          id="protocol"
          title="Standaard-protocol Darmrevalidatie"
          subtitle="Wat het systeem als concept klaarzet · Shelley past aan per paard · dit is de basis-template">
          <DCArtboard id="proto-full" label="Volledig protocol-document" width={920} height={7800}>
            <StandardProtocolDoc />
          </DCArtboard>
        </DCSection>

      </DesignCanvas>
    </div>
  );
}

const root = ReactDOM.createRoot(document.getElementById('app'));
root.render(<App />);
