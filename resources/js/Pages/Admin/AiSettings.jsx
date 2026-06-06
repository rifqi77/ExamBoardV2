import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

export default function AiSettings({ settings, keyStatus, providers }) {
    const [s, setS] = useState(settings);
    const [ks, setKs] = useState(keyStatus);
    const [keys, setKeys] = useState({ gemini: '', claude: '', openai: '' });
    const [msg, setMsg] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const models = providers.models[s.textProvider] || [];

    async function saveSettings() {
        setBusy(true); setError(''); setMsg('');
        const res = await fetch('/api/admin/ai-settings', { method: 'PUT', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(s), credentials: 'same-origin' });
        const d = await res.json().catch(() => ({}));
        setBusy(false);
        if (!res.ok) { setError(d.error || 'Failed.'); return; }
        setS(d.settings); setKs(d.keyStatus); setMsg('Settings saved.');
    }
    async function saveKeys() {
        setBusy(true); setError(''); setMsg('');
        const patch = {};
        for (const p of ['gemini', 'claude', 'openai']) if (keys[p] !== '') patch[p] = keys[p];
        const res = await fetch('/api/admin/ai-settings', { method: 'PATCH', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ keys: patch }), credentials: 'same-origin' });
        const d = await res.json().catch(() => ({}));
        setBusy(false);
        if (!res.ok) { setError(d.error || 'Failed.'); return; }
        setKs(d.keyStatus); setKeys({ gemini: '', claude: '', openai: '' }); setMsg('Keys saved.');
    }

    return (
        <AppLayout>
            <header className="teacher-page-header"><div><h1>AI settings</h1><p>Choose the provider + model for question generation, and store API keys (encrypted at rest).</p></div></header>
            {msg ? <p className="form-success">{msg}</p> : null}
            {error ? <p className="form-error">{error}</p> : null}

            <section className="admin-panel" style={{ maxWidth: 620 }}>
                <h2>Provider</h2>
                <label style={{ display: 'block', marginTop: 8 }}>Text provider
                    <select value={s.textProvider} onChange={(e) => setS({ ...s, textProvider: e.target.value, textModel: (providers.models[e.target.value] || [''])[0] })} style={{ display: 'block', marginTop: 4 }}>
                        {providers.text.map((p) => <option key={p} value={p}>{providers.labels[p]}</option>)}
                    </select>
                </label>
                <label style={{ display: 'block', marginTop: 10 }}>Model
                    <select value={s.textModel} onChange={(e) => setS({ ...s, textModel: e.target.value })} style={{ display: 'block', marginTop: 4 }}>
                        {models.map((m) => <option key={m} value={m}>{m}</option>)}
                    </select>
                </label>
                <label style={{ display: 'block', marginTop: 10 }}>Temperature
                    <input type="number" min="0" max="2" step="0.1" value={s.temperature} onChange={(e) => setS({ ...s, temperature: Number(e.target.value) })} style={{ width: 90, marginLeft: 8 }} />
                </label>
                <div style={{ marginTop: 14 }}><button className="primary-button" type="button" onClick={saveSettings} disabled={busy}>Save settings</button></div>
            </section>

            <section className="admin-panel" style={{ maxWidth: 620 }}>
                <h2>API keys</h2>
                <p style={{ color: 'var(--muted)' }}>Stored encrypted (AES). Leave blank to keep the current key; keys are never shown back.</p>
                {['gemini', 'claude', 'openai'].map((p) => (
                    <label key={p} style={{ display: 'block', marginTop: 10, textTransform: 'capitalize' }}>
                        {p} {ks[p] ? <span className="status-item neutral">set</span> : <span className="status-item warning">not set</span>}
                        <input type="password" value={keys[p]} onChange={(e) => setKeys({ ...keys, [p]: e.target.value })} placeholder={ks[p] ? '•••• (already set)' : 'paste key'} style={{ display: 'block', width: '100%', marginTop: 4, boxSizing: 'border-box' }} />
                    </label>
                ))}
                <div style={{ marginTop: 14 }}><button className="primary-button" type="button" onClick={saveKeys} disabled={busy}>Save keys</button></div>
            </section>
        </AppLayout>
    );
}
