import { Head, Link } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useMemo, useRef, useState, type DragEvent, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import AppLayout from '@/Layouts/AppLayout';
import type { Tool } from '@/types';

type Props = {
    tool: Tool;
};

const SCALE_TOOLS = new Set(['Video Upscaler', 'Video Enhancer', 'Anime Video Enhancer']);
const MODE_TOOLS = new Set(['Denoise Video', 'Video Enhancer']);
const IMAGE_TOOLS = new Set(['tools.animate-a-picture']);

const GROUP_BY_ROUTE: Record<string, 'enhance' | 'transform' | 'edit' | 'create'> = {
    'tools.video-upscaler': 'enhance',
    'tools.video-enhancer': 'enhance',
    'tools.denoise-video': 'enhance',
    'tools.anime-video-enhancer': 'enhance',
    'tools.lip-sync': 'transform',
    'tools.face-swap-video': 'transform',
    'tools.video-to-anime-ai': 'transform',
    'tools.ai-video-filters': 'transform',
    'tools.ai-dance-generator': 'transform',
    'tools.motion-control': 'transform',
    'tools.ai-video-editor': 'edit',
    'tools.ai-video-extender': 'edit',
    'tools.video-to-video': 'edit',
    'tools.video-background-remover': 'edit',
    'tools.remove-subtitles-from-video': 'edit',
    'tools.animate-a-picture': 'create',
    'tools.ai-sound-effect-generator': 'create',
};

function slugFromRoute(route: string): string {
    return route.replace(/^tools\./, '');
}

export default function ToolDetail({ tool }: Props) {
    const { t } = useTranslation('tools');
    const slug = slugFromRoute(tool.route);
    const group = GROUP_BY_ROUTE[tool.route] ?? 'enhance';
    const isImageTool = IMAGE_TOOLS.has(tool.route);
    const showScale = SCALE_TOOLS.has(tool.name);
    const showMode = MODE_TOOLS.has(tool.name);

    const [model, setModel] = useState('auto');
    const [mode, setMode] = useState('general');
    const [scale, setScale] = useState('2x');
    const [quality, setQuality] = useState(true);
    const [publicVisible, setPublicVisible] = useState(true);
    const [copyProtection, setCopyProtection] = useState(false);
    const [loading, setLoading] = useState(false);
    const [progress, setProgress] = useState(0);
    const [settingsOpen, setSettingsOpen] = useState(true);
    const [fileName, setFileName] = useState<string | null>(null);
    const [filePreview, setFilePreview] = useState<string | null>(null);
    const [dragging, setDragging] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);

    const description = t(`detail.descriptions.${slug}`, {
        defaultValue: t('detail.subtitle'),
    });

    const modelLabel = useMemo(() => {
        const map: Record<string, string> = {
            auto: t('detail.modelAuto'),
            standard: t('detail.modelStandard'),
            pro: t('detail.modelPro'),
            ultra: t('detail.modelUltra'),
        };
        return map[model] ?? map.auto;
    }, [model, t]);

    useEffect(() => {
        return () => {
            if (filePreview) URL.revokeObjectURL(filePreview);
        };
    }, [filePreview]);

    useEffect(() => {
        if (!loading) return;
        const id = setInterval(() => {
            setProgress((p) => {
                if (p >= 90) return p;
                const next = p + Math.random() * 8 + 2;
                return next > 90 ? 90 : next;
            });
        }, 600);
        const done = setTimeout(() => {
            clearInterval(id);
            setProgress(100);
            setTimeout(() => {
                setLoading(false);
                setProgress(0);
            }, 800);
        }, 4000);
        return () => {
            clearInterval(id);
            clearTimeout(done);
        };
    }, [loading]);

    const handleFile = (file?: File) => {
        if (!file) return;
        setFileName(file.name);
        if (filePreview) URL.revokeObjectURL(filePreview);
        setFilePreview(URL.createObjectURL(file));
    };

    const onDrop = (e: DragEvent) => {
        e.preventDefault();
        setDragging(false);
        handleFile(e.dataTransfer.files[0]);
    };

    const startCreate = () => {
        setLoading(true);
        setProgress(0);
    };

    return (
        <AppLayout flush>
            <Head title={tool.name} />
            <div className="flex w-full min-w-0 flex-col md:h-full md:min-h-0 [&_a]:cursor-pointer [&_button]:cursor-pointer [&_button:disabled]:cursor-not-allowed [&_label]:cursor-pointer">
                <div className="flex flex-col rounded-xl bg-[#070708] md:min-h-0 md:flex-1 md:overflow-hidden">
                    <div className="flex flex-col md:min-h-0 md:flex-1 md:overflow-hidden md:flex-row">
                        {/* Create panel */}
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
                                    {/* Header */}
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

                                    {/* Upload */}
                                    <section className="space-y-2">
                                        <SectionLabel>{isImageTool ? t('detail.uploadImage') : t('detail.upload')}</SectionLabel>
                                        <div
                                            onDragOver={(e) => {
                                                e.preventDefault();
                                                setDragging(true);
                                            }}
                                            onDragLeave={(e) => {
                                                e.preventDefault();
                                                setDragging(false);
                                            }}
                                            onDrop={onDrop}
                                            onClick={() => inputRef.current?.click()}
                                            className={`group relative flex cursor-pointer flex-col items-center justify-center overflow-hidden rounded-2xl border border-dashed px-4 py-7 transition ${
                                                dragging
                                                    ? 'border-[#FF5733]/60 bg-orange-500/[0.08]'
                                                    : 'border-white/15 bg-gradient-to-b from-white/[0.05] to-white/[0.02] hover:border-orange-400/40 hover:bg-orange-500/[0.04]'
                                            }`}
                                        >
                                            {filePreview ? (
                                                <div className="relative w-full">
                                                    {isImageTool ? (
                                                        <img
                                                            src={filePreview}
                                                            alt=""
                                                            className="mx-auto max-h-36 rounded-xl object-contain"
                                                        />
                                                    ) : (
                                                        <video
                                                            src={filePreview}
                                                            className="mx-auto max-h-36 rounded-xl object-contain"
                                                            muted
                                                            playsInline
                                                        />
                                                    )}
                                                    <p className="mt-3 truncate text-center text-[12px] font-medium text-zinc-200">{fileName}</p>
                                                    <p className="mt-0.5 text-center text-[11px] text-white/35">{t('detail.changeFile')}</p>
                                                </div>
                                            ) : (
                                                <>
                                                    <div className="mb-3 flex h-12 w-12 items-center justify-center rounded-2xl border border-white/10 bg-white/[0.05] shadow-[0_12px_30px_-18px_rgba(255,87,51,0.8)] transition group-hover:border-orange-400/30">
                                                        <svg className="h-5 w-5 text-[#FF8A65]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                                                        </svg>
                                                    </div>
                                                    <p className="text-[13px] font-medium text-zinc-100">{t('detail.uploadHint')}</p>
                                                    <p className="mt-1 text-[11px] text-white/35">
                                                        {isImageTool ? t('detail.uploadImageTypes') : t('detail.uploadVideoTypes')}
                                                    </p>
                                                </>
                                            )}
                                            <input
                                                ref={inputRef}
                                                type="file"
                                                accept={isImageTool ? 'image/*' : 'video/*'}
                                                className="hidden"
                                                onChange={(e) => handleFile(e.target.files?.[0])}
                                            />
                                        </div>
                                    </section>

                                    {/* Model */}
                                    <section className="space-y-2">
                                        <SectionLabel>{t('detail.aiModel')}</SectionLabel>
                                        <div className="overflow-hidden rounded-2xl border border-white/[0.08] bg-gradient-to-b from-white/[0.05] to-white/[0.02] p-1 shadow-[0_0_0_1px_rgba(255,255,255,0.02)_inset]">
                                            <div className="relative overflow-hidden rounded-xl">
                                                <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_80%_20%,rgba(255,87,51,0.28),transparent_55%),linear-gradient(135deg,#1a1020_0%,#0d0d14_50%,#12101a_100%)]" />
                                                <div className="relative flex items-center gap-3 px-3.5 py-3">
                                                    <div className="flex h-10 w-10 items-center justify-center rounded-xl border border-white/10 bg-black/30">
                                                        <svg className="h-4 w-4 text-[#FF8A65]" viewBox="0 0 24 24" fill="currentColor">
                                                            <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z" />
                                                        </svg>
                                                    </div>
                                                    <div className="min-w-0 flex-1">
                                                        <p className="truncate text-[13px] font-medium text-white">{modelLabel}</p>
                                                        <p className="text-[11px] text-white/40">{t('detail.modelHint')}</p>
                                                    </div>
                                                    <select
                                                        value={model}
                                                        onChange={(e) => setModel(e.target.value)}
                                                        className="absolute inset-0 cursor-pointer opacity-0"
                                                        aria-label={t('detail.aiModel')}
                                                    >
                                                        <option value="auto">{t('detail.modelAuto')}</option>
                                                        <option value="standard">{t('detail.modelStandard')}</option>
                                                        <option value="pro">{t('detail.modelPro')}</option>
                                                        <option value="ultra">{t('detail.modelUltra')}</option>
                                                    </select>
                                                    <svg className="h-4 w-4 shrink-0 text-white/35" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="m6 9 6 6 6-6" />
                                                    </svg>
                                                </div>
                                            </div>
                                        </div>
                                    </section>

                                    {/* Scale */}
                                    {showScale && (
                                        <section className="space-y-2">
                                            <SectionLabel>{t('detail.scale')}</SectionLabel>
                                            <div className="grid grid-cols-3 gap-2">
                                                {['2x', '4x', '8x'].map((option) => (
                                                    <OptionPill key={option} active={scale === option} onClick={() => setScale(option)}>
                                                        {option}
                                                    </OptionPill>
                                                ))}
                                            </div>
                                        </section>
                                    )}

                                    {/* Mode */}
                                    {showMode && (
                                        <section className="space-y-2">
                                            <SectionLabel>{t('detail.mode')}</SectionLabel>
                                            <div className="grid grid-cols-3 gap-2">
                                                {[
                                                    { label: t('detail.modeGeneral'), value: 'general' },
                                                    { label: t('detail.modeAnimation'), value: 'animation' },
                                                    { label: t('detail.modeLowLight'), value: 'low light' },
                                                ].map((option) => (
                                                    <OptionPill
                                                        key={option.value}
                                                        active={mode === option.value}
                                                        onClick={() => setMode(option.value)}
                                                    >
                                                        {option.label}
                                                    </OptionPill>
                                                ))}
                                            </div>
                                        </section>
                                    )}

                                    {/* Settings */}
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
                                                        <CoralToggle
                                                            checked={quality}
                                                            onChange={setQuality}
                                                            label={t('detail.hq')}
                                                            description={t('detail.hqDesc')}
                                                        />
                                                        <CoralToggle
                                                            checked={publicVisible}
                                                            onChange={setPublicVisible}
                                                            label={t('detail.public')}
                                                            description={t('detail.publicDesc')}
                                                        />
                                                        <CoralToggle
                                                            checked={copyProtection}
                                                            onChange={setCopyProtection}
                                                            label={t('detail.copyProtection')}
                                                            description={t('detail.copyProtectionDesc')}
                                                        />
                                                    </div>
                                                </motion.div>
                                            )}
                                        </AnimatePresence>
                                    </section>
                                </div>

                                {/* Sticky create bar */}
                                <div className="relative shrink-0 border-t border-white/[0.07] bg-[#0a0a0f]/95 p-3 backdrop-blur-xl">
                                    <div className="mb-2.5 flex items-center justify-between gap-2">
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            <span className="rounded-lg border border-white/10 bg-white/[0.04] px-2 py-1 text-[11px] text-white/65">
                                                {modelLabel}
                                            </span>
                                            {showScale && (
                                                <span className="rounded-lg border border-white/10 bg-white/[0.04] px-2 py-1 text-[11px] text-white/65">
                                                    {scale}
                                                </span>
                                            )}
                                            {quality && (
                                                <span className="rounded-lg border border-orange-400/20 bg-orange-500/10 px-2 py-1 text-[11px] text-orange-100/80">
                                                    HQ
                                                </span>
                                            )}
                                        </div>
                                        <div className="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/[0.04] px-2 py-1 text-[11px] text-white/65">
                                            <svg className="h-3 w-3 text-[#FF8A65]" viewBox="0 0 24 24" fill="currentColor">
                                                <path d="M12 2l2.4 7.2L22 12l-7.6 2.8L12 22l-2.4-7.2L2 12l7.6-2.8L12 2z" />
                                            </svg>
                                            <span>12</span>
                                            <span className="text-white/35">{t('detail.credits')}</span>
                                        </div>
                                    </div>

                                    <motion.button
                                        type="button"
                                        whileTap={!loading ? { scale: 0.98 } : undefined}
                                        disabled={loading}
                                        onClick={startCreate}
                                        className="group relative flex h-12 w-full items-center justify-center gap-2 overflow-hidden rounded-xl bg-gradient-to-b from-[#FF6A45] via-[#FF5733] to-[#D63A18] text-sm font-semibold text-white shadow-[0_10px_30px_rgba(255,87,51,0.35)] transition disabled:cursor-not-allowed disabled:opacity-45"
                                    >
                                        {!loading && (
                                            <span className="pointer-events-none absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/35 to-transparent transition-transform duration-[900ms] ease-out group-hover:translate-x-full" />
                                        )}
                                        {loading ? (
                                            <>
                                                <span className="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white" />
                                                <span>{t('detail.processing')}</span>
                                            </>
                                        ) : (
                                            <>
                                                <svg className="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                                                    <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z" />
                                                </svg>
                                                <span>{t('detail.create')}</span>
                                            </>
                                        )}
                                        {loading && (
                                            <div className="absolute inset-x-0 bottom-0 h-1 overflow-hidden bg-black/20">
                                                <motion.div
                                                    className="h-full bg-white/80"
                                                    initial={false}
                                                    animate={{ width: `${progress}%` }}
                                                    transition={{ duration: 0.3 }}
                                                />
                                            </div>
                                        )}
                                    </motion.button>
                                </div>
                            </div>
                        </motion.aside>

                        {/* Preview stage */}
                        <motion.div
                            initial={{ opacity: 0 }}
                            animate={{ opacity: 1 }}
                            transition={{ delay: 0.08, duration: 0.4 }}
                            className="relative flex min-h-[50vh] min-w-0 w-full flex-col md:min-h-0 md:flex-1 md:overflow-hidden"
                        >
                            <div aria-hidden className="pointer-events-none absolute inset-0">
                                <div className="absolute left-1/2 top-1/3 h-80 w-80 -translate-x-1/2 rounded-full bg-[#FF5733]/12 blur-[120px]" />
                                <div className="absolute inset-0 bg-[radial-gradient(circle_at_50%_35%,rgba(255,255,255,0.035),transparent_55%)]" />
                                <div className="absolute inset-0 bg-[linear-gradient(to_bottom,transparent_0%,rgba(7,7,8,0.35)_100%)]" />
                            </div>

                            <div className="relative z-10 flex items-center justify-between gap-3 border-b border-white/[0.05] px-4 py-3 md:px-5">
                                <div>
                                    <p className="text-[11px] font-medium uppercase tracking-[0.14em] text-white/35">{t('detail.preview')}</p>
                                    <p className="mt-0.5 text-[13px] text-white/70">{t('detail.previewHint')}</p>
                                </div>
                                <div className="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/[0.04] px-2.5 py-1 text-[11px] text-white/50">
                                    <span className="h-1.5 w-1.5 rounded-full bg-emerald-400 shadow-[0_0_8px_rgba(52,211,153,0.8)]" />
                                    {t('detail.demoLive')}
                                </div>
                            </div>

                            <div className="relative z-10 flex min-h-0 flex-1 items-center justify-center p-4 md:p-6">
                                <motion.div
                                    initial={{ opacity: 0, y: 16, scale: 0.98 }}
                                    animate={{ opacity: 1, y: 0, scale: 1 }}
                                    transition={{ delay: 0.12, duration: 0.45, ease: [0.22, 1, 0.36, 1] }}
                                    className="relative w-full max-w-4xl"
                                >
                                    <div className="absolute -inset-px rounded-[1.35rem] bg-gradient-to-b from-white/15 via-white/5 to-transparent opacity-70" />
                                    <div className="relative overflow-hidden rounded-[1.25rem] border border-white/[0.08] bg-black/50 shadow-[0_40px_100px_-40px_rgba(0,0,0,0.9)]">
                                        <div className="aspect-video w-full">
                                            <video
                                                key={tool.video}
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
                        </motion.div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

function SectionLabel({ children }: { children: ReactNode }) {
    return <p className="text-[11px] font-medium uppercase tracking-[0.12em] text-white/40">{children}</p>;
}

function OptionPill({
    active,
    onClick,
    children,
}: {
    active: boolean;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`relative rounded-xl border px-2 py-2.5 text-[12px] font-medium transition ${
                active
                    ? 'border-[#FF5733]/50 bg-gradient-to-b from-[#FF5733]/25 to-[#FF5733]/10 text-white shadow-[0_8px_24px_-16px_rgba(255,87,51,0.9)]'
                    : 'border-white/[0.07] bg-white/[0.03] text-white/55 hover:border-white/15 hover:text-white/80'
            }`}
        >
            <span className="relative">{children}</span>
        </button>
    );
}

function CoralToggle({
    checked,
    onChange,
    label,
    description,
}: {
    checked: boolean;
    onChange: (v: boolean) => void;
    label: string;
    description: string;
}) {
    return (
        <label className="flex cursor-pointer items-center justify-between gap-4 rounded-xl px-1 py-2.5 transition hover:bg-white/[0.02]">
            <div className="min-w-0 flex-1">
                <p className="text-[13px] font-medium text-white/85">{label}</p>
                <p className="text-[11px] text-white/35">{description}</p>
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
