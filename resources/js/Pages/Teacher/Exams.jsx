import { CheckCircle2, CircleX } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';

export default function Exams({ exams, scope, examsBasePath }) {
    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div>
                    <h1>Exams</h1>
                    <p>{exams.length} exam{exams.length === 1 ? '' : 's'} — {scope}.</p>
                </div>
                <a className="primary-button" href={`${examsBasePath}/new`}>+ Create exam</a>
            </header>
            <section className="admin-panel">
                {exams.length === 0 ? (
                    <p style={{ color: 'var(--muted)', margin: 0 }}>No exams yet.</p>
                ) : (
                    <table className="dashboard-table">
                        <thead>
                            <tr>
                                <th>Exam</th><th>Teacher</th><th>Duration</th><th>Passing</th>
                                <th>Active tokens</th><th>Submissions</th><th>Avg</th><th>Status</th><th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {exams.map((e) => (
                                <tr key={e.examId}>
                                    <td>
                                        <strong>{e.name}</strong>
                                        <div style={{ color: 'var(--muted)', fontSize: '0.85rem' }}><code>{e.examId}</code></div>
                                    </td>
                                    <td>{e.owner}</td>
                                    <td>{e.durationMinutes} min</td>
                                    <td>{e.passingGrade}%</td>
                                    <td>{e.activeTokens}</td>
                                    <td>{e.submissions}</td>
                                    <td>{e.avg === null ? '—' : `${e.avg}%`}</td>
                                    <td>
                                        {e.active ? (
                                            <span className="status-item neutral"><CheckCircle2 size={14} aria-hidden /> Active</span>
                                        ) : (
                                            <span className="status-item warning"><CircleX size={14} aria-hidden /> Inactive</span>
                                        )}
                                    </td>
                                    <td><a className="ghost-button" href={`${examsBasePath}/${e.examId}`}>Manage</a></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>
        </AppLayout>
    );
}
