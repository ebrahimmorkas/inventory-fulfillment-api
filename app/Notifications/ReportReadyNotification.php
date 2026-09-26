<?php

namespace App\Notifications;

use App\Models\ReportExport;
use Illuminate\Notifications\Notification;

class ReportReadyNotification extends Notification
{
    public function __construct(public readonly ReportExport $export) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'report_ready',
            'report_export_id' => $this->export->id,
            'report_type' => $this->export->type,
            'row_count' => $this->export->row_count,
        ];
    }
}
