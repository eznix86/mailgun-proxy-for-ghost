import { Head, Link } from '@inertiajs/react';
import { Activity, ArrowLeft, CheckCircle2, ShieldAlert } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { dashboard, health } from '@/routes';

type HealthStatus = {
    state: 'ok' | 'warning';
    updated_at: string;
};

type Signal = {
    signal: string;
    value: string;
};

type Check = {
    check: string;
    status: 'ok' | 'warn';
    detail: string;
};

export default function Health({
    status,
    signals,
    checks,
}: {
    status: HealthStatus;
    signals: Signal[];
    checks: Check[];
}) {
    return (
        <>
            <Head title="Health" />
            <div className="min-h-svh flex-1 bg-[#f4f5f6] p-3 text-[#141619] md:p-5 dark:bg-background dark:text-foreground">
                <div className="rounded-2xl bg-white shadow-sm ring-1 ring-black/5 dark:bg-card dark:ring-white/10">
                    <div className="flex flex-col gap-5 px-5 py-6 md:px-8">
                        <header className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div className="space-y-2">
                                <h1 className="text-2xl font-semibold tracking-tight text-pretty md:text-3xl">
                                    Health
                                </h1>
                                <p className="text-sm text-muted-foreground">
                                    Configuration and delivery pipeline
                                    diagnostics.
                                </p>
                            </div>

                            <Button variant="outline" asChild>
                                <Link href={dashboard()} prefetch>
                                    <ArrowLeft aria-hidden />
                                    Back to dashboard
                                </Link>
                            </Button>
                        </header>

                        <section className="grid gap-4 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
                            <Card className="rounded-xl border-[#e5e9eb] shadow-none dark:border-border">
                                <CardHeader className="flex-row items-start justify-between gap-4">
                                    <div className="space-y-2">
                                        <div className="flex items-center gap-2">
                                            {status.state === 'ok' ? (
                                                <CheckCircle2
                                                    className="size-5 text-emerald-600"
                                                    aria-hidden
                                                />
                                            ) : (
                                                <ShieldAlert
                                                    className="size-5 text-amber-600"
                                                    aria-hidden
                                                />
                                            )}
                                            <CardTitle>System status</CardTitle>
                                        </div>
                                        <CardDescription>
                                            Last updated{' '}
                                            {formatDateTime(status.updated_at)}
                                        </CardDescription>
                                    </div>
                                    <StatusBadge status={status.state} />
                                </CardHeader>
                                <CardContent>
                                    <p className="text-sm text-muted-foreground">
                                        {status.state === 'ok'
                                            ? 'No delivery risks detected in the current checks.'
                                            : 'One or more checks need attention before delivery is fully healthy.'}
                                    </p>
                                </CardContent>
                            </Card>

                            <Card className="rounded-xl border-[#e5e9eb] shadow-none dark:border-border">
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <Activity
                                            className="size-5 text-muted-foreground"
                                            aria-hidden
                                        />
                                        Integration checks
                                    </CardTitle>
                                    <CardDescription>
                                        Warnings identify configuration or
                                        delivery risks.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Check</TableHead>
                                                <TableHead>Status</TableHead>
                                                <TableHead>Detail</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {checks.map((check) => (
                                                <TableRow key={check.check}>
                                                    <TableCell className="font-medium">
                                                        {check.check}
                                                    </TableCell>
                                                    <TableCell>
                                                        <StatusBadge
                                                            status={
                                                                check.status
                                                            }
                                                        />
                                                    </TableCell>
                                                    <TableCell className="whitespace-normal text-muted-foreground">
                                                        {check.detail}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </CardContent>
                            </Card>
                        </section>

                        <Card className="rounded-xl border-[#e5e9eb] shadow-none dark:border-border">
                            <CardHeader>
                                <CardTitle>Core signals</CardTitle>
                                <CardDescription>
                                    Runtime configuration and delivery counters.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Signal</TableHead>
                                            <TableHead>Value</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {signals.map((signal) => (
                                            <TableRow key={signal.signal}>
                                                <TableCell className="font-medium">
                                                    {signal.signal}
                                                </TableCell>
                                                <TableCell className="font-mono text-sm whitespace-normal text-muted-foreground">
                                                    {signal.value}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </>
    );
}

function StatusBadge({ status }: { status: string }) {
    return (
        <Badge className={cn('uppercase', statusBadgeClassName(status))}>
            {status}
        </Badge>
    );
}

function statusBadgeClassName(status: string) {
    return (
        {
            ok: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-200',
            warn: 'bg-amber-500/10 text-amber-700 dark:text-amber-200',
            warning: 'bg-amber-500/10 text-amber-700 dark:text-amber-200',
        }[status] ?? 'bg-muted text-muted-foreground'
    );
}

function formatDateTime(value: string) {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

Health.layout = {
    breadcrumbs: [
        {
            title: 'Health',
            href: health(),
        },
    ],
};
