import Plyr from 'plyr';
import { useEffect, useRef } from 'react';
import 'plyr/dist/plyr.css';

type Props = {
    src: string;
    poster?: string;
    className?: string;
    autoPlay?: boolean;
    /** Muted teaser: play from 0 then pause at this second (user Play continues full clip). */
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
    previewSeconds,
    objectFit = 'contain',
}: Props) {
    const videoRef = useRef<HTMLVideoElement>(null);
    const playerRef = useRef<Plyr | null>(null);
    const previewDoneRef = useRef(false);

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
            keyboard: { focused: true, global: false },
            tooltips: { controls: true, seek: true },
            autopause: true,
            storage: { enabled: false },
            muted: Boolean(previewSeconds) || autoPlay,
            autoplay: Boolean(previewSeconds) || autoPlay,
        });

        const player = playerRef.current;
        const onTimeUpdate = () => {
            if (!previewSeconds || previewDoneRef.current) return;
            if (player.currentTime >= previewSeconds) {
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

        if (previewSeconds || autoPlay) {
            void player.play().catch(() => undefined);
        }

        return () => {
            player.off('timeupdate', onTimeUpdate);
            player.off('play', onPlay);
            player.destroy();
            playerRef.current = null;
        };
    }, [src, autoPlay, previewSeconds]);

    return (
        <div className={`lab-plyr h-full w-full overflow-hidden rounded-[5px] bg-black ${className}`}>
            <video
                ref={videoRef}
                key={src}
                className={`h-full w-full ${objectFit === 'cover' ? 'object-cover' : 'object-contain'}`}
                playsInline
                poster={poster || undefined}
                preload="metadata"
                muted={Boolean(previewSeconds) || autoPlay}
            >
                <source src={src} type="video/mp4" />
            </video>
        </div>
    );
}
