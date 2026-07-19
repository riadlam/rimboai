import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import AppLayout from '@/Layouts/AppLayout';
import { buildInnovationLabDraft, labHrefForPost, normalizeAspectRatio, type InnovationPost } from '@/data/innovationPrompts';
import { saveLabReuseDraft } from '@/lib/labReuse';

type Props = {
    id: string;
    post?: InnovationPost | null;
};

export default function InnovationPost({ id, post }: Props) {
    const { t } = useTranslation('innovation');

    if (!post) {
        return (
            <AppLayout>
                <Head title={t('postNotFoundTitle')} />
                <div className="flex h-[calc(100dvh-3.5rem)] flex-col items-center justify-center gap-3 text-center md:h-[calc(100dvh-4rem)]">
                    <p className="text-white/60">{t('postNotFound')}</p>
                    <p className="text-xs text-white/30">{id}</p>
                    <Link href="/innovation" className="cursor-pointer text-sm text-[#ff8f73] hover:text-[#ffb39f]">
                        {t('back')}
                    </Link>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title={post.title} />
            <PostDetail post={post} />
        </AppLayout>
    );
}

function PostDetail({ post }: { post: InnovationPost }) {
    const { t } = useTranslation('innovation');
    const [copied, setCopied] = useState(false);
    const [opening, setOpening] = useState(false);
    const gallery = (post.images?.length ? post.images : [post.image]).filter(Boolean);
    const [slide, setSlide] = useState(0);
    const activeImage = gallery[Math.min(slide, gallery.length - 1)] || post.image;
    const categoryLabel = post.category_label || post.category;
    const labLabel =
        post.lab_type === 'text-to-video' || post.media === 'videos'
            ? t('labs.video')
            : post.lab_type === 'text-to-music' || post.media === 'music'
              ? t('labs.music')
              : t('labs.image');

    const copyPrompt = async () => {
        try {
            await navigator.clipboard.writeText(post.prompt);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1800);
        } catch {
            /* ignore */
        }
    };

    const recreate = () => {
        if (opening) return;
        setOpening(true);
        const draft = buildInnovationLabDraft(post);
        draft.aspect = normalizeAspectRatio(
            post.aspect_ratio ?? post.settings?.aspect ?? post.settings?.aspect_ratio,
            draft.aspect || '1:1',
        );
        saveLabReuseDraft(draft);
        router.visit(labHrefForPost(post), {
            onFinish: () => setOpening(false),
            onError: () => setOpening(false),
        });
    };

    return (
        <div className="-mx-4 -my-4 h-[calc(100dvh-3.5rem)] overflow-hidden sm:-mx-5 md:h-[calc(100dvh-4rem)] lg:-mx-6 lg:-my-5 xl:-mx-8 [&_button]:cursor-pointer [&_a]:cursor-pointer">
            <div className="flex h-full flex-col gap-3 p-3 sm:gap-4 sm:p-4 lg:flex-row lg:gap-5 lg:p-5">
                <div className="relative min-h-0 flex-[1.15] overflow-hidden rounded-2xl bg-zinc-900 lg:flex-[1.4]">
                    {post.video ? (
                        <video
                            src={post.video}
                            poster={activeImage}
                            className="absolute inset-0 h-full w-full object-cover"
                            autoPlay
                            muted
                            loop
                            playsInline
                        />
                    ) : (
                        <img
                            src={activeImage}
                            alt={post.title}
                            className="absolute inset-0 h-full w-full object-cover"
                        />
                    )}
                    {!post.video && gallery.length > 1 && (
                        <>
                            <button
                                type="button"
                                onClick={() => setSlide((s) => (s - 1 + gallery.length) % gallery.length)}
                                className="absolute start-3 top-1/2 z-10 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-black/55 text-white backdrop-blur-sm transition hover:bg-black/70"
                                aria-label="Previous image"
                            >
                                <svg className="h-4 w-4 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="m15 19-7-7 7-7" />
                                </svg>
                            </button>
                            <button
                                type="button"
                                onClick={() => setSlide((s) => (s + 1) % gallery.length)}
                                className="absolute end-3 top-1/2 z-10 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-black/55 text-white backdrop-blur-sm transition hover:bg-black/70"
                                aria-label="Next image"
                            >
                                <svg className="h-4 w-4 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="m9 5 7 7-7 7" />
                                </svg>
                            </button>
                            <div className="absolute inset-x-0 bottom-3 z-10 flex justify-center gap-1.5">
                                {gallery.map((url, i) => (
                                    <button
                                        key={url}
                                        type="button"
                                        onClick={() => setSlide(i)}
                                        className={`h-1.5 rounded-full transition ${
                                            i === slide ? 'w-5 bg-white' : 'w-1.5 bg-white/40 hover:bg-white/70'
                                        }`}
                                        aria-label={`Image ${i + 1}`}
                                    />
                                ))}
                            </div>
                        </>
                    )}
                    <Link
                        href="/innovation"
                        className="absolute start-3 top-3 z-10 inline-flex items-center gap-1.5 rounded-full bg-black/55 px-3 py-1.5 text-xs font-medium text-white backdrop-blur-sm transition hover:bg-black/70"
                    >
                        <svg className="h-3.5 w-3.5 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                            <path strokeLinecap="round" strokeLinejoin="round" d="m12 19-7-7 7-7M19 12H5" />
                        </svg>
                        {t('back')}
                    </Link>
                </div>

                <div className="flex min-h-0 flex-1 flex-col justify-between gap-3 rounded-2xl bg-zinc-900/60 p-4 sm:p-5 lg:max-w-md lg:flex-none lg:w-[380px] xl:w-[420px]">
                    <div className="min-h-0 space-y-3">
                        <div>
                            <p className="text-xs text-white/40">
                                {categoryLabel} · {post.model}
                            </p>
                            <h1 className="mt-1 line-clamp-2 font-[family-name:Outfit,sans-serif] text-lg font-semibold leading-snug text-white sm:text-xl">
                                {post.title}
                            </h1>
                        </div>

                        <div>
                            <div className="mb-1.5 flex items-center justify-between gap-2">
                                <p className="text-xs font-medium text-white/50">{t('prompt')}</p>
                                <button type="button" onClick={copyPrompt} className="text-xs text-white/40 transition hover:text-white">
                                    {copied ? t('copied') : t('copy')}
                                </button>
                            </div>
                            <div className="rounded-xl bg-black/30 p-3">
                                <p className="line-clamp-5 whitespace-pre-wrap text-xs leading-relaxed text-zinc-300 sm:line-clamp-6 sm:text-sm">
                                    {post.prompt}
                                </p>
                            </div>
                        </div>

                        <LabOptionsChips post={post} />
                    </div>

                    <div className="flex shrink-0 flex-col gap-2">
                        <button
                            type="button"
                            onClick={recreate}
                            disabled={opening}
                            className="group relative inline-flex w-full items-center justify-center gap-2 overflow-hidden rounded-xl border-2 border-[#ffb39f]/55 bg-[#FF5733] px-4 py-2.5 text-sm font-semibold text-white shadow-[inset_0_1px_0_rgba(255,255,255,0.2),0_0_0_1px_rgba(255,87,51,0.35)] transition hover:border-[#ffd0c0] hover:bg-[#ff6a4a] disabled:cursor-wait disabled:opacity-70"
                        >
                            <span
                                aria-hidden
                                className="pointer-events-none absolute inset-y-0 -start-1/2 w-1/2 skew-x-[-20deg] animate-[lab-btn-shine_2.8s_ease-in-out_infinite] bg-gradient-to-r from-transparent via-white/30 to-transparent"
                            />
                            <span className="relative">{opening ? t('opening') : t('useIn', { lab: labLabel })}</span>
                        </button>
                        <Link
                            href="/innovation"
                            className="inline-flex w-full items-center justify-center rounded-xl border border-white/10 bg-white/[0.06] px-4 py-2.5 text-sm font-medium text-white/65 transition hover:border-white/20 hover:bg-white/[0.1] hover:text-white"
                        >
                            {t('browseMore')}
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    );
}

function LabOptionsChips({ post }: { post: InnovationPost }) {
    const { t } = useTranslation('innovation');
    const chips: string[] = [];
    if (post.aspect_ratio) chips.push(post.aspect_ratio);
    if (post.resolution) chips.push(String(post.resolution));
    if (post.duration != null && post.duration !== '') {
        chips.push(post.duration === 'auto' ? t('chips.autoDuration') : `${post.duration}s`);
    }
    if (post.quantity && post.quantity > 1) chips.push(`×${post.quantity}`);
    if (post.generate_audio === true) chips.push(t('chips.audioOn'));
    if (post.generate_audio === false) chips.push(t('chips.audioOff'));
    if (post.image_mode === 'variations') chips.push(t('chips.variations'));
    if (post.style_prompt) chips.push(t('chips.styleSet'));

    if (chips.length === 0) return null;

    return (
        <div>
            <p className="mb-1.5 text-xs font-medium text-white/50">{t('labOptions')}</p>
            <div className="flex flex-wrap gap-1.5">
                {chips.map((chip) => (
                    <span
                        key={chip}
                        className="rounded-lg border border-white/10 bg-black/30 px-2 py-1 text-[11px] font-medium text-white/70"
                    >
                        {chip}
                    </span>
                ))}
            </div>
        </div>
    );
}
