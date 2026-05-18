/* global React */

function Testimonial({ quote, attribution }) {
  return (
    <div className="tm">
      <p className="tm-quote">"{quote}"</p>
      <div className="tm-attr">— {attribution}</div>
    </div>
  );
}

function BenefitsList({ items }) {
  return (
    <ul className="benefits">
      {items.map((it, i) => (
        <li key={i}>
          <span className={`glyph ${it.kind === 'arrow' ? 'arrow' : ''}`}>{it.kind === 'arrow' ? '▶' : '✔'}</span>
          <p dangerouslySetInnerHTML={{ __html: it.text }} />
        </li>
      ))}
    </ul>
  );
}

window.Testimonial = Testimonial;
window.BenefitsList = BenefitsList;
