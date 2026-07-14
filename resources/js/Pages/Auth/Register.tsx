import { Head, Link, useForm } from '@inertiajs/react';
import AuthLayout from '@/Layouts/AuthLayout';
import Input from '@/Components/Input';
import Button from '@/Components/Button';
import type { FormEvent } from 'react';

export default function Register() {
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
        <AuthLayout title="Create account" subtitle="Join AI Studio and start creating">
            <Head title="Register" />
            <form onSubmit={submit} className="space-y-5">
                <Input
                    label="Name"
                    name="name"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    required
                    autoFocus
                    placeholder="Your name"
                    error={errors.name}
                />
                <Input
                    label="Email address"
                    type="email"
                    name="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    required
                    autoComplete="email"
                    placeholder="you@example.com"
                    error={errors.email}
                />
                <Input
                    label="Password"
                    type="password"
                    name="password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    required
                    autoComplete="new-password"
                    placeholder="Min. 8 characters"
                    error={errors.password}
                />
                <Input
                    label="Confirm password"
                    type="password"
                    name="password_confirmation"
                    value={data.password_confirmation}
                    onChange={(e) => setData('password_confirmation', e.target.value)}
                    required
                    autoComplete="new-password"
                    placeholder="Confirm password"
                    error={errors.password_confirmation}
                />
                <Button type="submit" variant="auth" className="w-full" loading={processing}>
                    Create Account
                </Button>
            </form>
            <p className="mt-6 text-center text-sm text-[#475569]">
                Already have an account?{' '}
                <Link href="/login" className="font-medium text-[#3b82f6] transition-colors hover:text-[#2563eb]">
                    Sign in
                </Link>
            </p>
        </AuthLayout>
    );
}
