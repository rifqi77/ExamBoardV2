import { CheckCircle2, CircleX, Clock, PlayCircle } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

export default function Hub({ submissions }) {
    const [token, setToken] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    async function start(e) {
        e.preventDefault();
        setBusy(true);
        setError('');
        try {
            const res = await fetch('/api/exam-access/validate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ token }),
                credentials: 'same-origin',
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                if (data.alreadySubmitted && data.submissionId) {
                    window.location.href = '/student/result/' + data.submissionId;
                    return;
                }
                setError(data.error || 'Invalid token.');
                setBusy(false);
                return;
            }
            window.location.href = '/exam/' + data.examId;
        } catch {
            setError('Network error.');
            setBusy(false);
        }
    }

    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div>
                    <h1>My exams</h1>
                    <p>Start an exam or review your past scores.</p>
                </div>
            </header>

            <section className="admin-panel">
                <div className="section-title-row">
                    <div>
                        <h2>Start an exam</h2>
                        <p style={{ color: 'var(--muted)', margin: 0 }}>Enter the exam token your teacher gave you.</p>
                    </div>
                </div>
                <form onSubmit={start} style={{ display: 'flex', gap: 8, maxWidth: 420, marginTop: 12 }}>
                    <input
                        placeholder="EXAM TOKEN"
                        value={token}
                        onChange={(e) => setToken(e.target.value.toUpperCase())}
                        style={{ flex: 1 }}
                    />
                    <button className="primary-button" type="submit" disabled={busy}>
                        <PlayCircle size={17} aria-hidden /> {busy ? 'Starting…' : 'Start'}
                    </button>
                </form>
                {error ? <p className="form-error" style={{ marginTop: 10 }}>{error}</p> : null}
            </section>

            <section className="admin-panel">
                <div className="section-title-row"><div><h2>My scores</h2></div></div>
                {submissions.length === 0 ? (
                    <p style={{ color: 'var(--muted)', margin: 0 }}>No exams taken yet.</p>
                ) : (
                    <table className="dashboard-table">
                        <thead>
                            <tr><th>Exam</th><th>Score</th><th>Status</th><th>Submitted</th><th></th></tr>
                        </thead>
                        <tbody>
                            {submissions.map((s, i) => (
                                <tr key={i}>
                                    <td><strong>{s.examName}</strong></td>
                                    <td>{s.percentScore}%</td>
                                    <td>
                                        {s.pendingEssayCount > 0 ? (
                                            <span className="status-item warning"><Clock size={14} aria-hidden /> Pending grading</span>
                                        ) : s.passed ? (
                                            <span className="status-item neutral"><CheckCircle2 size={14} aria-hidden /> Passed</span>
                                        ) : (
                                            <span className="status-item warning"><CircleX size={14} aria-hidden /> Not passed</span>
                                        )}
                                    </td>
                                    <td>{s.submittedAt ? new Date(s.submittedAt).toLocaleString() : '—'}</td>
                                    <td>{s.id ? <a className="ghost-button" href={'/student/result/' + s.id}>View</a> : null}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>
        </AppLayout>
    );
}
