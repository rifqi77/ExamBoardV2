import { RefreshCw } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import { t } from '../../lib/i18n';

const DOT = { ok: '#16a34a', warn: '#d97706', down: '#dc2626' };
const LABEL = { ok: 'OK', warn: 'Check', down: 'Down' };

export default function System({ initial }) {
    const [snap, setSnap] = useState(initial);
    const [busy, setBusy] = useState(false);

    async function refresh() {
        setBusy(true);
        try {
            const res = await fetch('/api/admin/system-health', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            setSnap(await res.json());
        } catch { /* keep previous snapshot */ }
        setBusy(false);
    }

    const checks = snap?.checks || [];

    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div>
                    <h1>{t('System health')}</h1>
                    <p>Live status of the things that keep exams running. For the full pre-flight check run <code>php artisan app:doctor</code>.</p>
                </div>
                <div>
                    <button className="ghost-button" type="button" onClick={refresh} disabled={busy}>
                        <RefreshCw size={15} aria-hidden /> {busy ? 'Refreshing…' : 'Refresh'}
                    </button>
                </div>
            </header>

            <section className="admin-panel">
                <table className="dashboard-table">
                    <thead><tr><th>Status</th><th>Component</th><th>Detail</th></tr></thead>
                    <tbody>
                        {checks.map((c) => (
                            <tr key={c.key}>
                                <td>
                                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                                        <span style={{ width: 10, height: 10, borderRadius: '50%', background: DOT[c.status] || '#999' }} />
                                        {LABEL[c.status] || c.status}
                                    </span>
                                </td>
                                <td><strong>{c.label}</strong></td>
                                <td style={{ color: 'var(--muted)' }}>{c.detail}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <p style={{ color: 'var(--muted)', marginTop: 10, fontSize: 13 }}>
                    As of {snap?.generatedAt ? new Date(snap.generatedAt).toLocaleString() : '—'}
                </p>
            </section>
        </AppLayout>
    );
}
