import { AnimatePresence, motion } from 'framer-motion';
import { useState, type ReactNode } from 'react';
import AppHeader from '@/Components/AppHeader';
import Sidebar from '@/Components/Sidebar';
import ModalHost from '@/Components/ModalHost';
import { PageFade } from '@/Components/Motion';

type Props = {
    children: ReactNode;
    /** Full-height pages (Lab): no page scroll — children manage their own overflow */
    flush?: boolean;
};

export default function AppLayout({ children, flush = false }: Props) {
    const [sidebarOpen, setSidebarOpen] = useState(false);

    return (
        <div className="flex h-dvh max-h-dvh w-full max-w-[100vw] flex-col overflow-x-hidden overflow-y-hidden">
            <AppHeader onMenuClick={() => setSidebarOpen((v) => !v)} />
            {/* Spacer for fixed header height */}
            <div aria-hidden className="h-14 shrink-0 md:h-16" />

            <div className="flex min-h-0 min-w-0 flex-1 overflow-x-hidden overflow-hidden">
                <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)} />

                <AnimatePresence>
                    {sidebarOpen && (
                        <motion.div
                            initial={{ opacity: 0 }}
                            animate={{ opacity: 1 }}
                            exit={{ opacity: 0 }}
                            onClick={() => setSidebarOpen(false)}
                            className="fixed inset-x-0 bottom-0 top-14 z-20 bg-black/30 backdrop-blur-sm md:top-16 lg:hidden"
                        />
                    )}
                </AnimatePresence>

                <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-x-hidden overflow-hidden">
                    <main
                        className={`min-h-0 min-w-0 flex-1 overflow-x-hidden bg-[#070708] ${
                            flush
                                ? 'overflow-y-auto scrollbar-thin md:overflow-y-hidden'
                                : 'overflow-y-auto scrollbar-thin'
                        }`}
                    >
                        <div
                            className={
                                flush
                                    ? 'box-border flex w-full max-w-full flex-col px-4 py-3 sm:px-5 md:h-full md:min-h-0 lg:px-6 lg:py-4 xl:px-8'
                                    : 'mx-auto box-border w-full max-w-full px-4 py-4 sm:px-5 lg:px-6 lg:py-5 xl:px-8'
                            }
                        >
                            <PageFade className={flush ? 'flex flex-col md:h-full md:min-h-0 md:flex-1' : undefined}>
                                {children}
                            </PageFade>
                        </div>
                    </main>
                </div>
            </div>

            <ModalHost />
        </div>
    );
}
