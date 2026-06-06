import { AlertTriangle, CheckCircle2 } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';

export default function AnswerAudit({ exam, rows, totalMismatch, examsBasePath }) {
    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div>
                    <h1>Answer audit — {exam.name}</h1>
                    <p>{rows.length} session(s) · {totalMismatch} mismatch(es) between saved drafts and the submitted snapshot.</p>
                </div>
                <a className="ghost-button" href={examsBasePath}>← Back to exams</a>
            </header>
            <section className="admin-panel">
                {rows.length === 0 ? (
                    <p style={{ color: 'var(--muted)', margin: 0 }}>No sessions yet.</p>
                ) : (
                    <table className="dashboard-table">
                        <thead><tr><th>Student</th><th>Status</th><th>Drafts</th><th>Snapshot</th><th>Integrity</th><th>Score</th><th>Submitted</th></tr></thead>
                        <tbody>
                            {rows.map((r, i) => (
                                <tr key={i}>
                                    <td><strong>{r.fullName}</strong><div style={{ color: 'var(--muted)', fontSize: '0.85rem' }}>{r.username}</div></td>
                                    <td>{r.status}</td>
                                    <td>{r.draftCount}</td>
                                    <td>{r.snapCount}</td>
                                    <td>{r.mismatchCount > 0
                                        ? <span className="status-item warning"><AlertTriangle size={14} aria-hidden /> {r.mismatchCount} mismatch</span>
                                        : <span className="status-item neutral"><CheckCircle2 size={14} aria-hidden /> OK</span>}</td>
                                    <td>{r.percentScore !== null ? `${r.percentScore}%` : '—'}</td>
                                    <td>{r.submittedAt ? new Date(r.submittedAt).toLocaleString() : '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>
        </AppLayout>
    );
}
