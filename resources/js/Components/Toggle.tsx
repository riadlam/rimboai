import { motion } from 'framer-motion';

type Props = {
    checked: boolean;
    onChange: (checked: boolean) => void;
    label?: string;
    description?: string;
};

export default function Toggle({ checked, onChange, label, description }: Props) {
    return (
        <label className="flex cursor-pointer items-center justify-between gap-4 py-2">
            {(label || description) && (
                <div className="flex-1">
                    {label && (
                        <p className="text-sm font-medium text-text-primary dark:text-[var(--dark-text-primary)]">
                            {label}
                        </p>
                    )}
                    {description && (
                        <p className="text-xs text-text-tertiary dark:text-[var(--dark-text-tertiary)]">
                            {description}
                        </p>
                    )}
                </div>
            )}
            {/* Force LTR so the knob never mirrors oddly in Arabic RTL */}
            <button
                type="button"
                role="switch"
                dir="ltr"
                aria-checked={checked}
                onClick={() => onChange(!checked)}
                className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors duration-200 ${
                    checked ? 'bg-brand-500' : 'bg-surface-tertiary dark:bg-[var(--dark-surface-tertiary)]'
                }`}
            >
                <motion.span
                    layout={false}
                    className="absolute top-0.5 left-0.5 inline-block size-5 rounded-full bg-white shadow"
                    animate={{ x: checked ? 20 : 0 }}
                    transition={{ type: 'spring', stiffness: 500, damping: 35 }}
                />
            </button>
        </label>
    );
}
