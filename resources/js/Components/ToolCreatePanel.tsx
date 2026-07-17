import { Link, usePage } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useCallback, useEffect, useMemo, useRef, useState, type DragEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiError, apiGet, apiPost, apiPostForm } from '@/lib/api';
import { estimateToolCredits } from '@/lib/toolCredits';
import type { CreditsConfig } from '@/lib/imageCredits';
import type { PageProps, Tool, ToolControlSpec, ToolUploadSpec, ToolWorkspace } from '@/types';

type FileSlot = {
    file: File | null;
    preview: string | null;
    duration: number | null;
};

type ToolCreationResponse = {
    id: number;
    status: string;
    progress_message?: string | null;
    progress_percent?: number | null;
    video_url?: string | null;
    preview_url?: string | null;
    error?: string | null;
    credits?: number | null;
};

type Props = {
    tool: Tool;
    workspace: ToolWorkspace;
    creditsConfig: CreditsConfig;
    tokenBalance: number;
    onResultVideo?: (url: string | null) => void;
};

const GROUP_BY_SLUG: Record<string, 'enhance' | 'transform' | 'edit' | 'create'> = {
    'video-upscaler': 'enhance',
    'video-enhancer': 'enhance',
    'denoise-video': 'enhance',
    'anime-video-enhancer': 'enhance',
    'lip-sync': 'transform',
    'face-swap-video': 'transform',
    'video-to-anime-ai': 'transform',
    'ai-dance-generator': 'transform',
    'video-background-remover': 'edit',
    'remove-subtitles-from-video': 'edit',
    'ai-video-extender': 'edit',
    'video-to-video': 'edit',
    'ai-video-editor': 'edit',
    'ai-video-filters': 'edit',
    'animate-a-picture': 'create',
    'motion-control': 'create',
    'ai-sound-effect-generator': 'create',
};

export default function ToolCreatePanel({ tool, workspace, creditsConfig, tokenBalance, onResultVideo }: Props) {
    const { t } = useTranslation('tools');
    const { props: pageProps } = usePage<PageProps>();
    const isGuest = pageProps.auth.user === null;
    const slug = workspace.tool_slug;
    const group = GROUP_BY_SLUG[slug] ?? 'enhance';
    const description = t(`detail.descriptions.${slug}`, { defaultValue: t('detail.subtitle') });

    const [values, setValues] = useState<Record<string, string | number | boolean>>(() =>
        initControlValues(workspace.controls),
    );
    const [slots, setSlots] = useState<Record<string, FileSlot>>(() => initSlots(workspace.uploads));
    const [settingsOpen, setSettingsOpen] = useState(true);
    const [loading, setLoading] = useState(false);
    const [progress, setProgress] = useState(0);
    const [statusMessage, setStatusMessage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [draggingKey, setDraggingKey] = useState<string | null>(null);
    const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

    useEffect(() => {
        setValues(initControlValues(workspace.controls));
        setSlots((prev) => {
            Object.values(prev).forEach((s) => {
                if (s.preview) URL.revokeObjectURL(s.preview);
            });
            return initSlots(workspace.uploads);
        });
        setError(null);
        setStatusMessage(null);
        onResultVideo?.(null);
    }, [workspace.tool_slug, workspace.model_id, onResultVideo]);

    useEffect(() => {
        return () => {
            Object.values(slots).forEach((s) => {
                if (s.preview) URL.revokeObjectURL(s.preview);
            });
            if (pollRef.current) clearInterval(pollRef.current);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const stopPolling = useCallback(() => {
        if (pollRef.current) {
            clearInterval(pollRef.current);
            pollRef.current = null;
        }
    }, []);

    const pollStatus = useCallback(
        (creationId: number) => {
            stopPolling();
            pollRef.current = setInterval(async () => {
                try {
                    const data = await apiGet<ToolCreationResponse>(`/tools/creations/${creationId}/status`);
                    if (typeof data.progress_percent === 'number') {
                        setProgress(Math.max(5, Math.min(95, data.progress_percent)));
                    }
                    if (data.progress_message) setStatusMessage(data.progress_message);

                    if (data.status === 'completed') {
                        stopPolling();
                        setProgress(100);
                        setLoading(false);
                        setStatusMessage(t('detail.done'));
                        if (data.video_url) onResultVideo?.(data.video_url);
                        return;
                    }
                    if (data.status === 'failed' || data.status === 'cancelled') {
                        stopPolling();
                        setLoading(false);
                        setProgress(0);
                        setError(data.error || t('detail.failed'));
                    }
                } catch (e) {
                    stopPolling();
                    setLoading(false);
                    setError(e instanceof ApiError ? e.message : t('detail.failed'));
                }
            }, 2500);
        },
        [onResultVideo, stopPolling, t],
    );

    const videoDuration = slots.video?.duration ?? null;
    const hasDurationControl = workspace.controls.some((c) => c.key === 'duration');
    const selectedDuration =
        typeof values.duration === 'string' || typeof values.duration === 'number'
            ? Number(values.duration)
            : null;

    const billDuration = useMemo(() => {
        // Tools with an explicit duration control (extender, image→video, motion…) bill on it.
        if (hasDurationControl && selectedDuration && selectedDuration > 0) {
            return selectedDuration;
        }
        if (videoDuration && videoDuration > 0) return videoDuration;
        return workspace.billing?.ref_duration_seconds ?? 5;
    }, [hasDurationControl, selectedDuration, videoDuration, workspace.billing?.ref_duration_seconds]);

    const creditEstimate = useMemo(() => {
        return estimateToolCredits(
            workspace.billing,
            {
                durationSeconds: billDuration,
                resolution:
                    typeof values.resolution === 'string'
                        ? values.resolution
                        : typeof values.scale === 'string'
                          ? undefined
                          : '1080p',
            },
            creditsConfig,
        );
    }, [workspace.billing, billDuration, values.resolution, values.scale, creditsConfig]);

    const requiredReady = workspace.uploads.every((u) => {
        if (!u.required) return true;
        return Boolean(slots[u.key]?.file);
    });

    const promptRequired = workspace.controls.some((c) => c.type === 'textarea' && c.required);
    const promptOk =
        !promptRequired ||
        (typeof values.prompt === 'string' && values.prompt.trim().length > 0);

    const canCreate =
        workspace.available &&
        requiredReady &&
        promptOk &&
        creditEstimate.credits > 0 &&
        !loading;

    const startCreate = useCallback(async () => {
        if (isGuest) {
            window.location.href = '/login';
            return;
        }
        if (!workspace.available || !workspace.model_id) return;

        setError(null);
        setStatusMessage(t('detail.uploading'));
        setLoading(true);
        setProgress(4);
        onResultVideo?.(null);

        try {
            const urls: Record<string, string> = {};
            for (const upload of workspace.uploads) {
                const file = slots[upload.key]?.file;
                if (!file) {
                    if (upload.required) throw new Error(t('detail.missingUpload'));
                    continue;
                }
                const formUpload = new FormData();
                formUpload.append('file', file);
                const uploaded = await apiPostForm<{ url: string }>('/lab/media/upload', formUpload);
                urls[`${upload.key}_url`] = uploaded.url;
                setProgress((p) => Math.min(30, p + 8));
            }

            setStatusMessage(t('detail.submitting'));
            const settings: Record<string, string | number | boolean> = {};
            for (const [k, v] of Object.entries(values)) {
                settings[k] = v;
            }

            const data = await apiPost<ToolCreationResponse>('/tools/generate', {
                model_id: workspace.model_id,
                tool_slug: workspace.tool_slug,
                duration_seconds: billDuration,
                settings,
                video_url: urls.video_url ?? null,
                image_url: urls.image_url ?? null,
                audio_url: urls.audio_url ?? null,
            });

            setProgress(35);
            setStatusMessage(data.progress_message || t('detail.processing'));
            pollStatus(data.id);
        } catch (e) {
            setLoading(false);
            setProgress(0);
            stopPolling();
            if (e instanceof ApiError && e.status === 401) {
                window.location.href = '/login';
                return;
            }
            const message =
                e instanceof ApiError
                    ? e.message
                    : e instanceof Error
                      ? e.message
                      : t('detail.failed');
            setError(message);
        }
    }, [
        billDuration,
        isGuest,
        onResultVideo,
        pollStatus,
        slots,
        stopPolling,
        t,
        values,
        workspace.available,
        workspace.model_id,
        workspace.tool_slug,
        workspace.uploads,
    ]);

    const setFile = async (key: string, file?: File) => {
        if (!file) return;
        setSlots((prev) => {
            const old = prev[key]?.preview;
            if (old) URL.revokeObjectURL(old);
            return {
                ...prev,
                [key]: {
                    file,
                    preview: URL.createObjectURL(file),
                    duration: prev[key]?.duration ?? null,
                },
            };
        });

        if (file.type.startsWith('video/')) {
            const duration = await readMediaDuration(file);
            setSlots((prev) => ({
                ...prev,
                [key]: {
                    ...(prev[key] ?? { file: null, preview: null, duration: null }),
                    file,
                    preview: prev[key]?.preview ?? URL.createObjectURL(file),
                    duration,
                },
            }));
        }
    };

    // Toggles are the only "advanced" controls; sliders/choices/prompts stay inline
    // so a tool's single Strength slider is never buried behind a collapse.
    const advancedControls = workspace.controls.filter((c) => c.type === 'toggle');
    const mainControls = workspace.controls.filter((c) => c.type !== 'toggle');

    return (
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
                                href="/tools"
                                className="inline-flex h-8 w-8 items-center justify-center rounded-full border border-white/10 bg-white/[0.04] text-white/60 transition hover:border-orange-400/35 hover:bg-orange-500/10 hover:text-orange-100"
                                aria-label={t('detail.backTools')}
                            >
                                <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.2">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" />
                                </svg>
                            </Link>
                            <span className="rounded-full border border-white/10 bg-white/[0.04] px-2.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-white/45">
                                {t(`groups.${group}.title`)}
                            </span>
                            {tool.badge && (
                                <span className="rounded-md bg-emerald-500/90 px-1.5 py-0.5 text-[9px] font-bold uppercase text-white">
                                    {tool.badge}
                                </span>
                            )}
                        </div>
                        <div>
                            <h1 className="font-[family-name:Outfit,sans-serif] text-[22px] font-semibold leading-tight tracking-tight text-white">
                                {tool.name}
                            </h1>
                            <p className="mt-1.5 text-[13px] leading-relaxed text-white/45">{description}</p>
                        </div>
                    </div>

                    {!workspace.available && (
                        <div className="rounded-xl border border-amber-400/25 bg-amber-500/10 px-3 py-2.5 text-[12px] text-amber-100/90">
                            {t('detail.unavailable')}
                        </div>
                    )}

                    {workspace.notices.includes('max_duration') && workspace.billing?.max_duration && (
                        <div className="rounded-xl border border-white/10 bg-white/[0.03] px-3 py-2 text-[11px] text-white/45">
                            {t('detail.maxDurationNotice', { seconds: workspace.billing.max_duration })}
                        </div>
                    )}

                    {workspace.uploads.map((upload) => (
                        <UploadSlot
                            key={upload.key}
                            spec={upload}
                            slot={slots[upload.key]}
                            dragging={draggingKey === upload.key}
                            label={t(`detail.${upload.label_key}`)}
                            hint={hintFor(upload, t)}
                            onDragState={(on) => setDraggingKey(on ? upload.key : null)}
                            onFile={(file) => void setFile(upload.key, file)}
                        />
                    ))}

                    {mainControls.map((control) => (
                        <ControlField
                            key={control.key}
                            control={control}
                            value={values[control.key]}
                            onChange={(v) => setValues((prev) => ({ ...prev, [control.key]: v }))}
                        />
                    ))}

                    {advancedControls.length > 0 && (
                        <section className="overflow-hidden rounded-2xl border border-white/[0.08] bg-gradient-to-b from-white/[0.04] to-transparent">
                            <button
                                type="button"
                                onClick={() => setSettingsOpen((v) => !v)}
                                className="flex w-full items-center justify-between px-3.5 py-3 text-left"
                            >
                                <span className="text-[12px] font-medium text-white/70">{t('detail.settings')}</span>
                                <svg
                                    className={`h-4 w-4 text-white/40 transition ${settingsOpen ? 'rotate-180' : ''}`}
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    strokeWidth="2"
                                >
                                    <path strokeLinecap="round" strokeLinejoin="round" d="m6 9 6 6 6-6" />
                                </svg>
                            </button>
                            <AnimatePresence initial={false}>
                                {settingsOpen && (
                                    <motion.div
                                        initial={{ height: 0, opacity: 0 }}
                                        animate={{ height: 'auto', opacity: 1 }}
                                        exit={{ height: 0, opacity: 0 }}
                                        transition={{ duration: 0.22 }}
                                        className="overflow-hidden"
                                    >
                                        <div className="space-y-1 border-t border-white/[0.05] px-3.5 pb-3.5 pt-1">
                                            {advancedControls.map((control) => (
                                                <ControlField
                                                    key={control.key}
                                                    control={control}
                                                    value={values[control.key]}
                                                    onChange={(v) =>
                                                        setValues((prev) => ({ ...prev, [control.key]: v }))
                                                    }
                                                />
                                            ))}
                                        </div>
                                    </motion.div>
                                )}
                            </AnimatePresence>
                        </section>
                    )}
                </div>

                <div className="relative shrink-0 border-t border-white/[0.07] bg-[#0a0a0f]/95 p-3 backdrop-blur-xl">
                    <div className="mb-2.5 flex items-center justify-between gap-2">
                        <div className="flex flex-wrap items-center gap-1.5">
                            <span className="rounded-lg border border-white/10 bg-white/[0.04] px-2 py-1 text-[11px] text-white/65">
                                ~{Math.round(billDuration)}s
                            </span>
                            {typeof values.scale === 'string' && (
                                <span className="rounded-lg border border-white/10 bg-white/[0.04] px-2 py-1 text-[11px] text-white/65">
                                    {values.scale}
                                </span>
                            )}
                            {typeof values.resolution === 'string' && (
                                <span className="rounded-lg border border-white/10 bg-white/[0.04] px-2 py-1 text-[11px] text-white/65">
                                    {values.resolution}
                                </span>
                            )}
                        </div>
                        <div className="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/[0.04] px-2 py-1 text-[11px] text-white/65">
                            <svg className="h-3 w-3 text-[#FF8A65]" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2l2.4 7.2L22 12l-7.6 2.8L12 22l-2.4-7.2L2 12l7.6-2.8L12 2z" />
                            </svg>
                            <span>{creditEstimate.credits || '—'}</span>
                            <span className="text-white/35">{t('detail.credits')}</span>
                        </div>
                    </div>

                    {error && (
                        <p className="mb-2 text-[11px] leading-snug text-red-300/90">{error}</p>
                    )}
                    {statusMessage && loading && !error && (
                        <p className="mb-2 text-[11px] text-white/45">{statusMessage}</p>
                    )}
                    {isGuest && (
                        <p className="mb-2 text-[11px] text-white/40">{t('detail.signInToCreate')}</p>
                    )}
                    {tokenBalance > 0 && creditEstimate.credits > tokenBalance && !isGuest && (
                        <p className="mb-2 text-[11px] text-amber-200/80">{t('detail.insufficientCredits')}</p>
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
                        {!loading && (
                            <span className="pointer-events-none absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/35 to-transparent transition-transform duration-[900ms] ease-out group-hover:translate-x-full" />
                        )}
                        {loading ? (
                            <>
                                <span className="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white" />
                                <span>{statusMessage || t('detail.processing')}</span>
                            </>
                        ) : (
                            <>
                                <svg className="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z" />
                                </svg>
                                <span>
                                    {isGuest
                                        ? t('detail.signInToCreate')
                                        : `${t('detail.create')} · ${creditEstimate.credits} ${t('detail.credits')}`}
                                </span>
                            </>
                        )}
                        {loading && (
                            <div className="absolute inset-x-0 bottom-0 h-1 overflow-hidden bg-black/20">
                                <motion.div
                                    className="h-full bg-white/80"
                                    animate={{ width: `${progress}%` }}
                                    transition={{ duration: 0.3 }}
                                />
                            </div>
                        )}
                    </motion.button>
                </div>
            </div>
        </motion.aside>
    );
}

function initControlValues(controls: ToolControlSpec[]): Record<string, string | number | boolean> {
    const out: Record<string, string | number | boolean> = {};
    for (const c of controls) {
        if (c.default !== undefined) out[c.key] = c.default;
        else if (c.type === 'toggle') out[c.key] = false;
        else if (c.type === 'slider') out[c.key] = c.min ?? 0;
        else out[c.key] = '';
    }
    return out;
}

function initSlots(uploads: ToolUploadSpec[]): Record<string, FileSlot> {
    const out: Record<string, FileSlot> = {};
    for (const u of uploads) {
        out[u.key] = { file: null, preview: null, duration: null };
    }
    return out;
}

function hintFor(upload: ToolUploadSpec, t: (k: string) => string): string {
    if (upload.key === 'audio') return t('detail.uploadAudioTypes');
    if (upload.key === 'image') return t('detail.uploadImageTypes');
    return t('detail.uploadVideoTypes');
}

function readMediaDuration(file: File): Promise<number | null> {
    return new Promise((resolve) => {
        const url = URL.createObjectURL(file);
        const el = document.createElement('video');
        el.preload = 'metadata';
        el.onloadedmetadata = () => {
            const d = Number.isFinite(el.duration) ? el.duration : null;
            URL.revokeObjectURL(url);
            resolve(d);
        };
        el.onerror = () => {
            URL.revokeObjectURL(url);
            resolve(null);
        };
        el.src = url;
    });
}

function UploadSlot({
    spec,
    slot,
    dragging,
    label,
    hint,
    onDragState,
    onFile,
}: {
    spec: ToolUploadSpec;
    slot?: FileSlot;
    dragging: boolean;
    label: string;
    hint: string;
    onDragState: (on: boolean) => void;
    onFile: (file?: File) => void;
}) {
    const { t } = useTranslation('tools');
    const inputRef = useRef<HTMLInputElement>(null);
    const isImage = spec.accept.startsWith('image');
    const isAudio = spec.accept.startsWith('audio');

    const onDrop = (e: DragEvent) => {
        e.preventDefault();
        onDragState(false);
        onFile(e.dataTransfer.files[0]);
    };

    return (
        <section className="space-y-2">
            <p className="text-[11px] font-medium uppercase tracking-[0.12em] text-white/40">{label}</p>
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
                            <img src={slot.preview} alt="" className="mx-auto max-h-32 rounded-xl object-contain" />
                        ) : (
                            <video src={slot.preview} className="mx-auto max-h-32 rounded-xl object-contain" muted playsInline />
                        )}
                        <p className="mt-2 truncate text-center text-[12px] font-medium text-zinc-200">{slot.file?.name}</p>
                        {slot.duration ? (
                            <p className="text-center text-[11px] text-white/35">{slot.duration.toFixed(1)}s</p>
                        ) : (
                            <p className="text-center text-[11px] text-white/35">{t('detail.changeFile')}</p>
                        )}
                    </div>
                ) : slot?.file && isAudio ? (
                    <div className="text-center">
                        <p className="text-[13px] font-medium text-zinc-100">{slot.file.name}</p>
                        <p className="mt-1 text-[11px] text-white/35">{t('detail.changeFile')}</p>
                    </div>
                ) : (
                    <>
                        <div className="mb-3 flex h-11 w-11 items-center justify-center rounded-2xl border border-white/10 bg-white/[0.05]">
                            <svg className="h-5 w-5 text-[#FF8A65]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                            </svg>
                        </div>
                        <p className="text-[13px] font-medium text-zinc-100">{t('detail.uploadHint')}</p>
                        <p className="mt-1 text-[11px] text-white/35">{hint}</p>
                    </>
                )}
                <input
                    ref={inputRef}
                    type="file"
                    accept={spec.accept}
                    className="hidden"
                    onChange={(e) => onFile(e.target.files?.[0])}
                />
            </div>
        </section>
    );
}

function ControlField({
    control,
    value,
    onChange,
}: {
    control: ToolControlSpec;
    value: string | number | boolean | undefined;
    onChange: (v: string | number | boolean) => void;
}) {
    const { t } = useTranslation('tools');
    const label = control.label_key ? t(`detail.${control.label_key}`) : control.key;

    if (control.type === 'choice') {
        const options = control.options ?? [];
        return (
            <section className="space-y-2">
                <p className="text-[11px] font-medium uppercase tracking-[0.12em] text-white/40">{label}</p>
                <div className={`grid gap-2 ${options.length <= 3 ? 'grid-cols-3' : options.length === 4 ? 'grid-cols-4' : 'grid-cols-3'}`}>
                    {options.map((option) => {
                        const active = String(value) === String(option);
                        const text = control.option_label_prefix
                            ? t(`detail.${control.option_label_prefix}.${option}`, { defaultValue: option })
                            : control.suffix
                              ? `${option}${control.suffix}`
                              : option;
                        return (
                            <button
                                key={option}
                                type="button"
                                onClick={() => onChange(option)}
                                className={`rounded-xl border px-2 py-2.5 text-[12px] font-medium transition ${
                                    active
                                        ? 'border-[#FF5733]/50 bg-gradient-to-b from-[#FF5733]/25 to-[#FF5733]/10 text-white'
                                        : 'border-white/[0.07] bg-white/[0.03] text-white/55 hover:border-white/15 hover:text-white/80'
                                }`}
                            >
                                {text}
                            </button>
                        );
                    })}
                </div>
            </section>
        );
    }

    if (control.type === 'textarea') {
        return (
            <section className="space-y-2">
                <p className="text-[11px] font-medium uppercase tracking-[0.12em] text-white/40">{label}</p>
                <textarea
                    value={typeof value === 'string' ? value : ''}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder={
                        control.placeholder_key ? t(`detail.${control.placeholder_key}`) : undefined
                    }
                    rows={4}
                    className="w-full resize-none rounded-xl border border-white/10 bg-black/30 px-3.5 py-3 text-[14px] leading-6 text-white outline-none placeholder:text-white/30 focus:border-orange-400/40 focus:ring-2 focus:ring-orange-500/15"
                />
            </section>
        );
    }

    if (control.type === 'slider') {
        const num = typeof value === 'number' ? value : Number(control.default ?? 0);
        return (
            <label className="flex flex-col gap-2 rounded-xl px-1 py-2.5">
                <div className="flex items-center justify-between">
                    <span className="text-[13px] font-medium text-white/85">{label}</span>
                    <span className="text-[11px] text-white/40">{num.toFixed(2)}</span>
                </div>
                <input
                    type="range"
                    min={control.min ?? 0}
                    max={control.max ?? 1}
                    step={control.step ?? 0.05}
                    value={num}
                    onChange={(e) => onChange(Number(e.target.value))}
                    className="w-full cursor-pointer accent-[#FF5733]"
                />
            </label>
        );
    }

    // toggle
    const checked = Boolean(value);
    return (
        <label className="flex cursor-pointer items-center justify-between gap-4 rounded-xl px-1 py-2.5 transition hover:bg-white/[0.02]">
            <div className="min-w-0 flex-1">
                <p className="text-[13px] font-medium text-white/85">{label}</p>
                {control.desc_key && (
                    <p className="text-[11px] text-white/35">{t(`detail.${control.desc_key}`)}</p>
                )}
            </div>
            <button
                type="button"
                role="switch"
                dir="ltr"
                aria-checked={checked}
                onClick={() => onChange(!checked)}
                className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-200 ${
                    checked ? 'bg-[#FF5733]' : 'bg-white/15'
                }`}
            >
                <motion.span
                    layout={false}
                    className="absolute top-0.5 left-0.5 inline-block size-5 rounded-full bg-white shadow"
                    animate={{ x: checked ? 20 : 0 }}
                    transition={{ type: 'spring', stiffness: 500, damping: 35 }}
                />
            </button>
        </label>
    );
}
