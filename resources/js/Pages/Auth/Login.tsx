import { Head, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import AuthLayout from '@/Layouts/AuthLayout';
import Input from '@/Components/Input';
import Button from '@/Components/Button';
import GoogleAuthButton from '@/Components/GoogleAuthButton';
import type { FormEvent } from 'react';

export default function Login() {
    const { t } = useTranslation('auth');
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post('/login');
    };

    return (
        <AuthLayout title={t('welcomeBack')} subtitle={t('signInSubtitle')}>
            <Head title={t('signInTitle')} />
            <div className="mb-5">
                <GoogleAuthButton label={t('signInWithGoogle')} />
            </div>
            <form onSubmit={submit} className="space-y-5">
                <Input
                    label={t('email')}
                    type="email"
                    name="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    autoComplete="email"
                    autoFocus
                    required
                    placeholder={t('emailPlaceholder')}
                    error={errors.email}
                />
                <Input
                    label={t('password')}
                    type="password"
                    name="password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    autoComplete="current-password"
                    required
                    placeholder={t('passwordPlaceholder')}
                    error={errors.password}
                />
                <label className="flex cursor-pointer items-center gap-2">
                    <input
                        type="checkbox"
                        checked={data.remember}
                        onChange={(e) => setData('remember', e.target.checked)}
                        className="size-4 rounded border border-white/10 bg-black/40 text-[#3b82f6] focus:ring-[#3b82f6]/20 focus:ring-offset-0"
                    />
                    <span className="text-sm text-[#94a3b8]">{t('rememberMe')}</span>
                </label>
                <Button type="submit" variant="auth" className="w-full" loading={processing}>
                    {t('signIn')}
                    <svg className="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                    </svg>
                </Button>
            </form>
        </AuthLayout>
    );
}
