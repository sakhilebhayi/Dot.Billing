# Host agreement: chargeback recovery (DRAFT, version v1)

> **DRAFT. NOT LEGAL ADVICE. UNREVIEWED.** This wording was written by an engineer to show the adviser what the
> software does, in plain language. It has not been read by a lawyer and must not be shown to hosts, accepted by
> anyone or relied on until the owner's adviser has reviewed, corrected and approved it. Everything in square
> brackets is a placeholder or a question for the adviser. Changing the approved wording means a new version
> (`BILLING_MARKETPLACE_AGREEMENT_VERSION`), which every host must accept again.

This is the term a host accepts before they can sell paid tickets. InfoDot shows it, records who accepted which
version and when, and tells Dot.Billing (`POST /api/payout-accounts/{id}/agreement`). Dot.Billing refuses to create a
ticket for a host who has not accepted the current version, and takes nothing under it from a host who has not.

---

## Chargebacks on your tickets

**1. What a chargeback is.** When someone who bought a ticket asks their bank to reverse the payment, and the dispute
is decided against us, the bank takes the money back. We call that a *lost chargeback*. [Adviser: confirm the term
"chargeback" and how a dispute is decided with the payment provider.]

**2. Who has already been paid.** When an attendee pays for your ticket, the payment is split at that moment. Your
share goes to your bank account. Our share, 10% of the ticket price [adviser: confirm this stays the contractual
percentage], is kept by InfoDot. So when a chargeback is lost, the money the bank takes back comes out of InfoDot's
payment account, even though your share had already been paid to you.

**3. What you agree to.** If a chargeback on one of your tickets is lost, the amount that InfoDot lost becomes a debt
you owe InfoDot. The amount is the ticket price, minus InfoDot's own 10% fee, minus anything already refunded to the
attendee. [Adviser: is this a fair and enforceable definition? Does it need to include or exclude the payment
provider's chargeback fee?]

**4. How the debt is paid.** You do not need to pay it separately. Until the debt is paid, InfoDot takes an extra
amount from each new ticket you sell, as a larger fee on that ticket. The extra amount on a ticket is the smaller of:

- what you still owe, and
- half (50%) of your share of that ticket, where your share is the ticket price minus InfoDot's 10% fee.

You always keep at least half of your share of every ticket for the debt's duration [before the payment provider's
own processing fee, which is taken from your share as it is today]. The extra amount is taken only when the
attendee's payment is confirmed. If a ticket is cancelled or not paid, nothing is taken for it. If a ticket is
refunded, the extra amount taken on it is given back to the debt in the same proportion. [Adviser: confirm
that taking the amount at the point of sale, rather than by invoice, is permissible and sufficiently disclosed; confirm
the 50% limit.]

**5. An example.** You sell a R200.00 ticket. InfoDot's fee is R20.00, so your share is R180.00. You owe R100.00.
InfoDot takes the smaller of R100.00 and half of R180.00 (R90.00), so R90.00. The fee on that ticket is R110.00 and
you receive R90.00 (before the payment provider's processing fee). You now owe R10.00.

**6. What we will tell you.** We will tell you when a debt is created, how much it is, when an amount is taken and what
is still owed, and when it is cleared. [Adviser: what notice is required, in what form, and who at the host receives it?]

**7. Writing a debt off.** An InfoDot moderator can decide to write a debt off, in whole or in part [the software writes
off the whole remaining debt only]. Nothing further is then taken for it. We record who made the decision and why.

**8. Disagreeing.** If you think a debt is wrong you can tell us at [contact]. [Adviser: dispute process, time limits,
and what happens to the debt while it is being looked at. The software has no "paused" state today.]

**9. If you stop selling.** A debt does not expire and nothing is taken if you sell no further tickets. [Adviser:
whether InfoDot may also claim the debt another way, and after what period it lapses.]

**10. Changes.** If this wording changes you will be asked to accept the new version before you can sell more tickets.
Until you accept it, you cannot create new tickets, and nothing is taken under the new wording. [Adviser: effect of the
change on debts that already exist.]

**11. Your personal information.** We keep a record of who accepted this agreement, which version, and when, and of any
debt and the tickets it relates to. [Adviser: POPIA notice, lawful basis, retention period.]

---

### Open questions for the adviser

1. Is a term accepted by clicking in InfoDot enough, and who has authority to accept for a business host?
2. May InfoDot recover as a larger fee at the point of sale, or only through a separate invoice or set-off statement?
3. Consumer protection and the host's own obligations to attendees: does a chargeback recovery change who is the
   merchant of record (see "Owner decisions still open" in `seminar-payments.md`)?
4. VAT treatment of the larger fee.
5. Is the 50% cap right, and should the host be able to choose a different arrangement?
6. Is a debt that is never recovered (a host who stops selling) written off after a fixed period?

### What the software does, for the adviser's reference

- Acceptance: one record per host and version (`host_agreements`: who, when, which version).
- A lost chargeback creates one debt per ticket (`host_debts`).
- The larger fee is added to a ticket when it is created and is credited to the debt only when the ticket's payment is
  verified. See "Chargeback recovery" in `seminar-payments.md` for the exact arithmetic and the list of what is not handled.
