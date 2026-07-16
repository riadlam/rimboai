import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useState, type ReactNode } from 'react';
import LabVideoPlayer from '@/Components/LabVideoPlayer';

export type ImageLabPreviewItem = {
    id: string;
    prompt: string;
    src: string;
    favorite: boolean;
    isPublic?: boolean;
    isFeatured?: boolean;
    aspect?: string;
    resolution?: string;
    duration?: number | string | null;
    audio?: boolean | null;
    quantity?: number | null;
    imageMode?: 'create' | 'variations' | null;
    inputAssets?: { url: string; kind: 'image' | 'video' | 'audio'; name?: string | null; fallbackUrls?: string[] | null }[];
    method?: 'text-to-image' | 'image-to-image' | 'text-to-video' | 'image-to-video' | 'reference-to-video';
    modelName?: string | null;
    videoUrl?: string;
};

type Props = {
    image: ImageLabPreviewItem;
    index: number;
    total: number;
    onClose: () => void;
    onPrev?: () => void;
    onNext?: () => void;
    onToggleFavorite?: (id: string) => void;
    onTogglePublic?: (id: string) => void;
    onDelete?: (ids: string[]) => void;
    onReuseSettings?: (image: ImageLabPreviewItem) => void;
    onUseResult?: (image: ImageLabPreviewItem) => void;
    onUseLastFrame?: (image: ImageLabPreviewItem) => void | Promise<void>;
};

function methodLabel(method?: ImageLabPreviewItem['method']): string {
    switch (method) {
        case 'image-to-image':
            return 'Image to Image';
        case 'text-to-video':
            return 'Text to Video';
        case 'image-to-video':
            return 'Image to Video';
        case 'reference-to-video':
            return 'Reference to Video';
        default:
            return 'Text to Image';
    }
}

function formatModelName(name: string): string {
    return name
        .replace(/[-_]+/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase())
        .replace(/\bGpt\b/g, 'GPT')
        .replace(/\bAi\b/g, 'AI');
}

async function downloadAsset(url: string, filename: string) {
    try {
        const res = await fetch(url);
        if (!res.ok) throw new Error('fetch failed');
        const blob = await res.blob();
        const objectUrl = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = objectUrl;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(objectUrl);
    } catch {
        window.open(url, '_blank', 'noopener,noreferrer');
    }
}

/** Exact image preview used on /lab?type=text-to-image */
export default function ImageLabPreviewModal({
    image,
    index,
    total,
    onClose,
    onPrev,
    onNext,
    onToggleFavorite,
    onTogglePublic,
    onDelete,
    onReuseSettings,
    onUseResult,
    onUseLastFrame,
}: Props) {
    const [zoom, setZoom] = useState(1);
    const [detailsOpen, setDetailsOpen] = useState(true);
    const [copied, setCopied] = useState(false);
    const [downloading, setDownloading] = useState(false);
    const [reusing, setReusing] = useState(false);
    const [capturingFrame, setCapturingFrame] = useState(false);
    const [lightboxOpen, setLightboxOpen] = useState(false);

    const isVideo =
        image.method === 'text-to-video' ||
        image.method === 'image-to-video' ||
        image.method === 'reference-to-video' ||
        Boolean(image.videoUrl);

    const mediaUrl = isVideo ? image.videoUrl || image.src : image.src;
    const modelDisplay = image.modelName?.trim() ? formatModelName(image.modelName.trim()) : '—';
    const downloadName = isVideo ? `video-${image.id}.mp4` : `image-${image.id}.jpg`;

    const copyPrompt = async (text: string) => {
        try {
            await navigator.clipboard.writeText(text);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1500);
        } catch {
            /* ignore */
        }
    };

    const handleDownload = async () => {
        if (!mediaUrl || downloading) return;
        setDownloading(true);
        try {
            await downloadAsset(mediaUrl, downloadName);
        } finally {
            setDownloading(false);
        }
    };

    const handleReuseSettings = () => {
        if (!onReuseSettings || reusing || capturingFrame) return;
        setReusing(true);
        onReuseSettings(image);
        onClose();
    };

    const handleUseResult = () => {
        if (!onUseResult || reusing || capturingFrame || !mediaUrl) return;
        setReusing(true);
        onUseResult(image);
        onClose();
    };

    const handleUseLastFrame = async () => {
        if (!onUseLastFrame || reusing || capturingFrame || !mediaUrl || !isVideo) return;
        setCapturingFrame(true);
        try {
            await onUseLastFrame(image);
            onClose();
        } catch {
            setCapturingFrame(false);
        }
    };

    useEffect(() => {
        setLightboxOpen(false);
        setZoom(1);
    }, [image.id]);

    useEffect(() => {
        if (!lightboxOpen) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setLightboxOpen(false);
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [lightboxOpen]);

    const ratio =
        image.aspect && /^\d+:\d+$/.test(image.aspect)
            ? image.aspect.replace(':', ' / ')
            : isVideo
              ? '16 / 9'
              : '1 / 1';

    return (
        <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-0 sm:p-4 [&_a]:cursor-pointer [&_button]:cursor-pointer"
            onClick={onClose}
        >
            <motion.div
                initial={{ opacity: 0, scale: 0.97, y: 10 }}
                animate={{ opacity: 1, scale: 1, y: 0 }}
                exit={{ opacity: 0, scale: 0.98 }}
                className="flex h-full min-h-0 w-full max-w-6xl flex-col overflow-hidden border-white/10 bg-[#101014] shadow-2xl sm:h-[90vh] sm:rounded-[5px] sm:border md:flex-row"
                onClick={(e) => e.stopPropagation()}
            >
                <div
                    className="relative flex h-[40vh] shrink-0 items-center justify-center overflow-hidden bg-black p-3 md:h-full md:flex-1 md:p-5"
                    style={{ containerType: 'size' }}
                >
                    {/* Mobile close — always visible at top-right when details open */}
                    <button
                        type="button"
                        title="Close"
                        aria-label="Close"
                        onClick={onClose}
                        className="absolute end-3 top-3 z-20 inline-flex h-9 w-9 items-center justify-center rounded-full border border-white/15 bg-black/55 text-white shadow-lg backdrop-blur-md transition hover:bg-black/75 md:hidden"
                    >
                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                            <path d="M18 6 6 18" />
                            <path d="m6 6 12 12" />
                        </svg>
                    </button>

                    <div
                        className={`relative overflow-hidden rounded-[5px] bg-[#14141c] transition-transform duration-200 ${
                            !isVideo ? 'cursor-zoom-in' : ''
                        }`}
                        style={{
                            aspectRatio: ratio,
                            width: 'min(100cqmin, 100%)',
                            height: 'min(100cqmin, 100%)',
                            maxWidth: '100%',
                            maxHeight: '100%',
                            transform: isVideo ? undefined : `scale(${zoom})`,
                        }}
                        onClick={!isVideo ? () => setLightboxOpen(true) : undefined}
                        role={!isVideo ? 'button' : undefined}
                        tabIndex={!isVideo ? 0 : undefined}
                        onKeyDown={
                            !isVideo
                                ? (e) => {
                                      if (e.key === 'Enter' || e.key === ' ') {
                                          e.preventDefault();
                                          setLightboxOpen(true);
                                      }
                                  }
                                : undefined
                        }
                        aria-label={!isVideo ? 'View full image' : undefined}
                    >
                        {isVideo ? (
                            <LabVideoPlayer
                                src={mediaUrl}
                                poster={image.src !== mediaUrl ? image.src : undefined}
                            />
                        ) : (
                            <img
                                key={image.id}
                                src={image.src}
                                alt={image.prompt}
                                data-testid="img-preview"
                                className="pointer-events-none absolute inset-0 size-full object-cover object-center"
                            />
                        )}
                    </div>

                    <div className="absolute bottom-3 start-3 z-10 flex max-w-[calc(100%-1.5rem)] flex-row flex-wrap items-center gap-2 md:bottom-5 md:start-5 md:max-w-[calc(100%-2.5rem)]">
                        <div className="flex items-center gap-0.5 rounded-xl border border-white/10 bg-[#121217]/85 p-1 shadow-lg backdrop-blur-md">
                            {!isVideo && (
                                <>
                                    <PreviewIconBtn
                                        title="Zoom in"
                                        onClick={() => setZoom((z) => Math.min(3, Number((z + 0.25).toFixed(2))))}
                                    >
                                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                            <circle cx="11" cy="11" r="8" />
                                            <line x1="21" x2="16.65" y1="21" y2="16.65" />
                                            <line x1="11" x2="11" y1="8" y2="14" />
                                            <line x1="8" x2="14" y1="11" y2="11" />
                                        </svg>
                                    </PreviewIconBtn>
                                    <PreviewIconBtn
                                        title="Zoom out"
                                        onClick={() => setZoom((z) => Math.max(0.5, Number((z - 0.25).toFixed(2))))}
                                    >
                                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                            <circle cx="11" cy="11" r="8" />
                                            <line x1="21" x2="16.65" y1="21" y2="16.65" />
                                            <line x1="8" x2="14" y1="11" y2="11" />
                                        </svg>
                                    </PreviewIconBtn>
                                    <span className="mx-1 hidden h-4 w-px bg-white/10 sm:block" />
                                </>
                            )}
                            <PreviewIconBtn title={downloading ? 'Downloading…' : 'Download'} onClick={() => void handleDownload()}>
                                <IconDownload className="h-4 w-4" />
                            </PreviewIconBtn>
                        </div>
                    </div>

                    {total > 1 && onPrev && onNext && (
                        <>
                            <NavArrow
                                side="left"
                                onClick={() => {
                                    setZoom(1);
                                    setCopied(false);
                                    onPrev();
                                }}
                            />
                            <NavArrow
                                side="right"
                                onClick={() => {
                                    setZoom(1);
                                    setCopied(false);
                                    onNext();
                                }}
                            />
                        </>
                    )}
                </div>

                <div className="flex min-h-0 flex-1 flex-col overflow-hidden border-t border-white/10 bg-[#111116] font-sans md:w-80 md:flex-none md:border-s md:border-t-0">
                    <div className="hidden shrink-0 items-center justify-between border-b border-white/10 px-4 py-3.5 md:flex">
                        <span className="text-[13px] font-semibold tracking-tight text-zinc-100">Details</span>
                        <div className="flex items-center gap-1">
                            <IconBtn
                                title="Favorite"
                                active={image.favorite}
                                onClick={() => onToggleFavorite?.(image.id)}
                                className="h-8 w-8 border-transparent bg-transparent hover:bg-white/5"
                            >
                                <svg
                                    className={`h-4 w-4 ${image.favorite ? 'fill-[#FF5733] text-[#FF5733]' : ''}`}
                                    fill={image.favorite ? 'currentColor' : 'none'}
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    strokeWidth="1.5"
                                >
                                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
                                </svg>
                            </IconBtn>
                            <IconBtn title="Close" onClick={onClose} className="h-8 w-8 border-transparent bg-transparent hover:bg-white/5">
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                    <path d="M18 6 6 18" />
                                    <path d="m6 6 12 12" />
                                </svg>
                            </IconBtn>
                        </div>
                    </div>

                    <div className="flex shrink-0 items-center justify-between border-b border-white/10 px-4 py-3 md:hidden">
                        <span className="text-[13px] font-semibold tracking-tight text-zinc-100">Details</span>
                        <IconBtn title="Close" onClick={onClose} className="h-8 w-8 border-transparent bg-transparent hover:bg-white/5">
                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                <path d="M18 6 6 18" />
                                <path d="m6 6 12 12" />
                            </svg>
                        </IconBtn>
                    </div>

                    <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain scrollbar-thin">
                        <div className="space-y-2.5 p-4">
                            <div className="grid grid-cols-2 gap-2">
                                <DetailBtn>
                                    <svg className="h-3.5 w-3.5 shrink-0 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                        <path d="M12 10v6" />
                                        <path d="M9 13h6" />
                                        <path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z" />
                                    </svg>
                                    Move To Albums
                                </DetailBtn>
                                <button
                                    type="button"
                                    onClick={() => void handleDownload()}
                                    disabled={!mediaUrl || downloading}
                                    className="inline-flex h-9 w-full cursor-pointer items-center justify-center gap-1.5 rounded-lg border border-white/10 bg-white/[0.04] px-3 font-sans text-[13px] font-medium tracking-tight text-zinc-200 transition hover:border-white/15 hover:bg-white/[0.07] hover:text-white disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <IconDownload className="h-3.5 w-3.5 opacity-70" />
                                    {downloading ? 'Downloading…' : 'Download'}
                                </button>
                            </div>

                            <div className="grid grid-cols-2 gap-2">
                                <button
                                    type="button"
                                    onClick={() => onTogglePublic?.(image.id)}
                                    className={`inline-flex h-9 w-full cursor-pointer items-center justify-center gap-1.5 rounded-lg border px-3 font-sans text-[13px] font-medium tracking-tight transition ${
                                        image.isPublic
                                            ? 'border-[#FF5733]/40 bg-[#FF5733]/15 text-[#ffb39f]'
                                            : 'border-white/10 bg-gradient-to-b from-white/[0.08] to-white/[0.03] text-zinc-200 hover:border-white/15 hover:text-white'
                                    }`}
                                >
                                    <svg className="h-3.5 w-3.5 opacity-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                        <circle cx="12" cy="12" r="10" />
                                        <path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20" />
                                        <path d="M2 12h20" />
                                    </svg>
                                    {image.isPublic ? 'Public' : 'Publish'}
                                </button>
                                <DetailBtn gradient>
                                    <svg className="h-3.5 w-3.5 opacity-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                        <circle cx="18" cy="5" r="3" />
                                        <circle cx="6" cy="12" r="3" />
                                        <circle cx="18" cy="19" r="3" />
                                        <line x1="8.59" x2="15.42" y1="13.51" y2="17.49" />
                                        <line x1="15.41" x2="8.59" y1="6.51" y2="10.49" />
                                    </svg>
                                    Share
                                </DetailBtn>
                            </div>
                        </div>

                        <div className="border-t border-white/10">
                            <button
                                type="button"
                                onClick={() => setDetailsOpen((v) => !v)}
                                className="flex w-full items-center justify-between px-4 py-3.5 transition hover:bg-white/[0.03]"
                            >
                                <div className="flex items-center gap-2">
                                    <svg className="h-4 w-4 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                        <circle cx="12" cy="12" r="10" />
                                        <path d="M12 16v-4" />
                                        <path d="M12 8h.01" />
                                    </svg>
                                    <span className="text-[13px] font-medium tracking-tight text-zinc-200">Generation Details</span>
                                </div>
                                <svg
                                    className={`h-4 w-4 text-zinc-500 transition ${detailsOpen ? 'rotate-0' : 'rotate-180'}`}
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    strokeWidth="1.5"
                                >
                                    <path d="m18 15-6-6-6 6" />
                                </svg>
                            </button>

                            {detailsOpen && (
                                <div className="space-y-3 px-4 pb-4">
                                    <div className="grid grid-cols-2 gap-2">
                                        <DetailBtn onClick={handleReuseSettings} disabled={!onReuseSettings || reusing || capturingFrame}>
                                            <svg className="h-3.5 w-3.5 shrink-0 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                                <path d="M20 7h-9" />
                                                <path d="M14 17H5" />
                                                <circle cx="17" cy="17" r="3" />
                                                <circle cx="7" cy="7" r="3" />
                                            </svg>
                                            Reuse Settings
                                        </DetailBtn>
                                        <DetailBtn onClick={handleUseResult} disabled={!onUseResult || reusing || capturingFrame || !mediaUrl}>
                                            <svg className="h-3.5 w-3.5 shrink-0 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                                <path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5" />
                                                <rect x="2" y="6" width="14" height="12" rx="2" />
                                            </svg>
                                            {isVideo ? 'Use Video' : 'Use Image'}
                                        </DetailBtn>
                                    </div>
                                    {isVideo && onUseLastFrame && (
                                        <DetailBtn
                                            onClick={() => void handleUseLastFrame()}
                                            disabled={reusing || capturingFrame || !mediaUrl}
                                        >
                                            <svg className="h-3.5 w-3.5 shrink-0 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                                <rect x="3" y="3" width="18" height="18" rx="2" />
                                                <path d="M3 15h18" />
                                                <path d="m9 9 3 3 3-3" />
                                            </svg>
                                            {capturingFrame ? 'Capturing last frame…' : 'Continue from last frame'}
                                        </DetailBtn>
                                    )}

                                    <div className="flex items-center justify-between gap-3 text-[13px]">
                                        <span className="shrink-0 text-zinc-500">Model</span>
                                        <span className="truncate text-end font-medium text-[#FF5733]" title={modelDisplay}>
                                            {modelDisplay}
                                        </span>
                                    </div>

                                    <div className="flex items-center justify-between gap-3 text-[13px]">
                                        <span className="shrink-0 text-zinc-500">Method</span>
                                        <span className="truncate text-end font-medium text-zinc-200">{methodLabel(image.method)}</span>
                                    </div>

                                    <div className="space-y-1.5">
                                        <div className="flex items-center justify-between">
                                            <span className="text-[13px] text-zinc-500">Prompt</span>
                                            <button
                                                type="button"
                                                title={copied ? 'Copied' : 'Copy prompt'}
                                                onClick={() => copyPrompt(image.prompt)}
                                                className="inline-flex h-7 w-7 items-center justify-center rounded-md text-zinc-500 transition hover:bg-white/5 hover:text-zinc-200"
                                            >
                                                {copied ? (
                                                    <svg className="h-3.5 w-3.5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                                        <path d="M20 6 9 17l-5-5" />
                                                    </svg>
                                                ) : (
                                                    <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                                        <rect width="14" height="14" x="8" y="8" rx="2" ry="2" />
                                                        <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />
                                                    </svg>
                                                )}
                                            </button>
                                        </div>
                                        <div className="rounded-lg bg-white/[0.03] p-3 ring-1 ring-inset ring-white/5">
                                            <p className="line-clamp-3 text-[13px] leading-relaxed text-zinc-300">{image.prompt}</p>
                                        </div>
                                    </div>

                                    <div className="flex items-center justify-between text-xs text-zinc-600">
                                        <span>{image.aspect || (isVideo ? '16:9' : '1:1')}</span>
                                        <span>
                                            {index + 1} / {total}
                                        </span>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="shrink-0 border-t border-white/10 p-3 md:p-4">
                        <button
                            type="button"
                            onClick={() => {
                                onDelete?.([image.id]);
                                onClose();
                            }}
                            className="inline-flex h-9 w-full items-center justify-center gap-2 rounded-lg font-sans text-[13px] font-medium tracking-tight text-red-400 transition hover:bg-red-500/10 hover:text-red-300"
                        >
                            <IconTrash className="h-4 w-4" />
                            Delete
                        </button>
                    </div>
                </div>
            </motion.div>

            {/* Ecommerce-style full image lightbox — opens when tapping the image in details */}
            <AnimatePresence>
                {lightboxOpen && !isVideo && (
                    <motion.div
                        key="img-lightbox"
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        transition={{ duration: 0.22 }}
                        className="fixed inset-0 z-[70] flex flex-col bg-black/95 backdrop-blur-md"
                        onClick={() => setLightboxOpen(false)}
                    >
                        <div className="pointer-events-none absolute inset-x-0 top-0 z-10 flex items-center justify-between px-4 pb-8 pt-[max(0.75rem,env(safe-area-inset-top))] bg-gradient-to-b from-black/70 to-transparent">
                            <span className="pointer-events-none text-[13px] font-medium text-white/50">
                                {index + 1} / {total}
                            </span>
                            <motion.button
                                type="button"
                                title="Close"
                                aria-label="Close full image"
                                initial={{ opacity: 0, scale: 0.85, y: -6 }}
                                animate={{ opacity: 1, scale: 1, y: 0 }}
                                exit={{ opacity: 0, scale: 0.9 }}
                                transition={{ type: 'spring', stiffness: 420, damping: 28 }}
                                onClick={(e) => {
                                    e.stopPropagation();
                                    setLightboxOpen(false);
                                }}
                                className="pointer-events-auto inline-flex h-10 w-10 items-center justify-center rounded-full border border-white/15 bg-white/10 text-white shadow-lg backdrop-blur-md transition hover:bg-white/20"
                            >
                                <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                    <path d="M18 6 6 18" />
                                    <path d="m6 6 12 12" />
                                </svg>
                            </motion.button>
                        </div>

                        <div className="flex min-h-0 flex-1 items-center justify-center p-4 sm:p-8">
                            <motion.img
                                key={image.src}
                                layoutId={`lab-lightbox-${image.id}`}
                                src={image.src}
                                alt={image.prompt}
                                initial={{ opacity: 0, scale: 0.92 }}
                                animate={{ opacity: 1, scale: 1 }}
                                exit={{ opacity: 0, scale: 0.96 }}
                                transition={{ type: 'spring', stiffness: 280, damping: 28 }}
                                className="max-h-full max-w-full object-contain select-none"
                                draggable={false}
                                onClick={(e) => e.stopPropagation()}
                            />
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>
        </motion.div>
    );
}

function PreviewIconBtn({
    children,
    title,
    onClick,
}: {
    children: ReactNode;
    title: string;
    onClick?: () => void;
}) {
    return (
        <button
            type="button"
            title={title}
            onClick={onClick}
            className="inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg text-zinc-300 transition hover:bg-white/10 hover:text-white active:scale-95"
        >
            {children}
        </button>
    );
}

function DetailBtn({
    children,
    gradient,
    onClick,
    disabled,
}: {
    children: ReactNode;
    gradient?: boolean;
    onClick?: () => void;
    disabled?: boolean;
}) {
    if (gradient) {
        return (
            <button
                type="button"
                onClick={onClick}
                disabled={disabled}
                className="inline-flex h-9 w-full cursor-pointer items-center justify-center gap-1.5 rounded-lg bg-[#FF5733] px-3 font-sans text-[13px] font-medium tracking-tight text-white transition hover:bg-[#ff6b4d] active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50"
            >
                {children}
            </button>
        );
    }

    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            className="inline-flex h-9 w-full cursor-pointer items-center justify-center gap-1.5 rounded-lg border border-white/10 bg-white/[0.04] px-3 font-sans text-[13px] font-medium tracking-tight text-zinc-200 transition hover:border-white/15 hover:bg-white/[0.07] hover:text-white active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50"
        >
            {children}
        </button>
    );
}

function NavArrow({ side, onClick }: { side: 'left' | 'right'; onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`absolute top-1/2 z-10 flex h-10 w-10 -translate-y-1/2 cursor-pointer items-center justify-center rounded-full border border-white/10 bg-black/50 font-sans text-base text-zinc-200 backdrop-blur-md transition hover:bg-black/70 hover:text-white ${
                side === 'left' ? 'start-3' : 'end-3'
            }`}
        >
            {side === 'left' ? '‹' : '›'}
        </button>
    );
}

function IconBtn({
    children,
    title,
    active,
    onClick,
    className = '',
}: {
    children: ReactNode;
    title?: string;
    active?: boolean;
    onClick?: () => void;
    className?: string;
}) {
    return (
        <button
            type="button"
            title={title}
            onClick={onClick}
            className={`inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg border transition active:scale-95 ${
                active
                    ? 'border-[#FF5733]/35 bg-[#FF5733]/15 text-[#ffb39f]'
                    : 'border-white/10 bg-black/40 text-zinc-300 hover:bg-white/10 hover:text-white'
            } ${className}`}
        >
            {children}
        </button>
    );
}

function IconDownload({ className = 'h-4 w-4' }: { className?: string }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
            <path d="M12 4v10.5" />
            <path d="m8.5 11.5 3.5 3.5 3.5-3.5" />
            <path d="M5 17.5v.5A2 2 0 0 0 7 20h10a2 2 0 0 0 2-2v-.5" />
        </svg>
    );
}

function IconTrash({ className = 'h-4 w-4' }: { className?: string }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
            <path d="M4.5 7h15" />
            <path d="M9.5 7V5.5A1.5 1.5 0 0 1 11 4h2a1.5 1.5 0 0 1 1.5 1.5V7" />
            <path d="M18 7v11.5a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V7" />
            <path d="M10 11v5M14 11v5" />
        </svg>
    );
}
