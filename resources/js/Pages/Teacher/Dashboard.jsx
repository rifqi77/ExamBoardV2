import { BookOpenCheck, FileSpreadsheet, PenLine, Users } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';

export default function Dashboard({ stats }) {
    const cards = [
        [BookOpenCheck, 'My exams', stats.exams, 'created by you'],
        [Users, 'My students', stats.students, 'on your rosters'],
        [FileSpreadsheet, 'Submissions', stats.submissions, 'completed attempts'],
        [PenLine, 'Pending grading', stats.pendingGrading, 'essays awaiting marks'],
    ];
    return (
        <AppLayout>
            <header className="teacher-page-header">
                <div>
                    <h1>Overview</h1>
                    <p>Your exams, students, and grading queue.</p>
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
