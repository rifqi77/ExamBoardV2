import { Sparkles } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

export default function AiGenerate({ exams, provider, model, imageProvider, keyReady, loTopics = [], subjects = [] }) {
    const [f, setF] = useState({
        target: 'exam', examId: exams[0]?.examId || '', count: 5, type: 'any', topic: '',
        subject: subjects[0] || '', language: 'English', difficulty: 'medium', learningObjective: '',
        olympiadIntensity: 'off', extraInstructions: '', generateImages: false,
    });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [result, setResult] = useState(null);
    const up = (k, v) => setF((prev) => ({ ...prev, [k]: v }));
    const imagesAvailable = imageProvider && imageProvider !== 'off';

    async function run(e) {
        e.preventDefault();
        setBusy(true); setError(''); setResult(null);
        try {
            const res = await fetch('/api/teacher/ai-generate/run', {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(f), credentials: 'same-origin',
            });
            const d = await res.json().catch(() => ({}));
            setBusy(false);
            if (!res.ok) { setError(d.error || 'Generation failed.'); return; }
            setResult(d);
        } catch { setError('Network error.'); setBusy(false); }
    }

    return (
        <AppLayout>
            <header className="teacher-page-header"><div>
                <h1>AI question generation</h1>
                <p>Text <strong>{provider}</strong> · <code>{model}</code> · images <strong>{imageProvider || 'off'}</strong>{keyReady ? '' : ' · ⚠ no API key'}</p>
            </div></header>
            {!keyReady ? <p className="form-error">No API key for "{provider}". An admin can set one in <strong>AI settings</strong> (or switch to Pollinations, which needs no key).</p> : null}

            <section className="admin-panel" style={{ maxWidth: 720 }}>
                <form onSubmit={run} className="login-form">
                    <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
                        <label style={{ flex: 1, minWidth: 160 }}>Output to
                            <select value={f.target} onChange={(e) => up('target', e.target.value)}>
                                <option value="exam">An exam</option>
                                <option value="bank">Question bank</option>
                            </select>
                        </label>
                        {f.target === 'exam' ? (
                            <label style={{ flex: 2, minWidth: 200 }}>Target exam
                                <select value={f.examId} onChange={(e) => up('examId', e.target.value)} required>
                                    {exams.length === 0 ? <option value="">(no exams — create one first)</option> : exams.map((e) => <option key={e.examId} value={e.examId}>{e.name} ({e.examId})</option>)}
                                </select>
                            </label>
                        ) : null}
                    </div>

                    <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
                        <label style={{ flex: 1, minWidth: 160 }}>Topic
                            <input list="lo-topics" value={f.topic} onChange={(e) => up('topic', e.target.value)} placeholder="Kinematics" required />
                            <datalist id="lo-topics">{loTopics.map((t) => <option key={t} value={t} />)}</datalist>
                        </label>
                        <label style={{ flex: 1, minWidth: 140 }}>Subject
                            <input list="lo-subjects" value={f.subject} onChange={(e) => up('subject', e.target.value)} placeholder="Physics" />
                            <datalist id="lo-subjects">{subjects.map((s) => <option key={s} value={s} />)}</datalist>
                        </label>
                        <label style={{ width: 120 }}>Language<input value={f.language} onChange={(e) => up('language', e.target.value)} /></label>
                    </div>

                    <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
                        <label style={{ flex: 1, minWidth: 140 }}>Type
                            <select value={f.type} onChange={(e) => up('type', e.target.value)}>
                                <option value="any">Mixed (let AI choose)</option>
                                <option value="single_choice">Single choice</option>
                                <option value="multi_select">Multi select</option>
                                <option value="numeric">Numeric</option>
                                <option value="short_text">Short text</option>
                                <option value="essay">Essay</option>
                            </select>
                        </label>
                        <label style={{ flex: 1, minWidth: 120 }}>Difficulty
                            <select value={f.difficulty} onChange={(e) => up('difficulty', e.target.value)}>
                                <option value="easy">Easy</option><option value="medium">Medium</option><option value="hard">Hard</option>
                                <option value="hots">HOTS</option><option value="olympiad">Olympiad</option>
                            </select>
                        </label>
                        <label style={{ flex: 1, minWidth: 120 }}>Olympiad intensity
                            <select value={f.olympiadIntensity} onChange={(e) => up('olympiadIntensity', e.target.value)}>
                                <option value="off">Off</option><option value="intro">Intro</option><option value="moderate">Moderate</option><option value="extreme">Extreme</option>
                            </select>
                        </label>
                        <label style={{ width: 90 }}>Count<input type="number" min="1" max="100" value={f.count} onChange={(e) => up('count', e.target.value)} /></label>
                    </div>

                    <label>Learning objective (optional)
                        <input value={f.learningObjective} onChange={(e) => up('learningObjective', e.target.value)} placeholder="Constrain to a specific objective…" />
                    </label>
                    <label>Extra instructions (optional)
                        <textarea rows={2} value={f.extraInstructions} onChange={(e) => up('extraInstructions', e.target.value)} placeholder="e.g. use SI units; include a data table" />
                    </label>

                    <label style={{ display: 'flex', gap: 8, alignItems: 'center', opacity: imagesAvailable ? 1 : 0.5 }}>
                        <input type="checkbox" checked={f.generateImages} disabled={!imagesAvailable} onChange={(e) => up('generateImages', e.target.checked)} />
                        Generate figures for questions that need them {imagesAvailable ? `(via ${imageProvider})` : '(enable an image provider in AI settings)'}
                    </label>

                    {error ? <p className="form-error">{error}</p> : null}
                    <button className="primary-button" type="submit" disabled={busy || !keyReady || (f.target === 'exam' && !f.examId)}>
                        <Sparkles size={16} aria-hidden /> {busy ? 'Generating…' : f.target === 'bank' ? 'Generate & add to bank' : 'Generate & add to exam'}
                    </button>
                </form>
            </section>

            {result ? (
                <section className="admin-panel">
                    <div className="section-title-row"><div><h2>Added {result.created} question(s) to {result.target}{result.imageCount ? ` · ${result.imageCount} image(s)` : ''}</h2></div></div>
                    <ol style={{ paddingLeft: 18 }}>
                        {result.questions.map((q, i) => (<li key={i} style={{ marginBottom: 6 }}><strong>{q.type}</strong>{q.hasImage ? ' 🖼' : ''} — {q.prompt}</li>))}
                    </ol>
                </section>
            ) : null}
        </AppLayout>
    );
}
