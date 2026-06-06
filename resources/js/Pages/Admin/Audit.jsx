import { useMemo, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

const ACTION_LABELS = {
    'grade.set': 'Essay graded',
    'grade.bulk': 'AI grades applied',
    'submission.delete': 'Submission deleted',
    'submission.bulk_delete': 'Submissions bulk-deleted',
    'exam.finalize_drafts': 'Lost submissions recovered',
    'session.reset': 'Session reset',
    'token.generate': 'Token generated',
    'token.regenerate': 'Token regenerated',
    'token.deactivate': 'Token deactivated',
    'token.set_active': 'Token activated/deactivated',
    'token.delete': 'Token deleted',
    'exam.seb': 'SEB setting changed',
    'impersonate.start': 'Impersonation started',
    'impersonate.stop': 'Impersonation stopped',
    'teacher.create': 'Teacher created',
    'teacher.update': 'Teacher updated',
    'teacher.capabilities': 'Capabilities changed',
    'student.create': 'Student created',
    'student.bulk': 'Student bulk action',
    'ai.settings': 'AI settings changed',
    'ai.keys': 'AI keys changed',
};

function actionTone(action) {
    if (action.includes('delete')) return 'warning';
    if (action.startsWith('impersonate')) return 'warning';
    return 'neutral';
}

export default function Audit({ entries, actions }) {
    const [action, setAction] = useState('');
    const [q, setQ] = useState('');

    const filtered = useMemo(() => entries.filter((e) => {
        if (action && e.action !== action) return false;
        if (q) {
            const s = q.toLowerCase();
            return (e.summary || '').toLowerCase().includes(s)
                || (e.actorName || '').toLowerCase().includes(s)
                || (e.action || '').toLowerCase().includes(s);
        }
        return true;
    }), [entries, action, q]);

    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div>
                    <h1>Audit log</h1>
                    <p>Integrity-sensitive actions (grades, deletions, impersonation, tokens, accounts). Showing the latest {entries.length}.</p>
                </div>
            </header>

            <section className="admin-panel">
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                    <select value={action} onChange={(e) => setAction(e.target.value)}>
                        <option value="">All actions</option>
                        {actions.map((a) => <option key={a} value={a}>{ACTION_LABELS[a] || a}</option>)}
                    </select>
                    <input placeholder="Search actor / summary…" value={q} onChange={(e) => setQ(e.target.value)} style={{ minWidth: 240 }} />
                    <span style={{ color: 'var(--muted)', fontSize: 13 }}>{filtered.length} shown</span>
                </div>

                {filtered.length === 0 ? (
                    <p style={{ color: 'var(--muted)', marginTop: 12 }}>No matching events yet.</p>
                ) : (
                    <table className="dashboard-table" style={{ marginTop: 12 }}>
                        <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
                        <tbody>
                            {filtered.map((e) => (
                                <tr key={e.id}>
                                    <td style={{ whiteSpace: 'nowrap' }}>{e.at ? new Date(e.at).toLocaleString() : '—'}</td>
                                    <td>
                                        <strong>{e.actorName || '—'}</strong>
                                        <div style={{ color: 'var(--muted)', fontSize: '0.8rem' }}>
                                            {e.actorRole}{e.impersonated ? ' · (impersonating)' : ''}
                                        </div>
                                    </td>
                                    <td><span className={`status-item ${actionTone(e.action)}`}>{ACTION_LABELS[e.action] || e.action}</span></td>
                                    <td style={{ maxWidth: 380 }}>{e.summary || '—'}</td>
                                    <td style={{ color: 'var(--muted)', fontSize: '0.8rem' }}>{e.ip || '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>
        </AppLayout>
    );
}
