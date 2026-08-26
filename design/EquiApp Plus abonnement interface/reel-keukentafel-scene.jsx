function ReelKeukentafelPiece() {
  const { T, CUES, duration } = useComposition();

  const zoomA = animate({ from: 1, to: 1.08, start: 0, end: CUES.Line })(T);
  const zoomB = animate({ from: 1.02, to: 1.1, start: CUES.Line, end: duration })(T);

  const crossStart = CUES.Line - 0.4;
  const crossEnd = CUES.Line + 0.4;
  const opB = animate({ from: 0, to: 1, start: crossStart, end: crossEnd })(T);
  const opA = 1 - opB;

  const globalFade = Math.min(
    animate({ from: 0, to: 1, start: 0, end: 0.35 })(T),
    animate({ from: 1, to: 0, start: duration - 0.35, end: duration })(T)
  );

  const cta = animate({ from: 0, to: 1, start: CUES.CTA + 0.3, end: CUES.CTA + 0.9 })(T);

  return (
    <div style={{ position: 'absolute', inset: 0, background: '#0d3b34', overflow: 'hidden', fontFamily: "'Source Sans 3', sans-serif" }}>
      <div style={{ position: 'absolute', inset: 0, opacity: globalFade }}>
        <img src="uploads/PXL_20260804_112909281.jpg" style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover', objectPosition: 'center 30%', opacity: opA, transform: `scale(${zoomA})` }} />
        <img src="uploads/PXL_20260801_104706742.MP.jpg" style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover', objectPosition: 'center 25%', opacity: opB, transform: `scale(${zoomB})` }} />
        <div style={{ position: 'absolute', inset: 0, background: 'linear-gradient(180deg, rgba(13,59,52,0.12) 0%, rgba(13,59,52,0.2) 45%, rgba(13,59,52,0.5) 100%)' }} />

        <Captions items={[
          { at: 0.6, until: CUES.Line - 0.5, text: 'Weer een avond researchen naar wat er mis is.', style: { fontFamily: "'Source Sans 3', sans-serif", fontWeight: 600, fontSize: 32 } },
          { at: CUES.Line + 0.5, until: CUES.CTA - 0.3, text: 'De nerdversie van intuïtie.', style: { fontFamily: "'Source Sans 3', sans-serif", fontStyle: 'italic', fontWeight: 600, fontSize: 40 } },
        ]} />

        <div style={{ position: 'absolute', inset: 0, opacity: cta, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'flex-end', paddingBottom: 160 }}>
          <img src="assets/logo-horse-white.png" style={{ width: 84, marginBottom: 28, opacity: 0.95 }} />
          <div style={{ fontSize: 26, fontWeight: 700, letterSpacing: '0.12em', textTransform: 'uppercase', color: '#f6f4ef' }}>De Paardentherapeut</div>
          <div style={{ marginTop: 14, fontSize: 26, color: '#c9ece8' }}>Paardengezondheid van de toekomst</div>
        </div>
      </div>
    </div>
  );
}

window.ReelKeukentafel = function ReelKeukentafel() {
  return (
    <CompositionStage width={1080} height={1920} scenes={window.OM_SCENES} playback={window.OM_PLAYBACK} bg="#0d3b34">
      <ReelKeukentafelPiece />
    </CompositionStage>
  );
};
