import { useCallback, useEffect, useMemo, useRef, useState, type SyntheticEvent, type VideoHTMLAttributes } from 'react';

type Props = Omit<VideoHTMLAttributes<HTMLVideoElement>, 'autoPlay' | 'controls'> & {
    /** Seconds into the clip to freeze as the thumbnail frame. */
    seekTo?: number;
    /** Play on hover (desktop). Defaults to true. */
    playOnHover?: boolean;
};

function withTimeFragment(url: string, seconds: number): string {
    if (!url || url.includes('#') || url.startsWith('blob:') || url.startsWith('data:')) return url;
    return `${url}#t=${Math.max(0.1, seconds).toFixed(2)}`;
}

function mediaFitClass(className: string): string {
    if (/\bobject-contain\b/.test(className)) return 'object-contain object-center';
    return 'object-cover object-center';
}

/**
 * Video card thumb: never sits on a black frame.
 * Prefer instant poster when available; otherwise seek ~2s with a shimmer underlay.
 */
export default function VideoThumb({
    src,
    poster,
    className = '',
    seekTo = 2,
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

    const stillOnly = Boolean(poster) && !playOnHover;
    const framedSrc = useMemo(() => (src ? withTimeFragment(src, seekTo) : undefined), [src, seekTo]);
    const fit = mediaFitClass(className);

    useEffect(() => {
        framedRef.current = false;
        setFrameReady(false);
        setPlaying(false);
    }, [src, seekTo, poster]);

    const markReady = useCallback((video: HTMLVideoElement) => {
        video.pause();
        framedRef.current = true;
        setFrameReady(true);
    }, []);

    const freezeAt = useCallback(
        (video: HTMLVideoElement) => {
            if (framedRef.current) return;
            if (!Number.isFinite(video.duration) || video.duration <= 0) {
                if (video.readyState >= 2) markReady(video);
                return;
            }
            const target = Math.min(Math.max(0.1, seekTo), Math.max(0.1, video.duration - 0.05));
            if (Math.abs(video.currentTime - target) < 0.2 && video.readyState >= 2) {
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
                <img src={poster} alt="" className={`absolute inset-0 size-full ${fit}`} draggable={false} loading="lazy" />
            </div>
        );
    }

    const showPoster = Boolean(poster) && !playing && !frameReady;
    const showShimmer = !poster && !frameReady && !playing;

    return (
        <div className={`relative overflow-hidden bg-zinc-900 ${className}`}>
            {showPoster && (
                <img
                    src={poster}
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
                poster={poster || undefined}
                muted={muted}
                playsInline={playsInline}
                preload={preload}
                className={`absolute inset-0 size-full ${fit} transition-opacity duration-300 ${
                    frameReady || playing ? 'opacity-100' : 'opacity-0'
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
                            const target = Math.min(Math.max(0.1, seekTo), Math.max(0.1, video.duration - 0.05));
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
