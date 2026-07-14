import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { buildInnovationLabDraft, labHrefForPost, type InnovationPost } from '@/data/innovationPrompts';
import { saveLabReuseDraft } from '@/lib/labReuse';

type Props = {
    id: string;
    post?: InnovationPost | null;
};

export default function InnovationPost({ id, post }: Props) {
    if (!post) {
        return (
            <AppLayout>
                <Head title="Post not found" />
                <div className="flex h-[calc(100dvh-3.5rem)] flex-col items-center justify-center gap-3 text-center md:h-[calc(100dvh-4rem)]">
                    <p className="text-white/60">This post could not be found.</p>
                    <p className="text-xs text-white/30">{id}</p>
                    <Link href="/innovation" className="cursor-pointer text-sm text-[#ff8f73] hover:text-[#ffb39f]">
                        Back to Innovation
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
    const [copied, setCopied] = useState(false);
    const [opening, setOpening] = useState(false);
    const categoryLabel = post.category_label || post.category;
    const labLabel =
        post.lab_type === 'text-to-video' || post.media === 'videos'
            ? 'Video Lab'
            : post.lab_type === 'text-to-music' || post.media === 'music'
              ? 'Music Lab'
              : 'Image Lab';

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
                            poster={post.image}
                            className="absolute inset-0 h-full w-full object-cover"
                            autoPlay
                            muted
                            loop
                            playsInline
                        />
                    ) : (
                        <img
                            src={post.image}
                            alt={post.title}
                            className="absolute inset-0 h-full w-full object-cover"
                        />
                    )}
                    <Link
                        href="/innovation"
                        className="absolute start-3 top-3 inline-flex items-center gap-1.5 rounded-full bg-black/55 px-3 py-1.5 text-xs font-medium text-white backdrop-blur-sm transition hover:bg-black/70"
                    >
                        <svg className="h-3.5 w-3.5 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                            <path strokeLinecap="round" strokeLinejoin="round" d="m12 19-7-7 7-7M19 12H5" />
                        </svg>
                        Back
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
                                <p className="text-xs font-medium text-white/50">Prompt</p>
                                <button type="button" onClick={copyPrompt} className="text-xs text-white/40 transition hover:text-white">
                                    {copied ? 'Copied' : 'Copy'}
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
                            <span className="relative">{opening ? 'Opening…' : `Use in ${labLabel}`}</span>
                        </button>
                        <Link
                            href="/innovation"
                            className="inline-flex w-full items-center justify-center rounded-xl border border-white/10 bg-white/[0.06] px-4 py-2.5 text-sm font-medium text-white/65 transition hover:border-white/20 hover:bg-white/[0.1] hover:text-white"
                        >
                            Browse more
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    );
}

function LabOptionsChips({ post }: { post: InnovationPost }) {
    const chips: string[] = [];
    if (post.aspect_ratio) chips.push(post.aspect_ratio);
    if (post.resolution) chips.push(String(post.resolution));
    if (post.duration != null && post.duration !== '') {
        chips.push(post.duration === 'auto' ? 'Auto duration' : `${post.duration}s`);
    }
    if (post.quantity && post.quantity > 1) chips.push(`×${post.quantity}`);
    if (post.generate_audio === true) chips.push('Audio on');
    if (post.generate_audio === false) chips.push('Audio off');
    if (post.image_mode === 'variations') chips.push('Variations');
    if (post.style_prompt) chips.push('Style set');

    if (chips.length === 0) return null;

    return (
        <div>
            <p className="mb-1.5 text-xs font-medium text-white/50">Lab options</p>
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
