// Client-side .xlsx export of the report matrix (ExcelJS, dynamically
// imported so it's code-split).
export async function downloadReportExcel(exams, classes) {
    const ExcelJS = (await import('exceljs')).default;
    const wb = new ExcelJS.Workbook();
    const ws = wb.addWorksheet('Report');
    ws.addRow(['Class', 'Student', 'Username', ...exams.map((e) => e.examId), 'Average %', 'Passed/Taken', 'Strongest', 'Weakest']);
    classes.forEach((cls) =>
        cls.students.forEach((s) => {
            ws.addRow([
                cls.className,
                s.studentName,
                s.username,
                ...exams.map((e) => {
                    const c = s.perExam[e.examDatabaseId];
                    return c ? (c.status === 'pending_grading' ? 'pending' : c.percent) : '';
                }),
                s.averagePercent ?? '',
                `${s.examsPassed}/${s.examsTaken}`,
                s.strongestTopic ?? '',
                s.weakestTopic ?? '',
            ]);
        })
    );
    ws.getRow(1).font = { bold: true };
    const buf = await wb.xlsx.writeBuffer();
    const blob = new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'exam-report.xlsx';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
