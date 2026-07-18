import Plyr from 'plyr';
import { forwardRef, useEffect, useImperativeHandle, useMemo, useRef } from 'react';
import 'plyr/dist/plyr.css';
import { captureLastFrameFromVideoElement, sameOriginMediaUrl } from '@/lib/labReuse';

type Props = {
    src: string;
    poster?: string;
    className?: string;
    autoPlay?: boolean;
    /** When true, clip loops until the user pauses. */
    loop?: boolean;
    /** Muted teaser: play from 0 then pause at this second (user Play continues full clip). Ignored when `loop` is set. */
    previewSeconds?: number;
    objectFit?: 'cover' | 'contain';
    /**
     * Proxy remote CDN URLs through same-origin /lab/asset-fetch so canvas
     * frame capture is not CORS-tainted (Continue from last frame).
     */
    sameOriginProxy?: boolean;
};

export type LabVideoPlayerHandle = {
    getVideoElement: () => HTMLVideoElement | null;
    captureLastFrameFile: (name?: string) => Promise<File>;
};

/**
 * Branded Plyr player for Lab / Trends asset previews.
 */
const LabVideoPlayer = forwardRef<LabVideoPlayerHandle, Props>(function LabVideoPlayer(
    {
        src,
        poster,
        className = '',
        autoPlay = false,
        loop = false,
        previewSeconds,
        objectFit = 'contain',
        sameOriginProxy = false,
    },
    ref,
) {
    const videoRef = useRef<HTMLVideoElement>(null);
    const playerRef = useRef<Plyr | null>(null);
    const previewDoneRef = useRef(false);

    const playSrc = useMemo(
        () => (sameOriginProxy ? sameOriginMediaUrl(src) : src),
        [sameOriginProxy, src],
    );

    const teaserSeconds = loop ? undefined : previewSeconds;
    const shouldAutoplay = Boolean(teaserSeconds) || autoPlay || loop;

    useImperativeHandle(ref, () => ({
        getVideoElement: () => videoRef.current,
        captureLastFrameFile: async (name?: string) => {
            const el = videoRef.current;
            if (!el) {
                throw new Error('Video player is not ready yet.');
            }
            if (el.readyState < 2) {
                await new Promise<void>((resolve, reject) => {
                    const onReady = () => {
                        cleanup();
                        resolve();
                    };
                    const onError = () => {
                        cleanup();
                        reject(new Error('Video failed to load'));
                    };
                    const cleanup = () => {
                        el.removeEventListener('loadeddata', onReady);
                        el.removeEventListener('canplay', onReady);
                        el.removeEventListener('error', onError);
                    };
                    el.addEventListener('loadeddata', onReady, { once: true });
                    el.addEventListener('canplay', onReady, { once: true });
                    el.addEventListener('error', onError, { once: true });
                    // Already usable?
                    if (el.readyState >= 2) {
                        cleanup();
                        resolve();
                    }
                });
            }
            return captureLastFrameFromVideoElement(el, { name });
        },
    }));

    useEffect(() => {
        const el = videoRef.current;
        if (!el) return;

        previewDoneRef.current = false;
        playerRef.current?.destroy();
        playerRef.current = new Plyr(el, {
            controls: [
                'play-large',
                'play',
                'progress',
                'current-time',
                'duration',
                'mute',
                'volume',
                'settings',
                'fullscreen',
            ],
            settings: ['speed'],
            speed: { selected: 1, options: [0.5, 0.75, 1, 1.25, 1.5, 2] },
            hideControls: true,
            resetOnEnd: false,
            loop: { active: loop },
            keyboard: { focused: true, global: false },
            tooltips: { controls: true, seek: true },
            autopause: true,
            storage: { enabled: false },
            muted: shouldAutoplay,
            autoplay: shouldAutoplay,
        });

        const player = playerRef.current;
        const onTimeUpdate = () => {
            if (!teaserSeconds || previewDoneRef.current) return;
            if (player.currentTime >= teaserSeconds) {
                previewDoneRef.current = true;
                player.pause();
                player.currentTime = 0;
                player.muted = false;
            }
        };
        const onPlay = () => {
            if (previewDoneRef.current) {
                player.muted = false;
            }
        };

        player.on('timeupdate', onTimeUpdate);
        player.on('play', onPlay);

        if (shouldAutoplay) {
            void player.play().catch(() => undefined);
        }

        return () => {
            player.off('timeupdate', onTimeUpdate);
            player.off('play', onPlay);
            player.destroy();
            playerRef.current = null;
        };
    }, [playSrc, autoPlay, loop, teaserSeconds, shouldAutoplay]);

    return (
        <div className={`lab-plyr h-full w-full overflow-hidden rounded-[5px] bg-black ${className}`}>
            <video
                ref={videoRef}
                key={playSrc}
                className={`h-full w-full ${objectFit === 'cover' ? 'object-cover' : 'object-contain'}`}
                playsInline
                loop={loop}
                poster={poster || undefined}
                preload={sameOriginProxy ? 'auto' : 'metadata'}
                muted={shouldAutoplay}
            >
                <source src={playSrc} type="video/mp4" />
            </video>
        </div>
    );
});

export default LabVideoPlayer;
