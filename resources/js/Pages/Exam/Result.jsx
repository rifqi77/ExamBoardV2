import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

export default function Result({ submission }) {
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

            <div style={{ marginTop: 18 }}>
                <a className="ghost-button" href="/student">{t('Back to my exams')}</a>
            </div>
        </AppLayout>
    );
}
