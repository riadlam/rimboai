import AppLayout from '@/Layouts/AppLayout';
import LabWorkspace from '@/Components/LabWorkspace';
import type { Brand } from '@/types';
import type { CreditsConfig } from '@/lib/imageCredits';

type Props = {
    type: string;
    title: string;
    backHref: string;
    placeholder: string;
    brands?: Brand[];
    creditsConfig?: CreditsConfig;
};

export default function Lab({ type, title, backHref, placeholder, brands = [], creditsConfig }: Props) {
    return (
        <AppLayout flush>
            <LabWorkspace
                key={type}
                type={type}
                title={title}
                backHref={backHref}
                brands={brands}
                placeholder={placeholder}
                creditsConfig={creditsConfig}
            />
        </AppLayout>
    );
}
