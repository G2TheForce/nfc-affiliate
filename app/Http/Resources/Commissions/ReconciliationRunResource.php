<?php

namespace App\Http\Resources\Commissions;

use App\Models\ReconciliationRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReconciliationRun
 */
class ReconciliationRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'result' => $this->result,
            'commissions_checked' => $this->commissions_checked,
            'affiliates_checked' => $this->affiliates_checked,
            'entries_checked' => $this->entries_checked,
            'issues' => $this->issues,
            'triggered_by' => $this->trigger?->name ?? 'Schedule',
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
