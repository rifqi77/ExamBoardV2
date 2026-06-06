import { CheckCircle2, CircleX, Clock, Trash2 } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';

async function deleteSubmission(id) {
    if (!window.confirm('Delete this submission permanently? The student would need to retake the exam.')) return;
    await fetch('/api/teacher/submissions/' + id + '/delete', { method: 'POST', headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    window.location.reload();
}

export default function Scores({ groups, scoresBasePath }) {
    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div>
                    <h1>Scores</h1>
                    <p>Submissions grouped by exam.</p>
                </div>
            </header>
            {groups.length === 0 ? (
                <section className="admin-panel"><p style={{ color: 'var(--muted)', margin: 0 }}>No submissions yet.</p></section>
            ) : (
                groups.map((g, i) => (
                    <section className="admin-panel" key={i}>
                        <div className="section-title-row">
                            <div>
                                <h2>{g.name}</h2>
                                <p style={{ margin: 0, color: 'var(--muted)' }}>
                                    <code>{g.examId}</code> · {g.submissions.length} submission{g.submissions.length === 1 ? '' : 's'}
                                </p>
                            </div>
                        </div>
                        <table className="dashboard-table">
                            <thead>
                                <tr><th>Student</th><th>Score</th><th>Percent</th><th>Status</th><th>Submitted</th><th></th></tr>
                            </thead>
                            <tbody>
                                {g.submissions.map((s) => (
                                    <tr key={s.id}>
                                        <td>
                                            <strong>{s.studentName}</strong>
                                            <div style={{ color: 'var(--muted)', fontSize: '0.85rem' }}>{s.username}</div>
                                        </td>
                                        <td>{s.finalScore} <span style={{ color: 'var(--muted)' }}>/ {s.possibleScore}</span></td>
                                        <td>{s.percentScore}%</td>
                                        <td>
                                            {s.pendingEssayCount > 0 ? (
                                                <span className="status-item warning"><Clock size={14} aria-hidden /> Pending ({s.pendingEssayCount})</span>
                                            ) : s.passed ? (
                                                <span className="status-item neutral"><CheckCircle2 size={14} aria-hidden /> Passed</span>
                                            ) : (
                                                <span className="status-item warning"><CircleX size={14} aria-hidden /> Not passed</span>
                                            )}
                                        </td>
                                        <td>{s.submittedAt ? new Date(s.submittedAt).toLocaleString() : '—'}</td>
                                        <td style={{ display: 'flex', gap: 6 }}>
                                            <a className="ghost-button" href={`${scoresBasePath}/${s.id}`}>
                                                {s.pendingEssayCount > 0 ? 'Grade' : 'View'}
                                            </a>
                                            <button className="ghost-button danger" type="button" title="Delete submission" onClick={() => deleteSubmission(s.id)}><Trash2 size={14} aria-hidden /></button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>
                ))
            )}
        </AppLayout>
    );
}
