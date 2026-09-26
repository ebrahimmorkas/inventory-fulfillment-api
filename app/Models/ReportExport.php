<?php

namespace App\Models;

use App\Enums\ReportExportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['user_id', 'type', 'parameters', 'status', 'file_path', 'row_count', 'error', 'completed_at'])]
class ReportExport extends Model
{
    use Prunable;

    public const RETENTION_DAYS = 7;

    public const DISK = 'local';

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'status' => ReportExportStatus::class,
            'row_count' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    /** Remove the generated file together with the record. */
    protected function pruning(): void
    {
        if ($this->file_path) {
            Storage::disk(self::DISK)->delete($this->file_path);
        }
    }
}
