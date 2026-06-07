import { Link, usePage } from '@inertiajs/react';
import { getLang, setLang, t } from '../lib/i18n';
import { Activity, BarChart3, BookOpenCheck, Clock, FileText, Gauge, HeartPulse, Home, Library, LogOut, ScrollText, Settings, ShieldCheck, Sparkles, Target, Users } from 'lucide-react';

// Role nav. Only built routes are listed for now; grows per module.
const NAV = {
    admin: [
        { label: 'Workspace', items: [['Overview', '/admin', Home]] },
        { label: 'People', items: [
            ['Teachers', '/admin/teachers', ShieldCheck],
            ['Students', '/admin/students', Users],
        ] },
        { label: 'Manage', items: [
            ['Exams', '/admin/exams', BookOpenCheck],
            ['Question bank', '/admin/bank', Library],
            ['Scores', '/admin/scores', BarChart3],
            ['Pending grading', '/admin/pending-score', Clock],
            ['Reports', '/admin/reports', FileText],
            ['Mastery', '/admin/mastery', Gauge],
            ['Analyze', '/admin/analyze', Activity],
            ['AI Generation', '/admin/ai-generate', Sparkles],
            ['Learning objectives', '/admin/learning-objectives', Target],
        ] },
        { label: 'System', items: [
            ['AI settings', '/admin/ai-settings', Settings],
            ['Audit log', '/admin/audit', ScrollText],
            ['System health', '/admin/system', HeartPulse],
        ] },
    ],
    teacher: [
        { label: 'Workspace', items: [['Overview', '/teacher', Home]] },
        { label: 'Manage', items: [
            ['Students', '/teacher/students', Users],
            ['Exams', '/teacher/exams', BookOpenCheck],
            ['Question bank', '/teacher/bank', Library],
            ['Scores', '/teacher/scores', BarChart3],
            ['Pending grading', '/teacher/pending-score', Clock],
            ['Reports', '/teacher/reports', FileText],
            ['Mastery', '/teacher/mastery', Gauge],
            ['AI Generation', '/teacher/ai-generate', Sparkles],
            ['Learning objectives', '/teacher/learning-objectives', Target],
        ] },
    ],
    student: [
        { label: 'Workspace', items: [['Home', '/student', Home]] },
    ],
};
const ROLE_TITLE = { admin: 'Super admin', teacher: 'Teacher', student: 'Student' };
const ROLE_PILL = { admin: 'Admin', teacher: 'Teacher', student: 'Student' };

export default function AppLayout({ children }) {
    const { auth } = usePage().props;
    const user = auth?.user;
    const role = user?.role ?? 'student';
    const groups = NAV[role] ?? [];
    const path = typeof window !== 'undefined' ? window.location.pathname : '';
    const impersonating = auth?.impersonating;

    async function stopImpersonation() {
        try {
            await fetch('/api/admin/impersonate/stop', { method: 'POST', headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        } catch { /* ignore */ }
        window.location.href = '/admin';
    }

    async function onLogout() {
        try {
            await fetch('/api/auth/logout', {
                method: 'POST',
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
        } catch {
            /* ignore */
        }
        window.location.href = '/login';
    }

    return (
        <div className="teacher-shell">
            <aside className="teacher-sidebar">
                <div className="teacher-sidebar-brand">
                    <div className="brand-mark">
                        <ShieldCheck size={18} aria-hidden />
                    </div>
                    <div>
                        <strong>{t('Exam Dashboard')}</strong>
                        <p>{t(ROLE_TITLE[role])}</p>
                    </div>
                </div>

                <nav className="teacher-nav">
                    {groups.map((g) => (
                        <div className="nav-group" key={g.label}>
                            <span className="nav-group-label">{t(g.label)}</span>
                            {g.items.map(([label, href, Icon]) => (
                                <Link key={href} href={href} className={path === href ? 'active' : ''}>
                                    <Icon size={17} aria-hidden />
                                    {t(label)}
                                </Link>
                            ))}
                        </div>
                    ))}
                </nav>

                <div className="teacher-sidebar-footer">
                    <div className="teacher-user-card">
                        <strong>{user?.fullName}</strong>
                        <span>{user?.username}</span>
                        <span className={`teacher-subject-pill ${role}`}>{t(ROLE_PILL[role])}</span>
                    </div>
                    <button className="ghost-button" type="button" onClick={onLogout}>
                        <LogOut size={15} aria-hidden /> {t('Sign out')}
                    </button>
                    <div style={{ display: 'flex', gap: 8, marginTop: 8, fontSize: 12, alignItems: 'center' }}>
                        <span style={{ color: 'var(--muted)' }}>{t('Language')}:</span>
                        <button type="button" className="inline-link-button" onClick={() => setLang('id')} style={{ fontWeight: getLang() === 'id' ? 700 : 400 }}>ID</button>
                        <button type="button" className="inline-link-button" onClick={() => setLang('en')} style={{ fontWeight: getLang() === 'en' ? 700 : 400 }}>EN</button>
                    </div>
                </div>
            </aside>

            <main className="teacher-main">
                {impersonating ? (
                    <div style={{ background: '#fef3c7', border: '1px solid #f59e0b', borderRadius: 8, padding: '8px 12px', marginBottom: 14, display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                        <span>👤 Impersonating <strong>{user?.fullName}</strong> — actions are performed as this teacher.</span>
                        <button className="ghost-button" type="button" onClick={stopImpersonation}>Return to admin</button>
                    </div>
                ) : null}
                {children}
            </main>
        </div>
    );
}
