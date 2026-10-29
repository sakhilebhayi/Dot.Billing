# Host agreement: chargebacks and removal of seminars (DRAFT, version v1)

> **DRAFT. NOT LEGAL ADVICE. UNREVIEWED.** This wording was written by an engineer to show the adviser what the
> software does, in plain language. It has not been read by a lawyer and must not be shown to hosts, accepted by
> anyone or relied on until the owner's adviser has reviewed, corrected and approved it. Everything in square
> brackets is a placeholder or a question for the adviser. Changing the approved wording means a new version
> (`BILLING_MARKETPLACE_AGREEMENT_VERSION`), which every host must accept again.

This is the term a host accepts before they can sell paid tickets. InfoDot shows it, records who accepted which
version and when, and tells Dot.Billing (`POST /api/payout-accounts/{id}/agreement`). Dot.Billing refuses to create a
ticket for a host who has not accepted the current version, and takes nothing under it from a host who has not.

**Revised 2026-10-29.** Part B (removal of a seminar by a moderator) was added to match what the software does since
InfoDot PR #156 and updated the same day for the refund choice and the appeal (owner answers of 2026-10-29). It is still labelled v1. The owner's go-live record has the gate `SEMINARS_HOST_AGREEMENT_APPROVED` off, and
InfoDot shows the text and records an acceptance only when it is on, so no host should have accepted anything yet
[owner: confirm in the database before relying on this; `SELECT COUNT(*) FROM seminar_host_agreements`]. Keeping v1
avoids a mismatch with Dot.Billing's `BILLING_MARKETPLACE_AGREEMENT_VERSION`. If the adviser's approved wording differs from this draft in any way, the owner
decides the version label and sets the same value in both systems before turning the gate on. Approval is the
adviser's and the owner's. This file does not approve itself, and nothing here changes while the gate is off.

> **Copy kept in step with InfoDot.** The text below is the same as `resources/markdown/host-agreement.md` in the InfoDot repository, which is the copy
> hosts are shown. Part A (chargebacks) is carried out by Dot.Billing. **Part B (removal of a seminar by a moderator) is carried out by InfoDot:**
> Dot.Billing only executes the refunds InfoDot asks for through the existing refund call, and creates no debt for a removal. Last brought into line
> 2026-10-29 (InfoDot PRs #156 to #158). If the two ever differ, the InfoDot copy wins until the owner's adviser approves one wording; then both are
> replaced and `BILLING_MARKETPLACE_AGREEMENT_VERSION` is set to the approved version.

---

# Part A. Chargebacks on your tickets

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

# Part B. If a moderator removes your seminar

**B1. When this can happen.** Any signed-in member can report a seminar as misleading, harmful, a spam or scam, or
something else, with a note. A moderator at InfoDot reads the report and either dismisses it or removes the seminar.
A moderator can also remove a seminar they find themselves. We may remove a seminar that breaks the Terms or that a
moderator decides is misleading, harmful or unlawful. We do not tell you who reported it. [Adviser: the grounds for
removal, and whether they belong here or only in the Terms.]

**B2. What happens when a seminar is removed.** All of this happens together, at once:

- The seminar is cancelled and cannot be edited, reopened or restored. If it should run, you would create a new one.
- Everyone who registered is told it was cancelled.
- **If the seminar has not started, everyone who paid is refunded in full:** the price they paid, less anything
  already refunded to them. **If it has already started or taken place, the moderator chooses** one of three outcomes:
  everyone who paid is refunded in full; only the people who reported the seminar are refunded; or no one is refunded.
  You are told which was chosen. Anyone part-way through paying is released and no payment is taken from them in all
  three cases. [Adviser: are these three outcomes acceptable, and who may decide them? The software lets any moderator
  choose. The owner chose this arrangement on 2026-10-29.]
- You are told, in InfoDot, that a moderator removed it and the **reason the moderator wrote**, and the seminar page
  shows you the same reason. The reason is also kept in our moderation record with the moderator's name and the time.
  This record is kept as an audit trail and is not changed afterwards.
- If Dot Points had already been queued for you for this seminar, they are taken back.

**B3. What it means for your money.** Ticket refunds, where they are made (B2), go back through Paystack to the attendees. InfoDot's 10%
commission on a refunded amount is not kept: it is reversed on your next monthly commission invoice (a credit note if
refunds are larger than sales that month). Paystack's own processing fee on a refunded payment, and whether the refund
is taken from your share, from InfoDot's share or from both, depend on how Paystack apportions refunds for split
payments. **This has not been tested against Paystack.** [Adviser and owner: state who bears Paystack's fee on a refund
caused by removal, and whether a host whose share has already been paid out owes InfoDot anything for refunds InfoDot
funds. The software does not create a debt for a removal; it creates debts only for lost chargebacks, Part A.]

**B4. Removal is separate from a debt.** Removing a seminar does not itself create a debt. If an attendee also
disputes a payment with their bank and the dispute is lost, Part A applies to that ticket as it would otherwise.

**B5. If you disagree.** You can appeal **once**, with the form on the seminar's page, within 30 days of the removal
[owner: the 30 days is a setting, `SEMINARS_REMOVAL_APPEAL_DAYS`; confirm it]. A **different moderator** from the one
who removed it reads the appeal and answers on the same page (if InfoDot has only one moderator, that moderator
decides). While it is being decided the seminar stays removed. If the moderator agrees **and the seminar has not yet
started**, it is put back on: it is published again, the people who were registered are told, and **refunds already
made stay made** (a person who was refunded must register again, or buy a ticket again, if they still want to come). If
the seminar has already started, an appeal can only be answered; it cannot be put back on. You are told the decision and
the reason. There is no time limit on the reply. [Adviser: the process, who decides, the time limits, and whether
anything further is promised, for example a statement that the removal was a mistake.]

**B6. Repeated or serious breaches.** Removing a seminar does not limit what else we may do under the Terms, including
suspending or ending your account.

**B7. Your personal information in this.** We keep the moderator's record (your seminar, the reason, who decided and
when) and the report (the reason given, the note, who made it, and the decision). The report is visible to moderators
only. [Adviser: POPIA notice, lawful basis, retention period for moderation records.]

---

### Open questions for the adviser

1. Is a term accepted by clicking in InfoDot enough, and who has authority to accept for a business host?
2. May InfoDot recover as a larger fee at the point of sale, or only through a separate invoice or set-off statement?
3. Consumer protection and the host's own obligations to attendees: does a chargeback recovery change who is the
   merchant of record (see "Owner decisions still open" in `seminar-payments.md`)?
4. VAT treatment of the larger fee.
5. Is the 50% cap right, and should the host be able to choose a different arrangement?
6. Is a debt that is never recovered (a host who stops selling) written off after a fixed period?
7. Removal (Part B): are the grounds and the three refund outcomes after the start acceptable? Does removal for misleading
   content change who bears the Paystack fee on the refunds?
8. Appeal (B5): is one appeal within 30 days, decided by a different moderator, enough? Should a reply deadline be promised?
9. Is a moderator's decision to remove a seminar a decision about the host's rights that needs more process than this?

### What the software does, for the adviser's reference

- Acceptance: one record per host and version (`host_agreements`: who, when, which version).
- A lost chargeback creates one debt per ticket (`host_debts`).
- A moderator's removal (`SeminarService::removeByModerator`) cancels the seminar through the same path as the host
  cancelling it: registrants are told, tickets part-way through paying are released, queued Dot Points are reversed,
  the host is told with the moderator's reason and which refund outcome applied, the open reports on it are closed, and
  a row is added to the append-only moderation record. Paid tickets are refunded in full if the seminar has not
  started; after the start the moderator's choice applies (`all`, `complainants`, `none`, stored as `removed_refunds`).
  It creates no debt.
- An appeal (`SeminarService::appealRemoval`, `decideRemovalAppeal`; table `seminar_removal_appeals`): one per removal,
  within `removal_appeal_days` (30); decided by a moderator other than the one who removed it unless there is only one;
  reinstating works only before the start; it writes `seminar_reinstated` or `seminar_appeal_denied` to the moderation
  record and tells the host (and, on reinstating, the registered people).
- The larger fee is added to a ticket when it is created and is credited to the debt only when the ticket's payment is
  verified. See "Chargeback recovery" in `seminar-payments.md` for the exact arithmetic and the list of what is not handled.
