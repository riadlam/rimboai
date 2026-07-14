type Props = {
    progress?: number;
    label?: string;
};

export default function Loader({ progress = 0, label = 'Generating…' }: Props) {
    return (
        <div className="flex w-full flex-col items-center gap-4">
            <div className="relative flex h-16 w-16 items-center justify-center">
                <div className="absolute inset-0 rounded-full border-2 border-brand-500/20" />
                <div className="absolute inset-0 animate-spin rounded-full border-2 border-transparent border-t-brand-500" />
                <span className="text-xs font-semibold text-brand-400">{Math.round(progress)}%</span>
            </div>
            <p className="text-sm text-text-secondary dark:text-[var(--dark-text-secondary)]">{label}</p>
            <div className="h-1.5 w-full max-w-xs overflow-hidden rounded-full bg-surface-tertiary dark:bg-[var(--dark-surface-tertiary)]">
                <div
                    className="h-full rounded-full creative-gradient transition-all duration-300"
                    style={{ width: `${Math.min(100, progress)}%` }}
                />
            </div>
        </div>
    );
}
