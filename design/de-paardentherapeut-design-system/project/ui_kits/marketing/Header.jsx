/* global React */
const { useState } = React;

function Logo({ variant = 'dark' }) {
  const src = variant === 'light' ? 'assets/logo-equinova.svg' : 'assets/logo-equinova-light.svg';
  // Header sits on canvas → use the white-tile logo so the mint mark holds its own.
  const headerSrc = 'assets/logo-equinova.svg';
  return (
    <div className="brand">
      <img src={variant === 'header' ? headerSrc : src} alt="Equinova" />
      <div className="brand-name">
        <span className="word">Equinova</span>
        <small className="powered">powered by <em>De Paardentherapeut</em></small>
      </div>
    </div>
  );
}

function Header({ route, setRoute }) {
  const items = [
    ['home', 'Home'],
    ['cursussen', 'Cursussen'],
    ['course', '1-op-1'],
    ['contact', 'Contact'],
  ];
  return (
    <header className="site-header">
      <div className="container">
        <a onClick={() => setRoute('home')}><Logo variant="header" /></a>
        <nav className="nav">
          {items.map(([key, label]) => (
            <a key={key}
               className={route === key ? 'active' : ''}
               onClick={() => setRoute(key)}>{label}</a>
          ))}
        </nav>
      </div>
    </header>
  );
}

window.Header = Header;
window.Logo = Logo;
