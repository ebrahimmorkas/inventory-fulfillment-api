<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Enums\ReportExportStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReportExportResource;
use App\Jobs\GenerateSalesReport;
use App\Models\ReportExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesExportController extends Controller
{
    private const MAX_RANGE_DAYS = 366;

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('create', ReportExport::class);

        $exports = ReportExport::where('user_id', $request->user()->id)
            ->latest()
            ->paginate($this->perPage($request));

        return ReportExportResource::collection($exports);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', ReportExport::class);

        $user = $request->user();
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today'],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')],
        ]);

        if (Carbon::parse($validated['from'])->diffInDays(Carbon::parse($validated['to'])) > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'to' => 'The date range may not exceed '.self::MAX_RANGE_DAYS.' days.',
            ]);
        }

        if (isset($validated['warehouse_id'])) {
            abort_unless($user->canAccessWarehouse($validated['warehouse_id']), 403);
        }

        // The warehouse scope is resolved now and stored with the export, so the
        // job reports exactly what the user was allowed to see when requesting it.
        $warehouseIds = isset($validated['warehouse_id'])
            ? [(int) $validated['warehouse_id']]
            : ($user->hasAccessToAllWarehouses() ? null : $user->assignedWarehouseIds());

        $export = ReportExport::create([
            'user_id' => $user->id,
            'type' => 'sales',
            'parameters' => ['from' => $validated['from'], 'to' => $validated['to'], 'warehouse_ids' => $warehouseIds],
            'status' => ReportExportStatus::Pending,
        ]);

        GenerateSalesReport::dispatch($export)->afterCommit();

        return (new ReportExportResource($export->fresh()))->response()->setStatusCode(202);
    }

    public function show(ReportExport $export): ReportExportResource
    {
        Gate::authorize('view', $export);

        return new ReportExportResource($export);
    }

    public function download(ReportExport $export): StreamedResponse|JsonResponse
    {
        Gate::authorize('view', $export);

        if ($export->status !== ReportExportStatus::Completed) {
            return response()->json(['message' => 'The export is not ready yet.', 'status' => $export->status], 409);
        }

        return Storage::disk(ReportExport::DISK)->download(
            $export->file_path,
            "sales-{$export->parameters['from']}-to-{$export->parameters['to']}.csv",
            ['Content-Type' => 'text/csv'],
        );
    }
}
