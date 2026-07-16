import { AnimatePresence, motion } from 'framer-motion';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
    type HTMLAttributes,
    type KeyboardEvent as ReactKeyboardEvent,
} from 'react';

export type AssetMention = {
    alias: `@${string}`;
    kind: 'image' | 'video' | 'audio';
    name: string;
    previewUrl?: string | null;
};

/** Keep positional aliases correct when an asset is removed from the middle. */
export function rebasePromptAfterAssetRemoval(
    value: string,
    kind: AssetMention['kind'],
    removedIndex: number,
): string {
    const pattern = new RegExp(`@${kind}([1-9]\\d*)\\b`, 'gi');
    return value
        .replace(pattern, (alias, rawIndex: string) => {
            const index = Number(rawIndex);
            if (index === removedIndex) return '';
            if (index > removedIndex) return `@${kind}${index - 1}`;
            return alias;
        })
        .replace(/[ \t]{2,}/g, ' ');
}

type Props = Omit<HTMLAttributes<HTMLDivElement>, 'onChange' | 'onInput' | 'value' | 'children' | 'dangerouslySetInnerHTML'> & {
    value: string;
    onChange: (value: string) => void;
    mentions: AssetMention[];
    maxLength?: number;
    minRows?: number;
    maxRows?: number;
    /** Alias for minRows (textarea-compat). */
    rows?: number;
    placeholder?: string;
    disabled?: boolean;
    autoFocus?: boolean;
};

type ActiveToken = {
    query: string;
    /** Text node where `@query` lives */
    textNode: Text;
    startOffset: number;
    endOffset: number;
};

const MENTION_RE = /@(?:image|video|audio)[1-9]\d*\b/gi;
const MENTION_TOKEN_RE = /^@(?:image|video|audio)[1-9]\d*$/i;
const MENTION_SPLIT = /(@(?:image|video|audio)[1-9]\d*\b)/gi;

const CHIP_CLASS =
    'asset-mention-chip mx-0.5 inline-flex max-w-[12rem] translate-y-px items-center gap-1 rounded-md bg-[#FF5733]/18 py-0.5 pe-1.5 ps-1 align-baseline text-[12px] font-semibold leading-5 text-orange-100 ring-1 ring-[#FF5733]/35 select-none';

function KindIcon({ kind, className = 'h-3 w-3' }: { kind: AssetMention['kind']; className?: string }) {
    if (kind === 'audio') {
        return (
            <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8">
                <path d="M9 18V5l12-2v13" />
                <circle cx="6" cy="18" r="3" />
                <circle cx="18" cy="16" r="3" />
            </svg>
        );
    }
    if (kind === 'video') {
        return (
            <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8">
                <rect x="3" y="5" width="14" height="14" rx="2" />
                <path d="m17 10 4-2v8l-4-2" />
            </svg>
        );
    }
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8">
            <rect x="3" y="3" width="18" height="18" rx="2" />
            <circle cx="9" cy="9" r="2" />
            <path d="m21 15-4-4L5 21" />
        </svg>
    );
}

function kindFromAlias(alias: string): AssetMention['kind'] {
    if (/^@video/i.test(alias)) return 'video';
    if (/^@audio/i.test(alias)) return 'audio';
    return 'image';
}

function serializeEditor(root: HTMLElement): string {
    let out = '';

    const walk = (node: Node) => {
        if (node.nodeType === Node.TEXT_NODE) {
            out += node.textContent ?? '';
            return;
        }
        if (node.nodeType !== Node.ELEMENT_NODE) return;
        const el = node as HTMLElement;
        if (el.dataset.mention) {
            out += el.dataset.mention;
            return;
        }
        if (el.tagName === 'BR') {
            out += '\n';
            return;
        }
        const isBlock = el.tagName === 'DIV' || el.tagName === 'P';
        if (isBlock && out.length > 0 && !out.endsWith('\n')) {
            // Avoid leading newline from first block wrapper browsers insert.
            const isFirst = el === root.firstChild;
            if (!isFirst) out += '\n';
        }
        el.childNodes.forEach(walk);
    };

    root.childNodes.forEach(walk);
    return out.replace(/\u00a0/g, ' ');
}

function createChipElement(alias: string, mention?: AssetMention | null): HTMLSpanElement {
    const kind = mention?.kind ?? kindFromAlias(alias);
    const chip = document.createElement('span');
    chip.contentEditable = 'false';
    chip.dataset.mention = alias;
    chip.dataset.kind = kind;
    chip.className = CHIP_CLASS;
    chip.setAttribute('title', mention?.name || alias);

    const media = document.createElement('span');
    media.className = 'inline-flex h-4 w-4 shrink-0 items-center justify-center overflow-hidden rounded-[4px] bg-black/35 text-orange-200';

    if (mention?.previewUrl && (kind === 'image' || kind === 'video')) {
        if (kind === 'image') {
            const img = document.createElement('img');
            img.src = mention.previewUrl;
            img.alt = '';
            img.className = 'h-full w-full object-cover';
            media.appendChild(img);
        } else {
            const vid = document.createElement('video');
            vid.src = mention.previewUrl;
            vid.muted = true;
            vid.className = 'h-full w-full object-cover';
            media.appendChild(vid);
        }
    } else {
        media.innerHTML =
            kind === 'audio'
                ? '<svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>'
                : kind === 'video'
                  ? '<svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="14" height="14" rx="2"/><path d="m17 10 4-2v8l-4-2"/></svg>'
                  : '<svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-4-4L5 21"/></svg>';
    }

    const label = document.createElement('span');
    label.className = 'truncate';
    label.textContent = alias;

    chip.appendChild(media);
    chip.appendChild(label);
    return chip;
}

function renderValueToEditor(root: HTMLElement, value: string, mentions: AssetMention[]) {
    root.replaceChildren();
    if (!value) {
        return;
    }

    const byAlias = new Map(mentions.map((m) => [m.alias.toLowerCase(), m]));
    const parts = value.split(MENTION_SPLIT);

    for (const part of parts) {
        if (!part) continue;
        if (MENTION_TOKEN_RE.test(part)) {
            root.appendChild(createChipElement(part, byAlias.get(part.toLowerCase()) ?? null));
        } else {
            // Preserve newlines as <br> so contenteditable layout matches.
            const lines = part.split('\n');
            lines.forEach((line, index) => {
                if (line) root.appendChild(document.createTextNode(line));
                if (index < lines.length - 1) root.appendChild(document.createElement('br'));
            });
        }
    }
}

function getMentionTokenAtCaret(): ActiveToken | null {
    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0 || !selection.isCollapsed) return null;
    const range = selection.getRangeAt(0);
    const node = range.startContainer;
    if (node.nodeType !== Node.TEXT_NODE) return null;

    const textNode = node as Text;
    const text = textNode.textContent ?? '';
    const caret = range.startOffset;
    const before = text.slice(0, caret);
    const match = before.match(/(?:^|\s)@([a-z0-9_-]*)$/i);
    if (!match) return null;

    const query = match[1] ?? '';
    const startOffset = caret - query.length - 1;
    return {
        query: query.toLowerCase(),
        textNode,
        startOffset,
        endOffset: caret,
    };
}

function placeCaretAfter(node: Node) {
    const selection = window.getSelection();
    if (!selection) return;
    const range = document.createRange();
    range.setStartAfter(node);
    range.collapse(true);
    selection.removeAllRanges();
    selection.addRange(range);
}

function placeCaretAtEnd(el: HTMLElement) {
    const selection = window.getSelection();
    if (!selection) return;
    const range = document.createRange();
    range.selectNodeContents(el);
    range.collapse(false);
    selection.removeAllRanges();
    selection.addRange(range);
}

export default function AssetMentionTextarea({
    value,
    onChange,
    mentions,
    maxLength,
    minRows = 3,
    maxRows = 14,
    rows,
    placeholder,
    disabled,
    autoFocus,
    className,
    style,
    onKeyDown,
    onBlur,
    onFocus,
    onClick,
    ...props
}: Props) {
    const editorRef = useRef<HTMLDivElement>(null);
    const lastEmittedRef = useRef(value);
    const [focused, setFocused] = useState(false);
    const [token, setToken] = useState<ActiveToken | null>(null);
    const [activeIndex, setActiveIndex] = useState(0);
    const [menuPos, setMenuPos] = useState<{ top: number; left: number } | null>(null);
    const effectiveMinRows = rows ?? minRows;

    const filtered = useMemo(() => {
        if (!token) return [];
        return mentions.filter((mention) => mention.alias.slice(1).toLowerCase().includes(token.query));
    }, [mentions, token]);

    const mentionedAliases = useMemo(() => {
        const found = value.match(MENTION_RE) ?? [];
        const unique: string[] = [];
        for (const alias of found) {
            if (!unique.some((item) => item.toLowerCase() === alias.toLowerCase())) unique.push(alias);
        }
        return unique;
    }, [value]);

    const emitChange = useCallback(() => {
        const root = editorRef.current;
        if (!root) return;
        let next = serializeEditor(root);
        if (maxLength && next.length > maxLength) {
            next = next.slice(0, maxLength);
            renderValueToEditor(root, next, mentions);
            placeCaretAtEnd(root);
        }
        if (next === lastEmittedRef.current) return;
        lastEmittedRef.current = next;
        onChange(next);
    }, [maxLength, mentions, onChange]);

    // Keep DOM chips in sync with the string value (drafts / trim / asset removal).
    useLayoutEffect(() => {
        const root = editorRef.current;
        if (!root) return;

        if (!root.dataset.hydrated) {
            renderValueToEditor(root, value, mentions);
            lastEmittedRef.current = value;
            root.dataset.hydrated = '1';
            return;
        }

        if (value === lastEmittedRef.current) return;

        // Avoid clobbering caret while the user is typing the same content.
        if (focused && serializeEditor(root) === value) {
            lastEmittedRef.current = value;
            return;
        }

        renderValueToEditor(root, value, mentions);
        lastEmittedRef.current = value;
    }, [value, mentions, focused]);

    useLayoutEffect(() => {
        const root = editorRef.current;
        if (!root) return;
        const cs = window.getComputedStyle(root);
        const lineHeight = Number.parseFloat(cs.lineHeight) || 22;
        const paddingY = (Number.parseFloat(cs.paddingTop) || 0) + (Number.parseFloat(cs.paddingBottom) || 0);
        const borderY = (Number.parseFloat(cs.borderTopWidth) || 0) + (Number.parseFloat(cs.borderBottomWidth) || 0);
        const minH = lineHeight * effectiveMinRows + paddingY + borderY;
        const maxH = lineHeight * maxRows + paddingY + borderY;
        root.style.minHeight = `${minH}px`;
        root.style.height = 'auto';
        const next = Math.min(Math.max(root.scrollHeight, minH), maxH);
        root.style.height = `${next}px`;
        root.style.overflowY = root.scrollHeight > maxH + 1 ? 'auto' : 'hidden';
    }, [value, effectiveMinRows, maxRows, focused, mentionedAliases.length]);

    useEffect(() => {
        if (!autoFocus) return;
        const root = editorRef.current;
        if (!root) return;
        root.focus();
        placeCaretAtEnd(root);
    }, [autoFocus]);

    const refreshToken = useCallback(() => {
        const next = getMentionTokenAtCaret();
        if (!next || mentions.length === 0) {
            setToken(null);
            setMenuPos(null);
            return;
        }
        setToken(next);
        setActiveIndex(0);

        const selection = window.getSelection();
        if (selection && selection.rangeCount > 0 && editorRef.current) {
            const rect = selection.getRangeAt(0).getBoundingClientRect();
            const box = editorRef.current.getBoundingClientRect();
            setMenuPos({
                top: Math.max(4, rect.bottom - box.top + 6),
                left: Math.max(4, Math.min(rect.left - box.left, box.width - 220)),
            });
        }
    }, [mentions.length]);

    const insertMention = useCallback(
        (mention: AssetMention) => {
            const root = editorRef.current;
            const active = token ?? getMentionTokenAtCaret();
            if (!root || !active) return;

            const { textNode, startOffset, endOffset } = active;
            const text = textNode.textContent ?? '';
            const before = text.slice(0, startOffset);
            const after = text.slice(endOffset);

            const chip = createChipElement(mention.alias, mention);
            const parent = textNode.parentNode;
            if (!parent) return;

            const afterNode = document.createTextNode(after || '\u00a0');
            if (before) {
                textNode.textContent = before;
                parent.insertBefore(chip, textNode.nextSibling);
                parent.insertBefore(afterNode, chip.nextSibling);
            } else {
                parent.insertBefore(chip, textNode);
                parent.insertBefore(afterNode, chip.nextSibling);
                parent.removeChild(textNode);
            }

            // Prefer a trailing space after the chip for natural typing.
            if (!after) {
                afterNode.textContent = ' ';
            }
            placeCaretAfter(chip);
            if (afterNode.textContent === ' ' || afterNode.textContent === '\u00a0') {
                const selection = window.getSelection();
                if (selection) {
                    const range = document.createRange();
                    range.setStart(afterNode, afterNode.textContent.length);
                    range.collapse(true);
                    selection.removeAllRanges();
                    selection.addRange(range);
                }
            }

            setToken(null);
            setMenuPos(null);
            emitChange();
            root.focus();
        },
        [emitChange, token],
    );

    const handleKeyDown = (event: ReactKeyboardEvent<HTMLDivElement>) => {
        if (token && filtered.length > 0) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                setActiveIndex((index) => (index + 1) % filtered.length);
                return;
            }
            if (event.key === 'ArrowUp') {
                event.preventDefault();
                setActiveIndex((index) => (index - 1 + filtered.length) % filtered.length);
                return;
            }
            if (event.key === 'Enter' || event.key === 'Tab') {
                event.preventDefault();
                insertMention(filtered[activeIndex] ?? filtered[0]);
                return;
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                setToken(null);
                setMenuPos(null);
                return;
            }
        }

        if (event.key === 'Enter' && !event.shiftKey) {
            // Soft line break inside the editor (not form submit).
            event.preventDefault();
            document.execCommand('insertLineBreak');
            emitChange();
            return;
        }

        onKeyDown?.(event as unknown as ReactKeyboardEvent<HTMLDivElement>);
    };

    const showPlaceholder = !value && !focused;

    return (
        <div className="relative">
            <div className="relative">
                {showPlaceholder && placeholder ? (
                    <div
                        className={`${className ?? ''} pointer-events-none absolute inset-0 z-0 text-white/30`}
                        aria-hidden
                    >
                        {placeholder}
                    </div>
                ) : null}

                <div
                    {...props}
                    ref={editorRef}
                    role="textbox"
                    aria-multiline="true"
                    aria-placeholder={placeholder}
                    aria-disabled={disabled || undefined}
                    contentEditable={disabled ? false : true}
                    suppressContentEditableWarning
                    data-asset-mention-editor="true"
                    className={`${className ?? ''} relative z-10 block w-full whitespace-pre-wrap break-words empty:before:content-[''] focus:outline-none`}
                    style={{
                        ...style,
                        resize: 'none',
                    }}
                    onInput={() => {
                        emitChange();
                        refreshToken();
                    }}
                    onKeyDown={handleKeyDown}
                    onKeyUp={() => refreshToken()}
                    onClick={(event) => {
                        refreshToken();
                        onClick?.(event);
                    }}
                    onFocus={(event) => {
                        setFocused(true);
                        onFocus?.(event);
                    }}
                    onBlur={(event) => {
                        setFocused(false);
                        window.setTimeout(() => {
                            setToken(null);
                            setMenuPos(null);
                        }, 140);
                        onBlur?.(event);
                    }}
                    onPaste={(event) => {
                        event.preventDefault();
                        const text = event.clipboardData.getData('text/plain');
                        document.execCommand('insertText', false, text);
                        emitChange();
                        refreshToken();
                    }}
                />
            </div>

            {mentionedAliases.length > 0 && (
                <div className="mt-2 flex flex-wrap items-center gap-1.5 px-0.5">
                    <span className="text-[10px] font-medium uppercase tracking-[0.12em] text-white/30">Referenced</span>
                    {mentionedAliases.map((alias) => {
                        const meta = mentions.find((m) => m.alias.toLowerCase() === alias.toLowerCase());
                        const kind = meta?.kind ?? kindFromAlias(alias);
                        return (
                            <span
                                key={alias}
                                className="inline-flex items-center gap-1 rounded-full bg-[#FF5733]/15 py-0.5 pe-2 ps-1 text-[11px] font-semibold text-orange-100 ring-1 ring-[#FF5733]/30"
                            >
                                <span className="inline-flex h-4 w-4 items-center justify-center overflow-hidden rounded-full bg-black/30 text-orange-200">
                                    {meta?.previewUrl && (kind === 'image' || kind === 'video') ? (
                                        kind === 'image' ? (
                                            <img src={meta.previewUrl} alt="" className="h-full w-full object-cover" />
                                        ) : (
                                            <video src={meta.previewUrl} muted className="h-full w-full object-cover" />
                                        )
                                    ) : (
                                        <KindIcon kind={kind} />
                                    )}
                                </span>
                                {alias}
                            </span>
                        );
                    })}
                </div>
            )}

            <AnimatePresence>
                {token && filtered.length > 0 && menuPos && (
                    <motion.div
                        initial={{ opacity: 0, y: -4 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0, y: -4 }}
                        className="absolute z-40 w-[min(100%,20rem)] overflow-hidden rounded-xl border border-white/10 bg-[#17171d] shadow-[0_18px_45px_rgba(0,0,0,0.45)]"
                        style={{ top: menuPos.top, left: menuPos.left }}
                    >
                        <div className="flex items-center justify-between border-b border-white/[0.06] px-3 py-2">
                            <span className="text-[10px] font-semibold uppercase tracking-[0.14em] text-white/35">Reference asset</span>
                            <span className="text-[10px] text-white/25">↑↓ · Enter</span>
                        </div>
                        <div className="max-h-48 overflow-y-auto p-1.5 scrollbar-thin">
                            {filtered.map((mention, index) => (
                                <button
                                    key={mention.alias}
                                    type="button"
                                    onMouseDown={(event) => event.preventDefault()}
                                    onClick={() => insertMention(mention)}
                                    onMouseEnter={() => setActiveIndex(index)}
                                    className={`flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-start transition ${
                                        index === activeIndex ? 'bg-[#FF5733]/12 text-white' : 'text-white/65 hover:bg-white/[0.05]'
                                    }`}
                                >
                                    <span className="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-white/[0.06] text-orange-300 ring-1 ring-white/10">
                                        {mention.kind === 'image' && mention.previewUrl ? (
                                            <img src={mention.previewUrl} alt="" className="size-full object-cover" />
                                        ) : mention.kind === 'video' && mention.previewUrl ? (
                                            <video src={mention.previewUrl} className="size-full object-cover" muted />
                                        ) : (
                                            <KindIcon kind={mention.kind} className="h-4 w-4" />
                                        )}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block text-xs font-semibold text-orange-200">{mention.alias}</span>
                                        <span className="block truncate text-[11px] text-white/35">{mention.name}</span>
                                    </span>
                                    <span className="rounded-md border border-white/10 px-1.5 py-0.5 text-[9px] uppercase text-white/30">
                                        {mention.kind}
                                    </span>
                                </button>
                            ))}
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>

            {mentions.length > 0 && !token && (
                <p className="mt-1.5 px-1 text-[10px] text-white/30">
                    Type <span className="font-semibold text-orange-300/80">@</span> to attach an asset as a chip — delete removes the whole reference.
                </p>
            )}
        </div>
    );
}
