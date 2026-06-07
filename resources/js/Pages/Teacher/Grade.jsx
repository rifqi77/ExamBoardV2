import { AlertTriangle, ShieldCheck, Sparkles } from 'lucide-react';
import MarkdownContent from '../../Components/MarkdownContent';
import { useEffect, useRef, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

function fmtAnswer(v) {
    if (v === null || v === undefined || v === '') return '—';
    if (Array.isArray(v)) return v.join(', ');
    return String(v);
}

const EVENT_LABELS = {
    tab_blur: 'Left the exam tab', tab_focus: 'Returned to the tab',
    fullscreen_exit: 'Exited fullscreen', fullscreen_enter: 'Entered fullscreen',
    copy_blocked: 'Copy blocked', paste_blocked: 'Paste blocked',
    contextmenu_blocked: 'Right-click blocked', seb_missing: 'Opened without Safe Exam Browser',
    session_resumed: 'Resumed / refreshed the exam', auto_submitted_timeout: 'Auto-submitted (time up)',
};

const FLAG = {
    unstable: ['AI grades varied across runs — review', '#b45309'],
    disagree: ['Wording vs meaning mismatch — review', '#b42318'],
    no_model_answer: ['No model answer set — AI judged alone', '#b45309'],
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
                {Object.entries(counts).map(([k, n]) => <span key={k} className="status-item warning">{EVENT_LABELS[k] || k} · {n}</span>)}
            </div>
            <details>
                <summary style={{ cursor: 'pointer', color: 'var(--muted)', fontSize: 13 }}>Timeline</summary>
                <ul style={{ maxHeight: 220, overflow: 'auto', fontSize: 13, marginTop: 8 }}>
                    {events.map((e, i) => <li key={i}><code>{new Date(e.at).toLocaleTimeString()}</code> — {EVENT_LABELS[e.kind] || e.kind}{e.detail ? <span style={{ color: 'var(--muted)' }}> ({e.detail})</span> : null}</li>)}
                </ul>
            </details>
        </section>
    );
}

function SuggestionBox({ s, onUse }) {
    const flag = s.flag ? FLAG[s.flag] : null;
    return (
        <div style={{ marginTop: 10, padding: 10, border: '1px solid #c7d2fe', background: '#eef2ff', borderRadius: 8 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 8 }}>
                <strong style={{ color: '#4338ca' }}><Sparkles size={14} aria-hidden style={{ verticalAlign: '-2px' }} /> AI suggestion (draft)</strong>
                {s.suggested != null ? (
                    <button className="ghost-button" type="button" onClick={() => onUse(s.suggested)}>Use {s.suggested} / {s.maxPoints}</button>
                ) : null}
            </div>
            {s.ai ? (
                <div style={{ fontSize: 13, marginTop: 4 }}>
                    Suggested <b>{s.ai.mean}</b> / {s.maxPoints} <span style={{ color: 'var(--muted)' }}>(±{s.ai.sd} over {s.ai.runs} run{s.ai.runs === 1 ? '' : 's'})</span>
                    {s.ai.feedback ? <div style={{ color: '#3f3f46', marginTop: 4 }}>{s.ai.feedback}</div> : null}
                </div>
            ) : <div style={{ fontSize: 13, color: 'var(--muted)', marginTop: 4 }}>AI unavailable — lexical estimate only.</div>}
            {s.lexical ? (
                <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 4 }}>
                    Similarity vs model answer — ROUGE-1 {s.lexical.rouge1} · ROUGE-L {s.lexical.rougeL} · BLEU {s.lexical.bleu}
                </div>
            ) : null}
            {flag ? <div className="status-item warning" style={{ marginTop: 6, color: flag[1] }}><AlertTriangle size={12} aria-hidden /> {flag[0]}</div> : null}
        </div>
    );
}

function QuestionCard({ q, submissionId, suggestion, setScoreExternal }) {
    const [score, setScore] = useState(q.manualScore ?? '');
    const [saved, setSaved] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    async function save() {
        setBusy(true); setError(''); setSaved(false);
        try {
            const res = await fetch('/api/teacher/submissions/' + submissionId + '/grade', {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ questionId: q.id, score: score === '' ? null : Number(score) }), credentials: 'same-origin',
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) { setError(data.error || 'Failed to save.'); setBusy(false); return; }
            setSaved(true); setBusy(false);
            setTimeout(() => window.location.reload(), 900);
        } catch { setError('Network error.'); setBusy(false); }
    }

    const isEssay = q.type === 'essay';
    return (
        <section className="admin-panel" style={{ marginTop: 14 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                <strong>Q{q.position} · {q.type} · {q.topic}</strong>
                <span style={{ color: 'var(--muted)', fontSize: 13 }}>{q.points} pt</span>
            </div>
            <MarkdownContent>{q.prompt}</MarkdownContent>
            <div style={{ background: '#fafafa', border: '1px solid #eee', borderRadius: 8, padding: '8px 10px' }}>
                <div style={{ fontSize: 12, color: 'var(--muted)' }}>Student answer</div>
                <div style={{ whiteSpace: 'pre-wrap' }}>{fmtAnswer(q.studentAnswer)}</div>
            </div>
            {isEssay && q.originality ? (
                <div style={{ marginTop: 6, fontSize: 13 }}>
                    {q.originality.flag ? (
                        <span className="status-item warning" title="Word overlap with another student's answer to this question">
                            <AlertTriangle size={13} aria-hidden /> Possible copy — {q.originality.similarity}% overlap{q.originality.matchName ? ` with ${q.originality.matchName}` : ''} (review)
                        </span>
                    ) : (
                        <span style={{ color: 'var(--muted)' }}>Originality OK · {q.originality.similarity}% max peer overlap</span>
                    )}
                </div>
            ) : null}
            {isEssay && q.rubric && q.rubric.length ? (
                <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 6 }}>
                    Rubric: {q.rubric.map((c, i) => <span key={i}>{c.criterion} ({c.points})  </span>)}
                </div>
            ) : null}
            {!isEssay ? (
                <p style={{ fontSize: 13, color: 'var(--muted)', marginTop: 8 }}>Correct: <MarkdownContent inline>{fmtAnswer(q.correctAnswer)}</MarkdownContent> · auto-graded</p>
            ) : (
                <>
                    {suggestion ? <SuggestionBox s={suggestion} onUse={(v) => setScore(String(v))} /> : null}
                    <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginTop: 10, flexWrap: 'wrap' }}>
                        <label style={{ fontSize: 13 }}>
                            Score
                            <input type="number" min="0" max={q.points} step="0.5" value={score} onChange={(e) => setScore(e.target.value)} style={{ width: 90, margin: '0 6px' }} />
                            / {q.points}
                        </label>
                        <button className="primary-button" type="button" onClick={save} disabled={busy}>{busy ? 'Saving…' : 'Save'}</button>
                        {saved ? <span className="status-item neutral">Saved</span> : null}
                        {error ? <span className="form-error" style={{ margin: 0 }}>{error}</span> : null}
                    </div>
                </>
            )}
        </section>
    );
}

export default function Grade({ submission, questions, scoresBasePath }) {
    const s = submission;
    const [suggestions, setSuggestions] = useState(s.gradingSuggestions || {});
    const [busy, setBusy] = useState(false);
    const [note, setNote] = useState('');
    const [quality, setQuality] = useState(null);
    const hasEssays = questions.some((q) => q.type === 'essay');
    const alive = useRef(true);
    useEffect(() => () => { alive.current = false; }, []);

    async function suggest() {
        setBusy(true); setNote('');
        try {
            const res = await fetch('/api/teacher/submissions/' + s.id + '/suggest-grades', {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ runs: 3 }), credentials: 'same-origin',
            });
            const d = await res.json().catch(() => ({}));
            if (!res.ok) { setNote(d.error || 'Suggestion failed.'); setBusy(false); return; }
            pollSuggest(d.jobId);
        } catch { setNote('Network error.'); setBusy(false); }
    }
    async function pollSuggest(jobId) {
        // Under the sync queue the job is already done on the first poll; with a
        // worker it streams. Cap at ~5 min as a safety net.
        for (let i = 0; i < 200; i++) {
            await new Promise((r) => setTimeout(r, 1500));
            if (!alive.current) return;
            let j;
            try {
                const res = await fetch('/api/teacher/ai-jobs/' + jobId, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                j = await res.json();
            } catch { continue; }
            if (!alive.current) return;
            if (j.status === 'done') {
                setSuggestions(j.result || {});
                setNote('AI drafts ready — review each, then Save. You decide the final mark.');
                setBusy(false); return;
            }
            if (j.status === 'failed') { setNote(j.error || 'Suggestion failed.'); setBusy(false); return; }
        }
        setNote('Still working — ensure the queue worker is running (docs/OPERATIONS.md).');
        setBusy(false);
    }
    async function checkQuality() {
        try {
            const res = await fetch('/api/teacher/exams/' + s.examId + '/grading-quality', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            setQuality(await res.json().catch(() => ({})));
        } catch { setQuality({ error: 'Network error.' }); }
    }

    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div>
                    <h1>Grade — {s.studentName}</h1>
                    <p>
                        {s.examName} · {s.username} · {s.finalScore}/{s.possibleScore} ({s.percentScore}%) ·{' '}
                        {s.pendingEssayCount > 0 ? `${s.pendingEssayCount} essay(s) pending` : s.passed ? 'Passed' : 'Not passed'}
                    </p>
                </div>
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                    {hasEssays ? <button className="ghost-button" type="button" onClick={suggest} disabled={busy}><Sparkles size={15} aria-hidden /> {busy ? 'Thinking…' : 'Suggest with AI'}</button> : null}
                    {hasEssays ? <button className="ghost-button" type="button" onClick={checkQuality}>Validate (χ²)</button> : null}
                    <a className="ghost-button" href={scoresBasePath}>← Back to scores</a>
                </div>
            </header>

            {note ? <p className="form-success">{note}</p> : null}
            {quality ? (
                <section className="admin-panel" style={{ marginTop: 0 }}>
                    <strong>AI-vs-human grading quality (this exam)</strong>
                    {quality.message ? <p style={{ color: 'var(--muted)', margin: '4px 0 0' }}>{quality.message}</p> : quality.error ? <p className="form-error">{quality.error}</p> : (
                        <p style={{ margin: '4px 0 0' }}>
                            χ² = {quality.chiSquare}, df {quality.df}, p = {quality.pValue} over {quality.pairs} graded essay(s) — {' '}
                            <span className={`status-item ${quality.aligned ? 'neutral' : 'warning'}`}>{quality.aligned ? 'statistically aligned (p > 0.05)' : 'NOT aligned (p ≤ 0.05) — review rubric'}</span>
                        </p>
                    )}
                </section>
            ) : null}

            <AntiCheatPanel events={s.antiCheatEvents} />
            {questions.map((q) => <QuestionCard key={q.id} q={q} submissionId={s.id} suggestion={suggestions[q.id]} />)}
        </AppLayout>
    );
}
