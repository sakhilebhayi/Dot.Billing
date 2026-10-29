<?php

namespace App\Livewire\Billing;

use App\Exceptions\Billing\BillingRuleViolation;
use App\Models\BillingSeatChange;
use App\Models\BillingSubscription;
use App\Models\Team;
use App\Services\Billing\SeatChange;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The customer "Seats" screen: the team's seats in words, one unpaid purchase if there is one (with Pay now
 * into the existing invoice payment flow), a reduction scheduled for period end, the extra-seat price for the
 * team's cycle, and a form that sets the desired TOTAL of extra seats through SeatChange::change.
 *
 * Authorization is the manage-billing gate against the CURRENT team, on mount and on every action. The
 * subscription is always re-read from that team, never taken from component state, so a crafted request
 * cannot reach another team's. Nothing here decides a price or a count: SeatChange does, and its refusals
 * are shown in its own plain words.
 *
 * Idempotency: one request key is minted per form state and kept until the number in the box changes, so a
 * double click (or a re-sent request) carries the same key and SeatChange answers it from the first result.
 */
class SeatsPanel extends Component
{
    /** What the person typed: validated as a whole number before it is used. */
    public $targetExtraSeats = 0;

    /** Server-minted and locked, so the browser cannot choose or reuse a key it was not given. */
    #[Locked]
    public string $requestKey = '';

    /** @var array{kind: string, replayed: bool, from: int, to: int, effective: ?string}|null */
    #[Locked]
    public ?array $result = null;

    public function mount(): void
    {
        $team = $this->managedTeam();
        $this->targetExtraSeats = (int) ($team->liveSubscription()->value('extra_seats') ?? 0);
        $this->requestKey = (string) Str::uuid();
    }

    /** A different number is a different request: it gets its own key. */
    public function updatedTargetExtraSeats(): void
    {
        $this->requestKey = (string) Str::uuid();
        $this->result = null;
        $this->resetErrorBag();
    }

    private function resolveCurrentTeam(): ?Team
    {
        return Auth::user()?->currentTeam;
    }

    private function managedTeam(): Team
    {
        $team = $this->resolveCurrentTeam();
        abort_unless($team, 403, 'No active team selected.');
        Gate::authorize('manage-billing', $team);

        return $team;
    }

    /** The team's one live subscription, with what the screen needs. */
    #[Computed]
    public function subscription(): ?BillingSubscription
    {
        return $this->resolveCurrentTeam()?->liveSubscription()->with('plan')->first();
    }

    /** The seat purchase still waiting for payment, with its invoice. */
    #[Computed]
    public function unpaidPurchase(): ?BillingSeatChange
    {
        $sub = $this->subscription;

        if (! $sub) {
            return null;
        }

        return BillingSeatChange::withoutGlobalScope('team')->with('invoice')
            ->where('subscription_id', $sub->id)->where('status', BillingSeatChange::AWAITING_PAYMENT)
            ->latest('id')->first();
    }

    public function submit(SeatChange $seats): void
    {
        $team = $this->managedTeam();
        $this->resetErrorBag();
        $this->result = null;

        $max = (int) config('billing.seats.max_extra', 500);

        $this->validate(
            ['targetExtraSeats' => ['required', 'integer', 'min:0', 'max:'.$max]],
            [
                'targetExtraSeats.required' => 'Enter how many extra seats you want in total.',
                'targetExtraSeats.integer' => 'Enter a whole number of extra seats.',
                'targetExtraSeats.min' => 'The number of extra seats cannot be negative.',
                'targetExtraSeats.max' => "A subscription can hold at most {$max} extra seats.",
            ],
        );

        $sub = $team->liveSubscription()->first();

        if (! $sub) {
            $this->addError('seats', 'This team has no running subscription, so there are no seats to change.');

            return;
        }

        try {
            $change = $seats->change($sub, (int) $this->targetExtraSeats, $this->requestKey, Auth::user());
        } catch (BillingRuleViolation $e) {
            $this->addError('seats', $e->getMessage());
            $this->refresh();

            return;
        }

        $this->result = [
            'kind' => $change->kind,
            'replayed' => $change->replayed,
            'from' => $change->from_extra_seats,
            'to' => $change->to_extra_seats,
            'effective' => $sub->fresh()->current_period_end?->toIso8601String(),
        ];

        $this->refresh();
    }

    private function refresh(): void
    {
        unset($this->subscription, $this->unpaidPurchase);
    }

    public function render()
    {
        $this->managedTeam();

        return view('livewire.billing.seats-panel');
    }
}
