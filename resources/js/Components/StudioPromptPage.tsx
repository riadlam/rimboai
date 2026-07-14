import { Link } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useMemo, useState, type FormEvent, type ReactNode } from 'react';
import type { Brand } from '@/types';
import Button from '@/Components/Button';

type Props = {
    title: string;
    description: string;
    placeholder: string;
    brands?: Brand[];
    homeHref?: string;
    labHref?: string;
    showUpload?: boolean;
    children?: ReactNode;
    onClose?: () => void;
};

export default function StudioPromptPage({
    title,
    description,
    placeholder,
    brands = [],
    homeHref = '/',
    labHref = '/lab',
    showUpload = true,
    onClose,
}: Props) {
    const [prompt, setPrompt] = useState('');
    const [modelOpen, setModelOpen] = useState(false);
    const [selectedBrand, setSelectedBrand] = useState(brands[0]?.name || 'Auto');
    const [selectedModel, setSelectedModel] = useState(brands[0]?.models[0]?.name || 'Default');
    const [loading, setLoading] = useState(false);

    const currentBrand = useMemo(
        () => brands.find((b) => b.name === selectedBrand) || brands[0],
        [brands, selectedBrand],
    );

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        if (!prompt.trim()) return;
        setLoading(true);
        setTimeout(() => setLoading(false), 2200);
    };

    return (
        <div className="relative flex min-h-[calc(100dvh-2.5rem)] w-full items-center justify-center py-8">
            {onClose ? (
                <button
                    type="button"
                    onClick={onClose}
                    className="absolute right-0 top-0 z-10 flex size-10 items-center justify-center rounded-full bg-white/10 text-white/70 transition-colors hover:bg-white/20 hover:text-white"
                >
                    <svg className="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            ) : (
                <div className="absolute right-0 top-0 z-10 flex items-center gap-2">
                    <Link
                        href={labHref}
                        className="flex h-10 items-center rounded-full bg-white/10 px-3 text-xs font-medium text-white/70 transition-colors hover:bg-white/20 hover:text-white"
                    >
                        Open Lab
                    </Link>
                    <Link
                        href={homeHref}
                        className="flex size-10 items-center justify-center rounded-full bg-white/10 text-white/70 transition-colors hover:bg-white/20 hover:text-white"
                    >
                        <svg className="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </Link>
                </div>
            )}

            <motion.div
                className="w-full max-w-[1000px]"
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.4, ease: [0.22, 1, 0.36, 1] }}
            >
                <h1 className="text-center text-2xl font-bold text-white md:text-3xl lg:text-4xl">{title}</h1>
                <p className="mx-auto mt-3 max-w-[700px] text-center text-sm leading-relaxed text-white/60 md:text-base">
                    {description}
                </p>

                <div className="mx-auto mt-8 w-full">
                    <div className="group relative mx-auto max-w-full rounded-2xl md:rounded-3xl">
                        <div className="conic-border relative h-full rounded-2xl bg-[#1F1F26AD] p-3 md:rounded-3xl md:p-4">
                            <form onSubmit={handleSubmit} className="relative flex h-full flex-col">
                                <div className="flex flex-col items-start gap-2 md:flex-row md:gap-3">
                                    {showUpload && (
                                        <div
                                            className="group/upload-card relative flex cursor-pointer items-center justify-center rounded border border-dashed border-white/20 bg-white/[0.06] transition-transform duration-500 hover:z-[2] hover:scale-125"
                                            style={{ width: 45, height: 60, transform: 'rotate(-5deg)' }}
                                        >
                                            <svg className="size-4 text-white/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                            </svg>
                                        </div>
                                    )}
                                    <textarea
                                        value={prompt}
                                        onChange={(e) => setPrompt(e.target.value)}
                                        placeholder={placeholder}
                                        className="scrollbar-thin h-[108px] w-full resize-none bg-transparent text-sm leading-relaxed text-white outline-none placeholder:text-white/30"
                                    />
                                </div>

                                <div className="flex items-center justify-between gap-2 pt-2 md:gap-4">
                                    <div className="relative flex h-9 flex-1 items-center gap-1">
                                        <button
                                            type="button"
                                            onClick={() => setModelOpen((v) => !v)}
                                            className="flex h-9 cursor-pointer items-center gap-1.5 rounded-lg bg-white/[0.06] px-3 text-sm text-white/70"
                                        >
                                            <span className="flex h-4 w-4 items-center justify-center rounded-full bg-white/[0.08] text-[9px] font-bold text-white/40">
                                                {selectedBrand.charAt(0).toUpperCase()}
                                            </span>
                                            <span className="text-xs md:text-sm">{selectedModel}</span>
                                            <svg className="size-4 text-white/50" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                            </svg>
                                        </button>

                                        <AnimatePresence>
                                            {modelOpen && brands.length > 0 && (
                                                <motion.div
                                                    initial={{ opacity: 0, y: 8 }}
                                                    animate={{ opacity: 1, y: 0 }}
                                                    exit={{ opacity: 0, y: 4 }}
                                                    transition={{ duration: 0.2 }}
                                                    className="absolute bottom-11 left-0 z-50 flex max-h-72 overflow-hidden rounded-xl border border-white/10 bg-[#1F1F26] shadow-2xl"
                                                >
                                                    <div className="w-40 overflow-y-auto border-r border-white/10 py-1">
                                                        {brands.map((brand) => (
                                                            <button
                                                                key={brand.name}
                                                                type="button"
                                                                onClick={() => {
                                                                    setSelectedBrand(brand.name);
                                                                    if (brand.models[0]) setSelectedModel(brand.models[0].name);
                                                                }}
                                                                className={`flex w-full cursor-pointer items-center gap-2 px-3 py-2 text-sm text-white/70 transition-colors hover:bg-white/[0.06] ${
                                                                    selectedBrand === brand.name ? 'bg-white/[0.06]' : ''
                                                                }`}
                                                            >
                                                                <div className="flex size-6 shrink-0 items-center justify-center rounded-md bg-white/[0.08] text-[10px] font-bold">
                                                                    {brand.name.charAt(0)}
                                                                </div>
                                                                <span className="truncate">{brand.name}</span>
                                                            </button>
                                                        ))}
                                                    </div>
                                                    <div className="w-56 overflow-y-auto py-1">
                                                        {(currentBrand?.models || []).map((model) => (
                                                            <button
                                                                key={model.name}
                                                                type="button"
                                                                onClick={() => {
                                                                    setSelectedModel(model.name);
                                                                    setModelOpen(false);
                                                                }}
                                                                className={`w-full cursor-pointer px-3 py-2 text-left text-sm text-white/70 transition-colors hover:bg-white/[0.06] ${
                                                                    selectedModel === model.name ? 'bg-white/[0.06]' : ''
                                                                }`}
                                                            >
                                                                {model.name}
                                                            </button>
                                                        ))}
                                                    </div>
                                                </motion.div>
                                            )}
                                        </AnimatePresence>
                                    </div>

                                    <Button
                                        type="submit"
                                        loading={loading}
                                        className="!rounded-full !bg-white !px-5 !py-2 !text-sm !font-semibold !text-black hover:!bg-white/90"
                                    >
                                        Generate
                                    </Button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </motion.div>
        </div>
    );
}
