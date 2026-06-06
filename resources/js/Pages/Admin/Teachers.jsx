import { CheckCircle2, CircleX, KeyRound, SlidersHorizontal, UserCog, UserPlus } from 'lucide-react';
import { useEffect, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

function CapabilitiesEditor({ teacher, onClose }) {
    const [caps, setCaps] = useState(null);
    const [registry, setRegistry] = useState({});
    const [busy, setBusy] = useState(false);
    const [msg, setMsg] = useState('');

    useEffect(() => {
        (async () => {
            const res = await fetch('/api/admin/teachers/' + teacher.userId + '/capabilities', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const d = await res.json().catch(() => ({}));
            setCaps(d.capabilities || {});
            setRegistry(d.registry || {});
        })();
    }, [teacher.userId]);

    async function save() {
        setBusy(true); setMsg('');
        try {
            const res = await fetch('/api/admin/teachers/' + teacher.userId + '/capabilities', {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ capabilities: caps }), credentials: 'same-origin',
            });
            const d = await res.json().catch(() => ({}));
            if (!res.ok) { setMsg(d.error || 'Failed.'); setBusy(false); return; }
            setMsg('Saved.'); setBusy(false);
        } catch { setMsg('Network error.'); setBusy(false); }
    }

    if (!caps) return <p style={{ color: 'var(--muted)' }}>Loading capabilities…</p>;
    return (
        <div style={{ padding: 12, border: '1px solid #6366f1', borderRadius: 10, margin: '8px 0' }}>
            <div className="section-title-row"><div><h3 style={{ margin: 0 }}>Capabilities — {teacher.fullName}</h3>
                <p style={{ margin: 0, color: 'var(--muted)' }}>Unchecked features are blocked for this teacher.</p></div>
                <button className="ghost-button" type="button" onClick={onClose}>Close</button>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12, marginTop: 10 }}>
                {Object.entries(registry).map(([section, items]) => (
                    <div key={section}>
                        <strong style={{ fontSize: 13 }}>{section}</strong>
                        {Object.entries(items).map(([key, label]) => (
                            <label key={key} style={{ display: 'flex', gap: 8, alignItems: 'center', fontSize: 13, padding: '3px 0' }}>
                                <input type="checkbox" checked={!!caps[key]} onChange={(e) => setCaps((c) => ({ ...c, [key]: e.target.checked }))} />
                                {label}
                            </label>
                        ))}
                    </div>
                ))}
            </div>
            <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginTop: 10 }}>
                <button className="primary-button" type="button" onClick={save} disabled={busy}>{busy ? 'Saving…' : 'Save capabilities'}</button>
                {msg ? <span style={{ color: 'var(--muted)' }}>{msg}</span> : null}
            </div>
        </div>
    );
}

export default function Teachers({ teachers }) {
    const [showAdd, setShowAdd] = useState(false);
    const [add, setAdd] = useState({ username: '', fullName: '', subject: '', password: '' });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [msg, setMsg] = useState('');
    const [capsFor, setCapsFor] = useState(null);

    async function create(e) {
        e.preventDefault();
        setBusy(true); setError(''); setMsg('');
        try {
            const res = await fetch('/api/admin/teachers', {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(add), credentials: 'same-origin',
            });
            const d = await res.json().catch(() => ({}));
            if (!res.ok) { setError(d.error || 'Failed.'); setBusy(false); return; }
            window.location.reload();
        } catch { setError('Network error.'); setBusy(false); }
    }
    async function toggle(t) {
        await fetch('/api/admin/teachers/' + t.userId, {
            method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ active: !t.active }), credentials: 'same-origin',
        });
        window.location.reload();
    }
    async function reset(t) {
        const pw = window.prompt(`New password for ${t.fullName} (6–64 chars):`);
        if (!pw) return;
        const res = await fetch('/api/admin/teachers/' + t.userId, {
            method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ password: pw }), credentials: 'same-origin',
        });
        const d = await res.json().catch(() => ({}));
        if (!res.ok) { setError(d.error || 'Failed.'); return; }
        setMsg(`Password reset for ${t.fullName}.`);
    }
    async function impersonate(t) {
        if (!t.active) { setError('Cannot impersonate a deactivated teacher.'); return; }
        if (!window.confirm(`Impersonate ${t.fullName}? You'll act as this teacher until you return to admin.`)) return;
        const res = await fetch('/api/admin/impersonate/' + t.userId, { method: 'POST', headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const d = await res.json().catch(() => ({}));
        if (!res.ok) { setError(d.error || 'Failed.'); return; }
        window.location.href = d.redirect || '/teacher';
    }

    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div><h1>Teachers</h1><p>{teachers.length} teacher account{teachers.length === 1 ? '' : 's'}.</p></div>
                <button className="primary-button" type="button" onClick={() => setShowAdd((v) => !v)}>
                    <UserPlus size={16} aria-hidden /> {showAdd ? 'Close' : 'Add teacher'}
                </button>
            </header>
            {msg ? <p className="form-success">{msg}</p> : null}
            {error ? <p className="form-error">{error}</p> : null}
            {showAdd ? (
                <section className="admin-panel">
                    <form onSubmit={create} style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end' }}>
                        <label>Full name<input value={add.fullName} onChange={(e) => setAdd({ ...add, fullName: e.target.value })} required /></label>
                        <label>Username<input value={add.username} onChange={(e) => setAdd({ ...add, username: e.target.value })} required /></label>
                        <label>Subject<input value={add.subject} onChange={(e) => setAdd({ ...add, subject: e.target.value })} /></label>
                        <label>Password<input value={add.password} onChange={(e) => setAdd({ ...add, password: e.target.value })} required /></label>
                        <button className="primary-button" type="submit" disabled={busy}>{busy ? 'Adding…' : 'Create'}</button>
                    </form>
                </section>
            ) : null}
            <section className="admin-panel">
                <table className="dashboard-table">
                    <thead><tr><th>Teacher</th><th>Subject</th><th>Exams</th><th>Students</th><th>Bank&nbsp;Qs</th><th>Submissions</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        {teachers.map((t) => (
                            <>
                                <tr key={t.userId}>
                                    <td><strong>{t.fullName}</strong><div style={{ color: 'var(--muted)', fontSize: '0.85rem' }}>{t.username}</div></td>
                                    <td>{t.subject || '—'}</td>
                                    <td>{t.examCount}</td><td>{t.studentCount}</td><td>{t.bankQuestionCount}</td><td>{t.submissionCount}</td>
                                    <td>{t.active ? <span className="status-item neutral"><CheckCircle2 size={14} aria-hidden /> Active</span> : <span className="status-item warning"><CircleX size={14} aria-hidden /> Inactive</span>}</td>
                                    <td>
                                        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                                            <button className="ghost-button" type="button" onClick={() => reset(t)}><KeyRound size={14} aria-hidden /> Reset</button>
                                            <button className="ghost-button" type="button" onClick={() => toggle(t)}>{t.active ? 'Deactivate' : 'Activate'}</button>
                                            <button className="ghost-button" type="button" onClick={() => impersonate(t)}><UserCog size={14} aria-hidden /> Impersonate</button>
                                            <button className="ghost-button" type="button" onClick={() => setCapsFor(capsFor === t.userId ? null : t.userId)}><SlidersHorizontal size={14} aria-hidden /> Capabilities</button>
                                        </div>
                                    </td>
                                </tr>
                                {capsFor === t.userId ? (
                                    <tr key={t.userId + '-caps'}><td colSpan={8}><CapabilitiesEditor teacher={t} onClose={() => setCapsFor(null)} /></td></tr>
                                ) : null}
                            </>
                        ))}
                    </tbody>
                </table>
            </section>
        </AppLayout>
    );
}
