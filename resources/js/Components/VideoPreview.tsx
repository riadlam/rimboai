import { useRef } from 'react';

type Props = {
    src: string;
    poster?: string;
    className?: string;
    autoPlay?: boolean;
};

export default function VideoPreview({ src, poster, className = '', autoPlay = true }: Props) {
    const ref = useRef<HTMLVideoElement>(null);

    return (
        <video
            ref={ref}
            src={src}
            poster={poster}
            className={`size-full rounded-2xl object-cover ${className}`}
            playsInline
            loop
            muted
            autoPlay={autoPlay}
            controls
            preload="metadata"
        />
    );
}
