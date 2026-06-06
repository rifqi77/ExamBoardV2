import { BookOpenCheck, FileSpreadsheet, ShieldCheck, Users } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';

export default function Analyze({ system, perTeacher }) {
    const cards = [
        [ShieldCheck, 'Teachers', system.teacherCount, `${system.activeTeacherCount} active`],
        [Users, 'Students', system.studentCount, null],
        [BookOpenCheck, 'Exams', system.examCount, null],
        [FileSpreadsheet, 'Submissions', system.submissionCount, null],
    ];
    return (
        <AppLayout>
            <header className="teacher-page-header"><div><h1>Analyze</h1><p>School-wide activity across every teacher.</p></div></header>
            <div className="admin-metrics admin-metrics--3col">
                {cards.map(([Icon, label, value, sub]) => (
                    <div className="admin-panel metric-card" key={label}>
                        <Icon size={18} aria-hidden /><span>{label}</span><strong>{value}</strong>
                        {sub ? <small className="metric-card-sub">{sub}</small> : null}
                    </div>
                ))}
            </div>
            <section className="admin-panel">
                <div className="section-title-row"><div><h2>By teacher</h2><p style={{ margin: 0, color: 'var(--muted)' }}>Sorted by submissions.</p></div></div>
                <table className="dashboard-table">
                    <thead><tr><th>Teacher</th><th>Subject</th><th>Exams</th><th>Students</th><th>Submissions</th><th>Avg %</th><th>Status</th></tr></thead>
                    <tbody>
                        {perTeacher.map((t, i) => (
                            <tr key={i}>
                                <td><strong>{t.name}</strong></td>
                                <td>{t.subject || '—'}</td>
                                <td>{t.exams}</td><td>{t.students}</td><td>{t.submissions}</td>
                                <td>{t.avgPercent ?? '—'}</td>
                                <td>{t.active ? 'Active' : 'Inactive'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
        </AppLayout>
    );
}
