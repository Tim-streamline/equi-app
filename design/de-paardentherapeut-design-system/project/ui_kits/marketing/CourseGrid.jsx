/* global React */

function CourseCard({ course, onOpen, variant = 'default' }) {
  return (
    <div className={`course-card ${variant}`} onClick={() => onOpen?.(course)}>
      <div className="cover">
        <img src="assets/logo-horse-white.png" alt="" />
      </div>
      <span className="eyebrow">{course.kind}</span>
      <h3>{course.title}</h3>
      <p className="desc">{course.desc}</p>
      <div className="meta">
        <span>{course.duration}</span><span>·</span><span>{course.format}</span>
      </div>
    </div>
  );
}

function CourseGrid({ courses, onOpen }) {
  const variants = ['default', 'deep', 'canvas'];
  return (
    <div className="course-grid">
      {courses.map((c, i) => (
        <CourseCard key={c.id} course={c} onOpen={onOpen} variant={variants[i % variants.length]} />
      ))}
    </div>
  );
}

window.CourseCard = CourseCard;
window.CourseGrid = CourseGrid;
