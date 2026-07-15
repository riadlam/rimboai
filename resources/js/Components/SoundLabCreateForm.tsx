import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { Brand, BrandMusicExample } from '@/types';
import { LabModelPickerModal, LabModelPickerTrigger, type LabPickerModel } from '@/Components/LabModelPicker';
import type { CreditsConfig } from '@/lib/imageCredits';
import { estimateMusicCredits, formatMusicDuration, readAudioFileDuration } from '@/lib/musicCredits';
import { matchLabModel, type LabReuseDraft } from '@/lib/labReuse';
import { publicAsset } from '@/lib/publicAsset';
import AssetMentionTextarea, {
    rebasePromptAfterAssetRemoval,
    type AssetMention,
} from '@/Components/AssetMentionTextarea';

type Props = {
    brands?: Brand[];
    onGenerate?: (
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
    ) => void;
    loading?: boolean;
    creditsConfig?: CreditsConfig;
    tokenBalance?: number;
    draft?: LabReuseDraft | null;
};

type MusicSample = {
    id: string;
    title: string;
    vocals: boolean;
    cover: string;
    style: string;
    sample_url?: string | null;
};

function mapBrandExample(ex: BrandMusicExample): MusicSample {
    return {
        id: ex.example_key || String(ex.id),
        title: ex.title,
        vocals: Boolean(ex.vocals),
        cover: publicAsset(ex.cover_url) || '',
        style: ex.style || '',
        sample_url: publicAsset(ex.sample_url),
    };
}

const STYLE_SUGGESTIONS = [
    'Modern Raï',
    'Raï Fusion',
    'Algerian Chaabi',
    'Gnawa Diwan',
    'Andalusian Classical',
    'Kabyle Folk',
    'Saharan Blues',
    'Maghreb Pop',
    'Desert Trance',
    'Arabic Trap',
    'Mediterranean Jazz',
    'Oriental House',
    'Malouf Fusion',
    'Berber Rock',
    'Casbah Lo-fi',
    'Festival Chaabi',
    'Noir Phonk Maghreb',
    'Soft Rai Ballad',
    'Gnawa Electronica',
    'Atlas Ambient',
    'Oran Night Drive',
] as const;

const LYRIC_TAGS = ['[Intro]', '[Verse]', '[Pre Chorus]', '[Chorus]', '[Bridge]', '[Outro]'] as const;

export default function SoundLabCreateForm({
    brands = [],
    onGenerate,
    loading = false,
    creditsConfig,
    tokenBalance = 0,
    draft = null,
}: Props) {
    const [style, setStyle] = useState('');
    const [title, setTitle] = useState('');
    const [lyrics, setLyrics] = useState('');
    const [instrumental, setInstrumental] = useState(true);
    const [vocalGender, setVocalGender] = useState<'male' | 'female'>('female');
    const [autoEnhance, setAutoEnhance] = useState(true);
    const [lyricsOpen, setLyricsOpen] = useState(false);
    const [modelOpen, setModelOpen] = useState(false);
    const [samplesOpen, setSamplesOpen] = useState(false);
    const [moreStyles, setMoreStyles] = useState(false);
    const [playingId, setPlayingId] = useState<string | null>(null);
    const [selectedBrand, setSelectedBrand] = useState(brands[0]?.name || 'Studio');
    const [selectedModel, setSelectedModel] = useState(brands[0]?.models[0]?.name || 'MiniMax Music 2.6');
    const [sourceAudio, setSourceAudio] = useState<File | null>(null);
    const [sourceDurationSec, setSourceDurationSec] = useState<number | null>(null);
    const [durationSeconds, setDurationSeconds] = useState(90);
    const [editMode, setEditMode] = useState<'remix' | 'lyrics'>('remix');
    const [draftNotice, setDraftNotice] = useState<string | null>(null);
    const previewAudioRef = useRef<HTMLAudioElement | null>(null);
    const audioInputRef = useRef<HTMLInputElement>(null);
    const assetMentions = useMemo<AssetMention[]>(
        () =>
            sourceAudio
                ? [
                      {
                          alias: '@audio1',
                          kind: 'audio',
                          name: sourceAudio.name,
                      },
                  ]
                : [],
        [sourceAudio],
    );

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

    useEffect(() => {
        if (!draft || draft.lab !== 'music') return;

        const matched = matchLabModel(allModels, {
            modelName: draft.modelName,
            endpointId: draft.endpointId,
            // Innovation posts are text prompts — avoid ACE audio-to-audio by default
            preferNoAudioInput: true,
        });
        if (matched) {
            setSelectedBrand(matched.brandName);
            setSelectedModel(matched.name);
        }

        const maxStyle = matched?.max_prompt_chars || selectedModelRecord?.max_prompt_chars || 2000;
        const maxLyrics = matched?.max_lyrics_chars || selectedModelRecord?.max_lyrics_chars || 3000;

        if (draft.lyrics && draft.lyrics.trim()) {
            setLyrics(draft.lyrics.slice(0, maxLyrics));
            setStyle((draft.prompt || '').slice(0, maxStyle));
            setInstrumental(false);
            setLyricsOpen(true);
        } else if (draft.prompt) {
            setStyle(draft.prompt.slice(0, maxStyle));
        }

        setDraftNotice('Prompt loaded from Innovation — tweak style/lyrics then Create.');
    }, [draft?.id]);

    const canUseVocals = Boolean(selectedModelRecord?.supports_vocals);
    const canUseLyrics = Boolean(selectedModelRecord?.supports_lyrics);
    const needsSourceAudio = Boolean(selectedModelRecord?.supports_audio);
    const supportsDurationControl = Boolean(selectedModelRecord?.supports_duration_control);
    const minDuration = Math.max(1, selectedModelRecord?.min_duration_seconds ?? 1);
    const maxDuration = Math.max(minDuration, selectedModelRecord?.max_duration ?? 180);
    const durationStep = Math.max(1, selectedModelRecord?.duration_step_seconds ?? 1);
    const maxPromptChars = selectedModelRecord?.max_prompt_chars || 2000;
    const maxLyricsChars = selectedModelRecord?.max_lyrics_chars || 3000;

    const modelSamples = useMemo(() => {
        const fromDb = (selectedModelRecord?.examples ?? []).map(mapBrandExample);
        return fromDb.slice(0, 5);
    }, [selectedModelRecord?.examples]);
    const previewSamples = modelSamples;

    const creditEstimate = useMemo(
        () =>
            estimateMusicCredits(
                selectedModelRecord,
                {
                    autoEnhance,
                    durationSeconds: needsSourceAudio
                        ? sourceDurationSec
                        : supportsDurationControl
                          ? durationSeconds
                          : null,
                },
                creditsConfig,
            ),
        [
            selectedModelRecord,
            autoEnhance,
            creditsConfig,
            needsSourceAudio,
            sourceDurationSec,
            supportsDurationControl,
            durationSeconds,
        ],
    );
    const creditCost = creditEstimate.credits;
    const hasEnoughTokens = creditCost > 0 && tokenBalance >= creditCost;
    const sourceDurationLabel = formatMusicDuration(sourceDurationSec);
    const maxSourceSeconds =
        typeof selectedModelRecord?.max_duration === 'number' && selectedModelRecord.max_duration > 0
            ? selectedModelRecord.max_duration
            : needsSourceAudio
              ? 651
              : null;
    const sourceTooLong =
        needsSourceAudio &&
        sourceDurationSec != null &&
        maxSourceSeconds != null &&
        sourceDurationSec > maxSourceSeconds;

    const canGenerate =
        Boolean(style.trim()) && (!needsSourceAudio || Boolean(sourceAudio)) && !sourceTooLong && hasEnoughTokens;

    useEffect(() => {
        setSourceAudio(null);
        setSourceDurationSec(null);
        setStyle((value) => rebasePromptAfterAssetRemoval(value, 'audio', 1));
        if (audioInputRef.current) audioInputRef.current.value = '';
        setEditMode('remix');
        if (needsSourceAudio) {
            setLyricsOpen(true);
        }
    }, [selectedModelRecord?.endpoint_id, needsSourceAudio]);

    useEffect(() => {
        if (!supportsDurationControl) return;
        const configured = selectedModelRecord?.default_duration_seconds ?? Math.min(90, maxDuration);
        setDurationSeconds(Math.max(minDuration, Math.min(maxDuration, configured)));
    }, [
        selectedModelRecord?.endpoint_id,
        supportsDurationControl,
        selectedModelRecord?.default_duration_seconds,
        minDuration,
        maxDuration,
    ]);

    useEffect(() => {
        if (!sourceAudio) {
            setSourceDurationSec(null);
            return;
        }
        let cancelled = false;
        void readAudioFileDuration(sourceAudio).then((sec) => {
            if (!cancelled) setSourceDurationSec(sec);
        });
        return () => {
            cancelled = true;
        };
    }, [sourceAudio]);

    const visibleStyles = moreStyles ? STYLE_SUGGESTIONS : STYLE_SUGGESTIONS.slice(0, 5);
    const durationPresets = Array.from(
        new Set(
            [30, 60, 90, 120, 180, maxDuration].filter(
                (seconds) => seconds >= minDuration && seconds <= maxDuration,
            ),
        ),
    );

    useEffect(() => {
        if (brands[0] && !brands.find((b) => b.name === selectedBrand)) {
            setSelectedBrand(brands[0].name);
            setSelectedModel(brands[0].models[0]?.name || 'MiniMax Music 2.6');
        }
    }, [brands, selectedBrand]);

    useEffect(() => {
        setPlayingId(null);
        setSamplesOpen(false);
        previewAudioRef.current?.pause();
        previewAudioRef.current = null;
    }, [selectedModelRecord?.endpoint_id]);

    useEffect(() => {
        return () => {
            previewAudioRef.current?.pause();
            previewAudioRef.current = null;
        };
    }, []);

    useEffect(() => {
        if (!canUseVocals) {
            setInstrumental(true);
        }
    }, [canUseVocals, selectedModel]);

    useEffect(() => {
        setStyle((prev) => (prev.length > maxPromptChars ? prev.slice(0, maxPromptChars) : prev));
        setLyrics((prev) => (prev.length > maxLyricsChars ? prev.slice(0, maxLyricsChars) : prev));
    }, [maxPromptChars, maxLyricsChars]);

    useEffect(() => {
        if (needsSourceAudio) return;
        if (instrumental) {
            setLyricsOpen(false);
            setLyrics('');
        } else if (canUseLyrics) {
            setLyricsOpen(true);
        } else {
            setLyricsOpen(false);
            setLyrics('');
        }
    }, [instrumental, canUseLyrics, needsSourceAudio]);

    const applySample = (sample: MusicSample) => {
        setStyle(sample.style.slice(0, maxPromptChars));
        setTitle(sample.title);
        const wantVocals = sample.vocals && canUseVocals;
        setInstrumental(!wantVocals);
        if (wantVocals) setVocalGender('female');

        if (sample.sample_url) {
            previewAudioRef.current?.pause();
            const audio = new Audio(sample.sample_url);
            previewAudioRef.current = audio;
            setPlayingId(sample.id);
            audio.play().catch(() => setPlayingId(null));
            audio.onended = () => setPlayingId(null);
        } else {
            setPlayingId(sample.id);
            window.setTimeout(() => setPlayingId(null), 900);
        }
    };

    const appendStyle = (chip: string) => {
        setStyle((prev) => {
            const t = prev.trim();
            if (!t) return chip;
            if (t.toLowerCase().includes(chip.toLowerCase())) return t;
            return `${t}, ${chip}`;
        });
    };

    const insertLyricTag = (tag: string) => {
        setLyrics((prev) => {
            const t = prev.trimEnd();
            if (!t) return `${tag}\n`;
            return `${t}\n${tag}\n`;
        });
        setLyricsOpen(true);
    };

    const buildStyleForGenerate = () => {
        const base = style.trim();
        // ACE remix/lyrics — don't inject text-to-music vocal gender hints
        if (needsSourceAudio || instrumental) return base;
        const genderHint = vocalGender === 'male' ? 'male vocals' : 'female vocals';
        if (base.toLowerCase().includes('male vocal') || base.toLowerCase().includes('female vocal')) {
            return base;
        }
        return base ? `${base}, ${genderHint}` : genderHint;
    };

    return (
        <div className="relative flex w-full flex-col overflow-x-hidden bg-black md:h-full md:min-h-0 [&_a]:cursor-pointer [&_button]:cursor-pointer [&_button:disabled]:cursor-not-allowed [&_label]:cursor-pointer">
            <div className="pointer-events-none absolute inset-x-0 top-0 h-36 bg-[radial-gradient(ellipse_at_top,rgba(255,87,51,0.16),transparent_70%)]" />

            {/* Header — same model switch as voice lab */}
            <div className="relative flex shrink-0 items-center justify-between gap-2 border-b border-white/[0.07] px-4 py-3">
                <div className="flex min-w-0 items-center gap-2">
                    <h1 className="text-base font-semibold tracking-tight text-white">Music</h1>
                </div>
                <LabModelPickerTrigger
                    modelName={selectedModelRecord?.name || selectedModel}
                    icon={selectedModelRecord?.icon}
                    imageCover={selectedModelRecord?.image_cover}
                    onClick={() => setModelOpen(true)}
                />
            </div>

            {draftNotice && (
                <div className="mx-4 mt-3 flex items-start gap-2 rounded-xl border border-orange-400/25 bg-[#FF5733]/10 px-3 py-2.5 text-[12px] text-orange-100">
                    <p className="min-w-0 flex-1">{draftNotice}</p>
                    <button
                        type="button"
                        onClick={() => setDraftNotice(null)}
                        className="shrink-0 text-white/45 hover:text-white"
                        aria-label="Dismiss"
                    >
                        ×
                    </button>
                </div>
            )}

            <div className="relative overflow-x-hidden p-4 md:min-h-0 md:flex-1 md:overflow-y-auto md:scrollbar-thin">
                <div className="space-y-4">
                    {needsSourceAudio && (
                        <div className="space-y-3">
                            <div className="flex items-center justify-between gap-2">
                                <label className="text-sm font-medium text-white">
                                    Source Audio <span className="text-[#FF5733]">*</span>
                                </label>
                                <span className="rounded-md border border-orange-400/25 bg-[#FF5733]/10 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-orange-200">
                                    Audio edit
                                </span>
                            </div>
                            <p className="text-[12px] leading-relaxed text-white/45">
                                Upload a track, then choose Remix (new style) or Lyrics edit (keep beat, rewrite vocals).
                            </p>

                            <input
                                ref={audioInputRef}
                                type="file"
                                accept="audio/*,.mp3,.wav,.flac,.ogg,.m4a,.aac"
                                className="hidden"
                                onChange={(e) => setSourceAudio(e.target.files?.[0] ?? null)}
                            />

                            <div className="relative">
                            <motion.button
                                type="button"
                                onClick={() => audioInputRef.current?.click()}
                                onDragOver={(e) => {
                                    e.preventDefault();
                                    e.stopPropagation();
                                }}
                                onDrop={(e) => {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    const file = e.dataTransfer.files?.[0];
                                    if (file && (file.type.startsWith('audio/') || /\.(mp3|wav|flac|ogg|m4a|aac)$/i.test(file.name))) {
                                        setSourceAudio(file);
                                    }
                                }}
                                whileHover={{ scale: 1.01 }}
                                whileTap={{ scale: 0.985 }}
                                className={`group relative w-full overflow-hidden rounded-2xl border text-start transition ${
                                    sourceAudio
                                        ? 'border-orange-400/35 bg-gradient-to-br from-[#FF5733]/12 via-black to-black'
                                        : 'border-dashed border-white/15 bg-black hover:border-orange-400/40'
                                }`}
                            >
                                <motion.div
                                    aria-hidden
                                    className="pointer-events-none absolute -left-1/3 -top-1/2 h-[140%] w-[60%] bg-gradient-to-r from-transparent via-white/[0.07] to-transparent"
                                    animate={{ x: ['0%', '220%'] }}
                                    transition={{ duration: 2.8, repeat: Infinity, ease: 'easeInOut', repeatDelay: 1.2 }}
                                />
                                <div className="relative flex items-center gap-3.5 px-3.5 py-3.5">
                                    <div className="relative flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-[#FF5733]/15 ring-1 ring-[#FF5733]/30">
                                        <motion.span
                                            aria-hidden
                                            className="absolute inset-0 rounded-xl bg-[#FF5733]/20"
                                            animate={{ opacity: [0.35, 0.75, 0.35], scale: [0.92, 1.05, 0.92] }}
                                            transition={{ duration: 2.2, repeat: Infinity, ease: 'easeInOut' }}
                                        />
                                        {sourceAudio ? (
                                            <svg className="relative h-5 w-5 text-orange-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8">
                                                <path d="M9 18V5l12-2v13" />
                                                <circle cx="6" cy="18" r="3" />
                                                <circle cx="18" cy="16" r="3" />
                                            </svg>
                                        ) : (
                                            <svg className="relative h-5 w-5 text-orange-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8">
                                                <path d="M12 16V4" />
                                                <path d="m7 9 5-5 5 5" />
                                                <path d="M20 16v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2" />
                                            </svg>
                                        )}
                                    </div>
                                    <span className="min-w-0 flex-1 pe-6">
                                        <span className="block truncate text-[13px] font-semibold tracking-tight text-white">
                                            {sourceAudio ? `@audio1 · ${sourceAudio.name}` : 'Choose audio file'}
                                        </span>
                                        <span className="mt-0.5 block text-[11px] text-white/45">
                                            {sourceAudio
                                                ? `${sourceAudio.size > 1024 * 1024 ? `${(sourceAudio.size / (1024 * 1024)).toFixed(1)} MB` : `${Math.max(1, Math.round(sourceAudio.size / 1024))} KB`}${sourceDurationLabel ? ` · ${sourceDurationLabel}` : ''} · ready`
                                                : 'Drop or browse · MP3, WAV, FLAC, OGG…'}
                                        </span>
                                    </span>
                                    <span
                                        className={`shrink-0 rounded-xl px-3 py-1.5 text-[11px] font-semibold transition ${
                                            sourceAudio
                                                ? 'border border-white/10 bg-white/[0.06] text-white/80 group-hover:bg-white/10'
                                                : 'bg-[#FF5733] text-white shadow-[0_8px_20px_rgba(255,87,51,0.35)]'
                                        }`}
                                    >
                                        {sourceAudio ? 'Change' : 'Upload'}
                                    </span>
                                </div>
                            </motion.button>
                            {sourceAudio && (
                                <button
                                    type="button"
                                    aria-label="Remove audio"
                                    title="Remove"
                                    onClick={(e) => {
                                        e.preventDefault();
                                        e.stopPropagation();
                                        setSourceAudio(null);
                                        setStyle((value) => rebasePromptAfterAssetRemoval(value, 'audio', 1));
                                        if (audioInputRef.current) audioInputRef.current.value = '';
                                    }}
                                    className="absolute end-2 top-2 z-10 flex h-7 w-7 items-center justify-center rounded-full bg-black/75 text-sm text-white ring-1 ring-white/20 transition hover:bg-red-500/80 md:h-6 md:w-6 md:text-xs"
                                >
                                    ×
                                </button>
                            )}
                            </div>
                            {sourceTooLong && maxSourceSeconds != null && (
                                <p className="rounded-xl border border-red-400/25 bg-red-500/10 px-3 py-2 text-[12px] text-red-200">
                                    Source audio is too long ({sourceDurationLabel}). Max for this model is{' '}
                                    {formatMusicDuration(maxSourceSeconds)}.
                                </p>
                            )}

                            <div className="space-y-1.5">
                                <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-white/35">Edit mode</span>
                                <div className="relative grid grid-cols-2 gap-1 rounded-xl border border-white/10 bg-black p-1">
                                    <motion.span
                                        aria-hidden
                                        className="absolute inset-y-1 rounded-lg bg-gradient-to-b from-[#FF6A45] via-[#FF5733] to-[#D63A18] shadow-[0_8px_18px_rgba(255,87,51,0.35)]"
                                        animate={{
                                            left: editMode === 'remix' ? '0.25rem' : 'calc(50% + 0.125rem)',
                                            width: 'calc(50% - 0.375rem)',
                                        }}
                                        transition={{ type: 'spring', stiffness: 420, damping: 32 }}
                                    />
                                    {(
                                        [
                                            { id: 'remix' as const, label: 'Remix', hint: 'Best for instrumental + new vocals' },
                                            { id: 'lyrics' as const, label: 'Lyrics edit', hint: 'Rewrite vocals on a sung track' },
                                        ]
                                    ).map((mode) => {
                                        const active = editMode === mode.id;
                                        return (
                                            <button
                                                key={mode.id}
                                                type="button"
                                                onClick={() => {
                                                    setEditMode(mode.id);
                                                    setLyricsOpen(true);
                                                }}
                                                className={`relative z-[1] rounded-lg px-3 py-2.5 text-start transition ${
                                                    active ? 'text-white' : 'text-white/45 hover:text-white/80'
                                                }`}
                                            >
                                                <span className="block text-[12px] font-semibold">{mode.label}</span>
                                                <span className={`mt-0.5 block text-[10px] ${active ? 'text-white/80' : 'text-white/30'}`}>
                                                    {mode.hint}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                                <p className="text-[11px] leading-relaxed text-white/35">
                                    {editMode === 'remix'
                                        ? 'Remix — use this when the upload is instrumental and you want new singing. Paste lyrics below + keep Target Style short (e.g. “rai, pop, emotional, female vocals”).'
                                        : 'Lyrics edit — only for rewriting words on a track that already has singing. It will not add vocals onto an instrumental bed.'}
                                </p>
                            </div>

                            {/* Soft gender hint via style tags — ACE has no dedicated gender API field */}
                            <div className="space-y-1.5">
                                <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-white/35">Vocal gender</span>
                                <div className="grid grid-cols-2 gap-2">
                                    {(
                                        [
                                            { id: 'female' as const, label: 'Female', hint: 'Softer / brighter' },
                                            { id: 'male' as const, label: 'Male', hint: 'Deeper / warmer' },
                                        ] as const
                                    ).map((opt) => {
                                        const active = vocalGender === opt.id;
                                        return (
                                            <button
                                                key={opt.id}
                                                type="button"
                                                onClick={() => setVocalGender(opt.id)}
                                                className={`rounded-xl border px-3 py-2.5 text-start transition ${
                                                    active
                                                        ? 'border-[#FF5733]/55 bg-[#FF5733]/15 shadow-[0_0_20px_rgba(255,87,51,0.15)]'
                                                        : 'border-white/10 bg-black hover:border-white/25'
                                                }`}
                                            >
                                                <div className={`text-sm font-semibold ${active ? 'text-orange-100' : 'text-white'}`}>
                                                    {opt.label}
                                                </div>
                                                <div className="mt-0.5 text-[11px] text-white/40">{opt.hint}</div>
                                            </button>
                                        );
                                    })}
                                </div>
                                <p className="text-[11px] leading-relaxed text-white/35">
                                    Applied when lyrics are set — steers voice via style tags (ACE has no gender switch).
                                </p>
                            </div>
                        </div>
                    )}

                    {/* Style */}
                    <div className="space-y-2">
                        <div className="flex items-center justify-between gap-2">
                            <label className="text-sm font-medium text-white">
                                {needsSourceAudio ? 'Target Style' : 'Style of Music'} <span className="text-[#FF5733]">*</span>
                            </label>
                            <div className="flex items-center gap-2">
                                <div className="flex items-center gap-1.5">
                                    <svg className="h-3 w-3 text-white/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                        <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z" />
                                        <path d="M20 3v4" />
                                        <path d="M22 5h-4" />
                                    </svg>
                                    <span className="text-xs text-white/40">Auto-enhance · 1 credit</span>
                                    <Toggle checked={autoEnhance} onChange={setAutoEnhance} />
                                </div>
                                <span className="text-xs text-white/40">
                                    {style.length.toLocaleString()}/{maxPromptChars.toLocaleString()}
                                </span>
                            </div>
                        </div>
                        <AssetMentionTextarea
                            value={style}
                            onChange={setStyle}
                            mentions={assetMentions}
                            maxLength={maxPromptChars}
                            onWheel={(e) => e.stopPropagation()}
                            rows={5}
                            placeholder={
                                needsSourceAudio
                                    ? 'Short style tags work best, e.g. rai, pop, emotional, ambient, female vocals'
                                    : 'Type the style of song you want to generate...'
                            }
                            className="max-h-[220px] min-h-[140px] w-full resize-y overflow-y-auto rounded-xl border border-white/10 bg-black px-3 py-2.5 text-sm leading-relaxed text-white outline-none placeholder:text-white/30 focus:border-orange-400/40 focus:ring-2 focus:ring-orange-500/15 scrollbar-thin"
                        />
                        <div className="flex flex-wrap gap-1.5">
                            {visibleStyles.map((chip) => (
                                <button
                                    key={chip}
                                    type="button"
                                    onClick={() => appendStyle(chip)}
                                    className="cursor-pointer whitespace-nowrap rounded-md border border-white/10 bg-black px-2.5 py-0.5 text-xs font-semibold text-white/75 shadow-sm transition hover:border-orange-400/35 hover:bg-orange-500/10 hover:text-orange-100"
                                >
                                    {chip}
                                </button>
                            ))}
                            {!moreStyles && (
                                <button
                                    type="button"
                                    onClick={() => setMoreStyles(true)}
                                    className="cursor-pointer whitespace-nowrap rounded-md border border-white/10 px-2.5 py-0.5 text-xs font-semibold text-white/40 transition hover:text-white"
                                >
                                    +{STYLE_SUGGESTIONS.length - 5} more
                                </button>
                            )}
                        </div>
                    </div>

                    {/* Model-scoped examples — after style, like voice quick prompts */}
                    {modelSamples.length > 0 && (
                        <div className="min-w-0 space-y-2" key={selectedModelRecord?.endpoint_id || selectedModel}>
                            <div className="flex items-center justify-between gap-2">
                                <div className="min-w-0">
                                    <span className="text-sm font-medium text-white">Examples</span>
                                    <p className="truncate text-[11px] text-white/40">
                                        For {selectedModelRecord?.name || selectedModel}
                                        {!canUseVocals ? ' · instrumental' : ''}
                                    </p>
                                </div>
                                {modelSamples.length > 0 && (
                                    <button
                                        type="button"
                                        onClick={() => setSamplesOpen(true)}
                                        className="inline-flex h-7 shrink-0 cursor-pointer items-center gap-0.5 rounded-md px-2 text-xs font-medium text-white/50 transition hover:bg-white/[0.05] hover:text-white"
                                    >
                                        View all
                                        <svg className="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                            <path d="m9 18 6-6-6-6" />
                                        </svg>
                                    </button>
                                )}
                            </div>
                            <div className="flex gap-2.5 overflow-x-auto pb-1 pt-0.5 scrollbar-thin">
                                {previewSamples.map((sample) => (
                                    <SampleCard
                                        key={sample.id}
                                        sample={sample}
                                        playing={playingId === sample.id}
                                        onClick={() => applySample(sample)}
                                    />
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Title */}
                    <div className="space-y-2">
                        <div className="flex items-center justify-between">
                            <label className="text-sm font-medium text-white">
                                Title <span className="text-xs text-white/40">(optional)</span>
                            </label>
                            <span className="text-xs text-white/40">{title.length}/80</span>
                        </div>
                        <input
                            type="text"
                            value={title}
                            onChange={(e) => setTitle(e.target.value.slice(0, 80))}
                            placeholder="Give your track a name..."
                            className="flex h-10 w-full rounded-xl border border-white/10 bg-black px-3 text-sm text-white outline-none placeholder:text-white/30 focus:border-orange-400/40 focus:ring-2 focus:ring-orange-500/15"
                        />
                    </div>

                    <AnimatePresence initial={false}>
                        {supportsDurationControl && !needsSourceAudio && (
                            <motion.div
                                initial={{ opacity: 0, height: 0 }}
                                animate={{ opacity: 1, height: 'auto' }}
                                exit={{ opacity: 0, height: 0 }}
                                className="overflow-hidden"
                            >
                                <div className="space-y-3 rounded-2xl border border-white/10 bg-black p-3.5 sm:p-4">
                                    <div className="flex items-center justify-between gap-3">
                                        <div className="flex min-w-0 items-center gap-2.5">
                                            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-[#FF5733]/12 text-orange-300">
                                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8">
                                                    <circle cx="12" cy="12" r="9" />
                                                    <path strokeLinecap="round" d="M12 7v5l3 2" />
                                                </svg>
                                            </span>
                                            <div className="min-w-0">
                                                <p className="text-sm font-medium text-white">Track duration</p>
                                                <p className="truncate text-[11px] text-white/40">
                                                    Supported by {selectedModelRecord?.name || selectedModel}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="shrink-0 rounded-xl border border-orange-400/20 bg-[#FF5733]/10 px-3 py-1.5 text-center">
                                            <span className="font-[family-name:Outfit,sans-serif] text-lg font-bold tabular-nums text-orange-200">
                                                {formatMusicDuration(durationSeconds)}
                                            </span>
                                        </div>
                                    </div>

                                    <input
                                        type="range"
                                        min={minDuration}
                                        max={maxDuration}
                                        step={durationStep}
                                        value={durationSeconds}
                                        onChange={(event) => setDurationSeconds(Number(event.target.value))}
                                        aria-label="Track duration"
                                        className="h-1.5 w-full cursor-pointer appearance-none rounded-full bg-white/10 accent-[#FF5733]"
                                    />

                                    <div className="flex items-center justify-between text-[10px] tabular-nums text-white/30">
                                        <span>{formatMusicDuration(minDuration)} min</span>
                                        <span>{formatMusicDuration(maxDuration)} max</span>
                                    </div>

                                    <div className="grid grid-cols-3 gap-1.5 sm:flex sm:flex-wrap">
                                        {durationPresets.map((seconds) => {
                                            const active = durationSeconds === seconds;
                                            return (
                                                <button
                                                    key={seconds}
                                                    type="button"
                                                    onClick={() => setDurationSeconds(seconds)}
                                                    className={`rounded-lg border px-2.5 py-1.5 text-[11px] font-medium tabular-nums transition ${
                                                        active
                                                            ? 'border-[#FF5733]/50 bg-[#FF5733]/15 text-orange-200'
                                                            : 'border-white/10 bg-white/[0.025] text-white/45 hover:border-white/20 hover:text-white/75'
                                                    }`}
                                                >
                                                    {formatMusicDuration(seconds)}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            </motion.div>
                        )}
                    </AnimatePresence>

                    {/* Instrumental / Vocals — not used for ACE audio edit (Remix / Lyrics edit drive that) */}
                    {!needsSourceAudio && (
                    <div className={`space-y-3 rounded-2xl border border-white/10 bg-black p-3 ${!canUseVocals ? 'opacity-70' : ''}`}>
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2.5">
                                {instrumental ? (
                                    <svg className="h-4 w-4 text-white/50" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                        <line x1="2" x2="22" y1="2" y2="22" />
                                        <path d="M18.89 13.23A7.12 7.12 0 0 0 19 12v-2" />
                                        <path d="M5 10v2a7 7 0 0 0 12 5" />
                                        <path d="M15 9.34V5a3 3 0 0 0-5.68-1.33" />
                                        <path d="M9 9v3a3 3 0 0 0 5.12 2.12" />
                                        <line x1="12" x2="12" y1="19" y2="22" />
                                    </svg>
                                ) : (
                                    <svg className="h-4 w-4 text-orange-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                        <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z" />
                                        <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
                                        <line x1="12" x2="12" y1="19" y2="22" />
                                    </svg>
                                )}
                                <div>
                                    <label className="text-sm font-medium text-white">
                                        {!canUseVocals
                                            ? 'Instrumental only'
                                            : instrumental
                                              ? 'Without Vocals'
                                              : 'With Vocals'}
                                    </label>
                                    <p className="text-xs text-white/45">
                                        {!canUseVocals
                                            ? 'This model does not support singing vocals'
                                            : instrumental
                                              ? 'Music only, no singing'
                                              : 'Include sung vocals'}
                                    </p>
                                </div>
                            </div>
                            <Toggle
                                checked={instrumental}
                                onChange={setInstrumental}
                                disabled={!canUseVocals}
                            />
                        </div>

                        <AnimatePresence initial={false}>
                            {!instrumental && canUseVocals && (
                                <motion.div
                                    initial={{ height: 0, opacity: 0 }}
                                    animate={{ height: 'auto', opacity: 1 }}
                                    exit={{ height: 0, opacity: 0 }}
                                    className="overflow-hidden"
                                >
                                    <div className="space-y-2 border-t border-white/10 pt-3">
                                        <p className="text-xs font-medium uppercase tracking-wide text-white/45">Vocal gender</p>
                                        <div className="grid grid-cols-2 gap-2">
                                            {(
                                                [
                                                    { id: 'female' as const, label: 'Female', hint: 'Softer / brighter' },
                                                    { id: 'male' as const, label: 'Male', hint: 'Deeper / warmer' },
                                                ] as const
                                            ).map((opt) => {
                                                const active = vocalGender === opt.id;
                                                return (
                                                    <button
                                                        key={opt.id}
                                                        type="button"
                                                        onClick={() => setVocalGender(opt.id)}
                                                        className={`rounded-xl border px-3 py-2.5 text-start transition ${
                                                            active
                                                                ? 'border-[#FF5733]/55 bg-[#FF5733]/15 shadow-[0_0_20px_rgba(255,87,51,0.15)]'
                                                                : 'border-white/10 bg-black hover:border-white/25'
                                                        }`}
                                                    >
                                                        <div className={`text-sm font-semibold ${active ? 'text-orange-100' : 'text-white'}`}>
                                                            {opt.label}
                                                        </div>
                                                        <div className="mt-0.5 text-[11px] text-white/40">{opt.hint}</div>
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                </motion.div>
                            )}
                        </AnimatePresence>
                    </div>
                    )}

                    {/* Lyrics — ACE shows for remix + lyrics edit (empty lyrics ⇒ fal sings nothing) */}
                    {canUseVocals && canUseLyrics && (
                    <div className="overflow-hidden rounded-2xl border border-white/10 bg-black">
                        <button
                            type="button"
                            disabled={!needsSourceAudio && instrumental}
                            onClick={() => setLyricsOpen((v) => !v)}
                            className="inline-flex h-auto min-h-11 w-full cursor-pointer items-center justify-between px-3.5 py-3 text-sm font-medium text-white transition hover:bg-white/[0.03] disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            <span className="flex items-center gap-2">
                                <svg className="h-4 w-4 text-orange-300/90" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                    <path d="M12 20h9" />
                                    <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z" />
                                </svg>
                                Lyrics
                                {needsSourceAudio && (
                                    <span className="rounded-md border border-orange-400/25 bg-[#FF5733]/10 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-orange-200">
                                        {vocalGender === 'male' ? 'Male' : 'Female'}
                                    </span>
                                )}
                                {!needsSourceAudio && !instrumental && (
                                    <span className="rounded-md border border-orange-400/25 bg-[#FF5733]/10 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-orange-200">
                                        {vocalGender === 'male' ? 'Male' : 'Female'}
                                    </span>
                                )}
                                {needsSourceAudio && (
                                    <span
                                        className={`rounded-md border px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide ${
                                            lyrics.trim()
                                                ? 'border-orange-400/25 bg-[#FF5733]/10 text-orange-200'
                                                : 'border-amber-400/30 bg-amber-500/10 text-amber-100'
                                        }`}
                                    >
                                        {lyrics.trim() ? 'Vocals on' : 'Needed for singing'}
                                    </span>
                                )}
                                <span className="rounded-md border border-white/10 bg-black px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-white/40">
                                    {maxLyricsChars.toLocaleString()}
                                </span>
                            </span>
                            <svg
                                className={`h-4 w-4 text-white/40 transition ${lyricsOpen ? 'rotate-90' : ''}`}
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                strokeWidth="2"
                            >
                                <path d="m9 18 6-6-6-6" />
                            </svg>
                        </button>
                        <AnimatePresence initial={false}>
                            {lyricsOpen && (needsSourceAudio || !instrumental) && (
                                <motion.div
                                    initial={{ height: 0, opacity: 0 }}
                                    animate={{ height: 'auto', opacity: 1 }}
                                    exit={{ height: 0, opacity: 0 }}
                                    className="overflow-hidden"
                                >
                                    <div className="space-y-3 border-t border-white/10 px-3.5 pb-3.5 pt-3">
                                        <p className="text-[12px] leading-relaxed text-white/45">
                                            {needsSourceAudio
                                                ? editMode === 'remix'
                                                    ? 'Paste the source lyrics (or new ones). Leaving this empty usually produces an instrumental remix — fal does not copy singing from the file alone.'
                                                    : 'Write the new lyrics to sing. Structure tags help shape verses and chorus.'
                                                : 'Write your own lyrics, or leave blank and the model will invent them. Tap structure tags to shape the song.'}
                                        </p>
                                        <div className="flex flex-wrap gap-1.5">
                                            {LYRIC_TAGS.map((tag) => (
                                                <button
                                                    key={tag}
                                                    type="button"
                                                    onClick={() => insertLyricTag(tag)}
                                                    className="rounded-lg border border-white/10 bg-black px-2.5 py-1.5 text-[11px] font-semibold text-white/70 transition hover:border-orange-400/40 hover:bg-[#FF5733]/10 hover:text-orange-100"
                                                >
                                                    {tag}
                                                </button>
                                            ))}
                                        </div>
                                        <div className="relative overflow-hidden rounded-xl border border-white/10 bg-black shadow-[inset_0_0_0_1px_rgba(255,255,255,0.02)]">
                                            <div className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-[#FF5733]/55 to-transparent" />
                                            <div className="pointer-events-none absolute inset-y-0 start-0 w-1 bg-gradient-to-b from-[#FF5733]/50 via-[#FF5733]/15 to-transparent" />
                                            <textarea
                                                value={lyrics}
                                                onChange={(e) => setLyrics(e.target.value.slice(0, maxLyricsChars))}
                                                onWheel={(e) => e.stopPropagation()}
                                                rows={9}
                                                spellCheck={false}
                                                placeholder={`[Verse]\nWalking through the night lights…\n\n[Chorus]\nSing it loud, sing it clear…`}
                                                className="max-h-[320px] min-h-[200px] w-full resize-y overflow-y-auto bg-black ps-4 pe-3.5 py-3.5 font-mono text-[13px] leading-7 text-white outline-none placeholder:text-white/25 focus:ring-0 scrollbar-thin"
                                            />
                                            <div className="flex items-center justify-between border-t border-white/10 bg-black px-3 py-2">
                                                <span className="text-[11px] text-white/40">
                                                    {needsSourceAudio
                                                        ? `${editMode === 'remix' ? 'Remix' : 'Lyrics edit'} · paste lyrics for singing`
                                                        : `${vocalGender === 'male' ? 'Male' : 'Female'} vocal · tags help song structure`}
                                                </span>
                                                <span
                                                    className={`text-[11px] tabular-nums ${
                                                        lyrics.length > maxLyricsChars * 0.9 ? 'text-orange-300' : 'text-white/40'
                                                    }`}
                                                >
                                                    {lyrics.length.toLocaleString()}/{maxLyricsChars.toLocaleString()}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </motion.div>
                            )}
                        </AnimatePresence>
                    </div>
                    )}
                    {!needsSourceAudio && canUseVocals && !canUseLyrics && !instrumental && (
                        <p className="rounded-xl border border-white/10 bg-black px-3 py-2.5 text-[12px] text-white/45">
                            This model can sing from your style prompt, but does not take a separate lyrics field.
                        </p>
                    )}
                </div>
            </div>

            {/* Sticky create — match voice lab identity */}
            <div className="relative shrink-0 border-t border-white/[0.07] bg-[#0a0a0f]/95 p-3 backdrop-blur-xl">
                <div className="pointer-events-none absolute inset-x-0 -top-8 h-8 bg-gradient-to-t from-[#0a0a0f] to-transparent" />
                <div className="mb-2.5 flex items-center justify-between gap-2 px-0.5">
                    <div className="flex min-w-0 flex-wrap items-center gap-1.5">
                        <span className="max-w-[140px] truncate rounded-lg border border-white/10 bg-white/[0.04] px-2 py-1 text-[11px] text-white/65">
                            {selectedModelRecord?.name || selectedModel}
                        </span>
                        <span className="rounded-lg border border-white/10 bg-white/[0.04] px-2 py-1 text-[11px] text-white/65">
                            {needsSourceAudio
                                ? `${editMode === 'lyrics' ? 'Lyrics edit' : 'Remix'} · ${vocalGender === 'male' ? 'Male' : 'Female'}`
                                : instrumental || !canUseVocals
                                  ? 'Instrumental'
                                  : `Vocals · ${vocalGender === 'male' ? 'Male' : 'Female'}`}
                        </span>
                        {autoEnhance && (
                            <span className="rounded-lg border border-white/10 bg-white/[0.04] px-2 py-1 text-[11px] text-white/65">
                                Auto-enhance
                            </span>
                        )}
                    </div>
                    <span className="inline-flex shrink-0 flex-col items-end gap-0.5 text-[11px] font-medium tabular-nums text-orange-200/90">
                        {creditCost > 0 ? (
                            <span className="inline-flex items-center gap-1">
                                <CreditBoltIcon className="h-3.5 w-3.5 text-amber-300" />
                                {creditCost}
                            </span>
                        ) : (
                            '—'
                        )}
                        {needsSourceAudio && sourceDurationLabel && creditEstimate.assumedSeconds != null && (
                            <span className="text-[10px] font-normal text-white/40">
                                ≈ {formatMusicDuration(creditEstimate.assumedSeconds)} billed
                            </span>
                        )}
                    </span>
                </div>

                <motion.button
                    type="button"
                    whileTap={{ scale: 0.98 }}
                    disabled={loading || !canGenerate}
                    onClick={() =>
                        onGenerate?.(buildStyleForGenerate(), {
                            title,
                            instrumental: needsSourceAudio
                                ? false
                                : !canUseVocals
                                  ? true
                                  : instrumental,
                            lyrics: needsSourceAudio
                                ? lyrics
                                : !canUseVocals || instrumental || !canUseLyrics
                                  ? ''
                                  : lyrics,
                            autoEnhance,
                            model: selectedModel,
                            endpointId: selectedModelRecord?.endpoint_id || '',
                            vocalGender,
                            audioFile: needsSourceAudio ? sourceAudio : null,
                            editMode: needsSourceAudio ? editMode : undefined,
                            durationSeconds: needsSourceAudio
                                ? sourceDurationSec
                                : supportsDurationControl
                                  ? durationSeconds
                                  : null,
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
                            Creating…
                        </span>
                    ) : !hasEnoughTokens ? (
                        <span className="relative text-white/90">Not enough tokens ({tokenBalance} available)</span>
                    ) : (
                        <>
                            <svg className="relative h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z" />
                            </svg>
                            <span className="relative">Create</span>
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
                title="Select Model"
                subtitle="Pick a music model — each card shows its cover art and focus."
                fallbackDescription="Music generation model"
                onSelect={(m) => {
                    setSelectedBrand(m.brandName);
                    setSelectedModel(m.name);
                    setModelOpen(false);
                }}
            />

            {/* Samples modal */}
            <AnimatePresence>
                {samplesOpen && (
                    <ModalShell onClose={() => setSamplesOpen(false)} wide>
                        <h2 className="text-lg font-semibold tracking-tight text-white">Examples</h2>
                        <p className="mt-1 text-[13px] text-white/45">
                            Styles tuned for {selectedModelRecord?.name || selectedModel}
                        </p>
                        <div className="mt-4 grid max-h-[60vh] grid-cols-2 gap-3 overflow-y-auto sm:grid-cols-3 scrollbar-thin">
                            {modelSamples.map((sample) => (
                                <SampleCard
                                    key={sample.id}
                                    sample={sample}
                                    playing={playingId === sample.id}
                                    wide
                                    onClick={() => {
                                        applySample(sample);
                                        setSamplesOpen(false);
                                    }}
                                />
                            ))}
                        </div>
                    </ModalShell>
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

function SampleCard({
    sample,
    playing,
    onClick,
    wide,
}: {
    sample: MusicSample;
    playing?: boolean;
    onClick: () => void;
    wide?: boolean;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`group relative shrink-0 overflow-visible rounded-xl text-start transition-transform active:scale-[0.97] ${
                wide ? 'w-full' : 'w-[100px]'
            }`}
        >
            <div className="relative flex aspect-square items-end overflow-hidden rounded-xl p-2">
                {sample.cover ? (
                    <img src={sample.cover} alt={sample.title} className="absolute inset-0 size-full object-cover" loading="lazy" />
                ) : (
                    <div className="absolute inset-0 bg-gradient-to-br from-[#FF5733]/25 to-black" />
                )}
                <div className="absolute inset-0 bg-gradient-to-t from-black/70 via-black/15 to-transparent" />
                <div className="pointer-events-none absolute inset-0 z-10 flex items-center justify-center">
                    <div
                        className={`flex h-9 w-9 items-center justify-center rounded-full bg-black shadow-lg ring-1 ring-white/20 transition-transform group-hover:scale-110 ${
                            playing ? 'scale-110 bg-[#FF5733]' : ''
                        }`}
                    >
                        {playing ? (
                            <span className="flex gap-0.5">
                                <span className="h-3 w-0.5 animate-pulse rounded-full bg-white" />
                                <span className="h-3 w-0.5 animate-pulse rounded-full bg-white [animation-delay:120ms]" />
                                <span className="h-3 w-0.5 animate-pulse rounded-full bg-white [animation-delay:240ms]" />
                            </span>
                        ) : (
                            <svg className="ms-0.5 h-4 w-4 fill-white text-white" viewBox="0 0 24 24">
                                <polygon points="6 3 20 12 6 21 6 3" />
                            </svg>
                        )}
                    </div>
                </div>
                <div className="absolute start-2 top-2 z-10 flex flex-wrap gap-1">
                    <span
                        className={`inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[9px] font-medium text-white ${
                            sample.vocals ? 'bg-black/70' : 'bg-black'
                        }`}
                    >
                        {sample.vocals ? 'With Vocals' : 'Without Vocals'}
                    </span>
                    {!sample.sample_url && (
                        <span className="rounded-full bg-black px-1.5 py-0.5 text-[9px] font-medium text-white/50">
                            No audio yet
                        </span>
                    )}
                </div>
                <span className="relative z-10 text-[11px] font-semibold leading-tight text-white drop-shadow-md">{sample.title}</span>
            </div>
        </button>
    );
}

function Toggle({
    checked,
    onChange,
    disabled = false,
}: {
    checked: boolean;
    onChange: (v: boolean) => void;
    disabled?: boolean;
}) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            disabled={disabled}
            onClick={() => !disabled && onChange(!checked)}
            className={`peer inline-flex h-6 w-11 shrink-0 items-center rounded-full border-2 border-transparent transition-colors ${
                disabled ? 'cursor-not-allowed opacity-40' : 'cursor-pointer'
            } ${checked ? 'bg-[#FF5733]' : 'bg-white/15'}`}
        >
            <span
                className={`pointer-events-none block h-5 w-5 rounded-full bg-white shadow-lg ring-0 transition-transform ${
                    checked ? 'translate-x-5' : 'translate-x-0'
                }`}
            />
        </button>
    );
}

function ModalShell({
    children,
    onClose,
    wide,
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
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-3 backdrop-blur-lg"
            onClick={onClose}
        >
            <motion.div
                initial={{ opacity: 0, scale: 0.96, y: 12 }}
                animate={{ opacity: 1, scale: 1, y: 0 }}
                exit={{ opacity: 0, scale: 0.97 }}
                className={`relative overflow-hidden rounded-2xl border border-white/10 bg-black p-6 shadow-[0_30px_80px_rgba(0,0,0,0.55)] ${
                    wide ? 'w-[95vw] max-w-2xl' : 'w-[95vw] max-w-lg'
                }`}
                onClick={(e) => e.stopPropagation()}
            >
                <div className="pointer-events-none absolute inset-x-0 top-0 h-28 bg-[radial-gradient(ellipse_at_top,rgba(255,87,51,0.2),transparent_70%)]" />
                <button
                    type="button"
                    onClick={onClose}
                    className="absolute end-4 top-4 z-10 rounded-lg p-1.5 text-white/40 transition hover:bg-white/[0.06] hover:text-white"
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
