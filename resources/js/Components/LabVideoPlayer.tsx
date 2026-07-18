import Plyr from 'plyr';
import { useEffect, useRef } from 'react';
import 'plyr/dist/plyr.css';

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
};

/**
 * Branded Plyr player for Lab / Trends asset previews.
 */
export default function LabVideoPlayer({
    src,
    poster,
    className = '',
    autoPlay = false,
    loop = false,
    previewSeconds,
    objectFit = 'contain',
}: Props) {
    const videoRef = useRef<HTMLVideoElement>(null);
    const playerRef = useRef<Plyr | null>(null);
    const previewDoneRef = useRef(false);

    const teaserSeconds = loop ? undefined : previewSeconds;
    const shouldAutoplay = Boolean(teaserSeconds) || autoPlay || loop;

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
    }, [src, autoPlay, loop, teaserSeconds, shouldAutoplay]);

    return (
        <div className={`lab-plyr h-full w-full overflow-hidden rounded-[5px] bg-black ${className}`}>
            <video
                ref={videoRef}
                key={src}
                className={`h-full w-full ${objectFit === 'cover' ? 'object-cover' : 'object-contain'}`}
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
