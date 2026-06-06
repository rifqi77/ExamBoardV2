import { KeyRound, Trash2, Upload, UserCheck, UserPlus, UserX } from 'lucide-react';
import { useRef, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import { parseClassesFromExcel } from '../../lib/excelParser';

export default function Students({ groups }) {
    const total = groups.reduce((s, g) => s + g.students.length, 0);
    const [selected, setSelected] = useState(new Set());
    const [busy, setBusy] = useState(null);
    const [msg, setMsg] = useState('');
    const [error, setError] = useState('');
    const [showAdd, setShowAdd] = useState(false);
    const [add, setAdd] = useState({ username: '', fullName: '', password: '' });
    const [creds, setCreds] = useState(null);
    const fileRef = useRef(null);
    const [preview, setPreview] = useState(null);
    const [importYear, setImportYear] = useState('');
    const [importBusy, setImportBusy] = useState(false);

    async function onFile(e) {
        const file = e.target.files?.[0];
        e.target.value = '';
        if (!file) return;
        setError('');
        setMsg('');
        try {
            const classes = await parseClassesFromExcel(file);
            if (classes.length === 0) { setError('No classes/students found in that file.'); return; }
            setPreview({ fileName: file.name, classes });
        } catch {
            setError('Could not read that file.');
        }
    }
    async function confirmImport() {
        if (!preview) return;
        setImportBusy(true);
        setError('');
        setMsg('');
        try {
            const res = await fetch('/api/teacher/classes/import', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ fileName: preview.fileName, academicYear: importYear || undefined, classes: preview.classes }),
                credentials: 'same-origin',
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) { setError(data.error || 'Import failed.'); setImportBusy(false); return; }
            setPreview(null);
            setImportBusy(false);
            setMsg(`Imported ${data.studentsCreated} student(s) · ${data.classesCreated} class(es) created, ${data.classesUpdated} updated${data.studentsSkipped?.length ? ` · ${data.studentsSkipped.length} skipped` : ''}.`);
            if (data.createdStudents?.length) {
                setCreds(data.createdStudents.map((c) => ({ userId: c.username, fullName: c.fullName, username: c.username, password: c.password })));
            }
        } catch {
            setError('Network error.');
            setImportBusy(false);
        }
    }

    const sel = new Set(selected);
    function toggle(id) {
        setSelected((p) => {
            const n = new Set(p);
            n.has(id) ? n.delete(id) : n.add(id);
            return n;
        });
    }
    function setClass(g, on) {
        setSelected((p) => {
            const n = new Set(p);
            g.students.forEach((s) => (on ? n.add(s.userId) : n.delete(s.userId)));
            return n;
        });
    }
    const classAll = (g) => g.students.length > 0 && g.students.every((s) => sel.has(s.userId));

    async function addStudent(e) {
        e.preventDefault();
        setBusy('add');
        setError('');
        setMsg('');
        try {
            const res = await fetch('/api/teacher/students', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(add),
                credentials: 'same-origin',
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) { setError(data.error || 'Failed.'); setBusy(null); return; }
            window.location.reload();
        } catch { setError('Network error.'); setBusy(null); }
    }

    async function bulk(action) {
        const ids = [...sel];
        if (ids.length === 0) return;
        if (action === 'delete' && !window.confirm(`Permanently delete ${ids.length} student(s) and all their submissions?`)) return;
        setBusy(action);
        setError('');
        setMsg('');
        setCreds(null);
        try {
            const res = await fetch('/api/teacher/students/bulk', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ action, userIds: ids }),
                credentials: 'same-origin',
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) { setError(data.error || 'Failed.'); setBusy(null); return; }
            const n = data.updated ?? data.deleted ?? data.reset ?? ids.length;
            setMsg(`${n} student(s) ${action === 'delete' ? 'deleted' : action === 'reset' ? 'reset' : action === 'activate' ? 'reactivated' : 'deactivated'}${data.skipped ? ` · ${data.skipped} skipped` : ''}.`);
            setSelected(new Set());
            if (action === 'reset' && data.credentials) {
                setCreds(data.credentials);
                setBusy(null);
            } else {
                window.location.reload();
            }
        } catch { setError('Network error.'); setBusy(null); }
    }

    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div>
                    <h1>Students</h1>
                    <p>{total} student{total === 1 ? '' : 's'} across {groups.length} class{groups.length === 1 ? '' : 'es'}. Passwords are shown once at create/import — use <strong>Reset passwords</strong> to issue a new one.</p>
                </div>
                <div style={{ display: 'flex', gap: 8 }}>
                    <input ref={fileRef} type="file" accept=".xlsx" hidden onChange={onFile} />
                    <button className="ghost-button" type="button" onClick={() => fileRef.current?.click()}>
                        <Upload size={16} aria-hidden /> Import roster (.xlsx)
                    </button>
                    <button className="primary-button" type="button" onClick={() => setShowAdd((v) => !v)}>
                        <UserPlus size={16} aria-hidden /> {showAdd ? 'Close' : 'Add student'}
                    </button>
                </div>
            </header>

            {msg ? <p className="form-success">{msg}</p> : null}
            {error ? <p className="form-error">{error}</p> : null}

            {showAdd ? (
                <section className="admin-panel">
                    <form onSubmit={addStudent} style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end' }}>
                        <label>Full name<input value={add.fullName} onChange={(e) => setAdd({ ...add, fullName: e.target.value })} required /></label>
                        <label>Username<input value={add.username} onChange={(e) => setAdd({ ...add, username: e.target.value })} required /></label>
                        <label>Password<input value={add.password} onChange={(e) => setAdd({ ...add, password: e.target.value })} required /></label>
                        <button className="primary-button" type="submit" disabled={busy === 'add'}>{busy === 'add' ? 'Adding…' : 'Create'}</button>
                    </form>
                </section>
            ) : null}

            {preview ? (
                <section className="admin-panel">
                    <div className="section-title-row"><div>
                        <h2>Preview: {preview.fileName}</h2>
                        <p style={{ margin: 0, color: 'var(--muted)' }}>
                            {preview.classes.length} class(es), {preview.classes.reduce((s, c) => s + c.students.length, 0)} student(s). Blank usernames/passwords are auto-generated.
                        </p>
                    </div></div>
                    <ul className="import-preview-list">
                        {preview.classes.map((c) => (<li key={c.name}><strong>{c.name}</strong><span>{c.students.length} student(s)</span></li>))}
                    </ul>
                    <label style={{ display: 'block', marginTop: 10 }}>Academic year
                        <input value={importYear} onChange={(e) => setImportYear(e.target.value)} placeholder="2025/2026" style={{ maxWidth: 160, marginLeft: 6 }} />
                    </label>
                    <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
                        <button className="primary-button" type="button" onClick={confirmImport} disabled={importBusy}>{importBusy ? 'Importing…' : 'Confirm import'}</button>
                        <button className="ghost-button" type="button" onClick={() => setPreview(null)}>Cancel</button>
                    </div>
                </section>
            ) : null}

            {creds ? (
                <section className="admin-panel credentials-panel">
                    <div className="section-title-row"><div><h2>New passwords</h2><p style={{ margin: 0, color: 'var(--muted)' }}>Save these now — share with students. <a href="" onClick={(e) => { e.preventDefault(); window.location.reload(); }}>Refresh list</a></p></div></div>
                    <table className="dashboard-table"><thead><tr><th>Name</th><th>Username</th><th>New password</th></tr></thead>
                        <tbody>{creds.map((c) => (<tr key={c.userId}><td>{c.fullName}</td><td><code>{c.username}</code></td><td><code>{c.password}</code></td></tr>))}</tbody>
                    </table>
                </section>
            ) : null}

            {sel.size > 0 ? (
                <section className="admin-panel" style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap', position: 'sticky', top: 8, zIndex: 5 }}>
                    <strong>{sel.size} selected</strong>
                    <button className="ghost-button" type="button" onClick={() => bulk('reset')} disabled={busy}><KeyRound size={15} aria-hidden /> Reset passwords</button>
                    <button className="ghost-button" type="button" onClick={() => bulk('deactivate')} disabled={busy}><UserX size={15} aria-hidden /> Deactivate</button>
                    <button className="ghost-button" type="button" onClick={() => bulk('activate')} disabled={busy}><UserCheck size={15} aria-hidden /> Reactivate</button>
                    <button className="ghost-button danger" type="button" onClick={() => bulk('delete')} disabled={busy}><Trash2 size={15} aria-hidden /> Delete</button>
                    <button className="inline-link-button" type="button" onClick={() => setSelected(new Set())} style={{ marginLeft: 'auto' }}>Clear</button>
                </section>
            ) : null}

            {groups.length === 0 ? (
                <section className="admin-panel"><p style={{ color: 'var(--muted)', margin: 0 }}>No students yet.</p></section>
            ) : (
                groups.map((g, i) => (
                    <section className="admin-panel" key={i}>
                        <div className="section-title-row" style={{ alignItems: 'center' }}>
                            <div>
                                <h2>{g.className}</h2>
                                <p style={{ margin: 0, color: 'var(--muted)' }}>{g.academicYear ? `${g.academicYear} · ` : ''}{g.students.length} student{g.students.length === 1 ? '' : 's'}</p>
                            </div>
                            <label style={{ display: 'flex', gap: 6, alignItems: 'center', fontSize: 13, color: 'var(--muted)' }}>
                                <input type="checkbox" checked={classAll(g)} onChange={(e) => setClass(g, e.target.checked)} /> Select all
                            </label>
                        </div>
                        <table className="dashboard-table">
                            <thead><tr><th style={{ width: 32 }}></th><th>Student</th><th>Status</th><th>Submissions</th></tr></thead>
                            <tbody>
                                {g.students.map((s) => (
                                    <tr key={s.userId}>
                                        <td><input type="checkbox" checked={sel.has(s.userId)} onChange={() => toggle(s.userId)} /></td>
                                        <td><strong>{s.fullName}</strong><div style={{ color: 'var(--muted)', fontSize: '0.85rem' }}>{s.username}</div></td>
                                        <td>{s.active ? <span className="status-item neutral"><UserCheck size={14} aria-hidden /> Active</span> : <span className="status-item warning"><UserX size={14} aria-hidden /> Inactive</span>}</td>
                                        <td>{s.submissions}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>
                ))
            )}
        </AppLayout>
    );
}
