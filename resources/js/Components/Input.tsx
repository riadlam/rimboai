import type { InputHTMLAttributes } from 'react';

type Props = InputHTMLAttributes<HTMLInputElement> & {
    label?: string;
    error?: string;
};

export default function Input({ label, error, className = '', id, ...props }: Props) {
    const inputId = id || props.name;
    return (
        <div>
            {label && (
                <label htmlFor={inputId} className="mb-1.5 block text-sm font-medium text-[#94a3b8]">
                    {label}
                </label>
            )}
            <input
                id={inputId}
                className={`block w-full rounded-xl border border-white/10 bg-black/40 px-4 py-2.5 text-sm text-[#f1f5f9] placeholder:text-[#475569] transition-colors focus:border-[#3b82f6] focus:outline-none focus:ring-2 focus:ring-[#3b82f6]/20 ${className}`}
                {...props}
            />
            {error && <p className="mt-1.5 text-xs text-[#2563eb]">{error}</p>}
        </div>
    );
}
