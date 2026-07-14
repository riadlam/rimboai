import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useModal } from '@/Context/ModalContext';
import Button from '@/Components/Button';
import Loader from '@/Components/Loader';
import Toggle from '@/Components/Toggle';
import { useState } from 'react';

const titles: Record<string, string> = {
    settings: 'Settings',
    tool: 'Tool Details',
};

export default function ModalHost() {
    const { activeModal, toolData, close, loading, progress, simulateGeneration } = useModal();
    const [quality, setQuality] = useState(true);
    const open = !!activeModal;
    const title =
        activeModal === 'tool' ? toolData?.name || 'Tool Details' : activeModal ? titles[activeModal] : '';

    return (
        <AnimatePresence>
            {open && (
                <Dialog static open={open} onClose={close} className="relative z-50">
                    <motion.div
                        className="fixed inset-0 bg-black/50 backdrop-blur-sm"
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        transition={{ duration: 0.3 }}
                        aria-hidden="true"
                    />
                    <div className="fixed inset-0 flex items-center justify-center p-4 sm:p-6 lg:p-8">
                        <DialogPanel
                            as={motion.div}
                            initial={{ opacity: 0, scale: 0.95, y: 16 }}
                            animate={{ opacity: 1, scale: 1, y: 0 }}
                            exit={{ opacity: 0, scale: 0.95 }}
                            transition={{ duration: 0.3, ease: 'easeOut' }}
                            className="relative z-10 flex w-full max-w-7xl flex-col overflow-hidden rounded-2xl border border-border bg-surface shadow-2xl dark:border-[var(--dark-border)] dark:bg-[var(--dark-surface)]"
                            style={{ maxHeight: 'calc(100vh - 2rem)' }}
                        >
                            <div className="flex items-center justify-between border-b border-border px-6 py-4 dark:border-[var(--dark-border)]">
                                <DialogTitle className="text-lg font-semibold text-text-primary dark:text-[var(--dark-text-primary)]">
                                    {title}
                                </DialogTitle>
                                <button
                                    type="button"
                                    onClick={close}
                                    className="flex h-8 w-8 items-center justify-center rounded-lg text-text-tertiary transition-colors hover:bg-surface-tertiary hover:text-text-primary dark:text-[var(--dark-text-tertiary)] dark:hover:bg-[var(--dark-surface-tertiary)]"
                                    aria-label="Close modal"
                                >
                                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            <div className="flex flex-1 flex-col overflow-hidden lg:flex-row">
                                <div className="flex-1 overflow-y-auto border-r border-border p-6 scrollbar-thin dark:border-[var(--dark-border)] lg:w-1/2">
                                    {activeModal === 'settings' && (
                                        <div className="space-y-4">
                                            <Toggle
                                                checked={quality}
                                                onChange={setQuality}
                                                label="High Quality"
                                                description="Use higher fidelity processing"
                                            />
                                            <p className="text-sm text-text-secondary dark:text-[var(--dark-text-secondary)]">
                                                Account and preference settings.
                                            </p>
                                        </div>
                                    )}
                                    {activeModal === 'tool' && toolData && (
                                        <div className="space-y-5">
                                            <p className="text-sm text-text-secondary dark:text-[var(--dark-text-secondary)]">
                                                Configure and run {toolData.name} with premium AI processing.
                                            </p>
                                            <Toggle
                                                checked={quality}
                                                onChange={setQuality}
                                                label="High Quality"
                                                description="Recommended for final exports"
                                            />
                                            <Button variant="creative" className="w-full" onClick={simulateGeneration} loading={loading}>
                                                Create
                                            </Button>
                                        </div>
                                    )}
                                </div>

                                <div className="flex w-full flex-col items-center justify-center bg-surface-secondary p-6 dark:bg-[var(--dark-surface-secondary)] lg:w-1/2">
                                    {loading ? (
                                        <Loader progress={progress} />
                                    ) : toolData ? (
                                        <video
                                            src={toolData.video}
                                            poster={toolData.poster}
                                            className="max-h-[420px] w-full rounded-2xl object-cover"
                                            muted
                                            loop
                                            autoPlay
                                            playsInline
                                        />
                                    ) : (
                                        <div className="rounded-2xl border border-dashed border-border p-12 text-center text-sm text-text-tertiary dark:border-[var(--dark-border)] dark:text-[var(--dark-text-tertiary)]">
                                            Preview appears here
                                        </div>
                                    )}
                                </div>
                            </div>
                        </DialogPanel>
                    </div>
                </Dialog>
            )}
        </AnimatePresence>
    );
}
