import Plyr from 'plyr';
import { useEffect, useRef } from 'react';
import { claimTrendWarmVideo, restoreTrendWarmVideo } from '@/lib/trendWarmVideo';
import 'plyr/dist/plyr.css';

type Props = {
    src: string;
    poster?: string;
    className?: string;
    autoPlay?: boolean;
    /** When true, clip loops until the user pauses. */
    loop?: boolean;
    /** Muted teaser: play from 0 then pause at this second (false Play continues full clip). Ignored when `loop` is set. */
    previewSeconds?: number;
    objectFit?: 'cover' | 'contain';
    /**
     * When set, prefer adopting an already-buffered card <video> registered
     * under this key (instant open — no network reload).
     */
    warmKey?: string;
};

/**
 * Branded Plyr player for Lab / Trends asset previews.
 * Warm-card handoff uses native <video> (no Plyr) so destroy/restore stays clean.
 */
export default function LabVideoPlayer({
    src,
    poster,
    className = '',
    autoPlay = false,
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

        let adopted: HTMLVideoElement | null = null;
        if (warmKey) {
            adopted = claimTrendWarmVideo(warmKey);
        }

        // ---- Warm handoff: native video only (Plyr breaks reparent/restore) ----
        if (adopted) {
            const fallback = fallbackVideoRef.current;
            if (fallback) {
                fallback.style.display = 'none';
            }

            adopted.className = `h-full w-full ${fitClass}`;
            adopted.style.cssText = '';
            if (poster) adopted.poster = poster;
            adopted.controls = true;
            adopted.playsInline = true;
            adopted.loop = loop;
            adopted.muted = shouldAutoplay;
            adopted.defaultMuted = shouldAutoplay;
            if (adopted.srcObject) adopted.srcObject = null;

            mount.appendChild(adopted);
            adoptedRef.current = adopted;

            if (shouldAutoplay) {
                void adopted.play().catch(() => undefined);
            }

            return () => {
                const borrowed = adoptedRef.current;
                adoptedRef.current = null;
                if (borrowed) {
                    restoreTrendWarmVideo(borrowed);
                }
                if (fallback) {
                    fallback.style.display = '';
                }
            };
        }

        // ---- Cold path: normal Plyr ----
        adoptedRef.current = null;
        const el = fallbackVideoRef.current;
        if (!el) return;

        el.style.display = '';
        el.className = `h-full w-full ${fitClass}`;

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
    }, [src, autoPlay, loop, teaserSeconds, shouldAutoplay, fitClass, warmKey, poster]);

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
                muted={shouldAutoplay}
            >
                <source src={src} type="video/mp4" />
            </video>
        </div>
    );
}
