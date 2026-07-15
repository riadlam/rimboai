import { Head, useForm } from '@inertiajs/react';
import AuthLayout from '@/Layouts/AuthLayout';
import Input from '@/Components/Input';
import Button from '@/Components/Button';
import GoogleAuthButton from '@/Components/GoogleAuthButton';
import type { FormEvent } from 'react';

export default function Login() {
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
        <AuthLayout title="Welcome back" subtitle="Sign in to continue to AI Studio">
            <Head title="Sign In" />
            <div className="mb-5">
                <GoogleAuthButton label="Sign in with Google" />
            </div>
            <form onSubmit={submit} className="space-y-5">
                <Input
                    label="Email address"
                    type="email"
                    name="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    autoComplete="email"
                    autoFocus
                    required
                    placeholder="you@example.com"
                    error={errors.email}
                />
                <Input
                    label="Password"
                    type="password"
                    name="password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    autoComplete="current-password"
                    required
                    placeholder="Enter your password"
                    error={errors.password}
                />
                <label className="flex cursor-pointer items-center gap-2">
                    <input
                        type="checkbox"
                        checked={data.remember}
                        onChange={(e) => setData('remember', e.target.checked)}
                        className="size-4 rounded border border-white/10 bg-black/40 text-[#3b82f6] focus:ring-[#3b82f6]/20 focus:ring-offset-0"
                    />
                    <span className="text-sm text-[#94a3b8]">Remember me</span>
                </label>
                <Button type="submit" variant="auth" className="w-full" loading={processing}>
                    Sign In
                    <svg className="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                    </svg>
                </Button>
            </form>
        </AuthLayout>
    );
}
