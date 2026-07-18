import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import ImageLabPreviewModal from '@/Components/ImageLabPreviewModal';
import LabFailedCard from '@/Components/LabFailedCard';
import VideoThumb from '@/Components/VideoThumb';
import { downloadMediaAsset } from '@/lib/downloadMedia';
import { labCompletingRampMs, labEffectiveProgressPercent, labPhaseLabel, labProgressPercent } from '@/lib/labProgress';

export type LabImage = {
    id: string;
    prompt: string;
    src: string;
    favorite: boolean;
    createdAt: number;
    aspect?: string;
    resolution?: string;
    duration?: number | string | null;
    audio?: boolean | null;
    quantity?: number | null;
    imageMode?: 'create' | 'variations' | null;
    inputAssets?: { url: string; kind: 'image' | 'video' | 'audio'; name?: string | null; fallbackUrls?: string[] | null }[];
    method?: 'text-to-image' | 'image-to-image' | 'text-to-video' | 'image-to-video' | 'reference-to-video' | 'first-last-frame-to-video';
    modelName?: string | null;
    status?: 'pending' | 'queued' | 'in_progress' | 'completed' | 'failed' | 'cancelled';
    creationId?: number;
    progress?: string | null;
    queuePosition?: number | null;
    progressPercent?: number | null;
    error?: string | null;
    /** Links multiple placeholders from one generate request */
    batchId?: string;
    batchIndex?: number;
    /** Epoch ms — drives smart fake progress */
    startedAt?: number;
    /** Brief flash at 100% before the real image appears */
    completing?: boolean;
    /** When set, library can play the video instead of a still */
    videoUrl?: string;
};

type Props = {
    images?: LabImage[];
    onToggleFavorite?: (id: string) => void;
    onDelete?: (ids: string[]) => void;
    onReuseSettings?: (image: LabImage) => void;
    onUseResult?: (image: LabImage) => void;
    onUseLastFrame?: (image: LabImage, frameFile?: File) => void | Promise<void>;
    onRevealComplete?: (id: string) => void;
    generating?: boolean;
    /** Tools: hide Albums tab */
    hideAlbums?: boolean;
    /** Tools: hide lab method filters (text-to-video etc.) */
    hideMethodFilters?: boolean;
    /** Tools: hide prompt overlays / detail prompt */
    hidePrompt?: boolean;
};

const TIME_FILTERS = [
    { id: 'all', label: 'All time', days: null },
    { id: '1d', label: 'Past 1 day', days: 1 },
    { id: '2d', label: 'Past 2 days', days: 2 },
    { id: '3d', label: 'Past 3 days', days: 3 },
    { id: '7d', label: 'Past 7 days', days: 7 },
] as const;

const METHOD_FILTERS = [
    { id: 'text-to-image', label: 'Text to Image' },
    { id: 'image-to-image', label: 'Image to Image' },
    { id: 'text-to-video', label: 'Text to Video' },
    { id: 'image-to-video', label: 'Image to Video' },
    { id: 'reference-to-video', label: 'Reference to Video' },
] as const;

type TimeFilterId = (typeof TIME_FILTERS)[number]['id'];
type MethodFilterId = 'all' | (typeof METHOD_FILTERS)[number]['id'];

function IconSparkles({ className = 'h-4 w-4' }: { className?: string }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
            <path d="M12 3v3M12 18v3M3 12h3M18 12h3" />
            <path d="m5.6 5.6 2.1 2.1M16.3 16.3l2.1 2.1M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1" />
            <circle cx="12" cy="12" r="3.25" />
        </svg>
    );
}

function IconAlbums({ className = 'h-4 w-4' }: { className?: string }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
            <rect x="3" y="5" width="14" height="14" rx="2.5" />
            <path d="M8 3h10a3 3 0 0 1 3 3v10" />
            <path d="m7.5 14.5 2.2-2.2a1.5 1.5 0 0 1 2.1 0L14.5 15" />
            <circle cx="9" cy="9.5" r="1.1" fill="currentColor" stroke="none" />
        </svg>
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
            <path d="M9.5 7V5.5A1.5 1.5 0 0 1 11 4h2a1.5 1.5 0 0 1 1.5 1.5V7" />
            <path d="M18 7v11.5a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V7" />
            <path d="M10 11v5M14 11v5" />
        </svg>
    );
}

function IconFilter({ className = 'h-4 w-4' }: { className?: string }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
            <path d="M4 6h16" />
            <path d="M7 12h10" />
            <path d="M10 18h4" />
        </svg>
    );
}

export default function ImageLabLibrary({
    images = [],
    onToggleFavorite,
    onDelete,
    onReuseSettings,
    onUseResult,
    onUseLastFrame,
    onRevealComplete,
    generating = false,
    hideAlbums = false,
    hideMethodFilters = false,
    hidePrompt = false,
}: Props) {
    const { t } = useTranslation('lab');
    const [tab, setTab] = useState<'generation' | 'albums'>('generation');
    const [search, setSearch] = useState('');
    const [columns, setColumns] = useState(6);
    const [favoritesOnly, setFavoritesOnly] = useState(false);
    const [selectMode, setSelectMode] = useState(false);
    const [selected, setSelected] = useState<string[]>([]);
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [timeFilter, setTimeFilter] = useState<TimeFilterId>('all');
    const [methodFilter, setMethodFilter] = useState<MethodFilterId>('all');
    const [previewIndex, setPreviewIndex] = useState<number | null>(null);
    const filtersRef = useRef<HTMLDivElement>(null);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        const timeOpt = TIME_FILTERS.find((t) => t.id === timeFilter);
        const cutoff =
            timeOpt?.days != null ? Date.now() - timeOpt.days * 24 * 60 * 60 * 1000 : null;

        return images.filter((img) => {
            if (favoritesOnly && !img.favorite) return false;
            if (cutoff != null && img.createdAt < cutoff) return false;
            if (!hideMethodFilters && methodFilter !== 'all' && (img.method || 'text-to-image') !== methodFilter) {
                return false;
            }
            if (q && !img.prompt.toLowerCase().includes(q)) return false;
            return true;
        });
    }, [images, search, favoritesOnly, timeFilter, methodFilter, hideMethodFilters]);

    const preview = previewIndex !== null ? filtered[previewIndex] : null;
    const hasImages = images.length > 0;
    const hasResults = filtered.length > 0;
    const libraryFilterCount =
        (timeFilter !== 'all' ? 1 : 0) + (!hideMethodFilters && methodFilter !== 'all' ? 1 : 0);
    const activeFilterCount = libraryFilterCount + (favoritesOnly ? 1 : 0) + (search.trim() ? 1 : 0);
    const timeLabel = TIME_FILTERS.find((t) => t.id === timeFilter)?.label ?? 'All time';
    const methodLabel =
        METHOD_FILTERS.find((m) => m.id === methodFilter)?.label ?? 'All methods';
    useEffect(() => {
        if (!filtersOpen) return;
        const onPointerDown = (e: MouseEvent) => {
            if (filtersRef.current && !filtersRef.current.contains(e.target as Node)) {
                setFiltersOpen(false);
            }
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

    const openPreview = (id: string) => {
        const idx = filtered.findIndex((i) => i.id === id);
        if (idx >= 0) setPreviewIndex(idx);
    };

    const closePreview = () => {
        setPreviewIndex(null);
    };

    const resetFilters = () => {
        setSearch('');
        setFavoritesOnly(false);
        setTimeFilter('all');
        setMethodFilter('all');
        setFiltersOpen(false);
    };

    const downloadSelected = () => {
        const items = images.filter((img) => selected.includes(img.id));
        void (async () => {
            for (let i = 0; i < items.length; i++) {
                const img = items[i];
                const isVideo =
                    img.method === 'text-to-video' ||
                    img.method === 'image-to-video' ||
                    img.method === 'reference-to-video' ||
                    img.method === 'first-last-frame-to-video' ||
                    Boolean(img.videoUrl);
                const url = (isVideo ? img.videoUrl || img.src : img.src) || '';
                if (!url) continue;
                try {
                    await downloadMediaAsset(url, isVideo ? `video-${img.id}.mp4` : `image-${img.id}.jpg`);
                } catch {
                    /* continue next */
                }
                if (i < items.length - 1) {
                    await new Promise((r) => window.setTimeout(r, 200));
                }
            }
        })();
    };

    return (
        <div className="relative flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden bg-[#08080d] [&_a]:cursor-pointer [&_button]:cursor-pointer [&_button:disabled]:cursor-not-allowed [&_input[type=range]]:cursor-pointer">
            {/* Toolbar */}
            <div className="relative z-20 shrink-0 border-b border-white/[0.07] bg-[#0c0c12]">
                <div className="flex h-12 items-center justify-between gap-2 px-3 md:px-4">
                    <div className="inline-flex items-center rounded-xl border border-white/[0.06] bg-[#101016] p-1 shadow-[inset_0_1px_0_rgba(255,255,255,0.04)]">
                        {(
                            hideAlbums
                                ? [{ id: 'generation' as const, label: 'Generation', Icon: IconSparkles }]
                                : [
                                      { id: 'generation' as const, label: 'Generation', Icon: IconSparkles },
                                      { id: 'albums' as const, label: 'Albums', Icon: IconAlbums },
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
                                    {item.id === 'generation' && hasImages && (
                                        <span
                                            className={`rounded-md px-1.5 py-0.5 text-[10px] font-semibold tabular-nums ${
                                                active ? 'bg-[#FF5733]/20 text-[#ffb39f]' : 'bg-white/[0.06] text-zinc-500'
                                            }`}
                                        >
                                            {images.length}
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
                                onClick={downloadSelected}
                                className="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-white/10 bg-white/[0.04] px-2.5 text-[12px] font-semibold tracking-tight text-zinc-200 transition hover:bg-white/[0.08] hover:text-white"
                            >
                                <IconDownload className="h-3.5 w-3.5" />
                                Download {selected.length}
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    onDelete?.(selected);
                                    exitSelectMode();
                                }}
                                className="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-red-400/35 bg-red-500/20 px-2.5 text-[12px] font-semibold tracking-tight text-red-100 transition hover:bg-red-500/30"
                            >
                                <IconTrash className="h-3.5 w-3.5" />
                                Delete {selected.length}
                            </button>
                        </div>
                    )}
                </div>

                {tab === 'generation' && (
                    <div className="flex flex-col gap-2.5 border-t border-white/[0.05] px-3 py-3 sm:flex-row sm:items-center md:px-4">
                        <div className="relative min-w-0 flex-1 sm:max-w-md">
                            <svg className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-white/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                <circle cx="11" cy="11" r="8" />
                                <path d="m21 21-4.3-4.3" />
                            </svg>
                            <input
                                type="search"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder={hasImages ? t('searchByPrompt') : t('searchGenerations')}
                                className="h-10 w-full rounded-xl border border-white/12 bg-[#14141c] py-2 ps-9 pe-3 text-sm text-white outline-none placeholder:text-white/35 focus:border-orange-400/50 focus:ring-2 focus:ring-orange-500/20"
                            />
                        </div>

                        <div className="flex flex-wrap items-center gap-1.5">
                            <div className="relative" ref={filtersRef}>
                                <ToolBtn
                                    active={filtersOpen || libraryFilterCount > 0}
                                    onClick={() => setFiltersOpen((v) => !v)}
                                >
                                    <IconFilter className="h-3.5 w-3.5" />
                                    <span className="hidden sm:inline">Filters</span>
                                    {libraryFilterCount > 0 && (
                                        <span className="rounded bg-[#FF5733]/25 px-1.5 py-0.5 text-[10px] font-semibold text-[#ffb39f]">
                                            {libraryFilterCount}
                                        </span>
                                    )}
                                </ToolBtn>
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
                                                transition={{ duration: 0.18, ease: [0.22, 1, 0.36, 1] }}
                                                className="fixed inset-x-0 bottom-0 z-[60] max-h-[85dvh] overflow-y-auto rounded-t-2xl border border-white/[0.08] bg-[#14141c] shadow-[0_-12px_60px_rgba(0,0,0,0.55)] scrollbar-thin md:absolute md:inset-x-auto md:bottom-auto md:end-0 md:mt-2 md:max-h-none md:w-[300px] md:overflow-hidden md:rounded-2xl md:bg-[#14141c]/95 md:shadow-[0_24px_80px_rgba(0,0,0,0.55)] md:backdrop-blur-xl"
                                            >
                                            <div className="mx-auto mb-1 mt-2 h-1 w-10 rounded-full bg-white/15 md:hidden" />
                                            <div className="flex items-start justify-between gap-3 border-b border-white/[0.06] px-4 py-3">
                                                <div className="min-w-0">
                                                    <p className="text-[13px] font-semibold tracking-tight text-zinc-100">Filters</p>
                                                    <p className="mt-0.5 text-[12px] text-zinc-500">Refine by time and creation method</p>
                                                </div>
                                                <div className="flex shrink-0 items-center gap-1">
                                                {libraryFilterCount > 0 && (
                                                    <button
                                                        type="button"
                                                        title="Reset filters"
                                                        onClick={() => {
                                                            setTimeFilter('all');
                                                            setMethodFilter('all');
                                                        }}
                                                        className="inline-flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-lg text-zinc-400 transition hover:bg-white/[0.06] hover:text-zinc-100"
                                                    >
                                                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                                            <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
                                                            <path d="M3 3v5h5" />
                                                        </svg>
                                                    </button>
                                                )}
                                                <button
                                                    type="button"
                                                    title="Close"
                                                    onClick={() => setFiltersOpen(false)}
                                                    className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-zinc-400 transition hover:bg-white/[0.06] hover:text-zinc-100 md:hidden"
                                                >
                                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                        <path d="M18 6 6 18M6 6l12 12" />
                                                    </svg>
                                                </button>
                                                </div>
                                            </div>

                                            <div className="space-y-4 p-4 pb-[max(1rem,env(safe-area-inset-bottom))] md:pb-4">
                                                <div>
                                                    <p className="mb-2 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500">
                                                        Time range
                                                    </p>
                                                    <div className="flex flex-wrap gap-1.5">
                                                        {TIME_FILTERS.map((f) => {
                                                            const active = timeFilter === f.id;
                                                            return (
                                                                <button
                                                                    key={f.id}
                                                                    type="button"
                                                                    onClick={() => setTimeFilter(f.id)}
                                                                    className={`cursor-pointer rounded-full px-3 py-1.5 text-[12px] font-medium tracking-tight transition ${
                                                                        active
                                                                            ? 'bg-[#FF5733] text-white shadow-[0_6px_20px_rgba(255,87,51,0.35)]'
                                                                            : 'bg-white/[0.04] text-zinc-400 ring-1 ring-inset ring-white/[0.06] hover:bg-white/[0.08] hover:text-zinc-200'
                                                                    }`}
                                                                >
                                                                    {f.label}
                                                                </button>
                                                            );
                                                        })}
                                                    </div>
                                                </div>

                                                {!hideMethodFilters && (
                                                <div>
                                                    <p className="mb-2 text-[11px] font-medium uppercase tracking-[0.08em] text-zinc-500">
                                                        Creation method
                                                    </p>
                                                    <div className="grid grid-cols-1 gap-1.5">
                                                        {METHOD_FILTERS.map((f) => {
                                                            const active = methodFilter === f.id;
                                                            return (
                                                                <button
                                                                    key={f.id}
                                                                    type="button"
                                                                    onClick={() => setMethodFilter(active ? 'all' : f.id)}
                                                                    className={`flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 text-left transition ${
                                                                        active
                                                                            ? 'bg-[#FF5733]/12 ring-1 ring-inset ring-[#FF5733]/35'
                                                                            : 'bg-white/[0.03] ring-1 ring-inset ring-white/[0.05] hover:bg-white/[0.06]'
                                                                    }`}
                                                                >
                                                                    <span
                                                                        className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${
                                                                            active ? 'bg-[#FF5733]/20 text-[#FF5733]' : 'bg-white/[0.05] text-zinc-500'
                                                                        }`}
                                                                    >
                                                                        {f.id === 'image-to-image' ? (
                                                                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                                                                <rect width="18" height="18" x="3" y="3" rx="2" />
                                                                                <circle cx="9" cy="9" r="2" />
                                                                                <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" />
                                                                            </svg>
                                                                        ) : (
                                                                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                                                                <path d="M4 7V4h16v3" />
                                                                                <path d="M9 20h6" />
                                                                                <path d="M12 4v16" />
                                                                            </svg>
                                                                        )}
                                                                    </span>
                                                                    <span className="min-w-0 flex-1">
                                                                        <span className={`block text-[13px] font-medium tracking-tight ${active ? 'text-zinc-50' : 'text-zinc-200'}`}>
                                                                            {f.label}
                                                                        </span>
                                                                        <span className="mt-0.5 block text-[11px] text-zinc-500">
                                                                            {f.id === 'text-to-image'
                                                                                ? 'Generated from a text prompt'
                                                                                : 'Remixed from reference images'}
                                                                        </span>
                                                                    </span>
                                                                    {active && (
                                                                        <svg className="h-4 w-4 shrink-0 text-[#FF5733]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                                            <path d="M20 6 9 17l-5-5" />
                                                                        </svg>
                                                                    )}
                                                                </button>
                                                            );
                                                        })}
                                                    </div>
                                                </div>
                                                )}
                                            </div>
                                            </motion.div>
                                        </>
                                    )}
                                </AnimatePresence>
                            </div>

                            <IconBtn
                                active={favoritesOnly}
                                title="Favorites"
                                onClick={() => setFavoritesOnly((v) => !v)}
                            >
                                <svg
                                    className={`h-4 w-4 ${favoritesOnly ? 'fill-orange-400 text-orange-400' : ''}`}
                                    fill={favoritesOnly ? 'currentColor' : 'none'}
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    strokeWidth="2"
                                >
                                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
                                </svg>
                            </IconBtn>

                            <ToolBtn
                                active={selectMode}
                                disabled={!hasImages}
                                onClick={() => (selectMode ? exitSelectMode() : setSelectMode(true))}
                            >
                                {selectMode ? 'Done' : 'Select'}
                            </ToolBtn>

                            <div className="ms-0.5 hidden items-center gap-2 rounded-lg border border-white/10 bg-white/[0.03] px-2.5 py-1.5 lg:flex">
                                <span className="text-[11px] font-medium text-zinc-500">Size</span>
                                <input
                                    type="range"
                                    min={3}
                                    max={8}
                                    step={1}
                                    value={11 - columns}
                                    onChange={(e) => setColumns(11 - Number(e.target.value))}
                                    className="h-1.5 w-24 cursor-pointer appearance-none rounded-full bg-white/15 accent-[#FF5733]"
                                    title={`${columns} per row`}
                                />
                                <span className="min-w-[1.5rem] text-right text-[11px] font-medium tabular-nums text-zinc-400">
                                    {columns}
                                </span>
                            </div>
                        </div>
                    </div>
                )}

                {/* Active filter chips */}
                {tab === 'generation' && activeFilterCount > 0 && (
                    <div className="flex flex-wrap items-center gap-1.5 border-t border-white/[0.05] px-3 py-2 md:px-4">
                        {search.trim() && (
                            <Chip onClear={() => setSearch('')}>Search: “{search.trim().slice(0, 24)}{search.trim().length > 24 ? '…' : ''}”</Chip>
                        )}
                        {timeFilter !== 'all' && (
                            <Chip onClear={() => setTimeFilter('all')}>{timeLabel}</Chip>
                        )}
                        {!hideMethodFilters && methodFilter !== 'all' && (
                            <Chip onClear={() => setMethodFilter('all')}>{methodLabel}</Chip>
                        )}
                        {favoritesOnly && <Chip onClear={() => setFavoritesOnly(false)}>Favorites</Chip>}
                        <button type="button" onClick={resetFilters} className="ms-1 text-[11px] font-medium text-orange-300/90 hover:text-orange-200">
                            Clear all
                        </button>
                    </div>
                )}
            </div>

            {/* Content */}
            <div className="relative z-10 min-h-0 flex-1 overflow-y-auto scrollbar-thin">
                <AnimatePresence mode="wait">
                    {tab === 'albums' ? (
                        <EmptyState
                            key="albums"
                            title="Albums"
                            description="Group generations into curated collections as your library grows."
                        />
                    ) : generating && !hasImages ? (
                        <GeneratingState key="gen" />
                    ) : !hasImages ? (
                        <EmptyState
                            key="empty"
                            title="Your canvas is ready"
                            description="Describe an image on the left and hit Generate — results appear here with search, filters, and favorites."
                        />
                    ) : !hasResults ? (
                        <EmptyState
                            key="none"
                            title="Nothing matches"
                            description="Try a different search or clear your filters."
                            action="Clear filters"
                            onAction={resetFilters}
                        />
                    ) : (
                        <motion.div
                            key="grid"
                            initial={{ opacity: 0 }}
                            animate={{ opacity: 1 }}
                            className="grid grid-cols-2 gap-2 p-2 sm:grid-cols-3 sm:gap-3 sm:p-3 md:grid-cols-4 md:gap-3 md:p-4 lg:[grid-template-columns:repeat(var(--lab-cols),minmax(0,1fr))]"
                            style={{ ['--lab-cols' as string]: String(columns) }}
                        >
                            {filtered.map((img, index) => {
                                const isSelected = selected.includes(img.id);
                                const isBuilding =
                                    img.completing === true ||
                                    (img.status !== undefined &&
                                        img.status !== 'completed' &&
                                        img.status !== 'failed' &&
                                        img.status !== 'cancelled');
                                const isFailed = !img.completing && (img.status === 'failed' || img.status === 'cancelled');
                                const isVideo =
                                    img.method === 'text-to-video' ||
                                    img.method === 'image-to-video' ||
                                    img.method === 'reference-to-video' ||
                                    img.method === 'first-last-frame-to-video' ||
                                    Boolean(img.videoUrl);
                                const mediaSrc = isVideo ? img.videoUrl || img.src : img.src;
                                const posterSrc =
                                    isVideo && img.videoUrl && img.src !== img.videoUrl ? img.src : undefined;

                                if (isBuilding) {
                                    return (
                                        <BuildingCard
                                            key={img.id}
                                            prompt={img.prompt}
                                            hidePrompt={hidePrompt}
                                            status={img.status}
                                            progress={img.progress}
                                            queuePosition={img.queuePosition}
                                            progressPercent={img.progressPercent}
                                            startedAt={img.startedAt ?? img.createdAt}
                                            batchIndex={img.batchIndex ?? 0}
                                            completing={img.completing}
                                            method={img.method}
                                            onRevealComplete={() => onRevealComplete?.(img.id)}
                                        />
                                    );
                                }

                                if (isFailed) {
                                    return (
                                        <LabFailedCard
                                            key={img.id}
                                            error={img.error}
                                            prompt={img.prompt}
                                            variant="tile"
                                            onDismiss={() => onDelete?.([img.id])}
                                        />
                                    );
                                }

                                return (
                                    <motion.div
                                        key={img.id}
                                        layout
                                        initial={{ opacity: 0, scale: 0.96 }}
                                        animate={{ opacity: 1, scale: 1 }}
                                        transition={{ delay: Math.min(index * 0.03, 0.2), duration: 0.2 }}
                                        data-testid={`media-item-${img.id}`}
                                        className={`group relative w-full cursor-pointer overflow-hidden rounded-[5px] bg-[#14141c] transition-opacity ${
                                            isSelected ? 'ring-2 ring-orange-500/50' : ''
                                        }`}
                                        style={{ aspectRatio: '1 / 1' }}
                                    >
                                        <button
                                            type="button"
                                            className="absolute inset-0 overflow-hidden"
                                            onClick={() => (selectMode ? toggleSelect(img.id) : openPreview(img.id))}
                                        >
                                            {/* Soft fill — image blur only when we have a real still (never a second video load). */}
                                            {isVideo ? (
                                                posterSrc ? (
                                                    <img
                                                        aria-hidden
                                                        src={posterSrc}
                                                        alt=""
                                                        loading="lazy"
                                                        decoding="async"
                                                        className="absolute inset-0 size-full scale-110 object-cover object-center opacity-40 blur-xl"
                                                    />
                                                ) : (
                                                    <div
                                                        aria-hidden
                                                        className="absolute inset-0 bg-gradient-to-br from-zinc-800 via-zinc-900 to-zinc-950"
                                                    />
                                                )
                                            ) : (
                                                <img
                                                    aria-hidden
                                                    src={mediaSrc}
                                                    alt=""
                                                    loading="lazy"
                                                    decoding="async"
                                                    className="absolute inset-0 size-full scale-110 object-cover object-center opacity-40 blur-xl"
                                                />
                                            )}
                                            {isVideo ? (
                                                <VideoThumb
                                                    src={mediaSrc}
                                                    poster={posterSrc}
                                                    seekTo={0.15}
                                                    className="absolute inset-0 size-full object-contain object-center"
                                                />
                                            ) : (
                                                <img
                                                    src={mediaSrc}
                                                    alt={img.prompt}
                                                    loading="lazy"
                                                    decoding="async"
                                                    className="absolute inset-0 size-full object-contain object-center"
                                                />
                                            )}
                                        </button>
                                        {!selectMode && (
                                            <>
                                                {!hidePrompt && (
                                                    <div className="pointer-events-none absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 to-transparent p-2 opacity-0 transition-all duration-300 ease-out group-hover:opacity-100">
                                                        <p className="line-clamp-2 translate-y-1 text-[10px] text-white/80 transition-transform duration-300 ease-out group-hover:translate-y-0">
                                                            {img.prompt}
                                                        </p>
                                                    </div>
                                                )}
                                                <ImageCardActions
                                                    src={img.videoUrl || img.src}
                                                    filename={
                                                        img.videoUrl ||
                                                        img.method === 'text-to-video' ||
                                                        img.method === 'image-to-video' ||
                                                        img.method === 'reference-to-video' ||
                                                        img.method === 'first-last-frame-to-video'
                                                            ? `video-${img.id}.mp4`
                                                            : `image-${img.id}.jpg`
                                                    }
                                                    favorite={img.favorite}
                                                    onToggleFavorite={() => onToggleFavorite?.(img.id)}
                                                    onDelete={() => onDelete?.([img.id])}
                                                />
                                            </>
                                        )}
                                        {selectMode && (
                                            <span
                                                className={`absolute start-2 top-2 z-10 flex h-5 w-5 items-center justify-center rounded-md text-[10px] font-bold ${
                                                    isSelected
                                                        ? 'bg-orange-500 text-white'
                                                        : 'border border-white/50 bg-black/60 text-transparent'
                                                }`}
                                            >
                                                ✓
                                            </span>
                                        )}
                                    </motion.div>
                                );
                            })}
                        </motion.div>
                    )}
                </AnimatePresence>
            </div>

            <AnimatePresence>
                {selectMode && selected.length > 0 && (
                    <motion.div
                        initial={{ opacity: 0, y: 16 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0, y: 12 }}
                        className="absolute inset-x-0 bottom-4 z-30 flex justify-center px-4"
                    >
                        <div className="flex items-center gap-2 rounded-2xl border border-white/12 bg-[#16161e] px-3 py-2 shadow-2xl">
                            <span className="px-2 text-xs font-medium text-white/70">{selected.length} selected</span>
                            <button
                                type="button"
                                onClick={() => setSelected(filtered.map((i) => i.id))}
                                className="cursor-pointer rounded-lg px-2.5 py-1.5 text-xs text-white/55 hover:bg-white/[0.08] hover:text-white"
                            >
                                Select all
                            </button>
                            <button
                                type="button"
                                onClick={downloadSelected}
                                className="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-white/[0.08] px-2.5 py-1.5 text-xs font-semibold text-zinc-100 hover:bg-white/[0.12]"
                            >
                                <IconDownload className="h-3.5 w-3.5" />
                                Download
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    onDelete?.(selected);
                                    exitSelectMode();
                                }}
                                className="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-red-500/25 px-2.5 py-1.5 text-xs font-semibold text-red-100 hover:bg-red-500/35"
                            >
                                <IconTrash className="h-3.5 w-3.5" />
                                Delete
                            </button>
                            <button type="button" onClick={exitSelectMode} className="cursor-pointer rounded-lg px-2.5 py-1.5 text-xs text-white/45 hover:text-white">
                                Cancel
                            </button>
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>

            <AnimatePresence>
                {preview && previewIndex !== null && (
                    <ImageLabPreviewModal
                        image={preview}
                        index={previewIndex}
                        total={filtered.length}
                        onClose={closePreview}
                        onPrev={() => setPreviewIndex((i) => (i === null ? 0 : (i - 1 + filtered.length) % filtered.length))}
                        onNext={() => setPreviewIndex((i) => (i === null ? 0 : (i + 1) % filtered.length))}
                        onToggleFavorite={onToggleFavorite}
                        onDelete={onDelete}
                        onReuseSettings={onReuseSettings}
                        onUseResult={onUseResult}
                        onUseLastFrame={onUseLastFrame}
                        hidePrompt={hidePrompt}
                    />
                )}
            </AnimatePresence>
        </div>
    );
}

function Chip({ children, onClear }: { children: ReactNode; onClear: () => void }) {
    return (
        <span className="inline-flex items-center gap-1.5 rounded-full border border-white/12 bg-white/[0.06] px-2.5 py-1 text-[11px] text-white/75">
            {children}
            <button type="button" onClick={onClear} className="text-white/40 hover:text-white" aria-label="Remove filter">
                ×
            </button>
        </span>
    );
}

function EmptyState({
    title,
    description,
    action,
    onAction,
}: {
    title: string;
    description: string;
    action?: string;
    onAction?: () => void;
}) {
    return (
        <motion.div
            initial={{ opacity: 0, y: 10 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0 }}
            className="flex min-h-[320px] h-full flex-col items-center justify-center px-6 py-16 text-center"
        >
            <div className="mb-5 flex h-16 w-16 items-center justify-center rounded-2xl border border-white/10 bg-[#14141c]">
                <svg className="h-7 w-7 text-orange-300/80" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                    <path
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"
                    />
                </svg>
            </div>
            <h3 className="text-lg font-semibold tracking-tight text-white">{title}</h3>
            <p className="mt-2 max-w-sm text-sm leading-relaxed text-white/45">{description}</p>
            {action && onAction && (
                <button
                    type="button"
                    onClick={onAction}
                    className="mt-5 rounded-xl border border-white/12 bg-white/[0.06] px-4 py-2 text-xs font-semibold text-white/85 hover:bg-white/[0.1]"
                >
                    {action}
                </button>
            )}
        </motion.div>
    );
}

function GeneratingState() {
    return (
        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} className="flex min-h-[320px] h-full flex-col items-center justify-center px-6 text-center">
            <div className="mb-5 flex h-14 w-14 items-center justify-center rounded-2xl border border-white/10 bg-white/[0.03]">
                <div className="h-7 w-7 animate-spin rounded-full border-2 border-white/10 border-t-white/50" />
            </div>
            <h3 className="text-lg font-semibold text-white">Crafting your image</h3>
            <p className="mt-2 max-w-sm text-sm text-white/45">Lighting, detail, and composition are being refined…</p>
        </motion.div>
    );
}

/** Progress from queue position (real) + in-progress stage estimate (fal has no %). */
function useLabCardProgress(
    startedAt: number,
    status?: LabImage['status'],
    queuePosition?: number | null,
    completing?: boolean,
    serverProgressPercent?: number | null,
) {
    const [pct, setPct] = useState(() =>
        labEffectiveProgressPercent({ serverPercent: serverProgressPercent, status, queuePosition, startedAt, completing: false }),
    );
    const pctRef = useRef(pct);
    pctRef.current = pct;

    // Normal crawl while building
    useEffect(() => {
        if (completing) return;
        const tick = () => {
            const next = labEffectiveProgressPercent({
                serverPercent: serverProgressPercent,
                status,
                queuePosition,
                startedAt,
                completing: false,
            });
            setPct((prev) => (next >= prev ? next : prev));
        };
        tick();
        const id = window.setInterval(tick, 400);
        return () => window.clearInterval(id);
    }, [startedAt, status, queuePosition, completing, serverProgressPercent]);

    return { pct, setPct, pctRef };
}

function BuildingCard({
    prompt,
    hidePrompt = false,
    status,
    progress,
    queuePosition,
    progressPercent,
    startedAt,
    batchIndex = 0,
    completing = false,
    method,
    onRevealComplete,
}: {
    prompt: string;
    hidePrompt?: boolean;
    status?: LabImage['status'];
    progress?: string | null;
    queuePosition?: number | null;
    progressPercent?: number | null;
    startedAt: number;
    batchIndex?: number;
    completing?: boolean;
    method?: LabImage['method'];
    onRevealComplete?: () => void;
}) {
    const isVideo = method === 'text-to-video' || method === 'image-to-video' || method === 'reference-to-video' || method === 'first-last-frame-to-video';
    const { pct, setPct, pctRef } = useLabCardProgress(startedAt, status, queuePosition, completing, progressPercent);
    const [fadeOut, setFadeOut] = useState(false);
    const revealStarted = useRef(false);
    const phase = labPhaseLabel({
        status,
        queuePosition,
        progress,
        completing,
        kind: isVideo ? 'video' : 'image',
    });

    useEffect(() => {
        if (!completing || revealStarted.current) return;
        revealStarted.current = true;
        const from = pctRef.current;
        const duration = labCompletingRampMs(from);
        const t0 = performance.now();
        let raf = 0;

        const tick = (now: number) => {
            const t = Math.min(1, (now - t0) / duration);
            const eased = 1 - Math.pow(1 - t, 2.2);
            setPct(Math.round(from + (100 - from) * eased));
            if (t < 1) {
                raf = window.requestAnimationFrame(tick);
            } else {
                setPct(100);
                setFadeOut(true);
                window.setTimeout(() => onRevealComplete?.(), 380);
            }
        };
        raf = window.requestAnimationFrame(tick);
        return () => window.cancelAnimationFrame(raf);
    }, [completing, onRevealComplete, pctRef, setPct]);

    return (
        <motion.div
            initial={{ opacity: 0, scale: 0.98 }}
            animate={{ opacity: fadeOut ? 0 : 1, scale: fadeOut ? 0.98 : 1 }}
            transition={{ delay: fadeOut ? 0 : batchIndex * 0.04, duration: fadeOut ? 0.35 : 0.22 }}
            className="relative w-full overflow-hidden rounded-xl bg-gradient-to-b from-[#15151c] to-[#0c0c10] ring-1 ring-white/[0.07]"
            style={{ aspectRatio: '1 / 1' }}
        >
            <motion.div
                aria-hidden
                className="absolute inset-0"
                style={{
                    background: isVideo
                        ? 'radial-gradient(80% 55% at 50% 0%, rgba(255,87,51,0.14), transparent 70%)'
                        : 'radial-gradient(80% 55% at 50% 0%, rgba(199,33,255,0.12), transparent 70%)',
                }}
                animate={{ opacity: [0.55, 0.85, 0.55] }}
                transition={{ duration: 4.5, repeat: Infinity, ease: 'easeInOut' }}
            />

            <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 px-4 text-center">
                <div className="relative flex h-14 w-14 items-center justify-center">
                    <motion.span
                        className={`absolute inset-0 rounded-full border ${
                            isVideo ? 'border-[#FF5733]/35' : 'border-[#C721FF]/35'
                        }`}
                        animate={{ rotate: 360 }}
                        transition={{ duration: 8, repeat: Infinity, ease: 'linear' }}
                        style={{ borderTopColor: 'transparent', borderLeftColor: 'transparent' }}
                    />
                    <span className="text-xl font-medium tabular-nums tracking-tight text-white/90">{pct}%</span>
                </div>
                <div className="space-y-1">
                    <p className="text-[11px] font-medium tracking-wide text-white/70">{phase}</p>
                    {isVideo && status === 'queued' && typeof queuePosition === 'number' && queuePosition >= 0 && !completing && (
                        <p className="text-[10px] text-white/30">
                            {queuePosition > 0 ? `Queue #${queuePosition}` : 'Next up'}
                        </p>
                    )}
                    {isVideo && status === 'in_progress' && !completing && (
                        <p className="text-[10px] text-white/30">Usually a few minutes</p>
                    )}
                </div>
                {!hidePrompt && (
                    <p className="line-clamp-2 max-w-[92%] text-[10px] leading-snug text-white/25">{prompt}</p>
                )}
            </div>

            <div className="absolute inset-x-3 bottom-3 h-1 overflow-hidden rounded-full bg-white/[0.06]">
                <motion.div
                    className={`h-full rounded-full ${
                        isVideo
                            ? 'bg-gradient-to-r from-[#FF5733]/80 to-[#ff8c4a]/70'
                            : 'bg-gradient-to-r from-[#C721FF]/75 to-[#7c3aed]/70'
                    }`}
                    animate={{ width: `${pct}%` }}
                    transition={{ duration: 0.4, ease: 'easeOut' }}
                />
            </div>
        </motion.div>
    );
}

function ToolBtn({
    children,
    active,
    disabled,
    onClick,
}: {
    children: ReactNode;
    active?: boolean;
    disabled?: boolean;
    onClick?: () => void;
}) {
    return (
        <button
            type="button"
            disabled={disabled}
            onClick={onClick}
            className={`inline-flex h-9 cursor-pointer items-center gap-1.5 rounded-lg border px-3 font-sans text-[13px] font-medium tracking-tight transition disabled:cursor-not-allowed disabled:opacity-40 ${
                active
                    ? 'border-[#FF5733]/35 bg-[#FF5733]/12 text-[#ffb39f] shadow-[0_0_0_1px_rgba(255,87,51,0.08)]'
                    : 'border-white/[0.08] bg-[#121217] text-zinc-300 hover:border-white/15 hover:bg-[#17171f] hover:text-zinc-100'
            }`}
        >
            {children}
        </button>
    );
}

function ImageCardActions({
    src,
    filename,
    favorite,
    onToggleFavorite,
    onDelete,
}: {
    src: string;
    filename: string;
    favorite: boolean;
    onToggleFavorite: () => void;
    onDelete: () => void;
}) {
    const btn =
        'pointer-events-auto inline-flex h-7 w-7 items-center justify-center rounded-lg border border-white/[0.12] bg-black/50 text-zinc-300 shadow-[0_4px_18px_rgba(0,0,0,0.35)] ring-1 ring-white/[0.05] backdrop-blur-xl transition-all duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:scale-[1.06] hover:border-white/20 hover:bg-white/[0.1] hover:text-white active:scale-95';

    const stagger =
        'translate-x-2 scale-[0.88] opacity-0 group-hover:translate-x-0 group-hover:scale-100 group-hover:opacity-100';

    return (
        <div className="pointer-events-none absolute end-2 top-2 z-10 flex flex-col gap-1">
            <button
                type="button"
                title="Download"
                onClick={(e) => {
                    e.stopPropagation();
                    void downloadMediaAsset(src, filename).catch(() => {});
                }}
                className={`${btn} ${stagger} delay-[0ms] group-hover:delay-[0ms]`}
                style={{ transitionProperty: 'opacity, transform, background-color, border-color, color, box-shadow' }}
            >
                <IconDownload className="h-3.5 w-3.5" />
            </button>
            <button
                type="button"
                title="Favorite"
                onClick={(e) => {
                    e.stopPropagation();
                    onToggleFavorite();
                }}
                className={`${btn} ${stagger} delay-0 group-hover:delay-[50ms] ${
                    favorite ? 'border-[#FF5733]/30 bg-[#FF5733]/15 text-[#ffb39f] hover:bg-[#FF5733]/22' : ''
                }`}
                style={{ transitionProperty: 'opacity, transform, background-color, border-color, color, box-shadow' }}
            >
                <svg
                    className={`h-3.5 w-3.5 ${favorite ? 'fill-[#FF5733] text-[#FF5733]' : ''}`}
                    fill={favorite ? 'currentColor' : 'none'}
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    strokeWidth="1.5"
                >
                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
                </svg>
            </button>
            <button
                type="button"
                title="Delete"
                onClick={(e) => {
                    e.stopPropagation();
                    onDelete();
                }}
                className={`${btn} ${stagger} text-red-300/90 delay-0 hover:border-red-400/25 hover:bg-red-500/15 hover:text-red-200 group-hover:delay-[100ms]`}
                style={{ transitionProperty: 'opacity, transform, background-color, border-color, color, box-shadow' }}
            >
                <IconTrash className="h-3.5 w-3.5" />
            </button>
        </div>
    );
}

function IconBtn({
    children,
    title,
    active,
    disabled,
    onClick,
    className = '',
}: {
    children: ReactNode;
    title?: string;
    active?: boolean;
    disabled?: boolean;
    onClick?: (e: React.MouseEvent<HTMLButtonElement>) => void;
    className?: string;
}) {
    return (
        <button
            type="button"
            title={title}
            disabled={disabled}
            onClick={onClick}
            className={`inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg border transition disabled:cursor-not-allowed disabled:opacity-40 active:scale-95 ${
                active
                    ? 'border-[#FF5733]/35 bg-[#FF5733]/15 text-[#ffb39f]'
                    : 'border-white/10 bg-black/40 text-zinc-300 hover:bg-white/10 hover:text-white'
            } ${className}`}
        >
            {children}
        </button>
    );
}
