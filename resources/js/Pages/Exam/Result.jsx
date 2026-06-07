import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

// Render an answer (option id, array of ids, number, or text) readably, mapping
// choice ids to "A. their text" when options are available.
function fmtAnswer(value, options) {
    if (value == null || value === '') return '—';
    const opts = Array.isArray(options) ? options : [];
    const map = Object.fromEntries(opts.map((o) => [String(o.id).toUpperCase(), o.text]));
    const one = (x) => { const k = String(x).toUpperCase(); return map[k] ? `${k}. ${map[k]}` : String(x); };
    return Array.isArray(value) ? value.map(one).join(', ') : one(value);
}

export default function Result({ submission, review }) {
    const s = submission;
    const result = s.pendingEssayCount > 0 ? t('Pending grading') : s.passed ? t('Passed') : t('Not passed');
    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div>
                    <h1>{s.examName} — {t('Result')}</h1>
                    <p>{t('Submitted')} {s.submittedAt ? new Date(s.submittedAt).toLocaleString() : ''}</p>
                </div>
            </header>

            <div className="admin-metrics admin-metrics--3col">
                <div className="admin-panel metric-card">
                    <span>{t('Score')}</span>
                    <strong>{s.percentScore}%</strong>
                    <small className="metric-card-sub">{s.finalScore} / {s.possibleScore} pts</small>
                </div>
                <div className="admin-panel metric-card">
                    <span>{t('Result')}</span>
                    <strong>{result}</strong>
                    <small className="metric-card-sub">{t('pass mark')} {s.passingGrade}%</small>
                </div>
                <div className="admin-panel metric-card">
                    <span>{t('Essays to grade')}</span>
                    <strong>{s.pendingEssayCount}</strong>
                    <small className="metric-card-sub">{t('marked by your teacher')}</small>
                </div>
            </div>

            {s.topicBreakdown && s.topicBreakdown.length > 0 ? (
                <section className="admin-panel" style={{ marginTop: 16 }}>
                    <div className="section-title-row"><div><h2>{t('By topic')}</h2></div></div>
                    <table className="dashboard-table">
                        <thead><tr><th>{t('Topic')}</th><th>{t('Score')}</th><th>{t('Correct')}</th></tr></thead>
                        <tbody>
                            {s.topicBreakdown.map((t, i) => (
                                <tr key={i}>
                                    <td>{t.topic}</td>
                                    <td>{t.percent}% <span style={{ color: 'var(--muted)' }}>({t.earned}/{t.possible})</span></td>
                                    <td>{t.correct}/{t.total}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>
            ) : null}

            {review && review.length > 0 ? (
                <section className="admin-panel" style={{ marginTop: 16 }}>
                    <div className="section-title-row"><div><h2>{t('Review answers')}</h2></div></div>
                    <ol style={{ paddingLeft: 18, display: 'grid', gap: 16, margin: 0 }}>
                        {review.map((q) => (
                            <li key={q.position}>
                                <div style={{ whiteSpace: 'pre-wrap', fontWeight: 600 }}>{q.prompt}</div>
                                <div style={{ marginTop: 4 }}>
                                    {q.requiresGrading ? (
                                        <span className="status-item warning">{t('Pending grading')}</span>
                                    ) : (
                                        <span className={`status-item ${q.isCorrect ? 'neutral' : 'warning'}`}>
                                            {q.isCorrect ? t('Correct') : t('Incorrect')} · {q.awarded}/{q.points}
                                        </span>
                                    )}
                                </div>
                                <div style={{ fontSize: 14, marginTop: 6 }}><strong>{t('Your answer')}:</strong> {fmtAnswer(q.studentAnswer, q.options)}</div>
                                {q.type !== 'essay' && q.correctAnswer != null ? (
                                    <div style={{ fontSize: 14 }}><strong>{t('Correct answer')}:</strong> {fmtAnswer(q.correctAnswer, q.options)}</div>
                                ) : null}
                                {q.explanation ? (
                                    <div style={{ fontSize: 13, color: 'var(--muted)', marginTop: 4, whiteSpace: 'pre-wrap' }}><strong>{t('Explanation')}:</strong> {q.explanation}</div>
                                ) : null}
                            </li>
                        ))}
                    </ol>
                </section>
            ) : null}

            <div style={{ marginTop: 18 }}>
                <a className="ghost-button" href="/student">{t('Back to my exams')}</a>
            </div>
        </AppLayout>
    );
}
