import { AnimatePresence, motion } from 'framer-motion';
import { useTranslation } from 'react-i18next';
import type { BrandModel } from '@/types';
import { publicAsset } from '@/lib/publicAsset';

export type LabPickerModel = BrandModel & {
    brandName: string;
    brandIcon?: string | null;
};

export function isBrandIconUrl(url?: string | null): boolean {
    if (!url) return false;
    const lower = url.toLowerCase();
    return lower.endsWith('.svg') || lower.includes('/ai_icons/') || lower.startsWith('/storage/ai_icons/');
}

export function resolveModelMedia(model: {
    icon?: string | null;
    image_cover?: string | null;
}): { icon: string | null; cover: string | null; iconIsBrandMark: boolean } {
    const icon = publicAsset(model.icon || model.image_cover || null);
    const coverCandidate = publicAsset(model.image_cover || model.icon || null);
    const iconIsBrandMark = isBrandIconUrl(icon);
    const cover = coverCandidate && !isBrandIconUrl(coverCandidate) ? coverCandidate : null;
    return { icon, cover, iconIsBrandMark };
}

type TriggerProps = {
    modelName: string;
    icon?: string | null;
    imageCover?: string | null;
    onClick: () => void;
};

export function LabModelPickerTrigger({ modelName, icon, imageCover, onClick }: TriggerProps) {
    const media = resolveModelMedia({ icon, image_cover: imageCover });

    return (
        <button
            type="button"
            onClick={onClick}
            className="group relative inline-flex h-9 max-w-[220px] shrink-0 cursor-pointer items-center gap-2 overflow-hidden rounded-xl border border-white/10 ps-1.5 pe-2.5 text-xs font-medium text-white shadow-[0_8px_24px_rgba(0,0,0,0.35)] transition hover:border-orange-400/40"
        >
            <span
                className="pointer-events-none absolute inset-0 bg-cover bg-center transition duration-300 group-hover:scale-105"
                style={{
                    backgroundImage: media.cover ? `url(${media.cover})` : undefined,
                    backgroundColor: '#0a0a0a',
                }}
            />
            <span className="pointer-events-none absolute inset-0 bg-gradient-to-r from-black/90 via-black/75 to-black/55" />
            <span
                className={`relative flex h-6 w-6 shrink-0 items-center justify-center overflow-hidden rounded-lg ring-1 ring-white/15 ${
                    media.iconIsBrandMark ? 'bg-white/95 p-0.5' : 'bg-black'
                }`}
            >
                {media.icon ? (
                    <img
                        src={media.icon}
                        alt=""
                        className={`h-full w-full ${media.iconIsBrandMark ? 'object-contain' : 'object-cover'}`}
                    />
                ) : (
                    <span className="text-[10px] font-bold text-orange-100">{modelName[0]}</span>
                )}
            </span>
            <span className="relative max-w-[130px] truncate text-[12px] font-semibold tracking-tight">{modelName}</span>
            <svg className="relative h-3.5 w-3.5 shrink-0 text-white/50" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                <path d="m6 9 6 6 6-6" />
            </svg>
        </button>
    );
}

type ModalProps = {
    open: boolean;
    models: LabPickerModel[];
    selectedName: string;
    onSelect: (model: LabPickerModel) => void;
    onClose: () => void;
    title?: string;
    subtitle?: string;
    fallbackDescription?: string;
};

export function LabModelPickerModal({
    open,
    models,
    selectedName,
    onSelect,
    onClose,
    title,
    subtitle,
    fallbackDescription,
}: ModalProps) {
    const { t } = useTranslation('lab');
    const modalTitle = title ?? t('selectModel');
    const modalSubtitle = subtitle ?? t('selectModelSub');
    const modalFallback = fallbackDescription ?? t('selectModelSub');

    return (
        <AnimatePresence>
            {open && (
                <ModalShell onClose={onClose} wide>
                    <div className="pe-8">
                        <h2 className="text-lg font-semibold tracking-tight text-white">{modalTitle}</h2>
                        <p className="mt-1 text-[13px] text-white/45">{modalSubtitle}</p>
                    </div>
                    <div className="mt-4 max-h-[62vh] space-y-3 overflow-y-auto pe-1 scrollbar-thin">
                        {models.map((m) => {
                            const active = selectedName === m.name;
                            const tags = Array.isArray(m.tags) ? m.tags.filter(Boolean) : [];
                            const media = resolveModelMedia({
                                icon: m.icon,
                                image_cover: m.image_cover,
                            });
                            return (
                                <button
                                    key={`${m.brandName}-${m.name}-${m.endpoint_id || ''}`}
                                    type="button"
                                    onClick={() => onSelect(m)}
                                    className={`group relative flex w-full cursor-pointer overflow-hidden rounded-2xl border text-start transition ${
                                        active
                                            ? 'border-[#FF5733]/55 shadow-[0_12px_40px_rgba(255,87,51,0.22)]'
                                            : 'border-white/10 hover:border-white/25'
                                    }`}
                                >
                                    <span
                                        className="absolute inset-0 bg-cover bg-center transition duration-500 group-hover:scale-105"
                                        style={{
                                            backgroundImage: media.cover ? `url(${media.cover})` : undefined,
                                            backgroundColor: '#0a0a0a',
                                        }}
                                    />
                                    <span className="absolute inset-0 bg-gradient-to-r from-black via-black/88 to-black/35" />
                                    <span className="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-black/20" />
                                    {active && (
                                        <span className="absolute inset-0 bg-gradient-to-r from-[#FF5733]/25 via-transparent to-transparent" />
                                    )}

                                    <div className="relative flex w-full items-center gap-3.5 p-3.5 sm:p-4">
                                        <div
                                            className={`relative flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl shadow-lg ring-1 ring-white/15 sm:h-[4.5rem] sm:w-[4.5rem] ${
                                                media.iconIsBrandMark ? 'bg-white p-3' : 'bg-black'
                                            }`}
                                        >
                                            {media.icon ? (
                                                <img
                                                    src={media.icon}
                                                    alt=""
                                                    className={`h-full w-full ${
                                                        media.iconIsBrandMark ? 'object-contain' : 'object-cover'
                                                    }`}
                                                />
                                            ) : (
                                                <div className="flex h-full w-full items-center justify-center bg-gradient-to-br from-violet-500/40 to-orange-500/30 text-lg font-bold text-orange-50">
                                                    {m.name[0]}
                                                </div>
                                            )}
                                            {!media.iconIsBrandMark && (
                                                <span className="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/40 to-transparent" />
                                            )}
                                        </div>

                                        <div className="min-w-0 flex-1 py-0.5">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <p className="text-[14px] font-semibold tracking-tight text-white">{m.name}</p>
                                                <span className="rounded-md bg-black px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-white/70 ring-1 ring-inset ring-white/15">
                                                    {m.brandName}
                                                </span>
                                            </div>
                                            <p className="mt-1 line-clamp-2 text-[12px] leading-snug text-white/55">
                                                {m.description || modalFallback}
                                            </p>
                                            {tags.length > 0 && (
                                                <div className="mt-2 flex flex-wrap gap-1">
                                                    {tags.slice(0, 3).map((tag) => (
                                                        <span
                                                            key={tag}
                                                            className="rounded-md bg-black px-1.5 py-0.5 text-[10px] font-medium text-white/65 ring-1 ring-inset ring-white/15"
                                                        >
                                                            {tag}
                                                        </span>
                                                    ))}
                                                </div>
                                            )}
                                        </div>

                                        {active && (
                                            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#FF5733]/20 ring-1 ring-[#FF5733]/40">
                                                <svg className="h-4 w-4 text-[#FF5733]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.5">
                                                    <path d="M20 6 9 17l-5-5" />
                                                </svg>
                                            </span>
                                        )}
                                    </div>
                                </button>
                            );
                        })}
                    </div>
                </ModalShell>
            )}
        </AnimatePresence>
    );
}

function ModalShell({
    children,
    onClose,
    wide,
}: {
    children: React.ReactNode;
    onClose: () => void;
    wide?: boolean;
}) {
    return (
        <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-3 backdrop-blur-lg"
            onClick={onClose}
        >
            <motion.div
                initial={{ opacity: 0, scale: 0.96, y: 12 }}
                animate={{ opacity: 1, scale: 1, y: 0 }}
                exit={{ opacity: 0, scale: 0.97 }}
                className={`relative w-[95vw] overflow-hidden rounded-2xl border border-white/10 bg-black p-5 shadow-[0_30px_80px_rgba(0,0,0,0.55)] sm:p-6 ${
                    wide ? 'max-w-xl' : 'max-w-lg'
                }`}
                onClick={(e) => e.stopPropagation()}
            >
                <div className="pointer-events-none absolute inset-x-0 top-0 h-28 bg-[radial-gradient(ellipse_at_top,rgba(255,87,51,0.22),transparent_70%)]" />
                <button
                    type="button"
                    onClick={onClose}
                    className="absolute end-4 top-4 z-10 rounded-lg p-1.5 text-white/40 transition hover:bg-white/[0.06] hover:text-white"
                >
                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                        <path d="M18 6 6 18" />
                        <path d="m6 6 12 12" />
                    </svg>
                </button>
                <div className="relative">{children}</div>
            </motion.div>
        </motion.div>
    );
}
