import Plyr from 'plyr';
import { useEffect, useRef } from 'react';
import { claimTrendWarmVideo, restoreTrendWarmVideo } from '@/lib/trendWarmVideo';
import 'plyr/dist/plyr.css';

type Props = {
    src: string;
    poster?: string;
    className?: string;
    autoPlay?: boolean;
    /** User clicked play — try unmuted autoplay, fall back to muted. */
    userPlay?: boolean;
    /** When true, clip loops until the user pauses. */
    loop?: boolean;
    /** Muted teaser: play from 0 then pause at this second (false Play continues full clip). Ignored when `loop` is set. */
    previewSeconds?: number;
    objectFit?: 'cover' | 'contain';
    /**
     * When set, prefer adopting an already-buffered card <video> registered
     * under this key (instant open — no network reload). Always wrapped in Plyr.
     */
    warmKey?: string;
};

const PLYR_CONTROLS = [
    'play-large',
    'play',
    'progress',
    'current-time',
    'duration',
    'mute',
    'volume',
    'settings',
    'fullscreen',
] as const;

/**
 * Branded Plyr player for Lab / Trends asset previews.
 * Warm-card handoff still uses Plyr — destroy before restore so the card stays clean.
 */
export default function LabVideoPlayer({
    src,
    poster,
    className = '',
    autoPlay = false,
    userPlay = false,
    loop = false,
    previewSeconds,
    objectFit = 'contain',
    warmKey,
}: Props) {
    const mountRef = useRef<HTMLDivElement>(null);
    const fallbackVideoRef = useRef<HTMLVideoElement>(null);
    const playerRef = useRef<Plyr | null>(null);
    const previewDoneRef = useRef(false);
    const adoptedRef = useRef<HTMLVideoElement | null>(null);

    const teaserSeconds = loop ? undefined : previewSeconds;
    const shouldAutoplay = Boolean(teaserSeconds) || autoPlay || loop;
    const fitClass = objectFit === 'cover' ? 'object-cover' : 'object-contain';

    useEffect(() => {
        const mount = mountRef.current;
        if (!mount) return;

        previewDoneRef.current = false;
        playerRef.current?.destroy();
        playerRef.current = null;

        const kickPlayback = (play: () => Promise<void> | void, setMuted: (muted: boolean) => void) => {
            if (!shouldAutoplay) return;
            if (userPlay) {
                setMuted(false);
                void Promise.resolve(play()).catch(() => {
                    setMuted(true);
                    void Promise.resolve(play()).catch(() => undefined);
                });
                return;
            }
            setMuted(true);
            void Promise.resolve(play()).catch(() => undefined);
        };

        let adopted: HTMLVideoElement | null = null;
        if (warmKey) {
            adopted = claimTrendWarmVideo(warmKey);
        }

        const fallback = fallbackVideoRef.current;
        let el: HTMLVideoElement | null = null;

        if (adopted) {
            if (fallback) fallback.style.display = 'none';

            adopted.className = `h-full w-full ${fitClass}`;
            adopted.style.cssText = '';
            adopted.controls = false;
            adopted.removeAttribute('controls');
            adopted.playsInline = true;
            adopted.loop = loop;
            if (poster) adopted.poster = poster;
            if (adopted.srcObject) adopted.srcObject = null;
            try {
                adopted.currentTime = 0;
            } catch {
                /* ignore */
            }

            mount.appendChild(adopted);
            adoptedRef.current = adopted;
            el = adopted;
        } else {
            adoptedRef.current = null;
            if (!fallback) return;
            fallback.style.display = '';
            fallback.className = `h-full w-full ${fitClass}`;
            el = fallback;
        }

        playerRef.current = new Plyr(el, {
            controls: [...PLYR_CONTROLS],
            settings: ['speed'],
            speed: { selected: 1, options: [0.5, 0.75, 1, 1.25, 1.5, 2] },
            hideControls: true,
            resetOnEnd: false,
            loop: { active: loop },
            keyboard: { focused: true, global: false },
            tooltips: { controls: true, seek: true },
            autopause: true,
            storage: { enabled: false },
            muted: shouldAutoplay && !userPlay,
            autoplay: false,
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
            if (previewDoneRef.current || userPlay) {
                player.muted = false;
            }
        };

        player.on('timeupdate', onTimeUpdate);
        player.on('play', onPlay);

        kickPlayback(
            () => player.play(),
            (muted) => {
                player.muted = muted;
            },
        );

        return () => {
            player.off('timeupdate', onTimeUpdate);
            player.off('play', onPlay);

            const borrowed = adoptedRef.current;
            adoptedRef.current = null;

            try {
                player.destroy();
            } catch {
                /* Plyr teardown can throw if the element was already moved */
            }
            playerRef.current = null;

            if (borrowed) {
                try {
                    borrowed.pause();
                    restoreTrendWarmVideo(borrowed);
                } catch {
                    /* ignore */
                }
            }
            if (fallback) {
                fallback.style.display = '';
            }
        };
    }, [src, autoPlay, userPlay, loop, teaserSeconds, shouldAutoplay, fitClass, warmKey, poster]);

    return (
        <div ref={mountRef} className={`lab-plyr h-full w-full overflow-hidden rounded-[5px] bg-black ${className}`}>
            <video
                ref={fallbackVideoRef}
                key={src}
                className={`h-full w-full ${fitClass}`}
                playsInline
                loop={loop}
                poster={poster || undefined}
                preload="metadata"
                muted={shouldAutoplay && !userPlay}
            >
                <source src={src} type="video/mp4" />
            </video>
        </div>
    );
}
