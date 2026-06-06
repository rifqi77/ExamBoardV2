import { Activity, BookOpenCheck, FileSpreadsheet, ShieldCheck, Users } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';

export default function Dashboard({ stats }) {
    const cards = [
        [ShieldCheck, 'Teachers', stats.teachers, `${stats.activeTeachers} active`],
        [Users, 'Students', stats.students, 'registered learners'],
        [BookOpenCheck, 'Exams', stats.exams, 'across every teacher'],
        [FileSpreadsheet, 'Submissions', stats.submissions, 'completed attempts'],
        [Activity, 'Pending grading', stats.pendingGrading, 'essays awaiting marks'],
    ];
    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div>
                    <h1>Overview</h1>
                    <p>System totals across every teacher account.</p>
                </div>
            </header>
            <div className="admin-metrics admin-metrics--3col">
                {cards.map(([Icon, title, value, sub]) => (
                    <div className="admin-panel metric-card" key={title}>
                        <Icon size={18} aria-hidden />
                        <span>{title}</span>
                        <strong>{value}</strong>
                        <small className="metric-card-sub">{sub}</small>
                    </div>
                ))}
            </div>
        </AppLayout>
    );
}
