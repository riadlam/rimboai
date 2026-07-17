import { Head } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useCallback, useState } from 'react';
import { useTranslation } from 'react-i18next';
import AppLayout from '@/Layouts/AppLayout';
import ToolCreatePanel from '@/Components/ToolCreatePanel';
import type { CreditsConfig } from '@/lib/imageCredits';
import type { Tool, ToolWorkspace } from '@/types';

type Props = {
    tool: Tool;
    workspace: ToolWorkspace;
    creditsConfig: CreditsConfig;
    tokenBalance: number;
};

export default function ToolDetail({ tool, workspace, creditsConfig, tokenBalance }: Props) {
    const { t } = useTranslation('tools');
    const [resultVideoUrl, setResultVideoUrl] = useState<string | null>(null);
    const onResultVideo = useCallback((url: string | null) => setResultVideoUrl(url), []);

    const previewSrc = resultVideoUrl || tool.video;
    const isResult = Boolean(resultVideoUrl);

    return (
        <AppLayout flush>
            <Head title={tool.name} />
            <div className="flex w-full min-w-0 flex-col md:h-full md:min-h-0 [&_a]:cursor-pointer [&_button]:cursor-pointer [&_button:disabled]:cursor-not-allowed [&_label]:cursor-pointer">
                <div className="flex flex-col rounded-xl bg-[#070708] md:min-h-0 md:flex-1 md:overflow-hidden">
                    <div className="flex flex-col md:min-h-0 md:flex-1 md:overflow-hidden md:flex-row">
                        <ToolCreatePanel
                            tool={tool}
                            workspace={workspace}
                            creditsConfig={creditsConfig}
                            tokenBalance={tokenBalance}
                            onResultVideo={onResultVideo}
                        />

                        <motion.div
                            initial={{ opacity: 0 }}
                            animate={{ opacity: 1 }}
                            transition={{ delay: 0.08, duration: 0.4 }}
                            className="relative flex min-h-[50vh] min-w-0 w-full flex-col md:min-h-0 md:flex-1 md:overflow-hidden"
                        >
                            <div aria-hidden className="pointer-events-none absolute inset-0">
                                <div className="absolute left-1/2 top-1/3 h-80 w-80 -translate-x-1/2 rounded-full bg-[#FF5733]/12 blur-[120px]" />
                                <div className="absolute inset-0 bg-[radial-gradient(circle_at_50%_35%,rgba(255,255,255,0.035),transparent_55%)]" />
                            </div>

                            <div className="relative z-10 flex items-center justify-between gap-3 border-b border-white/[0.05] px-4 py-3 md:px-5">
                                <div>
                                    <p className="text-[11px] font-medium uppercase tracking-[0.14em] text-white/35">
                                        {isResult ? t('detail.result') : t('detail.preview')}
                                    </p>
                                    <p className="mt-0.5 text-[13px] text-white/70">
                                        {isResult ? t('detail.resultHint') : t('detail.previewHint')}
                                    </p>
                                </div>
                                <div className="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/[0.04] px-2.5 py-1 text-[11px] text-white/50">
                                    <span
                                        className={`h-1.5 w-1.5 rounded-full ${
                                            isResult
                                                ? 'bg-[#FF5733] shadow-[0_0_8px_rgba(255,87,51,0.8)]'
                                                : 'bg-emerald-400 shadow-[0_0_8px_rgba(52,211,153,0.8)]'
                                        }`}
                                    />
                                    {isResult ? t('detail.yourResult') : t('detail.demoLive')}
                                </div>
                            </div>

                            <div className="relative z-10 flex min-h-0 flex-1 items-center justify-center p-4 md:p-6">
                                <motion.div
                                    key={previewSrc}
                                    initial={{ opacity: 0, y: 16, scale: 0.98 }}
                                    animate={{ opacity: 1, y: 0, scale: 1 }}
                                    transition={{ duration: 0.45, ease: [0.22, 1, 0.36, 1] }}
                                    className="relative w-full max-w-4xl"
                                >
                                    <div className="absolute -inset-px rounded-[1.35rem] bg-gradient-to-b from-white/15 via-white/5 to-transparent opacity-70" />
                                    <div className="relative overflow-hidden rounded-[1.25rem] border border-white/[0.08] bg-black/50 shadow-[0_40px_100px_-40px_rgba(0,0,0,0.9)]">
                                        <div className="aspect-video w-full">
                                            <video
                                                src={previewSrc}
                                                poster={isResult ? undefined : tool.poster}
                                                className="h-full w-full object-cover"
                                                playsInline
                                                loop={!isResult}
                                                muted={!isResult}
                                                autoPlay
                                                controls
                                                preload="metadata"
                                            />
                                        </div>
                                        <div className="pointer-events-none absolute inset-x-0 top-0 h-24 bg-gradient-to-b from-black/50 to-transparent" />
                                        <div className="pointer-events-none absolute inset-x-0 bottom-0 h-28 bg-gradient-to-t from-black/70 via-black/25 to-transparent" />
                                        <div className="absolute start-4 top-4 rounded-lg border border-white/10 bg-black/45 px-2.5 py-1 text-[11px] font-medium text-white/80 backdrop-blur-md">
                                            {tool.name}
                                        </div>
                                    </div>
                                    {!isResult && (
                                        <p className="mt-4 text-center text-[12px] text-white/35">{t('detail.previewFooter')}</p>
                                    )}
                                </motion.div>
                            </div>
                        </motion.div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
