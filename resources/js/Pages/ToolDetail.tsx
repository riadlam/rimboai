import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import AppLayout from '@/Layouts/AppLayout';
import FileUploader from '@/Components/FileUploader';
import Toggle from '@/Components/Toggle';
import Button from '@/Components/Button';
import VideoPreview from '@/Components/VideoPreview';
import type { Tool } from '@/types';

type Props = {
    tool: Tool;
};

export default function ToolDetail({ tool }: Props) {
    const { t } = useTranslation('tools');
    const [model, setModel] = useState('auto');
    const [mode, setMode] = useState('general');
    const [scale, setScale] = useState('2x');
    const [quality, setQuality] = useState(true);
    const [publicVisible, setPublicVisible] = useState(true);
    const [copyProtection, setCopyProtection] = useState(false);
    const [loading, setLoading] = useState(false);
    const [progress, setProgress] = useState(0);

    const showScale = ['Video Upscaler', 'Video Enhancer', 'Anime Video Enhancer'].includes(tool.name);
    const showMode = ['Denoise Video', 'Video Enhancer'].includes(tool.name);

    useEffect(() => {
        if (!loading) return;
        const id = setInterval(() => {
            setProgress((p) => {
                if (p >= 90) return p;
                const next = p + Math.random() * 8 + 2;
                return next > 90 ? 90 : next;
            });
        }, 600);
        const done = setTimeout(() => {
            clearInterval(id);
            setProgress(100);
            setTimeout(() => {
                setLoading(false);
                setProgress(0);
            }, 800);
        }, 4000);
        return () => {
            clearInterval(id);
            clearTimeout(done);
        };
    }, [loading]);

    return (
        <AppLayout>
            <Head title={tool.name} />
            <div className="flex min-h-0 w-full min-w-0 flex-col gap-4 lg:h-[calc(100dvh-2.5rem)] lg:flex-row lg:gap-5">
                <div className="w-full min-w-0 shrink-0 space-y-5 overflow-y-auto scrollbar-thin lg:w-[320px] xl:w-[400px]">
                    <div>
                        <h1 className="text-2xl font-bold text-text-primary dark:text-[var(--dark-text-primary)]">{tool.name}</h1>
                        <p className="mt-1 text-sm text-text-tertiary dark:text-[var(--dark-text-tertiary)]">{t('detail.subtitle')}</p>
                    </div>

                    <div className="space-y-5">
                        <FileUploader label={t('detail.upload')} />

                        <div className="space-y-1.5">
                            <label className="block text-sm font-medium text-text-primary dark:text-[var(--dark-text-primary)]">{t('detail.aiModel')}</label>
                            <select
                                value={model}
                                onChange={(e) => setModel(e.target.value)}
                                className="w-full rounded-xl border border-border bg-surface px-4 py-2.5 text-sm text-text-primary transition-all focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-[var(--dark-border)] dark:bg-[var(--dark-surface-secondary)] dark:text-[var(--dark-text-primary)]"
                            >
                                <option value="auto">{t('detail.modelAuto')}</option>
                                <option value="standard">{t('detail.modelStandard')}</option>
                                <option value="pro">{t('detail.modelPro')}</option>
                                <option value="ultra">{t('detail.modelUltra')}</option>
                            </select>
                        </div>

                        {showScale && (
                            <div className="space-y-3">
                                <label className="text-sm font-medium text-text-primary dark:text-[var(--dark-text-primary)]">{t('detail.scale')}</label>
                                <div className="flex gap-2">
                                    {['2x', '4x', '8x'].map((option) => (
                                        <button
                                            key={option}
                                            type="button"
                                            onClick={() => setScale(option)}
                                            className={`flex-1 rounded-lg px-4 py-2 text-sm font-medium transition-colors ${
                                                scale === option
                                                    ? 'creative-gradient text-white'
                                                    : 'bg-surface-tertiary text-text-secondary hover:text-text-primary dark:bg-[var(--dark-surface-tertiary)] dark:text-[var(--dark-text-secondary)]'
                                            }`}
                                        >
                                            {option}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}

                        {showMode && (
                            <div className="space-y-3">
                                <label className="text-sm font-medium text-text-primary dark:text-[var(--dark-text-primary)]">{t('detail.mode')}</label>
                                <div className="flex gap-2">
                                    {[
                                        { label: t('detail.modeGeneral'), value: 'general' },
                                        { label: t('detail.modeAnimation'), value: 'animation' },
                                        { label: t('detail.modeLowLight'), value: 'low light' },
                                    ].map((option) => (
                                        <button
                                            key={option.value}
                                            type="button"
                                            onClick={() => setMode(option.value)}
                                            className={`flex-1 rounded-lg px-4 py-2 text-sm font-medium transition-colors ${
                                                mode === option.value
                                                    ? 'creative-gradient text-white'
                                                    : 'bg-surface-tertiary text-text-secondary hover:text-text-primary dark:bg-[var(--dark-surface-tertiary)] dark:text-[var(--dark-text-secondary)]'
                                            }`}
                                        >
                                            {option.label}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}

                        <Toggle checked={quality} onChange={setQuality} label={t('detail.hq')} description={t('detail.hqDesc')} />
                        <Toggle checked={publicVisible} onChange={setPublicVisible} label={t('detail.public')} description={t('detail.publicDesc')} />
                        <Toggle checked={copyProtection} onChange={setCopyProtection} label={t('detail.copyProtection')} description={t('detail.copyProtectionDesc')} />

                        <div className="flex items-center justify-between rounded-xl border border-border px-4 py-3 dark:border-[var(--dark-border)]">
                            <span className="text-sm text-text-secondary dark:text-[var(--dark-text-secondary)]">{t('detail.credits')}</span>
                            <span className="text-sm font-semibold text-text-primary dark:text-[var(--dark-text-primary)]">12</span>
                        </div>

                        <div className="relative">
                            <Button
                                variant="creative"
                                className="w-full"
                                loading={loading}
                                onClick={() => {
                                    setLoading(true);
                                    setProgress(0);
                                }}
                            >
                                {t('detail.create')}
                            </Button>
                            {loading && (
                                <div className="absolute bottom-0 left-0 h-1 w-full overflow-hidden rounded-b-xl">
                                    <div className="h-full creative-gradient transition-all duration-300" style={{ width: `${progress}%` }} />
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                <div className="flex min-h-[280px] min-w-0 flex-1 items-center justify-center overflow-hidden rounded-2xl bg-surface-secondary dark:bg-[var(--dark-surface-secondary)] lg:min-h-0">
                    <div className="h-full max-h-[70vh] w-full min-w-0 p-3 lg:p-4">
                        <VideoPreview src={tool.video} poster={tool.poster} />
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
