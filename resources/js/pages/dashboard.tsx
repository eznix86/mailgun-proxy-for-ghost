import { Form, Head, InfiniteScroll, Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArchiveX,
    CalendarDays,
    Eye,
    Globe2,
    Mail,
    Send,
    ShieldAlert,
} from 'lucide-react';
import { useState } from 'react';
import {
    Area,
    AreaChart,
    CartesianGrid,
    Line,
    LineChart,
    XAxis,
    YAxis,
} from 'recharts';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { ChartConfig } from '@/components/ui/chart';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';
import { dashboard, health } from '@/routes';
import { retry } from '@/routes/newsletter-requests';

type SummaryMetric = {
    label: string;
    value: string;
    detail: string;
    tone: 'neutral' | 'info' | 'success' | 'warning' | 'danger';
};

type AlertItem = {
    message: string;
    level: 'ok' | 'warning' | 'danger';
};

type RecentFailure = {
    time: string | null;
    event: string;
    recipient: string;
    reason: string;
};

type DeliveryTimelinePoint = {
    date: string;
    sent: number;
    delivered: number;
    opened: number;
    clicked: number;
    failed: number;
    open_rate: number;
    click_rate: number;
    failure_rate: number;
};

type DashboardMetric = SummaryMetric & {
    chartKey?: keyof DeliveryTimelinePoint;
    targetTab: 'delivery' | 'failures' | 'suppressions';
};

type DeliveryReport = {
    metrics: SummaryMetric[];
    timeline: DeliveryTimelinePoint[];
};

type FailureReason = {
    reason: string;
    count: number;
};

type SuppressionRow = {
    email: string;
    type: string;
    reason: string;
    created_at: string | null;
};

type SuppressionReport = {
    metrics: SummaryMetric[];
    rows: SuppressionRow[];
};

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
    summary,
    alerts,
    recentFailures,
    delivery,
    failureReasons,
    suppressions,
    requests,
}: {
    summary: SummaryMetric[];
    alerts: AlertItem[];
    recentFailures: RecentFailure[];
    delivery: DeliveryReport;
    failureReasons: FailureReason[];
    suppressions: SuppressionReport;
    requests: ScrollPage<RequestItem>;
}) {
    const requestItems = requests.data;
    const [selectedRequestId, setSelectedRequestId] = useState<number | null>(
        null,
    );
    const [activeTab, setActiveTab] = useState('overview');
    const selectedRequest =
        requestItems.find((request) => request.id === selectedRequestId) ??
        null;
    const suppressionTotal = suppressions.metrics.reduce(
        (total, metric) => total + Number.parseInt(metric.value, 10),
        0,
    );
    const metrics = dashboardMetrics(summary, suppressionTotal);

    return (
        <>
            <Head title="Dashboard" />
            <div className="min-h-svh flex-1 bg-[#f4f5f6] p-3 text-[#141619] md:p-5 dark:bg-background dark:text-foreground">
                <div className="rounded-2xl bg-white shadow-sm ring-1 ring-black/5 dark:bg-card dark:ring-white/10">
                    <div className="flex flex-col gap-5 px-5 py-6 md:px-8">
                        <header className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div className="space-y-2">
                                <h1 className="text-2xl font-semibold tracking-tight text-pretty md:text-3xl">
                                    Email delivery
                                </h1>
                                <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                    <Globe2 className="size-4" aria-hidden />
                                    <span>
                                        Newsletter proxy delivery telemetry
                                    </span>
                                </div>
                            </div>

                            <div className="flex flex-wrap items-center gap-2">
                                <Button variant="outline" asChild>
                                    <Link href={health()} prefetch>
                                        <Activity aria-hidden />
                                        Open health checks
                                    </Link>
                                </Button>
                                <div className="flex items-center gap-2">
                                    <span className="sr-only">Period</span>
                                    <Select defaultValue="30d">
                                        <SelectTrigger
                                            aria-label="Select period"
                                            className="h-9 w-[168px] rounded-lg bg-white dark:bg-background"
                                        >
                                            <CalendarDays
                                                className="size-4 text-muted-foreground"
                                                aria-hidden
                                            />
                                            <SelectValue aria-label="Selected period" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="24h">
                                                Last 24 hours
                                            </SelectItem>
                                            <SelectItem value="7d">
                                                Last 7 days
                                            </SelectItem>
                                            <SelectItem value="30d">
                                                Last 30 days
                                            </SelectItem>
                                            <SelectItem value="90d">
                                                Last 90 days
                                            </SelectItem>
                                            <SelectItem value="ytd">
                                                Year to date
                                            </SelectItem>
                                            <SelectItem value="12m">
                                                Last 12 months
                                            </SelectItem>
                                            <SelectItem value="all">
                                                All time
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                        </header>

                        <Tabs
                            value={activeTab}
                            onValueChange={setActiveTab}
                            className="gap-6"
                        >
                            <TabsList
                                variant="line"
                                className="flex h-auto w-full flex-wrap justify-start gap-2 p-0"
                            >
                                <TabsTrigger value="overview">
                                    <Activity aria-hidden />
                                    Overview
                                </TabsTrigger>
                                <TabsTrigger value="delivery">
                                    <Mail aria-hidden />
                                    Delivery
                                </TabsTrigger>
                                <TabsTrigger value="failures">
                                    <ShieldAlert aria-hidden />
                                    Failures & complaints
                                </TabsTrigger>
                                <TabsTrigger value="suppressions">
                                    <ArchiveX aria-hidden />
                                    Suppressions
                                </TabsTrigger>
                            </TabsList>

                            <TabsContent value="overview" className="space-y-6">
                                <section className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                                    {metrics.map((metric) => (
                                        <MetricCard
                                            key={metric.label}
                                            metric={metric}
                                            chartData={delivery.timeline}
                                            onViewMore={() =>
                                                setActiveTab(metric.targetTab)
                                            }
                                        />
                                    ))}
                                </section>

                                <section className="grid gap-4 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
                                    <StatusAlerts alerts={alerts} />
                                    <RecentFailures failures={recentFailures} />
                                </section>

                                <RequestQueue
                                    requests={requestItems}
                                    onSelect={setSelectedRequestId}
                                    title="Recent requests"
                                    description="Latest request batches for trace inspection."
                                />
                            </TabsContent>

                            <TabsContent value="delivery" className="space-y-6">
                                <DeliveryTab delivery={delivery} />
                            </TabsContent>

                            <TabsContent value="failures" className="space-y-6">
                                <FailuresTab
                                    timeline={delivery.timeline}
                                    reasons={failureReasons}
                                    failures={recentFailures}
                                />
                            </TabsContent>

                            <TabsContent
                                value="suppressions"
                                className="space-y-6"
                            >
                                <SuppressionsTab suppressions={suppressions} />
                            </TabsContent>
                        </Tabs>
                    </div>
                </div>

                <Sheet
                    open={selectedRequest !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            setSelectedRequestId(null);
                        }
                    }}
                >
                    <SheetContent className="w-full overflow-y-auto p-0 sm:max-w-3xl">
                        {selectedRequest && (
                            <>
                                <SheetHeader className="border-b px-6 py-5 text-left">
                                    <SheetTitle>
                                        Request #{selectedRequest.id}
                                    </SheetTitle>
                                    <SheetDescription>
                                        Delivery attempts, recipients, and event
                                        timeline for this request.
                                    </SheetDescription>
                                </SheetHeader>
                                <RequestDetails request={selectedRequest} />
                            </>
                        )}
                    </SheetContent>
                </Sheet>
            </div>
        </>
    );
}

function MetricCard({
    metric,
    chartData,
    chartKey,
    onViewMore,
}: {
    metric: SummaryMetric | DashboardMetric;
    chartData?: DeliveryTimelinePoint[];
    chartKey?: keyof DeliveryTimelinePoint;
    onViewMore?: () => void;
}) {
    const activeChartKey =
        chartKey ?? ('chartKey' in metric ? metric.chartKey : undefined);

    return (
        <Card className="overflow-hidden rounded-xl border-[#e5e9eb] shadow-none transition-[border-color,box-shadow] hover:border-[#d7dde1] hover:shadow-sm dark:border-border">
            <CardHeader className="gap-4 pb-3">
                <div className="flex items-center justify-between gap-3">
                    <CardDescription className="flex items-center gap-2">
                        <span
                            className={cn(
                                'size-2 rounded-full',
                                toneDotClassName(metric.tone),
                            )}
                        />
                        {metric.label}
                    </CardDescription>
                    {onViewMore ? (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="h-8 rounded-lg px-3 text-xs"
                            onClick={onViewMore}
                        >
                            View more
                        </Button>
                    ) : null}
                    {!onViewMore ? <MetricIcon tone={metric.tone} /> : null}
                </div>
                <CardTitle className="text-3xl font-semibold tabular-nums">
                    {metric.value}
                </CardTitle>
                <p className="text-sm text-muted-foreground">{metric.detail}</p>
            </CardHeader>
            <CardContent className="pt-0">
                {chartData && activeChartKey ? (
                    <MetricAreaChart
                        data={chartData}
                        dataKey={activeChartKey}
                        tone={metric.tone}
                    />
                ) : (
                    <MetricEmptyChart tone={metric.tone} />
                )}
            </CardContent>
        </Card>
    );
}

function DeliveryTab({ delivery }: { delivery: DeliveryReport }) {
    return (
        <>
            <section className="grid gap-4 md:grid-cols-3">
                {delivery.metrics.map((metric) => (
                    <MetricCard
                        key={metric.label}
                        metric={metric}
                        chartData={delivery.timeline}
                        chartKey={deliveryMetricChartKey(metric.label)}
                    />
                ))}
            </section>

            <Card className="rounded-xl border-[#e5e9eb] shadow-none dark:border-border">
                <CardHeader>
                    <CardTitle>Timeline</CardTitle>
                    <CardDescription>
                        Daily sent, opened, clicked, and failed delivery
                        signals.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-5">
                    <DeliveryLineChart data={delivery.timeline} />
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Date</TableHead>
                                <TableHead>Sent</TableHead>
                                <TableHead>Open rate</TableHead>
                                <TableHead>Click rate</TableHead>
                                <TableHead>Failure rate</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {delivery.timeline.map((point) => (
                                <TableRow key={point.date}>
                                    <TableCell>{point.date}</TableCell>
                                    <TableCell>{point.sent}</TableCell>
                                    <TableCell>{point.open_rate}%</TableCell>
                                    <TableCell>{point.click_rate}%</TableCell>
                                    <TableCell>{point.failure_rate}%</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </>
    );
}

function FailuresTab({
    timeline,
    reasons,
    failures,
}: {
    timeline: DeliveryTimelinePoint[];
    reasons: FailureReason[];
    failures: RecentFailure[];
}) {
    return (
        <>
            <Card className="rounded-xl border-[#e5e9eb] shadow-none dark:border-border">
                <CardHeader>
                    <CardTitle>Failure and complaint rate</CardTitle>
                    <CardDescription>
                        Failed, rejected, and complaint signals across the last
                        30 days.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <FailureLineChart data={timeline} />
                </CardContent>
            </Card>

            <section className="grid gap-4 xl:grid-cols-[minmax(0,0.75fr)_minmax(0,1.25fr)]">
                <FailureReasons reasons={reasons} />
                <RecentFailures
                    failures={failures}
                    title="Recent failures and complaints"
                />
            </section>
        </>
    );
}

function FailureReasons({ reasons }: { reasons: FailureReason[] }) {
    return (
        <Card className="rounded-xl border-[#e5e9eb] shadow-none dark:border-border">
            <CardHeader>
                <CardTitle>Failure reasons</CardTitle>
                <CardDescription>
                    Grouped causes from provider events.
                </CardDescription>
            </CardHeader>
            <CardContent>
                {reasons.length === 0 ? (
                    <p className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                        No failure reasons recorded.
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Reason</TableHead>
                                <TableHead>Count</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {reasons.map((reason) => (
                                <TableRow key={reason.reason}>
                                    <TableCell>{reason.reason}</TableCell>
                                    <TableCell>{reason.count}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}

function SuppressionsTab({
    suppressions,
}: {
    suppressions: SuppressionReport;
}) {
    return (
        <>
            <section className="grid gap-4 md:grid-cols-3">
                {suppressions.metrics.map((metric) => (
                    <MetricCard key={metric.label} metric={metric} />
                ))}
            </section>

            <Card className="rounded-xl border-[#e5e9eb] shadow-none dark:border-border">
                <CardHeader>
                    <CardTitle>Suppression list</CardTitle>
                    <CardDescription>
                        Recipients that bounced, complained, or opted out.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {suppressions.rows.length === 0 ? (
                        <p className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                            No suppression events are currently recorded.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Email</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Reason</TableHead>
                                    <TableHead>Created</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {suppressions.rows.map((row) => (
                                    <TableRow
                                        key={`${row.email}-${row.type}-${row.created_at}`}
                                    >
                                        <TableCell className="font-mono text-xs">
                                            {row.email}
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge status={row.type} />
                                        </TableCell>
                                        <TableCell>{row.reason}</TableCell>
                                        <TableCell>
                                            {formatDateTime(row.created_at)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>
        </>
    );
}

function MetricAreaChart({
    data,
    dataKey,
    tone,
}: {
    data: DeliveryTimelinePoint[];
    dataKey: keyof DeliveryTimelinePoint;
    tone: SummaryMetric['tone'];
}) {
    const color = chartColor(tone);
    const config = {
        [dataKey]: {
            label: String(dataKey),
            color,
        },
    } satisfies ChartConfig;

    return (
        <ChartContainer config={config} className="aspect-auto h-28">
            <AreaChart
                data={data}
                margin={{ left: 0, right: 0, top: 8, bottom: 0 }}
            >
                <defs>
                    <linearGradient
                        id={`fill-${String(dataKey)}`}
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >
                        <stop
                            offset="5%"
                            stopColor={color}
                            stopOpacity={0.28}
                        />
                        <stop
                            offset="95%"
                            stopColor={color}
                            stopOpacity={0.02}
                        />
                    </linearGradient>
                </defs>
                <CartesianGrid vertical={false} />
                <XAxis
                    dataKey="date"
                    tickLine={false}
                    axisLine={false}
                    interval="preserveStartEnd"
                />
                <ChartTooltip content={<ChartTooltipContent />} />
                <Area
                    dataKey={dataKey as string}
                    type="monotone"
                    stroke={color}
                    fill={`url(#fill-${String(dataKey)})`}
                    strokeWidth={2}
                />
            </AreaChart>
        </ChartContainer>
    );
}

function MetricEmptyChart({ tone }: { tone: SummaryMetric['tone'] }) {
    return (
        <div className="relative h-28 overflow-hidden rounded-lg">
            <div className="absolute inset-x-0 top-8 border-t border-[#e5e9eb] dark:border-border" />
            <div
                className={cn(
                    'absolute right-0 bottom-9 left-0 h-px',
                    toneLineClassName(tone),
                )}
            />
            <div className="absolute inset-x-0 bottom-0 flex justify-between text-xs text-muted-foreground">
                <span>Live</span>
                <span>No trend yet</span>
            </div>
        </div>
    );
}

function DeliveryLineChart({ data }: { data: DeliveryTimelinePoint[] }) {
    const config = {
        sent: { label: 'Sent', color: 'var(--chart-2)' },
        opened: { label: 'Opened', color: 'var(--chart-1)' },
        clicked: { label: 'Clicked', color: 'var(--chart-3)' },
        failed: { label: 'Failed', color: 'var(--destructive)' },
    } satisfies ChartConfig;

    return (
        <ChartContainer config={config} className="aspect-auto h-72">
            <LineChart data={data} margin={{ left: 12, right: 12 }}>
                <CartesianGrid vertical={false} />
                <XAxis
                    dataKey="date"
                    tickLine={false}
                    axisLine={false}
                    interval="preserveStartEnd"
                />
                <YAxis tickLine={false} axisLine={false} />
                <ChartTooltip content={<ChartTooltipContent />} />
                <Line
                    dataKey="sent"
                    type="monotone"
                    stroke="var(--color-sent)"
                    strokeWidth={2}
                    dot={false}
                />
                <Line
                    dataKey="opened"
                    type="monotone"
                    stroke="var(--color-opened)"
                    strokeWidth={2}
                    dot={false}
                />
                <Line
                    dataKey="clicked"
                    type="monotone"
                    stroke="var(--color-clicked)"
                    strokeWidth={2}
                    dot={false}
                />
                <Line
                    dataKey="failed"
                    type="monotone"
                    stroke="var(--color-failed)"
                    strokeWidth={2}
                    dot={false}
                />
            </LineChart>
        </ChartContainer>
    );
}

function FailureLineChart({ data }: { data: DeliveryTimelinePoint[] }) {
    const config = {
        failure_rate: { label: 'Failure rate', color: 'var(--destructive)' },
    } satisfies ChartConfig;

    return (
        <ChartContainer config={config} className="aspect-auto h-72">
            <AreaChart data={data} margin={{ left: 12, right: 12 }}>
                <defs>
                    <linearGradient
                        id="fill-failure-rate"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >
                        <stop
                            offset="5%"
                            stopColor="var(--destructive)"
                            stopOpacity={0.28}
                        />
                        <stop
                            offset="95%"
                            stopColor="var(--destructive)"
                            stopOpacity={0.02}
                        />
                    </linearGradient>
                </defs>
                <CartesianGrid vertical={false} />
                <XAxis
                    dataKey="date"
                    tickLine={false}
                    axisLine={false}
                    interval="preserveStartEnd"
                />
                <YAxis tickLine={false} axisLine={false} />
                <ChartTooltip content={<ChartTooltipContent />} />
                <Area
                    dataKey="failure_rate"
                    type="monotone"
                    stroke="var(--color-failure_rate)"
                    fill="url(#fill-failure-rate)"
                    strokeWidth={2}
                />
            </AreaChart>
        </ChartContainer>
    );
}

function StatusAlerts({ alerts }: { alerts: AlertItem[] }) {
    return (
        <Card className="rounded-xl border-[#e5e9eb] shadow-none dark:border-border">
            <CardHeader className="flex-row items-start justify-between gap-4">
                <div>
                    <CardTitle>Status and alerts</CardTitle>
                    <CardDescription>
                        Current delivery conditions that may need attention.
                    </CardDescription>
                </div>
                <Badge variant="outline">Updated now</Badge>
            </CardHeader>
            <CardContent className="space-y-3">
                {alerts.map((alert) => (
                    <div
                        key={alert.message}
                        className="flex items-center justify-between gap-3 text-sm"
                    >
                        <span>{alert.message}</span>
                        <StatusBadge status={alert.level} />
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}

function RecentFailures({
    failures,
    title = 'Recent failures preview',
}: {
    failures: RecentFailure[];
    title?: string;
}) {
    return (
        <Card className="rounded-xl border-[#e5e9eb] shadow-none dark:border-border">
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                <CardDescription>
                    Latest failed, rejected, or complained recipient events.
                </CardDescription>
            </CardHeader>
            <CardContent>
                {failures.length === 0 ? (
                    <p className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                        No failure or complaint events are currently recorded.
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Time</TableHead>
                                <TableHead>Event</TableHead>
                                <TableHead>Recipient</TableHead>
                                <TableHead>Reason</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {failures.map((failure) => (
                                <TableRow
                                    key={`${failure.event}-${failure.recipient}-${failure.time}`}
                                >
                                    <TableCell className="whitespace-nowrap text-muted-foreground">
                                        {formatDateTime(failure.time)}
                                    </TableCell>
                                    <TableCell>
                                        <DeliveryEventBadge
                                            event={failure.event}
                                        />
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {failure.recipient}
                                    </TableCell>
                                    <TableCell>{failure.reason}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}

function RequestQueue({
    requests,
    onSelect,
    title = 'Requests',
    description = 'Inspect individual newsletter requests without crowding the delivery overview.',
}: {
    requests: RequestItem[];
    onSelect: (id: number) => void;
    title?: string;
    description?: string;
}) {
    return (
        <Card className="overflow-hidden rounded-xl border-[#e5e9eb] shadow-none dark:border-border">
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardContent className="p-0">
                {requests.length === 0 ? (
                    <p className="p-6 text-sm text-muted-foreground">
                        Incoming Mailgun-compatible requests will appear here.
                    </p>
                ) : (
                    <InfiniteScroll
                        data="requests"
                        buffer={400}
                        loading={
                            <p className="p-4 text-center text-sm text-muted-foreground">
                                Loading more requests…
                            </p>
                        }
                    >
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Request</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Domain</TableHead>
                                    <TableHead>Subject</TableHead>
                                    <TableHead>Deliveries</TableHead>
                                    <TableHead>Latest</TableHead>
                                    <TableHead>Updated</TableHead>
                                    <TableHead className="text-right">
                                        Action
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {requests.map((request) => (
                                    <RequestRow
                                        key={request.id}
                                        request={request}
                                        onSelect={() => onSelect(request.id)}
                                    />
                                ))}
                            </TableBody>
                        </Table>
                    </InfiniteScroll>
                )}
            </CardContent>
        </Card>
    );
}

function RequestRow({
    request,
    onSelect,
}: {
    request: RequestItem;
    onSelect: () => void;
}) {
    const deliveries = request.attempts.flatMap(
        (attempt) => attempt.deliveries,
    );
    const latestEvent =
        deliveries.find((delivery) => delivery.latest_event)?.latest_event ??
        request.status;

    return (
        <TableRow>
            <TableCell className="font-medium">#{request.id}</TableCell>
            <TableCell>
                <StatusBadge status={request.status} />
            </TableCell>
            <TableCell>
                <Badge variant="outline">{request.domain || 'no-domain'}</Badge>
            </TableCell>
            <TableCell className="max-w-[24rem] truncate">
                {request.subject || '(no subject)'}
            </TableCell>
            <TableCell>{deliveries.length}</TableCell>
            <TableCell className="capitalize">{latestEvent}</TableCell>
            <TableCell className="text-muted-foreground">
                {formatDateTime(request.updated_at)}
            </TableCell>
            <TableCell className="text-right">
                <Button variant="outline" size="sm" onClick={onSelect}>
                    <Eye />
                    View
                </Button>
            </TableCell>
        </TableRow>
    );
}

function RequestDetails({ request }: { request: RequestItem | null }) {
    if (!request) {
        return null;
    }

    return (
        <div className="space-y-5 p-6">
            <section className="space-y-4">
                <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div className="min-w-0 space-y-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="text-lg font-semibold">
                                Request #{request.id}
                            </h2>
                            <StatusBadge status={request.status} />
                            <Badge variant="outline">
                                {request.domain || 'no-domain'}
                            </Badge>
                        </div>
                        <CardDescription className="line-clamp-2 text-base">
                            {request.subject || '(no subject)'}
                        </CardDescription>
                    </div>
                </div>
                <div className="grid gap-3 rounded-lg border bg-muted/20 p-4 text-sm md:grid-cols-2">
                    <Field
                        label="From"
                        value={request.from || '(unknown sender)'}
                    />
                    <Field
                        label="To"
                        value={request.to || '(unknown recipient)'}
                    />
                    <Field
                        label="Created"
                        value={formatDateTime(request.created_at)}
                    />
                    <Field
                        label="Updated"
                        value={formatDateTime(request.updated_at)}
                    />
                </div>
            </section>

            <div className="space-y-4">
                {request.attempts.map((attempt, index) => (
                    <section key={attempt.id} className="rounded-lg border p-4">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    Transmission attempt
                                </p>
                                <h3 className="mt-1 font-semibold">
                                    Attempt {request.attempts.length - index} of{' '}
                                    {request.attempts.length}
                                </h3>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Started {formatDateTime(attempt.started_at)}{' '}
                                    · Finished{' '}
                                    {formatDateTime(attempt.finished_at)} ·{' '}
                                    {attempt.deliveries.length} deliveries
                                </p>
                            </div>
                            <Badge variant="outline">#{attempt.id}</Badge>
                        </div>

                        {attempt.error_message && (
                            <div className="mt-4 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm">
                                <div className="font-medium">
                                    Latest failure
                                </div>
                                <p className="mt-1 text-muted-foreground">
                                    {attempt.error_message}
                                </p>
                            </div>
                        )}

                        <div className="mt-4 space-y-3">
                            {attempt.deliveries.map((delivery) => (
                                <DeliveryPanel
                                    key={delivery.id}
                                    requestId={request.id}
                                    delivery={delivery}
                                />
                            ))}
                        </div>
                    </section>
                ))}
            </div>
        </div>
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
        <div className="rounded-lg border bg-card p-4">
            <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div className="min-w-0 space-y-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="font-medium break-all">
                            {delivery.recipient}
                        </div>
                        {delivery.latest_event && (
                            <DeliveryEventBadge
                                event={delivery.latest_event}
                                severity={delivery.latest_severity}
                            />
                        )}
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Provider {delivery.provider}
                        {delivery.provider_message_id ? (
                            <span className="font-mono text-xs">
                                {' '}
                                · {delivery.provider_message_id}
                            </span>
                        ) : null}
                    </p>
                </div>
                {canRetry && (
                    <Form
                        {...retry.form(requestId)}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button size="sm" disabled={processing}>
                                Retry
                            </Button>
                        )}
                    </Form>
                )}
            </div>

            <div className="mt-4 grid gap-2 rounded-md border bg-muted/20 p-3 text-xs md:grid-cols-3">
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

            <div className="mt-4 space-y-2">
                {delivery.events.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No delivery events recorded yet.
                    </p>
                ) : (
                    delivery.events.map((event) => (
                        <div
                            key={event.id}
                            className="grid gap-2 rounded-md border px-3 py-2 text-sm md:grid-cols-[1fr_auto] md:items-center"
                        >
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

function Field({ label, value }: { label: string; value: string }) {
    return (
        <div className="min-w-0">
            <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </div>
            <div className="mt-1 break-words">{value}</div>
        </div>
    );
}

function MetricIcon({ tone }: { tone: SummaryMetric['tone'] }) {
    const className = cn(
        'size-4 text-muted-foreground',
        tone === 'danger' && 'text-destructive',
        tone === 'warning' && 'text-amber-600',
        tone === 'success' && 'text-emerald-600',
    );

    if (tone === 'danger') {
        return <ShieldAlert className={className} />;
    }

    if (tone === 'warning') {
        return <AlertTriangle className={className} />;
    }

    if (tone === 'success') {
        return <Send className={className} />;
    }

    return <Mail className={className} />;
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

function toneDotClassName(tone: SummaryMetric['tone']) {
    return {
        neutral: 'bg-slate-300',
        info: 'bg-sky-500',
        success: 'bg-emerald-500',
        warning: 'bg-amber-500',
        danger: 'bg-rose-500',
    }[tone];
}

function toneLineClassName(tone: SummaryMetric['tone']) {
    return {
        neutral: 'bg-slate-300',
        info: 'bg-sky-500',
        success: 'bg-sky-500',
        warning: 'bg-amber-400',
        danger: 'bg-rose-500',
    }[tone];
}

function chartColor(tone: SummaryMetric['tone']) {
    return {
        neutral: 'var(--chart-3)',
        info: 'var(--chart-2)',
        success: 'var(--chart-2)',
        warning: 'var(--chart-4)',
        danger: 'var(--destructive)',
    }[tone];
}

function deliveryMetricChartKey(
    label: string,
): keyof DeliveryTimelinePoint | undefined {
    return {
        Delivered: 'delivered',
        'Avg. open rate': 'open_rate',
        'Avg. click rate': 'click_rate',
    }[label] as keyof DeliveryTimelinePoint | undefined;
}

function dashboardMetrics(
    summary: SummaryMetric[],
    suppressionTotal: number,
): DashboardMetric[] {
    const byLabel = new Map(summary.map((metric) => [metric.label, metric]));
    const queued = byLabel.get('Queued requests');
    const processing = byLabel.get('Processing');
    const delivered = byLabel.get('Tracked deliveries');
    const failureRate = byLabel.get('Failure rate');

    return [
        {
            label: 'Queued batches',
            value: queued?.value ?? '0',
            detail: queued?.detail ?? 'Waiting for the first send attempt',
            tone: queued?.tone ?? 'neutral',
            targetTab: 'delivery',
        },
        {
            label: 'Processing',
            value: processing?.value ?? '0',
            detail: processing?.detail ?? 'Attempts currently open',
            tone: processing?.tone ?? 'info',
            targetTab: 'delivery',
        },
        {
            label: 'Sent',
            value: delivered?.value ?? '0',
            detail: delivered?.detail ?? 'Recipient-level delivery rows',
            tone: delivered?.tone ?? 'success',
            chartKey: 'sent',
            targetTab: 'delivery',
        },
        {
            label: 'Failure rate',
            value: failureRate?.value ?? '0%',
            detail: failureRate?.detail ?? 'No failed or rejected deliveries',
            tone: failureRate?.tone ?? 'success',
            chartKey: 'failure_rate',
            targetTab: 'failures',
        },
        {
            label: 'Suppressions',
            value: String(suppressionTotal),
            detail: 'Bounce, complaint, and opt-out events',
            tone: suppressionTotal > 0 ? 'warning' : 'neutral',
            targetTab: 'suppressions',
        },
    ];
}

function statusBadgeClassName(status: string) {
    return (
        {
            ok: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-200',
            warning: 'bg-amber-500/10 text-amber-700 dark:text-amber-200',
            danger: 'bg-rose-500/10 text-rose-700 dark:text-rose-200',
            failed: 'bg-rose-500/10 text-rose-700 dark:text-rose-200',
            processed:
                'bg-emerald-500/10 text-emerald-700 dark:text-emerald-200',
            processing: 'bg-amber-500/10 text-amber-700 dark:text-amber-200',
            pending: 'bg-slate-500/10 text-slate-700 dark:text-slate-200',
            bounces: 'bg-rose-500/10 text-rose-700 dark:text-rose-200',
            complaints: 'bg-violet-500/10 text-violet-700 dark:text-violet-200',
            unsubscribes: 'bg-slate-500/10 text-slate-700 dark:text-slate-200',
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

    if (event === 'complained') {
        return 'border-violet-500/30 bg-violet-500/10 text-violet-700 dark:text-violet-200';
    }

    if (['accepted', 'delivered', 'opened', 'clicked'].includes(event)) {
        return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-200';
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
