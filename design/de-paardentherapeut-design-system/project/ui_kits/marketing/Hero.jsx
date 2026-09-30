/* global React */

function Hero({ setRoute }) {
  return (
    <section className="hero">
      <div className="container">
        <div>
          <span className="eyebrow hero-eyebrow">Nu in beta · Android &amp; iOS</span>
          <h1>Paardengezondheid<br />van de toekomst.</h1>
          <p className="lead">
            Equi App is de holistische app voor paardeneigenaren — scan voer, volg een
            persoonlijk protocol, en stel je vragen direct aan Shelley. Powered by
            <em> De Paardentherapeut</em>.
          </p>
          <div className="hero-cta">
            <button className="btn btn--primary" onClick={() => setRoute('contact')}>Download Equi App →</button>
            <button className="btn btn--ghost" onClick={() => setRoute('cursussen')}>Werk met Shelley »</button>
          </div>
        </div>
        <div className="hero-art">
          <img className="equi-app-mark" src="assets/logo-equi-app.svg" alt="Equi App" />
          <span className="badge"><em>écht</em>&nbsp;holistisch</span>
        </div>
      </div>
    </section>
  );
}

window.Hero = Hero;
