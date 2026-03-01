<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogService
{
    public function record(
        ?User $actor,
        string $action,
        ?string $targetType = null,
        int|string|null $targetId = null,
        ?string $description = null,
        array $metadata = [],
        ?Request $request = null,
    ): void {
        AuditLog::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'description' => $description,
            'metadata' => $metadata,
            'ip_address' => $request?->ip(),
        ]);
    }
}

