import AppLayout from '../../Layouts/AppLayout';

function masteryColor(p) {
    if (p === null || p === undefined) return { background: '#f4f4f5', color: '#a1a1aa' };
    if (p < 50) return { background: '#fee2e2', color: '#991b1b' };
    if (p < 70) return { background: '#fef3c7', color: '#92400e' };
    return { background: '#dcfce7', color: '#166534' };
}

export default function Mastery({ scope, summary, topics, heatmapExams, heatmap }) {
    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div>
                    <h1>Objective mastery</h1>
                    <p>Across {scope} · {summary.exams} exam(s) · {summary.submissions} submission(s) · {summary.topics} topic(s). Weakest objectives first — use this to decide what to reteach.</p>
                </div>
            </header>

            {summary.topics === 0 ? (
                <section className="admin-panel"><p style={{ color: 'var(--muted)', margin: 0 }}>No graded submissions yet — mastery appears once students have taken exams.</p></section>
            ) : (
                <>
                    <section className="admin-panel">
                        <div className="section-title-row"><div><h2>Weakest objectives</h2>
                            <p style={{ margin: 0, color: 'var(--muted)' }}>Class mastery per topic, aggregated across all exams. Below 50% needs attention.</p></div></div>
                        <table className="dashboard-table" style={{ marginTop: 10 }}>
                            <thead><tr><th>Topic / objective</th><th>Class mastery</th><th>Responses below 50%</th><th>Responses</th></tr></thead>
                            <tbody>
                                {topics.map((t) => {
                                    const c = masteryColor(t.masteryPercent);
                                    return (
                                        <tr key={t.topic}>
                                            <td><strong>{t.topic}</strong></td>
                                            <td><span style={{ ...c, padding: '2px 8px', borderRadius: 6, fontWeight: 700 }}>{t.masteryPercent === null ? '—' : `${t.masteryPercent}%`}</span></td>
                                            <td>{t.studentsBelow} / {t.responses}</td>
                                            <td>{t.responses}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </section>

                    {heatmapExams.length > 1 ? (
                        <section className="admin-panel">
                            <div className="section-title-row"><div><h2>Topic × exam heatmap</h2>
                                <p style={{ margin: 0, color: 'var(--muted)' }}>Mastery per topic in each exam — spot whether a weakness is exam-specific or persistent.</p></div></div>
                            <div style={{ overflowX: 'auto' }}>
                                <table className="dashboard-table" style={{ marginTop: 10, minWidth: 480 }}>
                                    <thead>
                                        <tr>
                                            <th>Topic</th>
                                            {heatmapExams.map((e) => <th key={e.examId} title={e.name} style={{ fontSize: 12 }}>{e.name.length > 14 ? e.name.slice(0, 13) + '…' : e.name}</th>)}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {heatmap.map((row) => (
                                            <tr key={row.topic}>
                                                <td><strong>{row.topic}</strong></td>
                                                {row.cells.map((p, i) => {
                                                    const c = masteryColor(p);
                                                    return <td key={i} style={{ textAlign: 'center', background: c.background, color: c.color, fontWeight: 600 }}>{p === null ? '·' : `${Math.round(p)}`}</td>;
                                                })}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <p style={{ color: 'var(--muted)', fontSize: 12, marginTop: 8 }}>Cells show mastery %. <span style={{ background: '#fee2e2', padding: '1px 6px', borderRadius: 4 }}>&lt;50</span> <span style={{ background: '#fef3c7', padding: '1px 6px', borderRadius: 4 }}>50–69</span> <span style={{ background: '#dcfce7', padding: '1px 6px', borderRadius: 4 }}>70+</span></p>
                        </section>
                    ) : null}
                </>
            )}
        </AppLayout>
    );
}
