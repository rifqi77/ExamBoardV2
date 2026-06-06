// Port of src/lib/excel-parser.ts — parse an .xlsx roster client-side.
// Each sheet = one class (sheet name = class name); rows = students with
// columns [full name, username, password]; a header row is skipped when
// the first cell looks like a header.
const HEADER_HINTS = [
    'name', 'nama', 'full name', 'fullname', 'student', 'siswa', 'nama siswa', 'nama lengkap',
];

function cellText(cell) {
    if (cell.text !== undefined && cell.text !== null) return String(cell.text);
    if (cell.value !== undefined && cell.value !== null) {
        if (typeof cell.value === 'object' && cell.value !== null && 'text' in cell.value) {
            return String(cell.value.text ?? '');
        }
        return String(cell.value);
    }
    return '';
}

// Parse a learning-objectives .xlsx. Columns (header-aliased, EN + ID):
// Topic | Subtopic | Objective text | Subject | Curriculum | Language.
// Header row is detected and skipped. Each sheet name is used as a
// fallback curriculum when the curriculum column is blank.
const LO_HEADERS = {
    topic: ['topic', 'topik', 'materi'],
    subtopic: ['subtopic', 'sub topic', 'subtopik', 'sub-topik'],
    text: ['objective', 'objektif', 'learning objective', 'tujuan', 'capaian', 'description', 'text'],
    subject: ['subject', 'mapel', 'mata pelajaran', 'pelajaran'],
    curriculum: ['curriculum', 'kurikulum'],
    language: ['language', 'bahasa'],
};

function matchHeader(headerCells) {
    // returns column index map {field: colIndex} based on header row text
    const map = {};
    headerCells.forEach((txt, i) => {
        const low = txt.trim().toLowerCase();
        for (const [field, hints] of Object.entries(LO_HEADERS)) {
            if (map[field] === undefined && hints.some((h) => low === h || low.includes(h))) {
                map[field] = i + 1; // ExcelJS columns are 1-based
            }
        }
    });
    return map;
}

export async function parseLearningObjectives(file) {
    const ExcelJS = (await import('exceljs')).default;
    const buffer = await file.arrayBuffer();
    const workbook = new ExcelJS.Workbook();
    await workbook.xlsx.load(buffer);

    const rows = [];
    workbook.eachSheet((sheet) => {
        const headerCells = [];
        const hr = sheet.getRow(1);
        hr.eachCell({ includeEmpty: true }, (cell) => headerCells.push(cellText(cell)));
        const map = matchHeader(headerCells);
        const hasHeader = Object.keys(map).length >= 2;
        // Fallback positional columns if no header detected.
        const col = {
            topic: map.topic ?? 1, subtopic: map.subtopic ?? 2, text: map.text ?? 3,
            subject: map.subject ?? null, curriculum: map.curriculum ?? null, language: map.language ?? null,
        };
        let lastTopic = '';
        let lastSubtopic = '';
        sheet.eachRow({ includeEmpty: false }, (row, rowNumber) => {
            if (hasHeader && rowNumber === 1) return;
            const topic = cellText(row.getCell(col.topic)).trim() || lastTopic; // inherit merged-cell topic
            const subtopic = (col.subtopic ? cellText(row.getCell(col.subtopic)).trim() : '') || (topic === lastTopic ? lastSubtopic : '');
            const text = col.text ? cellText(row.getCell(col.text)).trim() : '';
            if (!topic && !text) return;
            lastTopic = topic; lastSubtopic = subtopic;
            rows.push({
                topic, subtopic, text,
                subject: col.subject ? cellText(row.getCell(col.subject)).trim() : '',
                curriculum: col.curriculum ? cellText(row.getCell(col.curriculum)).trim() : sheet.name.trim(),
                language: col.language ? cellText(row.getCell(col.language)).trim() : '',
            });
        });
    });
    return rows;
}

export async function parseClassesFromExcel(file) {
    const ExcelJS = (await import('exceljs')).default;
    const buffer = await file.arrayBuffer();
    const workbook = new ExcelJS.Workbook();
    await workbook.xlsx.load(buffer);

    const classes = [];
    workbook.eachSheet((sheet) => {
        const sheetName = sheet.name.trim();
        if (!sheetName) return;
        const students = [];
        const first = cellText(sheet.getRow(1).getCell(1)).trim().toLowerCase();
        const skipFirst = HEADER_HINTS.some((h) => first.includes(h));
        sheet.eachRow({ includeEmpty: false }, (row, rowNumber) => {
            if (skipFirst && rowNumber === 1) return;
            const fullName = cellText(row.getCell(1)).trim();
            if (!fullName) return;
            const username = cellText(row.getCell(2)).trim();
            const password = cellText(row.getCell(3)).trim();
            students.push({ fullName, username, password });
        });
        if (students.length > 0) classes.push({ name: sheetName, students });
    });
    return classes;
}
