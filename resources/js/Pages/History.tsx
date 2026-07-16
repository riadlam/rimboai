import { Head, router } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import ImageLabPreviewModal, { type ImageLabPreviewItem } from '@/Components/ImageLabPreviewModal';
import VideoThumb from '@/Components/VideoThumb';
import AppLayout from '@/Layouts/AppLayout';
import { apiGet, apiPost } from '@/lib/api';
import {
    buildReuseSettingsDraft,
    buildUseResultDraft,
    saveLabReuseDraft,
    type LabReuseMediaItem,
} from '@/lib/labReuse';

type TabId = 'image' | 'video' | 'audio' | 'music' | 'archived';

type HistoryItem = {
    id: string;
    creationId?: number | null;
    tab: Exclude<TabId, 'archived'>;
    title: string;
    prompt: string;
    src: string;
    favorite: boolean;
    isPublic?: boolean;
    isFeatured?: boolean;
    archived: boolean;
    createdAt: number;
    kind: 'image' | 'video' | 'audio' | 'music';
    videoUrl?: string;
    aspect?: string;
    resolution?: string | null;
    duration?: number | string | null;
    audio?: boolean | null;
    quantity?: number | null;
    imageMode?: 'create' | 'variations' | null;
    inputAssets?: LabReuseMediaItem[];
    method?: ImageLabPreviewItem['method'];
    modelName?: string | null;
};

type ApiImageItem = {
    id: string;
    creation_id?: number | null;
    prompt: string;
    src: string;
    favorite: boolean;
    is_public?: boolean;
    is_featured?: boolean;
    created_at: string | null;
    aspect?: string;
    resolution?: string | null;
    duration?: number | string | null;
    audio?: boolean | null;
    quantity?: number | null;
    image_mode?: 'create' | 'variations' | null;
    input_assets?: {
        url: string;
        kind: 'image' | 'video' | 'audio';
        name?: string | null;
        fallback_urls?: string[] | null;
    }[] | null;
    method?: string;
    model?: string | null;
    status?: string;
    video_url?: string | null;
};

type ApiTrackItem = {
    id: string;
    creation_id?: number | null;
    title: string;
    style: string;
    cover: string;
    favorite: boolean;
    is_public?: boolean;
    is_featured?: boolean;
    created_at: string | null;
};

type ApiVoiceItem = {
    id: string;
    title: string;
    text: string;
    voice: string;
    favorite: boolean;
    created_at: string | null;
    gradient?: string | null;
};

const TAB_IDS: TabId[] = ['image', 'video', 'audio', 'music', 'archived'];

const ARCHIVED_TAB_ICON = (
    <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
        <rect width="20" height="5" x="2" y="3" rx="1" />
        <path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8" />
        <path d="M10 12h4" />
    </svg>
);

const DAY = 24 * 60 * 60 * 1000;

const TIME_FILTERS = [
    { id: 'all', days: null as number | null },
    { id: '1d', days: 1 },
    { id: '3d', days: 3 },
    { id: '7d', days: 7 },
] as const;

function mapInputAssets(assets: ApiImageItem['input_assets']): LabReuseMediaItem[] {
    return (assets ?? []).map((a) => ({
        url: a.url,
        kind: a.kind,
        name: a.name ?? null,
        fallbackUrls: a.fallback_urls ?? null,
    }));
}

function titleFromPrompt(prompt: string, fallback: string): string {
    const t = prompt.trim();
    if (!t) return fallback;
    return t.length > 48 ? `${t.slice(0, 48)}…` : t;
}

function mapImageCreation(item: ApiImageItem): HistoryItem | null {
    if (item.status && item.status !== 'completed') return null;
    if (!item.src) return null;
    return {
        id: item.id,
        creationId: item.creation_id ?? null,
        tab: 'image',
        kind: 'image',
        title: titleFromPrompt(item.prompt, 'Image'),
        prompt: item.prompt,
        src: item.src,
        favorite: item.favorite,
        isPublic: Boolean(item.is_public),
        isFeatured: Boolean(item.is_featured),
        archived: false,
        createdAt: item.created_at ? new Date(item.created_at).getTime() : Date.now(),
        aspect: item.aspect ?? '1:1',
        resolution: item.resolution,
        quantity: item.quantity,
        imageMode: item.image_mode,
        inputAssets: mapInputAssets(item.input_assets),
        method: (item.method as HistoryItem['method']) || 'text-to-image',
        modelName: item.model,
    };
}

function mapVideoCreation(item: ApiImageItem): HistoryItem | null {
    if (item.status && item.status !== 'completed') return null;
    const src = item.src || item.video_url || '';
    if (!src) return null;
    return {
        id: item.id,
        creationId: item.creation_id ?? null,
        tab: 'video',
        kind: 'video',
        title: titleFromPrompt(item.prompt, 'Video'),
        prompt: item.prompt,
        src,
        videoUrl: item.video_url || undefined,
        favorite: item.favorite,
        isPublic: Boolean(item.is_public),
        isFeatured: Boolean(item.is_featured),
        archived: false,
        createdAt: item.created_at ? new Date(item.created_at).getTime() : Date.now(),
        aspect: item.aspect ?? '16:9',
        resolution: item.resolution,
        duration: item.duration,
        audio: item.audio,
        inputAssets: mapInputAssets(item.input_assets),
        method: (item.method as HistoryItem['method']) || 'text-to-video',
        modelName: item.model,
    };
}

function toPreviewItem(item: HistoryItem): ImageLabPreviewItem {
    return {
        id: item.id,
        prompt: item.prompt,
        src: item.src,
        favorite: item.favorite,
        isPublic: item.isPublic,
        isFeatured: item.isFeatured,
        aspect: item.aspect,
        resolution: item.resolution ?? undefined,
        duration: item.duration,
        audio: item.audio,
        quantity: item.quantity,
        imageMode: item.imageMode,
        inputAssets: item.inputAssets,
        method: item.method,
        modelName: item.modelName,
        videoUrl: item.videoUrl,
    };
}

function toReuseSource(item: HistoryItem) {
    return {
        id: item.id,
        prompt: item.prompt,
        src: item.src,
        videoUrl: item.videoUrl,
        method: item.method,
        modelName: item.modelName,
        aspect: item.aspect,
        resolution: item.resolution,
        duration: item.duration,
        audio: item.audio,
        quantity: item.quantity,
        imageMode: item.imageMode,
        inputAssets: item.inputAssets,
    };
}

export default function History() {
    const { t } = useTranslation('history');
    return (
        <AppLayout>
            <Head title={t('title')} />
            <HistoryWorkspace />
        </AppLayout>
    );
}

function HistoryWorkspace() {
    const { t } = useTranslation('history');
    const [tab, setTab] = useState<TabId>('image');
    const [items, setItems] = useState<HistoryItem[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [mobileSearchOpen, setMobileSearchOpen] = useState(false);
    const [favoritesOnly, setFavoritesOnly] = useState(false);
    const [selectMode, setSelectMode] = useState(false);
    const [selected, setSelected] = useState<string[]>([]);
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [timeFilter, setTimeFilter] = useState<(typeof TIME_FILTERS)[number]['id']>('all');
    const [columns, setColumns] = useState(5);
    const [layoutGrid, setLayoutGrid] = useState(true);
    const [previewIndex, setPreviewIndex] = useState<number | null>(null);
    const [playingId, setPlayingId] = useState<string | null>(null);
    const filtersRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        let cancelled = false;

        (async () => {
            setLoading(true);
            try {
                const [imagesRes, videosRes, musicRes, voiceRes] = await Promise.all([
                    apiGet<{ images: ApiImageItem[] }>('/lab/creations?type=text-to-image'),
                    apiGet<{ images: ApiImageItem[] }>('/lab/creations?type=text-to-video'),
                    apiGet<{ tracks: ApiTrackItem[] }>('/lab/creations?type=text-to-music'),
                    apiGet<{ voices: ApiVoiceItem[] }>('/lab/creations?type=text-to-voice'),
                ]);

                if (cancelled) return;

                const next: HistoryItem[] = [];

                for (const item of imagesRes.images ?? []) {
                    const mapped = mapImageCreation(item);
                    if (mapped) next.push(mapped);
                }
                for (const item of videosRes.images ?? []) {
                    const mapped = mapVideoCreation(item);
                    if (mapped) next.push(mapped);
                }
                for (const track of musicRes.tracks ?? []) {
                    if (!track.cover) continue;
                    next.push({
                        id: track.id,
                        creationId: track.creation_id ?? null,
                        tab: 'music',
                        kind: 'music',
                        title: track.title || t('untitledTrack'),
                        prompt: track.style || '',
                        src: track.cover,
                        favorite: track.favorite,
                        isPublic: Boolean(track.is_public),
                        isFeatured: Boolean(track.is_featured),
                        archived: false,
                        createdAt: track.created_at ? new Date(track.created_at).getTime() : Date.now(),
                    });
                }
                for (const voice of voiceRes.voices ?? []) {
                    next.push({
                        id: voice.id,
                        tab: 'audio',
                        kind: 'audio',
                        title: voice.title || t('voiceFallback'),
                        prompt: voice.text || voice.voice || '',
                        src: '',
                        favorite: voice.favorite,
                        archived: false,
                        createdAt: voice.created_at ? new Date(voice.created_at).getTime() : Date.now(),
                    });
                }

                next.sort((a, b) => b.createdAt - a.createdAt);
                setItems(next);
            } catch {
                if (!cancelled) setItems([]);
            } finally {
                if (!cancelled) setLoading(false);
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [t]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        const timeOpt = TIME_FILTERS.find((t) => t.id === timeFilter);
        const cutoff = timeOpt?.days != null ? Date.now() - timeOpt.days * DAY : null;

        return items.filter((item) => {
            if (tab === 'archived') {
                if (!item.archived) return false;
            } else {
                if (item.tab !== tab || item.archived) return false;
            }
            if (favoritesOnly && !item.favorite) return false;
            if (cutoff != null && item.createdAt < cutoff) return false;
            if (q && !`${item.title} ${item.prompt}`.toLowerCase().includes(q)) return false;
            return true;
        });
    }, [items, tab, search, favoritesOnly, timeFilter]);

    const preview = previewIndex !== null ? filtered[previewIndex] ?? null : null;

    useEffect(() => {
        if (!filtersOpen) return;
        const onDown = (e: MouseEvent) => {
            if (filtersRef.current && !filtersRef.current.contains(e.target as Node)) setFiltersOpen(false);
        };
        document.addEventListener('mousedown', onDown);
        return () => document.removeEventListener('mousedown', onDown);
    }, [filtersOpen]);

    useEffect(() => {
        setSelectMode(false);
        setSelected([]);
        setSearch('');
        setFavoritesOnly(false);
        setPreviewIndex(null);
        setPlayingId(null);
    }, [tab]);

    const openPreview = (id: string) => {
        const idx = filtered.findIndex((it) => it.id === id);
        if (idx >= 0) setPreviewIndex(idx);
    };

    const closePreview = () => {
        setPreviewIndex(null);
        setPlayingId(null);
    };

    const toggleFavorite = (id: string) => {
        setItems((prev) => prev.map((it) => (it.id === id ? { ...it, favorite: !it.favorite } : it)));
    };

    const togglePublic = async (item: HistoryItem) => {
        if (!item.creationId || item.kind === 'audio') return;
        const nextPublic = !item.isPublic;
        setItems((prev) =>
            prev.map((it) =>
                it.id === item.id
                    ? { ...it, isPublic: nextPublic, isFeatured: nextPublic ? it.isFeatured : false }
                    : it,
            ),
        );
        try {
            await apiPost('/trends/visibility', {
                type: item.kind === 'music' ? 'music' : item.kind,
                id: item.creationId,
                is_public: nextPublic,
            });
        } catch {
            setItems((prev) =>
                prev.map((it) =>
                    it.id === item.id ? { ...it, isPublic: !nextPublic, isFeatured: item.isFeatured } : it,
                ),
            );
        }
    };

    const toggleFeatured = async (item: HistoryItem) => {
        if (!item.creationId || item.kind === 'audio') return;
        const nextFeatured = !item.isFeatured;
        setItems((prev) =>
            prev.map((it) =>
                it.id === item.id
                    ? { ...it, isFeatured: nextFeatured, isPublic: nextFeatured ? true : it.isPublic }
                    : it,
            ),
        );
        try {
            await apiPost('/trends/visibility', {
                type: item.kind === 'music' ? 'music' : item.kind,
                id: item.creationId,
                is_featured: nextFeatured,
            });
        } catch {
            setItems((prev) =>
                prev.map((it) =>
                    it.id === item.id
                        ? { ...it, isFeatured: !nextFeatured, isPublic: item.isPublic }
                        : it,
                ),
            );
        }
    };

    const toggleSelect = (id: string) => {
        setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
    };

    const deleteItems = (ids: string[]) => {
        setItems((prev) => prev.filter((it) => !ids.includes(it.id)));
        setSelected((prev) => prev.filter((id) => !ids.includes(id)));
        if (preview && ids.includes(preview.id)) closePreview();
    };

    const deleteSelected = () => {
        deleteItems(selected);
        setSelectMode(false);
    };

    const sendToLab = useCallback((item: HistoryItem, intent: 'reuse-settings' | 'use-result') => {
        const draft =
            intent === 'reuse-settings'
                ? buildReuseSettingsDraft(toReuseSource(item))
                : buildUseResultDraft(toReuseSource(item));
        if (intent === 'use-result' && draft.lab === 'image') {
            draft.imageMode = 'create';
        }
        saveLabReuseDraft(draft);
        const labType = draft.lab === 'video' ? 'text-to-video' : 'text-to-image';
        router.visit(`/lab?type=${labType}`);
    }, []);

    useEffect(() => {
        if (previewIndex !== null && previewIndex >= filtered.length) {
            setPreviewIndex(filtered.length > 0 ? filtered.length - 1 : null);
        }
    }, [filtered.length, previewIndex]);

    const searchPlaceholder = t(`search.${tab}`);

    const selectLabel =
        tab === 'archived' ? t('select.generic') : t(`select.${tab}` as 'select.image');

    const emptyCopy = {
        title: t(`empty.${tab}Title` as 'empty.imageTitle'),
        sub: t(`empty.${tab}Sub` as 'empty.imageSub'),
    };

    return (
        <div className="relative flex h-[calc(100dvh-2.5rem)] min-h-0 w-full min-w-0 flex-col overflow-hidden rounded-xl border border-white/[0.06] bg-black [&_a]:cursor-pointer [&_button]:cursor-pointer [&_input[type=range]]:cursor-pointer [&_label]:cursor-pointer">
            <div className="shrink-0 border-b border-white/[0.07]">
                <div className="flex items-center justify-start overflow-x-auto px-2 scrollbar-thin md:px-4">
                    {TAB_IDS.map((tabId) => {
                        const active = tab === tabId;
                        return (
                            <button
                                key={tabId}
                                type="button"
                                onClick={() => setTab(tabId)}
                                className={`shrink-0 cursor-pointer border-b-2 px-4 py-3 text-sm font-medium transition md:px-6 ${
                                    active
                                        ? 'border-[#FF5733] text-white'
                                        : 'border-transparent text-zinc-500 hover:text-zinc-200'
                                }`}
                            >
                                <span className="flex items-center gap-1.5">
                                    {tabId === 'archived' ? ARCHIVED_TAB_ICON : null}
                                    {t(`tabs.${tabId}`)}
                                </span>
                            </button>
                        );
                    })}
                </div>
            </div>

            <div className="flex shrink-0 items-center justify-between gap-2 border-b border-white/[0.07] px-3 py-2 md:hidden">
                <div className="flex items-center gap-1.5">
                    <IconBtn title={t('layout')} active={layoutGrid} onClick={() => setLayoutGrid((v) => !v)}>
                        <IconGrid />
                    </IconBtn>
                    <IconBtn title={t('favorites')} active={favoritesOnly} onClick={() => setFavoritesOnly((v) => !v)}>
                        <IconStar filled={favoritesOnly} />
                    </IconBtn>
                    <button
                        type="button"
                        onClick={() => {
                            setSelectMode((v) => !v);
                            setSelected([]);
                        }}
                        className={`inline-flex h-8 cursor-pointer items-center rounded-md border px-3 text-xs font-medium ${
                            selectMode
                                ? 'border-orange-400/40 bg-orange-500/10 text-orange-100'
                                : 'border-white/10 text-zinc-400'
                        }`}
                    >
                        {selectMode ? t('select.done') : t('select.generic')}
                    </button>
                </div>
                <div className="flex items-center gap-1.5">
                    <IconBtn title={t('searchBtn')} active={mobileSearchOpen} onClick={() => setMobileSearchOpen((v) => !v)}>
                        <IconSearch />
                    </IconBtn>
                    <div className="flex w-20 items-center gap-1">
                        <input
                            type="range"
                            min={3}
                            max={6}
                            value={columns}
                            onChange={(e) => setColumns(Number(e.target.value))}
                            className="h-1.5 w-full cursor-pointer appearance-none rounded-full bg-white/15 accent-[#FF5733]"
                        />
                    </div>
                </div>
            </div>

            {mobileSearchOpen && (
                <div className="flex shrink-0 items-center gap-2 border-b border-white/[0.07] px-3 py-2 md:hidden">
                    <div className="relative flex-1">
                        <IconSearch className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500" />
                        <input
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder={searchPlaceholder}
                            className="h-9 w-full rounded-md border border-white/10 bg-white/[0.04] pe-3 ps-9 text-sm text-white outline-none placeholder:text-zinc-500 focus:border-orange-400/40"
                        />
                    </div>
                    <IconBtn title={t('filters')} onClick={() => setFiltersOpen((v) => !v)}>
                        <IconSliders />
                    </IconBtn>
                </div>
            )}

            <div className="hidden shrink-0 items-center justify-between gap-4 border-b border-white/[0.07] px-4 py-2 md:flex">
                <div className="flex max-w-md flex-1 items-center gap-2">
                    <div className="relative flex-1">
                        <IconSearch className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500" />
                        <input
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder={searchPlaceholder}
                            className="h-9 w-full rounded-md border border-white/10 bg-white/[0.04] pe-3 ps-9 text-sm text-white outline-none placeholder:text-zinc-500 focus:border-orange-400/40"
                        />
                    </div>
                    <div className="relative" ref={filtersRef}>
                        <button
                            type="button"
                            onClick={() => setFiltersOpen((v) => !v)}
                            className={`inline-flex h-9 shrink-0 cursor-pointer items-center gap-2 rounded-md border px-3 text-xs font-medium transition ${
                                filtersOpen || timeFilter !== 'all'
                                    ? 'border-orange-400/40 bg-orange-500/10 text-orange-100'
                                    : 'border-white/10 text-zinc-400 hover:text-zinc-200'
                            }`}
                        >
                            <IconSliders />
                            {t('filters')}
                        </button>
                        <AnimatePresence>
                            {filtersOpen && (
                                <motion.div
                                    initial={{ opacity: 0, y: 6 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    exit={{ opacity: 0, y: 4 }}
                                    className="absolute start-0 z-30 mt-2 w-56 rounded-xl border border-white/10 bg-black p-3 shadow-2xl"
                                >
                                    <p className="mb-2 text-[11px] font-medium uppercase tracking-wider text-zinc-500">Time</p>
                                    <div className="flex flex-wrap gap-1.5">
                                        {TIME_FILTERS.map((f) => (
                                            <button
                                                key={f.id}
                                                type="button"
                                                onClick={() => setTimeFilter(f.id)}
                                                className={`cursor-pointer rounded-lg px-2 py-1 text-[11px] font-medium ${
                                                    timeFilter === f.id
                                                        ? 'bg-orange-500/15 text-orange-100 ring-1 ring-orange-400/40'
                                                        : 'bg-white/[0.04] text-zinc-400 hover:text-zinc-200'
                                                }`}
                                            >
                                                {t(`time.${f.id}`)}
                                            </button>
                                        ))}
                                    </div>
                                </motion.div>
                            )}
                        </AnimatePresence>
                    </div>
                </div>

                <div className="flex items-center gap-2">
                    {selectMode && selected.length > 0 && (
                        <button
                            type="button"
                            onClick={deleteSelected}
                            className="inline-flex h-9 cursor-pointer items-center rounded-md border border-red-500/30 bg-red-500/10 px-3 text-xs font-medium text-red-300"
                        >
                            {t('deleteN', { count: selected.length })}
                        </button>
                    )}
                    <IconBtn title={t('layout')} active={layoutGrid} onClick={() => setLayoutGrid((v) => !v)}>
                        <IconGrid />
                    </IconBtn>
                    <IconBtn title={t('favorites')} active={favoritesOnly} onClick={() => setFavoritesOnly((v) => !v)}>
                        <IconStar filled={favoritesOnly} />
                    </IconBtn>
                    <button
                        type="button"
                        onClick={() => {
                            setSelectMode((v) => !v);
                            setSelected([]);
                        }}
                        className={`inline-flex h-9 cursor-pointer items-center gap-2 rounded-md border px-3 text-xs font-medium transition ${
                            selectMode
                                ? 'border-orange-400/40 bg-orange-500/10 text-orange-100'
                                : 'border-white/10 text-zinc-400 hover:text-zinc-200'
                        }`}
                    >
                        {selectMode ? t('select.done') : selectLabel}
                    </button>
                    <div className="ms-2 flex items-center gap-1">
                        <svg className="h-4 w-4 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                            <circle cx="11" cy="11" r="8" />
                            <line x1="21" x2="16.65" y1="21" y2="16.65" />
                            <line x1="8" x2="14" y1="11" y2="11" />
                        </svg>
                        <input
                            type="range"
                            min={3}
                            max={8}
                            value={columns}
                            onChange={(e) => setColumns(Number(e.target.value))}
                            className="h-1.5 w-24 cursor-pointer appearance-none rounded-full bg-white/15 accent-[#FF5733]"
                        />
                        <svg className="h-4 w-4 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                            <circle cx="11" cy="11" r="8" />
                            <line x1="21" x2="16.65" y1="21" y2="16.65" />
                            <line x1="11" x2="11" y1="8" y2="14" />
                            <line x1="8" x2="14" y1="11" y2="11" />
                        </svg>
                    </div>
                </div>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto p-2 scrollbar-thin md:p-3">
                {loading ? (
                    <div className="grid gap-2" style={{ gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))` }}>
                        {Array.from({ length: Math.max(columns * 2, 8) }).map((_, i) => (
                            <div
                                key={i}
                                className="relative aspect-square overflow-hidden rounded-[5px] bg-white/[0.04]"
                            >
                                <div className="absolute inset-0 -translate-x-full animate-[labSkeletonShimmer_2.2s_ease-in-out_infinite] bg-gradient-to-r from-transparent via-white/[0.05] to-transparent" />
                            </div>
                        ))}
                    </div>
                ) : filtered.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-white/10 py-16 text-center">
                        <EmptyIcon tab={tab} />
                        <p className="mt-4 text-sm text-zinc-400">{emptyCopy.title}</p>
                        <p className="mt-1 text-sm text-zinc-600">{emptyCopy.sub}</p>
                    </div>
                ) : layoutGrid ? (
                    <div className="grid gap-2" style={{ gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))` }}>
                        {filtered.map((item) => {
                            const isSelected = selected.includes(item.id);
                            return (
                                <button
                                    key={item.id}
                                    type="button"
                                    onClick={() => (selectMode ? toggleSelect(item.id) : openPreview(item.id))}
                                    className={`group relative w-full cursor-pointer overflow-hidden rounded-[5px] border bg-[#14141c] text-start transition ${
                                        isSelected
                                            ? 'border-orange-400/50 ring-1 ring-orange-400/30'
                                            : 'border-white/[0.06] hover:border-white/15'
                                    }`}
                                    style={{ aspectRatio: '1 / 1' }}
                                >
                                    {item.src ? (
                                        item.kind === 'video' && item.videoUrl ? (
                                            <VideoThumb
                                                src={item.videoUrl}
                                                poster={item.src !== item.videoUrl ? item.src : undefined}
                                                className="absolute inset-0 size-full object-contain object-center"
                                            />
                                        ) : (
                                            <img
                                                src={item.src}
                                                alt=""
                                                className="absolute inset-0 size-full object-contain object-center"
                                                loading="lazy"
                                            />
                                        )
                                    ) : (
                                        <div className="absolute inset-0 flex items-center justify-center bg-gradient-to-br from-violet-500/20 to-orange-500/10">
                                            <IconAudio className="h-8 w-8 text-white/40" />
                                        </div>
                                    )}
                                    <div className="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-transparent opacity-0 transition group-hover:opacity-100" />
                                    <div className="absolute inset-x-0 bottom-0 p-2 opacity-0 transition group-hover:opacity-100">
                                        <p className="line-clamp-2 text-[11px] font-medium text-white drop-shadow">{item.prompt}</p>
                                    </div>
                                    {(item.kind === 'video' || item.kind === 'audio' || item.kind === 'music') && (
                                        <span className="absolute start-1.5 top-1.5 rounded bg-black/55 px-1.5 py-0.5 text-[9px] font-medium uppercase text-white/80">
                                            {item.kind}
                                        </span>
                                    )}
                                    <button
                                        type="button"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            toggleFavorite(item.id);
                                        }}
                                        className="absolute end-1.5 top-1.5 flex h-7 w-7 cursor-pointer items-center justify-center rounded-lg bg-black/45 text-white opacity-0 backdrop-blur-sm transition group-hover:opacity-100"
                                    >
                                        <IconStar filled={item.favorite} className="h-3.5 w-3.5" />
                                    </button>
                                    {selectMode && (
                                        <span
                                            className={`absolute end-1.5 bottom-1.5 flex h-5 w-5 items-center justify-center rounded-md text-[10px] ${
                                                isSelected
                                                    ? 'bg-[#FF5733] text-white'
                                                    : 'bg-black/55 text-white/70 ring-1 ring-white/20'
                                            }`}
                                        >
                                            {isSelected ? '✓' : ''}
                                        </span>
                                    )}
                                </button>
                            );
                        })}
                    </div>
                ) : (
                    <div className="space-y-1">
                        {filtered.map((item) => {
                            const isSelected = selected.includes(item.id);
                            return (
                                <button
                                    key={item.id}
                                    type="button"
                                    onClick={() => (selectMode ? toggleSelect(item.id) : openPreview(item.id))}
                                    className={`flex w-full cursor-pointer items-center gap-3 rounded-xl border px-2.5 py-2 text-start transition ${
                                        isSelected
                                            ? 'border-orange-400/40 bg-orange-500/[0.08]'
                                            : 'border-white/[0.05] bg-white/[0.02] hover:bg-white/[0.04]'
                                    }`}
                                >
                                    {item.src ? (
                                        <img src={item.src} alt="" className="h-12 w-12 shrink-0 rounded-[5px] object-cover" />
                                    ) : (
                                        <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-[5px] bg-white/[0.06]">
                                            <IconAudio className="h-5 w-5 text-white/40" />
                                        </div>
                                    )}
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-[13px] font-medium text-zinc-100">{item.title}</p>
                                        <p className="truncate text-[11px] text-zinc-500">{item.prompt}</p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            toggleFavorite(item.id);
                                        }}
                                        className="shrink-0 cursor-pointer p-1.5 text-zinc-500 hover:text-orange-200"
                                    >
                                        <IconStar filled={item.favorite} className="h-4 w-4" />
                                    </button>
                                </button>
                            );
                        })}
                    </div>
                )}
            </div>

            <AnimatePresence>
                {preview && previewIndex !== null && (preview.kind === 'audio' || preview.kind === 'music') && (
                    <motion.div
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        className="absolute inset-0 z-40 flex items-end justify-center bg-black/70 p-3 backdrop-blur-sm sm:items-center"
                        onClick={closePreview}
                    >
                        <motion.div
                            initial={{ opacity: 0, y: 24 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={{ opacity: 0, y: 16 }}
                            className="grid w-full max-w-lg gap-4 overflow-hidden rounded-2xl border border-white/10 bg-[#121218] p-4 shadow-2xl sm:grid-cols-[140px_1fr]"
                            onClick={(e) => e.stopPropagation()}
                        >
                            <div className="relative mx-auto aspect-square w-36 overflow-hidden rounded-xl bg-white/[0.04] sm:w-full">
                                {preview.src ? (
                                    <img src={preview.src} alt="" className="size-full object-cover" />
                                ) : (
                                    <div className="flex size-full items-center justify-center">
                                        <IconAudio className="h-10 w-10 text-white/35" />
                                    </div>
                                )}
                            </div>
                            <div className="min-w-0">
                                <h3 className="text-base font-semibold text-white">{preview.title}</h3>
                                <p className="mt-1 text-[13px] leading-relaxed text-zinc-400">{preview.prompt}</p>
                                <div className="mt-3 flex flex-wrap gap-1.5">
                                    <span className="rounded-lg border border-white/10 px-2 py-1 text-[11px] capitalize text-zinc-400">
                                        {preview.kind}
                                    </span>
                                    <span className="rounded-lg border border-white/10 px-2 py-1 text-[11px] text-zinc-400">
                                        {new Date(preview.createdAt).toLocaleDateString()}
                                    </span>
                                </div>
                                <div className="mt-4 flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setPlayingId(playingId === preview.id ? null : preview.id)}
                                        className="inline-flex h-9 flex-1 cursor-pointer items-center justify-center gap-2 rounded-xl bg-gradient-to-b from-[#FF6A45] via-[#FF5733] to-[#D63A18] text-[13px] font-semibold text-white"
                                    >
                                        {playingId === preview.id ? t('actions.pause') : t('actions.play')}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => toggleFavorite(preview.id)}
                                        className="inline-flex h-9 cursor-pointer items-center justify-center rounded-xl border border-white/10 px-3 text-zinc-300 hover:bg-white/[0.05]"
                                    >
                                        {preview.favorite ? t('actions.unfavorite') : t('actions.favorite')}
                                    </button>
                                    {preview.creationId && preview.kind !== 'audio' && (
                                        <>
                                            <button
                                                type="button"
                                                onClick={() => void togglePublic(preview)}
                                                className={`inline-flex h-9 cursor-pointer items-center justify-center rounded-xl border px-3 ${
                                                    preview.isPublic
                                                        ? 'border-[#FF5733]/40 bg-[#FF5733]/10 text-[#ffb39f]'
                                                        : 'border-white/10 text-zinc-300 hover:bg-white/[0.05]'
                                                }`}
                                            >
                                                {preview.isPublic ? t('actions.publicOnTrends') : t('actions.publish')}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => void toggleFeatured(preview)}
                                                className={`inline-flex h-9 cursor-pointer items-center justify-center rounded-xl border px-3 ${
                                                    preview.isFeatured
                                                        ? 'border-amber-400/40 bg-amber-500/10 text-amber-200'
                                                        : 'border-white/10 text-zinc-300 hover:bg-white/[0.05]'
                                                }`}
                                            >
                                                {preview.isFeatured ? t('actions.featured') : t('actions.feature')}
                                            </button>
                                        </>
                                    )}
                                    <button
                                        type="button"
                                        onClick={() => deleteItems([preview.id])}
                                        className="inline-flex h-9 cursor-pointer items-center justify-center rounded-xl border border-red-500/30 px-3 text-red-300 hover:bg-red-500/10"
                                    >
                                        {t('actions.delete')}
                                    </button>
                                </div>
                            </div>
                        </motion.div>
                    </motion.div>
                )}
            </AnimatePresence>

            <AnimatePresence>
                {preview && previewIndex !== null && (preview.kind === 'image' || preview.kind === 'video') && (
                    <ImageLabPreviewModal
                        image={toPreviewItem(preview)}
                        index={previewIndex}
                        total={filtered.length}
                        onClose={closePreview}
                        onPrev={() =>
                            setPreviewIndex((i) =>
                                i === null ? 0 : (i - 1 + filtered.length) % filtered.length,
                            )
                        }
                        onNext={() => setPreviewIndex((i) => (i === null ? 0 : (i + 1) % filtered.length))}
                        onToggleFavorite={toggleFavorite}
                        onTogglePublic={(id) => {
                            const source = items.find((it) => it.id === id);
                            if (source) void togglePublic(source);
                        }}
                        onDelete={deleteItems}
                        onReuseSettings={(img) => {
                            const source = items.find((it) => it.id === img.id) ?? preview;
                            closePreview();
                            sendToLab(source, 'reuse-settings');
                        }}
                        onUseResult={(img) => {
                            const source = items.find((it) => it.id === img.id) ?? preview;
                            closePreview();
                            sendToLab(source, 'use-result');
                        }}
                    />
                )}
            </AnimatePresence>
        </div>
    );
}

function EmptyIcon({ tab }: { tab: TabId }) {
    if (tab === 'archived') {
        return (
            <svg className="mx-auto h-12 w-12 text-zinc-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                <rect width="20" height="5" x="2" y="3" rx="1" />
                <path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8" />
                <path d="M10 12h4" />
            </svg>
        );
    }
    if (tab === 'music' || tab === 'audio') {
        return <IconAudio className="mx-auto h-12 w-12 text-zinc-600" />;
    }
    if (tab === 'video') {
        return (
            <svg className="mx-auto h-12 w-12 text-zinc-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                <path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5" />
                <rect x="2" y="6" width="14" height="12" rx="2" />
            </svg>
        );
    }
    return (
        <svg className="mx-auto h-12 w-12 text-zinc-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
            <rect width="18" height="18" x="3" y="3" rx="2" ry="2" />
            <circle cx="9" cy="9" r="2" />
            <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" />
        </svg>
    );
}

function IconAudio({ className = 'h-4 w-4' }: { className?: string }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
            <circle cx="8" cy="18" r="4" />
            <path d="M12 18V2l7 4" />
        </svg>
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
            className={`inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-md transition md:h-9 md:w-9 ${
                active ? 'bg-orange-500/15 text-orange-200' : 'text-zinc-400 hover:bg-white/[0.06] hover:text-zinc-200'
            }`}
        >
            {children}
        </button>
    );
}

function IconSearch({ className = 'h-4 w-4' }: { className?: string }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
            <circle cx="11" cy="11" r="8" />
            <path d="m21 21-4.3-4.3" />
        </svg>
    );
}

function IconGrid() {
    return (
        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
            <rect width="7" height="7" x="3" y="3" rx="1" />
            <rect width="7" height="7" x="14" y="3" rx="1" />
            <rect width="7" height="7" x="14" y="14" rx="1" />
            <rect width="7" height="7" x="3" y="14" rx="1" />
        </svg>
    );
}

function IconStar({ filled, className = 'h-4 w-4' }: { filled?: boolean; className?: string }) {
    return (
        <svg className={className} fill={filled ? 'currentColor' : 'none'} viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
        </svg>
    );
}

function IconSliders() {
    return (
        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
            <line x1="21" x2="14" y1="4" y2="4" />
            <line x1="10" x2="3" y1="4" y2="4" />
            <line x1="21" x2="12" y1="12" y2="12" />
            <line x1="8" x2="3" y1="12" y2="12" />
            <line x1="21" x2="16" y1="20" y2="20" />
            <line x1="12" x2="3" y1="20" y2="20" />
            <line x1="14" x2="14" y1="2" y2="6" />
            <line x1="8" x2="8" y1="10" y2="14" />
            <line x1="16" x2="16" y1="18" y2="22" />
        </svg>
    );
}
