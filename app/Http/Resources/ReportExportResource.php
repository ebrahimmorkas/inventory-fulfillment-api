<?php

namespace App\Http\Resources;

use App\Enums\ReportExportStatus;
use App\Models\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReportExport */
class ReportExportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'parameters' => [
                'from' => $this->parameters['from'],
                'to' => $this->parameters['to'],
            ],
            'row_count' => $this->row_count,
            'error' => $this->error,
            'download_url' => $this->status === ReportExportStatus::Completed
                ? route('v1.reports.sales-exports.download', $this->resource)
                : null,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
