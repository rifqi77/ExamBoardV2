import { AlertTriangle } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';

function Cell({ cell }) {
    if (!cell || !cell.count) return <span style={{ color: 'var(--muted)' }}>·</span>;
    return <span>{cell.count} <span style={{ color: 'var(--muted)', fontSize: 12 }}>· {cell.points}p</span></span>;
}

export default function Blueprint({ exam, difficulties, matrix, byDifficulty, byType, totals, uncoveredTopics, examsBasePath }) {
    // Column totals across topics, per difficulty band.
    const colTotals = difficulties.map((d) => matrix.reduce((acc, r) => ({
        count: acc.count + (r.cells[d.key]?.count || 0),
        points: acc.points + (r.cells[d.key]?.points || 0),
    }), { count: 0, points: 0 }));

    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div>
                    <h1>Blueprint — {exam.name}</h1>
                    <p><code>{exam.examId}</code>{exam.subject ? ` · ${exam.subject}` : ''} · {totals.count} question(s) · {totals.points} point(s) · {totals.topics} topic(s)</p>
                </div>
                <a className="ghost-button" href={`${examsBasePath}/${exam.examId}`}>← Back to exam</a>
            </header>

            {totals.count === 0 ? (
                <section className="admin-panel"><p style={{ color: 'var(--muted)', margin: 0 }}>No questions yet — add questions to see the blueprint.</p></section>
            ) : (
                <>
                    <section className="admin-panel">
                        <div className="section-title-row"><div><h2>Table of specifications</h2>
                            <p style={{ margin: 0, color: 'var(--muted)' }}>Questions (and points) by topic × cognitive level. Difficulty bands map roughly to Bloom levels.</p></div></div>
                        <table className="dashboard-table" style={{ marginTop: 10 }}>
                            <thead>
                                <tr>
                                    <th>Topic</th>
                                    {difficulties.map((d) => <th key={d.key} title={d.bloom}>{d.label}<div style={{ fontWeight: 400, fontSize: 11, color: 'var(--muted)' }}>{d.bloom}</div></th>)}
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                {matrix.map((r) => (
                                    <tr key={r.topic}>
                                        <td><strong>{r.topic}</strong></td>
                                        {difficulties.map((d) => <td key={d.key}><Cell cell={r.cells[d.key]} /></td>)}
                                        <td>{r.count} <span style={{ color: 'var(--muted)', fontSize: 12 }}>· {r.points}p</span></td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr style={{ borderTop: '2px solid #e5e7eb', fontWeight: 600 }}>
                                    <td>Total</td>
                                    {colTotals.map((c, i) => <td key={i}>{c.count ? <>{c.count} <span style={{ color: 'var(--muted)', fontSize: 12 }}>· {Math.round(c.points * 100) / 100}p</span></> : <span style={{ color: 'var(--muted)' }}>·</span>}</td>)}
                                    <td>{totals.count} · {totals.points}p</td>
                                </tr>
                            </tfoot>
                        </table>
                    </section>

                    <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap' }}>
                        <section className="admin-panel" style={{ flex: 1, minWidth: 320 }}>
                            <h2>By cognitive level</h2>
                            <table className="dashboard-table" style={{ marginTop: 8 }}>
                                <thead><tr><th>Level</th><th>Bloom</th><th>Questions</th><th>Points</th><th>% of points</th></tr></thead>
                                <tbody>
                                    {byDifficulty.map((d) => (
                                        <tr key={d.key}>
                                            <td><strong>{d.label}</strong></td>
                                            <td style={{ color: 'var(--muted)' }}>{d.bloom}</td>
                                            <td>{d.count}</td>
                                            <td>{d.points}</td>
                                            <td>{d.pct}%</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </section>

                        <section className="admin-panel" style={{ flex: 1, minWidth: 280 }}>
                            <h2>By question type</h2>
                            <table className="dashboard-table" style={{ marginTop: 8 }}>
                                <thead><tr><th>Type</th><th>Questions</th><th>Points</th><th>% of points</th></tr></thead>
                                <tbody>
                                    {byType.map((t) => (
                                        <tr key={t.type}><td>{t.type}</td><td>{t.count}</td><td>{t.points}</td><td>{t.pct}%</td></tr>
                                    ))}
                                </tbody>
                            </table>
                        </section>
                    </div>

                    <section className="admin-panel">
                        <div className="section-title-row"><div><h2>Curriculum coverage</h2>
                            <p style={{ margin: 0, color: 'var(--muted)' }}>Your learning-objective topics{exam.subject ? ` for ${exam.subject}` : ''} not yet represented by any question.</p></div></div>
                        {uncoveredTopics.length === 0 ? (
                            <p style={{ color: '#16a34a', margin: 0 }}>✓ Every learning-objective topic is covered by at least one question.</p>
                        ) : (
                            <ul style={{ margin: 0, paddingLeft: 18 }}>
                                {uncoveredTopics.map((t) => (
                                    <li key={t} style={{ marginBottom: 4 }}><span className="status-item warning"><AlertTriangle size={12} aria-hidden /> {t}</span></li>
                                ))}
                            </ul>
                        )}
                    </section>
                </>
            )}
        </AppLayout>
    );
}
