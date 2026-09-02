import { Head, usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';

export type SeoMeta = {
    title: string;
    description: string;
    image: string;
    url: string;
    type?: string;
    robots?: string;
    site_name?: string;
    twitter_card?: string;
    twitter_handle?: string | null;
    locale?: string;
};

type Props = {
    title?: string;
    description?: string;
    image?: string;
    type?: string;
};

/**
 * Keeps Inertia <Head> in sync with server-rendered meta (for client navigations).
 * Initial page loads already have tags from resources/views/partials/seo-meta.blade.php.
 */
export default function PageSeo({ title, description, image, type }: Props) {
    const { seo } = usePage<PageProps>().props;
    const base = seo ?? {
        title: 'RIMBOAI',
        description: '',
        image: '',
        url: typeof window !== 'undefined' ? window.location.href : '',
    };
    const meta = {
        ...base,
        ...(title ? { title } : {}),
        ...(description ? { description } : {}),
        ...(image ? { image } : {}),
        ...(type ? { type } : {}),
    };

    return (
        <Head title={meta.title}>
            <meta head-key="description" name="description" content={meta.description} />
            <meta head-key="og:title" property="og:title" content={meta.title} />
            <meta head-key="og:description" property="og:description" content={meta.description} />
            <meta head-key="og:image" property="og:image" content={meta.image} />
            <meta head-key="og:url" property="og:url" content={meta.url} />
            {meta.type ? <meta head-key="og:type" property="og:type" content={meta.type} /> : null}
            <meta head-key="twitter:card" name="twitter:card" content={meta.twitter_card ?? 'summary_large_image'} />
            <meta head-key="twitter:title" name="twitter:title" content={meta.title} />
            <meta head-key="twitter:description" name="twitter:description" content={meta.description} />
            <meta head-key="twitter:image" name="twitter:image" content={meta.image} />
        </Head>
    );
}
