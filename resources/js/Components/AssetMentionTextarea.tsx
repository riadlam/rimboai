import { AnimatePresence, motion } from 'framer-motion';
import { useMemo, useRef, useState, type KeyboardEvent, type TextareaHTMLAttributes } from 'react';

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

type Props = Omit<TextareaHTMLAttributes<HTMLTextAreaElement>, 'value' | 'onChange'> & {
    value: string;
    onChange: (value: string) => void;
    mentions: AssetMention[];
};

type ActiveToken = {
    start: number;
    end: number;
    query: string;
};

function tokenAtCaret(value: string, caret: number | null): ActiveToken | null {
    if (caret == null) return null;
    const before = value.slice(0, caret);
    const match = before.match(/(?:^|\s)@([a-z0-9_-]*)$/i);
    if (!match) return null;

    const query = match[1] ?? '';
    return {
        start: caret - query.length - 1,
        end: caret,
        query: query.toLowerCase(),
    };
}

function KindIcon({ kind }: { kind: AssetMention['kind'] }) {
    if (kind === 'audio') {
        return (
            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8">
                <path d="M9 18V5l12-2v13" />
                <circle cx="6" cy="18" r="3" />
                <circle cx="18" cy="16" r="3" />
            </svg>
        );
    }
    if (kind === 'video') {
        return (
            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8">
                <rect x="3" y="5" width="14" height="14" rx="2" />
                <path d="m17 10 4-2v8l-4-2" />
            </svg>
        );
    }
    return (
        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8">
            <rect x="3" y="3" width="18" height="18" rx="2" />
            <circle cx="9" cy="9" r="2" />
            <path d="m21 15-4-4L5 21" />
        </svg>
    );
}

export default function AssetMentionTextarea({
    value,
    onChange,
    mentions,
    maxLength,
    onKeyDown,
    onBlur,
    onClick,
    onKeyUp,
    ...props
}: Props) {
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const [token, setToken] = useState<ActiveToken | null>(null);
    const [activeIndex, setActiveIndex] = useState(0);

    const filtered = useMemo(() => {
        if (!token) return [];
        return mentions.filter((mention) => mention.alias.slice(1).toLowerCase().includes(token.query));
    }, [mentions, token]);

    const refreshToken = (element: HTMLTextAreaElement) => {
        const next = tokenAtCaret(element.value, element.selectionStart);
        setToken(next && mentions.length > 0 ? next : null);
        setActiveIndex(0);
    };

    const insertMention = (mention: AssetMention) => {
        if (!token) return;
        const next = `${value.slice(0, token.start)}${mention.alias} ${value.slice(token.end)}`;
        const bounded = maxLength ? next.slice(0, maxLength) : next;
        const caret = Math.min(token.start + mention.alias.length + 1, bounded.length);
        onChange(bounded);
        setToken(null);
        requestAnimationFrame(() => {
            textareaRef.current?.focus();
            textareaRef.current?.setSelectionRange(caret, caret);
        });
    };

    const handleKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
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
                return;
            }
        }
        onKeyDown?.(event);
    };

    return (
        <div className="relative">
            <textarea
                {...props}
                ref={textareaRef}
                value={value}
                maxLength={maxLength}
                onChange={(event) => {
                    const next = maxLength ? event.target.value.slice(0, maxLength) : event.target.value;
                    onChange(next);
                    refreshToken(event.target);
                }}
                onKeyDown={handleKeyDown}
                onKeyUp={(event) => {
                    refreshToken(event.currentTarget);
                    onKeyUp?.(event);
                }}
                onClick={(event) => {
                    refreshToken(event.currentTarget);
                    onClick?.(event);
                }}
                onBlur={(event) => {
                    window.setTimeout(() => setToken(null), 120);
                    onBlur?.(event);
                }}
            />

            <AnimatePresence>
                {token && filtered.length > 0 && (
                    <motion.div
                        initial={{ opacity: 0, y: -4, height: 0 }}
                        animate={{ opacity: 1, y: 0, height: 'auto' }}
                        exit={{ opacity: 0, y: -4, height: 0 }}
                        className="relative z-30 mt-1.5 overflow-hidden rounded-xl border border-white/10 bg-[#17171d] shadow-[0_18px_45px_rgba(0,0,0,0.45)]"
                    >
                        <div className="flex items-center justify-between border-b border-white/[0.06] px-3 py-2">
                            <span className="text-[10px] font-semibold uppercase tracking-[0.14em] text-white/35">Reference an asset</span>
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
                                            <KindIcon kind={mention.kind} />
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
                    Type <span className="font-semibold text-orange-300/80">@</span> to reference uploaded assets.
                </p>
            )}
        </div>
    );
}
