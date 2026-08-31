import SettingsLayout from "@/layouts/settings/settings-layout";
import { router } from '@inertiajs/react';
import {
    CalendarDays,
    CheckCircle2,
    Database,
    Download,
    Filter,
    RefreshCw,
    Search,
    WalletCards,
    XCircle,
    Ban,
} from 'lucide-react';
import { useState } from 'react';

type Transaction = {
    id: number;
    invoice_id: string | null;
    description: string | null;
    amount: string;
    currency: string;
    type: 'payment' | 'refund';
    status: 'pending' | 'success' | 'failed' | 'refunded';
    payment_method: string | null;
    paid_at: string | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};



type PaginatedTransactions = {
    data: Transaction[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
    links: PaginationLink[];
};

type Summary = {
    total_spent: string | number;
    credit_purchases: string | number;
    subscription_payments: string | number;
};

type Filters = {
    search: string;
    period: string;
    status: string;
};

type Props = {
    transactions: PaginatedTransactions;
    summary: Summary;
    filters: Filters;
};

export default function PurchaseHistory({transactions, summary, filters}: Props) {
    const [ search, setSearch ] = useState(filters.search);
    const updateFilters = ( 
        values: Partial<Filters>, 
    ) => {
        router.get(
            '/settings/purchase-history',
            {
                search,
                period: filters.period,
                status: filters.status,
                ...values,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    const submitSearch = (
        event: React.FormEvent,
    ) => {
        event.preventDefault();

        updateFilters({
            search,
        });
    };

    return (
        <SettingsLayout>
            <div className="min-h-screen bg-surface">
                <div className="mx-auto w-full max-w-container-max px-margin-mobile py-8 md:px-gutter md:py-12">

                    {/* Header */}
                    <header className="mb-10">
                        <h1 className="font-h2 text-h2 text-primary">
                            Purchase History
                        </h1>

                        <p className="mt-2 max-w-2xl font-body-lg text-body-lg text-on-surface-variant">
                            View your credit purchases and
                            subscription payments.
                        </p>
                    </header>

                    {/* Summary */}
                    <div className="mb-12 grid grid-cols-1 gap-6 md:grid-cols-3">
                        <SummaryCard
                            label="Total Spent"
                            value={formatMoney(
                                summary.total_spent,
                                'GBP',
                            )}
                            icon={<WalletCards />}
                        />

                        <SummaryCard
                            label="Credit Purchases"
                            value={formatMoney(
                                summary.credit_purchases,
                                'GBP',
                            )}
                            icon={<Database />}
                        />

                        <SummaryCard
                            label="Subscription Payments"
                            value={formatMoney(
                                summary.subscription_payments,
                                'GBP',
                            )}
                            icon={<RefreshCw />}
                        />
                    </div>

                    {/* Table container */}
                    <section className="overflow-hidden rounded-xl border border-outline-variant bg-surface shadow-[0_4px_20px_rgba(0,32,91,0.04)]">

                        {/* Filters */}
                        <div className="flex flex-col items-center justify-between gap-4 border-b border-outline-variant bg-surface-bright p-6 lg:flex-row">

                            <form
                                onSubmit={submitSearch}
                                className="relative w-full lg:w-96"
                            >
                                <Search className="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-on-surface-variant" />

                                <input
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Search transactions..."
                                    className="w-full rounded-lg border border-outline-variant bg-surface py-2.5 pl-10 pr-4 font-body-md text-body-md text-on-surface outline-none transition-colors placeholder:text-on-surface-variant/60 focus:border-primary focus:ring-1 focus:ring-primary"
                                />
                            </form>

                            <div className="flex w-full gap-3 lg:w-auto">

                                <div className="relative flex-1 lg:flex-none">
                                    <select
                                        value={filters.period}
                                        onChange={(event) =>
                                            updateFilters({
                                                period:
                                                    event.target
                                                        .value,
                                            })
                                        }
                                        className="w-full appearance-none rounded-lg border border-outline-variant bg-surface py-2.5 pl-4 pr-10 font-body-md text-body-md text-on-surface outline-none lg:w-48"
                                    >
                                        <option value="all">
                                            All Time
                                        </option>
                                        <option value="30">
                                            Last 30 Days
                                        </option>
                                        <option value="90">
                                            Last 90 Days
                                        </option>
                                        <option value="year">
                                            This Year
                                        </option>
                                    </select>

                                    <CalendarDays className="pointer-events-none absolute right-3 top-1/2 h-5 w-5 -translate-y-1/2 text-on-surface-variant" />
                                </div>

                                <div className="relative flex-1 lg:flex-none">
                                    <select
                                        value={filters.status}
                                        onChange={(event) =>
                                            updateFilters({
                                                status:
                                                    event.target
                                                        .value,
                                            })
                                        }
                                        className="w-full appearance-none rounded-lg border border-outline-variant bg-surface py-2.5 pl-4 pr-10 font-body-md text-body-md text-on-surface outline-none lg:w-48"
                                    >
                                        <option value="all">
                                            All Statuses
                                        </option>
                                        <option value="success">
                                            Successful
                                        </option>
                                        <option value="pending">
                                            Pending
                                        </option>
                                        <option value="failed">
                                            Failed
                                        </option>
                                        <option value="refunded">
                                            Refunded
                                        </option>
                                    </select>

                                    <Filter className="pointer-events-none absolute right-3 top-1/2 h-5 w-5 -translate-y-1/2 text-on-surface-variant" />
                                </div>
                            </div>
                        </div>

                        <div className="sovereign-line" />

                        {/* Table */}
                        <div className="overflow-x-auto">
                            <table className="w-full border-collapse text-left">
                                <thead>
                                    <tr className="border-b border-outline-variant bg-surface-container-low">
                                        <TableHeader>
                                            Date
                                        </TableHeader>

                                        <TableHeader>
                                            Description
                                        </TableHeader>

                                        <TableHeader>
                                            Type
                                        </TableHeader>

                                        <TableHeader align="right">
                                            Amount
                                        </TableHeader>

                                        <TableHeader align="center">
                                            Status
                                        </TableHeader>

                                        <TableHeader align="center">
                                            Invoice
                                        </TableHeader>
                                    </tr>
                                </thead>

                                <tbody className="divide-y divide-outline-variant">
                                    {transactions.data.length >
                                    0 ? (
                                        transactions.data.map(
                                            (transaction) => (
                                                <TransactionRow
                                                    key={
                                                        transaction.id
                                                    }
                                                    transaction={
                                                        transaction
                                                    }
                                                />
                                            ),
                                        )
                                    ) : (
                                        <tr>
                                            <td
                                                colSpan={6}
                                                className="px-6 py-14 text-center font-body-md text-on-surface-variant"
                                            >
                                                No transactions
                                                found.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {/* Pagination */}
                        <div className="flex items-center justify-between border-t border-outline-variant bg-surface-bright p-4">
                            <span className="text-sm text-on-surface-variant">
                                {transactions.total > 0
                                    ? `Showing ${transactions.from} to ${transactions.to} of ${transactions.total} entries`
                                    : 'No entries'}
                            </span>

                            <div className="flex gap-2">
                                <PaginationButton
                                    url={
                                        transactions.prev_page_url
                                    }
                                >
                                    Previous
                                </PaginationButton>

                                <PaginationButton
                                    url={
                                        transactions.next_page_url
                                    }
                                >
                                    Next
                                </PaginationButton>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </SettingsLayout>
    );
}

function SummaryCard({
    label,
    value,
    icon,
    large = false,
}: {
    label: string;
    value: string;
    icon: React.ReactNode;
    large?: boolean;
}) {
    return (
        <div className="group relative overflow-hidden rounded-xl border border-outline-variant bg-surface p-6 shadow-[0_4px_20px_rgba(0,32,91,0.04)] transition-all hover:border-primary-container hover:shadow-[0_8px_30px_rgba(0,32,91,0.08)]">

            <div className="absolute right-4 top-4 [&>svg]:h-16 [&>svg]:w-16 [&>svg]:text-primary [&>svg]:opacity-10">
                {icon}
            </div>

            <p className="mb-2 font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">
                {label}
            </p>

            <p
                className={
                    large
                        ? 'font-h1 text-h1 text-primary'
                        : 'font-h2 text-h2 text-primary'
                }
            >
                {value}
            </p>
        </div>
    );
}

function TransactionRow({
    transaction,
}: {
    transaction: Transaction;
}) {
    const date = formatDate(transaction.paid_at);

    return (
        <tr className="group transition-colors hover:bg-surface-bright">
            <td className="whitespace-nowrap px-6 py-4 font-body-md text-body-md text-on-surface">
                <div className="flex flex-col">
                    <span>{date.date}</span>

                    <span className="font-label-sm text-on-surface-variant">
                        {date.time}
                    </span>
                </div>
            </td>

            <td className="px-6 py-4 font-body-md font-medium text-on-surface">
                {transaction.description ??
                    transaction.invoice_id ??
                    'Payment'}
            </td>

            <td className="px-6 py-4">
                <span className="inline-flex items-center rounded-full bg-surface-variant px-2.5 py-0.5 text-xs font-medium text-on-surface">
                    {transaction.type === 'refund'
                        ? 'Refund'
                        : 'Credit Purchase'}
                </span>
            </td>

            <td className="whitespace-nowrap px-6 py-4 text-right font-body-md font-medium text-on-surface">
                {formatMoney(
                    transaction.amount,
                    transaction.currency,
                )}
            </td>

            <td className="px-6 py-4 text-center">
                <TransactionStatus
                    status={transaction.status}
                />
            </td>

            <td className="px-6 py-4 text-center">
                {transaction.status === 'success' ? (
                    /*
                     * Invoice PDF belum punya route/backend.
                     * Untuk sementara visual only.
                     */
                    <button
                        type="button"
                        disabled
                        title="Invoice download coming soon"
                        className="cursor-not-allowed text-primary opacity-40"
                    >
                        <Download className="h-5 w-5" />
                    </button>
                ) : (
                    <span className="inline-flex text-outline-variant">
                        <Ban className="h-5 w-5" />
                    </span>
                )}
            </td>
        </tr>
    );
}

function TransactionStatus({
    status,
}: {
    status: Transaction['status'];
}) {
    if (status === 'success') {
        return (
            <div className="inline-flex items-center gap-1.5 text-emerald-600">
                <CheckCircle2 className="h-4 w-4" />
                <span className="font-label-sm text-label-sm">
                    Successful
                </span>
            </div>
        );
    }

    if (status === 'failed') {
        return (
            <div className="inline-flex items-center gap-1.5 text-error">
                <XCircle className="h-4 w-4" />
                <span className="font-label-sm text-label-sm">
                    Failed
                </span>
            </div>
        );
    }

    return (
        <span className="font-label-sm text-label-sm capitalize text-on-surface-variant">
            {status}
        </span>
    );
}

function TableHeader({
    children,
    align = 'left',
}: {
    children: React.ReactNode;
    align?: 'left' | 'right' | 'center';
}) {
    return (
        <th
            className={`whitespace-nowrap px-6 py-4 font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant ${
                align === 'right'
                    ? 'text-right'
                    : align === 'center'
                      ? 'text-center'
                      : 'text-left'
            }`}
        >
            {children}
        </th>
    );
}

function PaginationButton({
    url,
    children,
}: {
    url: string | null;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            disabled={!url}
            onClick={() => {
                if (url) {
                    router.get(
                        url,
                        {},
                        {
                            preserveState: true,
                            preserveScroll: true,
                        },
                    );
                }
            }}
            className="rounded-lg border border-outline-variant px-3 py-1.5 text-on-surface transition-colors hover:bg-surface-variant disabled:cursor-not-allowed disabled:opacity-50"
        >
            {children}
        </button>
    );
}

function formatMoney(
    amount: string | number,
    currency: string,
) {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: currency.toUpperCase(),
    }).format(Number(amount));
}

function formatDate(value: string | null) {
    if (!value) {
        return {
            date: '—',
            time: '',
        };
    }

    const date = new Date(value);

    return {
        date: new Intl.DateTimeFormat('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
        }).format(date),

        time: new Intl.DateTimeFormat('en-GB', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        }).format(date),
    };
}