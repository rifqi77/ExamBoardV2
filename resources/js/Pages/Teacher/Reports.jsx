import { Download } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';
import { downloadReportExcel } from '../../lib/reportExcel';

export default function Reports({ exams, classes }) {
    const totalStudents = classes.reduce((s, c) => s + c.students.length, 0);
    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div>
                    <h1>Reports</h1>
                    <p>{totalStudents} student{totalStudents === 1 ? '' : 's'} × {exams.length} exam{exams.length === 1 ? '' : 's'}.</p>
                </div>
                <button className="primary-button" type="button" onClick={() => downloadReportExcel(exams, classes)}>
                    <Download size={16} aria-hidden /> Export Excel
                </button>
            </header>
            {classes.length === 0 ? (
                <section className="admin-panel"><p style={{ color: 'var(--muted)', margin: 0 }}>No data yet.</p></section>
            ) : (
                classes.map((cls, i) => (
                    <section className="admin-panel" key={i}>
                        <div className="section-title-row"><div>
                            <h2>{cls.className}</h2>
                            <p style={{ margin: 0, color: 'var(--muted)' }}>{cls.academicYear ? `${cls.academicYear} · ` : ''}{cls.students.length} student{cls.students.length === 1 ? '' : 's'}</p>
                        </div></div>
                        <div style={{ overflowX: 'auto' }}>
                            <table className="dashboard-table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        {exams.map((e) => <th key={e.examDatabaseId} title={e.examName}>{e.examId}</th>)}
                                        <th>Avg</th><th>Passed</th><th>Strongest</th><th>Weakest</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {cls.students.map((s) => (
                                        <tr key={s.studentId}>
                                            <td><strong>{s.studentName}</strong><div style={{ color: 'var(--muted)', fontSize: '0.8rem' }}>{s.username}</div></td>
                                            {exams.map((e) => {
                                                const c = s.perExam[e.examDatabaseId];
                                                return (
                                                    <td key={e.examDatabaseId}>
                                                        {c ? (c.status === 'pending_grading'
                                                            ? <span className="status-item warning">pend</span>
                                                            : <span style={{ color: c.passed ? 'inherit' : 'var(--red)' }}>{c.percent}%</span>)
                                                            : <span style={{ color: 'var(--muted)' }}>—</span>}
                                                    </td>
                                                );
                                            })}
                                            <td>{s.averagePercent !== null ? `${s.averagePercent}%` : '—'}</td>
                                            <td>{s.examsPassed}/{s.examsTaken}</td>
                                            <td style={{ fontSize: '0.85rem' }}>{s.strongestTopic ?? '—'}</td>
                                            <td style={{ fontSize: '0.85rem' }}>{s.weakestTopic ?? '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                ))
            )}
        </AppLayout>
    );
}
