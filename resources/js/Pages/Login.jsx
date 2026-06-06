import { LockKeyhole, ShieldCheck } from 'lucide-react';
import { useState } from 'react';

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
                        <h1>Exam Dashboard</h1>
                        <p>Sign in with your account to continue.</p>
                    </div>
                </div>

                <form className="login-form" onSubmit={onSubmit}>
                    <label>
                        Username
                        <input
                            autoComplete="username"
                            value={username}
                            onChange={(e) => setUsername(e.target.value)}
                            required
                            autoFocus
                        />
                    </label>

                    <label>
                        Password
                        <input
                            type="password"
                            autoComplete="current-password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            required
                        />
                    </label>

                    {error ? <p className="form-error">{error}</p> : null}

                    <button className="primary-button" type="submit" disabled={loading}>
                        <LockKeyhole size={18} aria-hidden />
                        {loading ? 'Signing in...' : 'Sign in'}
                    </button>
                </form>
            </section>
        </main>
    );
}
