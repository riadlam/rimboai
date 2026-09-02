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

/** Reuse a captured still when opening the preview modal (avoids black screen before play). */
export function getCachedVideoPoster(...keys: Array<string | null | undefined>): string | undefined {
    for (const key of keys) {
        if (!key) continue;
        const hit = framePosterCache.get(key);
        if (hit) return hit;
    }
    return undefined;
}

export function rememberVideoPoster(src: string, poster: string, extraKeys: string[] = []): void {
    if (!src || !poster) return;
    framePosterCache.set(src, poster);
    for (const key of extraKeys) {
        if (key) framePosterCache.set(key, poster);
    }
}

/** True when URL is almost certainly a still image (not a video file). */
export function isLikelyImageUrl(url: string | null | undefined): boolean {
    if (!url) return false;
    if (url.startsWith('data:image')) return true;
    if (url.startsWith('blob:')) return false;
    return /\.(jpe?g|png|webp|gif|avif)(\?|#|$)/i.test(url);
}

/** Poll until the grid thumb captures a frame (reuse without remounting VideoThumb in preview). */
export function subscribeVideoPoster(
    keys: Array<string | null | undefined> | string,
    onPoster: (poster: string) => void,
): () => void {
    const list = (Array.isArray(keys) ? keys : [keys]).filter(Boolean) as string[];
    const existing = getCachedVideoPoster(...list);
    if (existing) {
        onPoster(existing);
        return () => undefined;
    }

    const id = window.setInterval(() => {
        const poster = getCachedVideoPoster(...list);
        if (poster) {
            onPoster(poster);
            window.clearInterval(id);
        }
    }, 50);

    return () => window.clearInterval(id);
}

export function withVideoTimeFragment(url: string, seconds = 0.15): string {
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
    const initialCaptured =
        src && !poster ? getCachedVideoPoster(src) ?? null : null;
    const [capturedPoster, setCapturedPoster] = useState<string | null>(() => initialCaptured);

    const previewMode =
        !playOnHover && (autoLoop || (typeof autoPreviewSeconds === 'number' && autoPreviewSeconds > 0));
    const clipPreviewSeconds =
        !autoLoop && typeof autoPreviewSeconds === 'number' && autoPreviewSeconds > 0
            ? autoPreviewSeconds
            : undefined;
    const effectivePoster = poster || capturedPoster || undefined;
    // Only treat as still-image card when poster is not the video URL itself (CDN thumbs, data URLs).
    const stillOnly =
        Boolean(effectivePoster) &&
        !playOnHover &&
        !previewMode &&
        (effectivePoster!.startsWith('data:') || effectivePoster !== src);
    const framedSrc = useMemo(() => (src ? withVideoTimeFragment(src, seekTo) : undefined), [src, seekTo]);
    const fit = mediaFitClass(className);

    useEffect(() => {
        framedRef.current = false;
        setFrameReady(false);
        setPlaying(false);
        setLifted(false);
        if (src && poster) {
            rememberVideoPoster(src, poster, warmKey ? [warmKey] : []);
            setCapturedPoster(null);
        } else {
            setCapturedPoster(src ? getCachedVideoPoster(src, warmKey) ?? null : null);
        }
    }, [src, seekTo, poster, warmKey]);

    useEffect(() => {
        if (!warmKey || !src) return;
        const el = videoRef.current;
        const host = hostRef.current;
        if (!el || !host) return;
        return bindTrendWarmVideo(warmKey, el, host, {
            onLift: () => {
                if (src) {
                    const dataUrl = captureFrameDataUrl(el);
                    if (dataUrl) {
                        rememberVideoPoster(src, dataUrl, [warmKey]);
                        setCapturedPoster(dataUrl);
                    } else if (poster) {
                        rememberVideoPoster(src, poster, [warmKey]);
                        setCapturedPoster(poster);
                    }
                }
                setLifted(true);
                setPlaying(false);
            },
            onRestore: () => {
                setLifted(false);
                el.controls = false;
                el.removeAttribute('controls');
                el.muted = true;
                el.defaultMuted = true;
                el.autoplay = false;
                el.loop = autoLoop;
                el.className = `absolute inset-0 size-full ${fit} opacity-100`;

                // Lab still cards: re-freeze the thumb frame (playback often leaves t=0 → black).
                if (!playOnHover && !previewMode) {
                    setPlaying(false);
                    el.pause();

                    const duration = el.duration;
                    const target =
                        Number.isFinite(duration) && duration > 0
                            ? Math.min(Math.max(0.05, seekTo), Math.max(0.05, duration - 0.05))
                            : Math.max(0.05, seekTo);

                    const finish = () => {
                        framedRef.current = true;
                        setFrameReady(true);
                        if (src) {
                            const dataUrl = captureFrameDataUrl(el);
                            if (dataUrl) {
                                rememberVideoPoster(src, dataUrl, [warmKey]);
                                setCapturedPoster(dataUrl);
                            }
                        }
                    };

                    const onSeeked = () => {
                        el.removeEventListener('seeked', onSeeked);
                        finish();
                    };
                    el.addEventListener('seeked', onSeeked);
                    try {
                        if (Math.abs(el.currentTime - target) < 0.08 && el.readyState >= 2) {
                            el.removeEventListener('seeked', onSeeked);
                            finish();
                        } else {
                            el.currentTime = target;
                            // Some browsers skip seeked when the frame is already decoded.
                            window.setTimeout(() => {
                                if (el.readyState >= 2 && Math.abs(el.currentTime - target) < 0.2) {
                                    el.removeEventListener('seeked', onSeeked);
                                    finish();
                                }
                            }, 120);
                        }
                    } catch {
                        el.removeEventListener('seeked', onSeeked);
                        finish();
                    }
                    return;
                }

                setPlaying(false);
                try {
                    el.currentTime = 0;
                } catch {
                    /* ignore */
                }
                requestAnimationFrame(() => {
                    el.muted = true;
                    void el.play()
                        .then(() => setPlaying(true))
                        .catch(() => setPlaying(false));
                });
            },
        });
    }, [warmKey, src, previewMode, playOnHover, fit, seekTo, autoLoop, poster]);

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
        if (lifted) return;
        const video = videoRef.current;
        if (!video) return;

        if (inView) {
            video.muted = true;
            void video
                .play()
                .then(() => setPlaying(true))
                .catch(() => undefined);
        } else {
            video.pause();
            setPlaying(false);
        }
    }, [inView, previewMode, src, lifted]);

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
                    rememberVideoPoster(src, dataUrl, warmKey ? [warmKey] : []);
                    setCapturedPoster(dataUrl);
                }
            }
        },
        [poster, previewMode, src, warmKey],
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

    const showPoster = Boolean(effectivePoster) && (!playing || lifted);
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

            <div ref={hostRef} className={`absolute inset-0 ${lifted ? 'pointer-events-none opacity-0' : ''}`}>
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
                        lifted || playing || previewMode || (!effectivePoster && frameReady) ? 'opacity-100' : 'opacity-0'
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
