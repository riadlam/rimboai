import '../css/app.css';
import './lib/i18n';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { bootstrapApp } from './bootstrapApp';

bootstrapApp({
    entry: 'modern',
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob('./Pages/**/*.tsx'),
        ),
});
