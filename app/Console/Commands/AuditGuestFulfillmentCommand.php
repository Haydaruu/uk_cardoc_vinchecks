<?php

namespace App\Console\Commands;

use App\Models\GuestReportPurchase;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

/**
 * Read-only operator audit of guest fulfillment state.
 *
 * This command is deliberately side-effect free: it only reads rows and prints
 * them. It never inserts, updates, deletes, dispatches a job, or claims a lease.
 *
 * A `processing` row is NOT assumed to be orphaned. The command exposes the
 * dispatch/claim expiry and attempt counters so an operator can decide.
 */
class AuditGuestFulfillmentCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'guest-fulfillment:audit
                            {--status= : Restrict to one fulfillment status (locked|pending|processing|failed)}
                            {--older-than= : Only include rows created at least this many hours ago}
                            {--limit= : Maximum number of rows to return}
                            {--json : Emit deterministic JSON instead of a table}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Read-only audit of paid guest purchases that are not yet fulfilled';

    /**
     * Statuses that are eligible for audit. `completed` is intentionally absent.
     *
     * @var array<int, string>
     */
    private const AUDITABLE_STATUSES = [
        GuestReportPurchase::FULFILLMENT_LOCKED,
        GuestReportPurchase::FULFILLMENT_PENDING,
        GuestReportPurchase::FULFILLMENT_PROCESSING,
        GuestReportPurchase::FULFILLMENT_FAILED,
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $status = $this->option('status');
        $olderThan = $this->option('older-than');
        $limit = $this->option('limit');

        if ($status !== null && ! in_array($status, self::AUDITABLE_STATUSES, true)) {
            $this->error(sprintf(
                'Invalid --status [%s]. Allowed values: %s.',
                $status,
                implode(', ', self::AUDITABLE_STATUSES),
            ));

            return self::FAILURE;
        }

        if ($olderThan !== null && ! $this->isNonNegativeInteger($olderThan)) {
            $this->error('Invalid --older-than. Provide a whole number of hours (0 or greater).');

            return self::FAILURE;
        }

        if ($limit !== null && (! $this->isNonNegativeInteger($limit) || (int) $limit < 1)) {
            $this->error('Invalid --limit. Provide a whole number greater than or equal to 1.');

            return self::FAILURE;
        }

        $olderThanHours = $olderThan !== null ? (int) $olderThan : null;
        $limitRows = $limit !== null ? (int) $limit : null;

        $query = GuestReportPurchase::query()
            ->where('payment_status', GuestReportPurchase::STATUS_PAID)
            ->whereIn('fulfillment_status', self::AUDITABLE_STATUSES)
            ->orderBy('id');

        if ($status !== null) {
            $query->where('fulfillment_status', $status);
        }

        if ($olderThanHours !== null) {
            $query->where('created_at', '<=', now()->subHours($olderThanHours));
        }

        if ($limitRows !== null) {
            $query->limit($limitRows);
        }

        $rows = $query
            ->get([
                'id',
                'report_id',
                'fulfillment_status',
                'fulfillment_attempts',
                'fulfillment_generation',
                'fulfillment_generation_attempts',
                'fulfillment_recovery_attempts',
                'fulfillment_dispatch_expires_at',
                'fulfillment_claim_expires_at',
                'fulfillment_manual_review_at',
                'created_at',
            ])
            ->map(fn (GuestReportPurchase $purchase): array => $this->toAuditRow($purchase));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'filters' => [
                    'status' => $status,
                    'older_than_hours' => $olderThanHours,
                    'limit' => $limitRows,
                ],
                'count' => $rows->count(),
                'rows' => $rows->values()->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Matched {$rows->count()} guest fulfillment row(s).");

        if ($rows->isEmpty()) {
            $this->line('No matching guest fulfillment rows.');
        } else {
            $this->table(
                [
                    'ID',
                    'Report',
                    'Fulfillment',
                    'Attempts',
                    'Gen',
                    'Gen Att',
                    'Rec Att',
                    'Dispatch Expires',
                    'Claim Expires',
                    'Manual Review',
                    'Created',
                    'Age',
                ],
                $rows->map(fn (array $row): array => [
                    $row['id'],
                    $row['report_id'],
                    $row['fulfillment_status'],
                    $row['fulfillment_attempts'],
                    $row['fulfillment_generation'],
                    $row['fulfillment_generation_attempts'],
                    $row['fulfillment_recovery_attempts'],
                    $this->displayTimestamp($row['fulfillment_dispatch_expires_at']),
                    $this->displayTimestamp($row['fulfillment_claim_expires_at']),
                    $this->displayTimestamp($row['fulfillment_manual_review_at']),
                    $this->displayTimestamp($row['created_at']),
                    $this->displayAge($row['age_seconds']),
                ])->all(),
            );
        }

        $this->line('Note: fulfillment_status=processing is not assumed to be orphaned. Inspect dispatch/claim expiry and attempt counters before acting.');

        return self::SUCCESS;
    }

    /**
     * Shape a single row with a stable key order.
     *
     * @return array{
     *     id: int,
     *     report_id: int|null,
     *     fulfillment_status: string|null,
     *     fulfillment_attempts: int|null,
     *     fulfillment_generation: int|null,
     *     fulfillment_generation_attempts: int|null,
     *     fulfillment_recovery_attempts: int|null,
     *     fulfillment_dispatch_expires_at: string|null,
     *     fulfillment_claim_expires_at: string|null,
     *     fulfillment_manual_review_at: string|null,
     *     created_at: string|null,
     *     age_seconds: int|null
     * }
     */
    private function toAuditRow(GuestReportPurchase $purchase): array
    {
        return [
            'id' => (int) $purchase->id,
            'report_id' => $purchase->report_id !== null ? (int) $purchase->report_id : null,
            'fulfillment_status' => $purchase->fulfillment_status,
            'fulfillment_attempts' => $purchase->fulfillment_attempts !== null ? (int) $purchase->fulfillment_attempts : null,
            'fulfillment_generation' => $purchase->fulfillment_generation !== null ? (int) $purchase->fulfillment_generation : null,
            'fulfillment_generation_attempts' => $purchase->fulfillment_generation_attempts !== null ? (int) $purchase->fulfillment_generation_attempts : null,
            'fulfillment_recovery_attempts' => $purchase->fulfillment_recovery_attempts !== null ? (int) $purchase->fulfillment_recovery_attempts : null,
            'fulfillment_dispatch_expires_at' => $this->isoTimestamp($purchase->fulfillment_dispatch_expires_at),
            'fulfillment_claim_expires_at' => $this->isoTimestamp($purchase->fulfillment_claim_expires_at),
            'fulfillment_manual_review_at' => $this->isoTimestamp($purchase->fulfillment_manual_review_at),
            'created_at' => $this->isoTimestamp($purchase->created_at),
            'age_seconds' => $this->ageSeconds($purchase->created_at),
        ];
    }

    private function isNonNegativeInteger(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d+$/', $value) === 1;
    }

    private function isoTimestamp(?CarbonInterface $value): ?string
    {
        return $value?->toIso8601String();
    }

    private function ageSeconds(?CarbonInterface $createdAt): ?int
    {
        if ($createdAt === null) {
            return null;
        }

        return max(0, (int) $createdAt->diffInSeconds(now()));
    }

    private function displayTimestamp(?string $value): string
    {
        if ($value === null) {
            return '-';
        }

        return (string) str_replace('T', ' ', substr($value, 0, 19));
    }

    private function displayAge(?int $seconds): string
    {
        if ($seconds === null) {
            return '-';
        }

        if ($seconds < 60) {
            return "{$seconds}s";
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60).'m';
        }

        if ($seconds < 86400) {
            return intdiv($seconds, 3600).'h '.intdiv($seconds % 3600, 60).'m';
        }

        return intdiv($seconds, 86400).'d '.intdiv($seconds % 86400, 3600).'h';
    }
}
