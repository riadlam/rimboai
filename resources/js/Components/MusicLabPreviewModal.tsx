import { motion } from 'framer-motion';
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import LabAudioPlayer, { MUSIC_EQ_BAR_COUNT } from '@/Components/LabAudioPlayer';
import { musicPalette } from '@/lib/musicPalette';

export type MusicPreviewTrack = {
    id: string;
    title: string;
    style: string;
    lyrics?: string;
    cover: string;
    favorite: boolean;
    instrumental: boolean;
    model?: string;
    duration?: string;
    audioUrl?: string;
};

type Props = {
    track: MusicPreviewTrack;
    index: number;
    total: number;
    onClose: () => void;
    onPrev?: () => void;
    onNext?: () => void;
    onToggleFavorite?: (id: string) => void;
    onDelete?: (ids: string[]) => void;
    onPlayingChange?: (playing: boolean) => void;
};

async function downloadAsset(url: string, filename: string) {
    try {
        const res = await fetch(url);
        if (!res.ok) throw new Error('fetch failed');
        const blob = await res.blob();
        const objectUrl = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = objectUrl;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(objectUrl);
    } catch {
        window.open(url, '_blank', 'noopener,noreferrer');
    }
}

export default function MusicLabPreviewModal({
    track,
    index,
    total,
    onClose,
    onPrev,
    onNext,
    onToggleFavorite,
    onDelete,
    onPlayingChange,
}: Props) {
    const [detailsOpen, setDetailsOpen] = useState(true);
    const [playing, setPlaying] = useState(false);
    const [downloading, setDownloading] = useState(false);
    const [copied, setCopied] = useState<'style' | 'lyrics' | null>(null);
    const [levels, setLevels] = useState<number[]>(() => new Array(MUSIC_EQ_BAR_COUNT).fill(0.08));
    const levelsRaf = useRef(0);
    const pendingLevels = useRef<number[] | null>(null);

    const handleLevels = useCallback((next: number[]) => {
        pendingLevels.current = next;
        if (levelsRaf.current) return;
        levelsRaf.current = requestAnimationFrame(() => {
            levelsRaf.current = 0;
            if (pendingLevels.current) setLevels(pendingLevels.current);
        });
    }, []);

    useEffect(() => {
        return () => {
            if (levelsRaf.current) cancelAnimationFrame(levelsRaf.current);
        };
    }, []);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
            if (e.key === 'ArrowLeft') onPrev?.();
            if (e.key === 'ArrowRight') onNext?.();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose, onPrev, onNext]);

    const handleDownload = async () => {
        if (!track.audioUrl || downloading) return;
        setDownloading(true);
        try {
            await downloadAsset(track.audioUrl, `music-${track.id}.mp3`);
        } finally {
            setDownloading(false);
        }
    };

    const copyText = async (text: string, kind: 'style' | 'lyrics') => {
        try {
            await navigator.clipboard.writeText(text);
            setCopied(kind);
            window.setTimeout(() => setCopied(null), 1500);
        } catch {
            /* ignore */
        }
    };

    const handlePlaying = (isPlaying: boolean) => {
        setPlaying(isPlaying);
        onPlayingChange?.(isPlaying);
    };

    const palette = musicPalette(track.id);

    return (
        <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-0 sm:p-4 [&_a]:cursor-pointer [&_button]:cursor-pointer"
            onClick={onClose}
        >
            <motion.div
                initial={{ opacity: 0, scale: 0.97, y: 10 }}
                animate={{ opacity: 1, scale: 1, y: 0 }}
                exit={{ opacity: 0, scale: 0.98 }}
                className="flex h-full min-h-0 w-full max-w-6xl flex-col overflow-hidden border-white/10 bg-[#101014] shadow-2xl sm:h-[90vh] sm:rounded-[5px] sm:border md:flex-row"
                onClick={(e) => e.stopPropagation()}
            >
                {/* Stage — colorful art (never result preview URL) */}
                <div className="relative flex h-[52vh] shrink-0 flex-col overflow-hidden bg-black md:h-full md:flex-1">
                    {/* Mobile close — always visible at top-right when details open */}
                    <button
                        type="button"
                        title="Close"
                        aria-label="Close"
                        onClick={onClose}
                        className="absolute end-3 top-3 z-20 inline-flex h-9 w-9 items-center justify-center rounded-full border border-white/15 bg-black/55 text-white shadow-lg backdrop-blur-md transition hover:bg-black/75 md:hidden"
                    >
                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg>
                    </button>

                    <div className="relative flex min-h-0 flex-1 items-center justify-center p-4 md:p-8">
                        <div
                            className="relative aspect-square w-full max-w-[min(100%,420px)] overflow-hidden rounded-[5px] shadow-[0_24px_80px_rgba(0,0,0,0.55)] ring-1 ring-white/10"
                            style={{ background: palette.base }}
                        >
                            <motion.div
                                key={`${track.id}-blob-a`}
                                aria-hidden
                                className="absolute -left-1/4 -top-1/4 h-[70%] w-[70%] rounded-full blur-3xl"
                                style={{ background: palette.blobA }}
                                animate={
                                    playing
                                        ? { x: [0, 28, -12, 0], y: [0, 18, -8, 0], scale: [1, 1.12, 0.96, 1] }
                                        : { x: 0, y: 0, scale: 1 }
                                }
                                transition={
                                    playing
                                        ? { duration: 6.5, repeat: Infinity, ease: 'easeInOut' }
                                        : { duration: 0.6 }
                                }
                            />
                            <motion.div
                                key={`${track.id}-blob-b`}
                                aria-hidden
                                className="absolute -bottom-1/4 -right-1/4 h-[75%] w-[75%] rounded-full blur-3xl"
                                style={{ background: palette.blobB }}
                                animate={
                                    playing
                                        ? { x: [0, -22, 14, 0], y: [0, -16, 10, 0], scale: [1, 1.08, 1.15, 1] }
                                        : { x: 0, y: 0, scale: 1 }
                                }
                                transition={
                                    playing
                                        ? { duration: 7.8, repeat: Infinity, ease: 'easeInOut' }
                                        : { duration: 0.6 }
                                }
                            />
                            <motion.div
                                key={`${track.id}-blob-c`}
                                aria-hidden
                                className="absolute left-1/3 top-1/3 h-[45%] w-[45%] -translate-x-1/2 -translate-y-1/2 rounded-full blur-2xl"
                                style={{ background: palette.blobC }}
                                animate={
                                    playing
                                        ? { opacity: [0.45, 0.75, 0.5, 0.45], scale: [0.9, 1.2, 1, 0.9] }
                                        : { opacity: 0.35, scale: 1 }
                                }
                                transition={
                                    playing
                                        ? { duration: 4.2, repeat: Infinity, ease: 'easeInOut' }
                                        : { duration: 0.5 }
                                }
                            />
                            <div className="absolute inset-0 bg-[radial-gradient(circle_at_center,transparent_20%,rgba(0,0,0,0.45)_100%)]" />

                            {/* Real spectrum — driven by Web Audio AnalyserNode */}
                            <div className="music-eq absolute inset-x-0 bottom-0 top-[18%] flex items-end justify-center gap-[3px] px-8 pb-14 sm:gap-1 sm:px-12" aria-hidden>
                                {levels.map((level, i) => {
                                    const scale = Math.max(0.06, Math.min(1, level));
                                    return (
                                        <span
                                            key={`${track.id}-bar-${i}`}
                                            className="music-eq-bar"
                                            style={{
                                                background: palette.bar,
                                                transform: `scaleY(${scale})`,
                                                opacity: 0.35 + scale * 0.65,
                                            }}
                                        />
                                    );
                                })}
                            </div>

                            <div className="absolute inset-x-0 top-0 flex items-start justify-between p-3">
                                <span className="rounded-lg border border-white/10 bg-black/45 px-2.5 py-1 text-[11px] font-medium text-white/80 backdrop-blur-md">
                                    {track.instrumental ? 'Instrumental' : 'With Vocals'}
                                </span>
                                <span className="rounded-lg border border-white/10 bg-black/45 px-2.5 py-1 text-[11px] tabular-nums text-white/60 backdrop-blur-md">
                                    {index + 1} / {total}
                                </span>
                            </div>
                        </div>
                    </div>

                    {/* Player dock */}
                    <div className="relative z-10 shrink-0 border-t border-white/10 bg-gradient-to-t from-black via-[#0a0a0f] to-[#0a0a0f]/90 px-3 py-3 md:px-5 md:py-4">
                        {track.audioUrl ? (
                            <LabAudioPlayer
                                key={track.id}
                                src={track.audioUrl}
                                autoPlay
                                variant="dock"
                                onPlayingChange={handlePlaying}
                                onLevels={handleLevels}
                            />
                        ) : (
                            <div className="rounded-xl border border-dashed border-white/10 px-4 py-5 text-center text-[13px] text-white/40">
                                No audio file available for this track yet.
                            </div>
                        )}
                    </div>

                    <div className="absolute bottom-[5.5rem] start-3 z-10 flex max-w-[calc(100%-1.5rem)] flex-row flex-wrap items-center gap-2 md:bottom-[6.25rem] md:start-5">
                        <div className="flex items-center gap-0.5 rounded-xl border border-white/10 bg-[#121217]/85 p-1 shadow-lg backdrop-blur-md">
                            <PreviewIconBtn title={downloading ? 'Downloading…' : 'Download'} onClick={() => void handleDownload()}>
                                <IconDownload className="h-4 w-4" />
                            </PreviewIconBtn>
                            <PreviewIconBtn
                                title={track.favorite ? 'Unfavorite' : 'Favorite'}
                                onClick={() => onToggleFavorite?.(track.id)}
                            >
                                <svg
                                    className={`h-4 w-4 ${track.favorite ? 'fill-[#FF5733] text-[#FF5733]' : ''}`}
                                    fill={track.favorite ? 'currentColor' : 'none'}
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    strokeWidth="1.5"
                                >
                                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
                                </svg>
                            </PreviewIconBtn>
                        </div>
                    </div>

                    {total > 1 && onPrev && onNext && (
                        <>
                            <NavArrow side="left" onClick={onPrev} />
                            <NavArrow side="right" onClick={onNext} />
                        </>
                    )}
                </div>

                {/* Details panel */}
                <div className="flex min-h-0 flex-1 flex-col overflow-hidden border-t border-white/10 bg-[#111116] font-sans md:w-80 md:flex-none md:border-s md:border-t-0">
                    <div className="hidden shrink-0 items-center justify-between border-b border-white/10 px-4 py-3.5 md:flex">
                        <span className="text-[13px] font-semibold tracking-tight text-zinc-100">Details</span>
                        <div className="flex items-center gap-1">
                            <IconBtn
                                title="Favorite"
                                active={track.favorite}
                                onClick={() => onToggleFavorite?.(track.id)}
                                className="h-8 w-8 border-transparent bg-transparent hover:bg-white/5"
                            >
                                <svg
                                    className={`h-4 w-4 ${track.favorite ? 'fill-[#FF5733] text-[#FF5733]' : ''}`}
                                    fill={track.favorite ? 'currentColor' : 'none'}
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    strokeWidth="1.5"
                                >
                                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
                                </svg>
                            </IconBtn>
                            <IconBtn title="Close" onClick={onClose} className="h-8 w-8 border-transparent bg-transparent hover:bg-white/5">
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                    <path d="M18 6 6 18" />
                                    <path d="m6 6 12 12" />
                                </svg>
                            </IconBtn>
                        </div>
                    </div>

                    <div className="flex shrink-0 items-center justify-between border-b border-white/10 px-4 py-3 md:hidden">
                        <span className="truncate text-[13px] font-semibold text-zinc-100">{track.title}</span>
                        <IconBtn title="Close" onClick={onClose} className="h-8 w-8 border-transparent bg-transparent hover:bg-white/5">
                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                <path d="M18 6 6 18" />
                                <path d="m6 6 12 12" />
                            </svg>
                        </IconBtn>
                    </div>

                    <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain scrollbar-thin">
                        <div className="space-y-2.5 p-4">
                            <div>
                                <p className="text-[11px] font-medium uppercase tracking-[0.14em] text-zinc-500">Now playing</p>
                                <h3 className="mt-1 text-base font-semibold tracking-tight text-white">{track.title}</h3>
                            </div>

                            <div className="grid grid-cols-2 gap-2">
                                <DetailBtn onClick={() => void handleDownload()} disabled={!track.audioUrl || downloading}>
                                    <IconDownload className="h-3.5 w-3.5 opacity-70" />
                                    {downloading ? 'Downloading…' : 'Download'}
                                </DetailBtn>
                                <DetailBtn gradient>
                                    <svg className="h-3.5 w-3.5 opacity-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                        <circle cx="12" cy="12" r="10" />
                                        <path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20" />
                                        <path d="M2 12h20" />
                                    </svg>
                                    Publish
                                </DetailBtn>
                            </div>
                        </div>

                        <div className="border-t border-white/10">
                            <button
                                type="button"
                                onClick={() => setDetailsOpen((v) => !v)}
                                className="flex w-full items-center justify-between px-4 py-3.5 transition hover:bg-white/[0.03]"
                            >
                                <div className="flex items-center gap-2">
                                    <svg className="h-4 w-4 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                        <circle cx="12" cy="12" r="10" />
                                        <path d="M12 16v-4" />
                                        <path d="M12 8h.01" />
                                    </svg>
                                    <span className="text-[13px] font-medium tracking-tight text-zinc-200">Generation Details</span>
                                </div>
                                <svg
                                    className={`h-4 w-4 text-zinc-500 transition ${detailsOpen ? 'rotate-0' : 'rotate-180'}`}
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    strokeWidth="1.5"
                                >
                                    <path d="m18 15-6-6-6 6" />
                                </svg>
                            </button>

                            {detailsOpen && (
                                <div className="space-y-3 px-4 pb-4">
                                    <div className="flex items-center justify-between gap-3 text-[13px]">
                                        <span className="shrink-0 text-zinc-500">Model</span>
                                        <span className="truncate text-end font-medium text-[#FF5733]" title={track.model || '—'}>
                                            {track.model || '—'}
                                        </span>
                                    </div>
                                    <div className="flex items-center justify-between gap-3 text-[13px]">
                                        <span className="shrink-0 text-zinc-500">Type</span>
                                        <span className="truncate text-end font-medium text-zinc-200">
                                            {track.instrumental ? 'Instrumental' : 'With Vocals'}
                                        </span>
                                    </div>
                                    {track.duration && (
                                        <div className="flex items-center justify-between gap-3 text-[13px]">
                                            <span className="shrink-0 text-zinc-500">Duration</span>
                                            <span className="truncate text-end font-medium text-zinc-200">{track.duration}</span>
                                        </div>
                                    )}
                                    <div className="space-y-1.5">
                                        <div className="flex items-center justify-between">
                                            <span className="text-[13px] text-zinc-500">Style</span>
                                            <button
                                                type="button"
                                                title={copied === 'style' ? 'Copied' : 'Copy style'}
                                                onClick={() => void copyText(track.style, 'style')}
                                                className="inline-flex h-7 w-7 items-center justify-center rounded-md text-zinc-500 transition hover:bg-white/5 hover:text-zinc-200"
                                            >
                                                {copied === 'style' ? (
                                                    <svg className="h-3.5 w-3.5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                                        <path d="M20 6 9 17l-5-5" />
                                                    </svg>
                                                ) : (
                                                    <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                                        <rect width="14" height="14" x="8" y="8" rx="2" ry="2" />
                                                        <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />
                                                    </svg>
                                                )}
                                            </button>
                                        </div>
                                        <div className="rounded-lg bg-white/[0.03] p-3 ring-1 ring-inset ring-white/5">
                                            <p className="line-clamp-6 text-[13px] leading-relaxed text-zinc-300">{track.style || '—'}</p>
                                        </div>
                                    </div>

                                    {!track.instrumental && (
                                        <div className="space-y-2">
                                            <div className="flex items-center justify-between">
                                                <span className="text-[13px] text-zinc-500">Lyrics</span>
                                                {track.lyrics?.trim() ? (
                                                    <button
                                                        type="button"
                                                        title={copied === 'lyrics' ? 'Copied' : 'Copy lyrics'}
                                                        onClick={() => void copyText(track.lyrics!.trim(), 'lyrics')}
                                                        className="inline-flex h-7 w-7 items-center justify-center rounded-md text-zinc-500 transition hover:bg-white/5 hover:text-zinc-200"
                                                    >
                                                        {copied === 'lyrics' ? (
                                                            <svg className="h-3.5 w-3.5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                                                <path d="M20 6 9 17l-5-5" />
                                                            </svg>
                                                        ) : (
                                                            <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                                                <rect width="14" height="14" x="8" y="8" rx="2" ry="2" />
                                                                <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />
                                                            </svg>
                                                        )}
                                                    </button>
                                                ) : null}
                                            </div>
                                            {track.lyrics?.trim() ? (
                                                <LyricsPreview text={track.lyrics.trim()} />
                                            ) : (
                                                <div className="rounded-lg border border-dashed border-white/10 px-3 py-3 text-[12px] text-zinc-500">
                                                    No lyrics entered — model invented them from style.
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    <div className="flex items-center justify-between text-xs text-zinc-600">
                                        <span>{track.instrumental ? 'Instrumental' : 'Vocals'}</span>
                                        <span>
                                            {index + 1} / {total}
                                        </span>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="shrink-0 border-t border-white/10 p-3 md:p-4">
                        <button
                            type="button"
                            onClick={() => {
                                onDelete?.([track.id]);
                                onClose();
                            }}
                            className="inline-flex h-9 w-full items-center justify-center gap-2 rounded-lg font-sans text-[13px] font-medium tracking-tight text-red-400 transition hover:bg-red-500/10 hover:text-red-300"
                        >
                            <IconTrash className="h-4 w-4" />
                            Delete
                        </button>
                    </div>
                </div>
            </motion.div>
        </motion.div>
    );
}

const LYRIC_TAGS = ['[Intro]', '[Verse]', '[Pre Chorus]', '[Chorus]', '[Bridge]', '[Outro]'] as const;

function LyricsPreview({ text }: { text: string }) {
    const usedTags = LYRIC_TAGS.filter((tag) => text.includes(tag));

    return (
        <div className="space-y-2">
            {usedTags.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {usedTags.map((tag) => (
                        <span
                            key={tag}
                            className="rounded-lg border border-orange-400/30 bg-[#FF5733]/10 px-2 py-1 text-[11px] font-semibold text-orange-100"
                        >
                            {tag}
                        </span>
                    ))}
                </div>
            )}
            <div className="relative overflow-hidden rounded-xl border border-white/10 bg-black shadow-[inset_0_0_0_1px_rgba(255,255,255,0.02)]">
                <div className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-[#FF5733]/55 to-transparent" />
                <div className="pointer-events-none absolute inset-y-0 start-0 w-1 bg-gradient-to-b from-[#FF5733]/50 via-[#FF5733]/15 to-transparent" />
                <pre className="max-h-[280px] overflow-y-auto whitespace-pre-wrap break-words ps-4 pe-3.5 py-3.5 font-mono text-[13px] leading-7 text-white scrollbar-thin">
                    {text.split(/(\[[^\]]+\])/g).map((part, i) =>
                        /^\[[^\]]+\]$/.test(part) ? (
                            <span key={i} className="font-semibold text-[#FF5733]">
                                {part}
                            </span>
                        ) : (
                            <span key={i}>{part}</span>
                        ),
                    )}
                </pre>
            </div>
        </div>
    );
}

function PreviewIconBtn({ children, title, onClick }: { children: ReactNode; title: string; onClick?: () => void }) {
    return (
        <button
            type="button"
            title={title}
            onClick={onClick}
            className="inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg text-zinc-300 transition hover:bg-white/10 hover:text-white active:scale-95"
        >
            {children}
        </button>
    );
}

function DetailBtn({
    children,
    gradient,
    onClick,
    disabled,
}: {
    children: ReactNode;
    gradient?: boolean;
    onClick?: () => void;
    disabled?: boolean;
}) {
    if (gradient) {
        return (
            <button
                type="button"
                onClick={onClick}
                disabled={disabled}
                className="inline-flex h-9 w-full cursor-pointer items-center justify-center gap-1.5 rounded-lg bg-[#FF5733] px-3 font-sans text-[13px] font-medium tracking-tight text-white transition hover:bg-[#ff6b4d] active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50"
            >
                {children}
            </button>
        );
    }

    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            className="inline-flex h-9 w-full cursor-pointer items-center justify-center gap-1.5 rounded-lg border border-white/10 bg-white/[0.04] px-3 font-sans text-[13px] font-medium tracking-tight text-zinc-200 transition hover:border-white/15 hover:bg-white/[0.07] hover:text-white active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50"
        >
            {children}
        </button>
    );
}

function NavArrow({ side, onClick }: { side: 'left' | 'right'; onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`absolute top-[38%] z-10 flex h-10 w-10 -translate-y-1/2 cursor-pointer items-center justify-center rounded-full border border-white/10 bg-black/50 font-sans text-base text-zinc-200 backdrop-blur-md transition hover:bg-black/70 hover:text-white md:top-1/2 ${
                side === 'left' ? 'start-3' : 'end-3'
            }`}
        >
            {side === 'left' ? '‹' : '›'}
        </button>
    );
}

function IconBtn({
    children,
    title,
    active,
    onClick,
    className = '',
}: {
    children: ReactNode;
    title?: string;
    active?: boolean;
    onClick?: () => void;
    className?: string;
}) {
    return (
        <button
            type="button"
            title={title}
            onClick={onClick}
            className={`inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg border transition active:scale-95 ${
                active
                    ? 'border-[#FF5733]/35 bg-[#FF5733]/15 text-[#ffb39f]'
                    : 'border-white/10 bg-black/40 text-zinc-300 hover:bg-white/10 hover:text-white'
            } ${className}`}
        >
            {children}
        </button>
    );
}

function IconDownload({ className = 'h-4 w-4' }: { className?: string }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
            <path d="M12 4v10.5" />
            <path d="m8.5 11.5 3.5 3.5 3.5-3.5" />
            <path d="M5 17.5v.5A2 2 0 0 0 7 20h10a2 2 0 0 0 2-2v-.5" />
        </svg>
    );
}

function IconTrash({ className = 'h-4 w-4' }: { className?: string }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
            <path d="M4.5 7h15" />
            <path d="M9 7V5.5A1.5 1.5 0 0 1 10.5 4h3A1.5 1.5 0 0 1 15 5.5V7" />
            <path d="M6.5 7l.7 12.2A1.5 1.5 0 0 0 8.7 20.5h6.6a1.5 1.5 0 0 0 1.5-1.3L17.5 7" />
        </svg>
    );
}
