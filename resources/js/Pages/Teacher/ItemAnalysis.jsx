import { AlertTriangle, ChevronDown, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

const FLAG_LABELS = {
    too_easy: 'Too easy',
    too_hard: 'Too hard',
    weak_discrimination: 'Weak discrimination',
    negative_discrimination: 'Negative discrimination',
    ungraded_essays: 'Ungraded essays',
};

const VERDICT_COLOR = { keep: '#16a34a', review: '#b45309', retire: '#b42318', info: '#3f3f46' };

function alphaVerdict(a) {
    if (a === null || a === undefined) return ['—', ''];
    if (a >= 0.9) return [a.toFixed(2), 'excellent'];
    if (a >= 0.8) return [a.toFixed(2), 'good'];
    if (a >= 0.7) return [a.toFixed(2), 'acceptable'];
    if (a >= 0.5) return [a.toFixed(2), 'questionable'];
    return [a.toFixed(2), 'low'];
}

function diffLabel(p) {
    if (p === null) return '—';
    if (p > 0.9) return 'very easy';
    if (p >= 0.7) return 'easy';
    if (p >= 0.3) return 'good';
    if (p >= 0.2) return 'hard';
    return 'very hard';
}

function discColor(d) {
    if (d === null) return 'var(--muted)';
    if (d < 0) return '#b42318';
    if (d < 0.15) return '#b45309';
    if (d < 0.3) return '#3f3f46';
    return '#16a34a';
}

function Card({ label, value, sub }) {
    return (
        <div className="admin-panel" style={{ flex: 1, minWidth: 140, margin: 0 }}>
            <div style={{ color: 'var(--muted)', fontSize: 13 }}>{label}</div>
            <div style={{ fontSize: 26, fontWeight: 700 }}>{value}</div>
            {sub ? <div style={{ color: 'var(--muted)', fontSize: 12 }}>{sub}</div> : null}
        </div>
    );
}

function Distribution({ buckets }) {
    const max = Math.max(1, ...buckets);
    return (
        <div style={{ display: 'flex', gap: 4, alignItems: 'flex-end', height: 90, marginTop: 8 }}>
            {buckets.map((c, i) => (
                <div key={i} style={{ flex: 1, textAlign: 'center' }} title={`${i * 10}–${i * 10 + 9}%: ${c}`}>
                    <div style={{ height: `${(c / max) * 70}px`, background: '#6366f1', borderRadius: '3px 3px 0 0', minHeight: c ? 3 : 0 }} />
                    <div style={{ fontSize: 10, color: 'var(--muted)', marginTop: 2 }}>{i * 10}</div>
                </div>
            ))}
        </div>
    );
}

function ItemRow({ item }) {
    const [open, setOpen] = useState(false);
    const hasOpts = item.options && item.options.length > 0;
    return (
        <>
            <tr>
                <td>{item.position}</td>
                <td>{item.type}</td>
                <td>{item.topic}</td>
                <td>{item.pValue === null ? '—' : item.pValue.toFixed(2)} <span style={{ color: 'var(--muted)', fontSize: 12 }}>{diffLabel(item.pValue)}</span></td>
                <td style={{ color: discColor(item.discrimination), fontWeight: 600 }}>{item.discrimination === null ? '—' : item.discrimination.toFixed(2)}</td>
                <td>
                    {item.flags.length === 0 ? <span style={{ color: '#16a34a' }}>✓</span> : item.flags.map((f) => (
                        <span key={f} className="status-item warning" style={{ marginRight: 4 }}><AlertTriangle size={12} aria-hidden /> {FLAG_LABELS[f] || f}</span>
                    ))}
                </td>
                <td title={item.verdict?.reason || ''} style={{ color: VERDICT_COLOR[item.verdict?.level] || 'inherit', fontWeight: 600, whiteSpace: 'nowrap' }}>{item.verdict?.label || '—'}</td>
                <td>{hasOpts ? <button className="ghost-button" type="button" onClick={() => setOpen((v) => !v)}>{open ? <ChevronDown size={14} /> : <ChevronRight size={14} />}</button> : null}</td>
            </tr>
            {open && hasOpts ? (
                <tr><td colSpan={8} style={{ background: '#fafafa' }}>
                    <div style={{ padding: '6px 10px', fontSize: 13 }}>
                        <strong>Distractor analysis</strong> — {item.prompt}
                        <table style={{ width: '100%', marginTop: 6 }}>
                            <tbody>
                                {item.options.map((o) => (
                                    <tr key={o.id}>
                                        <td style={{ width: 30 }}>{o.isCorrect ? '✅' : ''}</td>
                                        <td style={{ width: 24 }}><b>{o.id}</b></td>
                                        <td>{o.text}</td>
                                        <td style={{ width: 80, textAlign: 'right', color: o.isCorrect ? '#16a34a' : (o.chosen === 0 ? '#b45309' : 'inherit') }}>
                                            {o.chosen} picked{!o.isCorrect && o.chosen === 0 ? ' (dead)' : ''}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </td></tr>
            ) : null}
        </>
    );
}

export default function ItemAnalysis({ exam, summary, items, topics, examsBasePath }) {
    const [aVal, aWord] = alphaVerdict(summary.cronbachAlpha);
    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div>
                    <h1>Item analysis — {exam.name}</h1>
                    <p><code>{exam.examId}</code> · {summary.n} submission(s) analysed · pass mark {exam.passingGrade}%</p>
                </div>
                <a className="ghost-button" href={`${examsBasePath}/${exam.examId}`}>← Back to exam</a>
            </header>

            {summary.n === 0 ? (
                <section className="admin-panel"><p style={{ color: 'var(--muted)', margin: 0 }}>No submissions yet — nothing to analyse.</p></section>
            ) : (
                <>
                    <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 14 }}>
                        <Card label="Mean" value={`${summary.meanPercent}%`} sub={`median ${summary.medianPercent}%`} />
                        <Card label="Std dev" value={`${summary.stdDev}`} sub="spread of scores" />
                        <Card label="Pass rate" value={`${summary.passRate}%`} />
                        <Card label="Reliability (α)" value={aVal} sub={`Cronbach's alpha · ${aWord}`} />
                        <Card label="Auto items" value={summary.autoItemCount} sub="used for α / discrimination" />
                    </div>

                    <section className="admin-panel">
                        <h2>Score distribution</h2>
                        <Distribution buckets={summary.distribution} />
                    </section>

                    <section className="admin-panel">
                        <div className="section-title-row"><div><h2>Items</h2>
                            <p style={{ margin: 0, color: 'var(--muted)' }}>Difficulty = mean score ratio (higher = easier). Discrimination = corrected item-total correlation (higher = better separates strong/weak students). Expand choice items for distractor analysis.</p></div></div>
                        <table className="dashboard-table" style={{ marginTop: 10 }}>
                            <thead><tr><th>#</th><th>Type</th><th>Topic</th><th>Difficulty (p)</th><th>Discrimination</th><th>Flags</th><th>Verdict</th><th></th></tr></thead>
                            <tbody>
                                {items.map((it) => <ItemRow key={it.position} item={it} />)}
                            </tbody>
                        </table>
                    </section>

                    <section className="admin-panel">
                        <div className="section-title-row"><div><h2>Mastery by topic / objective</h2>
                            <p style={{ margin: 0, color: 'var(--muted)' }}>Class mastery per topic (weakest first), and how many students are below {`<50%`}.</p></div></div>
                        <table className="dashboard-table" style={{ marginTop: 10 }}>
                            <thead><tr><th>Topic</th><th>Class mastery</th><th>Students below 50%</th></tr></thead>
                            <tbody>
                                {topics.map((t) => (
                                    <tr key={t.topic}>
                                        <td>{t.topic}</td>
                                        <td>
                                            <span style={{ color: t.masteryPercent === null ? 'var(--muted)' : t.masteryPercent < 50 ? '#b42318' : t.masteryPercent < 70 ? '#b45309' : '#16a34a', fontWeight: 600 }}>
                                                {t.masteryPercent === null ? '—' : `${t.masteryPercent}%`}
                                            </span>
                                        </td>
                                        <td>{t.studentsBelow} / {t.studentsTotal}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>
                </>
            )}
        </AppLayout>
    );
}
