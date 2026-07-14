import ImageLabCreateForm, { type ImageGenerateOptions } from '@/Components/ImageLabCreateForm';
import ImageLabLibrary, { type LabImage } from '@/Components/ImageLabLibrary';
import SoundLabCreateForm from '@/Components/SoundLabCreateForm';
import SoundLabLibrary, { type LabTrack } from '@/Components/SoundLabLibrary';
import VideoLabCreateForm, { type VideoGenerateOptions } from '@/Components/VideoLabCreateForm';
import VoiceLabCreateForm, { type VoiceGenerateOptions } from '@/Components/VoiceLabCreateForm';
import VoiceLabLibrary, { type LabVoice } from '@/Components/VoiceLabLibrary';
import { ApiError, apiGet, apiPost, apiPostForm } from '@/lib/api';
import type { CreditsConfig } from '@/lib/imageCredits';
import {
    buildReuseSettingsDraft,
    buildUseResultDraft,
    consumeLabReuseDraft,
    type LabReuseDraft,
} from '@/lib/labReuse';
import type { Brand, PageProps } from '@/types';
import Button from '@/Components/Button';
import { Head, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useCallback, useEffect, useRef, useState } from 'react';

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
    error?: string | null;
};

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
        error: item.error ?? undefined,
    };
}

function isActiveStatus(status?: CreationStatus) {
    return status !== undefined && status !== 'completed' && status !== 'failed' && status !== 'cancelled';
}

export default function LabWorkspace({
    type = 'text-to-video',
    title,
    brands = [],
    placeholder = 'Enter your prompt…',
    creditsConfig,
}: Props) {
    const { props: pageProps } = usePage<PageProps>();
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
    const [reuseDraft, setReuseDraft] = useState<LabReuseDraft | null>(null);
    const pollTimers = useRef<Record<string, number>>({});
    const pollImageRef = useRef<(batchId: string, creationId: number, aspect: string, expectedCount: number) => void>(() => {});
    const pollVideoRef = useRef<(batchId: string, creationId: number, aspect: string) => void>(() => {});
    const model = brands[0]?.models[0]?.name || 'Auto';

    const imageGenerating = isImageLab && images.some((i) => isActiveStatus(i.status));
    const videoGenerating = isVideoLab && images.some((i) => isActiveStatus(i.status));
    const musicGenerating = isMusicLab && tracks.some((t) => isActiveStatus(t.status));
    const voiceGenerating = isVoiceLab && voices.some((v) => isActiveStatus(v.status));
    const pollVoiceRef = useRef<(localId: string, creationId: number) => void>(() => {});
    const pollMusicRef = useRef<(localId: string, creationId: number) => void>(() => {});

    useEffect(() => {
        const expectedLab = isVideoLab ? 'video' : isImageLab ? 'image' : isMusicLab ? 'music' : undefined;
        if (!expectedLab) return;
        const pending = consumeLabReuseDraft(expectedLab);
        if (pending) setReuseDraft(pending);
    }, [isImageLab, isVideoLab, isMusicLab]);

    useEffect(() => {
        const timers = pollTimers.current;
        return () => {
            Object.values(timers).forEach((t) => window.clearTimeout(t));
        };
    }, []);

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
            setReuseDraft(buildUseResultDraft(toReuseSource(img)));
        },
        [toReuseSource],
    );

    const failBatch = useCallback((batchId: string, error?: string | null) => {
        setImages((prev) =>
            prev.map((i) =>
                i.batchId === batchId
                    ? { ...i, status: 'failed' as const, progress: null, error: error ?? 'Generation failed.' }
                    : i,
            ),
        );
    }, []);

    const pollImage = useCallback(
        (batchId: string, creationId: number, aspect: string, expectedCount: number) => {
            const started = Date.now();

            const tick = async () => {
                let data: CreationResponse;
                try {
                    data = await apiGet<CreationResponse>(`/lab/image/creations/${creationId}/status`);
                } catch {
                    scheduleNext();
                    return;
                }

                if (data.status === 'completed') {
                    delete pollTimers.current[batchId];
                    const urls = data.images ?? [];
                    if (urls.length === 0) {
                        failBatch(batchId, 'Generation finished without an image.');
                        return;
                    }

                    setImages((prev) =>
                        prev.map((i) => (i.batchId === batchId ? { ...i, completing: true } : i)),
                    );

                    window.setTimeout(() => {
                        setImages((prev) => {
                            const batch = prev
                                .filter((i) => i.batchId === batchId)
                                .sort((a, b) => (a.batchIndex ?? 0) - (b.batchIndex ?? 0));
                            const rest = prev.filter((i) => i.batchId !== batchId);

                            const cards: LabImage[] = batch.map((slot, idx) => {
                                const url = urls[idx];
                                if (url) {
                                    return {
                                        id: slot.id,
                                        prompt: data.prompt,
                                        src: url,
                                        favorite: false,
                                        createdAt: Date.now(),
                                        aspect: slot.aspect ?? aspect,
                                        resolution: slot.resolution,
                                        quantity: slot.quantity,
                                        imageMode: slot.imageMode,
                                        inputAssets: slot.inputAssets,
                                        method: slot.method ?? 'text-to-image',
                                        modelName: data.model_name ?? slot.modelName,
                                        status: 'completed' as const,
                                        creationId,
                                        batchId,
                                        batchIndex: idx,
                                    };
                                }
                                return {
                                    ...slot,
                                    status: 'failed' as const,
                                    completing: false,
                                    error: 'This output was not returned by the model.',
                                };
                            });

                            if (urls.length > expectedCount) {
                                for (let i = expectedCount; i < urls.length; i++) {
                                    const template = batch[0];
                                    cards.push({
                                        id: `${batchId}-extra-${i}`,
                                        prompt: data.prompt,
                                        src: urls[i],
                                        favorite: false,
                                        createdAt: Date.now(),
                                        aspect: template?.aspect ?? aspect,
                                        resolution: template?.resolution,
                                        quantity: template?.quantity,
                                        imageMode: template?.imageMode,
                                        inputAssets: template?.inputAssets,
                                        method: template?.method ?? 'text-to-image',
                                        modelName: data.model_name ?? undefined,
                                        status: 'completed',
                                        creationId,
                                        batchId,
                                        batchIndex: i,
                                    });
                                }
                            }

                            return [...cards, ...rest];
                        });
                    }, 380);
                    return;
                }

                if (data.status === 'failed' || data.status === 'cancelled') {
                    delete pollTimers.current[batchId];
                    failBatch(batchId, data.error);
                    return;
                }

                setImages((prev) =>
                    prev.map((i) =>
                        i.batchId === batchId
                            ? { ...i, status: data.status, progress: data.progress_message ?? i.progress }
                            : i,
                    ),
                );
                scheduleNext();
            };

            const scheduleNext = () => {
                const elapsed = Date.now() - started;
                if (elapsed > 5 * 60 * 1000) {
                    delete pollTimers.current[batchId];
                    failBatch(batchId, 'Generation timed out.');
                    return;
                }
                const delay = elapsed < 5000 ? 1500 : 5000;
                pollTimers.current[batchId] = window.setTimeout(tick, delay);
            };

            scheduleNext();
        },
        [failBatch],
    );

    pollImageRef.current = pollImage;

    const pollVideo = useCallback(
        (batchId: string, creationId: number, aspect: string) => {
            const started = Date.now();

            const tick = async () => {
                let data: CreationResponse;
                try {
                    data = await apiGet<CreationResponse>(`/lab/video/creations/${creationId}/status`);
                } catch {
                    scheduleNext();
                    return;
                }

                if (data.status === 'completed') {
                    delete pollTimers.current[batchId];
                    const videoUrl = data.video_url || data.preview_url;
                    if (!videoUrl) {
                        failBatch(batchId, 'Generation finished without a video.');
                        return;
                    }

                    setImages((prev) =>
                        prev.map((i) => (i.batchId === batchId ? { ...i, completing: true } : i)),
                    );

                    window.setTimeout(() => {
                        setImages((prev) => {
                            const existing = prev.find((i) => i.batchId === batchId);
                            const rest = prev.filter((i) => i.batchId !== batchId);
                            const card: LabImage = {
                                id: `video-${creationId}`,
                                prompt: data.prompt,
                                src: data.thumbnail_url || videoUrl,
                                videoUrl,
                                favorite: false,
                                createdAt: Date.now(),
                                aspect: existing?.aspect ?? aspect,
                                resolution: existing?.resolution,
                                duration: existing?.duration,
                                audio: existing?.audio,
                                inputAssets: existing?.inputAssets,
                                method: (data.mode as LabImage['method']) || existing?.method || 'text-to-video',
                                modelName: data.model_name ?? existing?.modelName,
                                status: 'completed',
                                creationId,
                                batchId,
                                batchIndex: 0,
                            };
                            return [card, ...rest];
                        });
                    }, 380);
                    return;
                }

                if (data.status === 'failed' || data.status === 'cancelled') {
                    delete pollTimers.current[batchId];
                    failBatch(batchId, data.error);
                    return;
                }

                setImages((prev) =>
                    prev.map((i) =>
                        i.batchId === batchId
                            ? { ...i, status: data.status, progress: data.progress_message ?? i.progress }
                            : i,
                    ),
                );
                scheduleNext();
            };

            const scheduleNext = () => {
                const elapsed = Date.now() - started;
                if (elapsed > 10 * 60 * 1000) {
                    delete pollTimers.current[batchId];
                    failBatch(batchId, 'Generation timed out.');
                    return;
                }
                const delay = elapsed < 8000 ? 2000 : 5000;
                pollTimers.current[batchId] = window.setTimeout(tick, delay);
            };

            scheduleNext();
        },
        [failBatch],
    );

    pollVideoRef.current = pollVideo;

    useEffect(() => {
        if (!usesStudioLab) return;

        let cancelled = false;
        const labType = isMusicLab ? (type === 'text-to-sound' ? 'text-to-sound' : 'text-to-music') : type;

        (async () => {
            try {
                if (isImageLab || isVideoLab) {
                    const data = await apiGet<{ images: ApiImageItem[] }>(`/lab/creations?type=${encodeURIComponent(labType)}`);
                    if (cancelled) return;

                    const mapped = (data.images ?? []).map(mapApiImage);
                    setImages(mapped);

                    const resumed = new Set<string>();
                    for (const img of mapped) {
                        if (!img.batchId || !img.creationId || !isActiveStatus(img.status)) continue;
                        if (resumed.has(img.batchId)) continue;
                        resumed.add(img.batchId);

                        if (isVideoLab) {
                            pollVideoRef.current(img.batchId, img.creationId, img.aspect ?? '16:9');
                        } else {
                            const slots = mapped.filter((i) => i.batchId === img.batchId);
                            pollImageRef.current(img.batchId, img.creationId, img.aspect ?? '1:1', slots.length);
                        }
                    }
                    return;
                }

                if (isMusicLab) {
                    const data = await apiGet<{ tracks: ApiTrackItem[] }>(`/lab/creations?type=${encodeURIComponent(labType)}`);
                    if (cancelled) return;
                    const mapped = (data.tracks ?? []).map(mapApiTrack);
                    setTracks(mapped);

                    for (const track of mapped) {
                        if (!track.creationId || !isActiveStatus(track.status)) continue;
                        pollMusicRef.current(track.id, track.creationId);
                    }
                    return;
                }

                if (isVoiceLab) {
                    const data = await apiGet<{ voices: ApiVoiceItem[] }>(`/lab/creations?type=${encodeURIComponent(labType)}`);
                    if (cancelled) return;
                    setVoices((data.voices ?? []).map((item, index) => mapApiVoice(item, index)));
                }
            } catch {
                // Keep empty library if fetch fails.
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [type, usesStudioLab, isImageLab, isVideoLab, isMusicLab, isVoiceLab]);

    const startImageGenerate = useCallback(
        async (nextPrompt?: string, options?: ImageGenerateOptions) => {
            const text = (nextPrompt ?? '').trim();
            if (!text) return;

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
                progress: 'Starting…',
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
                                  modelName: data.model_name ?? i.modelName,
                              }
                            : i,
                    ),
                );
                if (data.status === 'failed') {
                    failBatch(batchId, data.error);
                    return;
                }
                pollImage(batchId, data.id, aspect, quantity);
            } catch (e) {
                syncTokenBalanceFromError(e);
                failBatch(batchId, e instanceof Error ? e.message : 'Could not start generation.');
            }
        },
        [failBatch, pollImage, syncTokenBalance, syncTokenBalanceFromError],
    );

    const startVideoGenerate = useCallback(
        async (nextPrompt?: string, options?: VideoGenerateOptions) => {
            const text = (nextPrompt ?? '').trim();
            if (!text) return;

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
                progress: 'Starting…',
            };

            setImages((prev) => [placeholder, ...prev]);

            try {
                const form = new FormData();
                form.append('prompt', text);
                if (options?.endpointId) form.append('endpoint_id', options.endpointId);
                form.append('aspect', aspect);
                form.append('resolution', options?.resolution ?? '720p');
                form.append('duration', String(options?.duration ?? 5));
                form.append('audio', options?.audio === false ? '0' : '1');

                options?.imageFiles?.forEach((file) => form.append('images[]', file));
                options?.videoFiles?.forEach((file) => form.append('videos[]', file));
                options?.audioFiles?.forEach((file) => form.append('audios[]', file));

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
                pollVideo(batchId, data.id, aspect);
            } catch (e) {
                syncTokenBalanceFromError(e);
                failBatch(batchId, e instanceof Error ? e.message : 'Could not start generation.');
            }
        },
        [failBatch, pollVideo, syncTokenBalance, syncTokenBalanceFromError],
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
            if (!trimmed || !options?.endpointId) return;

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
                progress: 'Starting…',
            };

            setTracks((prev) => [placeholder, ...prev]);
            setLoading(true);

            try {
                let data: CreationResponse;
                if (options.audioFile) {
                    const form = new FormData();
                    form.append('prompt', trimmed);
                    form.append('title', options.title || '');
                    form.append('endpoint_id', options.endpointId);
                    form.append('lyrics', options.lyrics || '');
                    form.append('instrumental', options.instrumental ? '1' : '0');
                    form.append('auto_enhance', options.autoEnhance ? '1' : '0');
                    form.append('vocal_gender', options.vocalGender);
                    form.append('edit_mode', options.editMode === 'lyrics' ? 'lyrics' : 'remix');
                    form.append('audio', options.audioFile);
                    if (durationSeconds != null) {
                        form.append('duration_seconds', String(durationSeconds));
                    }
                    data = await apiPostForm<CreationResponse>('/lab/music/generate', form);
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
                    prev.map((t) =>
                        t.id === localId
                            ? {
                                  ...t,
                                  creationId: data.id,
                                  status: data.status,
                                  progress: data.progress_message ?? t.progress,
                                  model: data.model_name ?? t.model,
                                  instrumental: data.instrumental ?? t.instrumental,
                                  title: data.title || t.title,
                                  lyrics: data.lyrics ?? t.lyrics,
                              }
                            : t,
                    ),
                );

                if (data.status === 'failed') {
                    setTracks((prev) =>
                        prev.map((t) =>
                            t.id === localId
                                ? { ...t, status: 'failed', error: data.error || 'Generation failed.', progress: 'Failed' }
                                : t,
                        ),
                    );
                    setLoading(false);
                    return;
                }

                pollMusicRef.current(localId, data.id);
            } catch (e) {
                syncTokenBalanceFromError(e);
                setTracks((prev) =>
                    prev.map((t) =>
                        t.id === localId
                            ? {
                                  ...t,
                                  status: 'failed',
                                  error: e instanceof Error ? e.message : 'Could not start generation.',
                                  progress: 'Failed',
                              }
                            : t,
                    ),
                );
                setLoading(false);
            }
        },
        [syncTokenBalance, syncTokenBalanceFromError],
    );

    const pollMusic = useCallback((localId: string, creationId: number) => {
        const started = Date.now();

        const scheduleNext = () => {
            const elapsed = Date.now() - started;
            // Music can run a long time — 15 min timeout
            if (elapsed > 15 * 60 * 1000) {
                setTracks((prev) =>
                    prev.map((t) =>
                        t.id === localId || t.creationId === creationId
                            ? { ...t, status: 'failed', error: 'Timed out waiting for music.', progress: 'Failed' }
                            : t,
                    ),
                );
                setLoading(false);
                delete pollTimers.current[localId];
                return;
            }
            // Every 2s at first, then every 8s after 4s to avoid rate limits
            const delay = elapsed < 4000 ? 2000 : 8000;
            pollTimers.current[localId] = window.setTimeout(tick, delay);
        };

        const tick = async () => {
            let data: CreationResponse;
            try {
                data = await apiGet<CreationResponse>(`/lab/music/creations/${creationId}/status`);
            } catch {
                scheduleNext();
                return;
            }

            if (data.status === 'completed') {
                delete pollTimers.current[localId];
                const audioUrl = data.audio_url || data.preview_url;
                if (!audioUrl) {
                    setTracks((prev) =>
                        prev.map((t) =>
                            t.id === localId || t.creationId === creationId
                                ? { ...t, status: 'failed', error: 'Generation finished without audio.', progress: 'Failed' }
                                : t,
                        ),
                    );
                    setLoading(false);
                    return;
                }

                setTracks((prev) =>
                    prev.map((t) =>
                        t.id === localId || t.creationId === creationId
                            ? {
                                  ...t,
                                  completing: true,
                                  progress: 'Completed',
                              }
                            : t,
                    ),
                );

                window.setTimeout(() => {
                    setTracks((prev) =>
                        prev.map((t) =>
                            t.id === localId || t.creationId === creationId
                                ? {
                                      ...t,
                                      id: `track-${creationId}`,
                                      creationId,
                                      status: 'completed',
                                      completing: false,
                                      progress: 'Completed',
                                      audioUrl,
                                      cover: data.cover_url || t.cover || '',
                                      title: data.title || t.title,
                                      style: data.prompt || t.style,
                                      lyrics: data.lyrics ?? t.lyrics,
                                      model: data.model_name ?? t.model,
                                      instrumental: data.instrumental ?? t.instrumental,
                                      duration: data.duration ?? t.duration,
                                      error: undefined,
                                  }
                                : t,
                        ),
                    );
                    setLoading(false);
                }, 380);
                return;
            }

            if (data.status === 'failed' || data.status === 'cancelled') {
                delete pollTimers.current[localId];
                setTracks((prev) =>
                    prev.map((t) =>
                        t.id === localId || t.creationId === creationId
                            ? {
                                  ...t,
                                  status: data.status,
                                  error: data.error || 'Generation failed.',
                                  progress: 'Failed',
                              }
                            : t,
                    ),
                );
                setLoading(false);
                return;
            }

            setTracks((prev) =>
                prev.map((t) =>
                    t.id === localId || t.creationId === creationId
                        ? {
                              ...t,
                              creationId,
                              status: data.status,
                              progress:
                                  data.progress_message ||
                                  (data.status === 'queued'
                                      ? 'In queue'
                                      : data.status === 'in_progress'
                                        ? 'Composing…'
                                        : t.progress),
                          }
                        : t,
                ),
            );
            scheduleNext();
        };

        scheduleNext();
    }, []);

    pollMusicRef.current = pollMusic;

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
                progress: 'Starting…',
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
                                  model: data.model_name ?? v.model,
                                  voice: data.voice ?? v.voice,
                              }
                            : v,
                    ),
                );

                if (data.status === 'failed') {
                    setVoices((prev) =>
                        prev.map((v) =>
                            v.id === localId
                                ? { ...v, status: 'failed', error: data.error || 'Generation failed.', progress: 'Failed' }
                                : v,
                        ),
                    );
                    setLoading(false);
                    return;
                }

                pollVoiceRef.current(localId, data.id);
            } catch (e) {
                syncTokenBalanceFromError(e);
                setVoices((prev) =>
                    prev.map((v) =>
                        v.id === localId
                            ? {
                                  ...v,
                                  status: 'failed',
                                  error: e instanceof Error ? e.message : 'Could not start generation.',
                                  progress: 'Failed',
                              }
                            : v,
                    ),
                );
                setLoading(false);
            }
        },
        [syncTokenBalance, syncTokenBalanceFromError],
    );

    const pollVoice = useCallback((localId: string, creationId: number) => {
        const started = Date.now();

        const scheduleNext = () => {
            const elapsed = Date.now() - started;
            if (elapsed > 5 * 60 * 1000) {
                setVoices((prev) =>
                    prev.map((v) =>
                        v.id === localId
                            ? { ...v, status: 'failed', error: 'Timed out waiting for audio.', progress: 'Failed' }
                            : v,
                    ),
                );
                setLoading(false);
                delete pollTimers.current[localId];
                return;
            }
            const delay = elapsed < 5000 ? 1500 : 4000;
            pollTimers.current[localId] = window.setTimeout(tick, delay);
        };

        const tick = async () => {
            let data: CreationResponse;
            try {
                data = await apiGet<CreationResponse>(`/lab/voice/creations/${creationId}/status`);
            } catch {
                scheduleNext();
                return;
            }

            if (data.status === 'completed') {
                delete pollTimers.current[localId];
                const audioUrl = data.audio_url || data.preview_url;
                if (!audioUrl) {
                    setVoices((prev) =>
                        prev.map((v) =>
                            v.id === localId
                                ? { ...v, status: 'failed', error: 'Generation finished without audio.', progress: 'Failed' }
                                : v,
                        ),
                    );
                    setLoading(false);
                    return;
                }

                setVoices((prev) =>
                    prev.map((v) =>
                        v.id === localId
                            ? {
                                  ...v,
                                  id: `voice-${creationId}`,
                                  creationId,
                                  status: 'completed',
                                  progress: 'Completed',
                                  audioUrl,
                                  title: data.prompt.length > 42 ? `${data.prompt.slice(0, 42)}…` : data.prompt,
                                  text: data.prompt,
                                  voice: data.voice ?? v.voice,
                                  model: data.model_name ?? v.model,
                                  error: undefined,
                              }
                            : v,
                    ),
                );
                setLoading(false);
                return;
            }

            if (data.status === 'failed' || data.status === 'cancelled') {
                delete pollTimers.current[localId];
                setVoices((prev) =>
                    prev.map((v) =>
                        v.id === localId
                            ? {
                                  ...v,
                                  status: data.status,
                                  error: data.error || 'Generation failed.',
                                  progress: 'Failed',
                              }
                            : v,
                    ),
                );
                setLoading(false);
                return;
            }

            setVoices((prev) =>
                prev.map((v) =>
                    v.id === localId
                        ? {
                              ...v,
                              status: data.status,
                              progress:
                                  data.progress_message ||
                                  (data.status === 'queued'
                                      ? data.queue_position
                                          ? `In queue (#${data.queue_position})`
                                          : 'In queue'
                                      : 'Generating…'),
                          }
                        : v,
                ),
            );
            scheduleNext();
        };

        scheduleNext();
    }, []);

    pollVoiceRef.current = pollVoice;

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
        for (const id of ids) {
            if (pollTimers.current[id]) {
                window.clearTimeout(pollTimers.current[id]);
                delete pollTimers.current[id];
            }
        }
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
                            {renderCreateForm()}
                        </motion.aside>

                        <div className="flex min-h-[50vh] min-w-0 w-full flex-col md:min-h-0 md:flex-1 md:overflow-hidden">
                            {isMusicLab ? (
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
                            <label className="mb-2 text-xs font-medium text-white/50">Prompt</label>
                            <textarea
                                value={prompt}
                                onChange={(e) => setPrompt(e.target.value)}
                                placeholder={placeholder}
                                className="mb-4 h-40 w-full resize-none rounded-xl border border-white/10 bg-black/40 p-3 text-sm text-white outline-none placeholder:text-white/25 focus:border-brand-500"
                            />
                            <div className="mb-4 rounded-xl border border-white/10 bg-white/[0.03] px-3 py-2 text-xs text-white/50">
                                Model: <span className="text-white/80">{model}</span>
                            </div>
                            <Button variant="creative" className="mt-auto w-full" loading={loading} onClick={() => startGenerate()}>
                                Generate
                            </Button>
                        </motion.aside>

                        <div className="flex min-h-0 min-w-0 flex-1 items-center justify-center p-4 md:p-6">
                            <div className="flex h-full max-h-[520px] w-full max-w-3xl items-center justify-center rounded-2xl border border-dashed border-white/10 bg-white/[0.02] text-sm text-white/30">
                                Preview canvas
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
