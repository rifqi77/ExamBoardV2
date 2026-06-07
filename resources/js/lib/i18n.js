// Lightweight gettext-style i18n. The English source string IS the key, so
// any string not yet translated gracefully falls back to English. Default
// language is Indonesian (the student audience); a toggle flips to English.
//
//   import { t } from '../lib/i18n';
//   <button>{t('Sign out')}</button>

const id = {
    // ── Navigation / chrome ──
    'Exam Dashboard': 'Dasbor Ujian',
    Workspace: 'Ruang Kerja',
    People: 'Pengguna',
    Manage: 'Kelola',
    System: 'Sistem',
    Overview: 'Ringkasan',
    Teachers: 'Guru',
    Students: 'Siswa',
    Exams: 'Ujian',
    'Question bank': 'Bank Soal',
    Scores: 'Nilai',
    'Pending grading': 'Menunggu Penilaian',
    Reports: 'Laporan',
    Analyze: 'Analisis',
    'AI Generation': 'Pembuatan AI',
    'Learning objectives': 'Tujuan Pembelajaran',
    'AI settings': 'Pengaturan AI',
    'Audit log': 'Log Audit',
    'System health': 'Kesehatan Sistem',
    Home: 'Beranda',
    'Sign out': 'Keluar',
    'Super admin': 'Super Admin',
    Teacher: 'Guru',
    Student: 'Siswa',
    Admin: 'Admin',
    Language: 'Bahasa',

    // ── Login ──
    'Sign in': 'Masuk',
    Username: 'Nama pengguna',
    Password: 'Kata sandi',
    'Signing in…': 'Memproses…',
    'Invalid username or password.': 'Nama pengguna atau kata sandi salah.',
    'Account is deactivated.': 'Akun dinonaktifkan.',
    'Login failed.': 'Gagal masuk.',
    'Sign in with your account to continue.': 'Masuk dengan akun Anda untuk melanjutkan.',

    // ── Student hub ──
    'Take an exam': 'Mulai Ujian',
    'My scores': 'Nilai Saya',
    'Enter the exam token from your teacher.': 'Masukkan token ujian dari guru Anda.',
    'Exam token': 'Token ujian',
    Start: 'Mulai',
    'No scores yet.': 'Belum ada nilai.',

    // ── Exam taking ──
    'Pass mark': 'Nilai lulus',
    answered: 'terjawab',
    monitored: 'dipantau',
    Question: 'Soal',
    of: 'dari',
    'Jump to question': 'Lompat ke soal',
    Prev: 'Sebelumnya',
    Next: 'Berikutnya',
    'Save progress': 'Simpan',
    Saved: 'Tersimpan',
    'Saving…': 'Menyimpan…',
    'Unsaved…': 'Belum tersimpan…',
    'Save failed': 'Gagal menyimpan',
    'Submit exam': 'Kumpulkan Ujian',
    'Submitting…': 'Mengirim…',
    'Submit your exam? You cannot change answers after this.': 'Kumpulkan ujian? Anda tidak dapat mengubah jawaban setelah ini.',
    'Write your answer…': 'Tulis jawaban Anda…',
    'Your answer': 'Jawaban Anda',
    'Loading exam…': 'Memuat ujian…',
    'Exam unavailable': 'Ujian tidak tersedia',
    'Could not load exam.': 'Tidak dapat memuat ujian.',
    'Back to my exams': 'Kembali ke ujian saya',
    'Safe Exam Browser required': 'Safe Exam Browser diperlukan',

    // ── Result ──
    'Your score': 'Nilai Anda',
    Passed: 'Lulus',
    'Not passed': 'Tidak Lulus',
    'By topic': 'Per Topik',
    'Back to home': 'Kembali ke beranda',
    Result: 'Hasil',
    Score: 'Nilai',
    'Essays to grade': 'Esai menunggu penilaian',
    'marked by your teacher': 'dinilai oleh guru Anda',
    'pass mark': 'nilai lulus',
    Topic: 'Topik',
    Correct: 'Benar',
    Submitted: 'Dikumpulkan',

    // ── My exams (hub) ──
    'My exams': 'Ujian Saya',
    'Start an exam or review your past scores.': 'Mulai ujian atau lihat nilai sebelumnya.',
    'Start an exam': 'Mulai Ujian',
    'Enter the exam token your teacher gave you.': 'Masukkan token ujian dari guru Anda.',
    'EXAM TOKEN': 'TOKEN UJIAN',
    'Starting…': 'Memulai…',
    'Invalid token.': 'Token tidak valid.',
    'No exams taken yet.': 'Belum ada ujian.',
    Exam: 'Ujian',
    Status: 'Status',
    View: 'Lihat',
    'Pending grading': 'Menunggu Penilaian',

    // ── Common ──
    Back: 'Kembali',
    Save: 'Simpan',
    Cancel: 'Batal',
    Delete: 'Hapus',
    Active: 'Aktif',
    Inactive: 'Nonaktif',
    'Network error.': 'Kesalahan jaringan.',
    'Loading…': 'Memuat…',
};

const DICTS = { id };

export function getLang() {
    if (typeof window === 'undefined') {
        return 'id';
    }
    return window.localStorage.getItem('lang') || 'id';
}

export function setLang(lang) {
    if (typeof window === 'undefined') {
        return;
    }
    window.localStorage.setItem('lang', lang === 'en' ? 'en' : 'id');
    window.location.reload(); // simplest reliable re-render for a custom i18n
}

/** Translate an English source string; falls back to the source if unmapped. */
export function t(s) {
    const lang = getLang();
    if (lang === 'en') {
        return s;
    }
    return (DICTS[lang] && DICTS[lang][s]) || s;
}
