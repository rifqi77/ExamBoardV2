import { AlertTriangle, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

function fmtAnswer(v) {
    if (v === null || v === undefined || v === '') return '—';
    if (Array.isArray(v)) return v.join(', ');
    return String(v);
}

const EVENT_LABELS = {
    tab_blur: 'Left the exam tab',
    tab_focus: 'Returned to the tab',
    fullscreen_exit: 'Exited fullscreen',
    fullscreen_enter: 'Entered fullscreen',
    copy_blocked: 'Copy blocked',
    paste_blocked: 'Paste blocked',
    contextmenu_blocked: 'Right-click blocked',
    seb_missing: 'Opened without Safe Exam Browser',
    session_resumed: 'Resumed / refreshed the exam',
    auto_submitted_timeout: 'Auto-submitted (time up)',
};

function AntiCheatPanel({ events }) {
    if (!events || events.length === 0) {
        return (
            <section className="admin-panel" style={{ marginTop: 14 }}>
                <strong><ShieldCheck size={16} aria-hidden style={{ verticalAlign: '-3px', marginRight: 6, color: '#16a34a' }} />Integrity</strong>
                <p style={{ color: 'var(--muted)', margin: '6px 0 0' }}>No anti-cheat events recorded for this attempt.</p>
            </section>
        );
    }
    const counts = {};
    for (const e of events) counts[e.kind] = (counts[e.kind] || 0) + 1;
    return (
        <section className="admin-panel" style={{ marginTop: 14, borderColor: '#f59e0b' }}>
            <strong><AlertTriangle size={16} aria-hidden style={{ verticalAlign: '-3px', marginRight: 6, color: '#b45309' }} />Anti-cheat events ({events.length})</strong>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, margin: '8px 0' }}>
                {Object.entries(counts).map(([k, n]) => (
                    <span key={k} className="status-item warning">{EVENT_LABELS[k] || k} · {n}</span>
                ))}
            </div>
            <details>
                <summary style={{ cursor: 'pointer', color: 'var(--muted)', fontSize: 13 }}>Timeline</summary>
                <ul style={{ maxHeight: 220, overflow: 'auto', fontSize: 13, marginTop: 8 }}>
                    {events.map((e, i) => (
                        <li key={i}>
                            <code>{new Date(e.at).toLocaleTimeString()}</code> — {EVENT_LABELS[e.kind] || e.kind}
                            {e.detail ? <span style={{ color: 'var(--muted)' }}> ({e.detail})</span> : null}
                        </li>
                    ))}
                </ul>
            </details>
        </section>
    );
}

function QuestionCard({ q, submissionId }) {
    const [score, setScore] = useState(q.manualScore ?? '');
    const [saved, setSaved] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    async function save() {
        setBusy(true);
        setError('');
        setSaved(false);
        try {
            const res = await fetch('/api/teacher/submissions/' + submissionId + '/grade', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ questionId: q.id, score: score === '' ? null : Number(score) }),
                credentials: 'same-origin',
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                setError(data.error || 'Failed to save.');
                setBusy(false);
                return;
            }
            setSaved(true);
            setBusy(false);
            setTimeout(() => window.location.reload(), 900);
        } catch {
            setError('Network error.');
            setBusy(false);
        }
    }

    const isEssay = q.type === 'essay';
    return (
        <section className="admin-panel" style={{ marginTop: 14 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                <strong>Q{q.position} · {q.type} · {q.topic}</strong>
                <span style={{ color: 'var(--muted)', fontSize: 13 }}>{q.points} pt</span>
            </div>
            <p style={{ whiteSpace: 'pre-wrap' }}>{q.prompt}</p>
            <div style={{ background: '#fafafa', border: '1px solid #eee', borderRadius: 8, padding: '8px 10px' }}>
                <div style={{ fontSize: 12, color: 'var(--muted)' }}>Student answer</div>
                <div style={{ whiteSpace: 'pre-wrap' }}>{fmtAnswer(q.studentAnswer)}</div>
            </div>
            {!isEssay ? (
                <p style={{ fontSize: 13, color: 'var(--muted)', marginTop: 8 }}>
                    Correct: <code>{fmtAnswer(q.correctAnswer)}</code> · auto-graded
                </p>
            ) : (
                <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginTop: 10, flexWrap: 'wrap' }}>
                    <label style={{ fontSize: 13 }}>
                        Score
                        <input type="number" min="0" max={q.points} step="0.5" value={score}
                            onChange={(e) => setScore(e.target.value)} style={{ width: 90, margin: '0 6px' }} />
                        / {q.points}
                    </label>
                    <button className="primary-button" type="button" onClick={save} disabled={busy}>
                        {busy ? 'Saving…' : 'Save'}
                    </button>
                    {saved ? <span className="status-item neutral">Saved</span> : null}
                    {error ? <span className="form-error" style={{ margin: 0 }}>{error}</span> : null}
                </div>
            )}
        </section>
    );
}

export default function Grade({ submission, questions, scoresBasePath }) {
    const s = submission;
    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div>
                    <h1>Grade — {s.studentName}</h1>
                    <p>
                        {s.examName} · {s.username} · {s.finalScore}/{s.possibleScore} ({s.percentScore}%) ·{' '}
                        {s.pendingEssayCount > 0
                            ? `${s.pendingEssayCount} essay(s) pending`
                            : s.passed ? 'Passed' : 'Not passed'}
                    </p>
                </div>
                <a className="ghost-button" href={scoresBasePath}>← Back to scores</a>
            </header>
            <AntiCheatPanel events={s.antiCheatEvents} />
            {questions.map((q) => <QuestionCard key={q.id} q={q} submissionId={s.id} />)}
        </AppLayout>
    );
}
