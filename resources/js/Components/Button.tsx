import { motion } from 'framer-motion';
import type { ReactNode } from 'react';

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'creative' | 'auth';
type Size = 'sm' | 'md' | 'lg';

type Props = {
    variant?: Variant;
    size?: Size;
    loading?: boolean;
    children: ReactNode;
    className?: string;
    type?: 'button' | 'submit' | 'reset';
    disabled?: boolean;
    onClick?: () => void;
};

const variants: Record<Variant, string> = {
    primary: 'bg-brand-600 text-white hover:bg-brand-700 shadow-sm',
    secondary:
        'bg-surface dark:bg-[var(--dark-surface-secondary)] text-text-primary dark:text-[var(--dark-text-primary)] border border-border dark:border-[var(--dark-border)] hover:bg-surface-tertiary dark:hover:bg-[var(--dark-surface-tertiary)]',
    ghost: 'text-text-secondary dark:text-[var(--dark-text-secondary)] hover:bg-surface-tertiary dark:hover:bg-[var(--dark-surface-tertiary)]',
    danger: 'bg-red-600 text-white hover:bg-red-700 shadow-sm',
    creative: 'creative-gradient text-white shadow-lg shadow-purple-500/25',
    auth: 'bg-gradient-to-b from-[#3b82f6] to-[#2563eb] text-white shadow-lg shadow-blue-500/25 hover:shadow-blue-500/40',
};

const sizes: Record<Size, string> = {
    sm: 'px-3 py-1.5 text-sm',
    md: 'px-4 py-2.5 text-sm',
    lg: 'px-6 py-3 text-base',
};

export default function Button({
    variant = 'primary',
    size = 'md',
    loading = false,
    disabled,
    children,
    className = '',
    type = 'button',
    onClick,
}: Props) {
    return (
        <motion.button
            type={type}
            whileTap={{ scale: 0.98 }}
            disabled={disabled || loading}
            onClick={onClick}
            className={`inline-flex items-center justify-center gap-2 rounded-xl font-medium transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-brand-500/50 disabled:cursor-not-allowed disabled:opacity-50 ${variants[variant]} ${sizes[size]} ${className}`}
        >
            {loading && (
                <svg className="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                    <path
                        className="opacity-75"
                        fill="currentColor"
                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                    />
                </svg>
            )}
            {children}
        </motion.button>
    );
}
