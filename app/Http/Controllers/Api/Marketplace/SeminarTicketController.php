<?php

namespace App\Http\Controllers\Api\Marketplace;

use App\Exceptions\Payments\MarketplaceException;
use App\Http\Controllers\Controller;
use App\Models\TicketPayment;
use App\Services\Marketplace\MarketplaceConfig;
use App\Services\Marketplace\SeminarTickets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The seminar ticket endpoints (see docs/seminar-payments.md). All are behind EnsureMarketplaceCaller, so by
 * the time anything here runs the caller is authenticated and the marketplace is on.
 *
 * Nothing here marks a ticket paid or refunded: that happens only in TicketSettlement, from Paystack's
 * verified webhook. The return_url is where the attendee's browser is sent after paying; it is UI only.
 */
class SeminarTicketController extends Controller
{
    public function store(Request $request, SeminarTickets $tickets, MarketplaceConfig $config): JsonResponse
    {
        $request->merge([
            'currency' => is_string($request->input('currency')) ? strtoupper(trim($request->input('currency'))) : $request->input('currency'),
            'buyer_email' => is_string($request->input('buyer_email')) ? strtolower(trim($request->input('buyer_email'))) : $request->input('buyer_email'),
        ]);

        $data = $request->validate([
            'ticket_ref' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'payout_account_id' => ['required', 'integer:strict', 'min:1'],
            'buyer_email' => ['required', 'string', 'email:rfc', 'max:254'],
            'amount_cents' => ['required', 'integer:strict', 'min:'.$config->minAmountCents(), 'max:'.$config->maxAmountCents()],
            'currency' => ['required', 'string', Rule::in($config->currencies())],
            'description' => ['required', 'string', 'max:140'],
            'return_url' => ['nullable', 'string', 'max:300', function (string $attribute, mixed $value, \Closure $fail) use ($config): void {
                if (! is_string($value) || ! $config->returnUrlAllowed($value)) {
                    $fail('The return url must be an https address on an allowed host.');
                }
            }],
        ]);

        [$ticket, $created] = $tickets->create([
            'ticket_ref' => $data['ticket_ref'],
            'payout_account_id' => $data['payout_account_id'],
            'buyer_email' => $data['buyer_email'],
            'amount_cents' => $data['amount_cents'],
            'currency' => $data['currency'],
            'description' => $data['description'],
            'return_url' => $data['return_url'] ?? null,
        ]);

        return response()->json([
            'ticket_ref' => $ticket->ticket_ref,
            'reference' => $ticket->paystack_reference,
            'authorization_url' => $ticket->authorization_url,
            'status' => $ticket->status,
        ], $created ? 201 : 200);
    }

    public function show(string $ticketRef, SeminarTickets $tickets): JsonResponse
    {
        $ticket = $tickets->find($ticketRef) ?? throw MarketplaceException::because('not_found', 'No such ticket.', 404);

        return response()->json($ticket->statusPayload());
    }

    public function refund(string $ticketRef, SeminarTickets $tickets): JsonResponse
    {
        return response()->json($this->withRefundFlag($tickets->refund($ticketRef)));
    }

    public function cancel(string $ticketRef, SeminarTickets $tickets): JsonResponse
    {
        return response()->json($tickets->cancel($ticketRef)->statusPayload());
    }

    /**
     * @return array<string, mixed>
     */
    private function withRefundFlag(TicketPayment $ticket): array
    {
        return $ticket->statusPayload() + ['refund_requested' => $ticket->refund_requested_at !== null];
    }
}
