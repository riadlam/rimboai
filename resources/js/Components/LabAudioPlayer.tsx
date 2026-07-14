import Plyr from 'plyr';
import { useEffect, useRef } from 'react';
import 'plyr/dist/plyr.css';

export const MUSIC_EQ_BAR_COUNT = 36;

type Props = {
    src: string;
    className?: string;
    autoPlay?: boolean;
    /** dock = full-width bar under the album stage (video-style) */
    variant?: 'default' | 'dock';
    onPlayingChange?: (playing: boolean) => void;
    /** Real-time frequency levels 0..1 (one per bar), driven by Web Audio AnalyserNode */
    onLevels?: (levels: number[]) => void;
};

/**
 * Branded Plyr audio player — seek scrubber, ±10s, volume, speed.
 * Optionally streams live frequency levels for a real equalizer.
 */
export default function LabAudioPlayer({
    src,
    className = '',
    autoPlay = false,
    variant = 'default',
    onPlayingChange,
    onLevels,
}: Props) {
    const audioRef = useRef<HTMLAudioElement>(null);
    const playerRef = useRef<Plyr | null>(null);
    const onPlayingChangeRef = useRef(onPlayingChange);
    const onLevelsRef = useRef(onLevels);
    onPlayingChangeRef.current = onPlayingChange;
    onLevelsRef.current = onLevels;

    useEffect(() => {
        const el = audioRef.current;
        if (!el) return;

        playerRef.current?.destroy();
        const player = new Plyr(el, {
            controls: [
                'play',
                'progress',
                'current-time',
                'duration',
                'mute',
                'volume',
                'settings',
            ],
            settings: ['speed'],
            speed: { selected: 1, options: [0.75, 1, 1.25, 1.5] },
            seekTime: 10,
            keyboard: { focused: true, global: false },
            tooltips: { controls: true, seek: true },
            storage: { enabled: false },
            listeners: {
                seek: true,
            },
        });
        playerRef.current = player;

        const onPlay = () => onPlayingChangeRef.current?.(true);
        const onPause = () => onPlayingChangeRef.current?.(false);
        const onEnded = () => onPlayingChangeRef.current?.(false);
        player.on('play', onPlay);
        player.on('pause', onPause);
        player.on('ended', onEnded);

        // Real spectrum from the playing audio
        const AC = window.AudioContext || (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext;
        let ctx: AudioContext | null = null;
        let source: MediaElementAudioSourceNode | null = null;
        let analyser: AnalyserNode | null = null;
        let raf = 0;
        const smoothed = new Array(MUSIC_EQ_BAR_COUNT).fill(0);

        try {
            ctx = new AC();
            source = ctx.createMediaElementSource(el);
            analyser = ctx.createAnalyser();
            analyser.fftSize = 512;
            analyser.smoothingTimeConstant = 0.55;
            analyser.minDecibels = -85;
            analyser.maxDecibels = -18;
            source.connect(analyser);
            analyser.connect(ctx.destination);
        } catch {
            // CORS / unsupported — player still works without live EQ
            ctx = null;
            analyser = null;
        }

        const data = analyser ? new Uint8Array(analyser.frequencyBinCount) : null;

        const resumeCtx = () => {
            if (ctx && ctx.state === 'suspended') void ctx.resume();
        };
        el.addEventListener('play', resumeCtx);

        const tick = () => {
            raf = requestAnimationFrame(tick);
            if (!analyser || !data || !onLevelsRef.current) return;

            analyser.getByteFrequencyData(data);
            const playing = !el.paused && !el.ended;
            const levels = new Array(MUSIC_EQ_BAR_COUNT);

            // Log-ish bands across audible spectrum (skip DC / ultra-high)
            const usable = Math.floor(data.length * 0.72);
            const start = 2;

            for (let i = 0; i < MUSIC_EQ_BAR_COUNT; i++) {
                const t0 = i / MUSIC_EQ_BAR_COUNT;
                const t1 = (i + 1) / MUSIC_EQ_BAR_COUNT;
                const i0 = start + Math.floor(Math.pow(t0, 1.55) * usable);
                const i1 = start + Math.floor(Math.pow(t1, 1.55) * usable);
                const from = Math.min(i0, data.length - 1);
                const to = Math.max(from + 1, Math.min(i1, data.length));

                let sum = 0;
                for (let j = from; j < to; j++) sum += data[j];
                const raw = playing ? Math.min(1, (sum / (to - from) / 255) * 1.35) : 0;

                // Fast attack, smooth decay so bars feel musical
                const prev = smoothed[i];
                smoothed[i] = raw > prev ? prev * 0.35 + raw * 0.65 : prev * 0.82 + raw * 0.18;
                levels[i] = smoothed[i];
            }

            onLevelsRef.current(levels);
        };
        raf = requestAnimationFrame(tick);

        if (autoPlay) {
            resumeCtx();
            void player.play().catch(() => undefined);
        }

        return () => {
            cancelAnimationFrame(raf);
            el.removeEventListener('play', resumeCtx);
            player.off('play', onPlay);
            player.off('pause', onPause);
            player.off('ended', onEnded);
            player.destroy();
            playerRef.current = null;
            try {
                source?.disconnect();
                analyser?.disconnect();
            } catch {
                /* ignore */
            }
            if (ctx) void ctx.close();
            onLevelsRef.current?.(new Array(MUSIC_EQ_BAR_COUNT).fill(0));
        };
    }, [src, autoPlay]);

    const skip = (delta: number) => {
        const player = playerRef.current;
        if (!player || !Number.isFinite(player.currentTime)) return;
        const duration = Number.isFinite(player.duration) ? player.duration : Infinity;
        player.currentTime = Math.max(0, Math.min(duration, player.currentTime + delta));
    };

    return (
        <div className={`lab-plyr lab-plyr--audio ${variant === 'dock' ? 'lab-plyr--dock' : ''} ${className}`}>
            <div className={`mb-2 flex items-center justify-center gap-2 ${variant === 'dock' ? 'mb-3' : ''}`}>
                <button
                    type="button"
                    onClick={() => skip(-10)}
                    className="inline-flex h-9 items-center gap-1.5 rounded-xl border border-white/10 bg-black px-3 text-[12px] font-medium text-white/70 transition hover:border-orange-400/35 hover:bg-[#FF5733]/10 hover:text-orange-100"
                    title="Back 10 seconds"
                >
                    <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                        <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" />
                        <path d="M3 3v5h5" />
                    </svg>
                    −10s
                </button>
                <button
                    type="button"
                    onClick={() => skip(10)}
                    className="inline-flex h-9 items-center gap-1.5 rounded-xl border border-white/10 bg-black px-3 text-[12px] font-medium text-white/70 transition hover:border-orange-400/35 hover:bg-[#FF5733]/10 hover:text-orange-100"
                    title="Forward 10 seconds"
                >
                    +10s
                    <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                        <path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8" />
                        <path d="M21 3v5h-5" />
                    </svg>
                </button>
            </div>
            <audio ref={audioRef} preload="metadata" crossOrigin="anonymous">
                <source src={src} />
            </audio>
        </div>
    );
}
