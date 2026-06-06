import { AlertTriangle, CheckCircle2, ChevronLeft, ChevronRight, Save, ShieldCheck } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import MarkdownContent from '../../Components/MarkdownContent';
import { t } from '../../lib/i18n';

const AUTOSAVE_INTERVAL_MS = 15_000;
const CHANGE_DEBOUNCE_MS = 800;
const EVENT_FLUSH_INTERVAL_MS = 10_000;

// Resolve a media URL: pass http(s) through (rewriting Google-Drive share
// links to a direct view), otherwise join against the exam's mediaBaseUrl.
function resolveMediaUrl(url, base) {
    if (!url) return url;
    if (/^https?:\/\//i.test(url)) {
        const m = url.match(/drive\.google\.com\/file\/d\/([^/]+)/);
        if (m) return `https://drive.google.com/uc?export=view&id=${m[1]}`;
        const open = url.match(/drive\.google\.com\/open\?id=([^&]+)/);
        if (open) return `https://drive.google.com/uc?export=view&id=${open[1]}`;
        return url;
    }
    if (base) return base.replace(/\/+$/, '') + '/' + url.replace(/^\/+/, '');
    return url;
}

function isAnswered(value) {
    if (Array.isArray(value)) return value.length > 0;
    return value !== undefined && value !== null && value !== '';
}

function QuestionMedia({ media, base }) {
    if (!media || media.length === 0) return null;
    return (
        <div style={{ display: 'grid', gap: 10, margin: '10px 0' }}>
            {media.map((m) => {
                const url = resolveMediaUrl(m.url, base);
                const type = (m.type || '').toLowerCase();
                return (
                    <figure key={m.id} style={{ margin: 0 }}>
                        {type === 'audio' ? (
                            <audio controls src={url} style={{ width: '100%' }} />
                        ) : type === 'video' ? (
                            <video controls src={url} style={{ maxWidth: '100%', borderRadius: 8 }} />
                        ) : (
                            <img src={url} alt={m.altText || ''} style={{ maxWidth: '100%', borderRadius: 8, border: '1px solid #e4e4e7' }} />
                        )}
                        {m.caption ? <figcaption style={{ color: '#71717a', fontSize: 13, marginTop: 4 }}>{m.caption}</figcaption> : null}
                    </figure>
                );
            })}
        </div>
    );
}

const optStyle = { display: 'flex', gap: 10, alignItems: 'flex-start', padding: '8px 10px', cursor: 'pointer', border: '1px solid #e4e4e7', borderRadius: 8, marginBottom: 8 };

function QuestionInput({ q, value, onChange }) {
    if (q.type === 'single_choice') {
        return (q.options || []).map((o) => (
            <label key={o.id} style={{ ...optStyle, borderColor: value === o.id ? '#6366f1' : '#e4e4e7', background: value === o.id ? '#eef2ff' : '#fff' }}>
                <input type="radio" name={q.id} checked={value === o.id} onChange={() => onChange(o.id)} />
                <span><b>{o.id}.</b> {o.text}</span>
            </label>
        ));
    }
    if (q.type === 'multi_select') {
        const arr = Array.isArray(value) ? value : [];
        return (q.options || []).map((o) => (
            <label key={o.id} style={{ ...optStyle, borderColor: arr.includes(o.id) ? '#6366f1' : '#e4e4e7', background: arr.includes(o.id) ? '#eef2ff' : '#fff' }}>
                <input type="checkbox" checked={arr.includes(o.id)}
                    onChange={(e) => onChange(e.target.checked ? [...arr, o.id] : arr.filter((x) => x !== o.id))} />
                <span><b>{o.id}.</b> {o.text}</span>
            </label>
        ));
    }
    if (q.type === 'essay') {
        return <textarea value={value || ''} onChange={(e) => onChange(e.target.value)} rows={8}
            style={{ width: '100%', boxSizing: 'border-box' }} placeholder={t('Write your answer…')} />;
    }
    return <input value={value || ''} onChange={(e) => onChange(e.target.value)} placeholder={t('Your answer')}
        style={{ width: '100%', maxWidth: 360 }} inputMode={q.type === 'numeric' ? 'decimal' : 'text'} />;
}

const pad = { fontFamily: 'system-ui, sans-serif', padding: 32, maxWidth: 560, margin: '40px auto', textAlign: 'center' };

export default function Take({ examId }) {
    const [data, setData] = useState(null);
    const [answers, setAnswers] = useState({});
    const [currentIndex, setCurrentIndex] = useState(0);
    const [error, setError] = useState('');
    const [sebBlocked, setSebBlocked] = useState(false);
    const [remaining, setRemaining] = useState(null);
    const [saveState, setSaveState] = useState('idle'); // idle|dirty|saving|saved|error
    const [submitting, setSubmitting] = useState(false);

    const answersRef = useRef({});
    const submittedRef = useRef(false);
    const remainingRef = useRef(null);
    const eventsRef = useRef([]);
    const flushedCountRef = useRef(0);
    const flushInFlightRef = useRef(null);
    const debounceRef = useRef(null);

    const strict = data?.metadata?.examMode === 'strict';

    const recordEvent = useCallback((kind, detail) => {
        eventsRef.current.push({ kind, at: new Date().toISOString(), ...(detail ? { detail } : {}) });
    }, []);

    // ---- load ----
    useEffect(() => {
        (async () => {
            try {
                const res = await fetch('/api/exams/' + examId, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                const body = await res.json().catch(() => ({}));
                if (!res.ok) {
                    if (body.submissionId && (body.alreadySubmitted || body.autoSubmitted)) {
                        window.location.href = '/student/result/' + body.submissionId;
                        return;
                    }
                    if (body.sebRequired) { setSebBlocked(true); return; }
                    setError(body.error || 'Could not load exam.');
                    return;
                }
                // Client-side SEB belt-and-suspenders (server already enforces).
                if (body.metadata.examMode === 'strict' && body.metadata.sebRequired) {
                    const ua = navigator.userAgent || '';
                    const inSeb = /SEB\b/.test(ua) || /SafeExamBrowser/i.test(ua) || typeof window.SafeExamBrowser !== 'undefined';
                    if (!inSeb) {
                        const ev = { kind: 'seb_missing', at: new Date().toISOString(), detail: ua.slice(0, 120) };
                        fetch('/api/exams/' + examId + '/events', {
                            method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                            body: JSON.stringify({ events: [ev] }), credentials: 'same-origin',
                        }).catch(() => {});
                        setSebBlocked(true);
                        return;
                    }
                }
                setData(body);
                setAnswers(body.draftAnswers || {});
                answersRef.current = body.draftAnswers || {};
                setRemaining(body.session.timeRemainingSeconds);
                remainingRef.current = body.session.timeRemainingSeconds;
                setSaveState('saved');
            } catch {
                setError('Network error loading exam.');
            }
        })();
    }, [examId]);

    const questions = data?.questions || [];
    const answeredCount = useMemo(() => questions.filter((q) => isAnswered(answers[q.id])).length, [questions, answers]);

    // ---- autosave ----
    const saveDraft = useCallback(async () => {
        if (!data || submittedRef.current) return;
        setSaveState('saving');
        try {
            await fetch('/api/exams/' + examId + '/draft', {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ answers: answersRef.current }), credentials: 'same-origin',
            });
            setSaveState((s) => (s === 'dirty' ? 'dirty' : 'saved'));
        } catch {
            setSaveState('error');
        }
    }, [data, examId]);

    function updateAnswer(qid, val) {
        setAnswers((a) => {
            const next = { ...a, [qid]: val };
            answersRef.current = next;
            return next;
        });
        setSaveState('dirty');
        if (debounceRef.current) clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => { void saveDraft(); }, CHANGE_DEBOUNCE_MS);
    }

    useEffect(() => {
        if (!data) return undefined;
        const t = setInterval(() => { if (saveState === 'dirty') void saveDraft(); }, AUTOSAVE_INTERVAL_MS);
        return () => clearInterval(t);
    }, [data, saveState, saveDraft]);

    // ---- timer + auto-submit ----
    useEffect(() => {
        if (!data || remaining === null) return undefined;
        const t = setInterval(() => {
            setRemaining((r) => {
                const next = r === null ? null : Math.max(0, r - 1);
                remainingRef.current = next;
                if (next === 0) { void submitExam(true); }
                return next;
            });
        }, 1000);
        return () => clearInterval(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data]);

    // ---- anti-cheat detection (strict only) ----
    useEffect(() => {
        if (!data || !strict) return undefined;
        const onVisibility = () => recordEvent(document.visibilityState === 'hidden' ? 'tab_blur' : 'tab_focus');
        const onBlur = () => recordEvent('tab_blur');
        const onFullscreen = () => recordEvent(document.fullscreenElement ? 'fullscreen_enter' : 'fullscreen_exit');
        const onCopy = (e) => { e.preventDefault(); recordEvent('copy_blocked'); };
        const onPaste = (e) => { e.preventDefault(); recordEvent('paste_blocked'); };
        const onContextMenu = (e) => { e.preventDefault(); recordEvent('contextmenu_blocked'); };
        document.addEventListener('visibilitychange', onVisibility);
        window.addEventListener('blur', onBlur);
        document.addEventListener('fullscreenchange', onFullscreen);
        document.addEventListener('copy', onCopy);
        document.addEventListener('paste', onPaste);
        document.addEventListener('contextmenu', onContextMenu);
        return () => {
            document.removeEventListener('visibilitychange', onVisibility);
            window.removeEventListener('blur', onBlur);
            document.removeEventListener('fullscreenchange', onFullscreen);
            document.removeEventListener('copy', onCopy);
            document.removeEventListener('paste', onPaste);
            document.removeEventListener('contextmenu', onContextMenu);
        };
    }, [data, strict, recordEvent]);

    // ---- event flushing (strict only): periodic + beforeunload beacon ----
    useEffect(() => {
        if (!data || !strict) return undefined;
        async function doFlush() {
            if (flushInFlightRef.current) return;
            const total = eventsRef.current.length;
            const start = flushedCountRef.current;
            if (start >= total) return;
            const batch = eventsRef.current.slice(start);
            const promise = (async () => {
                try {
                    await fetch('/api/exams/' + examId + '/events', {
                        method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                        body: JSON.stringify({ events: batch }), credentials: 'same-origin',
                    });
                    flushedCountRef.current = start + batch.length;
                } catch { /* retry next tick */ }
            })();
            flushInFlightRef.current = promise;
            try { await promise; } finally { flushInFlightRef.current = null; }
        }
        const timer = setInterval(doFlush, EVENT_FLUSH_INTERVAL_MS);
        const onBeforeUnload = () => {
            const total = eventsRef.current.length;
            const start = flushedCountRef.current;
            if (start >= total) return;
            const batch = eventsRef.current.slice(start);
            try {
                const blob = new Blob([JSON.stringify({ events: batch })], { type: 'application/json' });
                navigator.sendBeacon('/api/exams/' + encodeURIComponent(examId) + '/events', blob);
                flushedCountRef.current = start + batch.length;
            } catch { /* nothing we can do during unload */ }
        };
        window.addEventListener('beforeunload', onBeforeUnload);
        return () => { clearInterval(timer); window.removeEventListener('beforeunload', onBeforeUnload); };
    }, [data, strict, examId]);

    async function submitExam(auto) {
        if (submittedRef.current) return;
        if (!auto && !window.confirm(t('Submit your exam? You cannot change answers after this.'))) return;
        submittedRef.current = true;
        setSubmitting(true);
        if (flushInFlightRef.current) { try { await flushInFlightRef.current; } catch { /* ignore */ } }
        const tail = eventsRef.current.slice(flushedCountRef.current);
        try {
            const res = await fetch('/api/exams/' + examId + '/submit', {
                method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ answers: answersRef.current, antiCheatEvents: tail }), credentials: 'same-origin',
            });
            const body = await res.json().catch(() => ({}));
            flushedCountRef.current = eventsRef.current.length;
            if (body.submissionId) { window.location.href = '/student/result/' + body.submissionId; return; }
            setError(body.error || 'Submit failed.');
            submittedRef.current = false;
            setSubmitting(false);
        } catch {
            setError('Network error.');
            submittedRef.current = false;
            setSubmitting(false);
        }
    }

    // ---- render ----
    if (sebBlocked) {
        return (
            <div style={pad}>
                <ShieldCheck size={34} aria-hidden style={{ color: '#6366f1' }} />
                <h1>{t('Safe Exam Browser required')}</h1>
                <p style={{ color: '#52525b' }}>
                    This exam runs in strict mode with SEB enforcement enabled. Open the <code>.seb</code> config
                    file your teacher provided to launch Safe Exam Browser, then return here.
                </p>
                <p>Don&apos;t have SEB? <a href="https://safeexambrowser.org/" target="_blank" rel="noreferrer">Download from safeexambrowser.org</a>.</p>
                <a className="ghost-button" href="/student">{t('Back to my exams')}</a>
            </div>
        );
    }
    if (error) {
        return (
            <div style={pad}>
                <AlertTriangle size={34} aria-hidden style={{ color: '#b42318' }} />
                <h1>{t('Exam unavailable')}</h1>
                <p className="form-error">{error}</p>
                <a className="ghost-button" href="/student">{t('Back to my exams')}</a>
            </div>
        );
    }
    if (!data) return <div style={{ ...pad, textAlign: 'left' }}>{t('Loading exam…')}</div>;

    const mm = String(Math.floor((remaining ?? 0) / 60)).padStart(2, '0');
    const ss = String((remaining ?? 0) % 60).padStart(2, '0');
    const q = questions[currentIndex];
    const saveLabel = { idle: '', dirty: t('Unsaved…'), saving: t('Saving…'), saved: t('Saved'), error: t('Save failed') }[saveState];

    return (
        <div style={{ fontFamily: 'system-ui, sans-serif', maxWidth: 860, margin: '0 auto', padding: '0 16px 100px', userSelect: strict ? 'none' : 'auto' }}>
            <div style={{ position: 'sticky', top: 0, background: '#fff', borderBottom: '1px solid #e4e4e7', padding: '14px 0', display: 'flex', justifyContent: 'space-between', alignItems: 'center', zIndex: 10, flexWrap: 'wrap', gap: 8 }}>
                <div>
                    <strong>{data.metadata.name}</strong>
                    <div style={{ color: '#71717a', fontSize: 13 }}>
                        {t('Pass mark')} {data.metadata.passingGrade}% · {answeredCount}/{questions.length} {t('answered')}
                        {saveLabel ? <> · <span style={{ color: saveState === 'error' ? '#b42318' : '#16a34a' }}>{saveLabel}</span></> : null}
                        {strict ? <> · <span title="Strict mode: activity is monitored">🔒 {t('monitored')}</span></> : null}
                    </div>
                </div>
                <div style={{ fontVariantNumeric: 'tabular-nums', fontSize: 24, fontWeight: 700, color: (remaining ?? 0) < 60 ? '#b42318' : '#18181b' }}>
                    {mm}:{ss}
                </div>
            </div>

            {data.metadata.generalInstructions ? (
                <div className="admin-panel" style={{ marginTop: 16 }}>
                    <MarkdownContent>{data.metadata.generalInstructions}</MarkdownContent>
                </div>
            ) : null}

            {q ? (
                <div className="admin-panel" style={{ marginTop: 16 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                        <strong>{t('Question')} {q.position} {t('of')} {questions.length}</strong>
                        <span style={{ color: '#71717a', fontSize: 13 }}>{q.topic ? q.topic + ' · ' : ''}{q.points} pt</span>
                    </div>
                    <MarkdownContent>{q.prompt}</MarkdownContent>
                    <QuestionMedia media={q.media} base={data.metadata.mediaBaseUrl} />
                    <div style={{ marginTop: 10 }}>
                        <QuestionInput q={q} value={answers[q.id]} onChange={(v) => updateAnswer(q.id, v)} />
                    </div>
                </div>
            ) : null}

            <div style={{ display: 'flex', gap: 10, marginTop: 16, alignItems: 'center' }}>
                <button className="ghost-button" type="button" disabled={currentIndex === 0}
                    onClick={() => setCurrentIndex((i) => Math.max(0, i - 1))}>
                    <ChevronLeft size={16} aria-hidden /> {t('Prev')}
                </button>
                <button className="ghost-button" type="button" disabled={currentIndex >= questions.length - 1}
                    onClick={() => setCurrentIndex((i) => Math.min(questions.length - 1, i + 1))}>
                    {t('Next')} <ChevronRight size={16} aria-hidden />
                </button>
                <button className="ghost-button" type="button" onClick={() => saveDraft()} style={{ marginLeft: 'auto' }}>
                    <Save size={15} aria-hidden /> {t('Save progress')}
                </button>
            </div>

            {/* Question navigation palette */}
            <div className="admin-panel" style={{ marginTop: 16 }}>
                <div style={{ fontSize: 13, color: '#71717a', marginBottom: 8 }}>{t('Jump to question')}</div>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
                    {questions.map((qq, i) => {
                        const answered = isAnswered(answers[qq.id]);
                        const current = i === currentIndex;
                        return (
                            <button key={qq.id} type="button" onClick={() => setCurrentIndex(i)}
                                title={answered ? 'Answered' : 'Unanswered'}
                                style={{
                                    width: 38, height: 38, borderRadius: 8, cursor: 'pointer',
                                    border: current ? '2px solid #6366f1' : '1px solid #d4d4d8',
                                    background: answered ? '#dcfce7' : '#fff',
                                    color: answered ? '#166534' : '#3f3f46', fontWeight: 600,
                                }}>
                                {i + 1}
                            </button>
                        );
                    })}
                </div>
            </div>

            <div style={{ marginTop: 24 }}>
                <button className="primary-button" onClick={() => submitExam(false)} disabled={submitting}>
                    <CheckCircle2 size={16} aria-hidden /> {submitting ? t('Submitting…') : t('Submit exam')}
                </button>
            </div>
        </div>
    );
}
