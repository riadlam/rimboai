import { Head, router } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import VideoThumb from '@/Components/VideoThumb';
import { apiPost } from '@/lib/api';
import { saveLabReuseDraft, type LabReuseDraft } from '@/lib/labReuse';

export type TrendTemplate = {
    id: string;
    creation_id: number;
    type: 'image' | 'video' | 'music';
    name: string;
    category: 'Images' | 'Videos' | 'Music' | string;
    creator: string;
    avatar: string;
    cover: string;
    coverType: 'video' | 'image' | 'audio';
    video_url?: string | null;
    audio_url?: string | null;
    thumbnail_url?: string | null;
    featured: boolean;
    hot: boolean;
    uses: number;
    rating: number | null;
    credits: number;
    model: string;
    description: string;
    prompt: string;
    lyrics?: string | null;
    samples: string[];
    endpoint_id?: string | null;
    aspect?: string | null;
    resolution?: string | null;
    duration?: number | string | null;
    generate_audio?: boolean | null;
    quantity?: number | null;
    image_mode?: string | null;
    created_at?: string | null;
};

const PILLS = ['All', 'Featured', 'Videos', 'Images', 'Music'] as const;
const SORTS = ['Most Popular', 'Newest', 'Credits: Low → High', 'Credits: High → Low'] as const;

const EASE = [0.22, 1, 0.36, 1] as const;

type Props = {
    templates?: TrendTemplate[];
};

export default function Trends({ templates: initialTemplates = [] }: Props) {
    return (
        <AppLayout>
            <Head title="Trends" />
            <TrendsWorkspace initialTemplates={initialTemplates} />
        </AppLayout>
    );
}

function labHrefForType(type: TrendTemplate['type']): string {
    if (type === 'video') return '/lab?type=text-to-video';
    if (type === 'music') return '/lab?type=text-to-music';
    return '/lab?type=text-to-image';
}

function isAudioUrl(url?: string | null): boolean {
    return Boolean(url && /\.(mp3|wav|ogg|m4a)(\?|$)/i.test(url));
}

function buildTrendLabDraft(t: TrendTemplate): LabReuseDraft {
    const lab = t.type === 'video' ? 'video' : t.type === 'music' ? 'music' : 'image';
    return {
        id: `trend-${t.id}-${Date.now()}`,
        lab,
        intent: 'reuse-settings',
        prompt: (t.prompt || '').trim(),
        lyrics: t.lyrics ?? null,
        modelName: t.model || null,
        endpointId: t.endpoint_id || null,
        aspect: t.aspect ?? (lab === 'video' ? '16:9' : lab === 'image' ? '1:1' : null),
        resolution: t.resolution ?? (lab === 'video' ? '720p' : lab === 'image' ? '1K' : null),
        duration: t.duration ?? (lab === 'video' ? 5 : null),
        audio: lab === 'video' ? Boolean(t.generate_audio ?? true) : null,
        quantity: t.quantity ?? 1,
        imageMode: lab === 'image' ? (t.image_mode === 'variations' ? 'variations' : 'create') : null,
        media: [],
    };
}

function TrendsWorkspace({ initialTemplates }: { initialTemplates: TrendTemplate[] }) {
    const [templates, setTemplates] = useState(initialTemplates);
    const [query, setQuery] = useState('');
    const [pill, setPill] = useState<(typeof PILLS)[number]>('All');
    const [model, setModel] = useState('All Models');
    const [sort, setSort] = useState<(typeof SORTS)[number]>('Most Popular');
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [usingId, setUsingId] = useState<string | null>(null);

    useEffect(() => {
        setTemplates(initialTemplates);
    }, [initialTemplates]);

    const modelOptions = useMemo(() => {
        const names = Array.from(new Set(templates.map((t) => t.model).filter(Boolean))).sort();
        return ['All Models', ...names];
    }, [templates]);

    const sortList = (list: TrendTemplate[]) =>
        [...list].sort((a, b) => {
            if (sort === 'Credits: Low → High') return a.credits - b.credits;
            if (sort === 'Credits: High → Low') return b.credits - a.credits;
            if (sort === 'Newest') return String(b.created_at || '').localeCompare(String(a.created_at || ''));
            if (a.featured !== b.featured) return Number(b.featured) - Number(a.featured);
            return b.uses - a.uses || String(b.created_at || '').localeCompare(String(a.created_at || ''));
        });

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        const list = templates.filter((t) => {
            if (q && !`${t.name} ${t.creator} ${t.category} ${t.model} ${t.prompt}`.toLowerCase().includes(q)) {
                return false;
            }
            if (model !== 'All Models' && t.model !== model) return false;
            return true;
        });
        return sortList(list);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [templates, query, model, sort]);

    const groups = useMemo(() => {
        const featured = filtered.filter((t) => t.featured);
        return {
            featured,
            videos: filtered.filter((t) => t.type === 'video' && !t.featured),
            images: filtered.filter((t) => t.type === 'image' && !t.featured),
            music: filtered.filter((t) => t.type === 'music' && !t.featured),
        };
    }, [filtered]);

    const hasActiveFilters = pill !== 'All' || model !== 'All Models' || query.trim().length > 0;

    const clearFilters = () => {
        setQuery('');
        setPill('All');
        setModel('All Models');
        setSort('Most Popular');
    };

    const open = (id: string) => setSelectedId(id);
    const selected = selectedId ? templates.find((t) => t.id === selectedId) ?? null : null;

    const useInLab = async (t: TrendTemplate) => {
        if (usingId) return;
        setUsingId(t.id);
        try {
            const res = await apiPost<{ ok: boolean; uses: number; item: TrendTemplate }>('/trends/use', {
                type: t.type,
                id: t.creation_id,
            });
            const updated = { ...(res.item ?? t), uses: res.uses };
            setTemplates((prev) => prev.map((row) => (row.id === t.id ? updated : row)));
            saveLabReuseDraft(buildTrendLabDraft(updated));
            setSelectedId(null);
            router.visit(labHrefForType(t.type));
        } catch {
            setUsingId(null);
        }
    };

    useEffect(() => {
        if (!selectedId) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setSelectedId(null);
        };
        document.addEventListener('keydown', onKey);
        document.body.style.overflow = 'hidden';
        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = '';
        };
    }, [selectedId]);

    const pillCount = (p: (typeof PILLS)[number]) => {
        if (p === 'All') return filtered.length;
        if (p === 'Featured') return groups.featured.length;
        if (p === 'Videos') return filtered.filter((t) => t.type === 'video').length;
        if (p === 'Images') return filtered.filter((t) => t.type === 'image').length;
        return filtered.filter((t) => t.type === 'music').length;
    };

    // Sections rendered for the current pill.
    const sections: { key: string; title: string; sub: string; icon: 'star' | 'video' | 'image' | 'music'; items: TrendTemplate[] }[] =
        pill === 'All'
            ? [
                  { key: 'featured', title: 'Featured', sub: 'Hand-picked community creations', icon: 'star', items: groups.featured },
                  { key: 'videos', title: 'Videos', sub: 'Motion creations from the community', icon: 'video', items: groups.videos },
                  { key: 'images', title: 'Images', sub: 'Stills and artwork', icon: 'image', items: groups.images },
                  { key: 'music', title: 'Music', sub: 'Tracks and soundscapes', icon: 'music', items: groups.music },
              ].filter((s) => s.items.length > 0)
            : pill === 'Featured'
              ? [{ key: 'featured', title: 'Featured', sub: 'Hand-picked community creations', icon: 'star', items: groups.featured }]
              : pill === 'Videos'
                ? [{ key: 'videos', title: 'Videos', sub: 'Motion creations from the community', icon: 'video', items: filtered.filter((t) => t.type === 'video') }]
                : pill === 'Images'
                  ? [{ key: 'images', title: 'Images', sub: 'Stills and artwork', icon: 'image', items: filtered.filter((t) => t.type === 'image') }]
                  : [{ key: 'music', title: 'Music', sub: 'Tracks and soundscapes', icon: 'music', items: filtered.filter((t) => t.type === 'music') }];

    const totalVisible = sections.reduce((acc, s) => acc + s.items.length, 0);

    return (
        <div
            className="mx-auto w-full pb-20 [&_button]:cursor-pointer"
            style={{ fontFamily: "'Outfit', Inter, ui-sans-serif, system-ui, sans-serif" }}
        >
            {/* Search + filters */}
            <div className="mb-7 rounded-2xl border border-white/[0.08] bg-white/[0.02] p-3 shadow-[inset_0_1px_0_rgba(255,255,255,0.04)] md:p-4">
                <div className="flex flex-col gap-3 md:flex-row md:items-center">
                    <div className="relative min-w-0 flex-1">
                        <svg
                            className="pointer-events-none absolute start-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-white/40"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth="2"
                        >
                            <circle cx="11" cy="11" r="8" />
                            <path d="m21 21-4.3-4.3" />
                        </svg>
                        <input
                            type="search"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Search by prompt, creator, or model..."
                            className="h-11 w-full rounded-xl border border-white/[0.08] bg-[#0c0c10] pe-10 ps-10 text-[14px] text-white outline-none placeholder:text-white/30 transition focus:border-[#FF5733]/40 focus:bg-[#101014] focus:ring-2 focus:ring-[#FF5733]/15"
                        />
                        {query && (
                            <button
                                type="button"
                                onClick={() => setQuery('')}
                                className="absolute end-2.5 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-lg text-white/40 transition hover:bg-white/[0.06] hover:text-white"
                                aria-label="Clear search"
                            >
                                <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                    <path d="M18 6 6 18M6 6l12 12" />
                                </svg>
                            </button>
                        )}
                    </div>

                    <div className="flex flex-wrap gap-2 md:flex-nowrap">
                        <FilterSelect value={model} options={modelOptions} onChange={setModel} className="min-w-[140px] flex-1 md:w-[160px] md:flex-none" />
                        <FilterSelect value={sort} options={[...SORTS]} onChange={setSort} className="min-w-[150px] flex-1 md:w-[180px] md:flex-none" />
                    </div>
                </div>

                <div className="mt-3 flex flex-col gap-3 border-t border-white/[0.06] pt-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex gap-1.5 overflow-x-auto" style={{ scrollbarWidth: 'none' }}>
                        {PILLS.map((p) => {
                            const active = pill === p;
                            return (
                                <button
                                    key={p}
                                    type="button"
                                    onClick={() => setPill(p)}
                                    className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-3.5 py-1.5 text-[13px] font-medium transition-all ${
                                        active
                                            ? 'bg-[#FF5733] text-white shadow-[0_8px_20px_-10px_rgba(255,87,51,0.85)]'
                                            : 'bg-white/[0.04] text-white/50 ring-1 ring-white/[0.06] hover:bg-white/[0.07] hover:text-white/80'
                                    }`}
                                >
                                    {p}
                                    <span
                                        className={`rounded-full px-1.5 py-px text-[10px] font-semibold tabular-nums ${
                                            active ? 'bg-white/20 text-white' : 'bg-white/[0.06] text-white/35'
                                        }`}
                                    >
                                        {pillCount(p)}
                                    </span>
                                </button>
                            );
                        })}
                    </div>

                    <div className="flex items-center gap-2 text-[12px] text-white/35">
                        <span>
                            <span className="font-semibold text-white/70">{totalVisible}</span> creation{totalVisible === 1 ? '' : 's'}
                        </span>
                        {hasActiveFilters && (
                            <button
                                type="button"
                                onClick={clearFilters}
                                className="rounded-lg px-2 py-1 font-medium text-[#FF5733] transition hover:bg-[#FF5733]/10"
                            >
                                Reset
                            </button>
                        )}
                    </div>
                </div>
            </div>

            {templates.length === 0 ? (
                <EmptyState
                    title="No public creations yet"
                    sub="Publish a completed image, video, or music creation from History to show it here."
                />
            ) : totalVisible === 0 ? (
                <EmptyState title="No creations match your filters" action={<button type="button" onClick={clearFilters} className="mt-3 rounded-full bg-white/[0.06] px-4 py-2 text-[13px] font-medium text-white/70 ring-1 ring-white/10 transition hover:bg-white/[0.1] hover:text-white">Clear filters</button>} />
            ) : (
                <div className="space-y-10">
                    {sections.map((section) => (
                        <Section key={section.key} icon={section.icon} title={section.title} sub={section.sub} count={section.items.length}>
                            {section.items.map((t, i) => (
                                <TemplateCard key={t.id} template={t} index={i} onOpen={() => open(t.id)} />
                            ))}
                        </Section>
                    ))}
                </div>
            )}

            <AnimatePresence>
                {selected && (
                    <TemplateDetailModal
                        template={selected}
                        using={usingId === selected.id}
                        onClose={() => setSelectedId(null)}
                        onUse={() => void useInLab(selected)}
                    />
                )}
            </AnimatePresence>
        </div>
    );
}

function Section({
    icon,
    title,
    sub,
    count,
    children,
}: {
    icon: 'star' | 'video' | 'image' | 'music';
    title: string;
    sub: string;
    count: number;
    children: ReactNode;
}) {
    const iconWrap =
        icon === 'star'
            ? 'bg-amber-500/15 text-amber-300 ring-amber-400/20'
            : icon === 'video'
              ? 'bg-[#FF5733]/15 text-[#ff8a6d] ring-[#FF5733]/20'
              : icon === 'image'
                ? 'bg-indigo-500/15 text-indigo-300 ring-indigo-400/20'
                : 'bg-emerald-500/15 text-emerald-300 ring-emerald-400/20';

    return (
        <section>
            <div className="mb-4 flex items-center justify-between">
                <div className="flex items-center gap-2.5">
                    <span className={`flex h-9 w-9 items-center justify-center rounded-xl ring-1 ${iconWrap}`}>
                        <SectionIcon icon={icon} />
                    </span>
                    <div>
                        <h2 className="text-[17px] font-semibold tracking-tight text-white">{title}</h2>
                        <p className="text-[12px] text-white/35">{sub}</p>
                    </div>
                </div>
                <span className="rounded-full bg-white/[0.04] px-2.5 py-1 text-[11px] font-medium tabular-nums text-white/45 ring-1 ring-white/[0.06]">
                    {count}
                </span>
            </div>
            <motion.div
                initial="hidden"
                animate="show"
                variants={{ hidden: {}, show: { transition: { staggerChildren: 0.04 } } }}
                className="grid grid-cols-2 gap-2.5 sm:gap-4 lg:grid-cols-3 xl:grid-cols-4"
            >
                {children}
            </motion.div>
        </section>
    );
}

function TemplateCard({ template: t, index, onOpen }: { template: TrendTemplate; index: number; onOpen: () => void }) {
    const showVideo = t.coverType === 'video' && Boolean(t.video_url || t.cover);
    const showImage = !showVideo && (t.coverType === 'image' || (t.coverType === 'audio' && !isAudioUrl(t.cover)));
    const videoSrc = t.video_url || t.cover;
    const videoPoster =
        t.thumbnail_url ||
        t.samples.find((s) => s && s !== videoSrc && !/\.(mp4|webm|mov)(\?|$)/i.test(s)) ||
        (t.cover && t.cover !== videoSrc && !/\.(mp4|webm|mov)(\?|$)/i.test(t.cover) ? t.cover : undefined);

    return (
        <motion.div
            variants={{ hidden: { opacity: 0, y: 18 }, show: { opacity: 1, y: 0, transition: { duration: 0.4, ease: EASE } } }}
            className="group relative"
        >
            <motion.button
                type="button"
                onClick={onOpen}
                whileHover={{ y: -4 }}
                whileTap={{ scale: 0.985 }}
                transition={{ type: 'spring', stiffness: 400, damping: 30 }}
                className="relative block w-full overflow-hidden rounded-2xl border border-white/[0.08] bg-[#111116] text-start shadow-[0_1px_0_rgba(255,255,255,0.04)_inset] transition-colors hover:border-white/20"
            >
                {/* gradient glow ring on hover */}
                <span className="pointer-events-none absolute inset-0 rounded-2xl opacity-0 shadow-[0_24px_60px_-24px_rgba(255,87,51,0.55)] transition-opacity duration-300 group-hover:opacity-100" />

                <motion.div layoutId={`trend-media-${t.id}`} className="relative aspect-[4/5] overflow-hidden bg-zinc-900">
                    {showVideo ? (
                        <VideoThumb
                            src={videoSrc}
                            poster={videoPoster}
                            playOnHover={false}
                            className="absolute inset-0 size-full object-cover transition-transform duration-500 group-hover:scale-[1.04]"
                        />
                    ) : showImage ? (
                        <img
                            src={t.cover}
                            alt={t.name}
                            className="size-full object-cover transition-transform duration-500 group-hover:scale-[1.04]"
                            loading={index < 8 ? 'eager' : 'lazy'}
                        />
                    ) : (
                        <MusicArt name={t.name} />
                    )}

                    {/* readability gradient */}
                    <div className="pointer-events-none absolute inset-x-0 bottom-0 h-2/3 bg-gradient-to-t from-black/85 via-black/25 to-transparent" />

                    {/* top badges */}
                    <div className="absolute inset-x-3 top-3 flex items-start justify-between gap-2">
                        <div className="flex flex-wrap gap-1.5">
                            {t.featured && (
                                <span className="inline-flex items-center gap-1 rounded-full bg-gradient-to-r from-amber-400 to-amber-500 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-black shadow-lg">
                                    <IconSparkles className="h-3 w-3" />
                                    Featured
                                </span>
                            )}
                            {t.hot && !t.featured && (
                                <span className="inline-flex items-center rounded-full bg-[#fc0a35] px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-white shadow-lg">
                                    Hot
                                </span>
                            )}
                        </div>
                        <span className="inline-flex items-center gap-1 rounded-full bg-black/50 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-white/85 backdrop-blur-md ring-1 ring-white/10">
                            <TypeIcon type={t.type} />
                            {t.type}
                        </span>
                    </div>

                    {/* bottom overlay content */}
                    <div className="absolute inset-x-0 bottom-0 p-3.5">
                        <h3 className="line-clamp-1 text-[15px] font-semibold text-white drop-shadow">{t.name}</h3>
                        <div className="mt-2 flex items-center justify-between gap-2">
                            <div className="flex min-w-0 items-center gap-2">
                                <img
                                    className="h-6 w-6 shrink-0 rounded-full object-cover ring-1 ring-white/20"
                                    alt={t.creator}
                                    src={t.avatar}
                                />
                                <span className="truncate text-[12px] font-medium text-white/70">{t.creator}</span>
                            </div>
                            <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-white/10 px-2 py-0.5 text-[11px] font-semibold text-white/80 backdrop-blur-md">
                                <IconUsers className="h-3 w-3" />
                                {t.uses} uses
                            </span>
                        </div>
                    </div>

                    {/* hover CTA */}
                    <div className="pointer-events-none absolute inset-0 flex items-center justify-center opacity-0 transition-opacity duration-300 group-hover:opacity-100">
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-4 py-2 text-[13px] font-semibold text-white shadow-xl backdrop-blur-md ring-1 ring-white/25">
                            <IconWand className="h-4 w-4" />
                            View & Remix
                        </span>
                    </div>
                </motion.div>
            </motion.button>
        </motion.div>
    );
}

function TemplateDetailModal({
    template: t,
    onClose,
    onUse,
    using,
}: {
    template: TrendTemplate;
    onClose: () => void;
    onUse: () => void;
    using: boolean;
}) {
    const [activeSample, setActiveSample] = useState(0);
    const [isMobile, setIsMobile] = useState(() =>
        typeof window !== 'undefined' ? window.matchMedia('(max-width: 767px)').matches : true,
    );
    const samples = t.samples.length ? t.samples : [t.cover];
    const activeSrc = samples[Math.min(activeSample, samples.length - 1)] || t.cover;
    const showVideo = t.coverType === 'video' && Boolean(t.video_url || t.cover);

    useEffect(() => {
        const mq = window.matchMedia('(max-width: 767px)');
        const update = () => setIsMobile(mq.matches);
        update();
        mq.addEventListener('change', update);
        return () => mq.removeEventListener('change', update);
    }, []);

    const contentReveal = {
        hidden: { opacity: 0, y: 14 },
        show: (i: number) => ({ opacity: 1, y: 0, transition: { delay: 0.12 + i * 0.05, duration: 0.4, ease: EASE } }),
    };

    const mediaEl = (
        <>
            {showVideo ? (
                <video
                    src={t.video_url || t.cover}
                    className={`size-full ${isMobile ? 'object-contain' : 'object-cover'}`}
                    autoPlay
                    muted
                    loop
                    playsInline
                    controls
                />
            ) : t.type === 'music' ? (
                <div className="flex size-full flex-col items-center justify-center gap-5 bg-gradient-to-br from-[#1c1226] via-[#12121a] to-[#0b1a17] p-6">
                    {t.cover && !isAudioUrl(t.cover) ? (
                        <img
                            src={t.cover}
                            alt=""
                            className={`rounded-3xl object-cover shadow-2xl ring-1 ring-white/10 ${isMobile ? 'h-44 w-44' : 'h-52 w-52'}`}
                        />
                    ) : (
                        <span
                            className={`flex items-center justify-center rounded-3xl bg-white/[0.04] ring-1 ring-white/10 ${
                                isMobile ? 'h-44 w-44' : 'h-52 w-52'
                            }`}
                        >
                            <IconMusic className="h-16 w-16 text-white/40" />
                        </span>
                    )}
                    {t.audio_url && <audio src={t.audio_url} controls autoPlay className="w-full max-w-md" />}
                </div>
            ) : (
                <img src={activeSrc} alt={t.name} className={`size-full ${isMobile ? 'object-contain' : 'object-cover'}`} />
            )}
        </>
    );

    return (
        <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.25 }}
            className={`fixed inset-0 z-50 flex justify-center backdrop-blur-md ${
                isMobile ? 'items-stretch bg-black/90 p-0' : 'items-center bg-black/80 p-4'
            }`}
            onClick={onClose}
        >
            {isMobile ? (
                <motion.div
                    initial={{ opacity: 0, scale: 0.96 }}
                    animate={{ opacity: 1, scale: 1 }}
                    exit={{ opacity: 0, scale: 0.98 }}
                    transition={{ type: 'spring', stiffness: 320, damping: 28 }}
                    className="relative flex h-full w-full flex-col bg-black"
                    onClick={(e) => e.stopPropagation()}
                >
                    <motion.div layoutId={`trend-media-${t.id}`} className="relative min-h-0 flex-1 overflow-hidden">
                        {mediaEl}
                    </motion.div>

                    <button
                        type="button"
                        title="Close"
                        aria-label="Close"
                        onClick={onClose}
                        className="absolute end-3 top-[max(0.75rem,env(safe-area-inset-top))] z-30 inline-flex h-10 w-10 items-center justify-center rounded-full border border-white/15 bg-black/55 text-white shadow-lg backdrop-blur-md transition hover:bg-black/75"
                    >
                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg>
                    </button>

                    <div className="pointer-events-none absolute inset-x-0 bottom-0 z-30 flex justify-center bg-gradient-to-t from-black/80 via-black/30 to-transparent px-4 pb-[max(1.25rem,env(safe-area-inset-bottom))] pt-16">
                        <motion.button
                            type="button"
                            disabled={using}
                            onClick={onUse}
                            initial={{ y: 28, opacity: 0, scale: 0.9 }}
                            animate={{
                                y: 0,
                                opacity: 1,
                                scale: 1,
                                boxShadow: [
                                    '0 12px 32px -8px rgba(255,87,51,0.55)',
                                    '0 16px 40px -6px rgba(255,87,51,0.85)',
                                    '0 12px 32px -8px rgba(255,87,51,0.55)',
                                ],
                            }}
                            transition={{
                                y: { type: 'spring', stiffness: 380, damping: 22, delay: 0.15 },
                                opacity: { duration: 0.3, delay: 0.1 },
                                scale: { type: 'spring', stiffness: 380, damping: 22, delay: 0.15 },
                                boxShadow: { duration: 2.2, repeat: Infinity, ease: 'easeInOut' },
                            }}
                            whileTap={{ scale: 0.96 }}
                            className="pointer-events-auto inline-flex h-12 items-center justify-center gap-2 rounded-full bg-gradient-to-b from-[#FF6A45] via-[#FF5733] to-[#E04520] px-7 text-[15px] font-semibold text-white disabled:opacity-60"
                        >
                            <motion.span
                                aria-hidden
                                animate={{ rotate: [0, -12, 12, 0] }}
                                transition={{ duration: 2.4, repeat: Infinity, ease: 'easeInOut', delay: 0.6 }}
                                className="inline-flex"
                            >
                                <IconWand className="h-4 w-4" />
                            </motion.span>
                            {using ? 'Opening lab…' : 'Use Template'}
                        </motion.button>
                    </div>
                </motion.div>
            ) : (
                <motion.div
                    initial={{ opacity: 0, y: 40, scale: 0.96 }}
                    animate={{ opacity: 1, y: 0, scale: 1 }}
                    exit={{ opacity: 0, y: 30, scale: 0.97 }}
                    transition={{ type: 'spring', stiffness: 300, damping: 30 }}
                    className="flex max-h-[90vh] w-full max-w-5xl overflow-hidden rounded-3xl border border-white/10 bg-[#0e0e13] shadow-[0_40px_120px_-20px_rgba(0,0,0,0.9)] md:flex-row"
                    onClick={(e) => e.stopPropagation()}
                >
                    <div className="relative flex min-h-0 flex-1 flex-col bg-black md:max-w-[58%]">
                        <motion.div layoutId={`trend-media-${t.id}`} className="relative aspect-auto min-h-0 w-full flex-1 overflow-hidden">
                            {mediaEl}
                            <div className="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/40 via-transparent to-black/10" />
                            {(t.featured || t.hot) && (
                                <div className="absolute start-3 top-3 flex gap-1.5">
                                    {t.hot && !t.featured && (
                                        <span className="rounded-full bg-[#fc0a35] px-2.5 py-1 text-[11px] font-bold uppercase text-white">Hot</span>
                                    )}
                                    {t.featured && (
                                        <span className="inline-flex items-center gap-1 rounded-full bg-gradient-to-r from-amber-400 to-amber-500 px-2.5 py-1 text-[11px] font-bold uppercase text-black">
                                            <IconSparkles className="h-3 w-3" />
                                            Featured
                                        </span>
                                    )}
                                </div>
                            )}
                        </motion.div>

                        {!showVideo && t.type !== 'music' && samples.length > 1 && (
                            <motion.div
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1, transition: { delay: 0.2 } }}
                                className="shrink-0 border-t border-white/[0.06] bg-[#0a0a0e] p-3"
                            >
                                <p className="mb-2 text-[11px] font-medium uppercase tracking-[0.14em] text-white/35">Outputs</p>
                                <div className="flex gap-2 overflow-x-auto pb-0.5" style={{ scrollbarWidth: 'none' }}>
                                    {samples.map((src, i) => (
                                        <button
                                            key={src + i}
                                            type="button"
                                            onClick={() => setActiveSample(i)}
                                            className={`relative h-16 w-24 shrink-0 overflow-hidden rounded-xl transition ${
                                                activeSample === i
                                                    ? 'ring-2 ring-[#FF5733] ring-offset-2 ring-offset-black'
                                                    : 'ring-1 ring-white/10 hover:ring-white/30'
                                            }`}
                                        >
                                            <img src={src} alt="" className="size-full object-cover" loading="lazy" />
                                        </button>
                                    ))}
                                </div>
                            </motion.div>
                        )}
                    </div>

                    <div className="flex min-h-0 w-full flex-col md:w-[42%] md:max-w-md">
                        <motion.div
                            custom={0}
                            variants={contentReveal}
                            initial="hidden"
                            animate="show"
                            className="flex items-start justify-between gap-3 border-b border-white/[0.07] px-4 py-4 sm:px-5"
                        >
                            <div className="min-w-0">
                                <h2 className="truncate text-lg font-semibold tracking-tight text-white sm:text-xl">{t.name}</h2>
                                <div className="mt-2 flex items-center gap-2">
                                    <img src={t.avatar} alt={t.creator} className="h-7 w-7 shrink-0 rounded-full object-cover ring-1 ring-white/10" />
                                    <span className="truncate text-sm text-zinc-400">by {t.creator}</span>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={onClose}
                                className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-zinc-400 transition hover:bg-white/[0.06] hover:text-white"
                                aria-label="Close"
                            >
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                    <path d="M18 6 6 18M6 6l12 12" />
                                </svg>
                            </button>
                        </motion.div>

                        <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-4 scrollbar-thin sm:px-5">
                            <motion.div custom={1} variants={contentReveal} initial="hidden" animate="show" className="grid grid-cols-3 gap-2">
                                <Stat label="Uses" value={String(t.uses)} />
                                <Stat label="Type" value={t.type} />
                                <Stat label="Credits" value={t.credits > 0 ? String(t.credits) : '—'} />
                            </motion.div>

                            {t.description && (
                                <motion.div custom={2} variants={contentReveal} initial="hidden" animate="show" className="space-y-1.5">
                                    <p className="text-[11px] font-medium uppercase tracking-[0.14em] text-white/35">Prompt</p>
                                    <p className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-3 text-[13px] leading-relaxed text-zinc-300">
                                        {t.description}
                                    </p>
                                </motion.div>
                            )}

                            <motion.div custom={3} variants={contentReveal} initial="hidden" animate="show" className="flex flex-wrap gap-1.5">
                                <Chip>{t.model}</Chip>
                                {t.aspect && <Chip>{t.aspect}</Chip>}
                                {t.resolution && <Chip>{t.resolution}</Chip>}
                                {t.duration != null && t.duration !== '' && (
                                    <Chip>
                                        {t.duration === 'auto'
                                            ? 'Auto'
                                            : `${t.duration}${typeof t.duration === 'number' || /^\d+$/.test(String(t.duration)) ? 's' : ''}`}
                                    </Chip>
                                )}
                                {t.type === 'video' && t.generate_audio != null && <Chip>{t.generate_audio ? 'Audio on' : 'Audio off'}</Chip>}
                                {t.type === 'image' && t.quantity && t.quantity > 1 && <Chip>×{t.quantity}</Chip>}
                            </motion.div>
                        </div>

                        <motion.div
                            custom={4}
                            variants={contentReveal}
                            initial="hidden"
                            animate="show"
                            className="shrink-0 space-y-2 border-t border-white/[0.07] p-4 sm:p-5"
                        >
                            <button
                                type="button"
                                disabled={using}
                                onClick={onUse}
                                className="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-b from-[#FF6A45] via-[#FF5733] to-[#E04520] text-[14px] font-semibold text-white shadow-[0_12px_28px_-12px_rgba(255,87,51,0.9)] transition hover:brightness-110 active:scale-[0.99] disabled:opacity-60"
                            >
                                <IconWand className="h-4 w-4" />
                                {using ? 'Opening lab…' : 'Use Template'}
                            </button>
                        </motion.div>
                    </div>
                </motion.div>
            )}
        </motion.div>
    );
}

function Chip({ children }: { children: ReactNode }) {
    return (
        <span className="rounded-lg border border-white/10 bg-white/[0.05] px-2.5 py-1 text-xs font-semibold capitalize text-zinc-300">
            {children}
        </span>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-xl border border-white/[0.07] bg-white/[0.02] px-2 py-2.5 text-center">
            <p className="text-[15px] font-semibold capitalize tabular-nums text-white">{value}</p>
            <p className="mt-0.5 text-[11px] text-white/35">{label}</p>
        </div>
    );
}

function MusicArt({ name }: { name: string }) {
    return (
        <div className="relative flex size-full items-center justify-center bg-gradient-to-br from-[#1c1226] via-[#12121a] to-[#0b1a17]">
            <div className="pointer-events-none absolute inset-0 opacity-40 [background:radial-gradient(circle_at_30%_20%,rgba(255,87,51,0.35),transparent_45%),radial-gradient(circle_at_70%_80%,rgba(99,102,241,0.35),transparent_45%)]" />
            <IconMusic className="relative h-14 w-14 text-white/45" />
            <span className="absolute bottom-3 start-3 rounded-md bg-black/50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white/80 backdrop-blur-sm">
                {name.slice(0, 22)}
            </span>
        </div>
    );
}

function EmptyState({ title, sub, action }: { title: string; sub?: string; action?: ReactNode }) {
    return (
        <div className="rounded-2xl border border-dashed border-white/10 py-20 text-center">
            <p className="text-sm text-white/40">{title}</p>
            {sub && <p className="mx-auto mt-2 max-w-md text-[13px] text-white/25">{sub}</p>}
            {action}
        </div>
    );
}

function FilterSelect({
    value,
    options,
    onChange,
    className,
}: {
    value: string;
    options: string[];
    onChange: (v: string) => void;
    className?: string;
}) {
    return (
        <div className={`relative ${className ?? ''}`}>
            <select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="h-11 w-full cursor-pointer appearance-none rounded-xl border border-white/[0.08] bg-[#0c0c10] pe-9 ps-3.5 text-[13px] text-white/85 outline-none transition focus:border-[#FF5733]/40 focus:ring-2 focus:ring-[#FF5733]/15"
            >
                {options.map((opt) => (
                    <option key={opt} value={opt} className="bg-[#121217] text-zinc-100">
                        {opt}
                    </option>
                ))}
            </select>
            <svg
                className="pointer-events-none absolute end-3 top-1/2 h-4 w-4 -translate-y-1/2 text-white/35"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                strokeWidth="2"
            >
                <path d="m6 9 6 6 6-6" />
            </svg>
        </div>
    );
}

function SectionIcon({ icon }: { icon: 'star' | 'video' | 'image' | 'music' }) {
    if (icon === 'star') return <IconSparkles className="h-4 w-4" />;
    if (icon === 'video') return <IconVideo className="h-4 w-4" />;
    if (icon === 'image') return <IconImage className="h-4 w-4" />;
    return <IconMusic className="h-4 w-4" />;
}

function TypeIcon({ type }: { type: TrendTemplate['type'] }) {
    if (type === 'video') return <IconVideo className="h-3 w-3" />;
    if (type === 'music') return <IconMusic className="h-3 w-3" />;
    return <IconImage className="h-3 w-3" />;
}

function IconSparkles({ className = 'h-4 w-4' }: { className?: string }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 2l1.6 5.4L19 9l-5.4 1.6L12 16l-1.6-5.4L5 9l5.4-1.6L12 2z" />
        </svg>
    );
}

function IconVideo({ className = 'h-4 w-4' }: { className?: string }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
            <rect x="2" y="6" width="14" height="12" rx="2" />
            <path d="m22 8-6 4 6 4V8z" />
        </svg>
    );
}

function IconImage({ className = 'h-4 w-4' }: { className?: string }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
            <rect x="3" y="3" width="18" height="18" rx="2" />
            <circle cx="9" cy="9" r="2" />
            <path d="m21 15-3.5-3.5L9 20" />
        </svg>
    );
}

function IconMusic({ className = 'h-4 w-4' }: { className?: string }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
            <path d="M9 18V5l12-2v13" />
            <circle cx="6" cy="18" r="3" />
            <circle cx="18" cy="16" r="3" />
        </svg>
    );
}

function IconUsers({ className = 'h-4 w-4' }: { className?: string }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
            <circle cx="9" cy="7" r="4" />
            <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
            <path d="M16 3.13a4 4 0 0 1 0 7.75" />
        </svg>
    );
}

function IconWand({ className = 'h-4 w-4' }: { className?: string }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
            <path d="M15 4V2M15 16v-2M8 9h2M20 9h2M17.8 11.8 19 13M15 9h0M17.8 6.2 19 5M3 21l9-9M12.2 6.2 11 5" />
        </svg>
    );
}
