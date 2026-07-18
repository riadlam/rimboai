import { Head, Link, router, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useEffect, useMemo, useRef, useState, type DragEvent } from 'react';
import { useTranslation } from 'react-i18next';
import AppLayout from '@/Layouts/AppLayout';
import { ApiError, apiPost, apiPostForm } from '@/lib/api';
import type { PageProps } from '@/types';

type TrendUpload = {
    key: string;
    kind: 'image' | 'video' | 'audio';
    label: string;
    accept: string;
    required: boolean;
};

type TrendTemplateCard = {
    id: string;
    creation_id: number;
    type: 'image' | 'video' | 'music';
    name: string;
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
    const tmpl = workspace.template;

    const [slots, setSlots] = useState<Record<string, FileSlot>>(() =>
        Object.fromEntries(workspace.uploads.map((u) => [u.key, { file: null, preview: null }])),
    );
    const [creating, setCreating] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        return () => {
            Object.values(slots).forEach((s) => {
                if (s.preview) URL.revokeObjectURL(s.preview);
            });
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const requiredReady = workspace.uploads.every((u) => {
        if (!u.required) return true;
        return Boolean(slots[u.key]?.file);
    });

    const credits = workspace.credits > 0 ? workspace.credits : tmpl.credits;
    const canCreate = requiredReady && !creating;

    const assignFile = (key: string, file: File | null) => {
        setSlots((prev) => {
            const old = prev[key]?.preview;
            if (old) URL.revokeObjectURL(old);
            return {
                ...prev,
                [key]: {
                    file,
                    preview: file && file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
                },
            };
        });
        setError(null);
    };

    const startCreate = async () => {
        if (!canCreate) return;
        if (!props.auth.user) {
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

    return (
        <AppLayout>
            <Head title={tmpl.name || t('useTemplate')} />
            <div className="flex min-h-[calc(100dvh-4rem)] flex-col gap-0 md:flex-row md:overflow-hidden">
                {/* Left: uploads only */}
                <aside className="flex w-full shrink-0 flex-col border-b border-white/[0.06] bg-[#0a0a0e] md:w-[360px] md:border-b-0 md:border-e xl:w-[420px]">
                    <div className="flex items-center gap-3 border-b border-white/[0.06] px-4 py-3.5">
                        <Link
                            href="/trends"
                            className="inline-flex h-9 w-9 items-center justify-center rounded-xl text-zinc-400 transition hover:bg-white/[0.06] hover:text-white"
                            aria-label={t('back')}
                        >
                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                <path d="M15 18l-6-6 6-6" />
                            </svg>
                        </Link>
                        <div className="min-w-0">
                            <p className="truncate text-[15px] font-semibold text-white">{t('useTemplate')}</p>
                            <p className="truncate text-[12px] text-white/40">{tmpl.name}</p>
                        </div>
                    </div>

                    <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-4 scrollbar-thin">
                        {workspace.uploads.length === 0 ? (
                            <p className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-3 text-[13px] leading-relaxed text-zinc-400">
                                {t('noUploadsNeeded')}
                            </p>
                        ) : (
                            workspace.uploads.map((upload) => (
                                <UploadSlot
                                    key={upload.key}
                                    upload={upload}
                                    slot={slots[upload.key] ?? { file: null, preview: null }}
                                    onFile={(file) => assignFile(upload.key, file)}
                                    labelUpload={t('uploadAsset')}
                                    labelReplace={t('replaceAsset')}
                                />
                            ))
                        )}

                        {error && (
                            <p className="rounded-xl border border-red-500/30 bg-red-500/10 px-3 py-2 text-[13px] text-red-200">
                                {error}
                            </p>
                        )}
                    </div>

                    <div className="shrink-0 border-t border-white/[0.06] p-4">
                        <div className="mb-2 flex items-center justify-between text-[12px] text-white/40">
                            <span>{t('stats.credits')}</span>
                            <span className="tabular-nums text-white/70">
                                {credits > 0 ? t('credits', { count: credits }) : '—'}
                                {tokenBalance >= 0 && (
                                    <span className="ms-2 text-white/35">· {tokenBalance} bal.</span>
                                )}
                            </span>
                        </div>
                        <button
                            type="button"
                            disabled={!canCreate}
                            onClick={() => void startCreate()}
                            className="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-b from-[#FF6A45] via-[#FF5733] to-[#E04520] text-[14px] font-semibold text-white shadow-[0_12px_28px_-12px_rgba(255,87,51,0.9)] transition hover:brightness-110 active:scale-[0.99] disabled:opacity-50"
                        >
                            {creating ? t('creating') : t('create')}
                        </button>
                    </div>
                </aside>

                {/* Right: example */}
                <motion.div
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    className="relative flex min-h-[50vh] min-w-0 flex-1 flex-col bg-[#08080d] md:min-h-0"
                >
                    <div className="absolute start-4 top-4 z-10 rounded-full border border-white/10 bg-black/50 px-3 py-1 text-[11px] font-medium uppercase tracking-wider text-white/70 backdrop-blur-md">
                        {t('example')}
                    </div>
                    <div className="relative min-h-0 flex-1 overflow-hidden">
                        {showVideo ? (
                            <video
                                src={tmpl.video_url || tmpl.cover}
                                className="size-full object-contain"
                                autoPlay
                                muted
                                loop
                                playsInline
                                controls
                            />
                        ) : tmpl.type === 'music' ? (
                            <div className="flex size-full flex-col items-center justify-center gap-5 bg-gradient-to-br from-[#1c1226] via-[#12121a] to-[#0b1a17] p-6">
                                {tmpl.cover && !isAudioUrl(tmpl.cover) ? (
                                    <img
                                        src={tmpl.cover}
                                        alt=""
                                        className="h-52 w-52 rounded-3xl object-cover shadow-2xl ring-1 ring-white/10"
                                    />
                                ) : (
                                    <span className="flex h-52 w-52 items-center justify-center rounded-3xl bg-white/[0.04] ring-1 ring-white/10">
                                        <svg className="h-16 w-16 text-white/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                            <path d="M9 18V5l12-2v13" />
                                            <circle cx="6" cy="18" r="3" />
                                            <circle cx="18" cy="16" r="3" />
                                        </svg>
                                    </span>
                                )}
                                {tmpl.audio_url && <audio src={tmpl.audio_url} controls autoPlay className="w-full max-w-md" />}
                            </div>
                        ) : (
                            <img src={tmpl.cover} alt={tmpl.name} className="size-full object-contain" />
                        )}
                    </div>
                </motion.div>
            </div>
        </AppLayout>
    );
}

function UploadSlot({
    upload,
    slot,
    onFile,
    labelUpload,
    labelReplace,
}: {
    upload: TrendUpload;
    slot: FileSlot;
    onFile: (file: File | null) => void;
    labelUpload: string;
    labelReplace: string;
}) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [dragOver, setDragOver] = useState(false);

    const onDrop = (e: DragEvent) => {
        e.preventDefault();
        setDragOver(false);
        const file = e.dataTransfer.files?.[0] ?? null;
        if (file) onFile(file);
    };

    const previewLabel = useMemo(() => {
        if (!slot.file) return null;
        return slot.file.name;
    }, [slot.file]);

    return (
        <div className="space-y-2">
            <p className="text-[11px] font-medium uppercase tracking-[0.14em] text-white/35">{upload.label}</p>
            <button
                type="button"
                onClick={() => inputRef.current?.click()}
                onDragOver={(e) => {
                    e.preventDefault();
                    setDragOver(true);
                }}
                onDragLeave={() => setDragOver(false)}
                onDrop={onDrop}
                className={`relative flex w-full flex-col items-center justify-center gap-2 overflow-hidden rounded-2xl border border-dashed px-4 py-8 transition ${
                    dragOver
                        ? 'border-[#FF5733]/60 bg-[#FF5733]/10'
                        : slot.file
                          ? 'border-white/15 bg-white/[0.03]'
                          : 'border-white/10 bg-white/[0.02] hover:border-white/25'
                }`}
            >
                {slot.preview ? (
                    <img src={slot.preview} alt="" className="mb-1 h-28 w-auto max-w-full rounded-xl object-cover" />
                ) : slot.file && upload.kind === 'video' ? (
                    <span className="mb-1 flex h-16 w-16 items-center justify-center rounded-2xl bg-white/[0.06] text-white/50">
                        <svg className="h-7 w-7" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M8 5v14l11-7z" />
                        </svg>
                    </span>
                ) : null}
                <span className="text-[13px] font-medium text-white/80">{slot.file ? labelReplace : labelUpload}</span>
                {previewLabel && <span className="max-w-full truncate text-[11px] text-white/35">{previewLabel}</span>}
            </button>
            <input
                ref={inputRef}
                type="file"
                accept={upload.accept}
                className="hidden"
                onChange={(e) => onFile(e.target.files?.[0] ?? null)}
            />
            {slot.file && (
                <button
                    type="button"
                    onClick={() => onFile(null)}
                    className="text-[12px] text-white/40 transition hover:text-white/70"
                >
                    Remove
                </button>
            )}
        </div>
    );
}
