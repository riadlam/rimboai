import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useRef, useState, type ReactNode } from 'react';

type Props = {
    trigger: ReactNode;
    children: ReactNode;
    align?: 'left' | 'right';
    className?: string;
};

export default function Dropdown({ trigger, children, align = 'left', className = '' }: Props) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const onClick = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setOpen(false);
        };
        document.addEventListener('mousedown', onClick);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onClick);
            document.removeEventListener('keydown', onKey);
        };
    }, []);

    return (
        <div ref={ref} className={`relative ${className}`}>
            <div onClick={() => setOpen((v) => !v)}>{trigger}</div>
            <AnimatePresence>
                {open && (
                    <motion.div
                        initial={{ opacity: 0, y: 6 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0, y: 4 }}
                        transition={{ duration: open ? 0.2 : 0.15, ease: 'easeOut' }}
                        className={`absolute z-50 mt-2 min-w-[12rem] overflow-hidden rounded-xl border border-border/60 dark:border-[var(--dark-border)] bg-surface dark:bg-[var(--dark-surface)] py-1.5 shadow-xl shadow-black/5 dark:shadow-black/30 ${
                            align === 'right' ? 'right-0' : 'left-0'
                        }`}
                    >
                        <div onClick={() => setOpen(false)}>{children}</div>
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    );
}
