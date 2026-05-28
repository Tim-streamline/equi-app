/* global React, ReactDOM, useTweaks, TweaksPanel, TweakSection, TweakRadio, TweakToggle, TweakColor, TweakSelect,
   StatusBar, HomeIndicator, TabBar,
   ScreenWelcome, ScreenAddHorse, ScreenFocusPick, ScreenConnect,
   ScreenHome, ScreenHorseProfile,
   ScreenProtocolList, ScreenProtocolDetail, ScreenLogEntry,
   ScreenScannerCamera, ScreenScannerResult, ScreenScannerHistory,
   ScreenLibrary, ScreenLibraryArticle, ScreenLibraryVideo,
   ScreenCommunity, ScreenCommunityThread,
   ScreenAccount, ScreenMyHorses, ScreenSubscription,
   NovaChat */

const { useState: useAS, useEffect: useAE } = React;

/* ---------- screen registry (used by the jumper + router) ---------- */

const SCREENS = [
  { id: 'onb-welcome',     label: '01 · Welcome',           group: 'Onboarding' },
  { id: 'onb-add-horse',   label: '02 · Voeg paard toe',    group: 'Onboarding' },
  { id: 'onb-focus',       label: '03 · Focus kiezen',      group: 'Onboarding' },
  { id: 'onb-connect',     label: '04 · Pakket kiezen',     group: 'Onboarding' },
  { id: 'home',            label: '05 · Home',              group: 'Home',     tab: 'home' },
  { id: 'horse-profile',   label: '06 · Paardprofiel',      group: 'Home',     tab: 'home' },
  { id: 'protocol',        label: '07 · Protocol overzicht', group: 'Protocol', tab: 'protocol' },
  { id: 'protocol-detail', label: '08 · Protocol detail',   group: 'Protocol', tab: 'protocol' },
  { id: 'log-entry',       label: '09 · Log observatie',    group: 'Protocol', tab: 'protocol' },
  { id: 'scan-camera',     label: '10 · Scanner camera',    group: 'Scanner',  tab: 'scanner' },
  { id: 'scan-result',     label: '11 · Scan resultaat',    group: 'Scanner',  tab: 'scanner' },
  { id: 'scan-history',    label: '12 · Scan geschiedenis', group: 'Scanner',  tab: 'scanner' },
  { id: 'library',         label: '13 · Bibliotheek',       group: 'Library',  tab: 'library' },
  { id: 'library-article', label: '14 · Artikel',           group: 'Library',  tab: 'library' },
  { id: 'library-video',   label: '15 · Video',             group: 'Library',  tab: 'library' },
  { id: 'community',       label: '16 · Community feed',    group: 'Community', tab: 'account' },
  { id: 'community-thread',label: '17 · Thread',            group: 'Community', tab: 'account' },
  { id: 'account',         label: '18 · Account',           group: 'Account',  tab: 'account' },
  { id: 'my-horses',       label: '19 · Mijn paarden',      group: 'Account',  tab: 'account' },
  { id: 'subscription',    label: '20 · Abonnement',        group: 'Account',  tab: 'account' },
];

/* Tab → default screen and which screen belongs to which tab.
   Community lives behind Account for the tab-bar mapping (account opens to a hub
   that includes the community entry); but you can also jump to it from home. */
const TAB_DEFAULT = {
  home: 'home', protocol: 'protocol', scanner: 'scan-camera', library: 'library', account: 'account',
};
const TAB_HOLDS = {
  home:     ['home', 'horse-profile'],
  protocol: ['protocol', 'protocol-detail', 'log-entry'],
  scanner:  ['scan-camera', 'scan-result', 'scan-history'],
  library:  ['library', 'library-article', 'library-video'],
  account:  ['account', 'my-horses', 'subscription', 'community', 'community-thread'],
};
const tabOf = (screen) => Object.keys(TAB_HOLDS).find((t) => TAB_HOLDS[t].includes(screen)) || 'home';
const isOnboarding = (s) => s.startsWith('onb-');
const isFullscreen = (s) => s === 'scan-camera' || s === 'onb-welcome';

/* ---------- TWEAKS ---------- */

const PALETTES = [
  ['#18BAB0', '#127A79', '#FBF8F3'],   // Mint forward (default)
  ['#127A79', '#18BAB0', '#FBF8F3'],   // Deep teal forward
  ['#0D3B34', '#18BAB0', '#FBF8F3'],   // Logo green forward
  ['#1B2A2A', '#18BAB0', '#FFFFFF'],   // Mono w/ mint accent
];

const TWEAK_DEFAULTS = /*EDITMODE-BEGIN*/{
  "palette": ["#18BAB0", "#127A79", "#FBF8F3"],
  "tabStyle": "labels",
  "cardStyle": "soft",
  "density": "regular",
  "headlines": "sentence",
  "fontFamily": "Source Sans 3"
}/*EDITMODE-END*/;

function applyTweaks(t) {
  const root = document.documentElement;
  const [accent, deep, bg] = t.palette;
  root.style.setProperty('--app-accent', accent);
  root.style.setProperty('--app-accent-strong', deep);
  root.style.setProperty('--app-deep', deep);
  // soft tint of accent for backgrounds
  root.style.setProperty('--app-accent-soft', accent + '22');
  root.style.setProperty('--app-bg', bg);
  // Font family
  const ff = t.fontFamily === 'System' ? `system-ui, -apple-system, sans-serif`
           : t.fontFamily === 'Serif'  ? `"Lora", "Source Serif 4", Georgia, serif`
           : `"Source Sans 3", system-ui, sans-serif`;
  root.style.setProperty('--font-sans', ff);
  root.style.setProperty('--font-display', ff);
}

/* ---------- ROOT APP ---------- */

function App() {
  const [t, setTweak] = useTweaks(TWEAK_DEFAULTS);
  const [screen, setScreen] = useAS('onb-welcome');
  const [novaOpen, setNova] = useAS(false);

  useAE(() => { applyTweaks(t); }, [t]);

  const go = (id) => setScreen(id);
  const back = () => {
    // Simple back-stack: pop to the tab's default
    const tab = tabOf(screen);
    if (isOnboarding(screen)) {
      const idx = SCREENS.findIndex((s) => s.id === screen);
      setScreen(idx > 0 ? SCREENS[idx - 1].id : 'onb-welcome');
    } else {
      setScreen(TAB_DEFAULT[tab]);
    }
  };
  const onTab = (tab) => setScreen(TAB_DEFAULT[tab]);

  const showTabs = !isOnboarding(screen) && !isFullscreen(screen);
  const activeTab = tabOf(screen);

  let body;
  switch (screen) {
    case 'onb-welcome':     body = <ScreenWelcome go={go} />; break;
    case 'onb-add-horse':   body = <ScreenAddHorse go={go} back={back} />; break;
    case 'onb-focus':       body = <ScreenFocusPick go={go} back={back} />; break;
    case 'onb-connect':     body = <ScreenConnect go={go} back={back} />; break;
    case 'home':            body = <ScreenHome go={go} openNova={() => setNova(true)} />; break;
    case 'horse-profile':   body = <ScreenHorseProfile back={back} go={go} />; break;
    case 'protocol':        body = <ScreenProtocolList go={go} openNova={() => setNova(true)} />; break;
    case 'protocol-detail': body = <ScreenProtocolDetail back={back} go={go} />; break;
    case 'log-entry':       body = <ScreenLogEntry back={back} />; break;
    case 'scan-camera':     body = <ScreenScannerCamera go={go} back={() => setScreen('home')} />; break;
    case 'scan-result':     body = <ScreenScannerResult back={() => setScreen('scan-camera')} go={go} />; break;
    case 'scan-history':    body = <ScreenScannerHistory back={() => setScreen('scan-camera')} go={go} />; break;
    case 'library':         body = <ScreenLibrary go={go} />; break;
    case 'library-article': body = <ScreenLibraryArticle back={() => setScreen('library')} />; break;
    case 'library-video':   body = <ScreenLibraryVideo back={() => setScreen('library')} go={go} />; break;
    case 'community':       body = <ScreenCommunity go={go} />; break;
    case 'community-thread':body = <ScreenCommunityThread back={() => setScreen('community')} />; break;
    case 'account':         body = <ScreenAccount go={go} />; break;
    case 'my-horses':       body = <ScreenMyHorses back={() => setScreen('account')} go={go} />; break;
    case 'subscription':    body = <ScreenSubscription back={() => setScreen('account')} />; break;
    default:                body = <ScreenHome go={go} openNova={() => setNova(true)} />;
  }

  /* the dark scanner screen wants a deep-tone status bar; onb-welcome too */
  const sbTone = screen === 'scan-camera' || screen === 'onb-welcome' ? 'deep' : 'light';

  const ROOT = (
    <div className="equinova"
         data-density={t.density}
         data-cards={t.cardStyle}
         data-headlines={t.headlines}>
      <div className="stage">
        <div className="stage-meta">
          <img src="assets/logo-equinova.svg" alt="" style={{ width: 36, height: 36, borderRadius: 10 }} />
          <div className="name">Equinova <small>powered by De Paardentherapeut</small></div>
        </div>

        <div className="jumper">
          <button onClick={() => setScreen('onb-welcome')}>↺ Reset</button>
          <select value={screen} onChange={(e) => setScreen(e.target.value)}>
            {SCREENS.map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}
          </select>
          <span className="count">{SCREENS.findIndex((s) => s.id === screen) + 1} / {SCREENS.length}</span>
        </div>

        <div className="phone">
          <StatusBar tone={sbTone} />
          {body}
          {showTabs && <TabBar active={activeTab} onChange={onTab} style={t.tabStyle} />}
          <HomeIndicator tone={sbTone} />
          <NovaChat open={novaOpen} onClose={() => setNova(false)} />
        </div>
      </div>

      <TweaksPanel title="Tweaks · Equinova">
        <TweakSection label="Look" />
        <TweakColor label="Palet" value={t.palette} options={PALETTES} onChange={(v) => setTweak('palette', v)} />
        <TweakSelect label="Font" value={t.fontFamily}
                     options={['Source Sans 3', 'System', 'Serif']}
                     onChange={(v) => setTweak('fontFamily', v)} />
        <TweakRadio  label="Koppen" value={t.headlines}
                     options={[{ value: 'sentence', label: 'Sentence' }, { value: 'upper', label: 'UPPER' }]}
                     onChange={(v) => setTweak('headlines', v)} />

        <TweakSection label="Componenten" />
        <TweakRadio label="Cards" value={t.cardStyle}
                    options={[{ value: 'soft', label: 'Soft' }, { value: 'sharp', label: 'Sharp' }, { value: 'outline', label: 'Outline' }]}
                    onChange={(v) => setTweak('cardStyle', v)} />
        <TweakRadio label="Density" value={t.density}
                    options={[{ value: 'compact', label: 'Compact' }, { value: 'regular', label: 'Regular' }, { value: 'comfortable', label: 'Comfort' }]}
                    onChange={(v) => setTweak('density', v)} />
        <TweakRadio label="Tab bar" value={t.tabStyle}
                    options={[{ value: 'labels', label: 'Labels' }, { value: 'icons', label: 'Icons' }, { value: 'floating', label: 'Float' }]}
                    onChange={(v) => setTweak('tabStyle', v)} />
      </TweaksPanel>
    </div>
  );

  return ROOT;
}

const root = ReactDOM.createRoot(document.getElementById('app'));
root.render(<App />);
