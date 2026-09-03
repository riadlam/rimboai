import '../css/app.css';
import './lib/i18n';
import type { ComponentType } from 'react';
import { bootstrapApp, type AppPageModule } from './bootstrapApp';

const pages = import.meta.glob('./Pages/**/*.tsx', { eager: true }) as Record<string, AppPageModule>;

bootstrapApp({
    entry: 'meta-classic',
    resolve: (name) => {
        const page = pages[`./Pages/${name}.tsx`];
        if (!page) {
            throw new Error(`Unknown Inertia page: ${name}`);
        }
        return page.default as ComponentType<Record<string, unknown>>;
    },
});
