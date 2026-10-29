<?php

namespace App\Services\Marketplace;

use App\Exceptions\Payments\MarketplaceException;
use App\Exceptions\Payments\PaymentGatewayException;
use App\Models\PayoutAccount;
use App\Services\Billing\AuditLog;
use App\Services\Payments\Paystack\PaystackClient;
use App\Services\Payments\Paystack\PaystackConfig;
use App\Support\Redacted;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;

/**
 * Create or update a host's Paystack subaccount (their payout destination).
 *
 * The account number is handed to Paystack and then forgotten: it is wrapped in Redacted so it cannot appear
 * in a trace, and only Paystack's subaccount code and the last four digits are stored. Posting the same
 * host_ref again UPDATES the existing subaccount; a second one is never created. A per-host lock stops two
 * simultaneous first posts from creating two subaccounts at Paystack.
 *
 * A disabled account stays disabled when re-posted: there is no endpoint here that re-enables one.
 */
class PayoutAccounts
{
    public function __construct(
        private PaystackConfig $paystack,
        private PaystackClient $client,
        private MarketplaceConfig $config,
        private AuditLog $audit,
    ) {}

    /**
     * @return array{0: PayoutAccount, 1: bool} the account, and whether it was newly created
     *
     * @throws MarketplaceException
     */
    public function save(string $hostRef, string $businessName, string $bankCode, Redacted $accountNumber): array
    {
        if (! $this->paystack->canTakePayments()) {
            throw MarketplaceException::providerUnavailable();
        }

        $lock = Cache::lock('marketplace:payout-account:'.sha1($hostRef), 30);

        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            throw MarketplaceException::because('busy', 'That payout account is being saved by another request. Try again in a moment.', 409);
        }

        try {
            $existing = PayoutAccount::where('host_ref', $hostRef)->first();

            try {
                return $existing
                    ? [$this->update($existing, $businessName, $bankCode, $accountNumber), false]
                    : [$this->create($hostRef, $businessName, $bankCode, $accountNumber), true];
            } catch (PaymentGatewayException $e) {
                throw $e->reason === 'rejected'
                    ? MarketplaceException::because('payout_account_rejected', 'The payment provider did not accept those bank details. Check the bank and account number.', 422)
                    : MarketplaceException::providerUnavailable();
            }
        } finally {
            $lock->release();
        }
    }

    private function create(string $hostRef, string $businessName, string $bankCode, Redacted $accountNumber): PayoutAccount
    {
        $code = $this->client->createSubaccount($hostRef, $businessName, $bankCode, $accountNumber, $this->percent());

        try {
            $account = PayoutAccount::create([
                'host_ref' => $hostRef,
                'paystack_subaccount_code' => $code,
                'bank_code' => $bankCode,
                'account_last4' => $accountNumber->last4(),
                'business_name' => $businessName,
                'status' => PayoutAccount::ACTIVE,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Lost a race the lock did not cover (e.g. a cache store without locks): fall back to updating.
            return $this->update(PayoutAccount::where('host_ref', $hostRef)->firstOrFail(), $businessName, $bankCode, $accountNumber);
        }

        $this->audit->record('payout_account.created', null, null, $account, $this->meta($account));

        return $account;
    }

    private function update(PayoutAccount $account, string $businessName, string $bankCode, Redacted $accountNumber): PayoutAccount
    {
        $this->client->updateSubaccount($account->host_ref, $account->paystack_subaccount_code, $businessName, $bankCode, $accountNumber, $this->percent());

        $account->forceFill([
            'bank_code' => $bankCode,
            'account_last4' => $accountNumber->last4(),
            'business_name' => $businessName,
        ])->save();

        $this->audit->record('payout_account.updated', null, null, $account, $this->meta($account));

        return $account;
    }

    private function percent(): int
    {
        return (int) $this->config->platformPercent();
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(PayoutAccount $account): array
    {
        return [
            'host_ref' => $account->host_ref,
            'bank_code' => $account->bank_code,
            'last4' => $account->account_last4,
            'status' => $account->status,
            'platform_percent' => $this->config->platformPercent(),
        ];
    }
}
