import { Head, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import AppLayout from '@/Layouts/AppLayout';
import ImageLabLibrary, { type LabImage } from '@/Components/ImageLabLibrary';
import ToolCreatePanel, { type ToolCreationStatus } from '@/Components/ToolCreatePanel';
import { apiGet } from '@/lib/api';
import type { CreditsConfig } from '@/lib/imageCredits';
import type { PageProps, Tool, ToolWorkspace } from '@/types';

type Props = {
    tool: Tool;
    workspace: ToolWorkspace;
    creditsConfig: CreditsConfig;
    tokenBalance: number;
};

type ApiToolLibraryItem = {
    id: string;
    creation_id?: number;
    batch_id?: string | null;
    batch_index?: number | null;
    prompt: string;
    src: string;
    favorite: boolean;
    created_at: string | null;
    started_at?: number | null;
    aspect?: string;
    resolution?: string | null;
    duration?: number | string | null;
    method?: string;
    model?: string | null;
    status?: LabImage['status'];
    progress?: string | null;
    queue_position?: number | null;
    progress_percent?: number | null;
    error?: string | null;
    video_url?: string | null;
};

function mapToolLibraryItem(item: ApiToolLibraryItem): LabImage {
    const createdAt = item.created_at ? new Date(item.created_at).getTime() : Date.now();

    return {
        id: item.id,
        creationId: item.creation_id,
        batchId: item.batch_id ?? undefined,
        batchIndex: item.batch_index ?? undefined,
        prompt: item.prompt,
        src: item.src,
        favorite: item.favorite,
        createdAt,
        startedAt: item.started_at ?? createdAt,
        aspect: item.aspect,
        resolution: item.resolution ?? undefined,
        duration: item.duration ?? undefined,
        method: (item.method as LabImage['method']) ?? 'text-to-video',
        modelName: item.model ?? undefined,
        status: item.status,
        progress: item.progress,
        queuePosition: item.queue_position ?? null,
        progressPercent: item.progress_percent ?? null,
        error: item.error,
        videoUrl: item.video_url ?? undefined,
    };
}

function mergeToolCreationStatus(prev: LabImage[], data: ToolCreationStatus): LabImage[] {
    const creationId = data.id;
    const existing = prev.find((i) => i.creationId === creationId);
    if (!existing) return prev;

    if (data.status === 'completed') {
        const videoUrl = data.video_url || data.preview_url || null;
        const rest = prev.filter((i) => i.creationId !== creationId);
        if (!videoUrl) {
            return [
                {
                    ...existing,
                    status: 'failed',
                    completing: false,
                    error: data.error || 'Generation finished without a video.',
                    progress: null,
                    progressPercent: null,
                },
                ...rest,
            ];
        }
        return [
            {
                ...existing,
                src: data.preview_url || videoUrl,
                videoUrl,
                status: 'completed',
                completing: false,
                progress: null,
                progressPercent: 100,
                error: null,
            },
            ...rest,
        ];
    }

    if (data.status === 'failed' || data.status === 'cancelled') {
        return prev.map((i) =>
            i.creationId === creationId
                ? {
                      ...i,
                      status: 'failed' as const,
                      completing: false,
                      error: data.error || 'Generation failed.',
                      progress: data.progress_message ?? 'Failed',
                      progressPercent: null,
                  }
                : i,
        );
    }

    return prev.map((i) =>
        i.creationId === creationId
            ? {
                  ...i,
                  status: data.status as LabImage['status'],
                  progress: data.progress_message ?? i.progress,
                  progressPercent: data.progress_percent ?? i.progressPercent ?? null,
              }
            : i,
    );
}

export default function ToolDetail({ tool, workspace, creditsConfig, tokenBalance }: Props) {
    const { t } = useTranslation('tools');
    const { props: pageProps } = usePage<PageProps>();
    const isGuest = pageProps.auth.user === null;

    const [images, setImages] = useState<LabImage[]>([]);
    const [libraryReady, setLibraryReady] = useState(false);
    const [generating, setGenerating] = useState(false);

    useEffect(() => {
        setImages([]);
        setLibraryReady(false);
        setGenerating(false);

        if (isGuest) {
            setLibraryReady(true);
            return;
        }

        let cancelled = false;
        void (async () => {
            try {
                const data = await apiGet<{ images: ApiToolLibraryItem[] }>(
                    `/tools/creations?tool_slug=${encodeURIComponent(workspace.tool_slug)}`,
                );
                if (cancelled) return;
                setImages((data.images ?? []).map(mapToolLibraryItem));
            } catch {
                if (!cancelled) setImages([]);
            } finally {
                if (!cancelled) setLibraryReady(true);
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [isGuest, workspace.tool_slug]);

    const onCreationStarted = useCallback((data: ToolCreationStatus) => {
        const now = Date.now();
        const placeholder: LabImage = {
            id: `tool-${data.id}`,
            creationId: data.id,
            batchId: `tool-creation-${data.id}`,
            batchIndex: 0,
            prompt: tool.name,
            src: '',
            favorite: false,
            createdAt: now,
            startedAt: now,
            aspect: '16:9',
            method: 'text-to-video',
            status: (data.status as LabImage['status']) || 'pending',
            progress: data.progress_message ?? null,
            progressPercent: data.progress_percent ?? 5,
            error: null,
        };
        setImages((prev) => {
            if (prev.some((i) => i.creationId === data.id)) return prev;
            return [placeholder, ...prev];
        });
        setGenerating(true);
    }, [tool.name]);

    const onCreationUpdated = useCallback((data: ToolCreationStatus) => {
        setImages((prev) => mergeToolCreationStatus(prev, data));
        if (data.status === 'completed' || data.status === 'failed' || data.status === 'cancelled') {
            setGenerating(false);
        }
    }, []);

    const toggleFavorite = useCallback((id: string) => {
        setImages((prev) => prev.map((img) => (img.id === id ? { ...img, favorite: !img.favorite } : img)));
    }, []);

    const deleteImages = useCallback((ids: string[]) => {
        setImages((prev) => prev.filter((img) => !ids.includes(img.id)));
    }, []);

    const onRevealComplete = useCallback((id: string) => {
        setImages((prev) => prev.map((img) => (img.id === id ? { ...img, completing: false } : img)));
    }, []);

    const showLibrary = images.length > 0;

    return (
        <AppLayout flush>
            <Head title={tool.name} />
            <div className="flex w-full min-w-0 flex-col md:h-full md:min-h-0 [&_a]:cursor-pointer [&_button]:cursor-pointer [&_button:disabled]:cursor-not-allowed [&_label]:cursor-pointer">
                <div className="flex flex-col rounded-xl bg-[#070708] md:min-h-0 md:flex-1 md:overflow-hidden">
                    <div className="flex flex-col md:min-h-0 md:flex-1 md:overflow-hidden md:flex-row">
                        <ToolCreatePanel
                            tool={tool}
                            workspace={workspace}
                            creditsConfig={creditsConfig}
                            tokenBalance={tokenBalance}
                            onCreationStarted={onCreationStarted}
                            onCreationUpdated={onCreationUpdated}
                        />

                        <motion.div
                            initial={{ opacity: 0 }}
                            animate={{ opacity: 1 }}
                            transition={{ delay: 0.08, duration: 0.4 }}
                            className="relative flex min-h-[50vh] min-w-0 w-full flex-col md:min-h-0 md:flex-1 md:overflow-hidden"
                        >
                            {showLibrary ? (
                                <ImageLabLibrary
                                    images={images}
                                    generating={generating}
                                    hideAlbums
                                    hideMethodFilters
                                    hidePrompt
                                    onToggleFavorite={toggleFavorite}
                                    onDelete={deleteImages}
                                    onRevealComplete={onRevealComplete}
                                />
                            ) : libraryReady ? (
                                <>
                                    <div aria-hidden className="pointer-events-none absolute inset-0">
                                        <div className="absolute left-1/2 top-1/3 h-80 w-80 -translate-x-1/2 rounded-full bg-[#FF5733]/12 blur-[120px]" />
                                        <div className="absolute inset-0 bg-[radial-gradient(circle_at_50%_35%,rgba(255,255,255,0.035),transparent_55%)]" />
                                    </div>

                                    <div className="relative z-10 flex items-center justify-between gap-3 border-b border-white/[0.05] px-4 py-3 md:px-5">
                                        <div>
                                            <p className="text-[11px] font-medium uppercase tracking-[0.14em] text-white/35">
                                                {t('detail.preview')}
                                            </p>
                                            <p className="mt-0.5 text-[13px] text-white/70">{t('detail.previewHint')}</p>
                                        </div>
                                        <div className="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/[0.04] px-2.5 py-1 text-[11px] text-white/50">
                                            <span className="h-1.5 w-1.5 rounded-full bg-emerald-400 shadow-[0_0_8px_rgba(52,211,153,0.8)]" />
                                            {t('detail.demoLive')}
                                        </div>
                                    </div>

                                    <div className="relative z-10 flex min-h-0 flex-1 items-center justify-center p-4 md:p-6">
                                        <motion.div
                                            key={tool.video}
                                            initial={{ opacity: 0, y: 16, scale: 0.98 }}
                                            animate={{ opacity: 1, y: 0, scale: 1 }}
                                            transition={{ duration: 0.45, ease: [0.22, 1, 0.36, 1] }}
                                            className="relative w-full max-w-4xl"
                                        >
                                            <div className="absolute -inset-px rounded-[1.35rem] bg-gradient-to-b from-white/15 via-white/5 to-transparent opacity-70" />
                                            <div className="relative overflow-hidden rounded-[1.25rem] border border-white/[0.08] bg-black/50 shadow-[0_40px_100px_-40px_rgba(0,0,0,0.9)]">
                                                <div className="aspect-video w-full">
                                                    <video
                                                        src={tool.video}
                                                        poster={tool.poster}
                                                        className="h-full w-full object-cover"
                                                        playsInline
                                                        loop
                                                        muted
                                                        autoPlay
                                                        controls
                                                        preload="metadata"
                                                    />
                                                </div>
                                                <div className="pointer-events-none absolute inset-x-0 top-0 h-24 bg-gradient-to-b from-black/50 to-transparent" />
                                                <div className="pointer-events-none absolute inset-x-0 bottom-0 h-28 bg-gradient-to-t from-black/70 via-black/25 to-transparent" />
                                                <div className="absolute start-4 top-4 rounded-lg border border-white/10 bg-black/45 px-2.5 py-1 text-[11px] font-medium text-white/80 backdrop-blur-md">
                                                    {tool.name}
                                                </div>
                                            </div>
                                            <p className="mt-4 text-center text-[12px] text-white/35">{t('detail.previewFooter')}</p>
                                        </motion.div>
                                    </div>
                                </>
                            ) : (
                                <div className="min-h-0 flex-1 bg-[#08080d]" />
                            )}
                        </motion.div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
