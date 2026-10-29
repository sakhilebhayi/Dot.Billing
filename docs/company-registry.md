# Company registry

A company created in InfoDot did not exist in Dot.Billing until its owner or an admin signed in to Billing from
InfoDot (`SyncTeamsFromInfoDot` creates the team then). Until then `GET /api/entitlements/{company_uuid}` answered
404, and a company deleted or renamed in InfoDot was never reflected. The registry fixes that without creating
teams or users from an API call (that would mean accounts nobody can sign in to).

## `POST /api/companies`

Server to server. Only the platform key `infodot` may call it, signed exactly like the entitlements call:

| Header | Value |
|---|---|
| `X-InfoDot-Platform` | `infodot` |
| `X-InfoDot-Timestamp` | unix time (within 5 minutes either side) |
| `X-InfoDot-Signature` | `sha256=` + HMAC-SHA256 of `"{timestamp}.{raw body}"` with `BILLING_ENTITLEMENT_SECRET_INFODOT` |

Authentication happens before the body is validated or anything is looked up. An unknown or other platform, a blank
secret, a bad, tampered or stale signature all give the same generic `401 {"error":"invalid_request"}`. The route
is throttled like the entitlements route (`throttle:120,1`).

Body (JSON):

```json
{ "company_uuid": "3f1c2a64-8d0b-4c7e-9a52-1b6e4f7d9c01", "name": "Meridian Holdings", "deleted": false }
```

- `company_uuid`: required, a uuid (stored lower case)
- `name`: required, at most 120 characters
- `deleted`: optional boolean (`null` counts as absent)

A bad body is a `422` with Laravel's usual error list. Otherwise `200 {"status": ...}`:

| status | meaning |
|---|---|
| `registered` | first time this company was seen (not deleted) |
| `updated` | something changed: a rename, or a deleted company was restored |
| `unchanged` | the same facts as before; nothing was written and no audit entry was made |
| `deleted` | the company is now recorded (or newly flagged) as deleted |

The call is idempotent: the same body twice changes nothing and writes no second audit entry.

## What it stores

- `infodot_companies` (`company_uuid` unique, `name`, `registered_at`, `deleted_at`, timestamps): one row per
  company InfoDot has told Billing about. It is not a team and no user hangs off it.
- `teams.infodot_deleted_at` (nullable): set on an existing team (matched on `teams.infodot_team_uuid`) when InfoDot
  says its company was deleted; cleared when a later call arrives with `deleted` false or absent.
- When a team exists for the uuid, its `name` is updated to InfoDot's name if it differs.
- One `billing_audit_events` row per change (`company.registered`, `company.renamed`, `company.deleted`,
  `company.restored`), with `team_id` null when there is no team yet. Meta carries the uuid, the name and, for a
  rename, the previous name.

The migration is additive and guarded; running it twice changes nothing.

## Flag, never cancel

A deletion in InfoDot **only flags**. It never cancels, ends or changes a subscription, invoice or payment, and the
entitlements of a team that exists are answered exactly as before. Why:

- Money may still be owed to us (open or overdue invoices) or held for the company (payments, a refund in flight).
  Silently ending a subscription on another system's say-so would destroy a record that finance needs.
- A deletion in InfoDot can be a mistake, and it can be reversed (`deleted` false restores the flag). A cancelled
  subscription cannot be un-cancelled cleanly.
- What to do about a deleted company that still has a subscription (cancel it, refund, write off) is a decision for a
  person with authority, taken in the operator screens with the existing audit trail.

Operators see the flag as a **Deleted in InfoDot** badge on the Entitlement lookup page (`/operator/entitlements`)
and next to the team name in the dunning queue. The audit trail has the `company.deleted` entry. Nothing else in the
UI changes.

## Entitlements for a registered company with no team

`GET /api/entitlements/{company_uuid}` order of answers:

1. A team exists for the uuid: the payload as before (unchanged, including for a team flagged deleted).
2. No team, but the uuid is in `infodot_companies` and not deleted: `200`, same payload shape as a team that never
   subscribed: `status` `none`, `plan` null, free-tier limits from `billing.free_tier`, `extra_seats` 0.
3. Anything else (unknown to both, or in the registry but deleted and without a team): `404 {"error":"not_found"}`.

A company can only become a team here through a sign-in; once someone signs in and the team is created, case 1
applies and the registry row simply keeps tracking its name and deleted state.

## Open points

- InfoDot needs to call this endpoint on company create, rename and delete (the caller is not part of this change),
  and the existing `BILLING_ENTITLEMENT_SECRET_INFODOT` must be set in each environment.
- No clean-up of registry rows is done; a deleted company keeps its row (with `deleted_at`) as the record that it existed.
