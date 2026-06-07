// Helpers for multi-part essay questions — ported verbatim from the original
// Next.js app (src/lib/sub-questions.ts) so the stored answer format is
// byte-compatible with the shared database.
//
// Detection: sub-parts are lines beginning with `(a)`..`(z)` in the prompt.
// Storage: a single markdown string with `### (a)`, `### (b)` … section
// headings — survives the string answer type and renders in the grading panel
// via MarkdownContent.

const SUB_PART_RE =
    /^\s*(?:[-*+]\s+|\d+\.\s+)?(?:[*_]{1,3})?\(([a-z])\)(?:[*_]{1,3})?\s+(.*)$/;

/** Detect sub-parts in a question prompt → [{id, prompt}]; empty if none. */
export function parseSubParts(prompt) {
    const lines = String(prompt ?? '').split(/\r?\n/);
    const parts = [];
    let current = null;
    for (const line of lines) {
        const m = line.match(SUB_PART_RE);
        if (m) {
            if (current) parts.push(current);
            current = { id: m[1], prompt: m[2] };
        } else if (current) {
            current.prompt += '\n' + line;
        }
    }
    if (current) parts.push(current);
    for (const p of parts) p.prompt = p.prompt.trim();
    return parts;
}

const SUB_ANSWER_RE = /^###\s*\(([a-z])\)\s*$/;

/** Parse a saved per-sub-part answer → { partId: content }, or null if flat. */
export function parseSubAnswers(value) {
    const lines = String(value ?? '').split(/\r?\n/);
    if (!lines.some((l) => SUB_ANSWER_RE.test(l))) return null;
    const out = {};
    let currentKey = null;
    let buf = [];
    const flush = () => {
        if (currentKey !== null) out[currentKey] = buf.join('\n').trim();
    };
    for (const line of lines) {
        const m = line.match(SUB_ANSWER_RE);
        if (m) {
            flush();
            currentKey = m[1];
            buf = [];
        } else if (currentKey !== null) {
            buf.push(line);
        }
    }
    flush();
    return out;
}

/** Serialize sub-answers into the storage string, preserving partIds order. */
export function serializeSubAnswers(answers, partIds) {
    return partIds.map((id) => `### (${id})\n\n${answers[id] ?? ''}`).join('\n\n');
}
