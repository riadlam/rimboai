import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { Brand } from '@/types';
import type { CreditsConfig } from '@/lib/imageCredits';
import { estimateVideoCredits } from '@/lib/videoCredits';
import LabFormSkeleton from '@/Components/LabFormSkeleton';
import AssetMentionTextarea, {
    rebasePromptAfterAssetRemoval,
    type AssetMention,
} from '@/Components/AssetMentionTextarea';
import {
    describeMediaGuidance,
    generateBlockReason,
    getMediaCaps,
    mediaTotal,
    resolveMediaRouteMode,
    resolveUploadLimits,
    trimMediaToUploadLimits,
    supportsMediaMix,
    type MediaCounts,
} from '@/lib/videoMediaCaps';
import { pickBestVideoModel, shouldAutoSwitchVideoModel } from '@/lib/videoModelSelection';
import {
    loadDraftMediaFiles,
    matchLabModel,
    parseDurationDraft,
    type LabReuseDraft,
} from '@/lib/labReuse';
import { hasMeaningfulPrompt } from '@/lib/promptText';
import {
    aspectBox,
    pickSupportedValue,
    videoAspectOptions,
    videoResolutionOptions,
} from '@/lib/labModelOptions';

export type VideoGenerateOptions = {
    quantity: number;
    aspect: string;
    resolution: string;
    duration: number | 'auto';
    audio: boolean;
    endpointId?: string;
    modelName?: string;
    routeMode?: 'text-to-video' | 'image-to-video' | 'reference-to-video' | 'first-last-frame-to-video';
    frameMode?: 'first_last';
    negativePrompt?: string;
    imageFiles?: File[];
    videoFiles?: File[];
    audioFiles?: File[];
};

type Props = {
    brands?: Brand[];
    placeholder?: string;
    onGenerate?: (prompt: string, options?: VideoGenerateOptions) => void;
    loading?: boolean;
    creditsConfig?: CreditsConfig;
    tokenBalance?: number;
    /** Apply reuse / use-result from details modal or History */
    draft?: LabReuseDraft | null;
};

const PROMPT_CHIPS = [
    { id: 'dolly', label: 'Dolly in', text: 'slow dolly-in camera move, cinematic depth' },
    { id: 'orbit', label: 'Orbit', text: 'smooth orbital camera around subject' },
    { id: 'neon', label: 'Neon night', text: 'neon city night, reflective wet streets, volumetric light' },
    { id: 'soft', label: 'Soft light', text: 'soft golden-hour lighting, gentle motion, film grain' },
] as const;

type MediaItem = { id: string; url: string; kind: 'image' | 'video' | 'audio'; name: string; file: File };

type DurationSelection = number | 'auto';

type DurationOptions = {
    values: number[];
    allowAuto: boolean;
    min: number;
    max: number;
};

function parseDurationToken(token: string | number): number | 'auto' | null {
    if (token === 'auto' || token === 'Auto') return 'auto';
    const n = typeof token === 'number' ? token : Number.parseInt(String(token).replace(/s$/i, ''), 10);
    return Number.isFinite(n) ? n : null;
}

function buildDurationOptions(enums: Array<string | number> | null | undefined, maxDuration: number | null | undefined): DurationOptions {
    const raw = Array.isArray(enums) ? enums : [];
    let allowAuto = false;
    const parsed: number[] = [];

    for (const token of raw) {
        const value = parseDurationToken(token);
        if (value === 'auto') {
            allowAuto = true;
            continue;
        }
        if (typeof value === 'number') parsed.push(value);
    }

    let values = [...new Set(parsed)].sort((a, b) => a - b);

    if (values.length === 0) {
        const max = maxDuration && maxDuration > 0 ? maxDuration : 15;
        const min = Math.min(4, max);
        values = Array.from({ length: max - min + 1 }, (_, i) => min + i);
    }

    return {
        values,
        allowAuto,
        min: values[0],
        max: values[values.length - 1],
    };
}

function pickDurationForOptions(options: DurationOptions, current: DurationSelection): DurationSelection {
    if (current === 'auto') {
        return options.allowAuto ? 'auto' : options.values.includes(5) ? 5 : options.values[Math.floor(options.values.length / 2)] ?? options.max;
    }
    if (options.values.includes(current)) return current;
    // Prefer nearest allowed value
    let best = options.values[0];
    let bestDist = Math.abs(best - current);
    for (const v of options.values) {
        const dist = Math.abs(v - current);
        if (dist < bestDist) {
            best = v;
            bestDist = dist;
        }
    }
    return best;
}

export default function VideoLabCreateForm({
    brands = [],
    placeholder,
    onGenerate,
    loading = false,
    creditsConfig,
    tokenBalance = 0,
    draft = null,
}: Props) {
    const { t } = useTranslation('lab');
    const resolvedPlaceholder = placeholder ?? t('video.placeholder');
    const [prompt, setPrompt] = useState('');
    const [negativePrompt, setNegativePrompt] = useState('');
    const [expanded, setExpanded] = useState(false);
    const [modelOpen, setModelOpen] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(true);
    const [dragOver, setDragOver] = useState(false);
    const [selectedBrand, setSelectedBrand] = useState(brands[0]?.name || 'Studio');
    const [selectedModel, setSelectedModel] = useState(brands[0]?.models[0]?.name || 'Seedance 2.0');
    const [duration, setDuration] = useState<DurationSelection>(() => {
        const first = brands[0]?.models[0];
        const opts = buildDurationOptions(first?.enums ?? null, first?.max_duration ?? null);
        return opts.allowAuto ? 'auto' : opts.values.includes(5) ? 5 : opts.max;
    });
    const [audioOn, setAudioOn] = useState(true);
    const [resolution, setResolution] = useState('720p');
    const [aspect, setAspect] = useState('16:9');
    const [media, setMedia] = useState<MediaItem[]>([]);
    const [framesMode, setFramesMode] = useState(false);
    const [firstFrame, setFirstFrame] = useState<MediaItem | null>(null);
    const [lastFrame, setLastFrame] = useState<MediaItem | null>(null);
    const firstFrameInputId = useId();
    const lastFrameInputId = useId();
    const firstFrameRef = useRef<HTMLInputElement>(null);
    const lastFrameRef = useRef<HTMLInputElement>(null);
    const assetMentions = useMemo<AssetMention[]>(() => {
        const indexes: Record<MediaItem['kind'], number> = { image: 0, video: 0, audio: 0 };
        return media.map((item) => {
            indexes[item.kind] += 1;
            return {
                alias: `@${item.kind}${indexes[item.kind]}`,
                kind: item.kind,
                name: item.name,
                previewUrl: item.url,
            };
        });
    }, [media]);
    const [switchNotice, setSwitchNotice] = useState<string | null>(null);
    /** When true, respect manual model pick until media mix changes enough to unlock. */
    const userModelLocked = useRef(false);
    const lastMediaKey = useRef('');
    const [guidanceDismissed, setGuidanceDismissed] = useState(false);
    const [mediaNotice, setMediaNotice] = useState<string | null>(null);
    const [draftNotice, setDraftNotice] = useState<string | null>(null);
    const [draftLoading, setDraftLoading] = useState(false);
    const pendingDraftAudio = useRef<boolean | null>(null);
    const fileRef = useRef<HTMLInputElement>(null);
    const mediaInputId = useId();
    const mediaRef = useRef<MediaItem[]>(media);
    mediaRef.current = media;

    const allModels = useMemo(
        () =>
            brands.flatMap((brand) =>
                brand.models.map((m) => ({
                    ...m,
                    brandName: brand.name,
                    brandIcon: brand.icon,
                })),
            ),
        [brands],
    );

    const mediaCounts = useMemo<MediaCounts>(() => {
        if (framesMode) {
            return {
                images: (firstFrame ? 1 : 0) + (lastFrame ? 1 : 0),
                videos: 0,
                audios: 0,
            };
        }
        return {
            images: media.filter((m) => m.kind === 'image').length,
            videos: media.filter((m) => m.kind === 'video').length,
            audios: media.filter((m) => m.kind === 'audio').length,
        };
    }, [framesMode, firstFrame, lastFrame, media]);

    const modelsForPicker = useMemo(() => {
        const frameMode = framesMode ? 'first_last' : 'default';
        const compatible = allModels.filter((m) => {
            // Tools live under /tools — never list them in Video Lab.
            if (m.tool_slug) return false;
            // Frames toggle on → only models that can do first+last, even before images are added.
            if (framesMode && !getMediaCaps(m).supports_last_frame) return false;
            return supportsMediaMix(m, mediaCounts, frameMode);
        });
        return [...compatible].sort((a, b) => {
            const sa = pickBestVideoModel([a], mediaCounts, prompt)?.score ?? 0;
            const sb = pickBestVideoModel([b], mediaCounts, prompt)?.score ?? 0;
            return sb - sa;
        });
    }, [allModels, mediaCounts, prompt, framesMode]);

    const labModelsForPicker = modelsForPicker;

    const selectedModelRecord = useMemo(
        () => allModels.find((m) => m.name === selectedModel),
        [allModels, selectedModel],
    );

    const uploadLimits = useMemo(
        () => resolveUploadLimits(selectedModelRecord?.tool_slug ? null : selectedModelRecord),
        [selectedModelRecord],
    );

    const selectedMeta =
        selectedModelRecord ||
        labModelsForPicker[0] ||
        modelsForPicker[0] ||
        allModels[0] ||
        ({
            name: selectedModel,
            description: 'Cinematic text-to-video generation',
            brandName: selectedBrand,
            brandIcon: null as string | null,
            icon: null as string | null,
            tags: [] as string[],
            enums: null as Array<string | number> | null,
            max_duration: null as number | null,
            endpoint_id: '',
        } as const);

    const selectedEndpointId = 'endpoint_id' in selectedMeta ? selectedMeta.endpoint_id || selectedMeta.name : selectedMeta.name;
    const selectedEnums = 'enums' in selectedMeta ? selectedMeta.enums : null;
    const selectedMaxDuration = 'max_duration' in selectedMeta ? selectedMeta.max_duration : null;

    const availableAspects = useMemo(
        () =>
            videoAspectOptions(
                selectedModelRecord && 'aspect_ratios' in selectedModelRecord
                    ? selectedModelRecord.aspect_ratios
                    : null,
            ),
        [selectedModelRecord],
    );

    const availableResolutions = useMemo(
        () =>
            videoResolutionOptions(
                selectedModelRecord && 'resolutions' in selectedModelRecord
                    ? selectedModelRecord.resolutions
                    : null,
            ),
        [selectedModelRecord],
    );

    useEffect(() => {
        setAspect((prev) => pickSupportedValue(prev, availableAspects, '16:9'));
        setResolution((prev) =>
            pickSupportedValue(
                prev,
                availableResolutions.map((r) => r.id),
                '720p',
            ),
        );
    }, [availableAspects, availableResolutions]);

    const routeMode = resolveMediaRouteMode(
        selectedModelRecord,
        mediaCounts,
        framesMode ? 'first_last' : 'default',
    );

    const catalogHasFramesModels = useMemo(
        () => allModels.some((m) => getMediaCaps(m).supports_last_frame),
        [allModels],
    );

    const durationOptions = useMemo(
        () => {
            // veo3.x reference-to-video only accepts 8s (fal enforces it despite the shared schema).
            if (selectedEndpointId.toLowerCase().includes('veo') && routeMode === 'reference-to-video') {
                return { values: [8], allowAuto: false, min: 8, max: 8 };
            }
            return buildDurationOptions(selectedEnums, selectedMaxDuration);
        },
        // Recompute only when the chosen model’s duration schema or resolved route changes
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [selectedEndpointId, JSON.stringify(selectedEnums), selectedMaxDuration, routeMode],
    );

    const durationStops = useMemo<DurationSelection[]>(
        () => (durationOptions.allowAuto ? ['auto', ...durationOptions.values] : durationOptions.values),
        [durationOptions],
    );

    const durationIndex = Math.max(
        0,
        durationStops.findIndex((stop) => stop === duration),
    );

    const durationSeconds = duration === 'auto' ? durationOptions.max : duration;
    const durationLabel = duration === 'auto' ? t('auto') : `${duration}s`;

    const supportsAudio = selectedModelRecord?.supports_audio === true;
    const supportsNegativePrompt = selectedModelRecord?.supports_negative_prompt === true;
    const effectiveAudio = supportsAudio && audioOn;

    const creditEstimate = useMemo(
        () =>
            estimateVideoCredits(
                selectedModelRecord,
                {
                    durationSeconds,
                    audio: effectiveAudio,
                    resolution,
                    aspect,
                },
                creditsConfig,
            ),
        [selectedModelRecord, durationSeconds, effectiveAudio, resolution, aspect, creditsConfig],
    );
    const creditCost = creditEstimate.credits;
    const hasEnoughTokens = creditCost > 0 && tokenBalance >= creditCost;

    const mediaGuidance = describeMediaGuidance(
        mediaCounts,
        modelsForPicker.length,
        framesMode ? 'first_last' : 'default',
    );
    const showInfoGuidance = Boolean(mediaGuidance && mediaGuidance.tone !== 'error' && !guidanceDismissed);
    const showErrorGuidance = Boolean(mediaGuidance && mediaGuidance.tone === 'error');
    const rawBlockReason = generateBlockReason(
        hasMeaningfulPrompt(prompt),
        mediaCounts,
        selectedModelRecord,
        modelsForPicker.length,
        framesMode ? 'first_last' : 'default',
    );
    const blockReason = !rawBlockReason
        ? null
        : rawBlockReason === 'Add a prompt to generate.'
          ? t('video.blockPrompt')
          : rawBlockReason === 'Add an image to animate.'
            ? t('video.blockNeedImage')
          : rawBlockReason.startsWith('Add an image or video')
            ? t('video.blockAudioAlone')
            : rawBlockReason === 'No model supports this media mix. Remove some references.'
              ? t('video.blockMix')
              : rawBlockReason === 'Add a first-frame image.'
                ? t('video.needFirstFrame')
                : rawBlockReason === 'Add a last-frame image.'
                  ? t('video.needLastFrame')
                  : rawBlockReason.startsWith("This model doesn't support first")
                    ? t('video.framesUnsupported')
                    : rawBlockReason;
    const canGenerate = !blockReason && routeMode !== null && hasEnoughTokens;
    const durationPct =
        durationStops.length <= 1 ? 100 : (durationIndex / (durationStops.length - 1)) * 100;

    useEffect(() => {
        if (brands[0] && !brands.find((b) => b.name === selectedBrand)) {
            setSelectedBrand(brands[0].name);
            setSelectedModel(brands[0].models[0]?.name || 'Seedance 2.0');
        }
    }, [brands, selectedBrand]);

    useEffect(() => {
        setDuration((current) => pickDurationForOptions(durationOptions, current));
    }, [durationOptions]);

    // Unlock auto-routing when the media mix shape changes (user added/removed refs).
    useEffect(() => {
        const key = `${mediaCounts.images}:${mediaCounts.videos}:${mediaCounts.audios}`;
        if (lastMediaKey.current && lastMediaKey.current !== key) {
            userModelLocked.current = false;
        }
        lastMediaKey.current = key;
    }, [mediaCounts.images, mediaCounts.videos, mediaCounts.audios]);

    // Auto-switch to a safer model for this media + prompt (hides incompatible in picker).
    useEffect(() => {
        if (labModelsForPicker.length === 0) return;

        const best = pickBestVideoModel(labModelsForPicker, mediaCounts, prompt);
        if (!best) return;

        const decision = shouldAutoSwitchVideoModel(
            selectedModelRecord,
            best,
            mediaCounts,
            prompt,
            userModelLocked.current,
        );

        if (!decision.switch) return;
        if (best.model.name === selectedModel && best.model.brandName === selectedBrand) return;

        setSelectedBrand(best.model.brandName);
        setSelectedModel(best.model.name);
        setSwitchNotice(
            decision.reason.replace(best.model.name, formatModelName(best.model.name)),
        );
    }, [labModelsForPicker, mediaCounts, prompt, selectedModel, selectedBrand, selectedModelRecord]);

    // Auto-hide switch banner; clear immediately when all refs are removed
    useEffect(() => {
        if (!switchNotice) return;
        if (mediaTotal(mediaCounts) === 0) {
            setSwitchNotice(null);
            return;
        }
        const t = window.setTimeout(() => setSwitchNotice(null), 5500);
        return () => window.clearTimeout(t);
    }, [switchNotice, mediaCounts]);

    // Re-show info guidance when the media mix shape changes
    useEffect(() => {
        setGuidanceDismissed(false);
    }, [mediaCounts.images, mediaCounts.videos, mediaCounts.audios, framesMode]);

    const revokeFrame = (item: MediaItem | null) => {
        if (item?.url) URL.revokeObjectURL(item.url);
    };

    /** Move images from the general upload tray into first/last frame slots. */
    const promoteMediaToFrames = (items: MediaItem[]) => {
        const images = items.filter((m) => m.kind === 'image');
        const rest = items.filter((m) => m.kind !== 'image');

        const nextFirst = images[0] ?? null;
        const nextLast = images[1] ?? null;

        setFirstFrame((prev) => {
            if (prev && prev.id !== nextFirst?.id) revokeFrame(prev);
            return nextFirst;
        });
        setLastFrame((prev) => {
            if (prev && prev.id !== nextLast?.id) revokeFrame(prev);
            return nextLast;
        });

        // Drop leftover images (3+) and any video/audio refs — frames mode is image-only.
        [...images.slice(2), ...rest].forEach((m) => URL.revokeObjectURL(m.url));
        setMedia([]);
    };

    const setFrameFromFile = (slot: 'first' | 'last', file: File | null) => {
        if (!file) return;
        if (detectMediaKind(file) !== 'image') {
            setMediaNotice('First & last frames need an image (JPG, PNG, WEBP, or GIF).');
            return;
        }
        setMediaNotice(null);
        const next: MediaItem = {
            id: `frame-${slot}-${Date.now()}`,
            url: URL.createObjectURL(file),
            kind: 'image',
            name: file.name,
            file,
        };
        if (slot === 'first') {
            setFirstFrame((prev) => {
                revokeFrame(prev);
                return next;
            });
        } else {
            setLastFrame((prev) => {
                revokeFrame(prev);
                return next;
            });
        }
    };

    const clearFrame = (slot: 'first' | 'last') => {
        if (slot === 'first') {
            setFirstFrame((prev) => {
                revokeFrame(prev);
                return null;
            });
        } else {
            setLastFrame((prev) => {
                revokeFrame(prev);
                return null;
            });
        }
    };

    const enableFramesMode = () => {
        const before = mediaRef.current;
        const imageCount = before.filter((m) => m.kind === 'image').length;
        promoteMediaToFrames(before);
        setFramesMode(true);
        userModelLocked.current = false;

        if (getMediaCaps(selectedModelRecord).supports_last_frame) {
            return;
        }

        const flfModels = allModels.filter((m) => getMediaCaps(m).supports_last_frame);
        if (flfModels.length === 0) {
            setMediaNotice(t('video.framesUnsupported'));
            return;
        }

        const pickCounts: MediaCounts = {
            images: Math.min(2, imageCount),
            videos: 0,
            audios: 0,
        };

        const best =
            pickBestVideoModel(flfModels, pickCounts, prompt) ??
            ({ model: flfModels[0], score: 0 } as const);

        setSelectedBrand(best.model.brandName);
        setSelectedModel(best.model.name);
        setSwitchNotice(
            t('video.framesSwitchedModel', {
                model: formatModelName(best.model.name),
                defaultValue: `Switched to ${formatModelName(best.model.name)} for first & last frames`,
            }),
        );
    };

    const disableFramesMode = () => {
        setFramesMode(false);
        setFirstFrame((prev) => {
            revokeFrame(prev);
            return null;
        });
        setLastFrame((prev) => {
            revokeFrame(prev);
            return null;
        });
        userModelLocked.current = false;
    };

    // Audio toggle only applies to models with generate_audio
    useEffect(() => {
        if (!supportsAudio) {
            setAudioOn(false);
            return;
        }
        if (pendingDraftAudio.current !== null) {
            setAudioOn(pendingDraftAudio.current);
            pendingDraftAudio.current = null;
            return;
        }
    }, [supportsAudio, selectedEndpointId]);

    // Apply reuse / use-result drafts from details modal or History
    useEffect(() => {
        if (!draft || draft.lab !== 'video') return;

        let cancelled = false;
        const apply = async () => {
            setDraftLoading(true);
            setSettingsOpen(true);
            setModelOpen(false);
            setPrompt(draft.intent === 'reuse-settings' ? draft.prompt || '' : '');

            const matched = matchLabModel(allModels, {
                modelName: draft.modelName,
                endpointId: draft.endpointId,
            });
            if (matched) {
                setSelectedBrand(matched.brandName);
                setSelectedModel(matched.name);
            }

            const draftAspects = videoAspectOptions(matched?.aspect_ratios);
            const draftResolutions = videoResolutionOptions(matched?.resolutions);
            if (draft.aspect && draftAspects.includes(draft.aspect)) {
                setAspect(draft.aspect);
            }
            if (draft.resolution && draftResolutions.some((r) => r.id === draft.resolution)) {
                setResolution(draft.resolution);
            }

            const parsedDuration = parseDurationDraft(draft.duration);
            if (parsedDuration !== null) {
                setDuration(parsedDuration);
            }

            pendingDraftAudio.current = draft.audio ?? true;
            setAudioOn(Boolean(draft.audio ?? true));

            // Clear previous media
            mediaRef.current.forEach((m) => URL.revokeObjectURL(m.url));
            mediaRef.current = [];
            setMedia([]);

            try {
                if (draft.media.length > 0) {
                    const loaded = await loadDraftMediaFiles(draft.media);
                    if (cancelled) return;
                    const next: MediaItem[] = loaded.files.map((file, i) => {
                        const kind =
                            loaded.kinds[i] ||
                            (file.type.startsWith('video/')
                                ? 'video'
                                : file.type.startsWith('audio/')
                                  ? 'audio'
                                  : 'image');
                        return {
                            id: `${Date.now()}-${file.name}-${i}`,
                            url: URL.createObjectURL(file),
                            kind,
                            name: file.name,
                            file,
                        };
                    });
                    mediaRef.current = next;
                    setMedia(next);

                    if (loaded.failed > 0 && loaded.files.length === 0) {
                        setDraftNotice(t('settingsRestored'));
                    } else if (draft.intent === 'use-last-frame') {
                        setDraftNotice(t('lastFrameAttached'));
                    } else if (loaded.failed > 0) {
                        setDraftNotice(t('settingsRestored'));
                    } else {
                        setDraftNotice(t('settingsRestored'));
                    }
                }
            } catch {
                // Draft media restore is best-effort.
            } finally {
                if (!cancelled) setDraftLoading(false);
            }
        };

        void apply();
        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [draft?.id]);

    const chooseModel = (m: {
        name: string;
        brandName: string;
        tool_slug?: string | null;
        media_capabilities?: (typeof allModels)[number]['media_capabilities'];
    }) => {
        userModelLocked.current = true;
        setSelectedBrand(m.brandName);
        setSelectedModel(m.name);

        // If this model only allows 3–4 refs (etc.), drop extras so generate stays valid.
        const limits = resolveUploadLimits(m);
        const { items, trimmed } = trimMediaToUploadLimits(mediaRef.current, limits, (url) => {
            URL.revokeObjectURL(url);
        });
        if (trimmed > 0) {
            mediaRef.current = items;
            setMedia(items);
            setMediaNotice(
                `Trimmed ${trimmed} reference${trimmed === 1 ? '' : 's'} — ${m.name} allows up to ${limits.image} images, ${limits.video} videos, ${limits.audio} audio.`,
            );
            setSwitchNotice(null);
        } else {
            setSwitchNotice(null);
        }
        setModelOpen(false);
    };

    // Keep attachments inside the selected model's ref ceiling (manual pick + auto-switch).
    useEffect(() => {
        if (!selectedModelRecord || selectedModelRecord.tool_slug) return;
        const limits = resolveUploadLimits(selectedModelRecord);
        const prev = mediaRef.current;
        const { items, trimmed } = trimMediaToUploadLimits(prev, limits, (url) => {
            URL.revokeObjectURL(url);
        });
        if (trimmed === 0) return;
        mediaRef.current = items;
        setMedia(items);
        setMediaNotice(
            `Trimmed ${trimmed} reference${trimmed === 1 ? '' : 's'} — ${selectedModelRecord.name} allows up to ${limits.image} images, ${limits.video} videos, ${limits.audio} audio.`,
        );
    }, [selectedModelRecord]);

    const addMedia = (files: FileList | null) => {
        if (!files || files.length === 0) return;

        const prev = mediaRef.current;
        const counts = {
            image: prev.filter((m) => m.kind === 'image').length,
            video: prev.filter((m) => m.kind === 'video').length,
            audio: prev.filter((m) => m.kind === 'audio').length,
        };
        const next: MediaItem[] = [];
        let skippedUnsupported = 0;
        let skippedLimit = 0;
        let skippedType = 0;

        for (const file of Array.from(files)) {
            const kind = detectMediaKind(file);
            if (!kind) {
                skippedUnsupported += 1;
                continue;
            }
            if (uploadLimits[kind] <= 0) {
                skippedType += 1;
                continue;
            }
            if (counts[kind] >= uploadLimits[kind]) {
                skippedLimit += 1;
                continue;
            }
            counts[kind] += 1;
            next.push({
                id: `${Date.now()}-${file.name}-${Math.random().toString(36).slice(2, 6)}`,
                url: URL.createObjectURL(file),
                kind,
                name: file.name,
                file,
            });
        }

        if (next.length) {
            const merged = [...prev, ...next];
            mediaRef.current = merged;
            setMedia(merged);
        }

        if (skippedUnsupported > 0) {
            setMediaNotice('Only JPG, PNG, WEBP, GIF, MP4, WEBM, MOV, MP3, or WAV files are supported.');
        } else if (skippedType > 0) {
            setMediaNotice(
                `${selectedModelRecord?.name || 'This model'} doesn’t accept that media type. Pick a multimodal model or remove unsupported files.`,
            );
        } else if (skippedLimit > 0) {
            setMediaNotice(
                `Some files were skipped — ${selectedModelRecord?.name || 'this model'} allows up to ${uploadLimits.image} images, ${uploadLimits.video} videos, ${uploadLimits.audio} audio.`,
            );
        } else {
            setMediaNotice(null);
        }
    };

    const removeMedia = (id: string) => {
        const target = media.find((item) => item.id === id);
        if (target) {
            const removedIndex = media.filter((item) => item.kind === target.kind).findIndex((item) => item.id === id) + 1;
            setPrompt((value) => rebasePromptAfterAssetRemoval(value, target.kind, removedIndex));
        }
        setMedia((prev) => {
            const removed = prev.find((m) => m.id === id);
            if (removed) URL.revokeObjectURL(removed.url);
            return prev.filter((m) => m.id !== id);
        });
        setSwitchNotice(null);
    };

    const downloadMedia = (item: MediaItem) => {
        const objectUrl = URL.createObjectURL(item.file);
        const a = document.createElement('a');
        a.href = objectUrl;
        a.download = item.name || `${item.kind}-${item.id}`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(objectUrl);
    };

    const appendChip = (text: string) => {
        setPrompt((p) => {
            const t = p.trim();
            if (!t) return text;
            if (t.toLowerCase().includes(text.toLowerCase())) return t;
            return `${t}, ${text}`;
        });
    };

    return (
        <div className="relative flex flex-col bg-[#0a0a0f] md:h-full md:min-h-0 [&_a]:cursor-pointer [&_button]:cursor-pointer [&_button:disabled]:cursor-not-allowed [&_label]:cursor-pointer">
            <div className="pointer-events-none absolute inset-x-0 top-0 h-44 bg-[radial-gradient(ellipse_at_top,rgba(255,87,51,0.2),transparent_70%)]" />
            <div className="pointer-events-none absolute inset-x-6 top-24 h-24 rounded-full bg-[#FF5733]/10 blur-3xl" />

            <AnimatePresence>
                {draftLoading && (
                    <LabFormSkeleton
                        label={
                            draft?.intent === 'use-last-frame'
                                ? t('attachingLastFrame')
                                : draft?.intent === 'use-result'
                                  ? t('attaching')
                                  : t('restoring')
                        }
                    />
                )}
            </AnimatePresence>

            <div
                className={`relative transition-opacity duration-200 md:min-h-0 md:flex-1 md:overflow-y-auto md:scrollbar-thin ${
                    draftLoading ? 'pointer-events-none opacity-70' : 'opacity-100'
                }`}
            >
                {/* Hero header */}
                <div className="space-y-3.5 px-3 py-3">
                    {!draftLoading && draftNotice && (
                        <div className="flex items-start justify-between gap-2 rounded-xl border border-orange-400/25 bg-orange-500/10 px-3 py-2 text-[11px] leading-snug text-orange-100/90">
                            <p className="min-w-0 flex-1">{draftNotice}</p>
                            <button
                                type="button"
                                onClick={() => setDraftNotice(null)}
                                className="shrink-0 text-orange-100/50 hover:text-orange-100"
                                aria-label={t('dismiss')}
                            >
                                ×
                            </button>
                        </div>
                    )}
                    {/* Select Model — cinematic card */}
                    <div className="space-y-2">
                        <SectionLabel>{t('selectModel')}</SectionLabel>
                        <button
                            type="button"
                            onClick={() => setModelOpen(true)}
                            className="group relative min-h-[72px] w-full cursor-pointer overflow-hidden rounded-2xl border border-white/[0.08] text-left shadow-[0_0_0_1px_rgba(255,255,255,0.02)_inset] transition hover:border-orange-400/35"
                        >
                            <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_80%_20%,rgba(255,87,51,0.35),transparent_55%),linear-gradient(135deg,#1a1020_0%,#0d0d14_50%,#12101a_100%)]" />
                            <div className="absolute inset-0 opacity-40 mix-blend-overlay [background-image:url('data:image/svg+xml;utf8,<svg viewBox=%220 0 200 200%22 xmlns=%22http://www.w3.org/2000/svg%22><filter id=%22n%22><feTurbulence type=%22fractalNoise%22 baseFrequency=%220.9%22 numOctaves=%222%22 stitchTiles=%22stitch%22/></filter><rect width=%22100%25%22 height=%22100%25%22 filter=%22url(%23n)%22 opacity=%220.35%22/></svg>')]" />
                            <div className="absolute inset-0 bg-gradient-to-r from-[#0a0a0f]/95 via-[#0a0a0f]/55 to-transparent" />
                            <motion.div
                                className="pointer-events-none absolute -left-10 top-0 h-full w-24 bg-gradient-to-r from-transparent via-white/10 to-transparent"
                                animate={{ x: ['0%', '420%'] }}
                                transition={{ duration: 4.5, repeat: Infinity, ease: 'easeInOut', repeatDelay: 2 }}
                            />
                            <div className="relative flex items-center justify-between gap-3 p-3.5">
                                <div className="flex min-w-0 items-center gap-3">
                                    <ModelAvatar name={selectedMeta.name} icon={'icon' in selectedMeta ? selectedMeta.icon : null} brandIcon={selectedMeta.brandIcon} />
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-semibold text-white">{formatModelName(selectedMeta.name)}</p>
                                        <p className="mt-0.5 line-clamp-1 text-[11px] text-white/40">
                                            {selectedMeta.description || selectedMeta.brandName}
                                        </p>
                                    </div>
                                </div>
                                <div className="flex shrink-0 items-center gap-2">
                                    {creditCost > 0 && (
                                        <span className="inline-flex items-center gap-1 rounded-lg border border-orange-400/25 bg-orange-500/10 px-2 py-1 text-[11px] font-medium tabular-nums text-orange-200">
                                            <CreditBoltIcon className="h-3 w-3 text-amber-300" />
                                            {creditCost}
                                        </span>
                                    )}
                                    <span className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-white/15 bg-white/[0.1] px-3 text-[12px] font-medium text-zinc-100 backdrop-blur-sm transition group-hover:border-orange-400/40 group-hover:bg-orange-500/15 group-hover:text-orange-100">
                                        Switch
                                        <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
                                            <path d="M5 12h14" />
                                            <path d="m12 5 7 7-7 7" />
                                        </svg>
                                    </span>
                                </div>
                            </div>
                        </button>
                    </div>

                    {/* Upload media / First–last frames */}
                    <div className="relative">
                        {catalogHasFramesModels && (
                            <div className="mb-2 flex items-center justify-end">
                                <button
                                    type="button"
                                    role="switch"
                                    aria-checked={framesMode}
                                    onClick={() => (framesMode ? disableFramesMode() : enableFramesMode())}
                                    className="group inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.03] py-1 pe-1.5 ps-2.5 text-[11px] font-medium text-zinc-300 transition hover:border-orange-400/35 hover:bg-orange-500/[0.06] hover:text-orange-100"
                                >
                                    <span className="text-zinc-400 group-hover:text-orange-200/90">{t('video.framesToggle')}</span>
                                    <span
                                        className={`relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition ${
                                            framesMode ? 'bg-[#FF5733]' : 'bg-white/15'
                                        }`}
                                    >
                                        <span
                                            className={`inline-block h-3.5 w-3.5 rounded-full bg-white shadow transition ${
                                                framesMode ? 'translate-x-[18px]' : 'translate-x-[3px]'
                                            }`}
                                        />
                                    </span>
                                </button>
                            </div>
                        )}

                        <AnimatePresence mode="wait" initial={false}>
                            {framesMode ? (
                                <motion.div
                                    key="frames"
                                    initial={{ opacity: 0, y: 8, height: 0 }}
                                    animate={{ opacity: 1, y: 0, height: 'auto' }}
                                    exit={{ opacity: 0, y: -6, height: 0 }}
                                    transition={{ duration: 0.22, ease: 'easeOut' }}
                                    className="overflow-hidden"
                                >
                                    <div className="rounded-2xl border border-white/[0.08] bg-gradient-to-b from-white/[0.05] to-white/[0.02] p-3 shadow-[0_0_0_1px_rgba(255,255,255,0.02)_inset]">
                                        <div className="mb-2.5 flex items-center justify-between px-0.5">
                                            <div>
                                                <p className="text-xs font-medium text-white/70">{t('video.framesToggleHint')}</p>
                                                <p className="text-[10px] text-white/35">{t('video.framesHint')}</p>
                                            </div>
                                        </div>
                                        <div className="grid grid-cols-2 gap-2.5">
                                            <FrameSlot
                                                label={t('video.firstFrame')}
                                                hint={t('video.addFirstFrame')}
                                                item={firstFrame}
                                                inputId={firstFrameInputId}
                                                onClear={() => clearFrame('first')}
                                            />
                                            <FrameSlot
                                                label={t('video.lastFrame')}
                                                hint={t('video.addLastFrame')}
                                                item={lastFrame}
                                                inputId={lastFrameInputId}
                                                onClear={() => clearFrame('last')}
                                            />
                                        </div>
                                        <input
                                            id={firstFrameInputId}
                                            ref={firstFrameRef}
                                            type="file"
                                            accept="image/jpeg,image/jpg,image/png,image/webp,image/gif,.jpg,.jpeg,.png,.webp,.gif"
                                            className="sr-only"
                                            onChange={(e) => {
                                                setFrameFromFile('first', e.target.files?.[0] ?? null);
                                                e.target.value = '';
                                            }}
                                        />
                                        <input
                                            id={lastFrameInputId}
                                            ref={lastFrameRef}
                                            type="file"
                                            accept="image/jpeg,image/jpg,image/png,image/webp,image/gif,.jpg,.jpeg,.png,.webp,.gif"
                                            className="sr-only"
                                            onChange={(e) => {
                                                setFrameFromFile('last', e.target.files?.[0] ?? null);
                                                e.target.value = '';
                                            }}
                                        />
                                    </div>
                                </motion.div>
                            ) : (
                                <motion.div
                                    key="media"
                                    initial={{ opacity: 0, y: 8, height: 0 }}
                                    animate={{ opacity: 1, y: 0, height: 'auto' }}
                                    exit={{ opacity: 0, y: -6, height: 0 }}
                                    transition={{ duration: 0.22, ease: 'easeOut' }}
                                    className="overflow-hidden"
                                    onDragOver={(e) => {
                                        e.preventDefault();
                                        setDragOver(true);
                                    }}
                                    onDragLeave={() => setDragOver(false)}
                                    onDrop={(e) => {
                                        e.preventDefault();
                                        setDragOver(false);
                                        addMedia(e.dataTransfer.files);
                                    }}
                                >
                        {media.length === 0 ? (
                            <label
                                htmlFor={mediaInputId}
                                className={`group relative flex w-full cursor-pointer flex-col items-center justify-center gap-3.5 rounded-2xl border border-dashed px-4 py-7 transition ${
                                    dragOver
                                        ? 'border-orange-400/50 bg-orange-500/[0.08]'
                                        : 'border-white/15 bg-gradient-to-b from-white/[0.05] to-white/[0.02] hover:border-orange-400/40 hover:bg-orange-500/[0.04]'
                                }`}
                            >
                                <div className="flex items-center gap-2.5">
                                    <MediaBadge delay={0} label="Image">
                                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
                                            <rect width="18" height="18" x="3" y="3" rx="2" ry="2" />
                                            <circle cx="9" cy="9" r="2" />
                                            <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" />
                                        </svg>
                                    </MediaBadge>
                                    <MediaBadge delay={0.08} label="Video">
                                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
                                            <rect width="18" height="18" x="3" y="3" rx="2" />
                                            <path d="M7 3v18" />
                                            <path d="M3 7.5h4" />
                                            <path d="M3 12h18" />
                                            <path d="M3 16.5h4" />
                                            <path d="M17 3v18" />
                                            <path d="M17 7.5h4" />
                                            <path d="M17 16.5h4" />
                                        </svg>
                                    </MediaBadge>
                                    <MediaBadge delay={0.16} label="Audio">
                                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
                                            <path d="M9 18V5l12-2v13" />
                                            <circle cx="6" cy="18" r="3" />
                                            <circle cx="18" cy="16" r="3" />
                                        </svg>
                                    </MediaBadge>
                                </div>
                                <div className="text-center">
                                    <p className="text-sm font-semibold text-white">{t('video.uploadMedia')}</p>
                                    <p className="mt-1 text-xs text-white/40">
                                        Image, video, or audio · up to {uploadLimits.image} images / {uploadLimits.video}{' '}
                                        videos / {uploadLimits.audio} audio
                                        {selectedModelRecord?.name
                                            ? ` for ${formatModelName(selectedModelRecord.name)}`
                                            : ''}
                                    </p>
                                </div>
                            </label>
                        ) : (
                            <div className="overflow-hidden rounded-2xl border border-white/[0.08] bg-gradient-to-b from-white/[0.05] to-white/[0.02] p-3 shadow-[0_0_0_1px_rgba(255,255,255,0.02)_inset]">
                                <div className="mb-2.5 flex items-center justify-between px-0.5">
                                    <span className="text-xs font-medium text-white/60">
                                        Media · {mediaCounts.images}img · {mediaCounts.videos}vid · {mediaCounts.audios}aud
                                    </span>
                                    {(mediaCounts.images < uploadLimits.image ||
                                        mediaCounts.videos < uploadLimits.video ||
                                        mediaCounts.audios < uploadLimits.audio) && (
                                        <label
                                            htmlFor={mediaInputId}
                                            className="cursor-pointer text-[11px] font-medium text-orange-300 hover:text-orange-200"
                                        >
                                            + Add
                                        </label>
                                    )}
                                </div>
                                <div className="flex gap-2 overflow-x-auto pb-1 scrollbar-thin">
                                    {media.map((m, index) => (
                                        <div
                                            key={m.id}
                                            className="group/thumb relative h-[72px] w-[72px] shrink-0 overflow-hidden rounded-xl bg-black/40 ring-1 ring-white/10"
                                        >
                                            {m.kind === 'image' ? (
                                                <img src={m.url} alt="" className="size-full object-cover" />
                                            ) : m.kind === 'video' ? (
                                                <video src={m.url} className="size-full object-cover" muted playsInline />
                                            ) : (
                                                <div className="flex size-full flex-col items-center justify-center gap-1 bg-gradient-to-br from-violet-500/20 to-orange-500/10">
                                                    <svg className="h-5 w-5 text-orange-200/80" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                                        <path d="M9 18V5l12-2v13" />
                                                        <circle cx="6" cy="18" r="3" />
                                                        <circle cx="18" cy="16" r="3" />
                                                    </svg>
                                                    <span className="max-w-[56px] truncate text-[9px] text-white/50">{m.name}</span>
                                                </div>
                                            )}
                                            <span className="absolute bottom-1 start-1 rounded bg-black/70 px-1 py-px text-[9px] font-semibold text-orange-200 backdrop-blur-sm">
                                                {assetMentions[index]?.alias ?? m.kind}
                                            </span>
                                            {m.kind === 'image' && (
                                                <button
                                                    type="button"
                                                    onClick={() => downloadMedia(m)}
                                                    aria-label="Download image"
                                                    title="Download"
                                                    className="absolute end-1 bottom-1 z-10 flex h-6 w-6 items-center justify-center rounded-full bg-black/75 text-white ring-1 ring-white/20 md:h-5 md:w-5 md:opacity-0 md:transition md:group-hover/thumb:opacity-100"
                                                >
                                                    <svg className="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
                                                        <path d="M12 4v10.5" />
                                                        <path d="m8.5 11.5 3.5 3.5 3.5-3.5" />
                                                        <path d="M5 17.5v.5A2 2 0 0 0 7 20h10a2 2 0 0 0 2-2v-.5" />
                                                    </svg>
                                                </button>
                                            )}
                                            <button
                                                type="button"
                                                onClick={() => removeMedia(m.id)}
                                                aria-label="Remove media"
                                                className="absolute end-1 top-1 z-10 flex h-6 w-6 items-center justify-center rounded-full bg-black/75 text-xs text-white ring-1 ring-white/20 md:h-5 md:w-5 md:text-[10px] md:opacity-0 md:transition md:group-hover/thumb:opacity-100"
                                            >
                                                ×
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                        <input
                            id={mediaInputId}
                            ref={fileRef}
                            type="file"
                            accept="image/jpeg,image/jpg,image/png,image/webp,image/gif,.jpg,.jpeg,.png,.webp,.gif,video/mp4,video/webm,video/quicktime,.mp4,.webm,.mov,audio/mpeg,audio/mp3,audio/wav,.mp3,.wav"
                            multiple
                            className="sr-only"
                            onChange={(e) => {
                                addMedia(e.target.files);
                                e.target.value = '';
                            }}
                        />
                                </motion.div>
                            )}
                        </AnimatePresence>
                        {mediaNotice && (
                            <p className="mt-2 px-0.5 text-[11px] text-amber-200/90">{mediaNotice}</p>
                        )}
                    </div>

                    <AnimatePresence>
                        {(switchNotice || showErrorGuidance || showInfoGuidance) && (
                            <motion.div
                                initial={{ opacity: 0, y: -6 }}
                                animate={{ opacity: 1, y: 0 }}
                                exit={{ opacity: 0, y: -4 }}
                                className="space-y-2"
                            >
                                {switchNotice && (
                                    <div className="flex items-start gap-2 rounded-xl border border-amber-400/30 bg-amber-500/10 px-3.5 py-2.5 text-[12px] leading-relaxed text-amber-100">
                                        <p className="min-w-0 flex-1">{switchNotice}</p>
                                        <button
                                            type="button"
                                            onClick={() => setSwitchNotice(null)}
                                            className="shrink-0 rounded-md px-1.5 py-0.5 text-[11px] text-amber-200/70 transition hover:bg-amber-500/20 hover:text-amber-50"
                                            aria-label={t('dismiss')}
                                        >
                                            ×
                                        </button>
                                    </div>
                                )}
                                {showErrorGuidance && mediaGuidance && (
                                    <div className="rounded-xl border border-rose-400/35 bg-rose-500/10 px-3.5 py-2.5 text-rose-100">
                                        <p className="text-[12px] font-semibold">{mediaGuidance.title}</p>
                                        <p className="mt-0.5 text-[11px] opacity-85">{mediaGuidance.body}</p>
                                    </div>
                                )}
                                {showInfoGuidance && mediaGuidance && (
                                    <div className="rounded-xl border border-sky-400/25 bg-sky-500/10 px-3.5 py-2.5 text-sky-100">
                                        <div className="flex items-start gap-2">
                                            <div className="min-w-0 flex-1">
                                                <p className="text-[12px] font-semibold">{mediaGuidance.title}</p>
                                                <p className="mt-0.5 text-[11px] opacity-85">{mediaGuidance.body}</p>
                                                {routeMode === 'image-to-video' && (
                                                    <p className="mt-1.5 text-[11px] font-medium text-orange-200/90">
                                                        Mode: first-frame image → video
                                                    </p>
                                                )}
                                                {routeMode === 'first-last-frame-to-video' && (
                                                    <p className="mt-1.5 text-[11px] font-medium text-orange-200/90">
                                                        Mode: first → last frame
                                                    </p>
                                                )}
                                                {routeMode === 'reference-to-video' && (
                                                    <p className="mt-1.5 text-[11px] font-medium text-orange-200/90">
                                                        Mode: reference media → video
                                                    </p>
                                                )}
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => setGuidanceDismissed(true)}
                                                className="shrink-0 rounded-md px-1.5 py-0.5 text-[11px] text-sky-200/70 transition hover:bg-sky-500/20 hover:text-sky-50"
                                                aria-label={t('dismiss')}
                                            >
                                                ×
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </motion.div>
                        )}
                    </AnimatePresence>

                    {/* Prompt card */}
                    <div className="rounded-2xl border border-white/[0.08] bg-gradient-to-b from-white/[0.05] to-white/[0.02] shadow-[0_0_0_1px_rgba(255,255,255,0.02)_inset]">
                        <div className="flex items-center justify-between px-4 pb-2 pt-3.5">
                            <div>
                                <p className="text-sm font-semibold text-white">{t('prompt')}</p>
                                <p className="mt-0.5 text-[11px] text-white/35">{t('video.promptSub')}</p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setExpanded(true)}
                                className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-white/40 transition hover:bg-white/[0.06] hover:text-white"
                                title={t('expandEditor')}
                            >
                                <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                    <path d="m21 21-6-6m6 6v-4.8m0 4.8h-4.8" />
                                    <path d="M3 16.2V21m0 0h4.8M3 21l6-6" />
                                    <path d="M21 7.8V3m0 0h-4.8M21 3l-6 6" />
                                    <path d="M3 7.8V3m0 0h4.8M3 3l6 6" />
                                </svg>
                            </button>
                        </div>

                        <div className="px-3 pb-2">
                            <AssetMentionTextarea
                                value={prompt}
                                onChange={setPrompt}
                                mentions={assetMentions}
                                maxLength={1500}
                                minRows={4}
                                maxRows={16}
                                placeholder={resolvedPlaceholder}
                                className="w-full rounded-xl border border-white/10 bg-black/30 px-3.5 py-3 text-[15px] leading-6 text-white outline-none placeholder:text-white/30 focus:border-orange-400/40 focus:ring-2 focus:ring-orange-500/15 sm:text-sm sm:leading-relaxed"
                            />
                            <div className="mt-1.5 flex items-center justify-between px-1">
                                <span className={`text-[11px] ${prompt.length > 900 ? 'text-orange-300' : 'text-white/30'}`}>
                                    {prompt.length}/1500
                                </span>
                                <button
                                    type="button"
                                    onClick={() => setPrompt((p) => p.trim())}
                                    className="text-[11px] text-white/35 hover:text-white/60"
                                >
                                    Trim
                                </button>
                            </div>
                        </div>

                        <div className="flex flex-wrap gap-1.5 border-t border-white/[0.05] px-3 py-2.5">
                            {PROMPT_CHIPS.map((chip) => (
                                <button
                                    key={chip.id}
                                    type="button"
                                    onClick={() => appendChip(chip.text)}
                                    className="rounded-full border border-white/10 bg-white/[0.03] px-2.5 py-1 text-[11px] text-white/55 transition hover:border-orange-400/35 hover:bg-orange-500/10 hover:text-orange-100"
                                >
                                    {chip.label}
                                </button>
                            ))}
                        </div>
                    </div>

                    {supportsNegativePrompt && (
                        <div className="rounded-2xl border border-white/[0.08] bg-gradient-to-b from-white/[0.04] to-white/[0.02] shadow-[0_0_0_1px_rgba(255,255,255,0.02)_inset]">
                            <div className="px-4 pb-2 pt-3.5">
                                <p className="text-sm font-semibold text-white">{t('video.negativePrompt')}</p>
                                <p className="mt-0.5 text-[11px] text-white/35">{t('video.negativePromptSub')}</p>
                            </div>
                            <div className="px-3 pb-3">
                                <textarea
                                    value={negativePrompt}
                                    onChange={(e) => setNegativePrompt(e.target.value.slice(0, 500))}
                                    rows={3}
                                    maxLength={500}
                                    placeholder={t('video.negativePromptPlaceholder')}
                                    className="w-full resize-none rounded-xl border border-white/10 bg-black/30 px-3.5 py-3 text-[14px] leading-6 text-white outline-none placeholder:text-white/30 focus:border-orange-400/40 focus:ring-2 focus:ring-orange-500/15 sm:text-sm"
                                />
                                <div className="mt-1.5 flex justify-end px-1">
                                    <span className={`text-[11px] ${negativePrompt.length > 420 ? 'text-orange-300' : 'text-white/30'}`}>
                                        {negativePrompt.length}/500
                                    </span>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Settings */}
                    <div className="overflow-hidden rounded-2xl border border-white/[0.08] bg-gradient-to-b from-white/[0.04] to-transparent">
                        <button
                            type="button"
                            onClick={() => setSettingsOpen((v) => !v)}
                            className="flex w-full cursor-pointer items-center justify-between px-3.5 py-3"
                        >
                            <div className="flex items-center gap-2">
                                <span className="text-sm font-semibold text-white">{t('settings')}</span>
                                <span className="rounded-full border border-white/10 bg-white/[0.04] px-2 py-0.5 text-[10px] text-white/40">
                                    {durationLabel} · {resolution}
                                </span>
                            </div>
                            <svg
                                className={`h-4 w-4 text-white/40 transition ${settingsOpen ? 'rotate-180' : ''}`}
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                strokeWidth="1.75"
                            >
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>

                        <AnimatePresence initial={false}>
                            {settingsOpen && (
                                <motion.div
                                    initial={{ height: 0, opacity: 0 }}
                                    animate={{ height: 'auto', opacity: 1 }}
                                    exit={{ height: 0, opacity: 0 }}
                                    transition={{ duration: 0.2, ease: 'easeOut' }}
                                    className="overflow-hidden"
                                >
                                    <div className="space-y-5 border-t border-white/[0.05] px-3.5 pb-4 pt-3">
                                        {/* Duration — always a slider; stops come from model enums */}
                                        <div className="space-y-2.5">
                                            <div className="flex items-center justify-between">
                                                <div>
                                                    <p className="text-[13px] font-medium text-zinc-200">{t('duration')}</p>
                                                    <p className="text-[10px] text-white/30">
                                                        {durationOptions.allowAuto
                                                            ? `Auto or ${durationOptions.min}–${durationOptions.max}s`
                                                            : `${durationOptions.min}–${durationOptions.max}s for this model`}
                                                    </p>
                                                </div>
                                                <span className="rounded-lg border border-orange-400/25 bg-orange-500/10 px-2 py-0.5 text-[12px] font-semibold text-orange-100">
                                                    {durationLabel}
                                                </span>
                                            </div>
                                            <div className="relative px-0.5 pt-1">
                                                <div className="relative h-2 w-full overflow-hidden rounded-full bg-white/10">
                                                    <div
                                                        className="absolute inset-y-0 start-0 rounded-full bg-gradient-to-r from-[#FF5733] to-[#FF8C00]"
                                                        style={{ width: `${durationPct}%` }}
                                                    />
                                                </div>
                                                <input
                                                    type="range"
                                                    min={0}
                                                    max={Math.max(0, durationStops.length - 1)}
                                                    step={1}
                                                    value={durationIndex}
                                                    onChange={(e) => {
                                                        const next = durationStops[Number(e.target.value)];
                                                        if (next !== undefined) setDuration(next);
                                                    }}
                                                    className="absolute inset-x-0 top-0 h-4 w-full cursor-pointer appearance-none bg-transparent [&::-moz-range-thumb]:h-5 [&::-moz-range-thumb]:w-5 [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:border-2 [&::-moz-range-thumb]:border-[#FF5733] [&::-moz-range-thumb]:bg-white [&::-moz-range-thumb]:shadow-[0_0_12px_rgba(255,87,51,0.45)] [&::-webkit-slider-thumb]:relative [&::-webkit-slider-thumb]:-mt-1.5 [&::-webkit-slider-thumb]:h-5 [&::-webkit-slider-thumb]:w-5 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:border-2 [&::-webkit-slider-thumb]:border-[#FF5733] [&::-webkit-slider-thumb]:bg-white [&::-webkit-slider-thumb]:shadow-[0_0_12px_rgba(255,87,51,0.45)]"
                                                />
                                            </div>
                                            <div className="flex justify-between text-[11px] text-white/30">
                                                <span>{durationOptions.allowAuto ? t('auto') : `${durationOptions.min}s`}</span>
                                                <span>{durationOptions.max}s</span>
                                            </div>
                                        </div>

                                        {/* Audio — only for models with generate_audio */}
                                        {supportsAudio && (
                                            <div className="flex items-center justify-between gap-3">
                                                <div>
                                                    <p className="text-[13px] font-medium text-zinc-200">{t('video.audio')}</p>
                                                    <p className="text-[10px] text-white/30">{audioOn ? t('video.audioGenerate') : t('video.audioSilent')}</p>
                                                </div>
                                                <PremiumSegmented
                                                    groupId="video-audio"
                                                    value={audioOn ? 'on' : 'off'}
                                                    options={[
                                                        { id: 'on', label: t('video.audioOn') },
                                                        { id: 'off', label: t('video.audioOff') },
                                                    ]}
                                                    onChange={(v) => setAudioOn(v === 'on')}
                                                />
                                            </div>
                                        )}

                                        {/* Resolution */}
                                        <div>
                                            <p className="mb-2 text-[11px] font-medium uppercase tracking-wider text-white/35">{t('resolution')}</p>
                                            <div
                                                className="grid gap-1.5"
                                                style={{
                                                    gridTemplateColumns: `repeat(${Math.min(4, Math.max(2, availableResolutions.length))}, minmax(0, 1fr))`,
                                                }}
                                            >
                                                {availableResolutions.map((r) => {
                                                    const active = resolution === r.id;
                                                    return (
                                                        <button
                                                            key={r.id}
                                                            type="button"
                                                            onClick={() => setResolution(r.id)}
                                                            className={`rounded-xl border px-1.5 py-2.5 text-center transition ${
                                                                active
                                                                    ? 'border-orange-400/50 bg-orange-500/15 text-orange-100 shadow-[0_0_20px_rgba(255,87,51,0.12)]'
                                                                    : 'border-white/[0.07] bg-white/[0.03] text-white/55 hover:text-white'
                                                            }`}
                                                        >
                                                            <span className="block text-[12px] font-semibold">{r.id}</span>
                                                            <span className={`mt-0.5 block text-[9px] ${active ? 'text-orange-200/70' : 'text-white/30'}`}>
                                                                {r.sub}
                                                            </span>
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        </div>

                                        {/* Aspect */}
                                        <div>
                                            <p className="mb-2 text-[11px] font-medium uppercase tracking-wider text-white/35">{t('aspectRatio')}</p>
                                            <div
                                                className="grid gap-1.5"
                                                style={{
                                                    gridTemplateColumns: `repeat(${Math.min(5, Math.max(2, availableAspects.length))}, minmax(0, 1fr))`,
                                                }}
                                            >
                                                {availableAspects.map((key) => {
                                                    const meta = aspectBox(key);
                                                    const active = aspect === key;
                                                    return (
                                                        <button
                                                            key={key}
                                                            type="button"
                                                            onClick={() => setAspect(key)}
                                                            className={`flex flex-col items-center gap-1.5 rounded-xl border px-1 py-2.5 transition ${
                                                                active
                                                                    ? 'border-orange-400/50 bg-orange-500/15 text-orange-100'
                                                                    : 'border-white/[0.07] bg-white/[0.03] text-white/50 hover:border-white/15 hover:text-white/80'
                                                            }`}
                                                        >
                                                            <span
                                                                className={`rounded-[3px] border ${active ? 'border-orange-300/70' : 'border-current'}`}
                                                                style={{ width: meta.w, height: meta.h }}
                                                            />
                                                            <span className="text-[10px] font-semibold">{key}</span>
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        </div>
                                    </div>
                                </motion.div>
                            )}
                        </AnimatePresence>
                    </div>
                </div>
            </div>

            {/* Sticky create */}
            <div className="relative shrink-0 border-t border-white/[0.07] bg-[#0a0a0f]/95 p-3 backdrop-blur-xl">
                <div className="pointer-events-none absolute inset-x-0 -top-8 h-8 bg-gradient-to-t from-[#0a0a0f] to-transparent" />
                <div className="mb-2.5 flex items-center justify-between gap-2 px-0.5">
                    <div className="flex min-w-0 flex-wrap items-center gap-1.5">
                        <span className="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/[0.04] px-2 py-1 text-[11px] text-white/65">
                            <span
                                className="rounded-[2px] border border-orange-300/50"
                                style={{
                                    width: Math.max(8, (aspectBox(aspect).w || 14) * 0.7),
                                    height: Math.max(8, (aspectBox(aspect).h || 14) * 0.7),
                                }}
                            />
                            {aspect}
                        </span>
                        <span className="rounded-lg border border-white/10 bg-white/[0.04] px-2 py-1 text-[11px] text-white/65">{resolution}</span>
                        <span className="rounded-lg border border-white/10 bg-white/[0.04] px-2 py-1 text-[11px] text-white/65">{durationLabel}</span>
                        {supportsAudio && audioOn && (
                            <span className="rounded-lg border border-emerald-500/20 bg-emerald-500/10 px-2 py-1 text-[11px] text-emerald-300">{t('video.audio')}</span>
                        )}
                    </div>
                    <span className="inline-flex shrink-0 items-center gap-1 text-[11px] font-medium tabular-nums text-orange-200/90">
                        {creditCost > 0 ? (
                            <>
                                <CreditBoltIcon className="h-3.5 w-3.5 text-amber-300" />
                                {creditCost}
                            </>
                        ) : (
                            '—'
                        )}
                    </span>
                </div>

                <motion.button
                    type="button"
                    whileTap={canGenerate && !loading ? { scale: 0.98 } : undefined}
                    disabled={loading || !canGenerate}
                    onClick={() => {
                        if (loading || !canGenerate || !hasMeaningfulPrompt(prompt)) return;
                        onGenerate?.(prompt, {
                            quantity: 1,
                            aspect,
                            resolution,
                            duration,
                            audio: effectiveAudio,
                            endpointId: 'endpoint_id' in selectedMeta ? selectedMeta.endpoint_id || undefined : undefined,
                            modelName: selectedMeta.name,
                            routeMode: routeMode ?? undefined,
                            frameMode: framesMode ? 'first_last' : undefined,
                            negativePrompt: supportsNegativePrompt ? negativePrompt.trim() || undefined : undefined,
                            imageFiles: framesMode
                                ? [firstFrame, lastFrame].filter(Boolean).map((m) => m!.file)
                                : media.filter((m) => m.kind === 'image').map((m) => m.file),
                            videoFiles: framesMode ? [] : media.filter((m) => m.kind === 'video').map((m) => m.file),
                            audioFiles: framesMode ? [] : media.filter((m) => m.kind === 'audio').map((m) => m.file),
                        });
                    }}
                    className="relative flex h-12 w-full cursor-pointer items-center justify-center gap-2 overflow-hidden rounded-xl bg-gradient-to-b from-[#FF6A45] via-[#FF5733] to-[#D63A18] text-sm font-semibold text-white shadow-[0_10px_30px_rgba(255,87,51,0.35)] transition disabled:cursor-not-allowed disabled:opacity-45"
                >
                    <span className="absolute inset-0 bg-[linear-gradient(180deg,rgba(255,255,255,0.22),transparent_40%)]" />
                    {!loading && canGenerate && (
                        <motion.span
                            className="pointer-events-none absolute inset-y-0 w-24 bg-gradient-to-r from-transparent via-white/25 to-transparent"
                            animate={{ x: ['-120%', '420%'] }}
                            transition={{ duration: 2.2, repeat: Infinity, ease: 'easeInOut', repeatDelay: 1.4 }}
                        />
                    )}
                    {loading ? (
                        <span className="relative flex items-center gap-2">
                            <span className="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white" />
                            {t('generating')}
                        </span>
                    ) : !hasEnoughTokens ? (
                        <span className="relative text-white/90">{t('notEnoughTokens', { balance: tokenBalance })}</span>
                    ) : !hasMeaningfulPrompt(prompt) ? (
                        <span className="relative text-white/90">{t('video.blockPrompt')}</span>
                    ) : (
                        <>
                            <svg className="relative h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z" />
                            </svg>
                            <span className="relative">{t('create')}</span>
                            {creditCost > 0 && (
                                <span className="relative inline-flex items-center gap-1 rounded-full bg-white/20 px-2.5 py-0.5 text-[11px] font-medium tabular-nums">
                                    <CreditBoltIcon className="h-3 w-3 text-amber-100" />
                                    {creditCost}
                                </span>
                            )}
                        </>
                    )}
                </motion.button>
                {blockReason && !loading && (
                    <p className="mt-2 px-0.5 text-center text-[11px] leading-relaxed text-rose-300/90">{blockReason}</p>
                )}
            </div>

            {/* Expand prompt */}
            <AnimatePresence>
                {expanded && (
                    <motion.div
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        className="fixed inset-0 z-50 flex items-end justify-center bg-black/75 p-3 backdrop-blur-md sm:items-center"
                        onClick={() => setExpanded(false)}
                    >
                        <motion.div
                            initial={{ opacity: 0, y: 24, scale: 0.98 }}
                            animate={{ opacity: 1, y: 0, scale: 1 }}
                            exit={{ opacity: 0, y: 16, scale: 0.98 }}
                            className="w-full max-w-xl overflow-hidden rounded-2xl border border-white/10 bg-[#101016] shadow-[0_30px_80px_rgba(0,0,0,0.55)]"
                            onClick={(e) => e.stopPropagation()}
                        >
                            <div className="flex items-center justify-between border-b border-white/[0.06] px-4 py-3">
                                <div>
                                    <h3 className="text-sm font-semibold text-white">{t('promptEditor')}</h3>
                                    <p className="text-[11px] text-white/35">Write a full cinematic brief</p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setExpanded(false)}
                                    className="rounded-lg px-2.5 py-1.5 text-[12px] text-zinc-400 transition hover:bg-white/[0.06] hover:text-white"
                                >
                                    Done
                                </button>
                            </div>
                            <div className="p-4">
                                <AssetMentionTextarea
                                    autoFocus
                                    value={prompt}
                                    onChange={setPrompt}
                                    mentions={assetMentions}
                                    maxLength={1500}
                                    minRows={10}
                                    maxRows={24}
                                    className="w-full rounded-xl border border-white/10 bg-black/40 p-3.5 text-[15px] leading-6 text-white outline-none focus:border-orange-400/40 focus:ring-2 focus:ring-orange-500/15 sm:text-sm sm:leading-relaxed"
                                    placeholder={resolvedPlaceholder}
                                />
                            </div>
                        </motion.div>
                    </motion.div>
                )}
            </AnimatePresence>

            {/* Model modal */}
            <AnimatePresence>
                {modelOpen && (
                    <motion.div
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        className="fixed inset-0 z-50 flex items-center justify-center bg-black/35 p-3 backdrop-blur-lg"
                        onClick={() => setModelOpen(false)}
                    >
                        <motion.div
                            role="dialog"
                            aria-modal="true"
                            initial={{ opacity: 0, scale: 0.96, y: 12 }}
                            animate={{ opacity: 1, scale: 1, y: 0 }}
                            exit={{ opacity: 0, scale: 0.97 }}
                            className="relative grid w-[95vw] max-w-lg gap-4 overflow-hidden rounded-2xl border border-white/10 bg-black p-6 shadow-[0_30px_80px_rgba(0,0,0,0.55)]"
                            onClick={(e) => e.stopPropagation()}
                        >
                            <div className="pointer-events-none absolute inset-x-0 top-0 h-28 bg-[radial-gradient(ellipse_at_top,rgba(255,87,51,0.22),transparent_70%)]" />
                            <button
                                type="button"
                                onClick={() => setModelOpen(false)}
                                className="absolute end-4 top-4 z-10 rounded-lg p-1.5 text-zinc-500 transition hover:bg-white/[0.06] hover:text-white"
                                aria-label={t('close')}
                            >
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                    <path d="M18 6 6 18" />
                                    <path d="m6 6 12 12" />
                                </svg>
                            </button>
                            <div className="relative pe-6">
                                <h2 className="text-lg font-semibold tracking-tight text-white">{t('selectModel')}</h2>
                                <p className="mt-1 text-[13px] text-zinc-500">
                                    {framesMode
                                        ? t('video.framesModelsOnly', {
                                              count: modelsForPicker.length,
                                              defaultValue: `First & last frame models (${modelsForPicker.length})`,
                                          })
                                        : mediaTotal(mediaCounts) > 0
                                          ? `Safe models for your media (${modelsForPicker.length}) — others hidden`
                                          : t('selectModelSub')}
                                </p>
                            </div>
                            <div className="relative max-h-[60vh] space-y-1.5 overflow-y-auto py-1 scrollbar-thin">
                                {modelsForPicker.length === 0 && (framesMode || mediaTotal(mediaCounts) > 0) ? (
                                    <div className="rounded-xl border border-rose-400/25 bg-rose-500/10 px-4 py-6 text-center">
                                        <p className="text-sm font-medium text-rose-100">{t('video.noCompatible')}</p>
                                        <p className="mt-1 text-[12px] text-rose-100/70">
                                            {framesMode
                                                ? t('video.framesNoModels', {
                                                      defaultValue: 'No models support first & last frames right now.',
                                                  })
                                                : 'Change or remove references to see available models again.'}
                                        </p>
                                    </div>
                                ) : null}
                                {(modelsForPicker.length
                                    ? modelsForPicker
                                    : framesMode || mediaTotal(mediaCounts) > 0
                                      ? []
                                      : [
                                            {
                                                name: 'Seedance 2.0',
                                                description: 'Cinematic text-to-video generation',
                                                brandName: 'Studio',
                                                brandIcon: null,
                                                icon: null,
                                                tags: ['New'],
                                            },
                                        ]
                                ).map((m) => {
                                    const active = selectedModel === m.name;
                                    const tags = ('tags' in m && Array.isArray(m.tags) ? m.tags : []).filter(
                                        (tag) => tag !== 'tool' && !('tool_slug' in m && tag === m.tool_slug),
                                    );
                                    const modelCredits = estimateVideoCredits(
                                        m,
                                        {
                                            durationSeconds,
                                            audio: m.supports_audio === true && audioOn,
                                            resolution,
                                            aspect,
                                        },
                                        creditsConfig,
                                    ).credits;
                                    return (
                                        <button
                                            key={`${m.brandName}-${m.name}-${'endpoint_id' in m ? m.endpoint_id : ''}`}
                                            type="button"
                                            onClick={() => chooseModel(m)}
                                            className={`flex w-full cursor-pointer items-center gap-3 rounded-xl border p-3 text-start transition ${
                                                active
                                                    ? 'border-[#FF5733]/35 bg-[#FF5733]/12 shadow-[0_0_24px_rgba(255,87,51,0.12)]'
                                                    : 'border-transparent bg-white/[0.03] hover:bg-white/[0.06]'
                                            }`}
                                        >
                                            <ModelAvatar name={m.name} icon={'icon' in m ? m.icon : null} brandIcon={m.brandIcon} />
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <p className="text-[13px] font-medium text-zinc-100">{formatModelName(m.name)}</p>
                                                    {tags.slice(0, 2).map((tag) => (
                                                        <span
                                                            key={tag}
                                                            className="rounded px-1.5 py-0.5 text-[9px] font-semibold text-white bg-gradient-to-r from-[#FF5733] to-[#FF8C00]"
                                                        >
                                                            {tag}
                                                        </span>
                                                    ))}
                                                    {'supports_negative_prompt' in m && m.supports_negative_prompt ? (
                                                        <span className="rounded px-1.5 py-0.5 text-[9px] font-medium text-violet-100 bg-violet-500/15 ring-1 ring-violet-400/25">
                                                            {t('video.negativePrompt')}
                                                        </span>
                                                    ) : null}
                                                </div>
                                                <p className="mt-0.5 line-clamp-1 text-[12px] text-zinc-500">
                                                    {m.description || m.brandName}
                                                </p>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-1.5">
                                                {modelCredits > 0 && (
                                                    <span className="inline-flex items-center gap-1 rounded-md border border-orange-400/25 bg-orange-500/10 px-1.5 py-0.5 text-[10px] font-medium tabular-nums text-orange-200">
                                                        <CreditBoltIcon className="h-3 w-3 text-amber-300" />
                                                        {modelCredits}
                                                    </span>
                                                )}
                                                {active ? (
                                                    <svg className="h-4 w-4 shrink-0 text-[#FF5733]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                        <path d="M20 6 9 17l-5-5" />
                                                    </svg>
                                                ) : null}
                                            </div>
                                        </button>
                                    );
                                })}
                            </div>
                            <div className="relative flex justify-end">
                                <button
                                    type="button"
                                    onClick={() => setModelOpen(false)}
                                    className="inline-flex min-h-9 cursor-pointer items-center rounded-lg border border-white/10 px-4 text-[13px] font-medium text-zinc-300 transition hover:bg-white/[0.05] hover:text-white"
                                >
                                    {t('close')}
                                </button>
                            </div>
                        </motion.div>
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    );
}

function SectionLabel({ children }: { children: React.ReactNode }) {
    return <label className="px-0.5 text-[11px] font-medium uppercase tracking-wider text-white/35">{children}</label>;
}

function MediaBadge({ children, delay = 0 }: { children: React.ReactNode; delay?: number; label?: string }) {
    return (
        <motion.div
            initial={{ opacity: 0, y: 6 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay, duration: 0.35 }}
            className="flex h-10 w-10 items-center justify-center rounded-full border border-orange-400/20 bg-gradient-to-br from-orange-500/20 to-white/[0.04] text-orange-200/85 shadow-[0_0_16px_rgba(255,87,51,0.12)] transition group-hover:scale-105"
        >
            {children}
        </motion.div>
    );
}

function FrameSlot({
    label,
    hint,
    item,
    inputId,
    onClear,
}: {
    label: string;
    hint: string;
    item: MediaItem | null;
    inputId: string;
    onClear: () => void;
}) {
    return (
        <div className="min-w-0">
            <p className="mb-1.5 px-0.5 text-[10px] font-medium uppercase tracking-wider text-white/40">{label}</p>
            {item ? (
                <div className="group/frame relative aspect-[4/3] overflow-hidden rounded-xl bg-black/40 ring-1 ring-white/10">
                    <img src={item.url} alt="" className="size-full object-cover" />
                    <button
                        type="button"
                        onClick={onClear}
                        aria-label={`Remove ${label}`}
                        className="absolute end-1.5 top-1.5 z-10 flex h-6 w-6 items-center justify-center rounded-full bg-black/75 text-xs text-white ring-1 ring-white/20"
                    >
                        ×
                    </button>
                    <span className="absolute inset-x-0 bottom-0 truncate bg-gradient-to-t from-black/80 to-transparent px-2 pb-1.5 pt-4 text-[10px] text-white/70">
                        {item.name}
                    </span>
                </div>
            ) : (
                <label
                    htmlFor={inputId}
                    className="flex aspect-[4/3] cursor-pointer flex-col items-center justify-center gap-1.5 rounded-xl border border-dashed border-white/15 bg-white/[0.02] text-center transition hover:border-orange-400/40 hover:bg-orange-500/[0.05]"
                >
                    <span className="flex h-8 w-8 items-center justify-center rounded-full border border-orange-400/25 bg-orange-500/10 text-orange-200">
                        <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                            <path d="M12 5v14" />
                            <path d="M5 12h14" />
                        </svg>
                    </span>
                    <span className="px-2 text-[11px] font-medium text-zinc-300">{hint}</span>
                </label>
            )}
        </div>
    );
}

function PremiumSegmented({
    groupId,
    value,
    options,
    onChange,
}: {
    groupId: string;
    value: string;
    options: { id: string; label: string }[];
    onChange: (id: string) => void;
}) {
    return (
        <div className="relative flex rounded-xl border border-white/[0.06] bg-black/30 p-0.5">
            {options.map((opt) => {
                const active = value === opt.id;
                return (
                    <button
                        key={opt.id}
                        type="button"
                        onClick={() => onChange(opt.id)}
                        className={`relative z-10 inline-flex h-8 min-w-[3.25rem] cursor-pointer items-center justify-center rounded-lg px-3 text-[12px] font-medium transition ${
                            active ? 'text-white' : 'text-zinc-500 hover:text-zinc-300'
                        }`}
                    >
                        {active && (
                            <motion.span
                                layoutId={groupId}
                                className="absolute inset-0 rounded-lg bg-[#1c1c24] shadow-sm ring-1 ring-white/10"
                                transition={{ type: 'spring', stiffness: 420, damping: 32 }}
                            />
                        )}
                        <span className="relative">{opt.label}</span>
                    </button>
                );
            })}
        </div>
    );
}

function ModelAvatar({ name, icon, brandIcon }: { name: string; icon?: string | null; brandIcon?: string | null }) {
    const src = icon || brandIcon;
    if (src) {
        return <img src={src} alt="" className="h-10 w-10 shrink-0 rounded-md object-contain bg-white/5 p-1 ring-1 ring-white/10" />;
    }
    return (
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-gradient-to-br from-orange-400/35 to-fuchsia-500/20 text-sm font-bold text-orange-100 ring-1 ring-orange-400/20">
            {name[0]?.toUpperCase() || 'V'}
        </div>
    );
}

function CreditBoltIcon({ className = 'h-3.5 w-3.5' }: { className?: string }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="currentColor" aria-hidden>
            <path d="M13 2 4.5 13.5H11l-1 8.5L19.5 10H13l0-8Z" />
        </svg>
    );
}

function formatModelName(name: string) {
    return name
        .replace(/[-_]+/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase())
        .replace(/\bGpt\b/g, 'GPT')
        .replace(/\bAi\b/g, 'AI');
}

function detectMediaKind(file: File): 'image' | 'video' | 'audio' | null {
    const mime = (file.type || '').toLowerCase();
    if (mime.startsWith('image/')) return 'image';
    if (mime.startsWith('video/')) return 'video';
    if (mime.startsWith('audio/')) return 'audio';

    // Windows sometimes leaves type empty — fall back to extension.
    const ext = file.name.includes('.') ? file.name.slice(file.name.lastIndexOf('.') + 1).toLowerCase() : '';
    if (['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'].includes(ext)) return 'image';
    if (['mp4', 'webm', 'mov', 'm4v'].includes(ext)) return 'video';
    if (['mp3', 'wav', 'm4a', 'ogg', 'aac'].includes(ext)) return 'audio';

    return null;
}
