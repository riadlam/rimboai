import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { Brand, BrandVoice } from '@/types';
import type { CreditsConfig } from '@/lib/imageCredits';
import { estimateVoiceCredits } from '@/lib/voiceCredits';
import { publicAsset } from '@/lib/publicAsset';
import { LabModelPickerModal, LabModelPickerTrigger, type LabPickerModel } from '@/Components/LabModelPicker';

export type VoiceGenerateOptions = {
    voice: string;
    voiceName: string;
    model: string;
    endpointId: string;
    stability: number;
    clarity: number;
    styleExaggeration: number;
    speed: number;
};

type Props = {
    brands?: Brand[];
    onGenerate?: (text: string, options?: VoiceGenerateOptions) => void;
    loading?: boolean;
    creditsConfig?: CreditsConfig;
    tokenBalance?: number;
};

const MAX_CHARS = 70000;

const QUICK_PROMPTS = [
    {
        id: 'dream-loop',
        label: 'Dream Loop',
        text: 'Close your eyes and breathe in slowly. Feel the quiet settle around you as soft light fills the room. You are safe, calm, and ready to rest.',
    },
    {
        id: 'podcast-intro',
        label: 'Podcast Intro',
        text: "Welcome back to the show. Today we're unpacking ideas that change how you create, think, and ship — with practical takes you can use right away.",
    },
    {
        id: 'wellness',
        label: 'Wellness Guide',
        text: 'Take a moment for yourself. Roll your shoulders back, soften your jaw, and notice one thing you can release before we begin.',
    },
    {
        id: 'story',
        label: 'Story Opening',
        text: 'The night the city stopped humming, she heard a voice in the static — clear, familiar, and impossible to ignore.',
    },
    {
        id: 'tutorial',
        label: 'Tutorial Voice',
        text: "In this quick walkthrough, I'll show you each step clearly. Follow along at your own pace — pause anytime, and we'll continue when you're ready.",
    },
] as const;

const VOICE_USE_CASES = [
    'Conversational',
    'Narration',
    'Characters',
    'Social Media',
    'Entertainment',
    'Meditation',
] as const;

const LANG_LABELS: Record<string, string> = {
    en: 'English',
    zh: 'Chinese',
    nl: 'Dutch',
    fr: 'French',
    de: 'German',
    it: 'Italian',
    ja: 'Japanese',
    ko: 'Korean',
    pl: 'Polish',
    pt: 'Portuguese',
    es: 'Spanish',
    ru: 'Russian',
    hi: 'Hindi',
    he: 'Hebrew',
    ar: 'Arabic',
};

const VOICE_GRADIENTS = [
    'linear-gradient(135deg, rgb(139, 92, 246) 0%, rgb(236, 72, 153) 50%, rgb(249, 115, 22) 100%)',
    'linear-gradient(135deg, #0ea5e9 0%, #6366f1 55%, #a855f7 100%)',
    'linear-gradient(135deg, #f59e0b 0%, #ef4444 50%, #ec4899 100%)',
    'linear-gradient(135deg, #22c55e 0%, #14b8a6 50%, #06b6d4 100%)',
    'linear-gradient(135deg, #f472b6 0%, #fb7185 45%, #fb923c 100%)',
    'linear-gradient(135deg, #a855f7 0%, #6366f1 50%, #3b82f6 100%)',
] as const;

type VoiceOption = {
    id: string;
    voice_key: string;
    name: string;
    category: string;
    language: string | null;
    sample_url: string | null;
    gradient: string;
};

function languageLabel(code: string | null | undefined): string {
    if (!code) return '';
    const key = code.toLowerCase();
    return LANG_LABELS[key] || code.toUpperCase();
}

function voiceGradient(seed: string): string {
    let hash = 0;
    for (let i = 0; i < seed.length; i++) hash = (hash * 31 + seed.charCodeAt(i)) >>> 0;
    return VOICE_GRADIENTS[hash % VOICE_GRADIENTS.length];
}

function mapBrandVoice(v: BrandVoice): VoiceOption {
    const category =
        v.category && VOICE_USE_CASES.includes(v.category as (typeof VOICE_USE_CASES)[number])
            ? v.category
            : 'Conversational';

    return {
        id: String(v.id),
        voice_key: v.voice_key,
        name: v.name,
        category,
        language: v.language ? v.language.toLowerCase() : null,
        sample_url: publicAsset(v.sample_url),
        gradient: voiceGradient(v.voice_key || v.name),
    };
}

function pickDefaultVoice(voices: VoiceOption[], brandVoices: BrandVoice[]): VoiceOption | null {
    if (voices.length === 0) return null;
    const defaultBrand = brandVoices.find((v) => v.is_default);
    if (defaultBrand) {
        const match = voices.find((v) => v.id === String(defaultBrand.id));
        if (match) return match;
    }
    return voices[0];
}

function voiceControlCapabilities(endpointId?: string | null): {
    stability: boolean;
    clarity: boolean;
    style: boolean;
    speed: boolean;
} {
    const id = (endpointId || '').toLowerCase();
    if (id.includes('elevenlabs') || id.includes('/eleven') || id.includes('eleven-v') || id.includes('turbo-v')) {
        return { stability: true, clarity: true, style: true, speed: true };
    }
    if (id.includes('minimax')) {
        return { stability: false, clarity: false, style: false, speed: true };
    }
    return { stability: false, clarity: false, style: false, speed: false };
}

export default function VoiceLabCreateForm({
    brands = [],
    onGenerate,
    loading = false,
    creditsConfig,
    tokenBalance = 0,
}: Props) {
    const { t } = useTranslation('lab');
    const [text, setText] = useState('');
    const [modelOpen, setModelOpen] = useState(false);
    const [voiceOpen, setVoiceOpen] = useState(false);
    const [controlsOpen, setControlsOpen] = useState(false);
    const [voiceId, setVoiceId] = useState('');
    const [favoriteIds, setFavoriteIds] = useState<string[]>([]);
    const [voiceSearch, setVoiceSearch] = useState('');
    const [voiceCategory, setVoiceCategory] = useState<(typeof VOICE_USE_CASES)[number] | 'All' | 'Favorites'>('Conversational');
    const [voiceLanguage, setVoiceLanguage] = useState<string>('all');
    const [previewVoiceId, setPreviewVoiceId] = useState<string | null>(null);
    const [stability, setStability] = useState(50);
    const [clarity, setClarity] = useState(75);
    const [styleExaggeration, setStyleExaggeration] = useState(20);
    const [speed, setSpeed] = useState(100);
    const [selectedBrand, setSelectedBrand] = useState(brands[0]?.name || 'ElevenLabs');
    const [selectedModel, setSelectedModel] = useState(brands[0]?.models[0]?.name || 'Eleven Multilingual v2');
    const previewAudioRef = useRef<HTMLAudioElement | null>(null);

    const allModels = useMemo(
        (): LabPickerModel[] =>
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
        () => allModels.find((m) => m.name === selectedModel) || allModels[0] || null,
        [allModels, selectedModel],
    );

    const modelBrandVoices = selectedModelRecord?.voices;
    const modelVoices = useMemo(
        () => (modelBrandVoices ?? []).map(mapBrandVoice),
        [modelBrandVoices],
    );

    const voiceCategories = useMemo(() => ['All', 'Favorites', ...VOICE_USE_CASES] as const, []);

    const availableLanguages = useMemo(() => {
        const codes = Array.from(
            new Set(modelVoices.map((v) => v.language).filter((lang): lang is string => Boolean(lang))),
        ).sort((a, b) => languageLabel(a).localeCompare(languageLabel(b)));
        return codes;
    }, [modelVoices]);

    const supportsLanguageFilter = availableLanguages.length > 0;

    const selectedVoice = useMemo(() => {
        if (modelVoices.length === 0) return null;
        return modelVoices.find((v) => v.id === voiceId) || pickDefaultVoice(modelVoices, modelBrandVoices ?? []);
    }, [modelVoices, modelBrandVoices, voiceId]);

    const filteredVoices = useMemo(() => {
        const q = voiceSearch.trim().toLowerCase();
        return modelVoices.filter((v) => {
            if (supportsLanguageFilter && voiceLanguage !== 'all' && v.language !== voiceLanguage) return false;
            if (voiceCategory === 'Favorites') {
                if (!favoriteIds.includes(v.id)) return false;
            } else if (voiceCategory !== 'All' && v.category !== voiceCategory) {
                return false;
            }
            if (q && !v.name.toLowerCase().includes(q) && !v.voice_key.toLowerCase().includes(q)) return false;
            return true;
        });
    }, [modelVoices, voiceSearch, voiceCategory, voiceLanguage, favoriteIds, supportsLanguageFilter]);

    const controlCaps = useMemo(
        () => voiceControlCapabilities(selectedModelRecord?.endpoint_id),
        [selectedModelRecord?.endpoint_id],
    );
    const hasAnyVoiceControls =
        controlCaps.stability || controlCaps.clarity || controlCaps.style || controlCaps.speed;

    const creditEstimate = useMemo(() => {
        // Always show at least the minimum charge (1 character) so the bar never reads 0/—
        return estimateVoiceCredits(selectedModelRecord, Math.max(1, text.trim().length), creditsConfig);
    }, [selectedModelRecord, text, creditsConfig]);
    const creditCost = creditEstimate.credits;
    const hasEnoughTokens = creditCost > 0 && tokenBalance >= creditCost;

    const canGenerate = Boolean(text.trim()) && Boolean(selectedVoice?.voice_key) && hasEnoughTokens;

    const stopPreview = () => {
        if (previewAudioRef.current) {
            previewAudioRef.current.pause();
            previewAudioRef.current.src = '';
            previewAudioRef.current = null;
        }
        setPreviewVoiceId(null);
    };

    useEffect(() => {
        if (brands[0] && !brands.find((b) => b.name === selectedBrand)) {
            setSelectedBrand(brands[0].name);
            setSelectedModel(brands[0].models[0]?.name || 'Eleven Multilingual v2');
        }
    }, [brands, selectedBrand]);

    useEffect(() => {
        const next = pickDefaultVoice(modelVoices, modelBrandVoices ?? []);
        if (!next) {
            setVoiceId('');
            return;
        }
        if (!modelVoices.some((v) => v.id === voiceId)) {
            setVoiceId(next.id);
        }
    }, [selectedModel, modelVoices, modelBrandVoices, voiceId]);

    useEffect(() => {
        setVoiceCategory('Conversational');
        setVoiceLanguage('all');
        stopPreview();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset filters when model changes
    }, [selectedModel]);

    useEffect(() => {
        if (!supportsLanguageFilter && voiceLanguage !== 'all') {
            setVoiceLanguage('all');
        } else if (
            supportsLanguageFilter &&
            voiceLanguage !== 'all' &&
            !availableLanguages.includes(voiceLanguage)
        ) {
            setVoiceLanguage('all');
        }
    }, [supportsLanguageFilter, availableLanguages, voiceLanguage]);

    useEffect(() => {
        if (!voiceOpen) {
            setVoiceSearch('');
            stopPreview();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [voiceOpen]);

    useEffect(() => () => stopPreview(), []);

    const previewVoice = (id?: string) => {
        const targetId = id || selectedVoice?.id;
        if (!targetId) return;
        const voice = modelVoices.find((v) => v.id === targetId);
        if (!voice?.sample_url) return;

        if (previewVoiceId === voice.id && previewAudioRef.current) {
            stopPreview();
            return;
        }

        stopPreview();
        const audio = new Audio(voice.sample_url);
        previewAudioRef.current = audio;
        setPreviewVoiceId(voice.id);
        audio.play().catch(() => {
            if (previewAudioRef.current === audio) stopPreview();
        });
        audio.onended = () => {
            if (previewAudioRef.current === audio) stopPreview();
        };
        audio.onerror = () => {
            if (previewAudioRef.current === audio) stopPreview();
        };
    };

    const toggleFavorite = (id: string) => {
        setFavoriteIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
    };

    return (
        <div className="relative flex w-full flex-col overflow-x-hidden bg-black md:h-full md:min-h-0 [&_a]:cursor-pointer [&_button]:cursor-pointer [&_button:disabled]:cursor-not-allowed [&_label]:cursor-pointer">
            <div className="pointer-events-none absolute inset-x-0 top-0 h-36 bg-[radial-gradient(ellipse_at_top,rgba(255,87,51,0.16),transparent_70%)]" />

            {/* Header */}
            <div className="relative flex shrink-0 items-center justify-between gap-2 border-b border-white/[0.07] px-4 py-3">
                <div className="flex min-w-0 items-center gap-2">
                    <h1 className="text-base font-semibold tracking-tight text-white">{t('voice.title')}</h1>
                </div>
                <LabModelPickerTrigger
                    modelName={selectedModelRecord?.name || selectedModel}
                    icon={selectedModelRecord?.icon}
                    imageCover={selectedModelRecord?.image_cover}
                    onClick={() => setModelOpen(true)}
                />
            </div>

            <div className="relative overflow-x-hidden p-4 md:min-h-0 md:flex-1 md:overflow-y-auto md:scrollbar-thin">
                <div className="space-y-4">
                    {/* Text */}
                    <div className="space-y-2">
                        <div className="flex items-center justify-between">
                            <label htmlFor="voice-text-input" className="text-sm font-medium text-zinc-200">
                                {t('voice.textToConvert')}
                            </label>
                            <span className="text-xs text-zinc-500">
                                {text.length.toLocaleString()} / {MAX_CHARS.toLocaleString()}
                            </span>
                        </div>
                        <textarea
                            id="voice-text-input"
                            value={text}
                            onChange={(e) => setText(e.target.value.slice(0, MAX_CHARS))}
                            placeholder={t('voice.placeholder')}
                            className="min-h-[120px] w-full resize-none rounded-xl border border-white/10 bg-black/30 px-3 py-2.5 text-sm leading-relaxed text-white outline-none placeholder:text-white/30 focus:border-orange-400/40 focus:ring-2 focus:ring-orange-500/15"
                        />
                    </div>

                    {/* Quick prompts */}
                    <div className="space-y-2">
                        <label className="text-sm font-medium text-zinc-200">{t('voice.quickPrompts')}</label>
                        <div className="flex flex-wrap gap-1.5">
                            {QUICK_PROMPTS.map((p) => (
                                <button
                                    key={p.id}
                                    type="button"
                                    onClick={() => setText(p.text)}
                                    className="inline-flex h-7 cursor-pointer items-center rounded-lg border border-white/10 bg-white/[0.03] px-2 text-xs font-medium text-zinc-300 shadow-sm transition hover:border-orange-400/35 hover:bg-orange-500/10 hover:text-orange-100"
                                >
                                    {p.label}
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* Select Voice */}
                    <div className="space-y-2">
                        <label className="flex items-center gap-2 text-sm font-medium text-zinc-200">
                            <svg className="h-4 w-4 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                <path d="M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z" />
                                <path d="M16 9a5 5 0 0 1 0 6" />
                                <path d="M19.364 18.364a9 9 0 0 0 0-12.728" />
                            </svg>
                            {t('voice.selectVoice')}
                        </label>
                        <button
                            type="button"
                            onClick={() => setVoiceOpen(true)}
                            disabled={modelVoices.length === 0}
                            className="flex w-full cursor-pointer items-center gap-3 rounded-xl border border-white/10 bg-white/[0.03] p-3 text-start transition hover:border-orange-400/30 hover:bg-white/[0.05] disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {selectedVoice ? (
                                <>
                                    <span
                                        role="button"
                                        title={selectedVoice.sample_url ? t('voice.playSample') : t('voice.noSample')}
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            previewVoice(selectedVoice.id);
                                        }}
                                        className={`relative flex h-10 w-10 shrink-0 items-center justify-center rounded-xl shadow-lg ${
                                            selectedVoice.sample_url ? 'cursor-pointer' : 'cursor-not-allowed opacity-50'
                                        }`}
                                        style={{ background: selectedVoice.gradient }}
                                    >
                                        {previewVoiceId === selectedVoice.id ? (
                                            <span className="flex gap-0.5">
                                                <span className="h-3 w-0.5 animate-pulse rounded-full bg-white" />
                                                <span className="h-3 w-0.5 animate-pulse rounded-full bg-white [animation-delay:100ms]" />
                                                <span className="h-3 w-0.5 animate-pulse rounded-full bg-white [animation-delay:200ms]" />
                                            </span>
                                        ) : (
                                            <svg className="ms-0.5 h-4 w-4 fill-white text-white" viewBox="0 0 24 24">
                                                <polygon points="6 3 20 12 6 21 6 3" />
                                            </svg>
                                        )}
                                    </span>
                                    <div className="min-w-0 flex-1 overflow-hidden">
                                        <div className="truncate text-sm font-semibold text-zinc-100">{selectedVoice.name}</div>
                                        <div className="mt-1 flex flex-wrap gap-1">
                                            {selectedVoice.language && (
                                                <span className="inline-flex rounded-md border border-transparent bg-white/[0.08] px-2 py-0.5 text-[10px] font-semibold text-zinc-400">
                                                    {languageLabel(selectedVoice.language)}
                                                </span>
                                            )}
                                            <span className="inline-flex rounded-md border border-transparent bg-white/[0.08] px-2 py-0.5 text-[10px] font-semibold capitalize text-zinc-400">
                                                {selectedVoice.category}
                                            </span>
                                        </div>
                                    </div>
                                </>
                            ) : (
                                <div className="min-w-0 flex-1 text-sm text-zinc-500">{t('voice.noVoicesModel')}</div>
                            )}
                            <svg className="h-4 w-4 shrink-0 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                <path d="m9 18 6-6-6-6" />
                            </svg>
                        </button>
                    </div>

                    {/* Voice Controls — only for models whose fal schema supports them */}
                    {hasAnyVoiceControls && (
                        <div className="pt-1">
                            <button
                                type="button"
                                onClick={() => setControlsOpen((v) => !v)}
                                className="flex w-full cursor-pointer items-center justify-between rounded-md px-2 py-2 text-sm font-medium text-zinc-200 transition hover:bg-white/[0.04]"
                            >
                                <div className="flex items-center gap-2">
                                    <svg className="h-4 w-4 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                        <path d="M20 7h-9" />
                                        <path d="M14 17H5" />
                                        <circle cx="17" cy="17" r="3" />
                                        <circle cx="7" cy="7" r="3" />
                                    </svg>
                                    {t('voice.voiceControls')}
                                </div>
                                <svg
                                    className={`h-4 w-4 text-zinc-500 transition ${controlsOpen ? 'rotate-180' : ''}`}
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    strokeWidth="2"
                                >
                                    <path d="m6 9 6 6 6-6" />
                                </svg>
                            </button>
                            <AnimatePresence initial={false}>
                                {controlsOpen && (
                                    <motion.div
                                        initial={{ height: 0, opacity: 0 }}
                                        animate={{ height: 'auto', opacity: 1 }}
                                        exit={{ height: 0, opacity: 0 }}
                                        className="overflow-hidden"
                                    >
                                        <div className="space-y-4 px-1 pb-2 pt-3">
                                            {controlCaps.stability && (
                                                <SliderRow label={t('voice.stability')} value={stability} onChange={setStability} />
                                            )}
                                            {controlCaps.clarity && (
                                                <SliderRow label={t('voice.clarity')} value={clarity} onChange={setClarity} />
                                            )}
                                            {controlCaps.style && (
                                                <SliderRow
                                                    label={t('voice.styleExaggeration')}
                                                    value={styleExaggeration}
                                                    onChange={setStyleExaggeration}
                                                />
                                            )}
                                            {controlCaps.speed && (
                                                <SliderRow label={t('voice.speed')} value={speed} onChange={setSpeed} min={50} max={150} suffix="%" />
                                            )}
                                        </div>
                                    </motion.div>
                                )}
                            </AnimatePresence>
                        </div>
                    )}
                </div>
            </div>

            {/* Sticky create — match image/video lab identity */}
            <div className="relative shrink-0 border-t border-white/[0.07] bg-[#0a0a0f]/95 p-3 backdrop-blur-xl">
                <div className="pointer-events-none absolute inset-x-0 -top-8 h-8 bg-gradient-to-t from-[#0a0a0f] to-transparent" />
                <div className="mb-2.5 flex items-center justify-between gap-2 px-0.5">
                    <div className="flex min-w-0 flex-wrap items-center gap-1.5">
                        {selectedVoice && (
                            <span className="max-w-[140px] truncate rounded-lg border border-white/10 bg-white/[0.04] px-2 py-1 text-[11px] text-white/65">
                                {selectedVoice.name.split(' - ')[0]}
                            </span>
                        )}
                        <span className="rounded-lg border border-white/10 bg-white/[0.04] px-2 py-1 text-[11px] tabular-nums text-white/65">
                            {text.trim().length.toLocaleString()} chars
                        </span>
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
                    whileTap={{ scale: 0.98 }}
                    disabled={loading || !canGenerate}
                    onClick={() =>
                        onGenerate?.(text, {
                            voice: selectedVoice?.voice_key || '',
                            voiceName: selectedVoice?.name || '',
                            model: selectedModel,
                            endpointId: selectedModelRecord?.endpoint_id || '',
                            stability,
                            clarity,
                            styleExaggeration,
                            speed,
                        })
                    }
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
                    ) : !text.trim() ? (
                        <span className="relative text-white/90">{t('voice.needText')}</span>
                    ) : !selectedVoice?.voice_key ? (
                        <span className="relative text-white/90">{t('voice.needVoice')}</span>
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
            </div>

            <LabModelPickerModal
                open={modelOpen}
                models={allModels}
                selectedName={selectedModel}
                onClose={() => setModelOpen(false)}
                title={t('selectModel')}
                subtitle={t('selectModelSub')}
                fallbackDescription="Text-to-speech model"
                onSelect={(m) => {
                    setSelectedBrand(m.brandName);
                    setSelectedModel(m.name);
                    setModelOpen(false);
                }}
            />

            {/* Voice Selection modal */}
            <AnimatePresence>
                {voiceOpen && (
                    <motion.div
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        className="fixed inset-0 z-50 flex items-center justify-center bg-black/35 p-3 backdrop-blur-lg"
                        onClick={() => setVoiceOpen(false)}
                    >
                        <motion.div
                            role="dialog"
                            aria-modal="true"
                            aria-labelledby="voice-selection-title"
                            initial={{ opacity: 0, scale: 0.96, y: 12 }}
                            animate={{ opacity: 1, scale: 1, y: 0 }}
                            exit={{ opacity: 0, scale: 0.97 }}
                            className="relative flex h-[85vh] max-h-[85vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-white/10 bg-black shadow-[0_30px_80px_rgba(0,0,0,0.55)]"
                            onClick={(e) => e.stopPropagation()}
                        >
                            <button
                                type="button"
                                onClick={() => setVoiceOpen(false)}
                                className="absolute end-4 top-4 z-10 rounded-lg p-1.5 text-zinc-500 transition hover:bg-white/[0.06] hover:text-white"
                                aria-label={t('close')}
                            >
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                    <path d="M18 6 6 18" />
                                    <path d="m6 6 12 12" />
                                </svg>
                            </button>

                            <div className="space-y-1.5 px-6 pb-2 pt-6 text-start">
                                <h2 id="voice-selection-title" className="text-lg font-semibold tracking-tight text-white">
                                    {t('voice.voiceSelection')}
                                </h2>
                                <p className="text-sm text-zinc-500">
                                    {modelVoices.length} voices for {selectedModelRecord?.name || selectedModel}
                                </p>
                            </div>

                            <div className="space-y-3 px-6 pb-4">
                                <div className="relative">
                                    <svg
                                        className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        strokeWidth="2"
                                    >
                                        <circle cx="11" cy="11" r="8" />
                                        <path d="m21 21-4.3-4.3" />
                                    </svg>
                                    <input
                                        type="text"
                                        value={voiceSearch}
                                        onChange={(e) => setVoiceSearch(e.target.value)}
                                        placeholder={t('voice.searchVoices')}
                                        className="flex h-9 w-full rounded-lg border border-white/10 bg-black/30 pe-3 ps-10 text-sm text-white outline-none placeholder:text-zinc-500 focus:border-orange-400/40 focus:ring-2 focus:ring-orange-500/15"
                                    />
                                </div>
                                {supportsLanguageFilter && (
                                    <div className="overflow-x-auto scrollbar-thin">
                                        <div className="flex gap-1.5 pb-1">
                                            <button
                                                type="button"
                                                onClick={() => setVoiceLanguage('all')}
                                                className={`inline-flex h-8 shrink-0 cursor-pointer items-center whitespace-nowrap rounded-md px-3 text-xs font-medium transition ${
                                                    voiceLanguage === 'all'
                                                        ? 'bg-white text-zinc-900'
                                                        : 'border border-white/10 bg-white/[0.03] text-zinc-400 hover:bg-white/[0.06] hover:text-zinc-200'
                                                }`}
                                            >
                                                All languages
                                            </button>
                                            {availableLanguages.map((lang) => {
                                                const active = voiceLanguage === lang;
                                                return (
                                                    <button
                                                        key={lang}
                                                        type="button"
                                                        onClick={() => setVoiceLanguage(lang)}
                                                        className={`inline-flex h-8 shrink-0 cursor-pointer items-center whitespace-nowrap rounded-md px-3 text-xs font-medium transition ${
                                                            active
                                                                ? 'bg-white text-zinc-900'
                                                                : 'border border-white/10 bg-white/[0.03] text-zinc-400 hover:bg-white/[0.06] hover:text-zinc-200'
                                                        }`}
                                                    >
                                                        {languageLabel(lang)}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                )}
                                <div className="overflow-x-auto scrollbar-thin">
                                    <div className="flex gap-1.5 pb-1">
                                        {voiceCategories.map((cat) => {
                                            const active = voiceCategory === cat;
                                            return (
                                                <button
                                                    key={cat}
                                                    type="button"
                                                    onClick={() => setVoiceCategory(cat)}
                                                    className={`inline-flex h-8 shrink-0 cursor-pointer items-center whitespace-nowrap rounded-md px-3 text-xs font-medium transition ${
                                                        active
                                                            ? 'bg-gradient-to-b from-[#FF6A45] via-[#FF5733] to-[#D63A18] text-white shadow-[0_6px_16px_rgba(255,87,51,0.25)]'
                                                            : 'border border-white/10 bg-white/[0.03] text-zinc-400 hover:bg-white/[0.06] hover:text-zinc-200'
                                                    }`}
                                                >
                                                    {cat}
                                                    {cat === 'Favorites' && favoriteIds.length > 0 && (
                                                        <span className="ms-1.5 opacity-70">{favoriteIds.length}</span>
                                                    )}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            </div>

                            <div className="min-h-0 flex-1 overflow-y-auto border-t border-white/[0.06] px-4 scrollbar-thin">
                                <div className="space-y-1 py-2">
                                    {filteredVoices.length === 0 ? (
                                        <div className="px-2 py-12 text-center text-sm text-zinc-500">
                                            {modelVoices.length === 0
                                                ? t('voice.noVoicesSynced')
                                                : t('voice.noVoicesFilter')}
                                        </div>
                                    ) : (
                                        filteredVoices.map((v) => {
                                            const active = voiceId === v.id;
                                            const isFav = favoriteIds.includes(v.id);
                                            const isPreviewing = previewVoiceId === v.id;
                                            const hasSample = Boolean(v.sample_url);
                                            return (
                                                <div
                                                    key={v.id}
                                                    role="button"
                                                    tabIndex={0}
                                                    onClick={() => {
                                                        setVoiceId(v.id);
                                                        setVoiceOpen(false);
                                                    }}
                                                    onKeyDown={(e) => {
                                                        if (e.key === 'Enter' || e.key === ' ') {
                                                            e.preventDefault();
                                                            setVoiceId(v.id);
                                                            setVoiceOpen(false);
                                                        }
                                                    }}
                                                    className={`flex w-full cursor-pointer items-center gap-3 rounded-xl p-3 transition ${
                                                        active ? 'bg-white/[0.06]' : 'hover:bg-white/[0.04]'
                                                    }`}
                                                >
                                                    <button
                                                        type="button"
                                                        title={hasSample ? t('voice.playSample') : t('voice.noSample')}
                                                        disabled={!hasSample}
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            previewVoice(v.id);
                                                        }}
                                                        className={`relative flex h-10 w-10 shrink-0 items-center justify-center rounded-lg disabled:cursor-not-allowed disabled:opacity-40 ${
                                                            hasSample ? 'cursor-pointer' : ''
                                                        }`}
                                                        style={{ background: v.gradient }}
                                                    >
                                                        {isPreviewing ? (
                                                            <span className="flex gap-0.5">
                                                                <span className="h-3 w-0.5 animate-pulse rounded-full bg-white" />
                                                                <span className="h-3 w-0.5 animate-pulse rounded-full bg-white [animation-delay:100ms]" />
                                                                <span className="h-3 w-0.5 animate-pulse rounded-full bg-white [animation-delay:200ms]" />
                                                            </span>
                                                        ) : (
                                                            <svg className="ms-0.5 h-4 w-4 fill-white text-white" viewBox="0 0 24 24">
                                                                <polygon points="6 3 20 12 6 21 6 3" />
                                                            </svg>
                                                        )}
                                                    </button>
                                                    <div className="min-w-0 flex-1 text-start">
                                                        <div className="truncate text-sm font-semibold text-zinc-100">{v.name}</div>
                                                        <div className="mt-1 flex flex-wrap gap-1">
                                                            {v.language && (
                                                                <span className="inline-flex rounded-md border border-transparent bg-white/[0.08] px-2.5 py-0.5 text-[10px] font-semibold text-zinc-400">
                                                                    {languageLabel(v.language)}
                                                                </span>
                                                            )}
                                                            <span className="inline-flex rounded-md border border-transparent bg-white/[0.08] px-2.5 py-0.5 text-[10px] font-semibold capitalize text-zinc-400">
                                                                {v.category}
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <button
                                                        type="button"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            toggleFavorite(v.id);
                                                        }}
                                                        className="shrink-0 cursor-pointer rounded-md p-1.5 transition hover:bg-white/[0.06]"
                                                        aria-label={isFav ? t('unfavorite') : t('favorite')}
                                                    >
                                                        <svg
                                                            className={`h-4 w-4 ${isFav ? 'fill-[#FF5733] text-[#FF5733]' : 'text-zinc-500'}`}
                                                            fill={isFav ? 'currentColor' : 'none'}
                                                            viewBox="0 0 24 24"
                                                            stroke="currentColor"
                                                            strokeWidth="2"
                                                        >
                                                            <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z" />
                                                        </svg>
                                                    </button>
                                                </div>
                                            );
                                        })
                                    )}
                                </div>
                            </div>
                        </motion.div>
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    );
}

function SliderRow({
    label,
    value,
    onChange,
    min = 0,
    max = 100,
    suffix,
}: {
    label: string;
    value: number;
    onChange: (v: number) => void;
    min?: number;
    max?: number;
    suffix?: string;
}) {
    const pct = ((value - min) / (max - min)) * 100;
    return (
        <div className="space-y-2">
            <div className="flex items-center justify-between">
                <span className="text-[13px] font-medium text-zinc-300">{label}</span>
                <span className="rounded-md border border-orange-400/20 bg-orange-500/10 px-1.5 py-0.5 text-[11px] font-semibold text-orange-100">
                    {value}
                    {suffix || ''}
                </span>
            </div>
            <div className="relative h-2 rounded-full bg-white/10">
                <div className="absolute inset-y-0 start-0 rounded-full bg-gradient-to-r from-[#FF5733] to-[#FF8C00]" style={{ width: `${pct}%` }} />
                <input
                    type="range"
                    min={min}
                    max={max}
                    value={value}
                    onChange={(e) => onChange(Number(e.target.value))}
                    className="absolute inset-0 h-2 w-full cursor-pointer appearance-none bg-transparent accent-[#FF5733]"
                />
            </div>
        </div>
    );
}

function ModalShell({
    children,
    onClose,
    wide = false,
}: {
    children: React.ReactNode;
    onClose: () => void;
    wide?: boolean;
}) {
    return (
        <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-3 backdrop-blur-lg"
            onClick={onClose}
        >
            <motion.div
                initial={{ opacity: 0, scale: 0.96, y: 12 }}
                animate={{ opacity: 1, scale: 1, y: 0 }}
                exit={{ opacity: 0, scale: 0.97 }}
                className={`relative w-[95vw] overflow-hidden rounded-2xl border border-white/10 bg-zinc-950 p-5 shadow-[0_30px_80px_rgba(0,0,0,0.55)] sm:p-6 ${
                    wide ? 'max-w-xl' : 'max-w-lg'
                }`}
                onClick={(e) => e.stopPropagation()}
            >
                <div className="pointer-events-none absolute inset-x-0 top-0 h-28 bg-[radial-gradient(ellipse_at_top,rgba(255,87,51,0.22),transparent_70%)]" />
                <button
                    type="button"
                    onClick={onClose}
                    className="absolute end-4 top-4 z-10 rounded-lg p-1.5 text-zinc-500 transition hover:bg-white/[0.06] hover:text-white"
                >
                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                        <path d="M18 6 6 18" />
                        <path d="m6 6 12 12" />
                    </svg>
                </button>
                <div className="relative">{children}</div>
            </motion.div>
        </motion.div>
    );
}

function CreditBoltIcon({ className = 'h-3.5 w-3.5' }: { className?: string }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="currentColor" aria-hidden>
            <path d="M13 2 4.5 13.5H11l-1 8.5L19.5 10H13l0-8Z" />
        </svg>
    );
}
