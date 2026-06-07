import { Pencil, Save, Trash2, Upload } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import MarkdownContent from '../../Components/MarkdownContent';

const TYPES = ['single_choice', 'multi_select', 'short_text', 'numeric', 'essay'];
const DIFFS = ['easy', 'medium', 'hard', 'hots', 'olympiad'];

function BankEditForm({ q, onClose }) {
    const [topic, setTopic] = useState(q.topic || '');
    const [subtopic, setSubtopic] = useState(q.subtopic || '');
    const [subject, setSubject] = useState(q.subject || '');
    const [language, setLanguage] = useState(q.language || '');
    const [difficulty, setDifficulty] = useState(q.difficulty || '');
    const [prompt, setPrompt] = useState(q.prompt || '');
    const [points, setPoints] = useState(q.points ?? 1);
    const [expl, setExpl] = useState(q.explanationText || '');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    async function save(e) {
        e.preventDefault();
        setBusy(true); setError('');
        const payload = {
            type: q.type, topic, subtopic, subject, language, difficulty: difficulty || null,
            prompt, points: Number(points), explanationText: expl,
            options: q.options, correctAnswer: q.correctAnswer,
        };
        try {
            const res = await fetch('/api/teacher/bank/' + q.id, {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(payload), credentials: 'same-origin',
            });
            const d = await res.json().catch(() => ({}));
            if (!res.ok) { setError(d.error || 'Failed.'); setBusy(false); return; }
            window.location.reload();
        } catch { setError('Network error.'); setBusy(false); }
    }

    return (
        <form onSubmit={save} style={{ display: 'grid', gap: 8, margin: '10px 0', padding: 12, border: '1px solid #6366f1', borderRadius: 10 }}>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                <label style={{ flex: 1, minWidth: 120 }}>Topic<input value={topic} onChange={(e) => setTopic(e.target.value)} required /></label>
                <label style={{ flex: 1, minWidth: 120 }}>Subtopic<input value={subtopic} onChange={(e) => setSubtopic(e.target.value)} /></label>
                <label style={{ width: 120 }}>Difficulty
                    <select value={difficulty} onChange={(e) => setDifficulty(e.target.value)}>
                        <option value="">—</option>{DIFFS.map((d) => <option key={d} value={d}>{d}</option>)}
                    </select>
                </label>
                <label style={{ width: 90 }}>Points<input type="number" min="1" max="100" value={points} onChange={(e) => setPoints(e.target.value)} /></label>
            </div>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                <label style={{ flex: 1, minWidth: 120 }}>Subject<input value={subject} onChange={(e) => setSubject(e.target.value)} /></label>
                <label style={{ flex: 1, minWidth: 120 }}>Language<input value={language} onChange={(e) => setLanguage(e.target.value)} /></label>
            </div>
            <label>Prompt<textarea rows={2} value={prompt} onChange={(e) => setPrompt(e.target.value)} required /></label>
            <label>Explanation / marking notes<textarea rows={2} value={expl} onChange={(e) => setExpl(e.target.value)} /></label>
            <small style={{ color: 'var(--muted)' }}>Options &amp; correct answer are preserved as-is (edit a copy inside an exam to change them).</small>
            {error ? <p className="form-error" style={{ margin: 0 }}>{error}</p> : null}
            <div style={{ display: 'flex', gap: 8 }}>
                <button className="primary-button" type="submit" disabled={busy}><Save size={15} aria-hidden /> {busy ? 'Saving…' : 'Save'}</button>
                <button className="ghost-button" type="button" onClick={onClose}>Cancel</button>
            </div>
        </form>
    );
}

function ImportPanel() {
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const [msg, setMsg] = useState('');
    async function run() {
        setBusy(true); setMsg('');
        let parsed;
        try {
            parsed = JSON.parse(text);
            if (!Array.isArray(parsed)) parsed = parsed.questions;
            if (!Array.isArray(parsed)) throw new Error('not array');
        } catch { setMsg('Paste a JSON array of questions (or { "questions": [...] }).'); setBusy(false); return; }
        try {
            const res = await fetch('/api/teacher/bank/import', {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ questions: parsed }), credentials: 'same-origin',
            });
            const d = await res.json().catch(() => ({}));
            if (!res.ok) { setMsg(d.error || 'Import failed.'); setBusy(false); return; }
            setMsg(`Imported ${d.imported}, skipped ${d.skipped}.`);
            setTimeout(() => window.location.reload(), 1200);
        } catch { setMsg('Network error.'); setBusy(false); }
    }
    return (
        <section className="admin-panel">
            <div className="section-title-row"><div><h2>Import questions (JSON)</h2>
                <p style={{ margin: 0, color: 'var(--muted)' }}>Paste a JSON array of questions (e.g. AI output). Each needs type, topic, prompt, points, and correct answer/options.</p></div></div>
            <textarea value={text} onChange={(e) => setText(e.target.value)} rows={5}
                placeholder='[{"type":"single_choice","topic":"Kinematics","prompt":"…","points":1,"options":[{"id":"A","text":"…"}],"correctAnswer":"A","difficulty":"easy"}]'
                style={{ width: '100%', boxSizing: 'border-box', fontFamily: 'monospace', fontSize: 12 }} />
            <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginTop: 6 }}>
                <button className="primary-button" type="button" onClick={run} disabled={busy || !text.trim()}><Upload size={15} aria-hidden /> Import</button>
                {msg ? <span style={{ color: 'var(--muted)' }}>{msg}</span> : null}
            </div>
        </section>
    );
}

export default function Bank({ options, initial }) {
    const [rows, setRows] = useState(initial || []);
    const [filters, setFilters] = useState({ q: '', subject: '', topic: '', type: '', difficulty: '', language: '' });
    const [editing, setEditing] = useState(null);
    const [loading, setLoading] = useState(false);

    async function applyFilters(next) {
        const f = next || filters;
        setLoading(true);
        const qs = new URLSearchParams(Object.entries(f).filter(([, v]) => v !== '')).toString();
        try {
            const res = await fetch('/api/teacher/bank?' + qs, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const d = await res.json().catch(() => ({}));
            setRows(d.questions || []);
        } catch { /* keep old */ }
        setLoading(false);
    }
    function setF(key, val) {
        const next = { ...filters, [key]: val };
        setFilters(next);
        applyFilters(next);
    }
    async function del(id) {
        if (!window.confirm('Delete this bank question?')) return;
        await fetch('/api/teacher/bank/' + id + '/delete', { method: 'POST', headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        applyFilters();
    }

    const sel = (key, list) => (
        <select value={filters[key]} onChange={(e) => setF(key, e.target.value)}>
            <option value="">All {key}</option>
            {(list || []).map((v) => <option key={v} value={v}>{v}</option>)}
        </select>
    );

    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div>
                    <h1>Question bank</h1>
                    <p>{rows.length} question{rows.length === 1 ? '' : 's'}{loading ? ' · loading…' : ''}. Filter, edit, delete, or import.</p>
                </div>
            </header>

            <section className="admin-panel">
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                    <input placeholder="Search prompt/topic…" value={filters.q} onChange={(e) => setF('q', e.target.value)} style={{ minWidth: 220 }} />
                    {sel('subject', options.subjects)}
                    {sel('topic', options.topics)}
                    {sel('type', options.types)}
                    {sel('difficulty', options.difficulties)}
                    {sel('language', options.languages)}
                </div>
                {rows.length === 0 ? (
                    <p style={{ color: 'var(--muted)', marginTop: 12 }}>No questions match. (Teachers see only questions they uploaded; import some below.)</p>
                ) : (
                    <table className="dashboard-table" style={{ marginTop: 12 }}>
                        <thead><tr><th>Type</th><th>Topic</th><th>Difficulty</th><th>Prompt</th><th>Pts</th><th></th></tr></thead>
                        <tbody>
                            {rows.map((q) => (
                                <>
                                    <tr key={q.id}>
                                        <td>{q.type}</td>
                                        <td>{q.topic}{q.subtopic ? ` / ${q.subtopic}` : ''}</td>
                                        <td>{q.difficulty || '—'}</td>
                                        <td style={{ maxWidth: 340, overflow: 'hidden' }}><MarkdownContent inline>{q.prompt}</MarkdownContent></td>
                                        <td>{q.points}</td>
                                        <td style={{ display: 'flex', gap: 6 }}>
                                            <button className="ghost-button" type="button" title="Edit" onClick={() => setEditing(editing === q.id ? null : q.id)}><Pencil size={14} aria-hidden /></button>
                                            <button className="ghost-button danger" type="button" title="Delete" onClick={() => del(q.id)}><Trash2 size={14} aria-hidden /></button>
                                        </td>
                                    </tr>
                                    {editing === q.id ? (
                                        <tr key={q.id + '-edit'}><td colSpan={6}><BankEditForm q={q} onClose={() => setEditing(null)} /></td></tr>
                                    ) : null}
                                </>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>

            <ImportPanel />
        </AppLayout>
    );
}
