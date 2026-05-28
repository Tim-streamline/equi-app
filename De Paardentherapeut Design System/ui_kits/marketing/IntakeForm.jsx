/* global React */
const { useState: useStateForm } = React;

function IntakeForm() {
  const [sent, setSent] = useStateForm(false);
  const [name, setName] = useStateForm('');
  const [horse, setHorse] = useStateForm('');
  const [email, setEmail] = useStateForm('');
  const [topic, setTopic] = useStateForm('jeuk');
  const [msg, setMsg] = useStateForm('');

  if (sent) {
    return (
      <div className="intake" style={{ gridTemplateColumns: '1fr', textAlign: 'center' }}>
        <div>
          <span className="eyebrow">Bedankt</span>
          <h2 style={{ marginTop: 12 }}>IK NEEM ZO SNEL MOGELIJK<br/>CONTACT MET JE OP.</h2>
          <p className="lead" style={{ marginTop: 16 }}>
            Tijdens het gratis intakegesprek kijken we of mijn behandelmethode geschikt is voor jou en je paard.
            Hier zitten geen kosten aan verbonden.
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="intake">
      <div className="label-side">
        <span className="eyebrow">Gratis intake</span>
        <h2>BEN JIJ KLAAR<br/>VOOR EEN HOLISTISCH<br/>PLAN VOOR JE PAARD?</h2>
        <p className="lead" style={{ marginTop: 16 }}>
          Vertel kort wat er speelt. Ik neem zo snel mogelijk contact op om te kijken of we een match zijn —
          <em>écht</em>, zonder verplichting.
        </p>
      </div>
      <form onSubmit={(e) => { e.preventDefault(); setSent(true); }}>
        <div className="row">
          <div><label>Jouw naam</label><input value={name} onChange={e => setName(e.target.value)} required /></div>
          <div><label>Naam van je paard</label><input value={horse} onChange={e => setHorse(e.target.value)} placeholder="Pink Lady" /></div>
        </div>
        <div><label>E-mailadres</label><input type="email" value={email} onChange={e => setEmail(e.target.value)} required /></div>
        <div>
          <label>Wat speelt er?</label>
          <select value={topic} onChange={e => setTopic(e.target.value)}>
            <option value="jeuk">Jeukklachten</option>
            <option value="staak">Staakgedrag</option>
            <option value="darm">Darmproblemen</option>
            <option value="anders">Iets anders</option>
          </select>
        </div>
        <div>
          <label>Vertel kort wat je merkt aan je paard</label>
          <textarea value={msg} onChange={e => setMsg(e.target.value)} placeholder="Sinds een paar weken zie ik dat…" />
        </div>
        <div className="submit-row">
          <button type="submit" className="btn btn--primary">Verstuur aanvraag →</button>
          <span className="small-note">Reactie binnen 1–2 werkdagen.</span>
        </div>
      </form>
    </div>
  );
}

window.IntakeForm = IntakeForm;
