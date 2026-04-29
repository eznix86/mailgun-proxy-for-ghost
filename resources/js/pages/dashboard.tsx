import { Form, Head, InfiniteScroll } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';

type DeliveryEvent = {
    id: number;
    event: string;
    provider_event: string | null;
    severity: string | null;
    occurred_at: string | null;
};

type Delivery = {
    id: number;
    recipient: string;
    provider: string;
    provider_message_id: string | null;
    latest_event: string | null;
    latest_severity: string | null;
    accepted_at: string | null;
    delivered_at: string | null;
    failed_at: string | null;
    events: DeliveryEvent[];
};

type Attempt = {
    id: number;
    started_at: string | null;
    finished_at: string | null;
    error_message: string | null;
    error_class: string | null;
    deliveries: Delivery[];
};

type RequestItem = {
    id: number;
    status: string;
    created_at: string | null;
    updated_at: string | null;
    domain: string;
    subject: string;
    from: string;
    to: string;
    attempts: Attempt[];
};

type ScrollPage<T> = {
    data: T[];
};

export default function Dashboard({
    requests,
}: {
    requests: ScrollPage<RequestItem>;
}) {
    const requestItems = requests.data;
    const [selectedRequestId, setSelectedRequestId] = useState<number | null>(
        requestItems[0]?.id ?? null,
    );
    const selectedRequest =
        requestItems.find((request) => request.id === selectedRequestId) ??
        requestItems[0] ??
        null;

    return (
        <>
            <Head title="Dashboard" />
            <div className="relative isolate flex h-full flex-1 flex-col gap-6 overflow-hidden rounded-[2rem] bg-[radial-gradient(circle_at_top_left,rgba(15,118,110,0.12),transparent_30%),linear-gradient(180deg,hsl(var(--background)),hsl(var(--muted))/0.35)] p-4 md:p-6">
                <section className="relative overflow-hidden rounded-[1.75rem] border border-slate-900/80 bg-slate-950 p-6 text-white shadow-2xl shadow-slate-950/20 md:p-7">
                    <div className="absolute inset-y-0 right-0 w-2/3 bg-[radial-gradient(circle_at_top_right,rgba(45,212,191,0.22),transparent_42%),radial-gradient(circle_at_bottom_right,rgba(244,63,94,0.14),transparent_38%)]" />
                    <div className="absolute inset-0 bg-[linear-gradient(rgba(255,255,255,0.035)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,0.035)_1px,transparent_1px)] bg-[size:32px_32px] opacity-40" />

                    <div className="relative max-w-3xl">
                        <div className="mb-3 text-xs font-semibold tracking-[0.32em] text-teal-200 uppercase">
                            Operations console
                        </div>
                        <h1 className="text-3xl font-semibold tracking-tight text-white md:text-4xl">
                            Newsletter delivery control
                        </h1>
                        <p className="mt-3 max-w-2xl text-sm leading-6 text-slate-300 md:text-base">
                            Triage requests, inspect recipient-level delivery
                            traces, and retry failed downstream sends without
                            losing the queue context.
                        </p>
                    </div>

                    <div className="relative mt-6 grid gap-3 md:grid-cols-3">
                        <MetricCard
                            label="Recent requests"
                            value={requestItems.length.toString()}
                            detail="Recent proxied traffic"
                        />
                        <MetricCard
                            label="Failed requests"
                            value={requestItems
                                .filter(
                                    (request) =>
                                        request.status === 'failed' ||
                                        requestNeedsAttention(request),
                                )
                                .length.toString()}
                            detail="Need attention"
                        />
                        <MetricCard
                            label="Tracked deliveries"
                            value={requestItems
                                .flatMap((request) =>
                                    request.attempts.flatMap(
                                        (attempt) => attempt.deliveries,
                                    ),
                                )
                                .length.toString()}
                            detail="Recipient-level history"
                        />
                    </div>
                </section>

                <div className="grid min-h-0 gap-5 xl:grid-cols-[minmax(0,0.92fr)_minmax(440px,1.08fr)]">
                    {requestItems.length === 0 ? (
                        <Card className="rounded-[1.5rem] border-dashed xl:col-span-2">
                            <CardHeader>
                                <CardTitle>No newsletter traffic yet</CardTitle>
                                <CardDescription>
                                    Incoming Mailgun-compatible requests and
                                    downstream delivery history will appear
                                    here.
                                </CardDescription>
                            </CardHeader>
                        </Card>
                    ) : (
                        <>
                            <Card className="overflow-hidden rounded-[1.5rem] border-border/70 bg-card/95 shadow-xl shadow-slate-950/5 backdrop-blur">
                                <CardHeader className="border-b border-border/70 bg-muted/20 pb-4">
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <CardTitle>
                                                Transmission queue
                                            </CardTitle>
                                            <CardDescription>
                                                Select a request to inspect its
                                                delivery trace.
                                            </CardDescription>
                                        </div>
                                        <Badge
                                            variant="outline"
                                            className="rounded-full"
                                        >
                                            Live feed
                                        </Badge>
                                    </div>
                                </CardHeader>
                                <CardContent className="p-0">
                                    <InfiniteScroll
                                        data="requests"
                                        className="divide-y divide-border/70"
                                        buffer={400}
                                        loading={
                                            <div className="py-4 text-center text-sm text-muted-foreground">
                                                Loading more requests...
                                            </div>
                                        }
                                    >
                                        {requestItems.map((request) => (
                                            <RequestRow
                                                key={request.id}
                                                request={request}
                                                selected={
                                                    selectedRequest?.id ===
                                                    request.id
                                                }
                                                onSelect={() =>
                                                    setSelectedRequestId(
                                                        request.id,
                                                    )
                                                }
                                            />
                                        ))}
                                    </InfiniteScroll>
                                </CardContent>
                            </Card>

                            <RequestDetails request={selectedRequest} />
                        </>
                    )}
                </div>
            </div>
        </>
    );
}

function MetricCard({
    label,
    value,
    detail,
}: {
    label: string;
    value: string;
    detail: string;
}) {
    return (
        <Card className="rounded-[1.25rem] border-white/10 bg-white/[0.07] shadow-none backdrop-blur-md">
            <CardHeader className="gap-2 p-4">
                <CardDescription className="text-xs tracking-[0.18em] text-slate-300 uppercase">
                    {label}
                </CardDescription>
                <CardTitle className="text-3xl font-semibold text-white tabular-nums">
                    {value}
                </CardTitle>
                <p className="text-sm text-slate-400">{detail}</p>
            </CardHeader>
        </Card>
    );
}

function RequestRow({
    request,
    selected,
    onSelect,
}: {
    request: RequestItem;
    selected: boolean;
    onSelect: () => void;
}) {
    const latestAttempt = request.attempts[0];
    const totalDeliveries = request.attempts.flatMap(
        (attempt) => attempt.deliveries,
    ).length;
    const latestAttemptNeedsAttention = requestNeedsAttention(request);
    const latestEvent =
        latestAttempt?.deliveries.find((delivery) => delivery.latest_event)
            ?.latest_event ?? request.status;

    return (
        <button
            type="button"
            onClick={onSelect}
            className={cn(
                'group relative grid w-full gap-3 border-l-4 px-5 py-4 text-left transition duration-200 md:grid-cols-[1fr_auto]',
                requestAccentClassName(latestEvent),
                selected
                    ? 'bg-slate-950 text-white shadow-2xl ring-1 shadow-slate-950/20 ring-slate-800 ring-inset'
                    : 'bg-card hover:bg-slate-50/80 dark:hover:bg-muted/40',
            )}
        >
            {selected && (
                <div className="absolute inset-y-0 right-0 w-24 bg-gradient-to-l from-teal-400/10 to-transparent" />
            )}
            <div className="min-w-0 space-y-2">
                <div className="flex min-w-0 flex-wrap items-center gap-2">
                    <span
                        className={cn(
                            'font-semibold',
                            selected ? 'text-white' : 'text-foreground',
                        )}
                    >
                        Request #{request.id}
                    </span>
                    {latestAttemptNeedsAttention ? (
                        <Badge className="bg-rose-500/10 text-rose-700 dark:text-rose-200">
                            needs attention
                        </Badge>
                    ) : (
                        <StatusBadge status={request.status} />
                    )}
                    <Badge
                        variant="outline"
                        className={cn(
                            'rounded-full',
                            selected && 'border-white/15 text-slate-300',
                        )}
                    >
                        {request.domain || 'no-domain'}
                    </Badge>
                </div>
                <p
                    className={cn(
                        'line-clamp-1 text-sm font-medium',
                        selected ? 'text-slate-100' : 'text-foreground',
                    )}
                >
                    {request.subject || '(no subject)'}
                </p>
                <div
                    className={cn(
                        'flex flex-wrap gap-x-3 gap-y-1 text-xs',
                        selected ? 'text-slate-400' : 'text-muted-foreground',
                    )}
                >
                    <span>{request.attempts.length} attempts</span>
                    <span>{totalDeliveries} deliveries</span>
                    <span className="capitalize">Latest: {latestEvent}</span>
                </div>
            </div>
            <div
                className={cn(
                    'text-xs md:text-right',
                    selected ? 'text-slate-400' : 'text-muted-foreground',
                )}
            >
                <div>Updated</div>
                <div
                    className={cn(
                        'font-medium',
                        selected ? 'text-white' : 'text-foreground',
                    )}
                >
                    {formatDateTime(request.updated_at)}
                </div>
            </div>
        </button>
    );
}

function RequestDetails({ request }: { request: RequestItem | null }) {
    if (!request) {
        return (
            <Card className="rounded-[1.5rem] border-dashed">
                <CardHeader>
                    <CardTitle>Select a request</CardTitle>
                    <CardDescription>
                        Delivery history and events will appear here.
                    </CardDescription>
                </CardHeader>
            </Card>
        );
    }

    const latestAttempt = request.attempts[0];

    return (
        <Card className="overflow-hidden rounded-[1.75rem] border-border/70 bg-card/95 shadow-xl shadow-slate-950/10 backdrop-blur xl:sticky xl:top-6 xl:self-start">
            <CardHeader className="relative gap-4 overflow-hidden border-b border-slate-800 bg-slate-950 text-white">
                <div className="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(20,184,166,0.2),transparent_38%)]" />
                <div className="relative flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div className="min-w-0 space-y-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <CardTitle className="text-white">
                                Request #{request.id}
                            </CardTitle>
                            <StatusBadge status={request.status} />
                            <Badge
                                variant="outline"
                                className="rounded-full border-white/15 text-slate-300"
                            >
                                {request.domain || 'no-domain'}
                            </Badge>
                        </div>
                        <CardDescription className="line-clamp-2 text-base text-slate-200">
                            {request.subject || '(no subject)'}
                        </CardDescription>
                    </div>
                </div>

                <div className="relative grid gap-3 rounded-2xl border border-white/10 bg-white/[0.06] p-4 text-sm backdrop-blur md:grid-cols-2">
                    <Field
                        label="From"
                        value={request.from || '(unknown sender)'}
                        inverted
                    />
                    <Field
                        label="To"
                        value={request.to || '(unknown recipient)'}
                        inverted
                    />
                    <Field
                        label="Created"
                        value={formatDateTime(request.created_at)}
                        inverted
                    />
                    <Field
                        label="Updated"
                        value={formatDateTime(request.updated_at)}
                        inverted
                    />
                </div>

                {latestAttempt?.error_message && (
                    <div className="relative rounded-2xl border border-rose-300/20 bg-rose-500/15 px-4 py-3 text-sm text-rose-50">
                        <div className="font-medium">Latest failure</div>
                        <div className="mt-1">
                            {latestAttempt.error_message}
                        </div>
                        {latestAttempt.error_class && (
                            <div className="mt-1 text-xs opacity-80">
                                {latestAttempt.error_class}
                            </div>
                        )}
                    </div>
                )}
            </CardHeader>

            <CardContent className="max-h-none space-y-5 bg-[linear-gradient(180deg,hsl(var(--muted))/0.2,transparent)] p-5 xl:max-h-[calc(100vh-14rem)] xl:overflow-y-auto">
                {request.attempts.map((attempt, index) => (
                    <section
                        key={attempt.id}
                        className="relative overflow-hidden rounded-[1.5rem] border border-border/70 bg-background p-4 shadow-sm"
                    >
                        <div className="absolute inset-y-0 left-0 w-1 bg-gradient-to-b from-teal-400 via-sky-400 to-slate-300" />
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div className="text-xs font-semibold tracking-[0.2em] text-muted-foreground uppercase">
                                    Transmission attempt
                                </div>
                                <div className="mt-1 font-semibold text-foreground">
                                    Attempt {request.attempts.length - index} of{' '}
                                    {request.attempts.length}
                                </div>
                                <div className="mt-1 flex flex-wrap gap-3 text-xs text-muted-foreground">
                                    <span>
                                        Started{' '}
                                        {formatDateTime(attempt.started_at)}
                                    </span>
                                    <span>
                                        Finished{' '}
                                        {formatDateTime(attempt.finished_at)}
                                    </span>
                                    <span>
                                        {attempt.deliveries.length} deliveries
                                    </span>
                                </div>
                            </div>
                            <Badge
                                variant="outline"
                                className="rounded-full font-mono"
                            >
                                #{attempt.id}
                            </Badge>
                        </div>

                        {attempt.deliveries.length === 0 ? (
                            <p className="mt-4 rounded-xl border border-border/60 bg-muted/20 px-4 py-3 text-sm text-muted-foreground">
                                No delivery rows were created for this attempt.
                            </p>
                        ) : (
                            <div className="mt-4 space-y-3">
                                {attempt.deliveries.map((delivery) => (
                                    <DeliveryPanel
                                        key={delivery.id}
                                        requestId={request.id}
                                        delivery={delivery}
                                    />
                                ))}
                            </div>
                        )}
                    </section>
                ))}
            </CardContent>
        </Card>
    );
}

function DeliveryPanel({
    requestId,
    delivery,
}: {
    requestId: number;
    delivery: Delivery;
}) {
    const canRetry = ['failed', 'rejected'].includes(
        delivery.latest_event ?? '',
    );

    return (
        <div
            className={cn(
                'rounded-2xl border bg-card p-4 shadow-sm transition hover:shadow-md',
                deliveryAccentClassName(
                    delivery.latest_event,
                    delivery.latest_severity,
                ),
            )}
        >
            <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div className="min-w-0 space-y-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="font-medium break-all text-foreground">
                            {delivery.recipient}
                        </div>
                        {delivery.latest_event && (
                            <DeliveryEventBadge
                                event={delivery.latest_event}
                                severity={delivery.latest_severity}
                            />
                        )}
                    </div>
                    <div className="text-sm text-muted-foreground">
                        Provider {delivery.provider}
                        {delivery.provider_message_id ? (
                            <span className="font-mono text-xs">
                                {' '}
                                {' • '}
                                {delivery.provider_message_id}
                            </span>
                        ) : null}
                    </div>
                </div>

                {canRetry && (
                    <Form
                        action={`/newsletter-requests/${requestId}/retry`}
                        method="post"
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                size="sm"
                                variant="default"
                                className="rounded-full bg-slate-950 text-white hover:bg-slate-800"
                                disabled={processing}
                            >
                                Retry
                            </Button>
                        )}
                    </Form>
                )}
            </div>

            <div className="mt-4 grid gap-2 rounded-xl border border-border/60 bg-muted/20 p-3 text-xs text-muted-foreground md:grid-cols-3">
                <Field
                    label="Accepted"
                    value={formatDateTime(delivery.accepted_at)}
                />
                <Field
                    label="Delivered"
                    value={formatDateTime(delivery.delivered_at)}
                />
                <Field
                    label="Failed"
                    value={formatDateTime(delivery.failed_at)}
                />
            </div>

            <div className="mt-4 space-y-2 rounded-xl border border-border/50 bg-background/80 p-3">
                {delivery.events.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No delivery events recorded yet.
                    </p>
                ) : (
                    delivery.events.map((event) => (
                        <div
                            key={event.id}
                            className="relative flex flex-col gap-2 rounded-xl border border-border/50 bg-background px-3 py-2 pl-9 text-sm md:flex-row md:items-center md:justify-between"
                        >
                            <span
                                className={cn(
                                    'absolute top-3.5 left-3 h-2.5 w-2.5 rounded-full ring-4',
                                    eventDotClassName(
                                        event.event,
                                        event.severity,
                                    ),
                                )}
                            />
                            <div className="flex flex-wrap items-center gap-2">
                                <DeliveryEventBadge
                                    event={event.event}
                                    severity={event.severity}
                                    subtle
                                />
                                {event.provider_event && (
                                    <span className="font-mono text-xs text-muted-foreground">
                                        {event.provider_event}
                                    </span>
                                )}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                {formatDateTime(event.occurred_at)}
                            </div>
                        </div>
                    ))
                )}
            </div>
        </div>
    );
}

function Field({
    label,
    value,
    inverted = false,
}: {
    label: string;
    value: string;
    inverted?: boolean;
}) {
    return (
        <div className="min-w-0">
            <div
                className={cn(
                    'text-xs font-medium tracking-[0.14em] uppercase',
                    inverted ? 'text-slate-400' : 'text-muted-foreground',
                )}
            >
                {label}
            </div>
            <div
                className={cn(
                    'mt-1 text-sm break-words',
                    inverted ? 'text-slate-100' : 'text-foreground',
                )}
            >
                {value}
            </div>
        </div>
    );
}

function StatusBadge({ status }: { status: string }) {
    return (
        <Badge className={cn('capitalize', statusBadgeClassName(status))}>
            {status}
        </Badge>
    );
}

function DeliveryEventBadge({
    event,
    severity,
    subtle = false,
}: {
    event: string;
    severity?: string | null;
    subtle?: boolean;
}) {
    return (
        <Badge
            variant={subtle ? 'outline' : 'secondary'}
            className={cn('capitalize', eventBadgeClassName(event, severity))}
        >
            {event}
            {severity ? ` (${severity})` : ''}
        </Badge>
    );
}

function requestNeedsAttention(request: RequestItem) {
    return (
        request.attempts[0]?.deliveries.some((delivery) =>
            ['failed', 'rejected'].includes(delivery.latest_event ?? ''),
        ) ?? false
    );
}

function requestAccentClassName(event: string) {
    if (event === 'rejected' || event === 'failed') {
        return 'border-l-rose-500';
    }

    if (
        event === 'accepted' ||
        event === 'delivered' ||
        event === 'processed'
    ) {
        return 'border-l-emerald-500';
    }

    if (event === 'processing' || event === 'pending') {
        return 'border-l-amber-500';
    }

    return 'border-l-slate-300';
}

function deliveryAccentClassName(
    event: string | null,
    severity: string | null,
) {
    if (event === 'rejected' || severity === 'permanent') {
        return 'border-rose-500/40 bg-rose-500/[0.04]';
    }

    if (event === 'failed' || severity === 'temporary') {
        return 'border-amber-500/40 bg-amber-500/[0.04]';
    }

    if (
        event === 'accepted' ||
        event === 'delivered' ||
        event === 'opened' ||
        event === 'clicked'
    ) {
        return 'border-emerald-500/35 bg-emerald-500/[0.04]';
    }

    return 'border-border/60';
}

function eventDotClassName(event: string, severity?: string | null) {
    if (event === 'rejected' || severity === 'permanent') {
        return 'bg-rose-500 ring-rose-500/15';
    }

    if (event === 'failed' || severity === 'temporary') {
        return 'bg-amber-500 ring-amber-500/15';
    }

    if (
        event === 'accepted' ||
        event === 'delivered' ||
        event === 'opened' ||
        event === 'clicked'
    ) {
        return 'bg-emerald-500 ring-emerald-500/15';
    }

    return 'bg-slate-400 ring-slate-400/15';
}

function statusBadgeClassName(status: string) {
    return (
        {
            failed: 'bg-rose-500/15 text-rose-700 dark:text-rose-200',
            processed:
                'bg-emerald-500/15 text-emerald-700 dark:text-emerald-200',
            processing: 'bg-amber-500/15 text-amber-700 dark:text-amber-200',
            pending: 'bg-slate-500/15 text-slate-700 dark:text-slate-200',
        }[status] ?? 'bg-muted text-muted-foreground'
    );
}

function eventBadgeClassName(event: string, severity?: string | null) {
    if (event === 'rejected' || severity === 'permanent') {
        return 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-200';
    }

    if (event === 'failed' || severity === 'temporary') {
        return 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-200';
    }

    if (
        event === 'accepted' ||
        event === 'delivered' ||
        event === 'opened' ||
        event === 'clicked'
    ) {
        return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-200';
    }

    if (event === 'complained') {
        return 'border-violet-500/30 bg-violet-500/10 text-violet-700 dark:text-violet-200';
    }

    return 'border-border bg-muted text-muted-foreground';
}

function formatDateTime(value: string | null) {
    if (!value) {
        return 'not yet';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
