import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import remarkMath from 'remark-math';
import rehypeKatex from 'rehype-katex';
import 'katex/dist/katex.min.css';

// Renders question prompts / explanations as GitHub-flavored markdown with
// KaTeX math ($…$ and $$…$$) and tables — the Cambridge-style content the
// original app authored. Raw HTML is intentionally not enabled.
export default function MarkdownContent({ children, className }) {
    return (
        <div className={className ? `markdown-content ${className}` : 'markdown-content'}>
            <ReactMarkdown remarkPlugins={[remarkGfm, remarkMath]} rehypePlugins={[rehypeKatex]}>
                {children || ''}
            </ReactMarkdown>
        </div>
    );
}
