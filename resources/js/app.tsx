import '../css/app.css';
import './lib/i18n';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ThemeProvider } from './Context/ThemeContext';
import { ModalProvider } from './Context/ModalContext';
import { applyLanguage, readSavedLang } from './lib/i18n';

const appName = import.meta.env.VITE_APP_NAME || 'AI Studio';

applyLanguage(readSavedLang());

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob('./Pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        createRoot(el).render(
            <ThemeProvider>
                <ModalProvider>
                    <App {...props} />
                </ModalProvider>
            </ThemeProvider>,
        );
    },
    progress: {
        color: '#C721FF',
    },
});
