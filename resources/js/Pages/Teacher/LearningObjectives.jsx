import { Trash2, Upload } from 'lucide-react';
import { useMemo, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import MarkdownContent from '../../Components/MarkdownContent';
import { parseLearningObjectives } from '../../lib/excelParser';

const CURRICULUM_LABELS = {
    kurikulum_merdeka: 'Kurikulum Merdeka',
    as_a_level: 'Cambridge AS/A Level',
    ib: 'IB',
    olympiad: 'Olympiad',
};

function UploadPanel() {
    const [preview, setPreview] = useState(null);
    const [fileName, setFileName] = useState('');
    const [curriculum, setCurriculum] = useState('as_a_level');
    const [busy, setBusy] = useState(false);
    const [msg, setMsg] = useState('');

    async function onFile(e) {
        const file = e.target.files?.[0];
        if (!file) return;
        setFileName(file.name); setMsg('');
        try {
            const rows = await parseLearningObjectives(file);
            setPreview(rows);
            setMsg(`Parsed ${rows.length} row(s). Review and import.`);
        } catch { setMsg('Could not parse that .xlsx.'); }
    }
    async function importRows() {
        if (!preview || !preview.length) return;
        setBusy(true); setMsg('');
        try {
            const res = await fetch('/api/teacher/learning-objectives', {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ rows: preview, curriculum, sourceFileName: fileName }), credentials: 'same-origin',
            });
            const d = await res.json().catch(() => ({}));
            if (!res.ok) { setMsg(d.error || 'Import failed.'); setBusy(false); return; }
            setMsg(`Imported ${d.imported}, skipped ${d.skipped}.`);
            setTimeout(() => window.location.reload(), 1000);
        } catch { setMsg('Network error.'); setBusy(false); }
    }

    return (
        <section className="admin-panel">
            <div className="section-title-row"><div><h2>Import (.xlsx)</h2>
                <p style={{ margin: 0, color: 'var(--muted)' }}>Columns: Topic, Subtopic, Objective, Subject, Curriculum, Language (header row auto-detected; merged topic cells inherit down).</p></div></div>
            <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
                <label style={{ fontSize: 13, color: 'var(--muted)' }}>Curriculum
                    <select value={curriculum} onChange={(e) => setCurriculum(e.target.value)} style={{ marginLeft: 6 }}>
                        {Object.entries(CURRICULUM_LABELS).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
                    </select>
                </label>
                <input type="file" accept=".xlsx" onChange={onFile} />
            </div>
            {preview ? (
                <div style={{ marginTop: 10 }}>
                    <button className="primary-button" type="button" onClick={importRows} disabled={busy || preview.length === 0}>
                        <Upload size={15} aria-hidden /> Import {preview.length} row(s)
                    </button>
                </div>
            ) : null}
            {msg ? <p style={{ color: 'var(--muted)', marginTop: 8 }}>{msg}</p> : null}
        </section>
    );
}

export default function LearningObjectives({ objectives, curricula }) {
    const [curriculum, setCurriculum] = useState('');
    const [q, setQ] = useState('');

    async function del(id) {
        if (!window.confirm('Delete this objective?')) return;
        await fetch('/api/teacher/learning-objectives/' + id + '/delete', { method: 'POST', headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        window.location.reload();
    }

    const filtered = useMemo(() => objectives.filter((o) => {
        if (curriculum && (o.curriculum || '') !== curriculum) return false;
        if (q) {
            const s = q.toLowerCase();
            return (o.topic || '').toLowerCase().includes(s) || (o.subtopic || '').toLowerCase().includes(s) || (o.text || '').toLowerCase().includes(s);
        }
        return true;
    }), [objectives, curriculum, q]);

    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div>
                    <h1>Learning objectives</h1>
                    <p>{objectives.length} objective(s) across {curricula.length || 1} curriculum/a. Feeds the AI topic picker and exam auto-fill order.</p>
                </div>
            </header>

            <section className="admin-panel">
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                    <select value={curriculum} onChange={(e) => setCurriculum(e.target.value)}>
                        <option value="">All curricula</option>
                        {curricula.map((c) => <option key={c} value={c}>{CURRICULUM_LABELS[c] || c}</option>)}
                    </select>
                    <input placeholder="Search topic/objective…" value={q} onChange={(e) => setQ(e.target.value)} style={{ minWidth: 220 }} />
                    <span style={{ color: 'var(--muted)', fontSize: 13 }}>{filtered.length} shown</span>
                </div>
                {filtered.length === 0 ? (
                    <p style={{ color: 'var(--muted)', marginTop: 12 }}>No objectives. Import an .xlsx below. (Teachers see only their own uploads.)</p>
                ) : (
                    <table className="dashboard-table" style={{ marginTop: 12 }}>
                        <thead><tr><th>Curriculum</th><th>Subject</th><th>Topic</th><th>Subtopic</th><th>Objective</th><th></th></tr></thead>
                        <tbody>
                            {filtered.map((o) => (
                                <tr key={o.id}>
                                    <td>{CURRICULUM_LABELS[o.curriculum] || o.curriculum || '—'}</td>
                                    <td>{o.subject || '—'}</td>
                                    <td>{o.topic}</td>
                                    <td>{o.subtopic || '—'}</td>
                                    <td style={{ maxWidth: 360 }}><MarkdownContent inline>{o.text}</MarkdownContent></td>
                                    <td><button className="ghost-button danger" type="button" onClick={() => del(o.id)}><Trash2 size={14} aria-hidden /></button></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>

            <UploadPanel />
        </AppLayout>
    );
}
