import { motion } from 'framer-motion';

/** Soft wash while Reuse Settings / Use Video|Image hydrates the create form. */
export default function LabFormSkeleton({ label = 'Restoring settings…' }: { label?: string }) {
    return (
        <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.2 }}
            className="pointer-events-none absolute inset-0 z-30 flex flex-col"
            aria-busy="true"
            aria-live="polite"
        >
            <div className="absolute inset-0 bg-[#0a0a0f]/35" />

            <div className="relative mt-3 space-y-2.5 px-3">
                <SkeletonBlock className="h-12 w-full rounded-xl opacity-70" />
                <SkeletonBlock className="h-20 w-full rounded-xl opacity-55" />
                <div className="flex gap-2">
                    <SkeletonBlock className="h-8 flex-1 rounded-lg opacity-45" />
                    <SkeletonBlock className="h-8 flex-1 rounded-lg opacity-45" />
                    <SkeletonBlock className="h-8 flex-1 rounded-lg opacity-45" />
                </div>
            </div>

            <div className="relative mt-auto flex items-center justify-center gap-2 px-3 py-4">
                <span className="h-1 w-1 rounded-full bg-white/35" />
                <p className="text-[11px] tracking-wide text-white/40">{label}</p>
            </div>
        </motion.div>
    );
}

function SkeletonBlock({ className = '' }: { className?: string }) {
    return (
        <div className={`relative overflow-hidden bg-white/[0.04] ${className}`}>
            <div className="absolute inset-0 -translate-x-full animate-[labSkeletonShimmer_2.2s_ease-in-out_infinite] bg-gradient-to-r from-transparent via-white/[0.05] to-transparent" />
        </div>
    );
}
