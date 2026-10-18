<?php

namespace App\Services\Billing;

use App\Models\BillingAuditEvent;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingSubscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the append-only billing audit trail. Callers pass the actor (null for the scheduler).
 */
class AuditLog
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function record(string $action, Team|int $team, ?User $actor = null, ?Model $subject = null, array $meta = []): BillingAuditEvent
    {
        return BillingAuditEvent::create([
            'team_id' => $team instanceof Team ? $team->id : $team,
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject ? $this->subjectType($subject) : null,
            'subject_id' => $subject?->getKey(),
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    private function subjectType(Model $subject): string
    {
        return match (true) {
            $subject instanceof BillingSubscription => 'subscription',
            $subject instanceof BillingInvoice => 'invoice',
            $subject instanceof BillingPayment => 'payment',
            default => strtolower(class_basename($subject)),
        };
    }
}
