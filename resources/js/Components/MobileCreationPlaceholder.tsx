type Kind = 'image' | 'video' | 'audio' | 'music';

/**
 * Phone Lab/History tiles: no img/video network load until the user opens the item.
 */
export default function MobileCreationPlaceholder({ kind = 'image' }: { kind?: Kind }) {
    return (
        <div
            aria-hidden
            className="absolute inset-0 flex items-center justify-center bg-gradient-to-br from-[#1a1a24] via-[#121218] to-[#0c0c10]"
        >
            <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_30%_20%,rgba(255,87,51,0.12),transparent_55%)]" />
            <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_80%_90%,rgba(139,92,246,0.1),transparent_50%)]" />
            <span className="relative flex h-11 w-11 items-center justify-center rounded-2xl border border-white/[0.08] bg-white/[0.04] text-white/45">
                {kind === 'video' ? (
                    <svg className="h-5 w-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden>
                        <path d="M8 5.14v13.72a1 1 0 0 0 1.5.86l11-6.86a1 1 0 0 0 0-1.72l-11-6.86a1 1 0 0 0-1.5.86Z" />
                    </svg>
                ) : kind === 'audio' || kind === 'music' ? (
                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" aria-hidden>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M9 18V6l10-2v12" />
                        <circle cx="7" cy="18" r="2.5" />
                        <circle cx="17" cy="16" r="2.5" />
                    </svg>
                ) : (
                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" aria-hidden>
                        <rect x="4" y="5" width="16" height="14" rx="2" />
                        <circle cx="9" cy="10" r="1.5" />
                        <path strokeLinecap="round" d="m4 16 5-4 4 3 3-2 4 3" />
                    </svg>
                )}
            </span>
        </div>
    );
}
