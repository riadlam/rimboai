import { Head, Link, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import AuthLayout from '@/Layouts/AuthLayout';
import Input from '@/Components/Input';
import Button from '@/Components/Button';
import GoogleAuthButton from '@/Components/GoogleAuthButton';
import type { FormEvent } from 'react';

export default function Register() {
    const { t } = useTranslation('auth');
    const { t: tc } = useTranslation('common');
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post('/register');
    };

    return (
        <AuthLayout title={t('createAccount')} subtitle={t('registerSubtitle')}>
            <Head title={t('registerTitle')} />
            <div className="mb-5">
                <GoogleAuthButton label={t('signUpWithGoogle')} />
            </div>
            <form onSubmit={submit} className="space-y-5">
                <Input
                    label={t('name')}
                    name="name"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    required
                    autoFocus
                    placeholder={t('namePlaceholder')}
                    error={errors.name}
                />
                <Input
                    label={t('email')}
                    type="email"
                    name="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    required
                    autoComplete="email"
                    placeholder={t('emailPlaceholder')}
                    error={errors.email}
                />
                <Input
                    label={t('password')}
                    type="password"
                    name="password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    required
                    autoComplete="new-password"
                    placeholder={t('passwordMinPlaceholder')}
                    error={errors.password}
                />
                <Input
                    label={t('confirmPassword')}
                    type="password"
                    name="password_confirmation"
                    value={data.password_confirmation}
                    onChange={(e) => setData('password_confirmation', e.target.value)}
                    required
                    autoComplete="new-password"
                    placeholder={t('confirmPasswordPlaceholder')}
                    error={errors.password_confirmation}
                />
                <Button type="submit" variant="auth" className="w-full" loading={processing}>
                    {t('createAccountBtn')}
                </Button>
            </form>
            <p className="mt-6 text-center text-sm text-[#475569]">
                {t('alreadyHaveAccount')}{' '}
                <Link href="/login" className="font-medium text-[#3b82f6] transition-colors hover:text-[#2563eb]">
                    {tc('signIn')}
                </Link>
            </p>
        </AuthLayout>
    );
}
