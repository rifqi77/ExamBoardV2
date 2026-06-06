import { LockKeyhole, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { getLang, setLang, t } from '../lib/i18n';

export default function Login() {
    const [username, setUsername] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);

    async function onSubmit(e) {
        e.preventDefault();
        setLoading(true);
        setError('');
        try {
            const res = await fetch('/api/auth/login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ username, password }),
                credentials: 'same-origin',
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                setError(data.error || 'Login failed.');
                setLoading(false);
                return;
            }
            const role = data?.user?.role;
            window.location.href =
                role === 'admin' ? '/admin' : role === 'teacher' ? '/teacher' : '/student';
        } catch {
            setError('Network error.');
            setLoading(false);
        }
    }

    return (
        <main className="auth-shell auth-shell--centered">
            <section className="auth-panel">
                <div className="brand-lockup">
                    <div className="brand-mark">
                        <ShieldCheck size={24} aria-hidden />
                    </div>
                    <div>
                        <h1>{t('Exam Dashboard')}</h1>
                        <p>{t('Sign in with your account to continue.')}</p>
                    </div>
                </div>

                <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, fontSize: 12 }}>
                    <button type="button" className="inline-link-button" onClick={() => setLang('id')} style={{ fontWeight: getLang() === 'id' ? 700 : 400 }}>ID</button>
                    <button type="button" className="inline-link-button" onClick={() => setLang('en')} style={{ fontWeight: getLang() === 'en' ? 700 : 400 }}>EN</button>
                </div>

                <form className="login-form" onSubmit={onSubmit}>
                    <label>
                        {t('Username')}
                        <input
                            autoComplete="username"
                            value={username}
                            onChange={(e) => setUsername(e.target.value)}
                            required
                            autoFocus
                        />
                    </label>

                    <label>
                        {t('Password')}
                        <input
                            type="password"
                            autoComplete="current-password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            required
                        />
                    </label>

                    {error ? <p className="form-error">{t(error)}</p> : null}

                    <button className="primary-button" type="submit" disabled={loading}>
                        <LockKeyhole size={18} aria-hidden />
                        {loading ? t('Signing in…') : t('Sign in')}
                    </button>
                </form>
            </section>
        </main>
    );
}
