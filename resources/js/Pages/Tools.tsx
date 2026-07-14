import { Head, Link } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useMemo, useState, type ReactNode } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import type { Tool } from '@/types';

type Props = {
    tools: Tool[];
};

type Filter = 'all' | 'video' | 'image';

type ToolKind = 'video' | 'image';

type ToolGroup = {
    id: string;
    title: string;
    description: string;
    routes: string[];
};

/** Tools treated as image-oriented for the Image filter. */
const IMAGE_ROUTES = new Set(['tools.animate-a-picture']);

const GROUPS: ToolGroup[] = [
    {
        id: 'enhance',
        title: 'Enhance & Upscale',
        description: 'Sharpen, denoise, and boost any clip to higher clarity.',
        routes: ['tools.video-upscaler', 'tools.video-enhancer', 'tools.denoise-video', 'tools.anime-video-enhancer'],
    },
    {
        id: 'transform',
        title: 'Transform & Effects',
        description: 'Face swap, lip sync, anime styles, filters, and motion.',
        routes: [
            'tools.lip-sync',
            'tools.face-swap-video',
            'tools.video-to-anime-ai',
            'tools.ai-video-filters',
            'tools.ai-dance-generator',
            'tools.motion-control',
        ],
    },
    {
        id: 'edit',
        title: 'Edit & Extend',
        description: 'Cut, extend, restyle, and clean up your footage.',
        routes: [
            'tools.ai-video-editor',
            'tools.ai-video-extender',
            'tools.video-to-video',
            'tools.video-background-remover',
            'tools.remove-subtitles-from-video',
        ],
    },
    {
        id: 'create',
        title: 'Create & Animate',
        description: 'Bring stills to life and generate cinematic sound.',
        routes: ['tools.animate-a-picture', 'tools.ai-sound-effect-generator'],
    },
];

function toolKind(route: string): ToolKind {
    return IMAGE_ROUTES.has(route) ? 'image' : 'video';
}

export default function Tools({ tools }: Props) {
    const [filter, setFilter] = useState<Filter>('all');
    const [query, setQuery] = useState('');

    const byRoute = useMemo(() => {
        const map = new Map<string, Tool>();
        tools.forEach((t) => map.set(t.route, t));
        return map;
    }, [tools]);

    const sections = useMemo(() => {
        const q = query.trim().toLowerCase();

        return GROUPS.map((group) => {
            const items = group.routes
                .map((r) => byRoute.get(r))
                .filter((t): t is Tool => !!t)
                .filter((t) => {
                    const kind = toolKind(t.route);
                    if (filter === 'video' && kind !== 'video') return false;
                    if (filter === 'image' && kind !== 'image') return false;
                    if (q && !t.name.toLowerCase().includes(q)) return false;
                    return true;
                });

            return { ...group, items };
        }).filter((g) => g.items.length > 0);
    }, [byRoute, filter, query]);

    return (
        <AppLayout>
            <Head title="Tools" />
            <div className="-mx-4 -my-4 sm:-mx-5 lg:-mx-6 lg:-my-5 xl:-mx-8">
                {/* Sticky toolbar */}
                <div className="sticky top-0 z-20 border-b border-white/5 bg-[#070708]/95 backdrop-blur-md">
                    <div className="px-4 pb-3 pt-4 md:px-6">
                        <div className="flex items-center gap-2.5 overflow-x-auto scrollbar-hide md:gap-3">
                            <Link
                                href="/"
                                className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-zinc-800/80 text-white/70 transition hover:bg-zinc-700/80 hover:text-white"
                                aria-label="Back to home"
                            >
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="m12 19-7-7 7-7M19 12H5" />
                                </svg>
                            </Link>

                            <FilterPill active={filter === 'all'} onClick={() => setFilter('all')} icon={<IconGrid />}>
                                All
                            </FilterPill>
                            <FilterPill active={filter === 'video'} onClick={() => setFilter('video')} icon={<IconFilm />}>
                                Video
                            </FilterPill>
                            <FilterPill active={filter === 'image'} onClick={() => setFilter('image')} icon={<IconImage />}>
                                Image
                            </FilterPill>

                            <div className="relative ms-auto hidden min-w-[200px] max-w-[280px] flex-1 md:block">
                                <svg className="absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-white/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                    <circle cx="11" cy="11" r="8" />
                                    <path strokeLinecap="round" d="m21 21-4.3-4.3" />
                                </svg>
                                <input
                                    type="search"
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                    placeholder="Search tools"
                                    className="h-9 w-full rounded-full border border-white/10 bg-zinc-800/60 ps-10 pe-3 text-sm text-white placeholder:text-white/40 outline-none transition focus:border-[#FF5733]/40 focus:ring-2 focus:ring-[#FF5733]/20"
                                />
                            </div>
                        </div>

                        <div className="relative mt-3 md:hidden">
                            <svg className="absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-white/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                <circle cx="11" cy="11" r="8" />
                                <path strokeLinecap="round" d="m21 21-4.3-4.3" />
                            </svg>
                            <input
                                type="search"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                placeholder="Search tools"
                                className="h-9 w-full rounded-full border border-white/10 bg-zinc-800/60 ps-10 pe-3 text-sm text-white placeholder:text-white/40 outline-none transition focus:border-[#FF5733]/40 focus:ring-2 focus:ring-[#FF5733]/20"
                            />
                        </div>
                    </div>
                </div>

                {/* Groups */}
                <div className="space-y-4 px-4 py-4 md:px-6 md:py-6">
                    {sections.length === 0 && (
                        <div className="rounded-2xl border border-white/[0.06] bg-zinc-900/40 px-6 py-16 text-center">
                            <p className="text-sm text-white/45">No tools match your search.</p>
                            <button
                                type="button"
                                onClick={() => {
                                    setQuery('');
                                    setFilter('all');
                                }}
                                className="mt-3 text-sm font-medium text-[#ff8f73] hover:text-[#ffb39f]"
                            >
                                Clear filters
                            </button>
                        </div>
                    )}

                    {sections.map((section, i) => (
                        <motion.div
                            key={section.id}
                            initial={{ opacity: 0, y: 18 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ delay: Math.min(i * 0.06, 0.3), duration: 0.4 }}
                            className="rounded-2xl border border-zinc-800/50 bg-zinc-900/50 p-4 md:p-5"
                        >
                            <div className="flex flex-col gap-4 md:flex-row md:gap-6">
                                {/* Featured panel */}
                                <div className="w-full shrink-0 md:w-[200px]">
                                    <div className="relative aspect-video overflow-hidden rounded-xl bg-zinc-800 md:aspect-square">
                                        <video
                                            src={section.items[0].video}
                                            poster={section.items[0].poster}
                                            className="absolute inset-0 h-full w-full object-cover"
                                            autoPlay
                                            muted
                                            loop
                                            playsInline
                                            preload="metadata"
                                        />
                                        <div className="absolute inset-0 bg-gradient-to-t from-black/85 via-black/25 to-transparent" />
                                        <div className="absolute inset-x-0 bottom-0 p-3">
                                            <h2 className="font-[family-name:Outfit,sans-serif] text-lg font-bold text-white md:text-xl">
                                                {section.title}
                                            </h2>
                                            <p className="mt-0.5 line-clamp-2 text-xs text-white/60">{section.description}</p>
                                        </div>
                                    </div>
                                </div>

                                {/* Tools rail */}
                                <div className="min-w-0 flex-1">
                                    <p className="mb-3 text-xs text-zinc-400">
                                        Available tools ({section.items.length})
                                    </p>
                                    <div className="scrollbar-hide flex gap-3 overflow-x-auto pb-1">
                                        {section.items.map((tool) => (
                                            <ToolMiniCard key={tool.route} tool={tool} />
                                        ))}
                                    </div>
                                </div>
                            </div>
                        </motion.div>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}

function FilterPill({
    active,
    onClick,
    children,
    icon,
}: {
    active: boolean;
    onClick: () => void;
    children: string;
    icon: ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`inline-flex shrink-0 cursor-pointer items-center gap-2 whitespace-nowrap rounded-full px-4 py-2 text-sm font-medium transition-all ${
                active
                    ? 'bg-white text-black shadow-[0_8px_24px_-12px_rgba(255,255,255,0.5)]'
                    : 'bg-zinc-800/80 text-white/70 hover:bg-zinc-700/80 hover:text-white'
            }`}
        >
            {icon}
            {children}
        </button>
    );
}

function ToolMiniCard({ tool }: { tool: Tool }) {
    const path = '/tools/' + tool.route.replace('tools.', '');

    return (
        <Link
            href={path}
            className="group w-[140px] shrink-0 cursor-pointer overflow-hidden rounded-xl bg-zinc-900 sm:w-[160px]"
            onMouseEnter={(e) => {
                const v = e.currentTarget.querySelector('video');
                v?.play()?.catch(() => undefined);
            }}
            onMouseLeave={(e) => {
                const v = e.currentTarget.querySelector('video');
                if (v) {
                    v.pause();
                    v.currentTime = 0;
                }
            }}
        >
            <div className="relative aspect-[4/5] overflow-hidden bg-zinc-800">
                <video
                    src={tool.video}
                    poster={tool.poster}
                    className="absolute inset-0 h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
                    muted
                    loop
                    playsInline
                    preload="none"
                />
                {tool.badge && (
                    <span className="absolute start-2 top-2 rounded-md bg-emerald-500/90 px-1.5 py-0.5 text-[9px] font-bold uppercase text-white">
                        {tool.badge}
                    </span>
                )}
            </div>
            <div className="bg-zinc-900 p-2.5">
                <div className="flex items-center gap-1">
                    <h3 className="flex-1 truncate text-xs font-medium text-white">{tool.name}</h3>
                    <svg
                        className="h-3 w-3 shrink-0 text-zinc-500 transition-colors group-hover:text-[#FF5733]"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        strokeWidth="2"
                    >
                        <path strokeLinecap="round" strokeLinejoin="round" d="m9 18 6-6-6-6" />
                    </svg>
                </div>
            </div>
        </Link>
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

function IconFilm() {
    return (
        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
            <rect width="18" height="18" x="3" y="3" rx="2" />
            <path d="M7 3v18M3 7.5h4M3 12h18M3 16.5h4M17 3v18M17 7.5h4M17 16.5h4" />
        </svg>
    );
}

function IconImage() {
    return (
        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
            <rect width="18" height="18" x="3" y="3" rx="2" />
            <circle cx="9" cy="9" r="2" />
            <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" />
        </svg>
    );
}
