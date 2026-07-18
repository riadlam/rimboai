import { Head, Link, router, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useEffect, useRef, useState, type DragEvent } from 'react';
import { useTranslation } from 'react-i18next';
import AppLayout from '@/Layouts/AppLayout';
import { ApiError, apiPost, apiPostForm } from '@/lib/api';
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

type TrendWorkspace = {
    key: string;
    type: 'image' | 'video' | 'music';
    creation_id: number;
    template: TrendTemplateCard;
    uploads: TrendUpload[];
    locked: Record<string, unknown>;
    credits: number;
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

function isAudioUrl(url?: string | null): boolean {
    return Boolean(url && /\.(mp3|wav|ogg|m4a)(\?|$)/i.test(url));
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

    useEffect(() => {
        setSlots(Object.fromEntries(workspace.uploads.map((u) => [u.key, { file: null, preview: null }])));
        setError(null);
        setCreating(false);
        // Only reset when navigating to a different template.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [workspace.key]);

    useEffect(() => {
        return () => {
            Object.values(slots).forEach((s) => {
                if (s.preview) URL.revokeObjectURL(s.preview);
            });
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

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
            await apiPost('/trends/use', {
                type: workspace.type,
                id: workspace.creation_id,
            });

            const form = new FormData();
            const locked = workspace.locked;

            const uploadOne = async (file: File) => {
                const up = new FormData();
                up.append('file', file);
                const uploaded = await apiPostForm<{ url: string; type: string }>('/lab/media/upload', up);
                return uploaded.url;
            };

            if (workspace.type === 'image') {
                form.append('prompt', String(locked.prompt ?? ''));
                if (locked.endpoint_id) form.append('endpoint_id', String(locked.endpoint_id));
                if (locked.aspect) form.append('aspect', String(locked.aspect));
                if (locked.resolution) form.append('resolution', String(locked.resolution));
                if (locked.quantity) form.append('quantity', String(locked.quantity));
                form.append('mode', String(locked.image_mode ?? 'create'));
                for (const u of workspace.uploads) {
                    const file = slots[u.key]?.file;
                    if (file) form.append('references[]', file);
                }
            } else if (workspace.type === 'video') {
                form.append('prompt', String(locked.prompt ?? ''));
                if (locked.endpoint_id) form.append('endpoint_id', String(locked.endpoint_id));
                if (locked.aspect) form.append('aspect', String(locked.aspect));
                if (locked.resolution) form.append('resolution', String(locked.resolution));
                if (locked.duration != null) form.append('duration', String(locked.duration));
                form.append('audio', locked.audio ? '1' : '0');
                if (locked.frame_mode) form.append('frame_mode', String(locked.frame_mode));

                for (const u of workspace.uploads) {
                    const file = slots[u.key]?.file;
                    if (!file) continue;
                    const url = await uploadOne(file);
                    if (!url) continue;
                    if (u.kind === 'video') form.append('video_urls[]', url);
                    else if (u.kind === 'audio') form.append('audio_urls[]', url);
                    else form.append('image_urls[]', url);
                }
            } else {
                form.append('prompt', String(locked.prompt ?? ''));
                if (locked.endpoint_id) form.append('endpoint_id', String(locked.endpoint_id));
                if (locked.lyrics) form.append('lyrics', String(locked.lyrics));
                for (const u of workspace.uploads) {
                    const file = slots[u.key]?.file;
                    if (file && u.kind === 'audio') form.append('audio', file);
                }
            }

            await apiPostForm(workspace.generate_url, form);
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

    const showVideo = tmpl.coverType === 'video' && Boolean(tmpl.video_url || tmpl.cover);
    const displayTitle = (tmpl.trend_title || tmpl.name || '').trim() || t('useTemplate');

    return (
        <AppLayout flush>
            <Head title={displayTitle} />
            <div className="flex w-full min-w-0 flex-col md:h-full md:min-h-0 [&_a]:cursor-pointer [&_button]:cursor-pointer [&_button:disabled]:cursor-not-allowed [&_label]:cursor-pointer">
                <div className="flex flex-col rounded-xl bg-[#070708] md:min-h-0 md:flex-1 md:overflow-hidden">
                    <div className="flex flex-col md:min-h-0 md:flex-1 md:overflow-hidden md:flex-row">
                        {/* Left panel — same shell as ToolCreatePanel */}
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
                                            <p className="mt-1.5 text-[13px] leading-relaxed text-white/45">
                                                {displayTitle}
                                            </p>
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

                                    {error && (
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

                        {/* Right panel — same preview shell as ToolDetail */}
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
                                                <video
                                                    src={tmpl.video_url || tmpl.cover}
                                                    poster={tmpl.thumbnail_url || undefined}
                                                    className="h-full w-full object-cover"
                                                    playsInline
                                                    loop
                                                    muted
                                                    autoPlay
                                                    controls
                                                    preload="metadata"
                                                />
                                            </div>
                                        ) : tmpl.type === 'music' ? (
                                            <div className="flex aspect-video w-full flex-col items-center justify-center gap-5 bg-gradient-to-br from-[#1c1226] via-[#12121a] to-[#0b1a17] p-6">
                                                {tmpl.cover && !isAudioUrl(tmpl.cover) ? (
                                                    <img
                                                        src={tmpl.cover}
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
                                                {tmpl.audio_url && (
                                                    <audio src={tmpl.audio_url} controls autoPlay className="w-full max-w-md" />
                                                )}
                                            </div>
                                        ) : (
                                            <div className="flex aspect-square w-full max-h-[70vh] items-center justify-center bg-black/40 sm:aspect-video">
                                                <img
                                                    src={tmpl.cover}
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
                    <button
                        type="button"
                        onClick={onClear}
                        className="text-[11px] text-white/35 transition hover:text-white/70"
                    >
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
