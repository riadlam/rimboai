import { useCallback, useEffect, useMemo, useRef, useState, type SyntheticEvent, type VideoHTMLAttributes } from 'react';

type Props = Omit<VideoHTMLAttributes<HTMLVideoElement>, 'autoPlay' | 'controls'> & {
    /** Seconds into the clip to freeze as the thumbnail frame. Defaults to first scene. */
    seekTo?: number;
    /** Play on hover (desktop). Defaults to true. */
    playOnHover?: boolean;
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
        // Keep thumbs light — enough for sharp grid cards without huge data URLs.
        const maxEdge = 640;
        const scale = Math.min(1, maxEdge / Math.max(w, h));
        canvas.width = Math.max(2, Math.round(w * scale));
        canvas.height = Math.max(2, Math.round(h * scale));
        const ctx = canvas.getContext('2d');
        if (!ctx) return null;
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        return canvas.toDataURL('image/jpeg', 0.82);
    } catch {
        // Cross-origin / tainted canvas — fall back to live video frame.
        return null;
    }
}

/**
 * Video card thumb: always try to show a real first-scene still.
 * Prefer provided poster; otherwise freeze ~0.15s and cache a JPEG still when CORS allows.
 */
export default function VideoThumb({
    src,
    poster,
    className = '',
    seekTo = 0.15,
    playOnHover = true,
    muted = true,
    playsInline = true,
    preload = 'auto',
    onLoadedMetadata,
    onMouseEnter,
    onMouseLeave,
    ...rest
}: Props) {
    const videoRef = useRef<HTMLVideoElement>(null);
    const framedRef = useRef(false);
    const [frameReady, setFrameReady] = useState(false);
    const [playing, setPlaying] = useState(false);
    const [capturedPoster, setCapturedPoster] = useState<string | null>(() =>
        src && !poster ? framePosterCache.get(src) ?? null : null,
    );

    const effectivePoster = poster || capturedPoster || undefined;
    const stillOnly = Boolean(effectivePoster) && !playOnHover;
    const framedSrc = useMemo(() => (src ? withTimeFragment(src, seekTo) : undefined), [src, seekTo]);
    const fit = mediaFitClass(className);

    useEffect(() => {
        framedRef.current = false;
        setFrameReady(false);
        setPlaying(false);
        setCapturedPoster(src && !poster ? framePosterCache.get(src) ?? null : null);
    }, [src, seekTo, poster]);

    const markReady = useCallback(
        (video: HTMLVideoElement) => {
            video.pause();
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
        [poster, src],
    );

    const freezeAt = useCallback(
        (video: HTMLVideoElement) => {
            if (framedRef.current) return;

            // Show something ASAP — even mid-seek — so cards aren't empty/blur-only.
            if (video.readyState >= 2) {
                setFrameReady(true);
            }

            if (!Number.isFinite(video.duration) || video.duration <= 0) {
                if (video.readyState >= 2) markReady(video);
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
        [markReady, seekTo],
    );

    const handleLoadedMetadata = (e: SyntheticEvent<HTMLVideoElement>) => {
        freezeAt(e.currentTarget);
        onLoadedMetadata?.(e);
    };

    // Instant path: still thumbnail + no hover preview → zero video wait on mobile cards.
    if (stillOnly) {
        return (
            <div className={`relative overflow-hidden bg-zinc-900 ${className}`}>
                <img src={effectivePoster} alt="" className={`absolute inset-0 size-full ${fit}`} draggable={false} loading="lazy" />
            </div>
        );
    }

    const showPoster = Boolean(effectivePoster) && !playing;
    const showShimmer = !effectivePoster && !frameReady && !playing;

    return (
        <div className={`relative overflow-hidden bg-zinc-900 ${className}`}>
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

            <video
                ref={videoRef}
                key={framedSrc}
                src={framedSrc}
                poster={effectivePoster}
                muted={muted}
                playsInline={playsInline}
                preload={preload}
                className={`absolute inset-0 size-full ${fit} transition-opacity duration-200 ${
                    playing || (!effectivePoster && frameReady) ? 'opacity-100' : 'opacity-0'
                }`}
                onLoadedMetadata={handleLoadedMetadata}
                onLoadedData={(e) => freezeAt(e.currentTarget)}
                onCanPlay={(e) => freezeAt(e.currentTarget)}
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
    );
}
