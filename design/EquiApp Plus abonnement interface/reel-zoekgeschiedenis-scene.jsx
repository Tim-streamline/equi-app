function ReelZoekgeschiedenisPiece() {
  const { T, CUES, duration } = useComposition();

  const QUERIES = [
    { text: "waarom jeukt mijn paard vooral 's nachts", start: CUES.Search1, end: CUES.Search2 },
    { text: "eczeem paard natuurlijke behandeling", start: CUES.Search2, end: CUES.Search3 },
    { text: "kan stress jeuk bij paarden verergeren", start: CUES.Search3, end: CUES.Reveal },
  ];

  function typedText(q) {
    const dur = q.end - q.start;
    const typeDur = Math.min(1.7, dur * 0.55);
    const clearStart = q.end - 0.3;
    if (T <= q.start) return '';
    if (T < q.start + typeDur) {
      const p = clamp((T - q.start) / typeDur, 0, 1);
      return q.text.slice(0, Math.round(q.text.length * p));
    }
    if (T < clearStart) return q.text;
    const p = clamp((T - clearStart) / (q.end - clearStart), 0, 1);
    return q.text.slice(0, Math.round(q.text.length * (1 - p)));
  }

  let activeQuery = '';
  for (const q of QUERIES) {
    if (T >= q.start && T < q.end) { activeQuery = typedText(q); break; }
  }

  const fadeIn = animate({ from: 0, to: 1, start: 0, end: 0.5 })(T);
  const fadeOut = animate({ from: 1, to: 0, start: CUES.Reveal - 0.1, end: CUES.Reveal + 0.5 })(T);
  const uiOpacity = Math.min(fadeIn, fadeOut);

  const cardIn = animate({ from: 0, to: 1, start: CUES.Reveal + 0.5, end: CUES.Reveal + 1.1 })(T);
  const cardOut = animate({ from: 1, to: 0, start: duration - 0.5, end: duration })(T);
  const cardOpacity = Math.min(cardIn, cardOut);

  const zoom = 1 + 0.03 * clamp(T / duration, 0, 1);

  return (
    <div style={{ position: 'absolute', inset: 0, background: '#0d3b34', fontFamily: "'Source Sans 3', sans-serif", overflow: 'hidden' }}>
      <div style={{ position: 'absolute', inset: '-4%', transform: `scale(${zoom})`, background: 'radial-gradient(circle at 50% 30%, rgba(24,186,176,0.16) 0%, rgba(13,59,52,0) 60%)' }} />

      <div style={{ position: 'absolute', inset: 0, opacity: uiOpacity }}>
        <div style={{ position: 'absolute', top: 64, left: 64, right: 64, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <div style={{ fontSize: 34, fontWeight: 700, color: '#f6f4ef', letterSpacing: '0.02em' }}>03:12</div>
          <div style={{ display: 'flex', gap: 6, alignItems: 'flex-end' }}>
            <div style={{ width: 8, height: 14, background: '#f6f4ef', opacity: 0.6, borderRadius: 2 }} />
            <div style={{ width: 8, height: 20, background: '#f6f4ef', opacity: 0.75, borderRadius: 2 }} />
            <div style={{ width: 8, height: 26, background: '#f6f4ef', borderRadius: 2 }} />
          </div>
        </div>

        <div style={{ position: 'absolute', left: 64, right: 64, top: 240, display: 'flex', alignItems: 'center', gap: 20, background: 'rgba(255,255,255,0.07)', border: '1.5px solid rgba(24,186,176,0.55)', borderRadius: 9999, padding: '26px 32px' }}>
          <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#8fe3da" strokeWidth="2">
            <circle cx="11" cy="11" r="7" />
            <line x1="21" y1="21" x2="16.5" y2="16.5" />
          </svg>
          <div style={{ fontSize: 30, color: '#f6f4ef', fontWeight: 400, whiteSpace: 'nowrap', overflow: 'hidden' }}>
            {activeQuery}
            <span style={{ opacity: Math.sin(T * 6) > 0 ? 1 : 0, color: '#18BAB0' }}>|</span>
          </div>
        </div>
      </div>

      <div style={{ position: 'absolute', inset: 0, opacity: cardOpacity, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', padding: '0 90px', textAlign: 'center' }}>
        <div style={{ fontSize: 44, fontWeight: 600, fontStyle: 'italic', color: '#f6f4ef', lineHeight: 1.35 }}>Je zoekgeschiedenis is de eerlijkste versie van jou.</div>
        <div style={{ marginTop: 72, fontSize: 24, fontWeight: 700, letterSpacing: '0.12em', textTransform: 'uppercase', color: '#8fe3da' }}>De Paardentherapeut</div>
        <div style={{ marginTop: 16, fontSize: 26, color: '#c9ece8' }}>Gratis intake — link in bio</div>
      </div>
    </div>
  );
}

window.ReelZoekgeschiedenis = function ReelZoekgeschiedenis() {
  return (
    <CompositionStage width={1080} height={1920} scenes={window.OM_SCENES} playback={window.OM_PLAYBACK} bg="#0d3b34">
      <ReelZoekgeschiedenisPiece />
    </CompositionStage>
  );
};
