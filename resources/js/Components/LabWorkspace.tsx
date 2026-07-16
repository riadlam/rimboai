import ImageLabCreateForm, { type ImageGenerateOptions } from '@/Components/ImageLabCreateForm';
import ImageLabLibrary, { type LabImage } from '@/Components/ImageLabLibrary';
import SoundLabCreateForm from '@/Components/SoundLabCreateForm';
import SoundLabLibrary, { type LabTrack } from '@/Components/SoundLabLibrary';
import VideoLabCreateForm, { type VideoGenerateOptions } from '@/Components/VideoLabCreateForm';
import VoiceLabCreateForm, { type VoiceGenerateOptions } from '@/Components/VoiceLabCreateForm';
import VoiceLabLibrary, { type LabVoice } from '@/Components/VoiceLabLibrary';
import { ApiError, apiGet, apiPost, apiPostForm } from '@/lib/api';
import type { CreditsConfig } from '@/lib/imageCredits';
import { hasMeaningfulPrompt } from '@/lib/promptText';
import {
    buildReuseSettingsDraft,
    buildUseLastFrameDraft,
    buildUseResultDraft,
    captureVideoLastFrameFile,
    consumeLabReuseDraft,
    type LabReuseDraft,
} from '@/lib/labReuse';
import type { Brand, PageProps } from '@/types';
import Button from '@/Components/Button';
import { LabToastProvider, useLabToast } from '@/Components/LabToast';
import { Head, Link, usePage } from '@inertiajs/react';
import { getEcho } from '@/lib/echo';
import { motion } from 'framer-motion';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

type Props = {
    type?: string;
    title: string;
    backHref?: string;
    brands?: Brand[];
    placeholder?: string;
    creditsConfig?: CreditsConfig;
};

type CreationStatus = 'pending' | 'queued' | 'in_progress' | 'completed' | 'failed' | 'cancelled';

type CreationResponse = {
    id: number;
    status: CreationStatus;
    queue_position: number | null;
    progress_message: string | null;
    progress_percent?: number | null;
    prompt: string;
    lyrics?: string | null;
    title?: string | null;
    model_name: string | null;
    mode?: string | null;
    images?: string[];
    video_url?: string | null;
    thumbnail_url?: string | null;
    audio_url?: string | null;
    cover_url?: string | null;
    instrumental?: boolean | null;
    duration?: string | null;
    voice?: string | null;
    preview_url: string | null;
    error: string | null;
    created_at: string | null;
    credits?: number | null;
    token_balance?: number;
};

type ApiImageItem = {
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
    audio?: boolean | null;
    quantity?: number | null;
    image_mode?: 'create' | 'variations' | null;
    input_assets?: {
        url: string;
        kind: 'image' | 'video' | 'audio';
        name?: string | null;
        fallback_urls?: string[] | null;
    }[] | null;
    method?: string;
    model?: string | null;
    status?: CreationStatus;
    progress?: string | null;
    queue_position?: number | null;
    progress_percent?: number | null;
    error?: string | null;
    video_url?: string | null;
};

type ApiTrackItem = {
    id: string;
    creation_id?: number;
    title: string;
    style: string;
    lyrics?: string | null;
    cover: string;
    favorite: boolean;
    created_at: string | null;
    instrumental?: boolean;
    model?: string | null;
    duration?: string | null;
    audio_url?: string | null;
    status?: CreationStatus;
    progress?: string | null;
    queue_position?: number | null;
    progress_percent?: number | null;
    error?: string | null;
};

type ApiVoiceItem = {
    id: string;
    creation_id?: number;
    title: string;
    text: string;
    voice: string;
    favorite: boolean;
    created_at: string | null;
    model?: string | null;
    duration?: string | null;
    gradient?: string | null;
    audio_url?: string | null;
    status?: CreationStatus;
    progress?: string | null;
    queue_position?: number | null;
    progress_percent?: number | null;
    error?: string | null;
};

const ACTIVE_CREATION_POLL_MS = 6000;
// Max active cards synced per tick (round-robin). Keeps fal traffic flat as users scale.
const ACTIVE_CREATION_BATCH = 3;

function patchActiveCreationFields<
    T extends { progress?: string | null; queuePosition?: number | null; progressPercent?: number | null },
>(item: T, data: CreationResponse): T & { status: CreationStatus } {
    return {
        ...item,
        status: data.status,
        progress: data.progress_message ?? item.progress,
        queuePosition: data.queue_position ?? item.queuePosition ?? null,
        progressPercent: data.progress_percent ?? item.progressPercent ?? null,
    };
}

function mergeImageVideoCreationState(
    prev: LabImage[],
    creationId: number,
    creation: CreationResponse,
    kind: 'image' | 'video',
): LabImage[] {
    if (creation.status === 'completed') {
        if (kind === 'image') {
            const urls = creation.images ?? [];
            const batch = prev.filter((i) => i.creationId === creationId);
            if (batch.length === 0) return prev;
            const rest = prev.filter((i) => i.creationId !== creationId);
            const cards = batch.map((slot, idx) => {
                const url = urls[idx];
                if (!url) {
                    return { ...slot, status: 'failed' as const, completing: false, error: 'Output missing.' };
                }
                return {
                    ...slot,
                    src: url,
                    status: 'completed' as const,
                    completing: false,
                    progress: null,
                    progressPercent: 100,
                    error: null,
                    modelName: creation.model_name ?? slot.modelName,
                };
            });
            return [...cards, ...rest];
        }

        const videoUrl = creation.video_url || creation.preview_url;
        const existing = prev.find((i) => i.creationId === creationId);
        if (!existing) return prev;
        const rest = prev.filter((i) => i.creationId !== creationId);
        if (!videoUrl) {
            return [{ ...existing, status: 'failed' as const, error: 'Generation finished without a video.' }, ...rest];
        }
        return [
            {
                ...existing,
                id: `video-${creationId}`,
                src: creation.thumbnail_url || videoUrl,
                videoUrl,
                status: 'completed' as const,
                completing: false,
                progress: null,
                progressPercent: 100,
                error: null,
                modelName: creation.model_name ?? existing.modelName,
            },
            ...rest,
        ];
    }

    if (creation.status === 'failed' || creation.status === 'cancelled') {
        return prev.map((i) =>
            i.creationId === creationId
                ? { ...i, status: 'failed' as const, progress: null, error: creation.error ?? 'Generation failed.' }
                : i,
        );
    }

    return prev.map((i) => (i.creationId === creationId ? patchActiveCreationFields(i, creation) : i));
}

function mergeMusicCreationState(prev: LabTrack[], creationId: number, creation: CreationResponse): LabTrack[] {
    if (creation.status === 'completed') {
        const audioUrl = creation.audio_url || creation.preview_url;
        return prev.map((track) =>
            track.creationId === creationId
                ? {
                      ...track,
                      status: 'completed' as const,
                      audioUrl: audioUrl ?? track.audioUrl,
                      cover: creation.cover_url || track.cover,
                      progress: null,
                      progressPercent: 100,
                      error: null,
                      completing: false,
                  }
                : track,
        );
    }

    if (creation.status === 'failed' || creation.status === 'cancelled') {
        return prev.map((track) =>
            track.creationId === creationId
                ? { ...track, status: 'failed', error: creation.error || 'Generation failed.', progress: creation.progress_message ?? 'Failed' }
                : track,
        );
    }

    return prev.map((track) => (track.creationId === creationId ? patchActiveCreationFields(track, creation) : track));
}

function mergeVoiceCreationState(prev: LabVoice[], creationId: number, creation: CreationResponse): LabVoice[] {
    if (creation.status === 'completed') {
        const audioUrl = creation.audio_url || creation.preview_url;
        return prev.map((v) =>
            v.creationId === creationId
                ? {
                      ...v,
                      status: 'completed' as const,
                      audioUrl: audioUrl ?? v.audioUrl,
                      progress: undefined,
                      progressPercent: 100,
                      error: undefined,
                  }
                : v,
        );
    }

    if (creation.status === 'failed' || creation.status === 'cancelled') {
        return prev.map((v) =>
            v.creationId === creationId
                ? { ...v, status: 'failed', error: creation.error || 'Generation failed.', progress: creation.progress_message ?? 'Failed' }
                : v,
        );
    }

    return prev.map((v) => (v.creationId === creationId ? patchActiveCreationFields(v, creation) : v));
}

const VOICE_GRADIENTS = [
    'linear-gradient(135deg, rgb(139, 92, 246) 0%, rgb(236, 72, 153) 50%, rgb(249, 115, 22) 100%)',
    'linear-gradient(135deg, #f472b6 0%, #fb7185 45%, #fb923c 100%)',
    'linear-gradient(135deg, #0ea5e9 0%, #6366f1 55%, #a855f7 100%)',
];

function mapApiImage(item: ApiImageItem): LabImage {
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
        audio: item.audio ?? undefined,
        quantity: item.quantity ?? undefined,
        imageMode: item.image_mode ?? undefined,
        inputAssets: (item.input_assets ?? []).map((a) => ({
            url: a.url,
            kind: a.kind,
            name: a.name ?? null,
            fallbackUrls: a.fallback_urls ?? null,
        })),
        method: (item.method as LabImage['method']) ?? 'text-to-image',
        modelName: item.model ?? undefined,
        status: item.status,
        progress: item.progress,
        queuePosition: item.queue_position ?? null,
        progressPercent: item.progress_percent ?? null,
        error: item.error,
        videoUrl: item.video_url ?? undefined,
    };
}

function mapApiTrack(item: ApiTrackItem): LabTrack {
    return {
        id: item.id,
        creationId: item.creation_id,
        title: item.title,
        style: item.style,
        lyrics: item.lyrics ?? undefined,
        cover: item.cover,
        favorite: item.favorite,
        createdAt: item.created_at ? new Date(item.created_at).getTime() : Date.now(),
        instrumental: item.instrumental ?? false,
        model: item.model ?? undefined,
        duration: item.duration ?? undefined,
        audioUrl: item.audio_url ?? undefined,
        status: item.status,
        progress: item.progress ?? undefined,
        queuePosition: item.queue_position ?? null,
        progressPercent: item.progress_percent ?? null,
        error: item.error ?? undefined,
    };
}

function mapApiVoice(item: ApiVoiceItem, index: number): LabVoice {
    return {
        id: item.id,
        creationId: item.creation_id,
        title: item.title,
        text: item.text,
        voice: item.voice,
        favorite: item.favorite,
        createdAt: item.created_at ? new Date(item.created_at).getTime() : Date.now(),
        model: item.model ?? undefined,
        duration: item.duration ?? undefined,
        gradient: item.gradient ?? VOICE_GRADIENTS[index % VOICE_GRADIENTS.length],
        audioUrl: item.audio_url ?? undefined,
        status: item.status,
        progress: item.progress ?? undefined,
        queuePosition: item.queue_position ?? null,
        progressPercent: item.progress_percent ?? null,
        error: item.error ?? undefined,
    };
}

function isActiveStatus(status?: CreationStatus) {
    return status !== undefined && status !== 'completed' && status !== 'failed' && status !== 'cancelled';
}

export default function LabWorkspace(props: Props) {
    return (
        <LabToastProvider>
            <LabWorkspaceInner {...props} />
        </LabToastProvider>
    );
}

function LabWorkspaceInner({
    type = 'text-to-video',
    title,
    brands = [],
    placeholder = 'Enter your prompt…',
    creditsConfig,
}: Props) {
    const { t: tLab } = useTranslation('lab');
    const { pushError } = useLabToast();
    const { props: pageProps } = usePage<PageProps>();
    const isGuest = pageProps.auth.user === null;
    const [prompt, setPrompt] = useState('');
    const [tokenBalance, setTokenBalance] = useState(() => Math.max(0, pageProps.auth.user?.tokens ?? 0));
    const [loading, setLoading] = useState(false);
    const isImageLab = type === 'text-to-image';
    const isVideoLab = type === 'text-to-video';
    const isMusicLab = type === 'text-to-music' || type === 'text-to-sound';
    const isVoiceLab = type === 'text-to-voice';
    const syncTokenBalance = useCallback((balance?: number) => {
        if (typeof balance !== 'number' || !Number.isFinite(balance)) return;
        const normalized = Math.max(0, Math.floor(balance));
        setTokenBalance(normalized);
        window.dispatchEvent(new CustomEvent('tokens:updated', { detail: { balance: normalized } }));
    }, []);

    const syncTokenBalanceFromError = useCallback(
        (error: unknown) => {
            if (!(error instanceof ApiError)) return;
            const value = error.payload?.token_balance ?? error.payload?.available_tokens;
            if (typeof value === 'number') syncTokenBalance(value);
        },
        [syncTokenBalance],
    );

    const usesStudioLab = isImageLab || isVideoLab || isMusicLab || isVoiceLab;
    const [images, setImages] = useState<LabImage[]>([]);
    const [tracks, setTracks] = useState<LabTrack[]>([]);
    const [voices, setVoices] = useState<LabVoice[]>([]);
    const imagesRef = useRef(images);
    const tracksRef = useRef(tracks);
    const voicesRef = useRef(voices);
    imagesRef.current = images;
    tracksRef.current = tracks;
    voicesRef.current = voices;
    const [reuseDraft, setReuseDraft] = useState<LabReuseDraft | null>(null);
    const model = brands[0]?.models[0]?.name || 'Auto';

    const imageGenerating = isImageLab && images.some((i) => isActiveStatus(i.status));
    const videoGenerating = isVideoLab && images.some((i) => isActiveStatus(i.status));
    const musicGenerating = isMusicLab && tracks.some((t) => isActiveStatus(t.status));
    const voiceGenerating = isVoiceLab && voices.some((v) => isActiveStatus(v.status));

    useEffect(() => {
        const expectedLab = isVideoLab ? 'video' : isImageLab ? 'image' : isMusicLab ? 'music' : undefined;
        if (!expectedLab) return;
        const pending = consumeLabReuseDraft(expectedLab);
        if (pending) setReuseDraft(pending);
    }, [isImageLab, isVideoLab, isMusicLab]);

    const toReuseSource = useCallback((img: LabImage) => {
        return {
            id: img.id,
            prompt: img.prompt,
            src: img.src,
            videoUrl: img.videoUrl,
            method: img.method,
            modelName: img.modelName,
            aspect: img.aspect,
            resolution: img.resolution,
            duration: img.duration,
            audio: img.audio,
            quantity: img.quantity,
            imageMode: img.imageMode,
            inputAssets: img.inputAssets,
        };
    }, []);

    const handleReuseSettings = useCallback(
        (img: LabImage) => {
            setReuseDraft(buildReuseSettingsDraft(toReuseSource(img)));
        },
        [toReuseSource],
    );

    const handleUseResult = useCallback(
        (img: LabImage) => {
            const draft = buildUseResultDraft(toReuseSource(img));
            // Hard guarantee: Use Image never opens Variations / Remix refs.
            if (draft.lab === 'image') {
                draft.imageMode = 'create';
            }
            setReuseDraft(draft);
        },
        [toReuseSource],
    );

    const handleUseLastFrame = useCallback(
        async (img: LabImage) => {
            const videoUrl = img.videoUrl || img.src;
            if (!videoUrl) {
                pushError('No video to capture a frame from.');
                throw new Error('No video');
            }
            try {
                const file = await captureVideoLastFrameFile(videoUrl, {
                    name: `last-frame-${img.id}`,
                });
                const frameUrl = URL.createObjectURL(file);
                setReuseDraft(buildUseLastFrameDraft(toReuseSource(img), frameUrl));
            } catch (e) {
                pushError(e instanceof Error ? e.message : 'Could not capture the last frame.');
                throw e;
            }
        },
        [pushError, toReuseSource],
    );

    const failBatch = useCallback((batchId: string, error?: string | null) => {
        const message = error ?? 'Generation failed.';
        pushError(message);
        setImages((prev) =>
            prev.map((i) =>
                i.batchId === batchId
                    ? { ...i, status: 'failed' as const, progress: null, error: message }
                    : i,
            ),
        );
    }, [pushError]);

    // Realtime completion via Pusher (fal webhook → CreationUpdated).
    useEffect(() => {
        if (isGuest || !pageProps.auth.user?.id) return;

        const echo = getEcho();
        if (!echo) return;

        const channelName = `user.${pageProps.auth.user.id}`;
        const channel = echo.private(channelName);
        const onUpdated = (event: {
            type?: string;
            creation_id?: number;
            creation?: CreationResponse & { type?: string };
        }) => {
            const creation = event.creation;
            const creationId = event.creation_id ?? creation?.id;
            const kind = event.type ?? creation?.type;
            if (!creation || !creationId || !kind) return;

            if (typeof creation.token_balance === 'number') {
                syncTokenBalance(creation.token_balance);
            }

            if (creation.status === 'failed' || creation.status === 'cancelled') {
                pushError(creation.error || 'Generation failed.');
            }

            if (kind === 'image' || kind === 'video') {
                setImages((prev) => mergeImageVideoCreationState(prev, creationId, creation, kind));
                return;
            }

            if (kind === 'music') {
                setTracks((prev) => mergeMusicCreationState(prev, creationId, creation));
                return;
            }

            if (kind === 'voice') {
                setVoices((prev) => mergeVoiceCreationState(prev, creationId, creation));
            }
        };

        channel.listen('.creation.updated', onUpdated);

        return () => {
            channel.stopListening('.creation.updated');
            echo.leave(channelName);
        };
    }, [isGuest, pageProps.auth.user?.id, pushError, syncTokenBalance, tLab]);

    // Live-status sync while creations are active. Designed to scale:
    // - Pusher (fal webhook) is the primary channel; this is a safety-net sync.
    // - One batched request per tick, only when the tab is visible.
    // - Round-robins a small window of active cards; fal calls are throttled server-side.
    // - Skips while a request is in flight; backs off on errors / 429.
    useEffect(() => {
        if (isGuest || !usesStudioLab) return;

        let stopped = false;
        let timer: number | undefined;
        let inFlight = false;
        let cursor = 0;
        let backoffUntil = 0;

        const collectActive = (): { type: 'image' | 'video' | 'music' | 'voice'; id: number }[] => {
            if (isImageLab || isVideoLab) {
                return imagesRef.current
                    .filter((i) => i.creationId && isActiveStatus(i.status))
                    .map((i) => {
                        const isVideo =
                            i.method === 'text-to-video' ||
                            i.method === 'image-to-video' ||
                            i.method === 'reference-to-video';
                        return { type: (isVideo ? 'video' : 'image') as 'image' | 'video', id: i.creationId as number };
                    });
            }
            if (isMusicLab) {
                return tracksRef.current
                    .filter((t) => t.creationId && isActiveStatus(t.status))
                    .map((t) => ({ type: 'music' as const, id: t.creationId as number }));
            }
            if (isVoiceLab) {
                return voicesRef.current
                    .filter((v) => v.creationId && isActiveStatus(v.status))
                    .map((v) => ({ type: 'voice' as const, id: v.creationId as number }));
            }
            return [];
        };

        const applyCreation = (creation: CreationResponse & { type?: string }) => {
            const kind = creation.type;
            if (!creation.id || !kind) return;
            if (typeof creation.token_balance === 'number') syncTokenBalance(creation.token_balance);
            if (kind === 'image' || kind === 'video') {
                setImages((prev) => mergeImageVideoCreationState(prev, creation.id, creation, kind));
            } else if (kind === 'music') {
                setTracks((prev) => mergeMusicCreationState(prev, creation.id, creation));
            } else if (kind === 'voice') {
                setVoices((prev) => mergeVoiceCreationState(prev, creation.id, creation));
            }
        };

        const tick = async () => {
            if (stopped || inFlight) return;
            if (document.visibilityState !== 'visible') return;
            if (Date.now() < backoffUntil) return;

            const active = collectActive();
            if (active.length === 0) return;

            // Round-robin a small window so many active cards never fan out into many fal calls.
            const window: { type: string; id: number }[] = [];
            for (let n = 0; n < Math.min(ACTIVE_CREATION_BATCH, active.length); n++) {
                window.push(active[(cursor + n) % active.length]);
            }
            cursor = (cursor + window.length) % active.length;

            inFlight = true;
            try {
                const data = await apiPost<{ creations: (CreationResponse & { type?: string })[] }>(
                    '/lab/creations/status',
                    { items: window },
                );
                data.creations?.forEach(applyCreation);
            } catch (e) {
                // Back off harder on rate limiting; softer on transient errors.
                const status = e instanceof ApiError ? e.status : 0;
                backoffUntil = Date.now() + (status === 429 ? 30000 : 12000);
            } finally {
                inFlight = false;
            }
        };

        const loop = () => {
            if (stopped) return;
            void tick();
            timer = window.setTimeout(loop, ACTIVE_CREATION_POLL_MS);
        };

        const onVisible = () => {
            if (document.visibilityState === 'visible') void tick();
        };
        document.addEventListener('visibilitychange', onVisible);
        loop();

        return () => {
            stopped = true;
            if (timer) window.clearTimeout(timer);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [isGuest, usesStudioLab, isImageLab, isVideoLab, isMusicLab, isVoiceLab, syncTokenBalance]);

    useEffect(() => {
        if (!usesStudioLab || isGuest) return;

        let cancelled = false;
        const labType = isMusicLab ? (type === 'text-to-sound' ? 'text-to-sound' : 'text-to-music') : type;

        (async () => {
            try {
                if (isImageLab || isVideoLab) {
                    const data = await apiGet<{ images: ApiImageItem[] }>(`/lab/creations?type=${encodeURIComponent(labType)}`);
                    if (cancelled) return;

                    const mapped = (data.images ?? []).map(mapApiImage);
                    setImages(mapped);

                    return;
                }

                if (isMusicLab) {
                    const data = await apiGet<{ tracks: ApiTrackItem[] }>(`/lab/creations?type=${encodeURIComponent(labType)}`);
                    if (cancelled) return;
                    const mapped = (data.tracks ?? []).map(mapApiTrack);
                    setTracks(mapped);

                    return;
                }

                if (isVoiceLab) {
                    const data = await apiGet<{ voices: ApiVoiceItem[] }>(`/lab/creations?type=${encodeURIComponent(labType)}`);
                    if (cancelled) return;
                    const mapped = (data.voices ?? []).map((item, index) => mapApiVoice(item, index));
                    setVoices(mapped);
                }
            } catch {
                // Keep empty library if fetch fails.
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [type, usesStudioLab, isGuest, isImageLab, isVideoLab, isMusicLab, isVoiceLab]);

    const startImageGenerate = useCallback(
        async (nextPrompt?: string, options?: ImageGenerateOptions) => {
            const text = (nextPrompt ?? '').trim();
            if (!hasMeaningfulPrompt(text)) return;

            const aspect = options?.aspect ?? '1:1';
            const quantity = Math.max(1, Math.min(4, options?.quantity ?? 1));
            const hasReferences = (options?.referenceFiles?.length ?? 0) > 0;
            const method: LabImage['method'] = hasReferences || options?.mode === 'variations' ? 'image-to-image' : 'text-to-image';
            const batchId = `batch-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
            const startedAt = Date.now();

            const placeholders: LabImage[] = Array.from({ length: quantity }, (_, i) => ({
                id: `${batchId}-${i}`,
                batchId,
                batchIndex: i,
                prompt: text,
                src: '',
                favorite: false,
                createdAt: startedAt,
                startedAt,
                aspect,
                resolution: options?.resolution ?? '1K',
                quantity,
                imageMode: options?.mode ?? 'create',
                inputAssets: (options?.referenceFiles ?? []).map((file) => ({
                    url: URL.createObjectURL(file),
                    kind: (file.type.startsWith('video/') ? 'video' : 'image') as 'image' | 'video',
                    name: file.name,
                })),
                method,
                modelName: options?.modelName,
                status: 'pending',
                progress: tLab('starting'),
            }));

            setImages((prev) => [...placeholders, ...prev]);

            try {
                const form = new FormData();
                form.append('prompt', text);
                if (options?.endpointId) form.append('endpoint_id', options.endpointId);
                form.append('aspect', aspect);
                form.append('resolution', options?.resolution ?? '1K');
                form.append('quantity', String(quantity));
                if (options?.mode) form.append('mode', options.mode);
                options?.referenceFiles?.forEach((file) => form.append('references[]', file));

                const data = await apiPostForm<CreationResponse>('/lab/image/generate', form);
                syncTokenBalance(data.token_balance);
                setImages((prev) =>
                    prev.map((i) =>
                        i.batchId === batchId
                            ? {
                                  ...i,
                                  creationId: data.id,
                                  status: data.status,
                                  progress: data.progress_message ?? i.progress,
                                  queuePosition: data.queue_position ?? null,
                                  progressPercent: data.progress_percent ?? null,
                                  modelName: data.model_name ?? i.modelName,
                              }
                            : i,
                    ),
                );
                if (data.status === 'failed') {
                    failBatch(batchId, data.error);
                    return;
                }
            } catch (e) {
                syncTokenBalanceFromError(e);
                failBatch(batchId, e instanceof Error ? e.message : 'Could not start generation.');
            }
        },
        [failBatch, syncTokenBalance, syncTokenBalanceFromError, tLab],
    );

    const startVideoGenerate = useCallback(
        async (nextPrompt?: string, options?: VideoGenerateOptions) => {
            const text = (nextPrompt ?? '').trim();
            if (!hasMeaningfulPrompt(text)) return;

            const aspect = options?.aspect ?? '16:9';
            const batchId = `vbatch-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
            const startedAt = Date.now();

            const placeholder: LabImage = {
                id: `${batchId}-0`,
                batchId,
                batchIndex: 0,
                prompt: text,
                src: '',
                favorite: false,
                createdAt: startedAt,
                startedAt,
                aspect,
                resolution: options?.resolution ?? '720p',
                duration: options?.duration ?? 5,
                audio: options?.audio ?? true,
                inputAssets: [
                    ...(options?.imageFiles ?? []).map((file) => ({
                        url: URL.createObjectURL(file),
                        kind: 'image' as const,
                        name: file.name,
                    })),
                    ...(options?.videoFiles ?? []).map((file) => ({
                        url: URL.createObjectURL(file),
                        kind: 'video' as const,
                        name: file.name,
                    })),
                    ...(options?.audioFiles ?? []).map((file) => ({
                        url: URL.createObjectURL(file),
                        kind: 'audio' as const,
                        name: file.name,
                    })),
                ],
                method: (options?.routeMode as LabImage['method']) || 'text-to-video',
                modelName: options?.modelName,
                status: 'pending',
                progress: tLab('starting'),
            };

            setImages((prev) => [placeholder, ...prev]);

            try {
                const imageFiles = options?.imageFiles ?? [];
                const videoFiles = options?.videoFiles ?? [];
                const audioFiles = options?.audioFiles ?? [];
                const totalUploads = imageFiles.length + videoFiles.length + audioFiles.length;

                const uploadOne = async (file: File, index: number) => {
                    setImages((prev) =>
                        prev.map((i) =>
                            i.batchId === batchId
                                ? {
                                      ...i,
                                      progress: tLab('uploadingRefs', {
                                          current: index + 1,
                                          total: totalUploads,
                                          defaultValue: `Uploading references ${index + 1}/${totalUploads}…`,
                                      }),
                                  }
                                : i,
                        ),
                    );
                    const formUpload = new FormData();
                    formUpload.append('file', file);
                    const uploaded = await apiPostForm<{ url: string; type: string }>('/lab/media/upload', formUpload);
                    return uploaded;
                };

                const imageUrls: string[] = [];
                const videoUrls: string[] = [];
                const audioUrls: string[] = [];
                let uploadIndex = 0;

                for (const file of imageFiles) {
                    const uploaded = await uploadOne(file, uploadIndex++);
                    if (uploaded.url) imageUrls.push(uploaded.url);
                }
                for (const file of videoFiles) {
                    const uploaded = await uploadOne(file, uploadIndex++);
                    if (uploaded.url) videoUrls.push(uploaded.url);
                }
                for (const file of audioFiles) {
                    const uploaded = await uploadOne(file, uploadIndex++);
                    if (uploaded.url) audioUrls.push(uploaded.url);
                }

                setImages((prev) =>
                    prev.map((i) =>
                        i.batchId === batchId ? { ...i, progress: tLab('starting') } : i,
                    ),
                );

                const form = new FormData();
                form.append('prompt', text);
                if (options?.endpointId) form.append('endpoint_id', options.endpointId);
                form.append('aspect', aspect);
                form.append('resolution', options?.resolution ?? '720p');
                form.append('duration', String(options?.duration ?? 5));
                form.append('audio', options?.audio === false ? '0' : '1');

                imageUrls.forEach((url) => form.append('image_urls[]', url));
                videoUrls.forEach((url) => form.append('video_urls[]', url));
                audioUrls.forEach((url) => form.append('audio_urls[]', url));

                const data = await apiPostForm<CreationResponse>('/lab/video/generate', form);
                syncTokenBalance(data.token_balance);
                setImages((prev) =>
                    prev.map((i) =>
                        i.batchId === batchId
                            ? {
                                  ...i,
                                  creationId: data.id,
                                  status: data.status,
                                  progress: data.progress_message ?? i.progress,
                                  queuePosition: data.queue_position ?? null,
                                  progressPercent: data.progress_percent ?? null,
                                  modelName: data.model_name ?? i.modelName,
                                  method: (data.mode as LabImage['method']) || i.method,
                              }
                            : i,
                    ),
                );
                if (data.status === 'failed') {
                    failBatch(batchId, data.error);
                    return;
                }
            } catch (e) {
                syncTokenBalanceFromError(e);
                failBatch(batchId, e instanceof Error ? e.message : 'Could not start generation.');
            }
        },
        [failBatch, syncTokenBalance, syncTokenBalanceFromError, tLab],
    );

    const startGenerate = (
        nextPrompt?: string,
        _options?: { quantity: number; aspect: string; resolution: string },
    ) => {
        const text = (nextPrompt ?? prompt).trim();
        if (!text) return;
        setLoading(true);
        window.setTimeout(() => setLoading(false), 1200);
    };

    const startSoundGenerate = useCallback(
        async (
            style: string,
            options?: {
                title: string;
                instrumental: boolean;
                lyrics: string;
                autoEnhance: boolean;
                model: string;
                endpointId: string;
                vocalGender: 'male' | 'female';
                audioFile?: File | null;
                editMode?: 'remix' | 'lyrics';
                durationSeconds?: number | null;
            },
        ) => {
            const trimmed = style.trim();
            if (!hasMeaningfulPrompt(trimmed) || !options?.endpointId) return;

            const localId = `music-local-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
            const startedAt = Date.now();
            const title = (options.title || '').trim() || (trimmed.length > 42 ? `${trimmed.slice(0, 42)}…` : trimmed);
            const durationSeconds =
                typeof options.durationSeconds === 'number' &&
                Number.isFinite(options.durationSeconds) &&
                options.durationSeconds > 0
                    ? Math.max(1, Math.ceil(options.durationSeconds))
                    : null;

            const placeholder: LabTrack = {
                id: localId,
                title,
                style: trimmed,
                lyrics: options.lyrics || undefined,
                cover: '',
                favorite: false,
                createdAt: startedAt,
                instrumental: options.instrumental ?? true,
                model: options.model,
                status: 'pending',
                progress: tLab('starting'),
            };

            setTracks((prev) => [placeholder, ...prev]);
            setLoading(true);

            try {
                let data: CreationResponse;
                if (options.audioFile) {
                    // Shared hosting often breaks PHP multipart audio uploads
                    // (UPLOAD_ERR_CANT_WRITE / ModSecurity). Send base64 JSON instead.
                    if (options.audioFile.size > 20 * 1024 * 1024) {
                        throw new Error('Audio file is too large. Please use an MP3 under 20MB.');
                    }

                    const audioBase64 = await fileToBase64(options.audioFile);
                    const audioName = /\.(mp3|wav|flac|ogg|m4a|aac|mpeg|mpga)$/i.test(options.audioFile.name || '')
                        ? options.audioFile.name
                        : `source.${(options.audioFile.type || '').includes('wav') ? 'wav' : 'mp3'}`;

                    data = await apiPost<CreationResponse>('/lab/music/generate', {
                        prompt: trimmed,
                        title: options.title || '',
                        endpoint_id: options.endpointId,
                        lyrics: options.lyrics || '',
                        instrumental: options.instrumental,
                        auto_enhance: options.autoEnhance,
                        ...(options.vocalGender === 'male' || options.vocalGender === 'female'
                            ? { vocal_gender: options.vocalGender }
                            : {}),
                        edit_mode: options.editMode === 'lyrics' ? 'lyrics' : 'remix',
                        audio_base64: audioBase64,
                        audio_filename: audioName,
                        audio_mime: options.audioFile.type || 'audio/mpeg',
                        ...(durationSeconds != null && Number.isFinite(durationSeconds) && durationSeconds > 0
                            ? { duration_seconds: Math.min(7200, Math.ceil(durationSeconds)) }
                            : {}),
                    });
                } else {
                    data = await apiPost<CreationResponse>('/lab/music/generate', {
                        prompt: trimmed,
                        title: options.title || '',
                        endpoint_id: options.endpointId,
                        lyrics: options.lyrics || '',
                        instrumental: options.instrumental,
                        auto_enhance: options.autoEnhance,
                        vocal_gender: options.vocalGender,
                        ...(durationSeconds != null ? { duration_seconds: durationSeconds } : {}),
                    });
                }
                syncTokenBalance(data.token_balance);

                setTracks((prev) =>
                    prev.map((track) =>
                        track.id === localId
                            ? {
                                  ...track,
                                  creationId: data.id,
                                  status: data.status,
                                  progress: data.progress_message ?? track.progress,
                                  queuePosition: data.queue_position ?? null,
                                  progressPercent: data.progress_percent ?? null,
                                  model: data.model_name ?? track.model,
                                  instrumental: data.instrumental ?? track.instrumental,
                                  title: data.title || track.title,
                                  lyrics: data.lyrics ?? track.lyrics,
                              }
                            : track,
                    ),
                );

                if (data.status === 'failed') {
                    pushError(data.error || 'Generation failed.');
                    setTracks((prev) =>
                        prev.map((track) =>
                            track.id === localId
                                ? { ...track, status: 'failed', error: data.error || 'Generation failed.', progress: tLab('failed') }
                                : track,
                        ),
                    );
                    setLoading(false);
                    return;
                }

            } catch (e) {
                syncTokenBalanceFromError(e);
                pushError(e instanceof Error ? e.message : 'Could not start generation.');
                setTracks((prev) =>
                    prev.map((track) =>
                        track.id === localId
                            ? {
                                  ...track,
                                  status: 'failed',
                                  error: e instanceof Error ? e.message : 'Could not start generation.',
                                  progress: tLab('failed'),
                              }
                            : track,
                    ),
                );
                setLoading(false);
            }
        },
        [pushError, syncTokenBalance, syncTokenBalanceFromError, tLab],
    );

    const startVoiceGenerate = useCallback(
        async (text: string, options?: VoiceGenerateOptions) => {
            const trimmed = text.trim();
            if (!trimmed || !options?.endpointId || !options.voice) return;

            const localId = `voice-local-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
            const startedAt = Date.now();
            const title = trimmed.length > 42 ? `${trimmed.slice(0, 42)}…` : trimmed;

            const placeholder: LabVoice = {
                id: localId,
                title,
                text: trimmed,
                voice: options.voiceName || options.voice,
                favorite: false,
                createdAt: startedAt,
                model: options.model,
                status: 'pending',
                progress: tLab('starting'),
            };

            setVoices((prev) => [placeholder, ...prev]);
            setLoading(true);

            try {
                const data = await apiPost<CreationResponse>('/lab/voice/generate', {
                    text: trimmed,
                    endpoint_id: options.endpointId,
                    voice: options.voice,
                    voice_name: options.voiceName,
                    stability: options.stability,
                    clarity: options.clarity,
                    style: options.styleExaggeration,
                    speed: options.speed,
                });
                syncTokenBalance(data.token_balance);

                setVoices((prev) =>
                    prev.map((v) =>
                        v.id === localId
                            ? {
                                  ...v,
                                  creationId: data.id,
                                  status: data.status,
                                  progress: data.progress_message ?? v.progress,
                                  queuePosition: data.queue_position ?? null,
                                  progressPercent: data.progress_percent ?? null,
                                  model: data.model_name ?? v.model,
                                  voice: data.voice ?? v.voice,
                              }
                            : v,
                    ),
                );

                if (data.status === 'failed') {
                    pushError(data.error || 'Generation failed.');
                    setVoices((prev) =>
                        prev.map((v) =>
                            v.id === localId
                                ? { ...v, status: 'failed', error: data.error || 'Generation failed.', progress: tLab('failed') }
                                : v,
                        ),
                    );
                    setLoading(false);
                    return;
                }

            } catch (e) {
                syncTokenBalanceFromError(e);
                pushError(e instanceof Error ? e.message : 'Could not start generation.');
                setVoices((prev) =>
                    prev.map((v) =>
                        v.id === localId
                            ? {
                                  ...v,
                                  status: 'failed',
                                  error: e instanceof Error ? e.message : 'Could not start generation.',
                                  progress: tLab('failed'),
                              }
                            : v,
                    ),
                );
                setLoading(false);
            }
        },
        [pushError, syncTokenBalance, syncTokenBalanceFromError, tLab],
    );

    const toggleFavorite = (id: string) => {
        setImages((prev) => prev.map((img) => (img.id === id ? { ...img, favorite: !img.favorite } : img)));
    };

    const deleteImages = (ids: string[]) => {
        setImages((prev) => prev.filter((img) => !ids.includes(img.id)));
    };

    const toggleTrackFavorite = (id: string) => {
        setTracks((prev) => prev.map((t) => (t.id === id ? { ...t, favorite: !t.favorite } : t)));
    };

    const deleteTracks = (ids: string[]) => {
        setTracks((prev) => prev.filter((t) => !ids.includes(t.id)));
    };

    const toggleVoiceFavorite = (id: string) => {
        setVoices((prev) => prev.map((v) => (v.id === id ? { ...v, favorite: !v.favorite } : v)));
    };

    const deleteVoices = (ids: string[]) => {
        setVoices((prev) => prev.filter((v) => !ids.includes(v.id)));
    };

    const renderCreateForm = () => {
        if (isVoiceLab) {
            return (
                <VoiceLabCreateForm
                    brands={brands}
                    loading={loading || voiceGenerating}
                    creditsConfig={creditsConfig}
                    tokenBalance={tokenBalance}
                    onGenerate={startVoiceGenerate}
                />
            );
        }
        if (isMusicLab) {
            return (
                <SoundLabCreateForm
                    brands={brands}
                    loading={loading || musicGenerating}
                    creditsConfig={creditsConfig}
                    tokenBalance={tokenBalance}
                    onGenerate={startSoundGenerate}
                    draft={reuseDraft?.lab === 'music' ? reuseDraft : null}
                />
            );
        }
        if (isVideoLab) {
            return (
                <VideoLabCreateForm
                    brands={brands}
                    placeholder={placeholder}
                    loading={videoGenerating}
                    creditsConfig={creditsConfig}
                    tokenBalance={tokenBalance}
                    onGenerate={startVideoGenerate}
                    draft={reuseDraft?.lab === 'video' ? reuseDraft : null}
                />
            );
        }
        return (
            <ImageLabCreateForm
                brands={brands}
                placeholder={placeholder}
                loading={imageGenerating}
                creditsConfig={creditsConfig}
                tokenBalance={tokenBalance}
                onGenerate={startImageGenerate}
                draft={reuseDraft?.lab === 'image' ? reuseDraft : null}
            />
        );
    };

    return (
        <div className="flex w-full min-w-0 flex-col md:h-full md:min-h-0">
            <Head title={`${title} - Lab`} />
            <div className="flex flex-col rounded-xl bg-[#070708] md:min-h-0 md:flex-1 md:overflow-hidden">
                {usesStudioLab ? (
                    <div className="flex flex-col md:min-h-0 md:flex-1 md:overflow-hidden md:flex-row">
                        {/* Mobile: natural height + page scroll. Desktop: fixed side panel. */}
                        <motion.aside
                            initial={{ opacity: 0, x: -12 }}
                            animate={{ opacity: 1, x: 0 }}
                            className="flex w-full shrink-0 flex-col border-b border-white/[0.06] md:h-full md:min-h-0 md:w-[380px] md:overflow-hidden md:border-b-0 md:border-r xl:w-[420px]"
                        >
                            <div className="relative flex min-h-0 flex-1 flex-col">
                                {renderCreateForm()}
                                {isGuest && (
                                    <div className="absolute inset-x-0 bottom-0 z-30 flex flex-col items-center bg-gradient-to-t from-[#0a0a0f] via-[#0a0a0f] to-transparent px-4 pb-4 pt-10">
                                        <motion.div
                                            initial={{ opacity: 0, y: 8 }}
                                            animate={{ opacity: 1, y: 0 }}
                                            transition={{ delay: 0.1, duration: 0.4 }}
                                            whileHover={{ scale: 1.03 }}
                                            whileTap={{ scale: 0.97 }}
                                        >
                                            <Link
                                                href="/register"
                                                className="group relative inline-flex h-10 items-center justify-center gap-2 overflow-hidden rounded-full bg-gradient-to-b from-[#FF6A45] to-[#E24216] px-9 text-[13px] font-semibold text-white shadow-[0_10px_26px_-10px_rgba(255,87,51,0.95)]"
                                            >
                                                <span className="absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/40 to-transparent transition-transform duration-[900ms] ease-out group-hover:translate-x-full" />
                                                <motion.span
                                                    aria-hidden
                                                    className="relative flex h-4 w-4 items-center justify-center"
                                                    animate={{ rotate: [0, 15, -10, 0], scale: [1, 1.15, 1] }}
                                                    transition={{ duration: 2.2, repeat: Infinity, ease: 'easeInOut' }}
                                                >
                                                    <svg className="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                                                        <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z" />
                                                    </svg>
                                                </motion.span>
                                                <span className="relative">{tLab('signUpFree')}</span>
                                            </Link>
                                        </motion.div>
                                        <p className="mt-2 text-center text-[11px] text-white/40">
                                            {tLab('guestTokens')}
                                        </p>
                                    </div>
                                )}
                            </div>
                        </motion.aside>

                        <div className="flex min-h-[50vh] min-w-0 w-full flex-col md:min-h-0 md:flex-1 md:overflow-hidden">
                            {isGuest ? (
                                <GuestLibraryPlaceholder title={title} />
                            ) : isMusicLab ? (
                                <SoundLabLibrary
                                    tracks={tracks}
                                    generating={loading || musicGenerating}
                                    onToggleFavorite={toggleTrackFavorite}
                                    onDelete={deleteTracks}
                                />
                            ) : isVoiceLab ? (
                                <VoiceLabLibrary
                                    voices={voices}
                                    generating={loading}
                                    onToggleFavorite={toggleVoiceFavorite}
                                    onDelete={deleteVoices}
                                />
                            ) : (
                                <ImageLabLibrary
                                    images={images}
                                    generating={imageGenerating || videoGenerating}
                                    onToggleFavorite={toggleFavorite}
                                    onDelete={deleteImages}
                                    onReuseSettings={handleReuseSettings}
                                    onUseResult={handleUseResult}
                                    onUseLastFrame={handleUseLastFrame}
                                />
                            )}
                        </div>
                    </div>
                ) : (
                    <div className="flex flex-col md:min-h-0 md:flex-1 md:overflow-hidden md:flex-row">
                        <motion.aside
                            initial={{ opacity: 0, x: -12 }}
                            animate={{ opacity: 1, x: 0 }}
                            className="flex w-full shrink-0 flex-col border-b border-white/[0.04] bg-[#0c0c0e] p-4 md:w-[300px] md:border-b-0 md:border-r xl:w-[340px]"
                        >
                            <label className="mb-2 text-xs font-medium text-white/50">{tLab('prompt')}</label>
                            <textarea
                                value={prompt}
                                onChange={(e) => setPrompt(e.target.value)}
                                placeholder={placeholder}
                                className="mb-4 h-40 w-full resize-none rounded-xl border border-white/10 bg-black/40 p-3 text-sm text-white outline-none placeholder:text-white/25 focus:border-brand-500"
                            />
                            <div className="mb-4 rounded-xl border border-white/10 bg-white/[0.03] px-3 py-2 text-xs text-white/50">
                                {tLab('model')}: <span className="text-white/80">{model}</span>
                            </div>
                            <Button variant="creative" className="mt-auto w-full" loading={loading} onClick={() => startGenerate()}>
                                {tLab('generate')}
                            </Button>
                        </motion.aside>

                        <div className="flex min-h-0 min-w-0 flex-1 items-center justify-center p-4 md:p-6">
                            <div className="flex h-full max-h-[520px] w-full max-w-3xl items-center justify-center rounded-2xl border border-dashed border-white/10 bg-white/[0.02] text-sm text-white/30">
                                {tLab('previewCanvas')}
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

function fileToBase64(file: File): Promise<string> {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => {
            const result = typeof reader.result === 'string' ? reader.result : '';
            const comma = result.indexOf(',');
            resolve(comma >= 0 ? result.slice(comma + 1) : result);
        };
        reader.onerror = () => reject(new Error('Could not read the audio file.'));
        reader.readAsDataURL(file);
    });
}

function GuestLibraryPlaceholder({ title }: { title: string }) {
    const { t } = useTranslation('lab');
    const { t: tc } = useTranslation('common');

    return (
        <div className="relative flex h-full min-h-[50vh] w-full items-center justify-center overflow-hidden p-6">
            <div aria-hidden className="pointer-events-none absolute inset-0">
                <div className="absolute left-1/2 top-1/3 h-72 w-72 -translate-x-1/2 rounded-full bg-[#FF5733]/12 blur-[120px]" />
                <div className="absolute inset-0 bg-[radial-gradient(circle_at_50%_35%,rgba(255,255,255,0.035),transparent_55%)]" />
            </div>

            <motion.div
                initial={{ opacity: 0, y: 18, scale: 0.98 }}
                animate={{ opacity: 1, y: 0, scale: 1 }}
                transition={{ duration: 0.45, ease: [0.22, 1, 0.36, 1] }}
                className="relative w-full max-w-md text-center"
            >
                <div className="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl border border-white/10 bg-white/[0.04] shadow-[0_20px_50px_-24px_rgba(255,87,51,0.7)]">
                    <svg className="h-7 w-7 text-[#FF8A65]" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75">
                        <rect x="3" y="3" width="18" height="18" rx="4" />
                        <circle cx="8.5" cy="9" r="1.6" />
                        <path strokeLinecap="round" strokeLinejoin="round" d="m21 15-4.5-4.5L7 20" />
                    </svg>
                </div>

                <h3 className="font-[family-name:Outfit,sans-serif] text-xl font-bold text-white">
                    {t('guestGalleryTitle', { title })}
                </h3>
                <p className="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-white/45">
                    {t('guestGallerySub')}
                </p>

                <div className="mt-6 flex flex-col items-center justify-center gap-2.5 sm:flex-row">
                    <Link
                        href="/register"
                        className="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-b from-[#FF6A45] to-[#E24216] px-6 text-sm font-semibold text-white shadow-[0_12px_30px_-14px_rgba(255,87,51,0.95)] transition hover:brightness-110 sm:w-auto"
                    >
                        {t('signUpFree')}
                        <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M5 12h14m-6-6 6 6-6 6" />
                        </svg>
                    </Link>
                    <Link
                        href="/?login"
                        className="inline-flex h-11 w-full items-center justify-center rounded-xl border border-white/15 bg-white/[0.04] px-6 text-sm font-semibold text-white transition hover:border-white/25 hover:bg-white/[0.08] sm:w-auto"
                    >
                        {tc('signIn')}
                    </Link>
                </div>
            </motion.div>
        </div>
    );
}
