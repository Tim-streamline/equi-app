/* global React */

function Footer() {
  return (
    <footer className="site-footer">
      <div className="container">
        <div>
          <div className="brand brand--on-deep" style={{ marginBottom: 16 }}>
            <img src="assets/logo-equinova.svg" alt="Equinova" />
            <div className="brand-name">
              <span className="word">Equinova</span>
              <small className="powered">powered by <em>De Paardentherapeut</em></small>
            </div>
          </div>
          <p style={{ color: 'var(--bg-green-100)', fontSize: 14, lineHeight: 1.6, maxWidth: '32ch' }}>
            Holistische paardengezondheid in jouw broekzak. Begeleid door Shelley — specialist in jeuk, staakgedrag &amp; darmproblemen.
          </p>
        </div>
        <div>
          <h4>De app</h4>
          <ul>
            <li><a>Scanner</a></li>
            <li><a>Bibliotheek</a></li>
            <li><a>Protocol</a></li>
            <li><a>Community</a></li>
          </ul>
        </div>
        <div>
          <h4>Werk met Shelley</h4>
          <ul>
            <li><a>1-op-1 traject</a></li>
            <li><a>Gezond Paard Programma</a></li>
            <li><a>Darmen Cursus</a></li>
            <li><a>Opleiding</a></li>
          </ul>
        </div>
        <div>
          <h4>Contact</h4>
          <ul>
            <li><a>hallo@equinova.nl</a></li>
            <li><a>+31 6 40062617</a></li>
            <li><a>3068 Rotterdam</a></li>
          </ul>
        </div>
        <div className="meta">
          <span>Equinova is een product van De Paardentherapeut · KvK 65758900 · BTW NL002222756B64</span>
          <span>© Equinova</span>
        </div>
      </div>
    </footer>
  );
}

window.Footer = Footer;
