export default function Dashboard({ user, stats }) {
    const cards = [
        ['Teachers', stats.teachers],
        ['Students', stats.students],
        ['Exams', stats.exams],
        ['Submissions', stats.submissions],
    ];
    return (
        <div style={{ fontFamily: 'system-ui, sans-serif', padding: 32, maxWidth: 880, margin: '0 auto' }}>
            <h1 style={{ fontSize: 24, marginBottom: 4 }}>
                ExamBoard — Admin Overview
            </h1>
            <p style={{ color: '#666', marginTop: 0 }}>
                Laravel + Inertia + React, reading the live <code>secure_exam</code> DB.
                Signed in as <strong>{user.fullName}</strong> ({user.role}).
            </p>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 16, marginTop: 24 }}>
                {cards.map(([label, value]) => (
                    <div key={label} style={{ border: '1px solid #e2e2e2', borderRadius: 10, padding: 20 }}>
                        <div style={{ color: '#777', fontSize: 13 }}>{label}</div>
                        <div style={{ fontSize: 34, fontWeight: 700 }}>{value}</div>
                    </div>
                ))}
            </div>
        </div>
    );
}
