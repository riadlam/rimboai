import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { Brand } from '@/types';
import { estimateImageCredits, type CreditsConfig } from '@/lib/imageCredits';
import LabFormSkeleton from '@/Components/LabFormSkeleton';
import AssetMentionTextarea, {
    rebasePromptAfterAssetRemoval,
    type AssetMention,
} from '@/Components/AssetMentionTextarea';
import { loadDraftMediaFiles, matchLabModel, type LabReuseDraft } from '@/lib/labReuse';
import { hasMeaningfulPrompt } from '@/lib/promptText';
import {
    aspectBox,
    imageAspectOptions,
    imageResolutionOptions,
    pickSupportedValue,
} from '@/lib/labModelOptions';

export type ImageGenerateOptions = {
    quantity: number;
    aspect: string;
    resolution: string;
    endpointId?: string;
    modelName?: string;
    mode?: 'create' | 'variations';
    referenceFiles?: File[];
};

type Props = {
    brands?: Brand[];
    placeholder?: string;
    onGenerate?: (prompt: string, options?: ImageGenerateOptions) => void;
    loading?: boolean;
    creditsConfig?: CreditsConfig;
    tokenBalance?: number;
    draft?: LabReuseDraft | null;
};

const createTabGlow =
    'radial-gradient(40.23% 21.05% at 49.78% 0%, rgba(255, 255, 255, 0.25) 0%, rgba(255, 87, 51, 0) 100%), radial-gradient(64.38% 127.63% at 51.44% 189.47%, rgb(255, 87, 51) 0%, rgba(255, 87, 51, 0.14) 75.41%, rgba(255, 87, 51, 0) 100%), radial-gradient(62.56% 123.04% at 56.08% -62.5%, rgb(255, 87, 51) 0%, rgba(255, 87, 51, 0.14) 63.27%, rgba(255, 87, 51, 0) 100%), radial-gradient(132.84% 167.33% at 3.65% -101.97%, rgba(255, 87, 51, 0.7) 0%, rgba(255, 87, 51, 0.3) 52.68%, rgba(255, 87, 51, 0.24) 62.41%, rgba(255, 87, 51, 0) 100%), radial-gradient(108.19% 123.4% at 103.21% -59.87%, rgba(255, 87, 51, 0.7) 0%, rgba(255, 87, 51, 0.3) 45.38%, rgba(255, 87, 51, 0.24) 56.19%, rgba(255, 87, 51, 0) 100%)';

export default function ImageLabCreateForm({
    brands = [],
    placeholder = 'e.g. A cat is sitting on a table. We support all languages.',
    onGenerate,
    loading = false,
    creditsConfig,
    tokenBalance = 0,
    draft = null,
}: Props) {
    const { t } = useTranslation('lab');
    const [mode, setMode] = useState<'create' | 'variations'>('create');
    const [prompt, setPrompt] = useState('');
    const [expanded, setExpanded] = useState(false);
    const [autoPolish, setAutoPolish] = useState(true);
    const [aspect, setAspect] = useState('1:1');
    const [resolution, setResolution] = useState('1K');
    const [quantity, setQuantity] = useState(2);
    const [modelOpen, setModelOpen] = useState(false);
    const [selectedBrand, setSelectedBrand] = useState(brands[0]?.name || 'Studio');
    const [selectedModel, setSelectedModel] = useState(brands[0]?.models[0]?.name || 'Nano Banana Pro');
    const [refs, setRefs] = useState<{ id: string; url: string; file: File; kind: 'image' | 'video' }[]>([]);
    const [draftNotice, setDraftNotice] = useState<string | null>(null);
    const [draftLoading, setDraftLoading] = useState(false);
    const fileRef = useRef<HTMLInputElement>(null);
    const refsRef = useRef(refs);
    refsRef.current = refs;
    const isVariations = mode === 'variations';
    const imageRefs = useMemo(() => refs.filter((r) => r.kind === 'image'), [refs]);
    const assetMentions = useMemo<AssetMention[]>(
        () =>
            imageRefs.map((ref, index) => ({
                alias: `@image${index + 1}`,
                kind: 'image',
                name: ref.file.name,
                previewUrl: ref.url,
            })),
        [imageRefs],
    );

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

    const selectedModelRecord = useMemo(
        () => allModels.find((m) => m.name === selectedModel),
        [allModels, selectedModel],
    );

    const availableAspects = useMemo(
        () => imageAspectOptions(selectedModelRecord?.aspect_ratios),
        [selectedModelRecord],
    );

    const availableResolutions = useMemo(
        () => imageResolutionOptions(selectedModelRecord?.resolutions),
        [selectedModelRecord],
    );

    useEffect(() => {
        setAspect((prev) => pickSupportedValue(prev, availableAspects, '1:1'));
        setResolution((prev) =>
            pickSupportedValue(
                prev,
                availableResolutions.map((r) => r.id),
                '1K',
            ),
        );
    }, [availableAspects, availableResolutions]);

    const selectedSupportsVariations = selectedModelRecord?.supports_variations === true;

    const modelsForPicker = useMemo(
        () => (isVariations ? allModels.filter((m) => m.supports_variations === true) : allModels),
        [allModels, isVariations],
    );

    const creditEstimate = useMemo(
        () =>
            estimateImageCredits(
                selectedModelRecord,
                {
                    aspect,
                    resolution,
                    quantity,
                    referenceCount: imageRefs.length,
                },
                creditsConfig,
            ),
        [selectedModelRecord, aspect, resolution, quantity, imageRefs.length, creditsConfig],
    );
    const creditCost = creditEstimate.credits;
    const hasEnoughTokens = creditCost > 0 && tokenBalance >= creditCost;

    const hasPrompt = hasMeaningfulPrompt(prompt);
    const canGenerate =
        hasPrompt &&
        (!isVariations || imageRefs.length > 0) &&
        (!isVariations || selectedSupportsVariations) &&
        hasEnoughTokens;

    const selectedModelMeta =
        allModels.find((m) => m.name === selectedModel) ||
        ({
            name: selectedModel,
            description: 'High-fidelity image model',
            brandName: selectedBrand,
            brandIcon: null as string | null,
            icon: null as string | null,
            tags: [] as string[],
        } as const);

    useEffect(() => {
        if (brands[0] && !brands.find((b) => b.name === selectedBrand)) {
            setSelectedBrand(brands[0].name);
            setSelectedModel(brands[0].models[0]?.name || 'Nano Banana Pro');
        }
    }, [brands, selectedBrand]);

    useEffect(() => {
        if (!isVariations || selectedSupportsVariations) return;
        const first = allModels.find((m) => m.supports_variations);
        if (first) {
            setSelectedBrand(first.brandName);
            setSelectedModel(first.name);
        }
    }, [isVariations, selectedSupportsVariations, allModels]);

    useEffect(() => {
        if (!draft || draft.lab !== 'image') return;

        let cancelled = false;
        const apply = async () => {
            setDraftLoading(true);
            setModelOpen(false);
            setPrompt(draft.intent === 'use-result' ? '' : draft.prompt || '');

            const matched = matchLabModel(allModels, {
                modelName: draft.modelName,
                endpointId: draft.endpointId,
            });
            if (matched) {
                setSelectedBrand(matched.brandName);
                setSelectedModel(matched.name);
            }

            const nextAspect = String(draft.aspect || '')
                .trim()
                .replace(/\s+/g, '')
                .replace(/[xX×/／：]/g, ':');
            const draftAspects = imageAspectOptions(matched?.aspect_ratios);
            const draftResolutions = imageResolutionOptions(matched?.resolutions);
            if (nextAspect && draftAspects.includes(nextAspect)) {
                setAspect(nextAspect);
            }
            if (draft.resolution && draftResolutions.some((r) => r.id === draft.resolution)) {
                setResolution(draft.resolution);
            }
            if (draft.quantity && draft.quantity >= 1) setQuantity(Math.min(4, draft.quantity));

            // Use Image must land on Create Image — never Variations / Remix refs.
            const nextMode =
                draft.intent === 'use-result'
                    ? 'create'
                    : draft.imageMode === 'variations'
                      ? 'variations'
                      : 'create';
            setMode(nextMode);

            refsRef.current.forEach((r) => URL.revokeObjectURL(r.url));
            refsRef.current = [];
            setRefs([]);

            try {
                const imageMedia = draft.media.filter((m) => m.kind === 'image' || m.kind === 'video');
                if (imageMedia.length > 0) {
                    const loaded = await loadDraftMediaFiles(imageMedia);
                    if (cancelled) return;
                    const next = loaded.files.map((file, i) => ({
                        id: `${Date.now()}-${file.name}-${i}`,
                        url: URL.createObjectURL(file),
                        file,
                        kind: (loaded.kinds[i] === 'video' || file.type.startsWith('video/')
                            ? 'video'
                            : 'image') as 'image' | 'video',
                    }));
                    refsRef.current = next;
                    setRefs(next);
                    // Re-assert after async load — avoid stale mode flips.
                    if (draft.intent === 'use-result') {
                        setMode('create');
                    }

                    if (loaded.failed > 0 && loaded.files.length === 0) {
                        setDraftNotice(t('settingsRestored'));
                    } else if (loaded.failed > 0) {
                        setDraftNotice(t('settingsRestored'));
                    } else {
                        setDraftNotice(
                            draft.intent === 'use-result'
                                ? t('enterNewPrompt')
                                : t('settingsRestored'),
                        );
                    }
                } else {
                    setDraftNotice(
                        draft.intent === 'use-result'
                            ? t('enterNewPrompt')
                            : t('settingsRestored'),
                    );
                }
            } catch (err) {
                if (!cancelled && !(err instanceof DOMException && err.name === 'AbortError')) {
                    setDraftNotice(t('settingsRestored'));
                }
            } finally {
                if (!cancelled) {
                    // Re-assert settings after async media load so they aren't lost.
                    const retryAspect = String(draft.aspect || '')
                        .trim()
                        .replace(/\s+/g, '')
                        .replace(/[xX×/／：]/g, ':');
                    const retryAspects = imageAspectOptions(matched?.aspect_ratios);
                    const retryResolutions = imageResolutionOptions(matched?.resolutions);
                    if (retryAspect && retryAspects.includes(retryAspect)) {
                        setAspect(retryAspect);
                    }
                    if (draft.resolution && retryResolutions.some((r) => r.id === draft.resolution)) {
                        setResolution(draft.resolution);
                    }
                    if (draft.intent === 'use-result') {
                        setMode('create');
                    }
                    setDraftLoading(false);
                }
            }
        };

        void apply();
        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [draft?.id]);

    const addRefs = (files: FileList | null) => {
        if (!files) return;
        const next = Array.from(files)
            .slice(0, 8 - refs.length)
            .map((file) => ({
                id: `${Date.now()}-${file.name}`,
                url: URL.createObjectURL(file),
                file,
                kind: (file.type.startsWith('video/') ? 'video' : 'image') as 'image' | 'video',
            }));
        setRefs((prev) => [...prev, ...next].slice(0, 8));
    };

    const removeRef = (id: string) => {
        const target = refs.find((ref) => ref.id === id);
        if (target) {
            const removedIndex = refs.filter((ref) => ref.kind === target.kind).findIndex((ref) => ref.id === id) + 1;
            setPrompt((value) => rebasePromptAfterAssetRemoval(value, target.kind, removedIndex));
        }
        setRefs((prev) => {
            const removed = prev.find((r) => r.id === id);
            if (removed) URL.revokeObjectURL(removed.url);
            return prev.filter((r) => r.id !== id);
        });
    };

    return (
        <div className="relative flex flex-col bg-[#0a0a0f] md:h-full md:min-h-0 [&_a]:cursor-pointer [&_button]:cursor-pointer [&_button:disabled]:cursor-not-allowed [&_label]:cursor-pointer">
            <div className="pointer-events-none absolute inset-x-0 top-0 h-40 bg-[radial-gradient(ellipse_at_top,rgba(255,87,51,0.18),transparent_70%)]" />

            <AnimatePresence>
                {draftLoading && (
                    <LabFormSkeleton
                        label={
                            draft?.intent === 'use-result'
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
                {!draftLoading && draftNotice && (
                    <div className="mx-3 mt-2.5 flex items-start justify-between gap-2 rounded-xl border border-orange-400/25 bg-orange-500/10 px-3 py-2 text-[11px] leading-snug text-orange-100/90">
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
                {/* Mode tabs */}
                <div className="px-3 pb-1 pt-2.5">
                    <div className="flex gap-1.5 rounded-xl border border-white/[0.06] bg-black/30 p-1.5">
                        {(
                            [
                                { id: 'create' as const, label: t('image.modeCreate'), hint: t('image.modeCreateHint') },
                                { id: 'variations' as const, label: t('image.modeVariations'), hint: t('image.modeVariationsHint') },
                            ]
                        ).map((tab) => {
                            const active = mode === tab.id;
                            return (
                                <button
                                    key={tab.id}
                                    type="button"
                                    onClick={() => setMode(tab.id)}
                                    className={`relative flex flex-1 items-center justify-center gap-2.5 overflow-hidden rounded-xl px-2.5 py-2.5 transition-all ${
                                        active ? 'text-white' : 'text-white/45 hover:text-white/80'
                                    }`}
                                    style={active ? { background: createTabGlow } : undefined}
                                >
                                    {active && (
                                        <span className="absolute inset-0 rounded-xl ring-1 ring-inset ring-orange-400/20" />
                                    )}
                                    <span className={`relative flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${active ? 'bg-white/10' : 'bg-white/[0.04]'}`}>
                                        {tab.id === 'create' ? (
                                            <svg className={`h-4 w-4 ${active ? 'text-orange-300' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                <rect width="18" height="18" x="3" y="3" rx="2" ry="2" />
                                                <circle cx="9" cy="9" r="2" />
                                                <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" />
                                            </svg>
                                        ) : (
                                            <svg className={`h-4 w-4 ${active ? 'text-orange-300' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                <path d="M16 3h5v5" />
                                                <path d="M8 3H3v5" />
                                                <path d="M12 22v-8.3a4 4 0 0 0-1.172-2.872L3 3" />
                                                <path d="m15 9 6-6" />
                                            </svg>
                                        )}
                                    </span>
                                    <span className="relative flex min-w-0 flex-col items-start gap-0.5 leading-tight">
                                        <span className="text-[13px] font-semibold tracking-tight">{tab.label}</span>
                                        <span className="text-[10px] text-white/35">{tab.hint}</span>
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </div>

                {/* Prompt card */}
                <div className="space-y-3 px-3 py-3">
                    {/* Model trigger */}
                    <button
                        type="button"
                        onClick={() => setModelOpen(true)}
                        className="flex w-full items-center justify-between rounded-2xl border border-white/[0.08] bg-white/[0.035] px-3.5 py-3 transition hover:bg-white/[0.05]"
                    >
                        <div className="flex items-center gap-3">
                            <ModelAvatar name={selectedModelMeta.name} icon={'icon' in selectedModelMeta ? selectedModelMeta.icon : null} brandIcon={selectedModelMeta.brandIcon} />
                            <div className="text-left">
                                <p className="text-[11px] text-white/40">{t('model')}</p>
                                <p className="text-sm font-semibold text-white">{formatModelName(selectedModel)}</p>
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            {creditCost > 0 && (
                                <span className="inline-flex items-center gap-1 rounded-md border border-orange-400/25 bg-orange-500/10 px-2 py-1 text-[11px] font-medium tabular-nums text-orange-200">
                                    <CreditBoltIcon className="h-3 w-3 text-amber-300" />
                                    {creditCost}
                                </span>
                            )}
                            <svg className="h-4 w-4 text-white/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                <path d="m9 18 6-6-6-6" />
                            </svg>
                        </div>
                    </button>

                    {isVariations && (
                        <div className="overflow-hidden rounded-2xl border border-white/[0.08] bg-gradient-to-b from-white/[0.05] to-white/[0.02] shadow-[0_0_0_1px_rgba(255,255,255,0.02)_inset]">
                            <div className="px-4 pb-2 pt-3.5">
                                <p className="text-sm font-semibold text-white">{t('image.startWithImages')}</p>
                                <p className="mt-0.5 text-[11px] text-white/35">{t('image.startWithImagesSub')}</p>
                            </div>

                            <div className="mx-3 mb-3">
                                {refs.length === 0 ? (
                                    <button
                                        type="button"
                                        onClick={() => fileRef.current?.click()}
                                        className="group flex w-full flex-col items-center gap-3 rounded-xl border border-dashed border-white/15 bg-black/20 px-3 py-6 text-center transition hover:border-orange-400/40 hover:bg-orange-500/[0.04]"
                                    >
                                        <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500/20 to-red-500/10 ring-1 ring-orange-400/20 transition group-hover:scale-105">
                                            <svg className="h-6 w-6 text-orange-300/80" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                                <polyline points="17 8 12 3 7 8" />
                                                <line x1="12" x2="12" y1="3" y2="15" />
                                            </svg>
                                        </div>
                                        <div>
                                            <p className="text-sm font-semibold text-white">{t('image.uploadRefs')}</p>
                                            <p className="mt-1 text-xs text-white/40">{t('image.upTo8')}</p>
                                        </div>
                                    </button>
                                ) : (
                                    <div className="rounded-xl border border-white/10 bg-black/20 p-2.5">
                                        <div className="mb-2 flex items-center justify-between px-0.5">
                                            <span className="text-xs font-medium text-white/60">
                                                {imageRefs.length}/8 images
                                                {refs.some((r) => r.kind === 'video') && imageRefs.length === 0 && (
                                                    <span className="ms-1 text-amber-300/80">· video not supported yet</span>
                                                )}
                                            </span>
                                            {refs.length < 8 && (
                                                <button
                                                    type="button"
                                                    onClick={() => fileRef.current?.click()}
                                                    className="text-[11px] font-medium text-orange-300 hover:text-orange-200"
                                                >
                                                    + Add
                                                </button>
                                            )}
                                        </div>
                                        <div className="flex gap-2 overflow-x-auto pb-1 scrollbar-thin">
                                            {refs.map((r, index) => (
                                                <div key={r.id} className="relative h-16 w-16 shrink-0 overflow-hidden rounded-lg ring-1 ring-white/10">
                                                    {r.kind === 'video' ? (
                                                        <video src={r.url} className="size-full object-cover" muted />
                                                    ) : (
                                                        <img src={r.url} alt="" className="size-full object-cover" />
                                                    )}
                                                    <span className="absolute bottom-1 start-1 rounded bg-black/75 px-1 py-0.5 text-[8px] font-semibold text-orange-200">
                                                        @{r.kind}{refs.slice(0, index + 1).filter((item) => item.kind === r.kind).length}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        onClick={() => removeRef(r.id)}
                                                        aria-label={t('remove')}
                                                        className="absolute end-0.5 top-0.5 z-10 flex h-6 w-6 items-center justify-center rounded-full bg-black/75 text-xs text-white ring-1 ring-white/20 md:end-1 md:top-1 md:h-5 md:w-5 md:text-[10px]"
                                                    >
                                                        ×
                                                    </button>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    <div className="overflow-hidden rounded-2xl border border-white/[0.08] bg-gradient-to-b from-white/[0.05] to-white/[0.02] shadow-[0_0_0_1px_rgba(255,255,255,0.02)_inset]">
                        <div className="flex items-center justify-between px-4 pb-2 pt-3.5">
                            <div>
                                <p className="text-sm font-semibold text-white">
                                    {isVariations ? t('image.describeVariation') : t('image.describeImage')}
                                </p>
                                <p className="mt-0.5 text-[11px] text-white/35">
                                    {isVariations
                                        ? t('image.placeholderVariation')
                                        : t('image.placeholderCreate')}
                                </p>
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

                        <input
                            ref={fileRef}
                            type="file"
                            accept={isVariations ? 'image/*,video/*' : 'image/*'}
                            multiple
                            className="hidden"
                            onChange={(e) => {
                                addRefs(e.target.files);
                                e.target.value = '';
                            }}
                        />

                        <div className="px-3 pb-2">
                            {/* Composer: attach + preview refs live inside the prompt box */}
                            <div className="rounded-xl border border-white/10 bg-black/30 transition focus-within:border-orange-400/40 focus-within:ring-2 focus-within:ring-orange-500/15">
                                {!isVariations && (
                                    <div className="px-2.5 pt-2.5">
                                        {refs.length === 0 ? (
                                            <button
                                                type="button"
                                                onClick={() => fileRef.current?.click()}
                                                className="group flex w-full items-center gap-3 rounded-lg border border-dashed border-white/15 bg-white/[0.02] px-3 py-2.5 text-left transition hover:border-orange-400/40 hover:bg-orange-500/[0.04]"
                                            >
                                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-orange-500/20 to-red-500/10 ring-1 ring-orange-400/20 transition group-hover:scale-105">
                                                    <svg className="h-4 w-4 text-orange-300/90" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                        <rect width="18" height="18" x="3" y="3" rx="2" ry="2" />
                                                        <circle cx="9" cy="9" r="2" />
                                                        <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" />
                                                    </svg>
                                                </div>
                                                    <div className="min-w-0 flex-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="text-[13px] font-semibold text-white">{t('image.addRefs')}</span>
                                                        <span className="rounded-full border border-emerald-500/30 bg-emerald-500/15 px-1.5 py-0.5 text-[10px] font-medium text-emerald-300">
                                                            Optional
                                                        </span>
                                                    </div>
                                                    <p className="mt-0.5 text-[11px] text-white/40">{t('image.addRefsHint')}</p>
                                                </div>
                                                <span className="text-[11px] text-white/30">0/8</span>
                                            </button>
                                        ) : (
                                            <div className="flex items-center gap-2 overflow-x-auto pb-0.5 scrollbar-thin">
                                                {refs.map((r, index) => (
                                                    <div key={r.id} className="group relative h-14 w-14 shrink-0 overflow-hidden rounded-lg ring-1 ring-white/10">
                                                        {r.kind === 'video' ? (
                                                            <video src={r.url} className="size-full object-cover" muted />
                                                        ) : (
                                                            <img src={r.url} alt="" className="size-full object-cover" />
                                                        )}
                                                        <span className="absolute bottom-0.5 start-0.5 rounded bg-black/75 px-1 py-px text-[8px] font-semibold text-orange-200">
                                                            @{r.kind}
                                                            {refs.slice(0, index + 1).filter((item) => item.kind === r.kind).length}
                                                        </span>
                                                        <button
                                                            type="button"
                                                            onClick={() => removeRef(r.id)}
                                                            aria-label={t('remove')}
                                                            className="absolute end-0.5 top-0.5 z-10 flex h-5 w-5 items-center justify-center rounded-full bg-black/75 text-[10px] text-white ring-1 ring-white/20"
                                                        >
                                                            ×
                                                        </button>
                                                    </div>
                                                ))}
                                                {refs.length < 8 && (
                                                    <button
                                                        type="button"
                                                        onClick={() => fileRef.current?.click()}
                                                        className="flex h-14 w-14 shrink-0 flex-col items-center justify-center gap-0.5 rounded-lg border border-dashed border-white/15 text-white/45 transition hover:border-orange-400/40 hover:text-orange-200"
                                                        title={t('image.addRefs')}
                                                    >
                                                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                            <path d="M12 5v14M5 12h14" />
                                                        </svg>
                                                        <span className="text-[9px] font-medium">{refs.length}/8</span>
                                                    </button>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}

                                <AssetMentionTextarea
                                    value={prompt}
                                    onChange={setPrompt}
                                    mentions={assetMentions}
                                    maxLength={5000}
                                    placeholder={
                                        isVariations
                                            ? t('image.placeholderVariation')
                                            : placeholder
                                    }
                                    rows={4}
                                    minRows={4}
                                    maxRows={12}
                                    className="w-full border-0 bg-transparent px-3.5 py-3 text-[15px] leading-6 text-white outline-none placeholder:text-white/30 focus:ring-0 sm:text-sm sm:leading-relaxed"
                                />

                                <div className="flex items-center justify-between gap-2 px-2.5 pb-2">
                                    <span className="text-[10px] text-white/30">
                                        {!isVariations && refs.length > 0 ? t('mentionHint') : ' '}
                                    </span>
                                    <span className={`text-[11px] ${prompt.length > 800 ? 'text-orange-300' : 'text-white/30'}`}>{prompt.length}/1200</span>
                                </div>
                            </div>
                        </div>

                        <div className="flex items-center justify-between gap-2 border-t border-white/[0.05] px-3 py-2.5">
                            <div className="flex items-center gap-1">
                                <ChipButton title={t('image.characters')}>
                                    <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                                        <circle cx="9" cy="7" r="4" />
                                        <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
                                        <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                                    </svg>
                                </ChipButton>
                                <ChipButton title={t('image.style')}>
                                    <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                        <circle cx="13.5" cy="6.5" r="0.5" fill="currentColor" />
                                        <circle cx="17.5" cy="10.5" r="0.5" fill="currentColor" />
                                        <circle cx="8.5" cy="7.5" r="0.5" fill="currentColor" />
                                        <path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z" />
                                    </svg>
                                </ChipButton>
                            </div>
                            <button
                                type="button"
                                onClick={() => setAutoPolish((v) => !v)}
                                className="flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.03] px-2.5 py-1.5"
                            >
                                <span className="text-[11px] text-white/55">{t('image.autoPolish')}</span>
                                <span className={`relative h-5 w-9 rounded-full transition ${autoPolish ? 'bg-orange-500' : 'bg-white/15'}`} dir="ltr">
                                    <span className={`absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition ${autoPolish ? 'left-4' : 'left-0.5'}`} />
                                </span>
                            </button>
                        </div>
                    </div>

                    {/* Aspect visual picker */}
                    <div>
                        <p className="mb-2 px-0.5 text-[11px] font-medium uppercase tracking-wider text-white/35">{t('aspectRatio')}</p>
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

                    {/* Resolution */}
                    <div>
                        <p className="mb-2 px-0.5 text-[11px] font-medium uppercase tracking-wider text-white/35">{t('resolution')}</p>
                        <div
                            className="grid gap-1.5"
                            style={{
                                gridTemplateColumns: `repeat(${Math.min(3, Math.max(2, availableResolutions.length))}, minmax(0, 1fr))`,
                            }}
                        >
                            {availableResolutions.map((r) => {
                                const sub =
                                    r.subKey === 'fast'
                                        ? t('image.resFast')
                                        : r.subKey === 'balanced'
                                          ? t('image.resBalanced')
                                          : r.subKey === 'max'
                                            ? t('image.resMax')
                                            : r.id;
                                return (
                                    <button
                                        key={r.id}
                                        type="button"
                                        onClick={() => setResolution(r.id)}
                                        className={`rounded-xl border px-2 py-2.5 text-center transition ${
                                            resolution === r.id
                                                ? 'border-orange-400/50 bg-orange-500/15 text-orange-100'
                                                : 'border-white/[0.07] bg-white/[0.03] text-white/55 hover:text-white'
                                        }`}
                                    >
                                        <span className="block text-sm font-semibold">{r.id}</span>
                                        <span className={`mt-0.5 block text-[10px] ${resolution === r.id ? 'text-orange-200/70' : 'text-white/30'}`}>
                                            {sub}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    {/* Quantity */}
                    <div>
                        <p className="mb-2 px-0.5 text-[11px] font-medium uppercase tracking-wider text-white/35">{t('outputs')}</p>
                        <div className="grid grid-cols-4 gap-1.5">
                            {[1, 2, 3, 4].map((n) => (
                                <button
                                    key={n}
                                    type="button"
                                    onClick={() => setQuantity(n)}
                                    className={`flex h-11 items-center justify-center gap-1.5 rounded-xl border text-sm font-semibold transition ${
                                        quantity === n
                                            ? 'border-orange-400/50 bg-orange-500/15 text-orange-100'
                                            : 'border-white/[0.07] bg-white/[0.03] text-white/50 hover:text-white'
                                    }`}
                                >
                                    <span className="flex gap-0.5">
                                        {Array.from({ length: n }).map((_, i) => (
                                            <span
                                                key={i}
                                                className={`h-1.5 w-1.5 rounded-[2px] ${quantity === n ? 'bg-orange-300' : 'bg-white/35'}`}
                                            />
                                        ))}
                                    </span>
                                    <span>{n}</span>
                                </button>
                            ))}
                        </div>
                    </div>
                </div>
            </div>

            {/* Sticky generate bar */}
            <div className="relative shrink-0 border-t border-white/[0.07] bg-[#0a0a0f]/95 p-3 backdrop-blur-xl">
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
                        <span className="rounded-lg border border-white/10 bg-white/[0.04] px-2 py-1 text-[11px] text-white/65">×{quantity}</span>
                        {imageRefs.length > 0 && (
                            <span className="rounded-lg border border-white/10 bg-white/[0.04] px-2 py-1 text-[11px] text-white/65">
                                {imageRefs.length} ref{imageRefs.length === 1 ? '' : 's'}
                            </span>
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
                        if (loading || !hasMeaningfulPrompt(prompt)) return;
                        onGenerate?.(prompt, {
                            quantity,
                            aspect,
                            resolution,
                            endpointId: 'endpoint_id' in selectedModelMeta ? selectedModelMeta.endpoint_id : undefined,
                            modelName: selectedModelMeta.name,
                            mode,
                            referenceFiles: imageRefs.map((r) => r.file),
                        });
                    }}
                    className="relative flex h-12 w-full items-center justify-center gap-2 overflow-hidden rounded-xl bg-gradient-to-b from-[#FF6A45] via-[#FF5733] to-[#D63A18] text-sm font-semibold text-white shadow-[0_10px_30px_rgba(255,87,51,0.35)] transition disabled:cursor-not-allowed disabled:opacity-45"
                >
                    <span className="absolute inset-0 bg-[linear-gradient(180deg,rgba(255,255,255,0.22),transparent_40%)]" />
                    {loading ? (
                        <span className="relative flex items-center gap-2">
                            <span className="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white" />
                            {t('generating')}
                        </span>
                    ) : !hasEnoughTokens ? (
                        <span className="relative text-white/90">{t('notEnoughTokens', { balance: tokenBalance })}</span>
                    ) : !hasPrompt ? (
                        <span className="relative text-white/90">{t('image.needPrompt')}</span>
                    ) : !canGenerate && isVariations && imageRefs.length === 0 ? (
                        <span className="relative text-white/90">{t('image.needSources')}</span>
                    ) : (
                        <>
                            <svg className="relative h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z" />
                            </svg>
                            <span className="relative">
                                {quantity > 1 ? t('image.generateN', { count: quantity }) : t('image.generateOne')}
                            </span>
                        </>
                    )}
                </motion.button>
            </div>

            {/* Expanded prompt modal */}
            <AnimatePresence>
                {expanded && (
                    <motion.div
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        className="fixed inset-0 z-50 flex items-end justify-center bg-black/70 p-3 backdrop-blur-sm sm:items-center"
                        onClick={() => setExpanded(false)}
                    >
                        <motion.div
                            initial={{ opacity: 0, y: 24 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={{ opacity: 0, y: 16 }}
                            className="w-full max-w-xl rounded-2xl border border-white/10 bg-[#101016] p-4 shadow-2xl"
                            onClick={(e) => e.stopPropagation()}
                        >
                            <div className="mb-3 flex items-center justify-between">
                                <h3 className="text-sm font-semibold text-white">{t('promptEditor')}</h3>
                                <button type="button" onClick={() => setExpanded(false)} className="text-white/50 hover:text-white">
                                    {t('close')}
                                </button>
                            </div>
                            <AssetMentionTextarea
                                autoFocus
                                value={prompt}
                                onChange={setPrompt}
                                mentions={assetMentions}
                                maxLength={5000}
                                rows={10}
                                className="w-full resize-none rounded-xl border border-white/10 bg-black/40 p-3 text-sm leading-relaxed text-white outline-none focus:border-orange-400/40"
                                placeholder={placeholder}
                            />
                        </motion.div>
                    </motion.div>
                )}
            </AnimatePresence>

            {/* Select Model modal */}
            <AnimatePresence>
                {modelOpen && (
                    <motion.div
                        role="presentation"
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        className="fixed inset-0 z-50 flex items-center justify-center bg-black/35 p-3 backdrop-blur-md"
                        onClick={() => setModelOpen(false)}
                    >
                        <motion.div
                            role="dialog"
                            aria-modal="true"
                            aria-labelledby="select-model-title"
                            aria-describedby="select-model-desc"
                            initial={{ opacity: 0, scale: 0.95, y: 12 }}
                            animate={{ opacity: 1, scale: 1, y: 0 }}
                            exit={{ opacity: 0, scale: 0.95, y: 8 }}
                            transition={{ duration: 0.18 }}
                            className="relative grid w-[95vw] max-w-lg gap-4 rounded-lg border border-white/10 bg-black p-6 shadow-lg"
                            onClick={(e) => e.stopPropagation()}
                        >
                            <button
                                type="button"
                                onClick={() => setModelOpen(false)}
                                className="absolute end-4 top-4 rounded-sm text-white/50 transition hover:text-white"
                                aria-label={t('close')}
                            >
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                    <path d="M18 6 6 18" />
                                    <path d="m6 6 12 12" />
                                </svg>
                            </button>

                            <div className="flex flex-col space-y-1.5 text-start pe-6">
                                <h2 id="select-model-title" className="text-lg font-semibold leading-none tracking-tight text-white">
                                    {t('selectModel')}
                                </h2>
                                <p id="select-model-desc" className="text-sm text-white/50">
                                    {t('selectModelSub')}
                                </p>
                            </div>

                            <div className="max-h-[60vh] space-y-1 overflow-y-auto py-2 scrollbar-thin">
                                {(modelsForPicker.length
                                    ? modelsForPicker
                                    : [
                                          {
                                              name: 'Nano Banana Pro',
                                              description: 'High-quality image generation with fast processing',
                                              brandName: 'Nano Banana',
                                              brandIcon: null,
                                              icon: null,
                                              tags: ['Reference', '4K'],
                                          },
                                      ]
                                ).map((m) => {
                                    const active = selectedModel === m.name;
                                    const badge = modelBadge(m.name);
                                    const tags = modelTags(m);
                                    const modelCredits = estimateImageCredits(
                                        m,
                                        {
                                            aspect,
                                            resolution,
                                            quantity,
                                            referenceCount: imageRefs.length,
                                        },
                                        creditsConfig,
                                    ).credits;
                                    return (
                                        <button
                                            key={`${m.brandName}-${m.name}-${'endpoint_id' in m ? m.endpoint_id : ''}`}
                                            type="button"
                                            onClick={() => {
                                                setSelectedBrand(m.brandName);
                                                setSelectedModel(m.name);
                                                setModelOpen(false);
                                            }}
                                            className={`flex w-full items-center gap-3 rounded-lg border p-3 text-start transition ${
                                                active
                                                    ? 'border-orange-400/30 bg-orange-500/10'
                                                    : 'border-transparent bg-white/[0.04] hover:bg-white/[0.07]'
                                            }`}
                                        >
                                            <ModelAvatar name={m.name} icon={'icon' in m ? m.icon : null} brandIcon={m.brandIcon} brand={m.brandName} />
                                            <div className="flex min-w-0 flex-1 flex-col items-start">
                                                <span className="flex items-center gap-2 text-sm font-medium text-white">
                                                    {formatModelName(m.name)}
                                                    {badge && (
                                                        <span
                                                            className={`rounded px-1.5 py-0.5 text-[9px] font-semibold text-white ${
                                                                badge === 'NEW'
                                                                    ? 'bg-gradient-to-r from-[#FF5733] to-[#FF8C00]'
                                                                    : 'bg-gradient-to-r from-[#8B5CF6] to-[#EC4899]'
                                                            }`}
                                                        >
                                                            {badge}
                                                        </span>
                                                    )}
                                                </span>
                                                <span className="line-clamp-1 text-start text-xs text-white/45">
                                                    {m.description || m.brandName}
                                                </span>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-1.5">
                                                {modelCredits > 0 && (
                                                    <span className="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-orange-400/25 bg-orange-500/10 px-1.5 py-0.5 text-[10px] font-medium tabular-nums text-orange-200">
                                                        <CreditBoltIcon className="h-3 w-3 text-amber-300" />
                                                        {modelCredits}
                                                    </span>
                                                )}
                                                {tags.map((tag) => (
                                                    <span
                                                        key={tag}
                                                        className="inline-flex items-center whitespace-nowrap rounded-md border border-white/15 px-1.5 py-0.5 text-[10px] text-white/50"
                                                    >
                                                        {tag}
                                                    </span>
                                                ))}
                                                {active && (
                                                    <svg className="ms-1 h-4 w-4 text-orange-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                        <path d="M20 6 9 17l-5-5" />
                                                    </svg>
                                                )}
                                            </div>
                                        </button>
                                    );
                                })}
                            </div>

                            <div className="flex justify-end">
                                <button
                                    type="button"
                                    onClick={() => setModelOpen(false)}
                                    className="inline-flex min-h-9 items-center justify-center rounded-md border border-white/15 px-4 py-2 text-sm font-medium text-white/80 transition hover:bg-white/[0.06] hover:text-white"
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

function modelBadge(name: string): 'NEW' | 'POPULAR' | null {
    const key = name.toLowerCase();
    if (/(gpt-image-2|nano-banana-pro)/.test(key)) return 'POPULAR';
    if (/(nano-banana-2|seedream.?5|seedream45|flux2)/.test(key)) return 'NEW';
    return null;
}

function modelTags(m: { tags?: string[]; description?: string; brandName?: string; enums?: Record<string, unknown> | null }) {
    if (m.tags?.length) return m.tags.slice(0, 3);
    const tags = ['Reference'];
    const blob = `${m.description || ''} ${JSON.stringify(m.enums || {})}`.toLowerCase();
    if (blob.includes('4k')) tags.push('4K');
    else if (blob.includes('3k')) tags.push('3K');
    else if (blob.includes('2k')) tags.push('2K');
    return tags;
}

function ModelAvatar({
    name,
    icon,
    brandIcon,
    brand,
}: {
    name: string;
    icon?: string | null;
    brandIcon?: string | null;
    brand?: string;
}) {
    const src = icon || brandIcon;
    if (src) {
        return <img src={src} alt={name} className="h-10 w-10 shrink-0 rounded-md object-contain bg-white/5 p-1" loading="lazy" />;
    }
    const label = (brand || name || 'M')[0]?.toUpperCase() || 'M';
    return (
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-gradient-to-br from-orange-400/30 to-fuchsia-500/20 text-sm font-bold text-orange-100">
            {label}
        </div>
    );
}

function ChipButton({ children, title }: { children: React.ReactNode; title: string }) {
    return (
        <button
            type="button"
            title={title}
            className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-white/10 text-white/45 transition hover:bg-white/[0.06] hover:text-white"
        >
            {children}
        </button>
    );
}
