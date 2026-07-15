import { Head, Link } from '@inertiajs/react';
import { useMemo, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import AppLayout from '@/Layouts/AppLayout';
import {
    categoryGradient,
    type InnovationPost,
    type MediaType,
} from '@/data/innovationPrompts';

type CategoryProp = {
    id: string;
    slug: string;
    name: string;
    icon?: string | null;
    gradient?: string | null;
};

type Props = {
    categories?: CategoryProp[];
    posts?: InnovationPost[];
};

const FALLBACK_ICONS: Record<string, ReactNode> = {
    user: (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-4 w-4">
            <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" />
            <circle cx="12" cy="7" r="4" />
        </svg>
    ),
    share2: (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-4 w-4">
            <circle cx="18" cy="5" r="3" />
            <circle cx="6" cy="12" r="3" />
            <circle cx="18" cy="19" r="3" />
            <line x1="8.59" x2="15.42" y1="13.51" y2="17.49" />
            <line x1="15.41" x2="8.59" y1="6.51" y2="10.49" />
        </svg>
    ),
    music: (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-4 w-4">
            <path d="M9 18V5l12-2v13" />
            <circle cx="6" cy="18" r="3" />
            <circle cx="18" cy="16" r="3" />
        </svg>
    ),
    sparkles: (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-4 w-4">
            <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z" />
            <path d="M20 3v4" />
            <path d="M22 5h-4" />
        </svg>
    ),
};

function categoryIcon(icon?: string | null): ReactNode {
    return FALLBACK_ICONS[icon || ''] || FALLBACK_ICONS.sparkles;
}

export default function Innovation({ categories = [], posts = [] }: Props) {
    const { t } = useTranslation('innovation');
    const { t: tc } = useTranslation('common');
    const [mediaType, setMediaType] = useState<MediaType>('images');
    const [category, setCategory] = useState<string>('all');
    const [query, setQuery] = useState('');

    const categoryTabs = useMemo(
        () => [{ id: 'all', slug: 'all', name: tc('all'), icon: 'sparkles' }, ...categories],
        [categories, tc],
    );

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        return posts.filter((p) => {
            if (p.media !== mediaType) return false;
            if (category !== 'all' && p.category !== category) return false;
            if (
                q &&
                !p.title.toLowerCase().includes(q) &&
                !p.category.includes(q) &&
                !(p.category_label || '').toLowerCase().includes(q)
            ) {
                return false;
            }
            return true;
        });
    }, [posts, mediaType, category, query]);

    return (
        <AppLayout>
            <Head title={t('title')} />
            <div className="-mx-4 -my-4 sm:-mx-5 lg:-mx-6 lg:-my-5 xl:-mx-8">
                <div className="flex min-h-screen flex-col bg-[#0A0A0A] pb-20 md:pb-0">
                    <div className="sticky top-0 z-10 border-b border-white/5 bg-[#0A0A0A]/95 backdrop-blur-sm">
                        <div className="px-4 pb-3 pt-4 md:px-6">
                            <div className="mb-3 flex items-center gap-3">
                                <Link
                                    href="/"
                                    className="inline-flex h-9 w-9 flex-shrink-0 cursor-pointer items-center justify-center rounded-full bg-zinc-800/80"
                                    aria-label={t('backHome')}
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="h-4 w-4 rtl:rotate-180">
                                        <path d="m12 19-7-7 7-7" />
                                        <path d="M19 12H5" />
                                    </svg>
                                </Link>
                                <div className="relative flex-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-white/40">
                                        <circle cx="11" cy="11" r="8" />
                                        <path d="m21 21-4.3-4.3" />
                                    </svg>
                                    <input
                                        type="text"
                                        value={query}
                                        onChange={(e) => setQuery(e.target.value)}
                                        placeholder={t('search')}
                                        className="flex h-9 w-full rounded-full border border-white/10 bg-zinc-800/60 px-3 py-2 ps-10 text-sm text-white placeholder:text-white/40 outline-none"
                                    />
                                </div>
                            </div>

                            <div className="scrollbar-hide flex items-center gap-2 overflow-x-auto md:flex-wrap">
                                <div className="flex flex-shrink-0 items-center gap-1 rounded-full border border-white/10 bg-zinc-900/90 p-1">
                                    {(
                                        [
                                            { id: 'images' as const, label: t('tabs.images') },
                                            { id: 'videos' as const, label: t('tabs.videos') },
                                            { id: 'music' as const, label: t('tabs.music') },
                                        ]
                                    ).map((opt) => (
                                        <button
                                            key={opt.id}
                                            type="button"
                                            onClick={() => setMediaType(opt.id)}
                                            className={`flex cursor-pointer items-center gap-1.5 whitespace-nowrap rounded-full px-3.5 py-1.5 text-sm font-medium transition-all ${
                                                mediaType === opt.id ? 'bg-white text-black' : 'text-white/60'
                                            }`}
                                        >
                                            <span>{opt.label}</span>
                                        </button>
                                    ))}
                                </div>

                                {categoryTabs.map((cat) => {
                                    const active = category === cat.id;
                                    return (
                                        <button
                                            key={cat.id}
                                            type="button"
                                            onClick={() => setCategory(cat.id)}
                                            className={`flex cursor-pointer items-center gap-2 whitespace-nowrap rounded-full px-4 py-2 text-sm font-medium transition-all ${
                                                active ? 'bg-white text-black' : 'bg-zinc-800/80 text-white/70'
                                            }`}
                                        >
                                            {categoryIcon(cat.icon)}
                                            <span>{cat.name}</span>
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    </div>

                    <div className="px-4 py-4 md:px-6 md:py-6">
                        {filtered.length === 0 ? (
                            <div className="flex h-40 items-center justify-center text-sm text-white/40">{t('empty')}</div>
                        ) : (
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                                {filtered.map((prompt) => (
                                    <PromptCard key={prompt.id} prompt={prompt} />
                                ))}
                            </div>
                        )}
                        <div className="flex h-20 items-center justify-center" />
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

function PromptCard({ prompt }: { prompt: InnovationPost }) {
    const gradient = categoryGradient(prompt.category, prompt.gradient);

    return (
        <Link
            href={`/post/${prompt.id}`}
            className="group cursor-pointer overflow-hidden rounded-lg text-start"
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
            <div className={`relative aspect-[3/4] overflow-hidden bg-gradient-to-br ${gradient}`}>
                {prompt.video ? (
                    <video
                        src={prompt.video}
                        poster={prompt.image}
                        muted
                        loop
                        playsInline
                        preload="none"
                        className="h-full w-full object-cover transition duration-500 group-hover:scale-105"
                    />
                ) : (
                    <img
                        src={prompt.image}
                        alt={prompt.title}
                        loading="lazy"
                        className="h-full w-full object-cover transition duration-500 group-hover:scale-105"
                    />
                )}
                <div className="absolute inset-0 bg-black/0 transition-colors group-hover:bg-black/30" />
            </div>
        </Link>
    );
}
