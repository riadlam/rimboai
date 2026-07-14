import Plyr from 'plyr';
import { useEffect, useRef } from 'react';
import 'plyr/dist/plyr.css';

type Props = {
    src: string;
    poster?: string;
    className?: string;
    autoPlay?: boolean;
};

/**
 * Branded Plyr player for Lab asset previews.
 */
export default function LabVideoPlayer({ src, poster, className = '', autoPlay = false }: Props) {
    const videoRef = useRef<HTMLVideoElement>(null);
    const playerRef = useRef<Plyr | null>(null);

    useEffect(() => {
        const el = videoRef.current;
        if (!el) return;

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
        });

        if (autoPlay) {
            void playerRef.current.play().catch(() => undefined);
        }

        return () => {
            playerRef.current?.destroy();
            playerRef.current = null;
        };
    }, [src, autoPlay]);

    return (
        <div className={`lab-plyr h-full w-full overflow-hidden rounded-[5px] bg-black ${className}`}>
            <video
                ref={videoRef}
                key={src}
                className="h-full w-full"
                playsInline
                poster={poster || undefined}
                preload="metadata"
            >
                <source src={src} type="video/mp4" />
            </video>
        </div>
    );
}
