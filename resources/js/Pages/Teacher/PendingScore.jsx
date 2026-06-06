import { ClipboardCopy, Download, Upload } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

function ExamBlock({ group, scoresBasePath }) {
    const [markdown, setMarkdown] = useState('');
    const [importText, setImportText] = useState('');
    const [busy, setBusy] = useState(false);
    const [msg, setMsg] = useState('');

    async function exportAi() {
        setBusy(true); setMsg('');
        try {
            const res = await fetch('/api/teacher/exams/' + group.examId + '/ai-export', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const d = await res.json().catch(() => ({}));
            if (!res.ok) { setMsg(d.error || 'Export failed.'); setBusy(false); return; }
            setMarkdown(d.markdown || '');
            setBusy(false);
        } catch { setMsg('Network error.'); setBusy(false); }
    }
    async function importScores() {
        setBusy(true); setMsg('');
        let parsed;
        try {
            parsed = JSON.parse(importText);
            if (!Array.isArray(parsed)) throw new Error('not array');
        } catch { setMsg('Paste a JSON array of { submissionId, questionId, score }.'); setBusy(false); return; }
        try {
            const res = await fetch('/api/teacher/grade-bulk', {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ scores: parsed }), credentials: 'same-origin',
            });
            const d = await res.json().catch(() => ({}));
            if (!res.ok) { setMsg(d.error || 'Import failed.'); setBusy(false); return; }
            setMsg(`Applied ${d.applied}, skipped ${d.skipped}.`);
            setTimeout(() => window.location.reload(), 1200);
        } catch { setMsg('Network error.'); setBusy(false); }
    }

    return (
        <section className="admin-panel" style={{ marginTop: 14 }}>
            <div className="section-title-row">
                <div><h2>{group.name}</h2><p style={{ margin: 0, color: 'var(--muted)' }}><code>{group.examId}</code> · {group.submissions.length} awaiting grading</p></div>
                <button className="ghost-button" type="button" onClick={exportAi} disabled={busy}><Download size={15} aria-hidden /> Export essays for AI</button>
            </div>

            <table className="dashboard-table" style={{ marginTop: 12 }}>
                <thead><tr><th>Student</th><th>Pending essays</th><th>Auto %</th><th></th></tr></thead>
                <tbody>
                    {group.submissions.map((s) => (
                        <tr key={s.id}>
                            <td><strong>{s.studentName}</strong><div style={{ color: 'var(--muted)', fontSize: '0.85rem' }}>{s.username}</div></td>
                            <td>{s.pendingEssayCount}</td>
                            <td>{s.percentScore}%</td>
                            <td><a className="ghost-button" href={`${scoresBasePath}/${s.id}`}>Grade</a></td>
                        </tr>
                    ))}
                </tbody>
            </table>

            {markdown ? (
                <div style={{ marginTop: 12 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                        <strong>AI prompt (copy → paste into ChatGPT/Claude/Gemini)</strong>
                        <button className="ghost-button" type="button" onClick={() => navigator.clipboard?.writeText(markdown)}><ClipboardCopy size={14} aria-hidden /> Copy</button>
                    </div>
                    <textarea readOnly value={markdown} rows={8} style={{ width: '100%', boxSizing: 'border-box', fontFamily: 'monospace', fontSize: 12 }} />
                </div>
            ) : null}

            <div style={{ marginTop: 12 }}>
                <strong>Paste the AI's JSON result to apply scores</strong>
                <textarea value={importText} onChange={(e) => setImportText(e.target.value)} rows={4}
                    placeholder='[{"submissionId":"…","questionId":"…","score":7}]'
                    style={{ width: '100%', boxSizing: 'border-box', fontFamily: 'monospace', fontSize: 12 }} />
                <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginTop: 6 }}>
                    <button className="primary-button" type="button" onClick={importScores} disabled={busy || !importText.trim()}><Upload size={15} aria-hidden /> Apply AI scores</button>
                    {msg ? <span style={{ color: 'var(--muted)' }}>{msg}</span> : null}
                </div>
            </div>
        </section>
    );
}

export default function PendingScore({ groups, scoresBasePath }) {
    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div>
                    <h1>Pending essay grading</h1>
                    <p>Submissions with essays still awaiting a manual score. Grade by hand, or export to an AI and paste the result back.</p>
                </div>
                <a className="ghost-button" href={scoresBasePath}>← All scores</a>
            </header>
            {groups.length === 0 ? (
                <section className="admin-panel"><p style={{ color: 'var(--muted)', margin: 0 }}>Nothing pending — all essays are graded. 🎉</p></section>
            ) : groups.map((g) => <ExamBlock key={g.examId} group={g} scoresBasePath={scoresBasePath} />)}
        </AppLayout>
    );
}
