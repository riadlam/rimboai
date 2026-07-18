import { Head, Link, router, usePage } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useCallback, useEffect, useRef, useState, type DragEvent } from 'react';
import { useTranslation } from 'react-i18next';
import AppLayout from '@/Layouts/AppLayout';
import ImageLabPreviewModal, { type ImageLabPreviewItem } from '@/Components/ImageLabPreviewModal';
import LabVideoPlayer from '@/Components/LabVideoPlayer';
import { ApiError, apiGet, apiPostForm } from '@/lib/api';
import type { PageProps } from '@/types';

type TrendUpload = {
    key: string;
    kind: 'image' | 'video' | 'audio';
    label: string;
    label_key?: string;
    accept: string;
    required: boolean;
};

type TrendTemplateCard = {
    id: string;
    creation_id: number;
    type: 'image' | 'video' | 'music';
    name: string;
    trend_title?: string | null;
    creator: string;
    avatar: string;
    cover: string;
    coverType: 'video' | 'image' | 'audio';
    video_url?: string | null;
    audio_url?: string | null;
    thumbnail_url?: string | null;
    samples: string[];
    uses: number;
    credits: number;
};

type UserTrendLatest = {
    id: number;
    video_url?: string | null;
    preview_url?: string | null;
    thumbnail_url?: string | null;
    images?: string[];
    audio_url?: string | null;
    cover_url?: string | null;
};

type TrendWorkspace = {
    key: string;
    type: 'image' | 'video' | 'music';
    creation_id: number;
    template: TrendTemplateCard;
    uploads: TrendUpload[];
    locked: Record<string, unknown>;
    credits: number;
    /** Completed remakes by this user from this template. Example replaces only when > 1. */
    user_remake_count?: number;
    user_latest?: UserTrendLatest | null;
    generate_url: string;
    lab_href: string;
};

type Props = {
    workspace: TrendWorkspace;
    tokenBalance: number;
};

type FileSlot = {
    file: File | null;
    preview: string | null;
};

type RemakeCreation = {
    id: number;
    status: string;
    progress_percent?: number | null;
    progress_message?: string | null;
    prompt?: string;
    model_name?: string | null;
    video_url?: string | null;
    thumbnail_url?: string | null;
    preview_url?: string | null;
    images?: string[];
    audio_url?: string | null;
    cover_url?: string | null;
    aspect?: string | null;
    resolution?: string | null;
    duration?: string | number | null;
    audio?: boolean | null;
    mode?: string | null;
    error?: string | null;
    created_at?: string | null;
};

function isAudioUrl(url?: string | null): boolean {
    return Boolean(url && /\.(mp3|wav|ogg|m4a)(\?|$)/i.test(url));
}

function isMobileViewport(): boolean {
    if (typeof window === 'undefined') return false;
    return window.matchMedia('(max-width: 767px)').matches;
}

function statusUrl(type: TrendWorkspace['type'], id: number): string {
    if (type === 'image') return `/lab/image/creations/${id}/status`;
    if (type === 'music') return `/lab/music/creations/${id}/status`;
    return `/lab/video/creations/${id}/status`;
}

function resultSrc(type: TrendWorkspace['type'], c: RemakeCreation | UserTrendLatest): string | null {
    if (type === 'video') return c.video_url || c.preview_url || null;
    if (type === 'image') return ('images' in c && c.images?.[0]) || c.preview_url || null;
    return ('cover_url' in c ? c.cover_url : null) || c.preview_url || null;
}

export default function TrendTemplate({ workspace, tokenBalance }: Props) {
    const { t } = useTranslation('trends');
    const { props } = usePage<PageProps>();
    const isGuest = props.auth.user === null;
    const tmpl = workspace.template;

    const [slots, setSlots] = useState<Record<string, FileSlot>>(() =>
        Object.fromEntries(workspace.uploads.map((u) => [u.key, { file: null, preview: null }])),
    );
    const [draggingKey, setDraggingKey] = useState<string | null>(null);
    const [creating, setCreating] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const [modalOpen, setModalOpen] = useState(false);
    const [job, setJob] = useState<RemakeCreation | null>(null);
    const [detailsOpen, setDetailsOpen] = useState(false);
    const [completedRemakeCount, setCompletedRemakeCount] = useState(workspace.user_remake_count ?? 0);
    const [exampleOverride, setExampleOverride] = useState<RemakeCreation | UserTrendLatest | null>(
        (workspace.user_remake_count ?? 0) > 1 ? (workspace.user_latest ?? null) : null,
    );
    const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const remakeCountRef = useRef(workspace.user_remake_count ?? 0);

    useEffect(() => {
        setSlots(Object.fromEntries(workspace.uploads.map((u) => [u.key, { file: null, preview: null }])));
        setError(null);
        setCreating(false);
        setModalOpen(false);
        setJob(null);
        setDetailsOpen(false);
        const count = workspace.user_remake_count ?? 0;
        remakeCountRef.current = count;
        setCompletedRemakeCount(count);
        setExampleOverride(count > 1 ? (workspace.user_latest ?? null) : null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [workspace.key]);

    useEffect(() => {
        return () => {
            Object.values(slots).forEach((s) => {
                if (s.preview) URL.revokeObjectURL(s.preview);
            });
            if (pollRef.current) clearInterval(pollRef.current);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const stopPoll = useCallback(() => {
        if (pollRef.current) {
            clearInterval(pollRef.current);
            pollRef.current = null;
        }
    }, []);

    const startPoll = useCallback(
        (creationId: number) => {
            stopPoll();
            pollRef.current = setInterval(async () => {
                try {
                    const data = await apiGet<RemakeCreation>(statusUrl(workspace.type, creationId));
                    setJob(data);
                    if (data.status === 'completed') {
                        stopPoll();
                        setCreating(false);
                        const nextCount = remakeCountRef.current + 1;
                        remakeCountRef.current = nextCount;
                        setCompletedRemakeCount(nextCount);
                        // Replace the template example only after the 2nd+ remake.
                        if (nextCount > 1) {
                            setExampleOverride(data);
                        }
                        return;
                    }
                    if (data.status === 'failed' || data.status === 'cancelled') {
                        stopPoll();
                        setCreating(false);
                        setError(data.error || t('createFailed'));
                    }
                } catch (e) {
                    stopPoll();
                    setCreating(false);
                    setError(e instanceof ApiError ? e.message : t('createFailed'));
                }
            }, 2500);
        },
        [stopPoll, t, workspace.type],
    );

    const requiredReady =
        workspace.uploads.length === 0 ||
        workspace.uploads.every((u) => !u.required || Boolean(slots[u.key]?.file));

    const credits = workspace.credits > 0 ? workspace.credits : tmpl.credits;
    const canCreate = requiredReady && !creating && !isGuest;

    const assignFile = (key: string, file?: File) => {
        if (!file) return;
        setSlots((prev) => {
            const old = prev[key]?.preview;
            if (old) URL.revokeObjectURL(old);
            const isVisual = file.type.startsWith('image/') || file.type.startsWith('video/');
            return {
                ...prev,
                [key]: {
                    file,
                    preview: isVisual ? URL.createObjectURL(file) : null,
                },
            };
        });
        setError(null);
    };

    const clearFile = (key: string) => {
        setSlots((prev) => {
            const old = prev[key]?.preview;
            if (old) URL.revokeObjectURL(old);
            return { ...prev, [key]: { file: null, preview: null } };
        });
    };

    const startCreate = async () => {
        if (!canCreate) return;
        if (isGuest) {
            router.visit('/?login');
            return;
        }

        setCreating(true);
        setError(null);

        try {
            const uploadOne = async (file: File) => {
                const up = new FormData();
                up.append('file', file);
                const uploaded = await apiPostForm<{ url: string; type: string }>('/lab/media/upload', up);
                return uploaded.url;
            };

            const form = new FormData();
            form.append('type', workspace.type);
            form.append('id', String(workspace.creation_id));

            for (const u of workspace.uploads) {
                const file = slots[u.key]?.file;
                if (!file) continue;
                const url = await uploadOne(file);
                if (!url) continue;
                if (u.kind === 'video') form.append('video_urls[]', url);
                else if (u.kind === 'audio') form.append('audio_urls[]', url);
                else form.append('image_urls[]', url);
            }

            const data = await apiPostForm<
                RemakeCreation & { ok?: boolean; type?: string; user_remake_count?: number }
            >('/trends/remake', form);
            const mobile = isMobileViewport();
            const baseCount = data.user_remake_count ?? remakeCountRef.current;
            remakeCountRef.current = baseCount;
            setCompletedRemakeCount(baseCount);

            if (mobile) {
                setJob(data);
                setModalOpen(true);
                if (data.status === 'completed') {
                    const nextCount = baseCount + 1;
                    remakeCountRef.current = nextCount;
                    setCompletedRemakeCount(nextCount);
                    if (nextCount > 1) setExampleOverride(data);
                    setCreating(false);
                } else if (data.status !== 'failed') {
                    startPoll(data.id);
                } else {
                    setCreating(false);
                }
                return;
            }

            setCreating(false);
            router.visit(workspace.lab_href);
        } catch (e) {
            const message =
                e instanceof ApiError
                    ? e.message
                    : e instanceof Error
                      ? e.message
                      : t('createFailed');
            setError(message);
            setCreating(false);
        }
    };

    const closeModal = () => {
        stopPoll();
        setModalOpen(false);
        setCreating(false);
        setDetailsOpen(false);
    };

    const replaceExample = completedRemakeCount > 1 && exampleOverride != null;
    const exampleSrc = replaceExample ? resultSrc(workspace.type, exampleOverride) : null;
    const showVideo = replaceExample
        ? workspace.type === 'video' && Boolean(exampleSrc)
        : tmpl.coverType === 'video' && Boolean(tmpl.video_url || tmpl.cover);
    const exampleVideoSrc = replaceExample && exampleSrc ? exampleSrc : tmpl.video_url || tmpl.cover;
    const examplePoster =
        replaceExample && exampleOverride && 'thumbnail_url' in exampleOverride
            ? exampleOverride.thumbnail_url || undefined
            : tmpl.thumbnail_url || undefined;
    const exampleImageSrc = replaceExample && exampleSrc ? exampleSrc : tmpl.cover;
    const exampleAudioSrc =
        replaceExample && exampleOverride && 'audio_url' in exampleOverride && exampleOverride.audio_url
            ? exampleOverride.audio_url
            : tmpl.audio_url;
    const exampleCoverSrc =
        replaceExample && exampleOverride && 'cover_url' in exampleOverride && exampleOverride.cover_url
            ? exampleOverride.cover_url
            : tmpl.cover;
    const displayTitle = (tmpl.trend_title || tmpl.name || '').trim() || t('useTemplate');
    const progress = Math.max(5, Math.min(99, job?.progress_percent ?? 12));
    const jobDone = job?.status === 'completed';
    const jobFailed = job?.status === 'failed' || job?.status === 'cancelled';
    const doneSrc = job ? resultSrc(workspace.type, job) : null;

    const previewItem: ImageLabPreviewItem | null = (() => {
        if (!job || job.status !== 'completed') return null;
        const src = resultSrc(workspace.type, job);
        if (!src && workspace.type !== 'music') return null;
        const method =
            workspace.type === 'image'
                ? 'image-to-image'
                : (job.mode as ImageLabPreviewItem['method']) || 'image-to-video';
        return {
            id: `trend-remake-${job.id}`,
            prompt: job.prompt || displayTitle,
            src: src || job.cover_url || tmpl.cover,
            favorite: false,
            aspect: job.aspect || undefined,
            resolution: job.resolution || undefined,
            duration: job.duration ?? null,
            audio: job.audio ?? null,
            modelName: job.model_name ?? null,
            method: method as ImageLabPreviewItem['method'],
            videoUrl: workspace.type === 'video' ? job.video_url || src || undefined : undefined,
        };
    })();

    return (
        <AppLayout flush>
            <Head title={displayTitle} />
            <div className="flex w-full min-w-0 flex-col md:h-full md:min-h-0 [&_a]:cursor-pointer [&_button]:cursor-pointer [&_button:disabled]:cursor-not-allowed [&_label]:cursor-pointer">
                <div className="flex flex-col rounded-xl bg-[#070708] md:min-h-0 md:flex-1 md:overflow-hidden">
                    <div className="flex flex-col md:min-h-0 md:flex-1 md:overflow-hidden md:flex-row">
                        <motion.aside
                            initial={{ opacity: 0, x: -12 }}
                            animate={{ opacity: 1, x: 0 }}
                            transition={{ duration: 0.35, ease: [0.22, 1, 0.36, 1] }}
                            className="relative flex w-full shrink-0 flex-col border-b border-white/[0.06] bg-[#0a0a0f] md:h-full md:min-h-0 md:w-[380px] md:overflow-hidden md:border-b-0 md:border-r xl:w-[420px]"
                        >
                            <div className="pointer-events-none absolute inset-x-0 top-0 h-44 bg-[radial-gradient(ellipse_at_top,rgba(255,87,51,0.2),transparent_70%)]" />
                            <div className="pointer-events-none absolute inset-x-6 top-24 h-24 rounded-full bg-[#FF5733]/10 blur-3xl" />

                            <div className="relative flex min-h-0 flex-1 flex-col">
                                <div className="relative min-h-0 flex-1 space-y-4 overflow-y-auto px-3 py-3 scrollbar-thin md:pb-4">
                                    <div className="space-y-3">
                                        <div className="flex items-center gap-2">
                                            <Link
                                                href="/trends"
                                                className="inline-flex h-8 w-8 items-center justify-center rounded-full border border-white/10 bg-white/[0.04] text-white/60 transition hover:border-orange-400/35 hover:bg-orange-500/10 hover:text-orange-100"
                                                aria-label={t('back')}
                                            >
                                                <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.2">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" />
                                                </svg>
                                            </Link>
                                            <span className="rounded-full border border-white/10 bg-white/[0.04] px-2.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-white/45">
                                                {t('templateBadge')}
                                            </span>
                                        </div>
                                        <div>
                                            <h1 className="font-[family-name:Outfit,sans-serif] text-[22px] font-semibold leading-tight tracking-tight text-white">
                                                {t('useTemplate')}
                                            </h1>
                                            <p className="mt-1.5 text-[13px] leading-relaxed text-white/45">{displayTitle}</p>
                                        </div>
                                    </div>

                                    {workspace.uploads.map((upload) => (
                                        <UploadSlot
                                            key={upload.key}
                                            upload={upload}
                                            slot={slots[upload.key]}
                                            dragging={draggingKey === upload.key}
                                            label={
                                                upload.label_key
                                                    ? t(upload.label_key, { defaultValue: upload.label })
                                                    : upload.label
                                            }
                                            hint={
                                                upload.kind === 'audio'
                                                    ? t('uploadAudioTypes')
                                                    : upload.kind === 'video'
                                                      ? t('uploadVideoTypes')
                                                      : t('uploadImageTypes')
                                            }
                                            changeLabel={t('changeFile')}
                                            uploadHint={t('uploadHint')}
                                            onDragState={(on) => setDraggingKey(on ? upload.key : null)}
                                            onFile={(file) => assignFile(upload.key, file)}
                                            onClear={() => clearFile(upload.key)}
                                        />
                                    ))}
                                </div>

                                <div className="relative shrink-0 border-t border-white/[0.07] bg-[#0a0a0f]/95 p-3 backdrop-blur-xl">
                                    <div className="mb-2.5 flex items-center justify-between gap-2">
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            <span className="rounded-lg border border-white/10 bg-white/[0.04] px-2 py-1 text-[11px] capitalize text-white/65">
                                                {tmpl.type}
                                            </span>
                                            <span className="rounded-lg border border-white/10 bg-white/[0.04] px-2 py-1 text-[11px] text-white/65">
                                                {tmpl.uses} {t('stats.uses').toLowerCase()}
                                            </span>
                                        </div>
                                        <div className="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/[0.04] px-2 py-1 text-[11px] text-white/65">
                                            <svg className="h-3 w-3 text-[#FF8A65]" viewBox="0 0 24 24" fill="currentColor">
                                                <path d="M12 2l2.4 7.2L22 12l-7.6 2.8L12 22l-2.4-7.2L2 12l7.6-2.8L12 2z" />
                                            </svg>
                                            <span>{credits > 0 ? credits : '—'}</span>
                                            <span className="text-white/35">{t('stats.credits')}</span>
                                        </div>
                                    </div>

                                    {error && !modalOpen && (
                                        <p className="mb-2 text-[11px] leading-snug text-red-300/90">{error}</p>
                                    )}
                                    {isGuest && (
                                        <p className="mb-2 text-[11px] text-white/40">{t('signInToCreate')}</p>
                                    )}
                                    {tokenBalance > 0 && credits > tokenBalance && !isGuest && (
                                        <p className="mb-2 text-[11px] text-amber-200/80">{t('insufficientCredits')}</p>
                                    )}

                                    <motion.button
                                        type="button"
                                        whileTap={canCreate ? { scale: 0.98 } : undefined}
                                        disabled={!canCreate}
                                        onClick={() => {
                                            if (!canCreate) return;
                                            void startCreate();
                                        }}
                                        className="group relative flex h-12 w-full items-center justify-center gap-2 overflow-hidden rounded-xl bg-gradient-to-b from-[#FF6A45] via-[#FF5733] to-[#D63A18] text-sm font-semibold text-white shadow-[0_10px_30px_rgba(255,87,51,0.35)] transition disabled:cursor-not-allowed disabled:opacity-45"
                                    >
                                        {!creating && (
                                            <span className="pointer-events-none absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/35 to-transparent transition-transform duration-[900ms] ease-out group-hover:translate-x-full" />
                                        )}
                                        {creating ? (
                                            <>
                                                <span className="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white" />
                                                <span>{t('creating')}</span>
                                            </>
                                        ) : (
                                            <>
                                                <svg className="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                                                    <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z" />
                                                </svg>
                                                <span>
                                                    {isGuest
                                                        ? t('signInToCreate')
                                                        : `${t('create')} · ${credits > 0 ? credits : '—'} ${t('stats.credits')}`}
                                                </span>
                                            </>
                                        )}
                                    </motion.button>
                                </div>
                            </div>
                        </motion.aside>

                        <motion.div
                            initial={{ opacity: 0 }}
                            animate={{ opacity: 1 }}
                            transition={{ delay: 0.08, duration: 0.4 }}
                            className="relative flex min-h-[50vh] min-w-0 w-full flex-col md:min-h-0 md:flex-1 md:overflow-hidden"
                        >
                            <div aria-hidden className="pointer-events-none absolute inset-0">
                                <div className="absolute left-1/2 top-1/3 h-80 w-80 -translate-x-1/2 rounded-full bg-[#FF5733]/12 blur-[120px]" />
                                <div className="absolute inset-0 bg-[radial-gradient(circle_at_50%_35%,rgba(255,255,255,0.035),transparent_55%)]" />
                            </div>

                            <div className="relative z-10 flex items-center justify-between gap-3 border-b border-white/[0.05] px-4 py-3 md:px-5">
                                <div className="min-w-0">
                                    <h2 className="truncate font-[family-name:Outfit,sans-serif] text-[17px] font-semibold tracking-tight text-white md:text-[18px]">
                                        {displayTitle}
                                    </h2>
                                </div>
                            </div>

                            <div className="relative z-10 flex min-h-0 flex-1 items-center justify-center p-4 md:p-6">
                                <motion.div
                                    initial={{ opacity: 0, y: 16, scale: 0.98 }}
                                    animate={{ opacity: 1, y: 0, scale: 1 }}
                                    transition={{ duration: 0.45, ease: [0.22, 1, 0.36, 1] }}
                                    className="relative w-full max-w-4xl"
                                >
                                    <div className="absolute -inset-px rounded-[1.35rem] bg-gradient-to-b from-white/15 via-white/5 to-transparent opacity-70" />
                                    <div className="relative overflow-hidden rounded-[1.25rem] border border-white/[0.08] bg-black/50 shadow-[0_40px_100px_-40px_rgba(0,0,0,0.9)]">
                                        {showVideo ? (
                                            <div className="aspect-video w-full">
                                                <LabVideoPlayer
                                                    src={exampleVideoSrc}
                                                    poster={examplePoster}
                                                    previewSeconds={5}
                                                    objectFit="contain"
                                                />
                                            </div>
                                        ) : tmpl.type === 'music' ? (
                                            <div className="flex aspect-video w-full flex-col items-center justify-center gap-5 bg-gradient-to-br from-[#1c1226] via-[#12121a] to-[#0b1a17] p-6">
                                                {exampleCoverSrc && !isAudioUrl(exampleCoverSrc) ? (
                                                    <img
                                                        src={exampleCoverSrc}
                                                        alt=""
                                                        className="h-40 w-40 rounded-3xl object-cover shadow-2xl ring-1 ring-white/10"
                                                    />
                                                ) : (
                                                    <span className="flex h-40 w-40 items-center justify-center rounded-3xl bg-white/[0.04] ring-1 ring-white/10">
                                                        <svg className="h-14 w-14 text-white/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                                            <path d="M9 18V5l12-2v13" />
                                                            <circle cx="6" cy="18" r="3" />
                                                            <circle cx="18" cy="16" r="3" />
                                                        </svg>
                                                    </span>
                                                )}
                                                {exampleAudioSrc && (
                                                    <audio src={exampleAudioSrc} controls autoPlay className="w-full max-w-md" />
                                                )}
                                            </div>
                                        ) : (
                                            <div className="flex aspect-square w-full max-h-[70vh] items-center justify-center bg-black/40 sm:aspect-video">
                                                <img
                                                    src={exampleImageSrc}
                                                    alt={displayTitle}
                                                    className="max-h-full max-w-full object-contain"
                                                />
                                            </div>
                                        )}
                                        <div className="pointer-events-none absolute inset-x-0 top-0 h-24 bg-gradient-to-b from-black/50 to-transparent" />
                                        <div className="pointer-events-none absolute inset-x-0 bottom-0 h-28 bg-gradient-to-t from-black/70 via-black/25 to-transparent" />
                                    </div>
                                </motion.div>
                            </div>
                        </motion.div>
                    </div>
                </div>
            </div>

            <AnimatePresence>
                {modalOpen && (
                    <motion.div
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        className="fixed inset-0 z-[80] flex items-end justify-center bg-black/70 p-3 backdrop-blur-sm sm:items-center md:hidden"
                        onClick={closeModal}
                    >
                        <motion.div
                            initial={{ opacity: 0, y: 28, scale: 0.98 }}
                            animate={{ opacity: 1, y: 0, scale: 1 }}
                            exit={{ opacity: 0, y: 16, scale: 0.98 }}
                            transition={{ duration: 0.28, ease: [0.22, 1, 0.36, 1] }}
                            className="relative w-full max-w-md overflow-hidden rounded-3xl border border-white/10 bg-[#101016] shadow-[0_30px_80px_rgba(0,0,0,0.55)]"
                            onClick={(e) => e.stopPropagation()}
                        >
                            <div className="pointer-events-none absolute inset-x-0 top-0 h-28 bg-[radial-gradient(ellipse_at_top,rgba(255,87,51,0.28),transparent_70%)]" />
                            <button
                                type="button"
                                onClick={closeModal}
                                className="absolute end-3 top-3 z-10 rounded-lg p-1.5 text-zinc-500 transition hover:bg-white/[0.06] hover:text-white"
                                aria-label={t('close')}
                            >
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                    <path d="M18 6 6 18" />
                                    <path d="m6 6 12 12" />
                                </svg>
                            </button>

                            <div className="relative space-y-4 p-5 pt-6">
                                {!jobDone && !jobFailed && (
                                    <>
                                        <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl border border-white/10 bg-white/[0.04]">
                                            <span className="h-8 w-8 animate-spin rounded-full border-2 border-white/20 border-t-[#FF5733]" />
                                        </div>
                                        <div className="text-center">
                                            <h3 className="font-[family-name:Outfit,sans-serif] text-lg font-semibold text-white">
                                                {t('generatingTitle')}
                                            </h3>
                                            <p className="mt-2 text-[13px] leading-relaxed text-white/50">
                                                {t('generatingBody')}
                                            </p>
                                        </div>
                                        <div className="space-y-2">
                                            <div className="flex items-center justify-between text-[11px] text-white/45">
                                                <span>{job?.progress_message || t('creating')}</span>
                                                <span>{progress}%</span>
                                            </div>
                                            <div className="h-2 overflow-hidden rounded-full bg-white/10">
                                                <motion.div
                                                    className="h-full rounded-full bg-gradient-to-r from-[#FF6A45] to-[#FF5733]"
                                                    initial={{ width: '8%' }}
                                                    animate={{ width: `${progress}%` }}
                                                    transition={{ duration: 0.4 }}
                                                />
                                            </div>
                                        </div>
                                        <Link
                                            href="/history"
                                            className="flex h-11 w-full items-center justify-center rounded-xl border border-white/12 bg-white/[0.04] text-sm font-medium text-white/85 transition hover:border-orange-400/35 hover:bg-orange-500/10"
                                        >
                                            {t('viewHistory')}
                                        </Link>
                                    </>
                                )}

                                {jobFailed && (
                                    <div className="space-y-4 text-center">
                                        <h3 className="text-lg font-semibold text-rose-100">{t('createFailed')}</h3>
                                        <p className="text-[13px] text-rose-100/70">{job?.error || error}</p>
                                        <button
                                            type="button"
                                            onClick={closeModal}
                                            className="h-11 w-full rounded-xl bg-white/10 text-sm font-medium text-white"
                                        >
                                            {t('close')}
                                        </button>
                                    </div>
                                )}

                                {jobDone && (
                                    <div className="space-y-4">
                                        <div className="text-center">
                                            <h3 className="font-[family-name:Outfit,sans-serif] text-lg font-semibold text-white">
                                                {t('resultReady')}
                                            </h3>
                                            <p className="mt-1 text-[12px] text-white/45">{t('tapForDetails')}</p>
                                        </div>
                                        <div className="space-y-3">
                                            {workspace.type === 'video' && doneSrc ? (
                                                <div className="aspect-[9/16] max-h-[52vh] w-full overflow-hidden rounded-2xl border border-white/10">
                                                    <LabVideoPlayer
                                                        src={doneSrc}
                                                        poster={job?.thumbnail_url || undefined}
                                                        previewSeconds={5}
                                                        objectFit="cover"
                                                        className="!rounded-none"
                                                    />
                                                </div>
                                            ) : workspace.type === 'music' ? (
                                                <div className="flex aspect-video flex-col items-center justify-center gap-3 rounded-2xl border border-white/10 bg-gradient-to-br from-[#1c1226] to-[#0b1a17] p-4">
                                                    {job?.cover_url && (
                                                        <img src={job.cover_url} alt="" className="h-24 w-24 rounded-2xl object-cover" />
                                                    )}
                                                    {job?.audio_url && (
                                                        <audio src={job.audio_url} controls className="w-full" />
                                                    )}
                                                </div>
                                            ) : doneSrc ? (
                                                <button
                                                    type="button"
                                                    onClick={() => setDetailsOpen(true)}
                                                    className="w-full overflow-hidden rounded-2xl border border-white/10"
                                                >
                                                    <img src={doneSrc} alt="" className="max-h-[52vh] w-full object-contain" />
                                                </button>
                                            ) : null}
                                            <button
                                                type="button"
                                                onClick={() => setDetailsOpen(true)}
                                                className="flex h-11 w-full items-center justify-center rounded-xl bg-gradient-to-b from-[#FF6A45] via-[#FF5733] to-[#D63A18] text-sm font-semibold text-white"
                                            >
                                                {t('viewDetails')}
                                            </button>
                                        </div>
                                        <Link
                                            href="/history"
                                            className="flex h-11 w-full items-center justify-center rounded-xl border border-white/12 bg-white/[0.04] text-sm font-medium text-white/85"
                                        >
                                            {t('viewHistory')}
                                        </Link>
                                    </div>
                                )}
                            </div>
                        </motion.div>
                    </motion.div>
                )}
            </AnimatePresence>

            {detailsOpen && previewItem && (
                <ImageLabPreviewModal
                    image={previewItem}
                    index={0}
                    total={1}
                    onClose={() => setDetailsOpen(false)}
                    hidePrompt={false}
                />
            )}
        </AppLayout>
    );
}

function UploadSlot({
    upload,
    slot,
    dragging,
    label,
    hint,
    changeLabel,
    uploadHint,
    onDragState,
    onFile,
    onClear,
}: {
    upload: TrendUpload;
    slot?: FileSlot;
    dragging: boolean;
    label: string;
    hint: string;
    changeLabel: string;
    uploadHint: string;
    onDragState: (on: boolean) => void;
    onFile: (file?: File) => void;
    onClear: () => void;
}) {
    const inputRef = useRef<HTMLInputElement>(null);
    const isImage = upload.accept.startsWith('image');
    const isAudio = upload.accept.startsWith('audio');

    const onDrop = (e: DragEvent) => {
        e.preventDefault();
        onDragState(false);
        onFile(e.dataTransfer.files[0]);
    };

    return (
        <section className="space-y-2">
            <div className="flex items-center justify-between gap-2">
                <p className="text-[11px] font-medium uppercase tracking-[0.12em] text-white/40">{label}</p>
                {slot?.file && (
                    <button type="button" onClick={onClear} className="text-[11px] text-white/35 transition hover:text-white/70">
                        Remove
                    </button>
                )}
            </div>
            <div
                onDragOver={(e) => {
                    e.preventDefault();
                    onDragState(true);
                }}
                onDragLeave={(e) => {
                    e.preventDefault();
                    onDragState(false);
                }}
                onDrop={onDrop}
                onClick={() => inputRef.current?.click()}
                className={`group relative flex cursor-pointer flex-col items-center justify-center overflow-hidden rounded-2xl border border-dashed px-4 py-6 transition ${
                    dragging
                        ? 'border-[#FF5733]/60 bg-orange-500/[0.08]'
                        : 'border-white/15 bg-gradient-to-b from-white/[0.05] to-white/[0.02] hover:border-orange-400/40 hover:bg-orange-500/[0.04]'
                }`}
            >
                {slot?.preview && !isAudio ? (
                    <div className="relative w-full">
                        {isImage ? (
                            <img src={slot.preview} alt="" className="mx-auto max-h-40 rounded-xl object-contain" />
                        ) : (
                            <video src={slot.preview} className="mx-auto max-h-40 rounded-xl object-contain" muted playsInline />
                        )}
                        <p className="mt-2 truncate text-center text-[12px] font-medium text-zinc-200">{slot.file?.name}</p>
                        <p className="text-center text-[11px] text-white/35">{changeLabel}</p>
                    </div>
                ) : slot?.file && isAudio ? (
                    <div className="text-center">
                        <p className="text-[13px] font-medium text-zinc-100">{slot.file.name}</p>
                        <p className="mt-1 text-[11px] text-white/35">{changeLabel}</p>
                    </div>
                ) : (
                    <>
                        <div className="mb-3 flex h-11 w-11 items-center justify-center rounded-2xl border border-white/10 bg-white/[0.05]">
                            <svg className="h-5 w-5 text-[#FF8A65]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                            </svg>
                        </div>
                        <p className="text-[13px] font-medium text-zinc-100">{uploadHint}</p>
                        <p className="mt-1 text-[11px] text-white/35">{hint}</p>
                    </>
                )}
                <input
                    ref={inputRef}
                    type="file"
                    accept={upload.accept}
                    className="hidden"
                    onChange={(e) => onFile(e.target.files?.[0])}
                />
            </div>
        </section>
    );
}
