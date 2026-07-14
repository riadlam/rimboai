import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import VoiceLabPreviewModal from '@/Components/VoiceLabPreviewModal';
import { musicPalette } from '@/lib/musicPalette';

export type LabVoice = {
    id: string;
    creationId?: number;
    title: string;
    text: string;
    voice: string;
    favorite: boolean;
    createdAt: number;
    model?: string;
    duration?: string;
    gradient?: string;
    audioUrl?: string;
    status?: 'pending' | 'queued' | 'in_progress' | 'completed' | 'failed' | 'cancelled';
    progress?: string;
    error?: string;
};

type Props = {
    voices?: LabVoice[];
    onToggleFavorite?: (id: string) => void;
    onDelete?: (ids: string[]) => void;
    generating?: boolean;
};

const TIME_FILTERS = [
    { id: 'all', label: 'All time', days: null },
    { id: '1d', label: 'Past 1 day', days: 1 },
    { id: '2d', label: 'Past 2 days', days: 2 },
    { id: '3d', label: 'Past 3 days', days: 3 },
    { id: '7d', label: 'Past 7 days', days: 7 },
] as const;

type TimeFilterId = (typeof TIME_FILTERS)[number]['id'];

export default function VoiceLabLibrary({
    voices = [],
    onToggleFavorite,
    onDelete,
    generating = false,
}: Props) {
    const [tab, setTab] = useState<'generation' | 'playlists'>('generation');
    const [search, setSearch] = useState('');
    const [favoritesOnly, setFavoritesOnly] = useState(false);
    const [selectMode, setSelectMode] = useState(false);
    const [selected, setSelected] = useState<string[]>([]);
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [timeFilter, setTimeFilter] = useState<TimeFilterId>('all');
    const [playingId, setPlayingId] = useState<string | null>(null);
    const [previewIndex, setPreviewIndex] = useState<number | null>(null);
    const filtersRef = useRef<HTMLDivElement>(null);
    const audioRef = useRef<HTMLAudioElement | null>(null);

    const stopAudio = () => {
        if (audioRef.current) {
            audioRef.current.pause();
            audioRef.current.src = '';
            audioRef.current = null;
        }
    };

    const togglePlay = (item: LabVoice) => {
        if (playingId === item.id) {
            stopAudio();
            setPlayingId(null);
            return;
        }
        if (!item.audioUrl) return;
        stopAudio();
        const audio = new Audio(item.audioUrl);
        audioRef.current = audio;
        setPlayingId(item.id);
        audio.play().catch(() => {
            stopAudio();
            setPlayingId(null);
        });
        audio.onended = () => {
            if (audioRef.current === audio) {
                stopAudio();
                setPlayingId(null);
            }
        };
        audio.onerror = () => {
            if (audioRef.current === audio) {
                stopAudio();
                setPlayingId(null);
            }
        };
    };

    useEffect(() => () => stopAudio(), []);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        const timeOpt = TIME_FILTERS.find((t) => t.id === timeFilter);
        const cutoff = timeOpt?.days != null ? Date.now() - timeOpt.days * 24 * 60 * 60 * 1000 : null;

        return voices.filter((v) => {
            if (favoritesOnly && !v.favorite) return false;
            if (cutoff != null && v.createdAt < cutoff) return false;
            if (q && !`${v.title} ${v.text} ${v.voice}`.toLowerCase().includes(q)) return false;
            return true;
        });
    }, [voices, search, favoritesOnly, timeFilter]);

    const previewable = useMemo(
        () => filtered.filter((v) => v.status == null || v.status === 'completed'),
        [filtered],
    );
    const preview = previewIndex !== null ? previewable[previewIndex] ?? null : null;
    const hasVoices = voices.length > 0;
    const libraryFilterCount = timeFilter !== 'all' ? 1 : 0;
    const activeFilterCount = libraryFilterCount + (favoritesOnly ? 1 : 0) + (search.trim() ? 1 : 0);

    const openPreview = (id: string) => {
        stopAudio();
        setPlayingId(null);
        const idx = previewable.findIndex((v) => v.id === id);
        if (idx >= 0) setPreviewIndex(idx);
    };

    const closePreview = () => {
        setPreviewIndex(null);
        setPlayingId(null);
    };

    useEffect(() => {
        if (!filtersOpen) return;
        const onPointerDown = (e: MouseEvent) => {
            if (filtersRef.current && !filtersRef.current.contains(e.target as Node)) setFiltersOpen(false);
        };
        document.addEventListener('mousedown', onPointerDown);
        return () => document.removeEventListener('mousedown', onPointerDown);
    }, [filtersOpen]);

    const toggleSelect = (id: string) => {
        setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
    };

    const exitSelectMode = () => {
        setSelectMode(false);
        setSelected([]);
    };

    const resetFilters = () => {
        setSearch('');
        setFavoritesOnly(false);
        setTimeFilter('all');
        setFiltersOpen(false);
    };

    return (
        <div className="relative flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden bg-black [&_a]:cursor-pointer [&_button]:cursor-pointer [&_button:disabled]:cursor-not-allowed">
            <div className="relative z-20 shrink-0 border-b border-white/[0.07] bg-black">
                <div className="flex h-12 items-center justify-between gap-2 px-3 md:px-4">
                    <div className="inline-flex items-center rounded-xl border border-white/[0.06] bg-black p-1">
                        {(
                            [
                                { id: 'generation' as const, label: 'Voiceovers', Icon: IconMic },
                                { id: 'playlists' as const, label: 'Collections', Icon: IconAlbums },
                            ]
                        ).map((item) => {
                            const active = tab === item.id;
                            const ItemIcon = item.Icon;
                            return (
                                <button
                                    key={item.id}
                                    type="button"
                                    onClick={() => {
                                        setTab(item.id);
                                        exitSelectMode();
                                        setPreviewIndex(null);
                                    }}
                                    className={`inline-flex cursor-pointer items-center gap-2 rounded-lg px-3.5 py-2 text-[13px] font-medium transition ${
                                        active
                                            ? 'bg-gradient-to-b from-white/[0.12] to-white/[0.05] text-zinc-50 ring-1 ring-white/10'
                                            : 'text-zinc-500 hover:text-zinc-300'
                                    }`}
                                >
                                    <ItemIcon className={`h-3.5 w-3.5 ${active ? 'text-[#FF5733]' : ''}`} />
                                    <span>{item.label}</span>
                                    {item.id === 'generation' && hasVoices && (
                                        <span className={`rounded-md px-1.5 py-0.5 text-[10px] font-semibold ${active ? 'bg-[#FF5733]/20 text-[#ffb39f]' : 'bg-white/[0.06] text-zinc-500'}`}>
                                            {voices.length}
                                        </span>
                                    )}
                                </button>
                            );
                        })}
                    </div>

                    <div className="flex items-center gap-1.5">
                        <div className="relative hidden sm:block">
                            <svg className="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
                                <circle cx="11" cy="11" r="7" />
                                <path d="m20 20-3.5-3.5" />
                            </svg>
                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search voiceovers…"
                                className="h-8 w-40 rounded-lg border border-white/10 bg-white/[0.03] pe-3 ps-8 text-[12px] text-zinc-200 outline-none placeholder:text-zinc-600 focus:border-orange-400/40 md:w-52"
                            />
                        </div>

                        <div className="relative" ref={filtersRef}>
                            <button
                                type="button"
                                onClick={() => setFiltersOpen((v) => !v)}
                                className={`inline-flex h-8 items-center gap-1.5 rounded-lg border px-2.5 text-[12px] font-medium transition ${
                                    filtersOpen || libraryFilterCount > 0
                                        ? 'border-orange-400/40 bg-orange-500/10 text-orange-100'
                                        : 'border-white/10 bg-white/[0.03] text-zinc-400 hover:text-zinc-200'
                                }`}
                            >
                                <IconFilter className="h-3.5 w-3.5" />
                                <span className="hidden sm:inline">Filters</span>
                                {libraryFilterCount > 0 && (
                                    <span className="rounded bg-[#FF5733]/25 px-1.5 text-[10px] font-semibold text-[#ffb39f]">{libraryFilterCount}</span>
                                )}
                            </button>
                            <AnimatePresence>
                                {filtersOpen && (
                                    <>
                                        <motion.button
                                            type="button"
                                            aria-label="Close filters"
                                            initial={{ opacity: 0 }}
                                            animate={{ opacity: 1 }}
                                            exit={{ opacity: 0 }}
                                            className="fixed inset-0 z-[55] bg-black/55 md:hidden"
                                            onClick={() => setFiltersOpen(false)}
                                        />
                                        <motion.div
                                            initial={{ opacity: 0, y: 24 }}
                                            animate={{ opacity: 1, y: 0 }}
                                            exit={{ opacity: 0, y: 16 }}
                                            className="fixed inset-x-0 bottom-0 z-[60] max-h-[85dvh] overflow-y-auto rounded-t-2xl border border-white/10 bg-black p-4 pb-[max(1rem,env(safe-area-inset-bottom))] shadow-2xl md:absolute md:inset-x-auto md:bottom-auto md:end-0 md:mt-2 md:max-h-none md:w-64 md:rounded-xl md:pb-3"
                                        >
                                        <div className="mx-auto mb-2 h-1 w-10 rounded-full bg-white/15 md:hidden" />
                                        <div className="mb-2 flex items-center justify-between gap-2 md:mb-0">
                                            <p className="text-[13px] font-semibold text-zinc-100 md:hidden">Filters</p>
                                            <button
                                                type="button"
                                                onClick={() => setFiltersOpen(false)}
                                                className="rounded-md p-1 text-zinc-500 hover:text-zinc-200 md:hidden"
                                                aria-label="Close"
                                            >
                                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                    <path d="M18 6 6 18M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>
                                        {libraryFilterCount > 0 && (
                                            <button type="button" onClick={resetFilters} className="absolute end-2.5 top-2.5 hidden rounded-md p-1 text-zinc-500 hover:text-zinc-200 md:block" title="Reset">
                                                <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                    <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
                                                    <path d="M3 3v5h5" />
                                                </svg>
                                            </button>
                                        )}
                                        <p className="mb-2 text-[11px] font-medium uppercase tracking-wider text-zinc-500">Time</p>
                                        <div className="flex flex-wrap gap-1.5">
                                            {TIME_FILTERS.map((f) => (
                                                <button
                                                    key={f.id}
                                                    type="button"
                                                    onClick={() => setTimeFilter(f.id)}
                                                    className={`rounded-lg px-2 py-1 text-[11px] font-medium ${
                                                        timeFilter === f.id
                                                            ? 'bg-orange-500/15 text-orange-100 ring-1 ring-orange-400/40'
                                                            : 'bg-white/[0.04] text-zinc-400 hover:text-zinc-200'
                                                    }`}
                                                >
                                                    {f.label}
                                                </button>
                                            ))}
                                        </div>
                                        {libraryFilterCount > 0 && (
                                            <button
                                                type="button"
                                                onClick={resetFilters}
                                                className="mt-4 w-full rounded-xl border border-white/10 py-2.5 text-[12px] font-medium text-zinc-300 md:hidden"
                                            >
                                                Reset filters
                                            </button>
                                        )}
                                        </motion.div>
                                    </>
                                )}
                            </AnimatePresence>
                        </div>

                        <button
                            type="button"
                            onClick={() => setFavoritesOnly((v) => !v)}
                            className={`inline-flex h-8 w-8 items-center justify-center rounded-lg border ${
                                favoritesOnly ? 'border-orange-400/40 bg-orange-500/10 text-orange-200' : 'border-white/10 text-zinc-400'
                            }`}
                        >
                            <svg className="h-3.5 w-3.5" fill={favoritesOnly ? 'currentColor' : 'none'} viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
                                <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z" />
                            </svg>
                        </button>

                        <button
                            type="button"
                            onClick={() => (selectMode ? exitSelectMode() : setSelectMode(true))}
                            className={`inline-flex h-8 items-center rounded-lg border px-2.5 text-[12px] font-medium ${
                                selectMode ? 'border-orange-400/40 bg-orange-500/10 text-orange-100' : 'border-white/10 text-zinc-400'
                            }`}
                        >
                            {selectMode ? 'Done' : 'Select'}
                        </button>
                    </div>
                </div>

                {tab === 'generation' && (
                    <div className="border-t border-white/[0.05] px-3 py-2 sm:hidden">
                        <div className="relative">
                            <svg className="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
                                <circle cx="11" cy="11" r="7" />
                                <path d="m20 20-3.5-3.5" />
                            </svg>
                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search voiceovers…"
                                className="h-9 w-full rounded-lg border border-white/10 bg-white/[0.03] pe-3 ps-8 text-[13px] text-zinc-200 outline-none placeholder:text-zinc-600 focus:border-orange-400/40"
                            />
                        </div>
                    </div>
                )}

                {activeFilterCount > 0 && (
                    <div className="flex flex-wrap gap-1.5 border-t border-white/[0.05] px-3 py-2 md:px-4">
                        {search.trim() && <Chip onClear={() => setSearch('')}>“{search.trim()}”</Chip>}
                        {favoritesOnly && <Chip onClear={() => setFavoritesOnly(false)}>Favorites</Chip>}
                        {timeFilter !== 'all' && (
                            <Chip onClear={() => setTimeFilter('all')}>{TIME_FILTERS.find((t) => t.id === timeFilter)?.label}</Chip>
                        )}
                    </div>
                )}

                {selectMode && selected.length > 0 && (
                    <div className="flex gap-2 border-t border-white/[0.05] px-3 py-2">
                        <button
                            type="button"
                            onClick={() => onDelete?.(selected)}
                            className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-red-500/30 bg-red-500/10 px-2.5 text-[12px] font-medium text-red-300"
                        >
                            Delete ({selected.length})
                        </button>
                    </div>
                )}
            </div>

            <div className="relative min-h-0 flex-1 overflow-y-auto scrollbar-thin">
                {tab === 'playlists' ? (
                    <div className="flex h-full flex-col items-center justify-center gap-3 p-8 text-center">
                        <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-white/[0.04] ring-1 ring-white/10">
                            <IconAlbums className="h-6 w-6 text-zinc-500" />
                        </div>
                        <p className="text-sm font-medium text-zinc-300">Collections coming soon</p>
                        <p className="max-w-xs text-xs text-zinc-500">Organize voiceovers into reusable sets.</p>
                    </div>
                ) : !hasVoices && !generating ? (
                    <div className="flex h-full flex-col items-center justify-center gap-3 p-8 text-center">
                        <div className="relative">
                            <div className="absolute -inset-4 rounded-full bg-[#FF5733]/10 blur-2xl" />
                            <div className="relative flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500/20 to-transparent ring-1 ring-orange-400/20">
                                <IconMic className="h-7 w-7 text-orange-300" />
                            </div>
                        </div>
                        <p className="text-sm font-medium text-zinc-200">No voiceovers yet</p>
                        <p className="max-w-sm text-xs text-zinc-500">Enter text on the left and Create to generate speech.</p>
                    </div>
                ) : (
                    <div className="space-y-px p-2 md:p-3">
                        {generating && (
                            <div className="mb-2 flex items-center gap-2.5 overflow-hidden rounded-xl border border-orange-400/20 bg-orange-500/[0.06] px-3 py-2.5">
                                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-gradient-to-br from-violet-500/40 to-orange-500/30">
                                    <span className="h-4 w-4 animate-spin rounded-full border-2 border-orange-300/30 border-t-orange-300" />
                                </div>
                                <div className="min-w-0">
                                    <p className="text-[13px] font-medium text-orange-100">Generating voiceover…</p>
                                    <p className="text-[11px] text-orange-200/50">Usually takes a few seconds</p>
                                </div>
                            </div>
                        )}

                        {filtered.length === 0 ? (
                            <div className="rounded-xl border border-dashed border-white/10 py-16 text-center text-sm text-zinc-500">
                                No voiceovers match your filters
                            </div>
                        ) : (
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                                {filtered.map((item, index) => {
                                    const isPlaying = playingId === item.id;
                                    const isSelected = selected.includes(item.id);
                                    const thumb = musicPalette(item.id);
                                    const voiceShort = item.voice.split(' - ')[0];
                                    return (
                                        <motion.div
                                            key={item.id}
                                            initial={{ opacity: 0, scale: 0.98 }}
                                            animate={{ opacity: 1, scale: 1 }}
                                            transition={{ delay: Math.min(index * 0.02, 0.15) }}
                                            className={`group relative flex flex-col overflow-hidden rounded-xl border transition ${
                                                isSelected
                                                    ? 'border-orange-400/40 bg-orange-500/[0.1]'
                                                    : isPlaying
                                                      ? 'border-orange-400/25 bg-white/[0.05]'
                                                      : 'border-white/[0.06] bg-white/[0.025] hover:border-white/12 hover:bg-white/[0.04]'
                                            }`}
                                        >
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    selectMode
                                                        ? toggleSelect(item.id)
                                                        : item.status && item.status !== 'completed'
                                                          ? undefined
                                                          : togglePlay(item)
                                                }
                                                disabled={!selectMode && (!item.audioUrl || (item.status != null && item.status !== 'completed'))}
                                                className="relative aspect-[4/3] w-full overflow-hidden disabled:cursor-wait"
                                                style={{ background: thumb.base }}
                                            >
                                                <span
                                                    aria-hidden
                                                    className="pointer-events-none absolute -left-1/4 -top-1/4 h-[70%] w-[70%] rounded-full blur-xl opacity-80"
                                                    style={{ background: thumb.blobA }}
                                                />
                                                <span
                                                    aria-hidden
                                                    className="pointer-events-none absolute -bottom-1/4 -right-1/4 h-[75%] w-[75%] rounded-full blur-xl opacity-70"
                                                    style={{ background: thumb.blobB }}
                                                />
                                                <div className="absolute inset-0 bg-gradient-to-t from-black/55 via-black/10 to-transparent" />
                                                <span className="absolute inset-0 flex items-center justify-center">
                                                    <span className="flex h-9 w-9 items-center justify-center rounded-full bg-black/45 ring-1 ring-white/20 backdrop-blur-sm transition group-hover:scale-105">
                                                        {item.status && item.status !== 'completed' && item.status !== 'failed' ? (
                                                            <span className="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white" />
                                                        ) : item.status === 'failed' ? (
                                                            <span className="text-[10px] font-bold text-red-200">!</span>
                                                        ) : isPlaying ? (
                                                            <span className="flex gap-0.5">
                                                                <span className="h-3 w-0.5 animate-pulse rounded-full bg-white" />
                                                                <span className="h-3 w-0.5 animate-pulse rounded-full bg-white [animation-delay:100ms]" />
                                                                <span className="h-3 w-0.5 animate-pulse rounded-full bg-white [animation-delay:200ms]" />
                                                            </span>
                                                        ) : (
                                                            <svg className="ms-0.5 h-4 w-4 fill-white" viewBox="0 0 24 24">
                                                                <polygon points="6 3 20 12 6 21 6 3" />
                                                            </svg>
                                                        )}
                                                    </span>
                                                </span>
                                                {(item.progress || item.error) && item.status && item.status !== 'completed' && (
                                                    <span className="absolute inset-x-1.5 bottom-1.5 truncate rounded bg-black/55 px-1.5 py-0.5 text-[10px] text-white/85">
                                                        {item.error || item.progress}
                                                    </span>
                                                )}
                                                {item.duration && (
                                                    <span className="absolute bottom-1.5 end-1.5 rounded bg-black/55 px-1.5 py-0.5 text-[10px] tabular-nums text-white/85">
                                                        {item.duration}
                                                    </span>
                                                )}
                                                {isPlaying && (
                                                    <span className="absolute start-1.5 top-1.5 rounded-full bg-[#FF5733]/90 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-white">
                                                        Live
                                                    </span>
                                                )}
                                                {selectMode && (
                                                    <span
                                                        className={`absolute end-1.5 top-1.5 flex h-5 w-5 items-center justify-center rounded-md text-[10px] ${
                                                            isSelected ? 'bg-[#FF5733] text-white' : 'bg-black/55 text-white/70 ring-1 ring-white/25'
                                                        }`}
                                                    >
                                                        {isSelected ? '✓' : ''}
                                                    </span>
                                                )}
                                                {!selectMode && (
                                                    <div className="absolute end-1 top-1 flex flex-col gap-0.5 opacity-0 transition group-hover:opacity-100">
                                                        <IconBtn title="Favorite" active={item.favorite} onClick={() => onToggleFavorite?.(item.id)}>
                                                            <svg
                                                                className="h-3.5 w-3.5"
                                                                fill={item.favorite ? 'currentColor' : 'none'}
                                                                viewBox="0 0 24 24"
                                                                stroke="currentColor"
                                                                strokeWidth="1.75"
                                                            >
                                                                <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z" />
                                                            </svg>
                                                        </IconBtn>
                                                        <IconBtn title="Delete" onClick={() => onDelete?.([item.id])}>
                                                            <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                                                <path d="M4.5 7h15" />
                                                                <path d="M9.5 7V5.5A1.5 1.5 0 0 1 11 4h2a1.5 1.5 0 0 1 1.5 1.5V7" />
                                                                <path d="M18 7v11.5a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V7" />
                                                            </svg>
                                                        </IconBtn>
                                                    </div>
                                                )}
                                            </button>

                                            <button
                                                type="button"
                                                onClick={() => (selectMode ? toggleSelect(item.id) : openPreview(item.id))}
                                                className="flex min-h-0 flex-1 flex-col gap-0.5 px-2.5 py-2 text-start"
                                            >
                                                <p className="truncate text-[12px] font-semibold text-zinc-100">{item.title}</p>
                                                <p className="truncate text-[10px] text-zinc-500">{voiceShort}</p>
                                                <p className="line-clamp-2 text-[10px] leading-snug text-zinc-600">{item.text}</p>
                                            </button>
                                        </motion.div>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                )}
            </div>

            <AnimatePresence>
                {preview && previewIndex !== null && (
                    <VoiceLabPreviewModal
                        key={preview.id}
                        voice={preview}
                        index={previewIndex}
                        total={previewable.length}
                        onClose={closePreview}
                        onPrev={() =>
                            setPreviewIndex((i) => (i === null ? 0 : (i - 1 + previewable.length) % previewable.length))
                        }
                        onNext={() => setPreviewIndex((i) => (i === null ? 0 : (i + 1) % previewable.length))}
                        onToggleFavorite={onToggleFavorite}
                        onDelete={onDelete}
                        onPlayingChange={(playing) => setPlayingId(playing ? preview.id : null)}
                    />
                )}
            </AnimatePresence>
        </div>
    );
}

function Chip({ children, onClear }: { children: ReactNode; onClear: () => void }) {
    return (
        <span className="inline-flex items-center gap-1 rounded-lg border border-white/10 bg-white/[0.04] py-1 pe-1 ps-2 text-[11px] text-zinc-300">
            {children}
            <button type="button" onClick={onClear} className="rounded p-0.5 text-white/40 hover:text-white">
                ×
            </button>
        </span>
    );
}

function IconBtn({
    children,
    title,
    onClick,
    active,
}: {
    children: ReactNode;
    title: string;
    onClick?: () => void;
    active?: boolean;
}) {
    return (
        <button
            type="button"
            title={title}
            onClick={(e) => {
                e.stopPropagation();
                onClick?.();
            }}
            className={`inline-flex h-7 w-7 items-center justify-center rounded-lg bg-black/45 text-white/85 backdrop-blur-sm transition hover:bg-black/65 hover:text-white ${
                active ? 'text-[#ffb39f]' : ''
            }`}
        >
            {children}
        </button>
    );
}

function IconMic({ className = 'h-4 w-4' }: { className?: string }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
            <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z" />
            <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
            <line x1="12" x2="12" y1="19" y2="22" />
        </svg>
    );
}

function IconFilter({ className = 'h-4 w-4' }: { className?: string }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
            <path d="M4 6h16" />
            <path d="M7 12h10" />
            <path d="M10 18h4" />
        </svg>
    );
}

function IconAlbums({ className = 'h-4 w-4' }: { className?: string }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
            <rect x="3" y="5" width="14" height="14" rx="2.5" />
            <path d="M8 3h10a3 3 0 0 1 3 3v10" />
        </svg>
    );
}
