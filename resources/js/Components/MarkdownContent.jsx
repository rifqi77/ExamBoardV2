import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import remarkMath from 'remark-math';
import rehypeKatex from 'rehype-katex';
import 'katex/dist/katex.min.css';

// Renders question prompts / options / explanations / answers as GitHub-flavored
// markdown with KaTeX math ($…$ and $$…$$) and tables — the Cambridge-style
// content the original app authored. Raw HTML is intentionally not enabled.
//
// `inline` renders without the block <p> wrapper (paragraphs become <span>),
// so it can be dropped inside labels, table cells, and answer lines without
// breaking the surrounding layout.
const INLINE_COMPONENTS = { p: ({ node, ...props }) => <span {...props} /> };

export default function MarkdownContent({ children, className, inline = false }) {
    const cls = className ? `markdown-content ${className}` : 'markdown-content';
    const md = (
        <ReactMarkdown
            remarkPlugins={[remarkGfm, remarkMath]}
            rehypePlugins={[rehypeKatex]}
            components={inline ? INLINE_COMPONENTS : undefined}
        >
            {children || ''}
        </ReactMarkdown>
    );

    return inline ? <span className={cls}>{md}</span> : <div className={cls}>{md}</div>;
}
