import { Link } from '@inertiajs/react';
import { motion } from 'framer-motion';
import type { ReactNode } from 'react';

export default function AuthLayout({
    children,
    title,
    subtitle,
}: {
    children: ReactNode;
    title: string;
    subtitle: string;
}) {
    return (
        <div className="relative flex min-h-screen items-center justify-center bg-[#0d0d12] px-4 py-12 font-sans text-[#f1f5f9] antialiased">
            <div className="pointer-events-none fixed inset-0 overflow-hidden">
                <motion.div
                    className="absolute -left-32 -top-32 size-96 rounded-full bg-[#3b82f6]/10 blur-3xl"
                    animate={{ opacity: [0.5, 0.85, 0.5], scale: [1, 1.05, 1] }}
                    transition={{ duration: 8, repeat: Infinity, ease: 'easeInOut' }}
                />
                <motion.div
                    className="absolute -bottom-32 -right-32 size-96 rounded-full bg-[#2563eb]/10 blur-3xl"
                    animate={{ opacity: [0.4, 0.75, 0.4], scale: [1.05, 1, 1.05] }}
                    transition={{ duration: 9, repeat: Infinity, ease: 'easeInOut' }}
                />
            </div>

            <motion.div
                className="relative w-full max-w-md"
                initial={{ opacity: 0, y: 16 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.4, ease: [0.22, 1, 0.36, 1] }}
            >
                <div className="mb-8 text-center">
                    <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-[#3b82f6] to-[#2563eb] shadow-lg shadow-blue-500/25">
                        <svg className="h-7 w-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                        </svg>
                    </div>
                    <h1 className="text-2xl font-bold tracking-tight">{title}</h1>
                    <p className="mt-1.5 text-sm text-[#94a3b8]">{subtitle}</p>
                </div>

                <div className="rounded-2xl border border-white/10 bg-[#111827] p-8 shadow-xl">{children}</div>

                <div className="mt-4 text-center">
                    <Link
                        href="/"
                        className="inline-flex items-center gap-1 text-sm text-[#475569] transition-colors hover:text-[#94a3b8]"
                    >
                        <svg className="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                        </svg>
                        Back to home
                    </Link>
                </div>
            </motion.div>
        </div>
    );
}
