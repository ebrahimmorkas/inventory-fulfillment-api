<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ReportExport;
use App\Models\User;

class ReportExportPolicy
{
    public function create(User $user): bool
    {
        return $user->can(Permission::ReportsView->value);
    }

    /** Exports contain customer data, so only the requester may access them. */
    public function view(User $user, ReportExport $export): bool
    {
        return $export->user_id === $user->id && $user->can(Permission::ReportsView->value);
    }
}
