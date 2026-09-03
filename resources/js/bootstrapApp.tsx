import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';
import AppErrorBoundary from './Components/AppErrorBoundary';
import { ThemeProvider } from './Context/ThemeContext';
import { ModalProvider } from './Context/ModalContext';
import { dismissAppBoot, showAppBootError } from './lib/appBoot';
import { applyLanguage, readSavedLang } from './lib/i18n';

export type AppPageModule = {
    default: ComponentType<Record<string, unknown>>;
};

type BootStage = 'entry' | 'inertia' | 'page' | 'mounted' | 'failed';

type BootState = {
    attempt: number;
    entry: string;
    stage: BootStage;
    error?: string;
};

declare global {
    interface Window {
        __appBootState?: BootState;
        __appBootLangHeaderBound?: boolean;
    }
}

type BootOptions = {
    entry: string;
    resolve: (name: string) => AppPageModule | ComponentType<Record<string, unknown>> | Promise<AppPageModule>;
};

function publishBootState(state: BootState): void {
    window.__appBootState = state;
    window.dispatchEvent(new CustomEvent('app:boot-stage', { detail: state }));
}

function errorMessage(error: unknown): string {
    if (error instanceof Error) return error.message.slice(0, 500);
    return String(error).slice(0, 500);
}

/**
 * Shared, race-safe Inertia startup.
 *
 * A delayed compatibility entry may supersede a stalled module entry. Only the
 * newest attempt is allowed to create the React root.
 */
export function bootstrapApp({ entry, resolve }: BootOptions): void {
    const attempt = (window.__appBootState?.attempt ?? 0) + 1;
    const update = (stage: BootStage, error?: unknown) => {
        publishBootState({
            attempt,
            entry,
            stage,
            ...(error === undefined ? {} : { error: errorMessage(error) }),
        });
    };

    update('entry');
    applyLanguage(readSavedLang(), { reload: false });

    if (!window.__appBootLangHeaderBound) {
        window.__appBootLangHeaderBound = true;
        router.on('before', (event) => {
            const visit = event.detail.visit;
            visit.headers = {
                ...visit.headers,
                'X-App-Lang': readSavedLang(),
            };
        });
    }

    const appName = import.meta.env.VITE_APP_NAME || 'AI Studio';
    update('inertia');

    void createInertiaApp({
        title: (title) => (title ? `${title} - ${appName}` : appName),
        resolve: async (name) => {
            update('page');
            return await resolve(name);
        },
        setup({ el, App, props }) {
            if (window.__appBootState?.attempt !== attempt) return;

            createRoot(el).render(
                <AppErrorBoundary>
                    <ThemeProvider>
                        <ModalProvider>
                            <App {...props} />
                        </ModalProvider>
                    </ThemeProvider>
                </AppErrorBoundary>,
            );
            update('mounted');
            dismissAppBoot();
        },
        progress: {
            color: '#C721FF',
        },
    }).catch((error) => {
        if (window.__appBootState?.attempt !== attempt) return;
        console.error(`${entry} app boot failed:`, error);
        update('failed', error);
        showAppBootError();
    });
}
