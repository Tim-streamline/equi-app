/* global React, I */
// Shell.jsx — phone frame, status bar, tab bar, app header, primitives.

const { useState, useEffect } = React;

function StatusBar({ time = '9:41', tone = 'light' }) {
  return (
    <>
      <div className="notch"></div>
      <div className={tone === 'deep' ? 'statusbar on-deep' : 'statusbar'}>
        <span>{time}</span>
        <span className="statusbar-glyphs">
          {I.signal}{I.wifi}{I.battery}
        </span>
      </div>
    </>
  );
}

function HomeIndicator({ tone = 'light' }) {
  return <div className={tone === 'deep' ? 'home-indicator on-deep' : 'home-indicator'} />;
}

function TabBar({ active, onChange, style = 'labels' }) {
  const tabs = [
    { id: 'home',     icon: I.home,      label: 'Home' },
    { id: 'protocol', icon: I.clipboard, label: 'Protocol' },
    { id: 'scanner',  icon: I.scan,      label: 'Scan' },
    { id: 'library',  icon: I.book,      label: 'Bibliotheek' },
    { id: 'account',  icon: I.user,      label: 'Account' },
  ];
  const cls = ['tabbar'];
  if (style === 'floating')  cls.push('style-floating');
  if (style === 'icons') cls.push('style-icons-only');
  return (
    <div className={cls.join(' ')}>
      {tabs.map((t) => (
        <button key={t.id} className={active === t.id ? 'active' : ''} onClick={() => onChange(t.id)}>
          {t.icon}
          <span className="lbl">{t.label}</span>
        </button>
      ))}
    </div>
  );
}

function AppHeader({ greet, title, right, avatar = 'S' }) {
  return (
    <div className="app-header">
      <div>
        {greet && <div className="greet">{greet}</div>}
        <h1>{title}</h1>
      </div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        {right}
        <div className="avatar">{avatar}</div>
      </div>
    </div>
  );
}

function SubHeader({ title, onBack, right, tone = 'light' }) {
  return (
    <div className="subhdr">
      <button className={tone === 'deep' ? 'iconbtn on-deep' : 'iconbtn'} onClick={onBack}>{I.back}</button>
      <div className="title">{title}</div>
      <div style={{ width: 36 }}>{right}</div>
    </div>
  );
}

function Coach({ tag, name = 'Shelley', children }) {
  return (
    <div className="coach">
      <div className="av">S</div>
      <div className="by">{name} {tag && <small>· {tag}</small>}</div>
      <div className="msg">{children}</div>
    </div>
  );
}

function SectionTitle({ children, action, onAction }) {
  return (
    <div className="section-title">
      <h2>{children}</h2>
      {action && <a onClick={onAction}>{action}</a>}
    </div>
  );
}

function ProgressRing({ value, size = 56, stroke = 5 }) {
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  return (
    <svg className="progress-ring" width={size} height={size}>
      <circle className="track" cx={size/2} cy={size/2} r={r} strokeWidth={stroke} />
      <circle className="fill"  cx={size/2} cy={size/2} r={r} strokeWidth={stroke}
              strokeDasharray={c} strokeDashoffset={c - (c * value) / 100} />
    </svg>
  );
}

function Sheet({ open, onClose, children }) {
  if (!open) return null;
  return (
    <div className="sheet-overlay" onClick={onClose}>
      <div className="sheet" onClick={(e) => e.stopPropagation()}>
        <div className="sheet-grip" />
        {children}
      </div>
    </div>
  );
}

Object.assign(window, { StatusBar, HomeIndicator, TabBar, AppHeader, SubHeader, Coach, SectionTitle, ProgressRing, Sheet });
