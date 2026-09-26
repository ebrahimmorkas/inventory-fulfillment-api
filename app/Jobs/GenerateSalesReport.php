<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Enums\ReportExportStatus;
use App\Models\ReportExport;
use App\Notifications\ReportReadyNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds a CSV of shipped order lines for a date range.
 *
 * Rows are streamed with lazyById() into a temporary stream, so memory use stays
 * flat regardless of how many orders fall into the range.
 */
class GenerateSalesReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [60, 300];

    private const HEADER = [
        'order_number', 'shipped_at', 'warehouse_code', 'customer_name', 'customer_email',
        'sku', 'product_name', 'quantity', 'unit_price', 'line_total',
    ];

    public function __construct(public ReportExport $export)
    {
        $this->onQueue('reports');
    }

    public function handle(): void
    {
        // A redelivered job must not regenerate a finished export.
        if ($this->export->status === ReportExportStatus::Completed) {
            return;
        }

        $this->export->update(['status' => ReportExportStatus::Processing]);

        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, self::HEADER, escape: '');
        $rows = 0;

        foreach ($this->rows() as $row) {
            fputcsv($stream, array_map($this->sanitise(...), [
                $row->number,
                Carbon::parse($row->shipped_at)->toIso8601String(),
                $row->warehouse_code,
                $row->customer_name,
                $row->customer_email,
                $row->sku,
                $row->product_name,
                $row->quantity,
                number_format($row->unit_price_cents / 100, 2, '.', ''),
                number_format($row->line_total_cents / 100, 2, '.', ''),
            ]), escape: '');
            $rows++;
        }

        rewind($stream);
        $path = sprintf('reports/sales-%d-%s.csv', $this->export->id, Str::lower(Str::random(12)));
        Storage::disk(ReportExport::DISK)->writeStream($path, $stream);
        fclose($stream);

        $this->export->update([
            'status' => ReportExportStatus::Completed,
            'file_path' => $path,
            'row_count' => $rows,
            'completed_at' => now(),
        ]);

        $this->export->user->notify(new ReportReadyNotification($this->export));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Sales report generation failed', ['export_id' => $this->export->id, 'exception' => $exception?->getMessage()]);

        // The internal error is logged, not exposed to the API client.
        $this->export->update(['status' => ReportExportStatus::Failed, 'error' => 'Report generation failed. Please try again.']);
    }

    private function rows(): iterable
    {
        $parameters = $this->export->parameters;

        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->join('warehouses', 'warehouses.id', '=', 'orders.warehouse_id')
            ->where('orders.status', OrderStatus::Shipped->value)
            ->whereBetween('orders.shipped_at', [
                Carbon::parse($parameters['from'])->startOfDay(),
                Carbon::parse($parameters['to'])->endOfDay(),
            ])
            ->when($parameters['warehouse_ids'] !== null, fn ($q) => $q->whereIn('orders.warehouse_id', $parameters['warehouse_ids']))
            ->select([
                'order_items.id as item_id', 'orders.number', 'orders.shipped_at', 'warehouses.code as warehouse_code',
                'customers.name as customer_name', 'customers.email as customer_email',
                'products.sku', 'products.name as product_name',
                'order_items.quantity', 'order_items.unit_price_cents', 'order_items.line_total_cents',
            ])
            ->lazyById(1000, 'order_items.id', 'item_id');
    }

    /**
     * Prevent CSV/formula injection: a cell starting with =, +, - or @ would be
     * executed by spreadsheet software, and customer names are user input.
     */
    private function sanitise(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
