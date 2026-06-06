import { CheckCircle2, CircleX, Library, Lock, Pencil, Plus, Save, Settings, ShieldCheck, Sparkles, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

function BankPicker({ examId, onClose }) {
    const [rows, setRows] = useState([]);
    const [sel, setSel] = useState({});
    const [q, setQ] = useState('');
    const [busy, setBusy] = useState(false);
    const [msg, setMsg] = useState('');

    async function load(query) {
        const qs = new URLSearchParams(query ? { q: query } : {}).toString();
        try {
            const res = await fetch('/api/teacher/bank?' + qs, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const d = await res.json().catch(() => ({}));
            setRows(d.questions || []);
        } catch { /* ignore */ }
    }
    useEffect(() => { load(''); /* eslint-disable-next-line */ }, []);

    async function add() {
        const ids = Object.keys(sel).filter((k) => sel[k]);
        if (!ids.length) { setMsg('Select at least one question.'); return; }
        setBusy(true); setMsg('');
        try {
            const res = await fetch('/api/teacher/exams/' + examId + '/questions/from-bank', {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ bankIds: ids }), credentials: 'same-origin',
            });
            const d = await res.json().catch(() => ({}));
            if (!res.ok) { setMsg(d.error || 'Failed.'); setBusy(false); return; }
            setMsg(`Added ${d.added}.`); setTimeout(() => window.location.reload(), 800);
        } catch { setMsg('Network error.'); setBusy(false); }
    }

    return (
        <div style={{ marginTop: 12, padding: 12, border: '1px solid #6366f1', borderRadius: 10 }}>
            <div style={{ display: 'flex', gap: 8, marginBottom: 8 }}>
                <input placeholder="Search the bank…" value={q} onChange={(e) => { setQ(e.target.value); load(e.target.value); }} style={{ flex: 1 }} />
                <button className="ghost-button" type="button" onClick={onClose}>Close</button>
            </div>
            {rows.length === 0 ? (
                <p style={{ color: 'var(--muted)', margin: 0 }}>No bank questions (teachers see only their own uploads). Import some in Question bank.</p>
            ) : (
                <div style={{ maxHeight: 280, overflow: 'auto' }}>
                    {rows.map((r) => (
                        <label key={r.id} style={{ display: 'flex', gap: 8, alignItems: 'flex-start', padding: '6px 0', borderBottom: '1px solid #f0f0f0' }}>
                            <input type="checkbox" checked={!!sel[r.id]} onChange={(e) => setSel((s) => ({ ...s, [r.id]: e.target.checked }))} />
                            <span style={{ fontSize: 13 }}><b>{r.type}</b> · {r.topic}{r.difficulty ? ` · ${r.difficulty}` : ''}<br /><span style={{ color: 'var(--muted)' }}>{r.prompt.slice(0, 120)}</span></span>
                        </label>
                    ))}
                </div>
            )}
            <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginTop: 8 }}>
                <button className="primary-button" type="button" onClick={add} disabled={busy}><Plus size={15} aria-hidden /> Add selected</button>
                {msg ? <span style={{ color: 'var(--muted)' }}>{msg}</span> : null}
            </div>
        </div>
    );
}

function SebPanel({ examId, initialRequired, initialKeySet }) {
    const [required, setRequired] = useState(Boolean(initialRequired));
    const [keySet, setKeySet] = useState(Boolean(initialKeySet));
    const [key, setKey] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);

    async function save(e) {
        e.preventDefault();
        setBusy(true); setError(''); setSaved(false);
        try {
            const res = await fetch('/api/teacher/exams/' + examId + '/seb', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ sebRequired: required, sebKey: key }),
                credentials: 'same-origin',
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) { setError(data.error || 'Failed to save.'); setBusy(false); return; }
            setRequired(Boolean(data.sebRequired));
            setKeySet(Boolean(data.sebKeySet));
            setKey('');
            setSaved(true);
            setBusy(false);
        } catch { setError('Network error.'); setBusy(false); }
    }

    return (
        <section className="admin-panel">
            <div className="section-title-row">
                <div>
                    <h2><ShieldCheck size={18} aria-hidden style={{ verticalAlign: '-3px', marginRight: 6 }} />Safe Exam Browser</h2>
                    <p style={{ margin: 0, color: 'var(--muted)' }}>
                        Lock this exam so it can only be opened inside Safe Exam Browser. The server verifies SEB's
                        request hash on every load — a normal browser is rejected with a 403.
                    </p>
                </div>
                <span className={`status-item ${required ? 'neutral' : 'warning'}`}>
                    <Lock size={14} aria-hidden /> {required ? 'Required' : 'Off'}
                </span>
            </div>

            <form onSubmit={save} style={{ marginTop: 12, display: 'grid', gap: 12, maxWidth: 680 }}>
                <label style={{ display: 'flex', gap: 8, alignItems: 'center', cursor: 'pointer' }}>
                    <input type="checkbox" checked={required} onChange={(e) => setRequired(e.target.checked)} />
                    Require Safe Exam Browser for this exam
                </label>

                <label>Browser Exam Key
                    <input
                        value={key}
                        onChange={(e) => setKey(e.target.value)}
                        placeholder={keySet ? '•••••••• (a key is saved — leave blank to keep it)' : 'Paste the Browser Exam Key from the SEB Config Tool'}
                        autoComplete="off"
                        spellCheck={false}
                    />
                    <small style={{ color: 'var(--muted)' }}>
                        In SEB Config Tool → <b>Exam</b> tab, copy the <b>Browser Exam Key</b>. Distribute the matching
                        <code> .seb</code> file to students. The key is stored encrypted and never shown again.
                    </small>
                </label>

                {error ? <p className="form-error" style={{ margin: 0 }}>{error}</p> : null}
                {saved ? <p className="status-item neutral" style={{ margin: 0 }}><CheckCircle2 size={14} aria-hidden /> Saved.</p> : null}
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                    <button className="primary-button" type="submit" disabled={busy}>
                        <ShieldCheck size={16} aria-hidden /> {busy ? 'Saving…' : 'Save SEB settings'}
                    </button>
                    {keySet ? (
                        <a className="ghost-button" href={'/api/teacher/exams/' + examId + '/seb-config'}>
                            <Lock size={15} aria-hidden /> Download .seb file
                        </a>
                    ) : null}
                </div>
            </form>
        </section>
    );
}

function ExamSettingsPanel({ exam }) {
    const [name, setName] = useState(exam.name);
    const [duration, setDuration] = useState(exam.durationMinutes);
    const [passing, setPassing] = useState(exam.passingGrade);
    const [mode, setMode] = useState(exam.examMode);
    const [active, setActive] = useState(Boolean(exam.active));
    const [instructions, setInstructions] = useState(exam.generalInstructions || '');
    const [shuffleQ, setShuffleQ] = useState(Boolean(exam.shuffleQuestions));
    const [shuffleO, setShuffleO] = useState(Boolean(exam.shuffleOptions));
    const [drawCount, setDrawCount] = useState(exam.drawCount ?? 0);
    const [subject, setSubject] = useState(exam.subject || '');
    const [mediaBase, setMediaBase] = useState(exam.mediaBaseUrl || '');
    const [startTime, setStartTime] = useState(exam.startTime || '');
    const [endTime, setEndTime] = useState(exam.endTime || '');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState(false);

    async function save(e) {
        e.preventDefault();
        setBusy(true); setError(''); setSaved(false);
        const payload = {
            name, durationMinutes: Number(duration), passingGrade: Number(passing), examMode: mode, active,
            generalInstructions: instructions, shuffleQuestions: shuffleQ, shuffleOptions: shuffleO,
            subject, mediaBaseUrl: mediaBase, startTime: startTime || '', endTime: endTime || '',
            drawCount: Number(drawCount) || 0,
        };
        try {
            const res = await fetch('/api/teacher/exams/' + exam.examId + '/settings', {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(payload), credentials: 'same-origin',
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) { setError(data.error || 'Failed to save.'); setBusy(false); return; }
            setSaved(true); setBusy(false);
            setTimeout(() => window.location.reload(), 800);
        } catch { setError('Network error.'); setBusy(false); }
    }

    return (
        <form onSubmit={save} style={{ marginTop: 12, display: 'grid', gap: 12, maxWidth: 720 }}>
            <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
                <label style={{ flex: 2, minWidth: 200 }}>Name
                    <input value={name} onChange={(e) => setName(e.target.value)} />
                </label>
                <label style={{ flex: 1, minWidth: 120 }}>Subject
                    <input value={subject} onChange={(e) => setSubject(e.target.value)} placeholder="(optional)" />
                </label>
            </div>
            <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
                <label style={{ width: 130 }}>Duration (min)
                    <input type="number" min="1" max="480" value={duration} onChange={(e) => setDuration(e.target.value)} />
                </label>
                <label style={{ width: 130 }}>Passing grade %
                    <input type="number" min="0" max="100" value={passing} onChange={(e) => setPassing(e.target.value)} />
                </label>
                <label style={{ width: 150 }}>Mode
                    <select value={mode} onChange={(e) => setMode(e.target.value)}>
                        <option value="strict">Strict (1 attempt)</option>
                        <option value="try_out">Try-out (repeatable)</option>
                    </select>
                </label>
            </div>
            <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
                <label style={{ flex: 1, minWidth: 200 }}>Start time
                    <input type="datetime-local" value={startTime} onChange={(e) => setStartTime(e.target.value)} />
                </label>
                <label style={{ flex: 1, minWidth: 200 }}>End time
                    <input type="datetime-local" value={endTime} onChange={(e) => setEndTime(e.target.value)} />
                </label>
            </div>
            <label>Media base URL
                <input value={mediaBase} onChange={(e) => setMediaBase(e.target.value)} placeholder="https://… (optional, prefixes relative media)" />
            </label>
            <label>General instructions
                <textarea rows={3} value={instructions} onChange={(e) => setInstructions(e.target.value)} />
            </label>
            <div style={{ display: 'flex', gap: 18, flexWrap: 'wrap' }}>
                <label style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
                    <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} /> Active
                </label>
                <label style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
                    <input type="checkbox" checked={shuffleQ} onChange={(e) => setShuffleQ(e.target.checked)} /> Shuffle questions
                </label>
                <label style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
                    <input type="checkbox" checked={shuffleO} onChange={(e) => setShuffleO(e.target.checked)} /> Shuffle options
                </label>
            </div>
            <label style={{ maxWidth: 360 }}>Questions drawn per student (0 = all{exam.questionCount ? ` ${exam.questionCount}` : ''})
                <input type="number" min="0" max={exam.questionCount || 999} value={drawCount} onChange={(e) => setDrawCount(e.target.value)} />
                <small style={{ color: 'var(--muted)' }}>Serve each student a random subset from the pool — different students get different questions, which defeats answer-sharing. Leave 0 to serve every question.</small>
            </label>
            {error ? <p className="form-error" style={{ margin: 0 }}>{error}</p> : null}
            {saved ? <p className="status-item neutral" style={{ margin: 0 }}><CheckCircle2 size={14} aria-hidden /> Saved.</p> : null}
            <div><button className="primary-button" type="submit" disabled={busy}><Save size={16} aria-hidden /> {busy ? 'Saving…' : 'Save settings'}</button></div>
        </form>
    );
}

function QuestionForm({ examId, existing, onClose }) {
    const ex = existing || null;
    const [type, setType] = useState(ex?.type || 'single_choice');
    const [topic, setTopic] = useState(ex?.topic || '');
    const [prompt, setPrompt] = useState(ex?.prompt || '');
    const [points, setPoints] = useState(ex?.points ?? 1);
    const [expl, setExpl] = useState(ex?.explanationText || 'Model answer / marking notes.');
    const [options, setOptions] = useState(() => (
        Array.isArray(ex?.options) && ex.options.length
            ? ex.options.map((o) => ({ id: o.id, text: o.text }))
            : [{ id: 'A', text: '' }, { id: 'B', text: '' }, { id: 'C', text: '' }, { id: 'D', text: '' }]
    ));
    const [correctSingle, setCorrectSingle] = useState(() => (ex && typeof ex.correctAnswer === 'string' ? ex.correctAnswer : 'A'));
    const [correctMulti, setCorrectMulti] = useState(() => (ex && Array.isArray(ex.correctAnswer) ? ex.correctAnswer : []));
    const [correctText, setCorrectText] = useState(() => {
        if (!ex) return '';
        if (typeof ex.correctAnswer === 'number') return String(ex.correctAnswer);
        if (typeof ex.correctAnswer === 'string') return ex.correctAnswer;
        return '';
    });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    const isChoice = type === 'single_choice' || type === 'multi_select';
    const needsText = type === 'numeric' || type === 'short_text';

    async function submit(e) {
        e.preventDefault();
        setBusy(true);
        setError('');
        let correctAnswer = '';
        if (type === 'single_choice') correctAnswer = correctSingle;
        else if (type === 'multi_select') correctAnswer = correctMulti;
        else if (needsText) correctAnswer = correctText;
        const payload = { type, topic, prompt, points: Number(points), explanationText: expl, correctAnswer };
        if (isChoice) payload.options = options.filter((o) => o.text.trim() !== '');
        const url = ex ? '/api/teacher/questions/' + ex.id : '/api/teacher/exams/' + examId + '/questions';
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(payload),
                credentials: 'same-origin',
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) { setError(data.error || 'Failed to save question.'); setBusy(false); return; }
            window.location.reload();
        } catch { setError('Network error.'); setBusy(false); }
    }

    return (
        <form onSubmit={submit} style={{ marginTop: 12, display: 'grid', gap: 10, maxWidth: 680, border: ex ? '1px solid #6366f1' : 'none', borderRadius: ex ? 10 : 0, padding: ex ? 12 : 0 }}>
            {ex ? <strong style={{ color: '#4338ca' }}>Editing question {ex.position}</strong> : null}
            <div style={{ display: 'flex', gap: 12 }}>
                <label style={{ flex: 1 }}>Type
                    <select value={type} onChange={(e) => setType(e.target.value)} style={{ width: '100%' }}>
                        <option value="single_choice">Single choice</option>
                        <option value="multi_select">Multi select</option>
                        <option value="numeric">Numeric</option>
                        <option value="short_text">Short text</option>
                        <option value="essay">Essay</option>
                    </select>
                </label>
                <label style={{ flex: 1 }}>Topic
                    <input value={topic} onChange={(e) => setTopic(e.target.value)} placeholder="Kinematics" required />
                </label>
                <label style={{ width: 110 }}>Points
                    <input type="number" min="1" max="100" value={points} onChange={(e) => setPoints(e.target.value)} />
                </label>
            </div>
            <label>Prompt
                <textarea rows={2} value={prompt} onChange={(e) => setPrompt(e.target.value)} required />
            </label>

            {isChoice ? (
                <div style={{ display: 'grid', gap: 6 }}>
                    {options.map((o, i) => (
                        <div key={o.id} style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                            {type === 'single_choice' ? (
                                <input type="radio" name="correct" checked={correctSingle === o.id} onChange={() => setCorrectSingle(o.id)} />
                            ) : (
                                <input type="checkbox" checked={correctMulti.includes(o.id)}
                                    onChange={(e) => setCorrectMulti((m) => e.target.checked ? [...m, o.id] : m.filter((x) => x !== o.id))} />
                            )}
                            <b>{o.id}.</b>
                            <input style={{ flex: 1 }} value={o.text} placeholder={`Option ${o.id}`}
                                onChange={(e) => setOptions((os) => os.map((x, j) => j === i ? { ...x, text: e.target.value } : x))} />
                        </div>
                    ))}
                    <small style={{ color: 'var(--muted)' }}>Tick the correct option{type === 'multi_select' ? '(s)' : ''}. Empty options are ignored.</small>
                </div>
            ) : null}

            {needsText ? (
                <label>Correct answer
                    <input value={correctText} onChange={(e) => setCorrectText(e.target.value)} placeholder={type === 'numeric' ? 'e.g. 3.14' : 'expected text'} />
                </label>
            ) : null}

            <label>Explanation / marking notes
                <textarea rows={2} value={expl} onChange={(e) => setExpl(e.target.value)} />
            </label>

            {error ? <p className="form-error" style={{ margin: 0 }}>{error}</p> : null}
            <div style={{ display: 'flex', gap: 8 }}>
                <button className="primary-button" type="submit" disabled={busy}>
                    {ex ? <Save size={16} aria-hidden /> : <Plus size={16} aria-hidden />} {busy ? 'Saving…' : ex ? 'Save changes' : 'Add question'}
                </button>
                {onClose ? <button className="ghost-button" type="button" onClick={onClose}>Cancel</button> : null}
            </div>
        </form>
    );
}

export default function ExamDetail({ exam, questions, tokens, examsBasePath }) {
    const [busy, setBusy] = useState(false);
    const [maxUses, setMaxUses] = useState(40);
    const [expiresAt, setExpiresAt] = useState('');
    const [newCode, setNewCode] = useState('');
    const [error, setError] = useState('');
    const [showAdd, setShowAdd] = useState(false);
    const [editingId, setEditingId] = useState(null);
    const [showSettings, setShowSettings] = useState(false);
    const [showBank, setShowBank] = useState(false);

    async function autoFill() {
        if (!window.confirm('Auto-fill this exam from your question bank to match its type/difficulty distribution?')) return;
        try {
            const res = await fetch('/api/teacher/exams/' + exam.examId + '/auto-fill', { method: 'POST', headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const d = await res.json().catch(() => ({}));
            let m = `Added ${d.added ?? 0} question(s).`;
            if (d.warnings && d.warnings.length) m += '\n\n' + d.warnings.join('\n');
            window.alert(d.error || m);
            if (d.added) window.location.reload();
        } catch { window.alert('Network error.'); }
    }

    async function generate() {
        setBusy(true); setError(''); setNewCode('');
        try {
            const res = await fetch('/api/teacher/exams/' + exam.examId + '/tokens', {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ maxUses: Number(maxUses), expiresAt: expiresAt || null }), credentials: 'same-origin',
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) { setError(data.error || 'Failed.'); setBusy(false); return; }
            setNewCode(data.code); setBusy(false);
            setTimeout(() => window.location.reload(), 1500);
        } catch { setError('Network error.'); setBusy(false); }
    }
    async function deactivate(id) {
        if (!window.confirm('Deactivate this token?')) return;
        await fetch('/api/teacher/tokens/' + id + '/deactivate', { method: 'POST', headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        window.location.reload();
    }
    async function activate(id) {
        await fetch('/api/teacher/tokens/' + id + '/active', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ active: true }), credentials: 'same-origin' });
        window.location.reload();
    }
    async function regenerate(id) {
        if (!window.confirm('Generate a fresh code? The current code will stop working.')) return;
        const res = await fetch('/api/teacher/tokens/' + id + '/regenerate', { method: 'POST', headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const d = await res.json().catch(() => ({}));
        if (d.code) { setNewCode(d.code); setTimeout(() => window.location.reload(), 1500); } else { window.location.reload(); }
    }
    async function deleteToken(id) {
        if (!window.confirm('Delete this token permanently?')) return;
        await fetch('/api/teacher/tokens/' + id + '/delete', { method: 'POST', headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        window.location.reload();
    }
    async function deleteQuestion(id) {
        if (!window.confirm('Delete this question?')) return;
        await fetch('/api/teacher/questions/' + id + '/delete', { method: 'POST', headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        window.location.reload();
    }

    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div>
                    <h1>{exam.name}</h1>
                    <p><code>{exam.examId}</code> · {exam.subject || '—'} · {exam.durationMinutes} min · pass {exam.passingGrade}% · {exam.examMode}</p>
                </div>
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                    <button className="ghost-button" type="button" onClick={() => setShowSettings((v) => !v)}>
                        <Settings size={15} aria-hidden /> {showSettings ? 'Close settings' : 'Edit settings'}
                    </button>
                    <a className="ghost-button" href={`${examsBasePath}/${exam.examId}/live`}>Live monitor</a>
                    <a className="ghost-button" href={`${examsBasePath}/${exam.examId}/analysis`}>Item analysis</a>
                    <a className="ghost-button" href={`${examsBasePath}/${exam.examId}/audit`}>Answer audit</a>
                    <a className="ghost-button" href={examsBasePath}>← Back</a>
                </div>
            </header>

            {showSettings ? (
                <section className="admin-panel">
                    <div className="section-title-row"><div><h2>Exam settings</h2>
                        <p style={{ margin: 0, color: 'var(--muted)' }}>Edit timing, mode, availability window, shuffle, and instructions.</p></div></div>
                    <ExamSettingsPanel exam={exam} />
                </section>
            ) : null}

            <section className="admin-panel">
                <div className="section-title-row"><div><h2>Access tokens</h2>
                    <p style={{ margin: 0, color: 'var(--muted)' }}>Generate a code and read it out to your class.</p></div></div>
                <div style={{ display: 'flex', gap: 12, alignItems: 'center', marginTop: 12, flexWrap: 'wrap' }}>
                    <label style={{ fontSize: 13, color: 'var(--muted)' }}>Max uses
                        <input type="number" min="1" max="5000" value={maxUses} onChange={(e) => setMaxUses(e.target.value)} style={{ width: 90, marginLeft: 6 }} /></label>
                    <label style={{ fontSize: 13, color: 'var(--muted)' }}>Expires (optional)
                        <input type="datetime-local" value={expiresAt} onChange={(e) => setExpiresAt(e.target.value)} style={{ marginLeft: 6 }} /></label>
                    <button className="primary-button" type="button" onClick={generate} disabled={busy}><Plus size={16} aria-hidden /> {busy ? 'Generating…' : 'Generate token'}</button>
                </div>
                {newCode ? <p style={{ marginTop: 10 }}>New token: <strong style={{ fontSize: 22, letterSpacing: 3 }}>{newCode}</strong></p> : null}
                {error ? <p className="form-error" style={{ marginTop: 8 }}>{error}</p> : null}
                {tokens.length > 0 ? (
                    <table className="dashboard-table" style={{ marginTop: 14 }}>
                        <thead><tr><th>Code</th><th>Uses</th><th>Expires</th><th>Status</th><th>Created by</th><th>Actions</th></tr></thead>
                        <tbody>
                            {tokens.map((t) => (
                                <tr key={t.id}>
                                    <td><strong style={{ letterSpacing: 1 }}>{t.code}</strong></td>
                                    <td>{t.usedCount} / {t.maxUses}</td>
                                    <td>{t.expiresAt ? new Date(t.expiresAt).toLocaleString() : '—'}</td>
                                    <td>{t.active ? <span className="status-item neutral"><CheckCircle2 size={14} aria-hidden /> Active</span> : <span className="status-item warning"><CircleX size={14} aria-hidden /> Inactive</span>}</td>
                                    <td>{t.createdByName}</td>
                                    <td style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                                        {t.active
                                            ? <button className="ghost-button" type="button" onClick={() => deactivate(t.id)}>Deactivate</button>
                                            : <button className="ghost-button" type="button" onClick={() => activate(t.id)}>Activate</button>}
                                        <button className="ghost-button" type="button" onClick={() => regenerate(t.id)}>Regenerate</button>
                                        <button className="ghost-button danger" type="button" onClick={() => deleteToken(t.id)}><Trash2 size={14} aria-hidden /></button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                ) : <p style={{ color: 'var(--muted)', marginTop: 10 }}>No tokens yet.</p>}
            </section>

            <SebPanel examId={exam.examId} initialRequired={exam.sebRequired} initialKeySet={exam.sebKeySet} />

            <section className="admin-panel">
                <div className="section-title-row">
                    <div><h2>Questions ({questions.length})</h2></div>
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                        <button className="ghost-button" type="button" onClick={() => { setEditingId(null); setShowAdd(false); setShowBank((v) => !v); }}>
                            <Library size={15} aria-hidden /> {showBank ? 'Close bank' : 'Add from bank'}
                        </button>
                        <button className="ghost-button" type="button" onClick={autoFill}>
                            <Sparkles size={15} aria-hidden /> Auto-fill from bank
                        </button>
                        <button className="ghost-button" type="button" onClick={() => { setEditingId(null); setShowBank(false); setShowAdd((v) => !v); }}>
                            <Plus size={15} aria-hidden /> {showAdd ? 'Close' : 'Add question'}
                        </button>
                    </div>
                </div>
                {showBank ? <BankPicker examId={exam.examId} onClose={() => setShowBank(false)} /> : null}
                {showAdd ? <QuestionForm examId={exam.examId} onClose={() => setShowAdd(false)} /> : null}
                {editingId ? (
                    <QuestionForm examId={exam.examId} existing={questions.find((q) => q.id === editingId)} onClose={() => setEditingId(null)} />
                ) : null}
                {questions.length === 0 ? (
                    <p style={{ color: 'var(--muted)', margin: '10px 0 0' }}>No questions yet — add one above.</p>
                ) : (
                    <table className="dashboard-table" style={{ marginTop: 12 }}>
                        <thead><tr><th>#</th><th>Type</th><th>Topic</th><th>Prompt</th><th>Pts</th><th></th></tr></thead>
                        <tbody>
                            {questions.map((q) => (
                                <tr key={q.id}>
                                    <td>{q.position}</td>
                                    <td>{q.type}</td>
                                    <td>{q.topic}</td>
                                    <td style={{ maxWidth: 360, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{q.prompt}</td>
                                    <td>{q.points}</td>
                                    <td style={{ display: 'flex', gap: 6 }}>
                                        <button className="ghost-button" type="button" title="Edit" onClick={() => { setShowAdd(false); setEditingId(q.id); }}><Pencil size={14} aria-hidden /></button>
                                        <button className="ghost-button danger" type="button" title="Delete" onClick={() => deleteQuestion(q.id)}><Trash2 size={14} aria-hidden /></button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>
        </AppLayout>
    );
}
