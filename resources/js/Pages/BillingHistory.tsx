import { Head, Link, router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import AppLayout from '@/Layouts/AppLayout';

type PaymentStatus = 'paid' | 'pending' | 'failed' | 'canceled' | string;

type Payment = {
    reference: string;
    package: string | null;
    tokens: number;
    amount: number;
    currency: string;
    status: PaymentStatus;
    created_at: string | null;
    paid_at: string | null;
};

type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Props = {
    payments: Paginator<Payment>;
    filters: { status: string };
    stats: {
        total_count: number;
        paid_count: number;
        paid_amount: number;
        purchased_tokens: number;
    };
};

const FILTERS = [
    { id: 'all', label: 'All' },
    { id: 'paid', label: 'Paid' },
    { id: 'pending', label: 'Pending' },
    { id: 'failed', label: 'Failed' },
    { id: 'canceled', label: 'Canceled' },
] as const;

const STATUS_STYLES: Record<string, string> = {
    paid: 'border-emerald-400/20 bg-emerald-400/10 text-emerald-300',
    pending: 'border-amber-400/20 bg-amber-400/10 text-amber-300',
    failed: 'border-rose-400/20 bg-rose-400/10 text-rose-300',
    canceled: 'border-zinc-400/20 bg-zinc-400/10 text-zinc-300',
};

function money(amount: number, currency: string) {
    return new Intl.NumberFormat('en-DZ', {
        style: 'currency',
        currency: currency || 'DZD',
        maximumFractionDigits: 2,
    }).format(amount);
}

function dateTime(value: string | null) {
    if (!value) return '—';
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function changeFilter(status: string) {
    router.get('/billing/history', status === 'all' ? {} : { status }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        only: ['payments', 'filters'],
    });
}

function StatusBadge({ status }: { status: PaymentStatus }) {
    const style = STATUS_STYLES[status] ?? STATUS_STYLES.canceled;
    return (
        <span className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold capitalize ${style}`}>
            <span className="h-1.5 w-1.5 rounded-full bg-current" />
            {status}
        </span>
    );
}

function StatCard({
    label,
    value,
    detail,
    accent,
}: {
    label: string;
    value: string;
    detail: string;
    accent: string;
}) {
    return (
        <div className="relative overflow-hidden rounded-2xl border border-white/[0.08] bg-white/[0.035] p-4 sm:p-5">
            <div className={`absolute -right-8 -top-8 h-24 w-24 rounded-full blur-3xl ${accent}`} />
            <p className="relative text-[11px] font-semibold uppercase tracking-[0.16em] text-white/35">{label}</p>
            <p className="relative mt-2 font-[family-name:Outfit,sans-serif] text-2xl font-bold tabular-nums text-white">{value}</p>
            <p className="relative mt-1 text-xs text-white/35">{detail}</p>
        </div>
    );
}

export default function BillingHistory({ payments, filters, stats }: Props) {
    return (
        <AppLayout>
            <Head title="Billing history" />

            <div className="mx-auto w-full max-w-6xl pb-10">
                <motion.div
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
                >
                    <div>
                        <div className="mb-2 flex items-center gap-2 text-xs font-medium text-[#FF8A65]">
                            <span className="flex h-6 w-6 items-center justify-center rounded-lg bg-[#FF5733]/12">
                                <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8">
                                    <rect width="20" height="14" x="2" y="5" rx="2" />
                                    <path d="M2 10h20" />
                                </svg>
                            </span>
                            Payments
                        </div>
                        <h1 className="font-[family-name:Outfit,sans-serif] text-3xl font-bold tracking-tight text-white sm:text-4xl">
                            Billing history
                        </h1>
                        <p className="mt-2 max-w-xl text-sm leading-relaxed text-white/40">
                            Review token purchases and payment status. Sensitive gateway details are never displayed.
                        </p>
                    </div>
                    <Link
                        href="/pricing"
                        className="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-gradient-to-b from-[#FF6A45] to-[#E24216] px-4 text-sm font-semibold text-white shadow-[0_12px_28px_-14px_rgba(255,87,51,0.9)] transition hover:brightness-110"
                    >
                        Buy tokens
                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M5 12h14m-5-5 5 5-5 5" />
                        </svg>
                    </Link>
                </motion.div>

                <motion.div
                    initial={{ opacity: 0, y: 14 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ delay: 0.06 }}
                    className="mt-7 grid grid-cols-2 gap-3 lg:grid-cols-4"
                >
                    <StatCard label="Purchases" value={stats.total_count.toLocaleString()} detail={`${stats.paid_count} completed`} accent="bg-sky-500/20" />
                    <StatCard label="Tokens bought" value={stats.purchased_tokens.toLocaleString()} detail="From completed payments" accent="bg-[#FF5733]/25" />
                    <StatCard label="Total paid" value={money(stats.paid_amount, 'DZD')} detail="Completed payments only" accent="bg-emerald-500/20" />
                    <StatCard
                        label="Success rate"
                        value={stats.total_count ? `${Math.round((stats.paid_count / stats.total_count) * 100)}%` : '—'}
                        detail="Across all payment attempts"
                        accent="bg-violet-500/20"
                    />
                </motion.div>

                <motion.section
                    initial={{ opacity: 0, y: 14 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ delay: 0.1 }}
                    className="mt-6 overflow-hidden rounded-2xl border border-white/[0.08] bg-white/[0.025]"
                >
                    <div className="flex flex-col gap-3 border-b border-white/[0.07] px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                        <div>
                            <h2 className="text-sm font-semibold text-white">Transactions</h2>
                            <p className="mt-0.5 text-xs text-white/35">{payments.total.toLocaleString()} matching records</p>
                        </div>
                        <div className="flex max-w-full gap-1 overflow-x-auto rounded-xl bg-black/20 p-1 scrollbar-hide">
                            {FILTERS.map((filter) => (
                                <button
                                    key={filter.id}
                                    type="button"
                                    onClick={() => changeFilter(filter.id)}
                                    className={`shrink-0 rounded-lg px-3 py-1.5 text-xs font-medium transition ${
                                        filters.status === filter.id
                                            ? 'bg-white/10 text-white shadow-sm'
                                            : 'text-white/40 hover:bg-white/5 hover:text-white/70'
                                    }`}
                                >
                                    {filter.label}
                                </button>
                            ))}
                        </div>
                    </div>

                    {payments.data.length === 0 ? (
                        <div className="flex flex-col items-center px-5 py-16 text-center">
                            <span className="flex h-14 w-14 items-center justify-center rounded-2xl border border-white/[0.08] bg-white/[0.03] text-white/30">
                                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                    <path d="M3 7h18M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" />
                                    <path d="M8 15h4" />
                                </svg>
                            </span>
                            <h3 className="mt-4 text-sm font-semibold text-white">No payments found</h3>
                            <p className="mt-1 text-xs text-white/35">
                                {filters.status === 'all' ? 'Your token purchases will appear here.' : `You have no ${filters.status} payments.`}
                            </p>
                        </div>
                    ) : (
                        <>
                            <div className="hidden grid-cols-[1.2fr_1fr_0.85fr_0.9fr_0.8fr] gap-4 border-b border-white/[0.06] px-5 py-3 text-[10px] font-semibold uppercase tracking-wider text-white/25 md:grid">
                                <span>Reference</span>
                                <span>Package</span>
                                <span>Amount</span>
                                <span>Date</span>
                                <span className="text-end">Status</span>
                            </div>

                            <div className="divide-y divide-white/[0.055]">
                                {payments.data.map((payment, index) => (
                                    <motion.div
                                        key={payment.reference}
                                        initial={{ opacity: 0, y: 6 }}
                                        animate={{ opacity: 1, y: 0 }}
                                        transition={{ delay: Math.min(index * 0.025, 0.2) }}
                                        className="px-4 py-4 transition hover:bg-white/[0.025] sm:px-5"
                                    >
                                        <div className="hidden grid-cols-[1.2fr_1fr_0.85fr_0.9fr_0.8fr] items-center gap-4 md:grid">
                                            <div>
                                                <p className="font-mono text-xs font-medium text-white/75">{payment.reference}</p>
                                                <p className="mt-1 text-[11px] text-white/25">SofizPay · CIB/Edahabia</p>
                                            </div>
                                            <div>
                                                <p className="text-sm font-medium capitalize text-white/75">{payment.package ?? 'Token pack'}</p>
                                                <p className="mt-1 text-xs text-amber-300/70">{payment.tokens.toLocaleString()} tokens</p>
                                            </div>
                                            <p className="text-sm font-semibold tabular-nums text-white">{money(payment.amount, payment.currency)}</p>
                                            <p className="text-xs text-white/45">{dateTime(payment.paid_at ?? payment.created_at)}</p>
                                            <div className="text-end"><StatusBadge status={payment.status} /></div>
                                        </div>

                                        <div className="md:hidden">
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-semibold capitalize text-white">{payment.package ?? 'Token pack'}</p>
                                                    <p className="mt-1 font-mono text-[11px] text-white/35">{payment.reference}</p>
                                                </div>
                                                <StatusBadge status={payment.status} />
                                            </div>
                                            <div className="mt-4 grid grid-cols-3 gap-2 rounded-xl bg-black/20 p-3">
                                                <div>
                                                    <p className="text-[9px] font-semibold uppercase tracking-wider text-white/25">Amount</p>
                                                    <p className="mt-1 text-xs font-semibold text-white">{money(payment.amount, payment.currency)}</p>
                                                </div>
                                                <div>
                                                    <p className="text-[9px] font-semibold uppercase tracking-wider text-white/25">Tokens</p>
                                                    <p className="mt-1 text-xs font-semibold text-amber-300/80">{payment.tokens.toLocaleString()}</p>
                                                </div>
                                                <div>
                                                    <p className="text-[9px] font-semibold uppercase tracking-wider text-white/25">Date</p>
                                                    <p className="mt-1 truncate text-xs text-white/55">{dateTime(payment.paid_at ?? payment.created_at)}</p>
                                                </div>
                                            </div>
                                        </div>
                                    </motion.div>
                                ))}
                            </div>
                        </>
                    )}

                    {payments.last_page > 1 && (
                        <div className="flex items-center justify-between border-t border-white/[0.07] px-4 py-3 sm:px-5">
                            <p className="text-xs text-white/30">
                                {payments.from}–{payments.to} of {payments.total}
                            </p>
                            <div className="flex items-center gap-2">
                                <Link
                                    href={payments.prev_page_url ?? '#'}
                                    preserveScroll
                                    className={`rounded-lg border border-white/[0.08] px-3 py-1.5 text-xs transition ${
                                        payments.prev_page_url ? 'text-white/65 hover:bg-white/5 hover:text-white' : 'pointer-events-none opacity-30'
                                    }`}
                                >
                                    Previous
                                </Link>
                                <span className="text-xs tabular-nums text-white/35">{payments.current_page} / {payments.last_page}</span>
                                <Link
                                    href={payments.next_page_url ?? '#'}
                                    preserveScroll
                                    className={`rounded-lg border border-white/[0.08] px-3 py-1.5 text-xs transition ${
                                        payments.next_page_url ? 'text-white/65 hover:bg-white/5 hover:text-white' : 'pointer-events-none opacity-30'
                                    }`}
                                >
                                    Next
                                </Link>
                            </div>
                        </div>
                    )}
                </motion.section>
            </div>
        </AppLayout>
    );
}
