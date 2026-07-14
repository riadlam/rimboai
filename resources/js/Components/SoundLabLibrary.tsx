import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import MusicLabPreviewModal from '@/Components/MusicLabPreviewModal';
import { musicPalette } from '@/lib/musicPalette';

export type LabTrack = {
    id: string;
    title: string;
    style: string;
    lyrics?: string;
    cover: string;
    favorite: boolean;
    createdAt: number;
    instrumental: boolean;
    model?: string;
    duration?: string;
    audioUrl?: string;
    creationId?: number;
    status?: 'pending' | 'queued' | 'in_progress' | 'completed' | 'failed' | 'cancelled';
    progress?: string | null;
    error?: string | null;
    /** Brief flash at 100% before the real track appears */
    completing?: boolean;
};

type Props = {
    tracks?: LabTrack[];
    onToggleFavorite?: (id: string) => void;
    onDelete?: (ids: string[]) => void;
    generating?: boolean;
};

function isBuildingStatus(status?: LabTrack['status']) {
    return status !== undefined && status !== 'completed' && status !== 'failed' && status !== 'cancelled';
}

const TIME_FILTERS = [
    { id: 'all', label: 'All time', days: null },
    { id: '1d', label: 'Past 1 day', days: 1 },
    { id: '2d', label: 'Past 2 days', days: 2 },
    { id: '3d', label: 'Past 3 days', days: 3 },
    { id: '7d', label: 'Past 7 days', days: 7 },
] as const;

const TYPE_FILTERS = [
    { id: 'vocals', label: 'With Vocals' },
    { id: 'instrumental', label: 'Instrumental' },
] as const;

type TimeFilterId = (typeof TIME_FILTERS)[number]['id'];
type TypeFilterId = 'all' | (typeof TYPE_FILTERS)[number]['id'];

export default function SoundLabLibrary({
    tracks = [],
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
    const [typeFilter, setTypeFilter] = useState<TypeFilterId>('all');
    const [playingId, setPlayingId] = useState<string | null>(null);
    const [previewIndex, setPreviewIndex] = useState<number | null>(null);
    const filtersRef = useRef<HTMLDivElement>(null);
    const audioRef = useRef<HTMLAudioElement | null>(null);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        const timeOpt = TIME_FILTERS.find((t) => t.id === timeFilter);
        const cutoff = timeOpt?.days != null ? Date.now() - timeOpt.days * 24 * 60 * 60 * 1000 : null;

        return tracks.filter((t) => {
            if (favoritesOnly && !t.favorite) return false;
            if (cutoff != null && t.createdAt < cutoff) return false;
            if (typeFilter === 'vocals' && t.instrumental) return false;
            if (typeFilter === 'instrumental' && !t.instrumental) return false;
            if (q && !`${t.title} ${t.style}`.toLowerCase().includes(q)) return false;
            return true;
        });
    }, [tracks, search, favoritesOnly, timeFilter, typeFilter]);

    const previewable = useMemo(
        () => filtered.filter((t) => !isBuildingStatus(t.status) && t.status !== 'failed' && t.status !== 'cancelled'),
        [filtered],
    );
    const preview = previewIndex !== null ? previewable[previewIndex] ?? null : null;
    const hasTracks = tracks.length > 0;
    const hasBuilding = tracks.some((t) => isBuildingStatus(t.status));
    const libraryFilterCount = (timeFilter !== 'all' ? 1 : 0) + (typeFilter !== 'all' ? 1 : 0);
    const activeFilterCount = libraryFilterCount + (favoritesOnly ? 1 : 0) + (search.trim() ? 1 : 0);

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
        setTypeFilter('all');
        setFiltersOpen(false);
    };

    const openPreview = (id: string) => {
        audioRef.current?.pause();
        setPlayingId(null);
        const idx = previewable.findIndex((t) => t.id === id);
        if (idx >= 0) setPreviewIndex(idx);
    };

    const closePreview = () => {
        setPreviewIndex(null);
        setPlayingId(null);
    };

    const togglePlay = (id: string) => {
        const track = tracks.find((t) => t.id === id);
        if (!track?.audioUrl) {
            setPlayingId((prev) => (prev === id ? null : id));
            return;
        }

        if (playingId === id) {
            audioRef.current?.pause();
            setPlayingId(null);
            return;
        }

        audioRef.current?.pause();
        const audio = new Audio(track.audioUrl);
        audioRef.current = audio;
        setPlayingId(id);
        audio.play().catch(() => setPlayingId(null));
        audio.onended = () => setPlayingId(null);
    };

    useEffect(() => {
        return () => {
            audioRef.current?.pause();
            audioRef.current = null;
        };
    }, []);

    return (
        <div className="relative flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden bg-[#08080d] [&_a]:cursor-pointer [&_button]:cursor-pointer [&_button:disabled]:cursor-not-allowed">
            <div className="relative z-20 shrink-0 border-b border-white/[0.07] bg-[#0c0c12]">
                <div className="flex h-12 items-center justify-between gap-2 px-3 md:px-4">
                    <div className="inline-flex items-center rounded-xl border border-white/[0.06] bg-[#101016] p-1 shadow-[inset_0_1px_0_rgba(255,255,255,0.04)]">
                        {(
                            [
                                { id: 'generation' as const, label: 'Tracks', Icon: IconMusic },
                                { id: 'playlists' as const, label: 'Playlists', Icon: IconAlbums },
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
                                        setFiltersOpen(false);
                                    }}
                                    className={`relative inline-flex cursor-pointer items-center gap-2 rounded-lg px-3.5 py-2 text-[13px] font-medium tracking-tight transition ${
                                        active
                                            ? 'bg-gradient-to-b from-white/[0.12] to-white/[0.05] text-zinc-50 shadow-[0_1px_0_rgba(255,255,255,0.08)_inset,0_6px_16px_rgba(0,0,0,0.25)] ring-1 ring-white/10'
                                            : 'text-zinc-500 hover:bg-white/[0.03] hover:text-zinc-300'
                                    }`}
                                >
                                    <ItemIcon className={`h-3.5 w-3.5 ${active ? 'text-[#FF5733]' : 'text-current'}`} />
                                    <span>{item.label}</span>
                                    {item.id === 'generation' && hasTracks && (
                                        <span
                                            className={`rounded-md px-1.5 py-0.5 text-[10px] font-semibold tabular-nums ${
                                                active ? 'bg-[#FF5733]/20 text-[#ffb39f]' : 'bg-white/[0.06] text-zinc-500'
                                            }`}
                                        >
                                            {tracks.length}
                                        </span>
                                    )}
                                </button>
                            );
                        })}
                    </div>

                    {selectMode && selected.length > 0 && (
                        <div className="hidden items-center gap-1.5 sm:flex">
                            <button
                                type="button"
                                onClick={() => onDelete?.(selected)}
                                className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-red-500/30 bg-red-500/10 px-2.5 text-[12px] font-medium text-red-300 hover:bg-red-500/20"
                            >
                                <IconTrash className="h-3.5 w-3.5" />
                                Delete ({selected.length})
                            </button>
                        </div>
                    )}

                    <div className="flex items-center gap-1.5">
                        <div className="relative hidden sm:block">
                            <svg className="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
                                <circle cx="11" cy="11" r="7" />
                                <path d="m20 20-3.5-3.5" />
                            </svg>
                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search tracks…"
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
                                    <span className="rounded bg-[#FF5733]/25 px-1.5 text-[10px] font-semibold text-[#ffb39f]">
                                        {libraryFilterCount}
                                    </span>
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
                                            className="fixed inset-x-0 bottom-0 z-[60] max-h-[85dvh] overflow-y-auto rounded-t-2xl border border-white/10 bg-[#121218] p-4 pb-[max(1rem,env(safe-area-inset-bottom))] shadow-2xl md:absolute md:inset-x-auto md:bottom-auto md:end-0 md:mt-2 md:max-h-none md:w-64 md:rounded-xl md:pb-3"
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
                                            <button
                                                type="button"
                                                onClick={resetFilters}
                                                className="absolute end-2.5 top-2.5 hidden rounded-md p-1 text-zinc-500 hover:bg-white/[0.06] hover:text-zinc-200 md:block"
                                                title="Reset filters"
                                            >
                                                <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                    <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
                                                    <path d="M3 3v5h5" />
                                                </svg>
                                            </button>
                                        )}
                                        <p className="mb-2 text-[11px] font-medium uppercase tracking-wider text-zinc-500">Time</p>
                                        <div className="mb-3 flex flex-wrap gap-1.5">
                                            {TIME_FILTERS.map((f) => (
                                                <button
                                                    key={f.id}
                                                    type="button"
                                                    onClick={() => setTimeFilter(f.id)}
                                                    className={`rounded-lg px-2 py-1 text-[11px] font-medium transition ${
                                                        timeFilter === f.id
                                                            ? 'bg-orange-500/15 text-orange-100 ring-1 ring-orange-400/40'
                                                            : 'bg-white/[0.04] text-zinc-400 hover:text-zinc-200'
                                                    }`}
                                                >
                                                    {f.label}
                                                </button>
                                            ))}
                                        </div>
                                        <p className="mb-2 text-[11px] font-medium uppercase tracking-wider text-zinc-500">Type</p>
                                        <div className="flex flex-wrap gap-1.5">
                                            {TYPE_FILTERS.map((f) => (
                                                <button
                                                    key={f.id}
                                                    type="button"
                                                    onClick={() => setTypeFilter((prev) => (prev === f.id ? 'all' : f.id))}
                                                    className={`rounded-lg px-2 py-1 text-[11px] font-medium transition ${
                                                        typeFilter === f.id
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
                            className={`inline-flex h-8 w-8 items-center justify-center rounded-lg border transition ${
                                favoritesOnly
                                    ? 'border-orange-400/40 bg-orange-500/10 text-orange-200'
                                    : 'border-white/10 bg-white/[0.03] text-zinc-400 hover:text-zinc-200'
                            }`}
                            title="Favorites"
                        >
                            <svg className="h-3.5 w-3.5" fill={favoritesOnly ? 'currentColor' : 'none'} viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
                                <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z" />
                            </svg>
                        </button>

                        <button
                            type="button"
                            onClick={() => (selectMode ? exitSelectMode() : setSelectMode(true))}
                            className={`inline-flex h-8 items-center rounded-lg border px-2.5 text-[12px] font-medium transition ${
                                selectMode
                                    ? 'border-orange-400/40 bg-orange-500/10 text-orange-100'
                                    : 'border-white/10 bg-white/[0.03] text-zinc-400 hover:text-zinc-200'
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
                                placeholder="Search tracks…"
                                className="h-9 w-full rounded-lg border border-white/10 bg-white/[0.03] pe-3 ps-8 text-[13px] text-zinc-200 outline-none placeholder:text-zinc-600 focus:border-orange-400/40"
                            />
                        </div>
                    </div>
                )}

                {activeFilterCount > 0 && (
                    <div className="flex flex-wrap items-center gap-1.5 border-t border-white/[0.05] px-3 py-2 md:px-4">
                        {search.trim() && <Chip onClear={() => setSearch('')}>“{search.trim()}”</Chip>}
                        {favoritesOnly && <Chip onClear={() => setFavoritesOnly(false)}>Favorites</Chip>}
                        {timeFilter !== 'all' && (
                            <Chip onClear={() => setTimeFilter('all')}>
                                {TIME_FILTERS.find((t) => t.id === timeFilter)?.label}
                            </Chip>
                        )}
                        {typeFilter !== 'all' && (
                            <Chip onClear={() => setTypeFilter('all')}>
                                {TYPE_FILTERS.find((t) => t.id === typeFilter)?.label}
                            </Chip>
                        )}
                    </div>
                )}
            </div>

            <div className="relative min-h-0 flex-1 overflow-y-auto scrollbar-thin">
                {tab === 'playlists' ? (
                    <div className="flex h-full flex-col items-center justify-center gap-3 p-8 text-center">
                        <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-white/[0.04] ring-1 ring-white/10">
                            <IconAlbums className="h-6 w-6 text-zinc-500" />
                        </div>
                        <p className="text-sm font-medium text-zinc-300">Playlists coming soon</p>
                        <p className="max-w-xs text-xs text-zinc-500">Group your generated tracks into curated sets.</p>
                    </div>
                ) : !hasTracks && !generating && !hasBuilding ? (
                    <div className="flex h-full flex-col items-center justify-center gap-3 p-8 text-center">
                        <div className="relative">
                            <div className="absolute -inset-4 rounded-full bg-[#FF5733]/10 blur-2xl" />
                            <div className="relative flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500/20 to-transparent ring-1 ring-orange-400/20">
                                <IconMusic className="h-7 w-7 text-orange-300" />
                            </div>
                        </div>
                        <p className="text-sm font-medium text-zinc-200">No tracks yet</p>
                        <p className="max-w-sm text-xs text-zinc-500">Pick a sample or describe a style on the left, then Create.</p>
                    </div>
                ) : (
                    <div className="space-y-2 p-3 md:p-4">
                        {filtered.length === 0 ? (
                            <div className="rounded-xl border border-dashed border-white/10 py-16 text-center text-sm text-zinc-500">
                                No tracks match your filters
                            </div>
                        ) : (
                            filtered.map((track, index) => {
                                if (isBuildingStatus(track.status)) {
                                    return (
                                        <MusicBuildingCard
                                            key={track.id}
                                            title={track.title}
                                            stylePrompt={track.style}
                                            status={track.status}
                                            startedAt={track.createdAt}
                                            completing={track.completing}
                                            instrumental={track.instrumental}
                                        />
                                    );
                                }

                                if (track.status === 'failed' || track.status === 'cancelled') {
                                    return (
                                        <MusicFailedCard
                                            key={track.id}
                                            title={track.title}
                                            error={track.error}
                                            onDismiss={() => onDelete?.([track.id])}
                                        />
                                    );
                                }

                                const isPlaying = playingId === track.id;
                                const isSelected = selected.includes(track.id);
                                const thumb = musicPalette(track.id);
                                return (
                                    <motion.div
                                        key={track.id}
                                        initial={{ opacity: 0, y: 8 }}
                                        animate={{ opacity: 1, y: 0 }}
                                        transition={{ delay: Math.min(index * 0.03, 0.2) }}
                                        className={`group relative flex items-center gap-3 overflow-hidden rounded-2xl border p-2.5 transition ${
                                            isSelected
                                                ? 'border-orange-400/40 bg-orange-500/[0.08]'
                                                : 'border-white/[0.06] bg-white/[0.025] hover:border-white/12 hover:bg-white/[0.04]'
                                        }`}
                                    >
                                        <button
                                            type="button"
                                            onClick={() => (selectMode ? toggleSelect(track.id) : openPreview(track.id))}
                                            className="relative h-14 w-14 shrink-0 overflow-hidden rounded-xl ring-1 ring-white/10"
                                            style={{ background: thumb.base }}
                                        >
                                            <span
                                                aria-hidden
                                                className="pointer-events-none absolute -left-1/4 -top-1/4 h-[70%] w-[70%] rounded-full blur-md opacity-80"
                                                style={{ background: thumb.blobA }}
                                            />
                                            <span
                                                aria-hidden
                                                className="pointer-events-none absolute -bottom-1/4 -right-1/4 h-[75%] w-[75%] rounded-full blur-md opacity-70"
                                                style={{ background: thumb.blobB }}
                                            />
                                            <div className="absolute inset-0 bg-black/35" />
                                            {!selectMode && (
                                                <span
                                                    className="absolute inset-0 flex items-center justify-center"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        togglePlay(track.id);
                                                    }}
                                                >
                                                    <span className="flex h-8 w-8 items-center justify-center rounded-full bg-black/65 ring-1 ring-white/20 backdrop-blur-sm">
                                                        {isPlaying ? (
                                                            <span className="flex gap-0.5">
                                                                <span className="h-3 w-0.5 animate-pulse rounded-full bg-white" />
                                                                <span className="h-3 w-0.5 animate-pulse rounded-full bg-white [animation-delay:100ms]" />
                                                                <span className="h-3 w-0.5 animate-pulse rounded-full bg-white [animation-delay:200ms]" />
                                                            </span>
                                                        ) : (
                                                            <svg className="ms-0.5 h-3.5 w-3.5 fill-white" viewBox="0 0 24 24">
                                                                <polygon points="6 3 20 12 6 21 6 3" />
                                                            </svg>
                                                        )}
                                                    </span>
                                                </span>
                                            )}
                                            {selectMode && (
                                                <span
                                                    className={`absolute end-1 top-1 flex h-5 w-5 items-center justify-center rounded-md text-[10px] ${
                                                        isSelected ? 'bg-[#FF5733] text-white' : 'bg-black/50 text-white/70 ring-1 ring-white/20'
                                                    }`}
                                                >
                                                    {isSelected ? '✓' : ''}
                                                </span>
                                            )}
                                        </button>

                                        <button
                                            type="button"
                                            onClick={() => (selectMode ? toggleSelect(track.id) : openPreview(track.id))}
                                            className="min-w-0 flex-1 text-start"
                                        >
                                            <div className="flex items-center gap-2">
                                                <p className="truncate text-[13px] font-semibold text-zinc-100">{track.title}</p>
                                                {isPlaying && (
                                                    <span className="shrink-0 rounded-full bg-[#FF5733]/20 px-1.5 py-0.5 text-[9px] font-medium text-[#ffb39f]">
                                                        Playing
                                                    </span>
                                                )}
                                            </div>
                                            <p className="mt-0.5 line-clamp-1 text-[12px] text-zinc-500">{track.style}</p>
                                            <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                                                <span className="rounded-md border border-white/10 bg-white/[0.03] px-1.5 py-0.5 text-[10px] text-zinc-400">
                                                    {track.instrumental ? 'Instrumental' : 'Vocals'}
                                                </span>
                                                {track.duration && (
                                                    <span className="text-[10px] text-zinc-600">{track.duration}</span>
                                                )}
                                                {track.model && (
                                                    <span className="text-[10px] text-zinc-600">{track.model}</span>
                                                )}
                                            </div>
                                        </button>

                                        {!selectMode && (
                                            <div className="flex shrink-0 flex-col items-center gap-1 opacity-0 transition group-hover:opacity-100 max-sm:opacity-100">
                                                <IconBtn
                                                    title="Play"
                                                    onClick={() => togglePlay(track.id)}
                                                    active={isPlaying}
                                                >
                                                    {isPlaying ? (
                                                        <svg className="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24">
                                                            <rect x="6" y="5" width="4" height="14" rx="1" />
                                                            <rect x="14" y="5" width="4" height="14" rx="1" />
                                                        </svg>
                                                    ) : (
                                                        <svg className="h-3.5 w-3.5 fill-current" viewBox="0 0 24 24">
                                                            <polygon points="6 3 20 12 6 21 6 3" />
                                                        </svg>
                                                    )}
                                                </IconBtn>
                                                <IconBtn
                                                    title="Favorite"
                                                    onClick={() => onToggleFavorite?.(track.id)}
                                                    active={track.favorite}
                                                >
                                                    <svg
                                                        className="h-3.5 w-3.5"
                                                        fill={track.favorite ? 'currentColor' : 'none'}
                                                        viewBox="0 0 24 24"
                                                        stroke="currentColor"
                                                        strokeWidth="1.75"
                                                    >
                                                        <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z" />
                                                    </svg>
                                                </IconBtn>
                                                <IconBtn title="Delete" onClick={() => onDelete?.([track.id])}>
                                                    <IconTrash className="h-3.5 w-3.5" />
                                                </IconBtn>
                                            </div>
                                        )}
                                    </motion.div>
                                );
                            })
                        )}
                    </div>
                )}
            </div>

            {/* Preview / music player — full lab modal like video */}
            <AnimatePresence>
                {preview && previewIndex !== null && (
                    <MusicLabPreviewModal
                        key={preview.id}
                        track={preview}
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

/** Same long-running curve as video — music gens often take minutes. */
function useMusicSmartProgress(startedAt: number, status?: LabTrack['status']) {
    const [pct, setPct] = useState(0);

    useEffect(() => {
        const tick = () => {
            const elapsed = (Date.now() - startedAt) / 1000;
            let next: number;
            const cap = 93;

            if (status === 'in_progress') {
                const t = Math.min(elapsed / 180, 1);
                const eased = 1 - Math.pow(1 - t, 2.15);
                next = 10 + eased * 78; // 10 → 88 over ~3 min
                if (elapsed > 180) {
                    next = 88 + Math.min(5, (elapsed - 180) / 90); // 88 → 93
                }
                next += (Math.sin(elapsed / 9) + 1) * 0.35;
            } else if (status === 'queued') {
                next = Math.min(22, 5 + elapsed * 0.28);
            } else {
                next = Math.min(12, 3 + elapsed * 1.2);
            }

            const rounded = Math.round(Math.min(cap, next));
            setPct((prev) => Math.min(cap, Math.max(prev, rounded)));
        };

        tick();
        const id = window.setInterval(tick, 320);
        return () => window.clearInterval(id);
    }, [startedAt, status]);

    return pct;
}

function musicPhaseLabel(pct: number, status?: LabTrack['status'], completing?: boolean): string {
    if (completing) return 'Done';
    if (status === 'queued') return 'In queue';
    if (status === 'pending') return 'Starting';
    if (pct < 28) return 'Writing arrangement';
    if (pct < 55) return 'Composing melody';
    if (pct < 78) return 'Mixing layers';
    if (pct < 90) return 'Mastering';
    return 'Almost ready';
}

function MusicBuildingCard({
    title,
    stylePrompt,
    status,
    startedAt,
    completing = false,
    instrumental,
}: {
    title: string;
    stylePrompt: string;
    status?: LabTrack['status'];
    startedAt: number;
    completing?: boolean;
    instrumental: boolean;
}) {
    const simulated = useMusicSmartProgress(startedAt, status);
    const pct = completing ? 100 : simulated;
    const phase = musicPhaseLabel(pct, status, completing);

    return (
        <motion.div
            layout
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            className="relative overflow-hidden rounded-2xl border border-white/[0.06] bg-[#0e0e13] p-3 ring-1 ring-white/[0.04]"
        >
            <div className="flex items-center gap-3">
                <div className="relative h-16 w-16 shrink-0 overflow-hidden rounded-xl bg-[#14141c]">
                    <motion.div
                        aria-hidden
                        className="absolute inset-0 opacity-70"
                        style={{
                            background:
                                'radial-gradient(70% 60% at 30% 20%, rgba(255,87,51,0.22), transparent), radial-gradient(60% 50% at 75% 80%, rgba(255,140,0,0.12), transparent)',
                        }}
                        animate={{ opacity: [0.45, 0.75, 0.45] }}
                        transition={{ duration: 7, repeat: Infinity, ease: 'easeInOut' }}
                    />
                    <div className="absolute inset-0 flex flex-col items-center justify-center gap-0.5">
                        <span className="text-lg font-light tabular-nums tracking-tight text-white/80">{pct}%</span>
                    </div>
                    <div className="absolute inset-x-0 bottom-0 h-[2px] bg-white/[0.06]">
                        <motion.div
                            className="h-full bg-gradient-to-r from-orange-400/40 via-white/40 to-orange-300/30"
                            animate={{ width: `${pct}%` }}
                            transition={{ duration: 0.45, ease: 'easeOut' }}
                        />
                    </div>
                </div>

                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <p className="truncate text-[13px] font-semibold text-zinc-100">{title || 'New track'}</p>
                        <span className="shrink-0 rounded-md border border-orange-400/25 bg-[#FF5733]/10 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-orange-200">
                            {phase}
                        </span>
                    </div>
                    <p className="mt-0.5 line-clamp-1 text-[12px] text-zinc-500">{stylePrompt}</p>
                    <p className="mt-1 text-[11px] text-white/30">
                        {instrumental ? 'Instrumental' : 'Vocals'} · Music usually takes a few minutes
                    </p>
                </div>
            </div>
        </motion.div>
    );
}

function MusicFailedCard({
    title,
    error,
    onDismiss,
}: {
    title: string;
    error?: string | null;
    onDismiss?: () => void;
}) {
    return (
        <motion.div
            layout
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            className="flex items-center gap-3 rounded-2xl border border-red-500/20 bg-[#160f11] p-3"
        >
            <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl border border-red-500/30 bg-red-500/10 text-red-300">
                <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8">
                    <path d="M12 9v4" />
                    <path d="M12 17h.01" />
                    <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" />
                </svg>
            </div>
            <div className="min-w-0 flex-1">
                <p className="text-[13px] font-semibold text-red-200">Generation failed</p>
                <p className="mt-0.5 line-clamp-2 text-[12px] text-white/40">{error || title}</p>
            </div>
            {onDismiss && (
                <button
                    type="button"
                    onClick={onDismiss}
                    className="rounded-lg border border-white/10 bg-white/[0.05] px-2.5 py-1 text-[11px] font-medium text-white/70 transition hover:bg-white/[0.1] hover:text-white"
                >
                    Dismiss
                </button>
            )}
        </motion.div>
    );
}

function Chip({ children, onClear }: { children: ReactNode; onClear: () => void }) {
    return (
        <span className="inline-flex items-center gap-1 rounded-lg border border-white/10 bg-white/[0.04] py-1 pe-1 ps-2 text-[11px] text-zinc-300">
            {children}
            <button type="button" onClick={onClear} className="rounded p-0.5 text-white/40 hover:text-white" aria-label="Remove filter">
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
            onClick={onClick}
            className={`inline-flex h-7 w-7 items-center justify-center rounded-lg transition ${
                active ? 'bg-[#FF5733]/15 text-[#ffb39f]' : 'text-zinc-400 hover:bg-white/[0.06] hover:text-zinc-100'
            }`}
        >
            {children}
        </button>
    );
}

function IconMusic({ className = 'h-4 w-4' }: { className?: string }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
            <circle cx="8" cy="18" r="4" />
            <path d="M12 18V2l7 4" />
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

function IconTrash({ className = 'h-4 w-4' }: { className?: string }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
            <path d="M4.5 7h15" />
            <path d="M9.5 7V5.5A1.5 1.5 0 0 1 11 4h2a1.5 1.5 0 0 1 1.5 1.5V7" />
            <path d="M18 7v11.5a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V7" />
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
