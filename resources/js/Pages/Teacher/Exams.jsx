import { CheckCircle2, CircleX, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

export default function Exams({ exams, scope, examsBasePath }) {
    const fileRef = useRef(null);
    const [importing, setImporting] = useState(false);
    const [importMsg, setImportMsg] = useState('');
    const [importErr, setImportErr] = useState('');

    async function onImportFile(e) {
        const file = e.target.files?.[0];
        e.target.value = '';
        if (!file) return;
        setImportErr(''); setImportMsg(''); setImporting(true);
        try {
            const text = await file.text();
            let pkg;
            try { pkg = JSON.parse(text); } catch { setImportErr('That file is not valid JSON.'); setImporting(false); return; }
            const res = await fetch('/api/teacher/exams/import', {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(pkg), credentials: 'same-origin',
            });
            const d = await res.json().catch(() => ({}));
            if (!res.ok) { setImportErr(d.error || 'Import failed.'); setImporting(false); return; }
            setImportMsg(`Imported "${d.examId}" — ${d.questionsCreated} question(s)${d.mediaCreated ? `, ${d.mediaCreated} media` : ''}${d.warnings?.length ? ` · ${d.warnings.length} warning(s)` : ''}.`);
            setImporting(false);
            setTimeout(() => window.location.reload(), 1400);
        } catch { setImportErr('Network error.'); setImporting(false); }
    }

    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div>
                    <h1>Exams</h1>
                    <p>{exams.length} exam{exams.length === 1 ? '' : 's'} — {scope}.</p>
                </div>
                <div style={{ display: 'flex', gap: 8 }}>
                    <input ref={fileRef} type="file" accept=".json,application/json" hidden onChange={onImportFile} />
                    <button className="ghost-button" type="button" onClick={() => fileRef.current?.click()} disabled={importing}>
                        <Upload size={16} aria-hidden /> {importing ? 'Importing…' : 'Import package (.json)'}
                    </button>
                    <a className="primary-button" href={`${examsBasePath}/new`}>+ Create exam</a>
                </div>
            </header>

            {importMsg ? <p className="form-success">{importMsg}</p> : null}
            {importErr ? <p className="form-error">{importErr}</p> : null}

            <section className="admin-panel">
                {exams.length === 0 ? (
                    <p style={{ color: 'var(--muted)', margin: 0 }}>No exams yet.</p>
                ) : (
                    <table className="dashboard-table">
                        <thead>
                            <tr>
                                <th>Exam</th><th>Teacher</th><th>Duration</th><th>Passing</th>
                                <th>Active tokens</th><th>Submissions</th><th>Avg</th><th>Status</th><th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {exams.map((e) => (
                                <tr key={e.examId}>
                                    <td>
                                        <strong>{e.name}</strong>
                                        <div style={{ color: 'var(--muted)', fontSize: '0.85rem' }}><code>{e.examId}</code></div>
                                    </td>
                                    <td>{e.owner}</td>
                                    <td>{e.durationMinutes} min</td>
                                    <td>{e.passingGrade}%</td>
                                    <td>{e.activeTokens}</td>
                                    <td>{e.submissions}</td>
                                    <td>{e.avg === null ? '—' : `${e.avg}%`}</td>
                                    <td>
                                        {e.active ? (
                                            <span className="status-item neutral"><CheckCircle2 size={14} aria-hidden /> Active</span>
                                        ) : (
                                            <span className="status-item warning"><CircleX size={14} aria-hidden /> Inactive</span>
                                        )}
                                    </td>
                                    <td><a className="ghost-button" href={`${examsBasePath}/${e.examId}`}>Manage</a></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>
        </AppLayout>
    );
}
