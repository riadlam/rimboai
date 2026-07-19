import { useCallback, useEffect, useMemo, useRef, useState, type SyntheticEvent, type VideoHTMLAttributes } from 'react';
import { bindTrendWarmVideo } from '@/lib/trendWarmVideo';

type Props = Omit<VideoHTMLAttributes<HTMLVideoElement>, 'autoPlay' | 'controls' | 'loop'> & {
    /** Seconds into the clip to freeze as the thumbnail frame. Defaults to first scene. */
    seekTo?: number;
    /** Play on hover (desktop). Defaults to true. */
    playOnHover?: boolean;
    /**
     * When set (and playOnHover is false), muted-autoplay the first N seconds in a loop
     * while the card is in view — used on Trends feed.
     */
    autoPreviewSeconds?: number;
    /**
     * When true (and playOnHover is false), muted-autoplay the full clip in a loop
     * while the card is in view until the user navigates away.
     */
    autoLoop?: boolean;
    /**
     * Registers this video for instant reuse in the trend detail modal
     * (see LabVideoPlayer `warmKey`).
     */
    warmKey?: string;
};

/** Module cache so scrolling a grid of videos doesn't re-capture the same first frame. */
const framePosterCache = new Map<string, string>();

function withTimeFragment(url: string, seconds: number): string {
    if (!url || url.includes('#') || url.startsWith('blob:') || url.startsWith('data:')) return url;
    return `${url}#t=${Math.max(0.05, seconds).toFixed(2)}`;
}

function mediaFitClass(className: string): string {
    if (/\bobject-contain\b/.test(className)) return 'object-contain object-center';
    return 'object-cover object-center';
}

function captureFrameDataUrl(video: HTMLVideoElement): string | null {
    try {
        const w = video.videoWidth;
        const h = video.videoHeight;
        if (w < 2 || h < 2) return null;
        const canvas = document.createElement('canvas');
        const maxEdge = 640;
        const scale = Math.min(1, maxEdge / Math.max(w, h));
        canvas.width = Math.max(2, Math.round(w * scale));
        canvas.height = Math.max(2, Math.round(h * scale));
        const ctx = canvas.getContext('2d');
        if (!ctx) return null;
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        return canvas.toDataURL('image/jpeg', 0.82);
    } catch {
        return null;
    }
}

/**
 * Video card thumb: still frame + optional hover play or auto 0–N second muted preview.
 */
export default function VideoThumb({
    src,
    poster,
    className = '',
    seekTo = 0.15,
    playOnHover = true,
    autoPreviewSeconds,
    autoLoop = false,
    warmKey,
    muted = true,
    playsInline = true,
    preload = 'auto',
    onLoadedMetadata,
    onMouseEnter,
    onMouseLeave,
    ...rest
}: Props) {
    const videoRef = useRef<HTMLVideoElement>(null);
    const hostRef = useRef<HTMLDivElement>(null);
    const rootRef = useRef<HTMLDivElement>(null);
    const framedRef = useRef(false);
    const [frameReady, setFrameReady] = useState(false);
    const [playing, setPlaying] = useState(false);
    const [inView, setInView] = useState(false);
    const [lifted, setLifted] = useState(false);
    const [capturedPoster, setCapturedPoster] = useState<string | null>(() =>
        src && !poster ? framePosterCache.get(src) ?? null : null,
    );

    const previewMode =
        !playOnHover && (autoLoop || (typeof autoPreviewSeconds === 'number' && autoPreviewSeconds > 0));
    const clipPreviewSeconds =
        !autoLoop && typeof autoPreviewSeconds === 'number' && autoPreviewSeconds > 0
            ? autoPreviewSeconds
            : undefined;
    const effectivePoster = poster || capturedPoster || undefined;
    const stillOnly = Boolean(effectivePoster) && !playOnHover && !previewMode;
    const framedSrc = useMemo(() => (src ? withTimeFragment(src, seekTo) : undefined), [src, seekTo]);
    const fit = mediaFitClass(className);

    useEffect(() => {
        framedRef.current = false;
        setFrameReady(false);
        setPlaying(false);
        setLifted(false);
        setCapturedPoster(src && !poster ? framePosterCache.get(src) ?? null : null);
    }, [src, seekTo, poster]);

    useEffect(() => {
        if (!warmKey || !src) return;
        const el = videoRef.current;
        const host = hostRef.current;
        if (!el || !host) return;
        return bindTrendWarmVideo(warmKey, el, host, {
            onLift: () => setLifted(true),
            onRestore: () => {
                setLifted(false);
                setPlaying(true);
            },
        });
    }, [warmKey, src, previewMode]);

    useEffect(() => {
        if (!previewMode || !rootRef.current) return;
        const node = rootRef.current;
        const io = new IntersectionObserver(
            (entries) => {
                const entry = entries[0];
                setInView(Boolean(entry?.isIntersecting));
            },
            { rootMargin: '80px', threshold: 0.35 },
        );
        io.observe(node);
        return () => io.disconnect();
    }, [previewMode, src]);

    useEffect(() => {
        if (!previewMode) return;
        const video = videoRef.current;
        if (!video) return;

        if (inView) {
            video.muted = true;
            try {
                video.currentTime = 0;
            } catch {
                /* ignore */
            }
            void video
                .play()
                .then(() => setPlaying(true))
                .catch(() => undefined);
        } else {
            video.pause();
            setPlaying(false);
        }
    }, [inView, previewMode, src]);

    const markReady = useCallback(
        (video: HTMLVideoElement) => {
            if (!previewMode) {
                video.pause();
            }
            framedRef.current = true;
            setFrameReady(true);

            if (!poster && src && !framePosterCache.has(src) && video.readyState >= 2) {
                const dataUrl = captureFrameDataUrl(video);
                if (dataUrl) {
                    framePosterCache.set(src, dataUrl);
                    setCapturedPoster(dataUrl);
                }
            }
        },
        [poster, previewMode, src],
    );

    const freezeAt = useCallback(
        (video: HTMLVideoElement) => {
            if (framedRef.current) return;

            if (video.readyState >= 2) {
                setFrameReady(true);
            }

            if (!Number.isFinite(video.duration) || video.duration <= 0) {
                if (video.readyState >= 2) markReady(video);
                return;
            }

            if (previewMode) {
                framedRef.current = true;
                setFrameReady(true);
                return;
            }

            const target = Math.min(Math.max(0.05, seekTo), Math.max(0.05, video.duration - 0.05));
            if (Math.abs(video.currentTime - target) < 0.15 && video.readyState >= 2) {
                markReady(video);
                return;
            }

            const onSeeked = () => {
                video.removeEventListener('seeked', onSeeked);
                markReady(video);
            };
            video.addEventListener('seeked', onSeeked);
            try {
                video.currentTime = target;
            } catch {
                video.removeEventListener('seeked', onSeeked);
                if (video.readyState >= 2) markReady(video);
            }
        },
        [markReady, previewMode, seekTo],
    );

    const handleLoadedMetadata = (e: SyntheticEvent<HTMLVideoElement>) => {
        freezeAt(e.currentTarget);
        onLoadedMetadata?.(e);
    };

    const handleTimeUpdate = (e: SyntheticEvent<HTMLVideoElement>) => {
        if (!previewMode || !clipPreviewSeconds) return;
        const video = e.currentTarget;
        if (video.currentTime >= clipPreviewSeconds) {
            try {
                video.currentTime = 0;
            } catch {
                /* ignore */
            }
            if (inView) {
                void video.play().catch(() => undefined);
            }
        }
    };

    const handleEnded = (e: SyntheticEvent<HTMLVideoElement>) => {
        if (!previewMode || !autoLoop || !inView) return;
        const video = e.currentTarget;
        try {
            video.currentTime = 0;
        } catch {
            /* ignore */
        }
        void video.play().catch(() => undefined);
    };

    if (stillOnly) {
        return (
            <div className={`relative overflow-hidden bg-zinc-900 ${className}`}>
                <img src={effectivePoster} alt="" className={`absolute inset-0 size-full ${fit}`} draggable={false} loading="lazy" />
            </div>
        );
    }

    const showPoster = Boolean(effectivePoster) && ((!playing && !previewMode) || lifted);
    const showShimmer = !effectivePoster && !frameReady && !playing && !lifted;

    return (
        <div ref={rootRef} className={`relative overflow-hidden bg-zinc-900 ${className}`}>
            {showPoster && (
                <img
                    src={effectivePoster}
                    alt=""
                    className={`pointer-events-none absolute inset-0 size-full ${fit}`}
                    draggable={false}
                />
            )}

            {showShimmer && (
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-0 animate-pulse bg-gradient-to-br from-zinc-700/80 via-zinc-900 to-zinc-800"
                />
            )}

            <div ref={hostRef} className={`absolute inset-0 ${lifted ? 'invisible' : ''}`}>
                <video
                    ref={videoRef}
                    key={previewMode ? src : framedSrc}
                    src={previewMode ? src : framedSrc}
                    poster={effectivePoster}
                    muted={muted}
                    playsInline={playsInline}
                    loop={autoLoop}
                    preload={preload}
                    className={`absolute inset-0 size-full ${fit} transition-opacity duration-200 ${
                        playing || previewMode || (!effectivePoster && frameReady) ? 'opacity-100' : 'opacity-0'
                    }`}
                    onLoadedMetadata={handleLoadedMetadata}
                    onLoadedData={(e) => freezeAt(e.currentTarget)}
                    onCanPlay={(e) => freezeAt(e.currentTarget)}
                    onTimeUpdate={handleTimeUpdate}
                    onEnded={handleEnded}
                    onMouseEnter={(e) => {
                        if (playOnHover) {
                            void e.currentTarget
                                .play()
                                .then(() => setPlaying(true))
                                .catch(() => undefined);
                        }
                        onMouseEnter?.(e);
                    }}
                    onMouseLeave={(e) => {
                        if (playOnHover) {
                            const video = e.currentTarget;
                            video.pause();
                            setPlaying(false);
                            if (Number.isFinite(video.duration) && video.duration > 0) {
                                const target = Math.min(Math.max(0.05, seekTo), Math.max(0.05, video.duration - 0.05));
                                try {
                                    video.currentTime = target;
                                } catch {
                                    /* ignore */
                                }
                            }
                        }
                        onMouseLeave?.(e);
                    }}
                    {...rest}
                />
            </div>
        </div>
    );
}
