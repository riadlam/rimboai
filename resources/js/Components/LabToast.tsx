import { AnimatePresence, motion } from 'framer-motion';
import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react';

export type LabToastTone = 'error' | 'info' | 'success';

export type LabToastItem = {
    id: string;
    title: string;
    message: string;
    tone: LabToastTone;
};

type LabToastContextValue = {
    push: (toast: Omit<LabToastItem, 'id'> & { id?: string }) => void;
    pushError: (message: string, title?: string) => void;
};

const LabToastContext = createContext<LabToastContextValue | null>(null);

export function useLabToast(): LabToastContextValue {
    const ctx = useContext(LabToastContext);
    if (!ctx) {
        return {
            push: () => {},
            pushError: () => {},
        };
    }
    return ctx;
}

export function LabToastProvider({ children }: { children: ReactNode }) {
    const [items, setItems] = useState<LabToastItem[]>([]);

    const dismiss = useCallback((id: string) => {
        setItems((prev) => prev.filter((t) => t.id !== id));
    }, []);

    const push = useCallback((toast: Omit<LabToastItem, 'id'> & { id?: string }) => {
        const id = toast.id ?? `toast-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
        setItems((prev) => [{ ...toast, id }, ...prev].slice(0, 4));
        window.setTimeout(() => dismiss(id), toast.tone === 'error' ? 10_000 : 5_500);
    }, [dismiss]);

    const pushError = useCallback(
        (message: string, title = 'Generation failed') => {
            const text = (message || '').trim() || 'Something went wrong.';
            push({ title, message: text, tone: 'error' });
        },
        [push],
    );

    const value = useMemo(() => ({ push, pushError }), [push, pushError]);

    return (
        <LabToastContext.Provider value={value}>
            {children}
            <div
                className="pointer-events-none fixed z-[80] flex w-[min(100vw-1.5rem,22rem)] flex-col gap-2 max-sm:bottom-4 max-sm:left-1/2 max-sm:-translate-x-1/2 sm:bottom-5 sm:right-5 sm:left-auto sm:translate-x-0"
                aria-live="polite"
            >
                <AnimatePresence initial={false}>
                    {items.map((toast) => (
                        <motion.div
                            key={toast.id}
                            layout
                            initial={{ opacity: 0, y: 16, scale: 0.98 }}
                            animate={{ opacity: 1, y: 0, scale: 1 }}
                            exit={{ opacity: 0, y: 10, scale: 0.98 }}
                            transition={{ type: 'spring', stiffness: 420, damping: 32 }}
                            className={`pointer-events-auto overflow-hidden rounded-2xl border px-3.5 py-3 shadow-[0_12px_40px_rgba(0,0,0,0.35)] backdrop-blur-md ${
                                toast.tone === 'error'
                                    ? 'border-red-400/25 bg-[#1a1012]/95 text-red-50'
                                    : toast.tone === 'success'
                                      ? 'border-emerald-400/25 bg-[#0f1714]/95 text-emerald-50'
                                      : 'border-white/10 bg-[#121218]/95 text-white'
                            }`}
                        >
                            <div className="flex items-start gap-2.5">
                                <span
                                    className={`mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[13px] font-semibold ${
                                        toast.tone === 'error'
                                            ? 'bg-red-500/20 text-red-200'
                                            : toast.tone === 'success'
                                              ? 'bg-emerald-500/20 text-emerald-200'
                                              : 'bg-white/10 text-white/80'
                                    }`}
                                >
                                    !
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="text-[12px] font-semibold tracking-wide">{toast.title}</p>
                                    <p className="mt-0.5 text-[12px] leading-snug text-white/70">{toast.message}</p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => dismiss(toast.id)}
                                    className="rounded-md px-1.5 py-0.5 text-[11px] text-white/40 transition hover:bg-white/10 hover:text-white/80"
                                    aria-label="Dismiss"
                                >
                                    ✕
                                </button>
                            </div>
                        </motion.div>
                    ))}
                </AnimatePresence>
            </div>
        </LabToastContext.Provider>
    );
}
