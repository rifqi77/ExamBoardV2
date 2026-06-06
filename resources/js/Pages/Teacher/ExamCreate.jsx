import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

export default function ExamCreate({ examsBasePath }) {
    const [f, setF] = useState({
        examCode: '',
        name: '',
        durationMinutes: 60,
        passingGrade: 70,
        examMode: 'strict',
        subject: '',
        generalInstructions: 'Answer all questions to the best of your ability.',
    });
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const up = (k, v) => setF((s) => ({ ...s, [k]: v }));

    async function submit(e) {
        e.preventDefault();
        setBusy(true);
        setError('');
        try {
            const res = await fetch('/api/teacher/exams', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    ...f,
                    durationMinutes: Number(f.durationMinutes),
                    passingGrade: Number(f.passingGrade),
                }),
                credentials: 'same-origin',
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                setError(data.error || 'Failed to create exam.');
                setBusy(false);
                return;
            }
            window.location.href = examsBasePath + '/' + data.examId;
        } catch {
            setError('Network error.');
            setBusy(false);
        }
    }

    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div>
                    <h1>Create exam</h1>
                    <p>Set up the exam, then add questions and generate a token.</p>
                </div>
                <a className="ghost-button" href={examsBasePath}>← Back to exams</a>
            </header>
            <section className="admin-panel" style={{ maxWidth: 660 }}>
                <form onSubmit={submit} className="login-form">
                    <label>Exam code
                        <input value={f.examCode} onChange={(e) => up('examCode', e.target.value.toUpperCase())} placeholder="PHYS-01" required />
                    </label>
                    <label>Name
                        <input value={f.name} onChange={(e) => up('name', e.target.value)} placeholder="End of term physics" required />
                    </label>
                    <div style={{ display: 'flex', gap: 12 }}>
                        <label style={{ flex: 1 }}>Duration (min)
                            <input type="number" min="1" max="480" value={f.durationMinutes} onChange={(e) => up('durationMinutes', e.target.value)} />
                        </label>
                        <label style={{ flex: 1 }}>Passing grade (%)
                            <input type="number" min="0" max="100" value={f.passingGrade} onChange={(e) => up('passingGrade', e.target.value)} />
                        </label>
                    </div>
                    <div style={{ display: 'flex', gap: 12 }}>
                        <label style={{ flex: 1 }}>Mode
                            <select value={f.examMode} onChange={(e) => up('examMode', e.target.value)}>
                                <option value="strict">Strict (one attempt)</option>
                                <option value="try_out">Try-out (repeatable)</option>
                            </select>
                        </label>
                        <label style={{ flex: 1 }}>Subject
                            <input value={f.subject} onChange={(e) => up('subject', e.target.value)} placeholder="Physics" />
                        </label>
                    </div>
                    <label>Instructions
                        <textarea rows={3} value={f.generalInstructions} onChange={(e) => up('generalInstructions', e.target.value)} />
                    </label>
                    {error ? <p className="form-error">{error}</p> : null}
                    <button className="primary-button" type="submit" disabled={busy}>
                        {busy ? 'Creating…' : 'Create exam'}
                    </button>
                </form>
            </section>
        </AppLayout>
    );
}
