import AppLayout from '../../Layouts/AppLayout';

export default function Result({ submission }) {
    const s = submission;
    const result = s.pendingEssayCount > 0 ? 'Pending grading' : s.passed ? 'Passed' : 'Not passed';
    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div>
                    <h1>{s.examName} — Result</h1>
                    <p>Submitted {s.submittedAt ? new Date(s.submittedAt).toLocaleString() : ''}</p>
                </div>
            </header>

            <div className="admin-metrics admin-metrics--3col">
                <div className="admin-panel metric-card">
                    <span>Score</span>
                    <strong>{s.percentScore}%</strong>
                    <small className="metric-card-sub">{s.finalScore} / {s.possibleScore} pts</small>
                </div>
                <div className="admin-panel metric-card">
                    <span>Result</span>
                    <strong>{result}</strong>
                    <small className="metric-card-sub">pass mark {s.passingGrade}%</small>
                </div>
                <div className="admin-panel metric-card">
                    <span>Essays to grade</span>
                    <strong>{s.pendingEssayCount}</strong>
                    <small className="metric-card-sub">marked by your teacher</small>
                </div>
            </div>

            {s.topicBreakdown && s.topicBreakdown.length > 0 ? (
                <section className="admin-panel" style={{ marginTop: 16 }}>
                    <div className="section-title-row"><div><h2>By topic</h2></div></div>
                    <table className="dashboard-table">
                        <thead><tr><th>Topic</th><th>Score</th><th>Correct</th></tr></thead>
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
                <a className="ghost-button" href="/student">Back to my exams</a>
            </div>
        </AppLayout>
    );
}
