import { LifeBuoy } from 'lucide-react';
import { useEffect, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

function fmtTime(s) {
    const m = Math.floor(s / 60);
    return `${m}:${String(s % 60).padStart(2, '0')}`;
}

export default function LiveMonitor({ examId, examsBasePath }) {
    const [data, setData] = useState(null);
    const [error, setError] = useState('');

    useEffect(() => {
        let alive = true;
        async function load() {
            try {
                const res = await fetch('/api/teacher/exams/' + examId + '/live-scores', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                const d = await res.json().catch(() => ({}));
                if (!alive) return;
                if (!res.ok) { setError(d.error || 'Failed to load.'); return; }
                setData(d);
            } catch { if (alive) setError('Network error.'); }
        }
        load();
        const t = setInterval(load, 7000);
        return () => { alive = false; clearInterval(t); };
    }, [examId]);

    async function finalizeDrafts() {
        if (!window.confirm('Recover lost submissions? Draft/expired sessions that have saved answers will be scored and added to the gradebook.')) return;
        const res = await fetch('/api/teacher/exams/' + examId + '/finalize-drafts', { method: 'POST', headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const d = await res.json().catch(() => ({}));
        window.alert(d.recovered != null ? `Recovered ${d.recovered}, skipped (empty) ${d.skippedEmpty}, errors ${d.errors}.` : (d.error || 'Failed.'));
        if (d.recovered) window.location.reload();
    }

    if (error) return <AppLayout><p className="form-error">{error}</p><a className="ghost-button" href={examsBasePath}>← Back</a></AppLayout>;
    if (!data) return <AppLayout><p style={{ color: 'var(--muted)' }}>Loading…</p></AppLayout>;

    const inProgress = data.students.filter((s) => s.status === 'draft').length;
    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div>
                    <h1>Live monitor — {data.exam.name}</h1>
                    <p>{data.students.length} student(s) · {inProgress} in progress · auto-refreshing every 7s</p>
                </div>
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                    <button className="ghost-button" type="button" onClick={finalizeDrafts}><LifeBuoy size={15} aria-hidden /> Recover lost submissions</button>
                    <a className="ghost-button" href={examsBasePath}>← Back to exams</a>
                </div>
            </header>
            <section className="admin-panel">
                {data.students.length === 0 ? (
                    <p style={{ color: 'var(--muted)', margin: 0 }}>No sessions yet.</p>
                ) : (
                    <table className="dashboard-table">
                        <thead><tr><th>Student</th><th>Status</th><th>Answered</th><th>Auto score</th><th>Essays pending</th><th>Flags</th><th>Time left</th></tr></thead>
                        <tbody>
                            {data.students.map((s) => (
                                <tr key={s.userId}>
                                    <td><strong>{s.fullName}</strong><div style={{ color: 'var(--muted)', fontSize: '0.85rem' }}>{s.username}</div></td>
                                    <td>{s.status === 'draft' ? <span className="status-item neutral">In progress</span> : s.status === 'submitted' ? <span className="status-item neutral">Submitted</span> : <span className="status-item warning">{s.status}</span>}</td>
                                    <td>{s.answeredCount}/{s.totalQuestions}</td>
                                    <td>{s.autoEarned}/{s.autoPossible} ({s.autoPct}%)</td>
                                    <td>{s.essayPending}</td>
                                    <td>{s.antiCheatCount > 0 ? <span className="status-item warning" title="Anti-cheat events recorded">⚠ {s.antiCheatCount}</span> : <span style={{ color: 'var(--muted)' }}>—</span>}</td>
                                    <td>{s.status === 'submitted' ? '—' : fmtTime(s.timeRemainingSeconds)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>
        </AppLayout>
    );
}
