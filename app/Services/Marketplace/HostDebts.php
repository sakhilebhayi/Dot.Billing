<?php

namespace App\Services\Marketplace;

use App\Exceptions\Payments\MarketplaceException;
use App\Models\HostAgreement;
use App\Models\HostDebt;
use App\Models\HostDebtRecovery;
use App\Models\PayoutAccount;
use App\Models\TicketPayment;
use App\Services\Billing\AuditLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Host agreements and host debts: recovering the money InfoDot lost to a chargeback from the host's later tickets,
 * but only under a term the host accepted (docs/seminar-payments.md, "Chargeback recovery").
 *
 * The rules, in one place:
 *
 *  - OPEN. A lost dispute creates one debt per ticket: ticket amount minus platform fee minus what was already
 *    refunded to the attendee (what Paystack takes back is not the refunded part, and the platform fee was InfoDot's
 *    own). Nothing is created when that works out to nothing.
 *  - QUOTE. When a ticket is created for a host with open debt AND an accepted current term, its extra fee is
 *    min(debt still open, cap% of the host share), where debt still open ignores what pending tickets already
 *    reserved. Callers hold the debt rows locked, so two tickets created back to back cannot together reserve more
 *    than is owed.
 *  - APPLY. Only when a ticket is verified paid is its extra fee credited to the host's open debts, oldest first,
 *    never above what each still owes. Exactly once: the ledger row is unique per (debt, ticket).
 *  - REVERSE. A refund gives the recovery back in proportion to the refunded share of the ticket (all of it for a full
 *    refund). A cancelled or failed ticket never applied anything, so there is nothing to give back.
 *  - WRITE OFF. A moderator can give up an open debt; nothing more is taken for it afterwards.
 *
 * Every method that changes rows expects to run inside the caller's transaction, and returns the events InfoDot must
 * be told about, to be sent AFTER that transaction commits (nothing here calls out).
 */
class HostDebts
{
    public const OPENED = 'host_debt.opened';

    public const CLEARED = 'host_debt.cleared';

    public const REOPENED = 'host_debt.reopened';

    public const WRITTEN_OFF = 'host_debt.written_off';

    public function __construct(private MarketplaceConfig $config, private AuditLog $audit) {}

    // ------------------------------------------------------------------ agreements

    public function hasCurrentAgreement(PayoutAccount $account): bool
    {
        $version = $this->config->agreementVersion();

        return $version !== null && $account->agreements()->where('version', $version)->exists();
    }

    /**
     * Record that a host accepted a version of the term. Idempotent per (account, version): a repeat returns the
     * stored acceptance unchanged (the first acceptance stands) and writes nothing.
     *
     * @return array{0: HostAgreement, 1: bool} the acceptance, and whether it is new
     */
    public function accept(PayoutAccount $account, string $version, string $acceptedByRef): array
    {
        $existing = $account->agreements()->where('version', $version)->first();

        if ($existing) {
            return [$existing, false];
        }

        try {
            $agreement = DB::transaction(function () use ($account, $version, $acceptedByRef) {
                $agreement = $account->agreements()->create([
                    'version' => $version,
                    'accepted_at' => now(),
                    'accepted_by_ref' => $acceptedByRef,
                ]);

                $this->audit->record('host_agreement.accepted', null, null, $agreement, [
                    'payout_account_id' => $account->id,
                    'version' => $version,
                    'accepted_by_ref' => $acceptedByRef,
                    'current' => $version === $this->config->agreementVersion(),
                ]);

                return $agreement;
            });
        } catch (UniqueConstraintViolationException) {
            // A simultaneous identical request won the insert: answer from its row.
            return [$account->agreements()->where('version', $version)->firstOrFail(), false];
        }

        return [$agreement, true];
    }

    // ------------------------------------------------------------------ opening a debt

    /**
     * A lost dispute on a ticket: open the debt for the money InfoDot lost. Idempotent (one debt per ticket).
     * Expects the ticket row to be locked by the caller.
     *
     * "Lost" is the host share still outstanding: amount - platform fee - refunded. The attendee's bank takes the
     * chargeback back from InfoDot's Paystack account; the host was paid their share through the split and InfoDot
     * kept only the platform fee, so InfoDot's loss is the host share. A refund already returned part of the money to
     * the attendee, so it is not at stake twice. The extra fee taken from this ticket for an EARLIER debt is not
     * subtracted: the chargeback takes that money back too, so it is part of this loss (the earlier debt stays cleared).
     *
     * @return list<array{0: string, 1: HostDebt}> events to send after commit
     */
    public function openForLostDispute(TicketPayment $ticket): array
    {
        if (HostDebt::where('ticket_payment_id', $ticket->id)->exists()) {
            return [];
        }

        $owed = max(0, $ticket->amount_cents - $ticket->platform_fee_cents - $ticket->refunded_cents);

        if ($owed === 0) {
            $this->audit->record('host_debt.not_opened', null, null, $ticket, [
                'ticket_ref' => $ticket->ticket_ref, 'reason' => 'nothing_lost',
                'amount_cents' => $ticket->amount_cents, 'platform_fee_cents' => $ticket->platform_fee_cents, 'refunded_cents' => $ticket->refunded_cents,
            ]);

            return [];
        }

        $debt = HostDebt::create([
            'payout_account_id' => $ticket->payout_account_id,
            'ticket_payment_id' => $ticket->id,
            'currency' => strtoupper($ticket->currency),
            'amount_cents' => $owed,
            'recovered_cents' => 0,
            'status' => HostDebt::OPEN,
        ]);

        $this->audit->record('host_debt.opened', null, null, $debt, [
            'ticket_ref' => $ticket->ticket_ref, 'payout_account_id' => $debt->payout_account_id,
            'amount_cents' => $owed, 'currency' => $debt->currency,
        ]);

        return [[self::OPENED, $debt]];
    }

    // ------------------------------------------------------------------ quoting a ticket's extra fee

    /**
     * The extra fee for a ticket about to be created, in cents (0 for none). MUST run inside the transaction that
     * creates the ticket: it locks the host's open debt rows, which is what stops two tickets created together from
     * reserving the same debt twice.
     */
    public function quote(PayoutAccount $account, int $amountCents, int $platformFeeCents, string $currency): int
    {
        $cap = $this->config->recoveryCapPercent() ?? 0;

        if ($cap === 0 || ! $this->hasCurrentAgreement($account)) {
            return 0;
        }

        $open = $this->lockOpenDebts($account->id, $currency);

        if ($open->isEmpty()) {
            return 0;
        }

        $owed = (int) $open->sum(fn (HostDebt $debt) => $debt->amount_cents - $debt->recovered_cents);
        $available = max(0, $owed - $this->inFlightCents($account->id, $currency));
        $hostShare = max(0, $amountCents - $platformFeeCents);
        $limit = intdiv($hostShare * $cap, 100);

        return max(0, min($available, $limit));
    }

    /** Extra fee already promised on this host's unpaid tickets: reserved against the debt but not yet credited. */
    public function inFlightCents(int $payoutAccountId, ?string $currency = null): int
    {
        return (int) TicketPayment::where('payout_account_id', $payoutAccountId)
            ->where('status', TicketPayment::PENDING)
            ->when($currency !== null, fn ($q) => $q->where('currency', strtoupper($currency)))
            ->sum('recovery_cents');
    }

    // ------------------------------------------------------------------ crediting a paid ticket

    /**
     * The ticket was just verified paid: credit its extra fee to the host's open debts, oldest first, never above
     * what each still owes. Expects the ticket row to be locked and to be changing from pending to paid in this
     * transaction (the caller's replay check makes this run once); the ledger's unique key is a second guard.
     *
     * @return list<array{0: string, 1: HostDebt}> events to send after commit
     */
    public function applyToPaidTicket(TicketPayment $ticket): array
    {
        if ($ticket->recovery_cents <= 0 || $ticket->recovery_applied_cents > 0) {
            return [];
        }

        $events = [];
        $left = $ticket->recovery_cents;

        foreach ($this->lockOpenDebts($ticket->payout_account_id, $ticket->currency) as $debt) {
            if ($left <= 0) {
                break;
            }

            $take = min($left, $debt->amount_cents - $debt->recovered_cents);

            if ($take <= 0 || HostDebtRecovery::where('host_debt_id', $debt->id)->where('ticket_payment_id', $ticket->id)->exists()) {
                continue;
            }

            HostDebtRecovery::create(['host_debt_id' => $debt->id, 'ticket_payment_id' => $ticket->id, 'amount_cents' => $take]);

            $debt->recovered_cents += $take;
            $left -= $take;

            if ($debt->recovered_cents >= $debt->amount_cents) {
                $debt->status = HostDebt::CLEARED;
                $debt->cleared_at = now();
                $events[] = [self::CLEARED, $debt];
            }

            $debt->save();

            $this->audit->record($debt->status === HostDebt::CLEARED ? 'host_debt.cleared' : 'host_debt.recovered', null, null, $debt, [
                'ticket_ref' => $ticket->ticket_ref,
                'recovered_now_cents' => $take, 'recovered_cents' => $debt->recovered_cents, 'amount_cents' => $debt->amount_cents,
                'currency' => $debt->currency,
            ]);
        }

        $ticket->recovery_applied_cents = $ticket->recovery_cents - $left;
        $ticket->save();

        if ($left > 0) {
            // The debt shrank (written off, or already covered) after this ticket was priced. The larger fee was
            // already taken, and there is nothing left to credit it to: surface it for a person instead of hiding it.
            Log::warning('marketplace.host_debt.recovery_unapplied', ['ticket_ref' => $ticket->ticket_ref, 'unapplied_cents' => $left]);
            $this->audit->record('host_debt.recovery_unapplied', null, null, $ticket, [
                'ticket_ref' => $ticket->ticket_ref, 'recovery_cents' => $ticket->recovery_cents, 'unapplied_cents' => $left, 'currency' => $ticket->currency,
            ]);
        }

        return $events;
    }

    // ------------------------------------------------------------------ giving recovery back on a refund

    /**
     * A refund was just applied to the ticket (its refunded_cents is already updated): give back the matching share of
     * the recovery that was credited. The total given back is applied x refunded / amount rounded down, and all of it
     * once the ticket is fully refunded, so several partial refunds never give back more than was credited. Newest
     * credit first. A cleared debt that falls below its amount opens again; a written-off debt is left as it is.
     * Expects the ticket row to be locked.
     *
     * @return list<array{0: string, 1: HostDebt}> events to send after commit
     */
    public function reverseForRefund(TicketPayment $ticket): array
    {
        if ($ticket->recovery_applied_cents <= 0 || $ticket->amount_cents <= 0) {
            return [];
        }

        $target = $ticket->refunded_cents >= $ticket->amount_cents
            ? $ticket->recovery_applied_cents
            : intdiv($ticket->recovery_applied_cents * $ticket->refunded_cents, $ticket->amount_cents);
        $delta = $target - $ticket->recovery_reversed_cents;

        if ($delta <= 0) {
            return [];
        }

        $events = [];
        $given = 0;

        $credits = HostDebtRecovery::where('ticket_payment_id', $ticket->id)->orderByDesc('id')->lockForUpdate()->get();

        foreach ($credits as $credit) {
            $take = min($delta - $given, $credit->amount_cents - $credit->reversed_cents);

            if ($take <= 0) {
                continue;
            }

            $debt = HostDebt::lockForUpdate()->findOrFail($credit->host_debt_id);
            $credit->reversed_cents += $take;
            $credit->save();
            $given += $take;

            if ($debt->status !== HostDebt::WRITTEN_OFF) {
                $debt->recovered_cents = max(0, $debt->recovered_cents - $take);

                if ($debt->status === HostDebt::CLEARED && $debt->recovered_cents < $debt->amount_cents) {
                    $debt->status = HostDebt::OPEN;
                    $debt->cleared_at = null;
                    $events[] = [self::REOPENED, $debt];
                }

                $debt->save();
            }

            $this->audit->record('host_debt.recovery_reversed', null, null, $debt, [
                'ticket_ref' => $ticket->ticket_ref, 'reversed_now_cents' => $take, 'recovered_cents' => $debt->recovered_cents,
                'amount_cents' => $debt->amount_cents, 'status' => $debt->status, 'currency' => $debt->currency,
            ]);

            if ($given >= $delta) {
                break;
            }
        }

        $ticket->recovery_reversed_cents += $given;
        $ticket->save();

        return $events;
    }

    // ------------------------------------------------------------------ moderator write-off

    /**
     * Give up an open debt. Idempotent: writing off a written-off debt returns it unchanged. A cleared debt cannot
     * be written off. Returns the debt and whether it changed now.
     *
     * @return array{0: HostDebt, 1: bool}
     *
     * @throws MarketplaceException
     */
    public function writeOff(int $debtId, string $note, string $byRef): array
    {
        $changed = false;

        $debt = DB::transaction(function () use ($debtId, $note, $byRef, &$changed) {
            $debt = HostDebt::lockForUpdate()->find($debtId)
                ?? throw MarketplaceException::because('not_found', 'No such host debt.', 404);

            if ($debt->status === HostDebt::WRITTEN_OFF) {
                return $debt;
            }

            if ($debt->status !== HostDebt::OPEN) {
                throw MarketplaceException::because('debt_not_open', 'Only an open debt can be written off.', 409);
            }

            $debt->forceFill([
                'status' => HostDebt::WRITTEN_OFF,
                'written_off_at' => now(),
                'written_off_by_ref' => $byRef,
                'note' => $note,
            ])->save();

            $this->audit->record('host_debt.written_off', null, null, $debt, [
                'payout_account_id' => $debt->payout_account_id, 'amount_cents' => $debt->amount_cents,
                'recovered_cents' => $debt->recovered_cents, 'written_off_cents' => $debt->amount_cents - $debt->recovered_cents,
                'written_off_by_ref' => $byRef, 'currency' => $debt->currency,
            ]);

            $changed = true;

            return $debt;
        });

        return [$debt, $changed];
    }

    // ------------------------------------------------------------------ helpers

    /** @return Collection<int, HostDebt> the host's open debts in this currency, oldest first, locked for update */
    private function lockOpenDebts(int $payoutAccountId, string $currency): Collection
    {
        return HostDebt::where('payout_account_id', $payoutAccountId)
            ->where('currency', strtoupper($currency))
            ->where('status', HostDebt::OPEN)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }
}
