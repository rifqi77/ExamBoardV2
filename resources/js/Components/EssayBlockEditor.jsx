// Block-based essay answer editor — ported from the original Next.js app
// (src/components/exam/EssayBlockEditor.tsx). Alternating text + math blocks;
// math is entered with MathLive's <math-field> + virtual keyboard. The whole
// answer serializes to ONE string with $$...$$ around math blocks (markdown +
// KaTeX compatible), so autosave/draft-restore and the shared DB are unchanged.

import { Sigma, Type, X } from 'lucide-react';
import { createElement, useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';

export function parseBlocks(s) {
    const blocks = [];
    let remaining = String(s ?? '');
    while (remaining) {
        const start = remaining.indexOf('$$');
        if (start === -1) {
            blocks.push({ type: 'text', value: remaining });
            break;
        }
        if (start > 0) blocks.push({ type: 'text', value: remaining.slice(0, start) });
        const after = remaining.slice(start + 2);
        const end = after.indexOf('$$');
        if (end === -1) {
            blocks.push({ type: 'math', value: after });
            break;
        }
        blocks.push({ type: 'math', value: after.slice(0, end) });
        remaining = after.slice(end + 2);
    }
    if (blocks.length === 0) blocks.push({ type: 'text', value: '' });
    return blocks;
}

export function serializeBlocks(blocks) {
    return blocks.map((b) => (b.type === 'math' ? `$$${b.value}$$` : b.value)).join('');
}

const toolbarBtn = { display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 13, padding: '4px 10px', border: '1px solid #d4d4d8', borderRadius: 6, background: '#fff', cursor: 'pointer' };

export function EssayBlockEditor({ value, onChange, readOnly, ariaLabel }) {
    const [blocks, setBlocks] = useState(() => parseBlocks(value));
    const [pendingFocus, setPendingFocus] = useState(null);
    const editorId = useId();
    const lastSyncedRef = useRef(serializeBlocks(blocks));

    useEffect(() => {
        if (readOnly) return;
        void import('mathlive');
    }, [readOnly]);

    // Downward sync — re-parse only when the parent feeds a new string.
    useEffect(() => {
        if (value !== lastSyncedRef.current) {
            lastSyncedRef.current = value;
            setBlocks(parseBlocks(value));
        }
    }, [value]);

    // Upward sync — after commit, emit once per real change.
    useEffect(() => {
        const serialized = serializeBlocks(blocks);
        if (serialized !== lastSyncedRef.current) {
            lastSyncedRef.current = serialized;
            onChange(serialized);
        }
    }, [blocks, onChange]);

    const setBlockValue = useCallback((index, v) => {
        setBlocks((cur) => cur.map((b, i) => (i === index ? { ...b, value: v } : b)));
    }, []);
    const removeBlock = useCallback((index) => {
        setBlocks((cur) => {
            let next = cur.filter((_, i) => i !== index);
            if (next.length === 0) next = [{ type: 'text', value: '' }];
            return next;
        });
    }, []);
    const addMathBlock = useCallback(() => {
        setBlocks((cur) => {
            const next = [...cur, { type: 'math', value: '' }];
            setPendingFocus(next.length - 1);
            return next;
        });
    }, []);
    const addTextBlock = useCallback(() => {
        setBlocks((cur) => {
            const next = [...cur, { type: 'text', value: '' }];
            setPendingFocus(next.length - 1);
            return next;
        });
    }, []);

    return (
        <div role="group" aria-label={ariaLabel ?? 'Essay answer editor'} style={{ border: '1px solid #e4e4e7', borderRadius: 8, padding: 10, background: '#fff' }}>
            {!readOnly ? (
                <div role="toolbar" style={{ display: 'flex', gap: 8, marginBottom: 8 }}>
                    <button type="button" style={{ ...toolbarBtn, fontWeight: 600 }} onClick={addMathBlock} aria-label="Add equation">
                        <Sigma size={14} aria-hidden /> Add equation
                    </button>
                    <button type="button" style={toolbarBtn} onClick={addTextBlock} aria-label="Continue text">
                        <Type size={14} aria-hidden /> Continue text
                    </button>
                </div>
            ) : null}

            <div style={{ display: 'grid', gap: 8 }}>
                {blocks.map((block, index) =>
                    block.type === 'text' ? (
                        <TextBlockRow
                            key={`${editorId}-${index}`}
                            value={block.value}
                            readOnly={!!readOnly}
                            onChange={(next) => setBlockValue(index, next)}
                            onRemove={blocks.length > 1 ? () => removeBlock(index) : null}
                            shouldFocus={pendingFocus === index}
                            onFocusHandled={() => setPendingFocus(null)}
                        />
                    ) : (
                        <MathBlockRow
                            key={`${editorId}-${index}`}
                            value={block.value}
                            readOnly={!!readOnly}
                            onChange={(next) => setBlockValue(index, next)}
                            onRemove={() => removeBlock(index)}
                            shouldFocus={pendingFocus === index}
                            onFocusHandled={() => setPendingFocus(null)}
                        />
                    )
                )}
            </div>
        </div>
    );
}

function TextBlockRow({ value, readOnly, onChange, onRemove, shouldFocus, onFocusHandled }) {
    const ref = useRef(null);
    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = `${el.scrollHeight}px`;
    }, [value]);
    useEffect(() => {
        if (!shouldFocus) return;
        const el = ref.current;
        if (!el) return;
        el.focus();
        const len = el.value.length;
        el.setSelectionRange(len, len);
        onFocusHandled();
    }, [shouldFocus, onFocusHandled]);
    return (
        <div style={{ display: 'flex', gap: 6, alignItems: 'flex-start' }}>
            <textarea
                ref={ref}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder="Type your explanation…"
                rows={2}
                disabled={readOnly}
                style={{ flex: 1, width: '100%', boxSizing: 'border-box', resize: 'vertical', minHeight: 40 }}
            />
            {onRemove && !readOnly ? (
                <button type="button" onClick={onRemove} aria-label="Remove text block" title="Remove this block" style={{ border: 'none', background: 'transparent', cursor: 'pointer', color: '#a1a1aa', padding: 4 }}>
                    <X size={14} aria-hidden />
                </button>
            ) : null}
        </div>
    );
}

function MathBlockRow({ value, readOnly, onChange, onRemove, shouldFocus, onFocusHandled }) {
    const ref = useRef(null);
    const loadedRef = useRef(false);
    const onChangeRef = useRef(onChange);
    useEffect(() => { onChangeRef.current = onChange; }, [onChange]);

    useEffect(() => {
        if (loadedRef.current) return;
        loadedRef.current = true;
        void import('mathlive');
    }, []);

    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        const onInput = (event) => {
            const target = event.target;
            const latex =
                typeof target.getValue === 'function' ? target.getValue('latex')
                    : typeof target.value === 'string' ? target.value : '';
            onChangeRef.current(latex);
        };
        // Suppress MathLive's built-in right-click menu (clutter for a student field).
        const onContextMenu = (e) => { e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation(); };
        const onKeyDown = (e) => {
            if ((e.shiftKey && e.key === 'F10') || e.key === 'ContextMenu' || ((e.metaKey || e.ctrlKey) && e.key === '\\')) {
                e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
            }
        };
        const clearMenuItems = () => { try { el.menuItems = []; } catch { /* not ready */ } };
        const setupKeyboardLayouts = () => {
            try {
                const mvk = window.mathVirtualKeyboard;
                if (!mvk) return;
                mvk.layouts = ['numeric', 'symbols', 'greek'];
                if (typeof mvk.setKeycap === 'function') {
                    mvk.setKeycap('[left]', { latex: 'x_n', insert: '_{#?}', tooltip: 'Subscript' });
                    mvk.setKeycap('[right]', { latex: '\\sum_{n}^{N}', insert: '\\sum_{#?}^{#?}', tooltip: 'Sigma (summation)' });
                    mvk.setKeycap('[return]', { latex: '\\frac{a}{b}', insert: '\\frac{#?}{#?}', tooltip: 'Fraction / divide' });
                }
            } catch { /* mathlive global not ready */ }
        };
        el.addEventListener('input', onInput);
        el.addEventListener('contextmenu', onContextMenu, { capture: true });
        el.addEventListener('keydown', onKeyDown, { capture: true });
        el.addEventListener('focusin', clearMenuItems);
        clearMenuItems();
        setupKeyboardLayouts();
        queueMicrotask(setupKeyboardLayouts);
        const lateRetry = window.setTimeout(setupKeyboardLayouts, 50);
        return () => {
            el.removeEventListener('input', onInput);
            el.removeEventListener('contextmenu', onContextMenu, { capture: true });
            el.removeEventListener('keydown', onKeyDown, { capture: true });
            el.removeEventListener('focusin', clearMenuItems);
            window.clearTimeout(lateRetry);
        };
    }, []);

    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        const current = typeof el.getValue === 'function' ? el.getValue('latex') : el.value ?? '';
        if (current === value) return;
        if (typeof el.setValue === 'function') el.setValue(value, { silenceNotifications: true });
        else el.value = value;
    }, [value]);

    useEffect(() => {
        if (!shouldFocus) return;
        const el = ref.current;
        if (!el) return;
        if (typeof el.focus === 'function') el.focus();
        onFocusHandled();
    }, [shouldFocus, onFocusHandled]);

    const mathFieldEl = useMemo(
        () => createElement('math-field', { ref, 'aria-label': 'Math expression', style: { flex: 1, minWidth: 0, border: '1px solid #d4d4d8', borderRadius: 6, padding: '4px 8px' }, ...(readOnly ? { 'read-only': '' } : {}) }),
        [readOnly]
    );

    return (
        <div style={{ display: 'flex', gap: 6, alignItems: 'center', background: '#f8fafc', borderRadius: 6, padding: 4 }}>
            {mathFieldEl}
            {!readOnly ? (
                <button type="button" onClick={onRemove} aria-label="Remove math block" title="Remove this equation" style={{ border: 'none', background: 'transparent', cursor: 'pointer', color: '#a1a1aa', padding: 4 }}>
                    <X size={14} aria-hidden />
                </button>
            ) : null}
        </div>
    );
}
