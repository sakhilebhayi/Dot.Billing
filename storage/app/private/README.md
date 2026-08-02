# Private keys

This directory is gitignored (`/storage/app/private`, see `.gitignore`) and holds
secrets that must never be committed.

## `dkp-signing.key`

A single line of base64: the raw 32-byte Ed25519 seed for this platform's
Dot Knowledge Protocol signing key, `dot-billing-dkp-signing-v1` — the format
`sodium_crypto_sign_seed_keypair()` expects directly, not a PEM envelope. Its
public half is committed at [`platform.dkp.json`](../../../platform.dkp.json)
in the repo root — the two must always correspond, or every pack this
platform signs will fail verification against the manifest everyone else
trusts.

A real key already exists for this platform (generated 2026-08-02, one-time,
outside any CI system, using Node's `crypto.generateKeyPairSync('ed25519')`
since this environment has no PHP/sodium runtime to generate it via Artisan;
the raw 32-byte seed was extracted from the PKCS8 DER export). If it is ever
lost or rotated:

1. Generate a new Ed25519 keypair.
2. Write the new public key to a new entry in `platform.dkp.json`'s `keys[]`
   array with a new `key_id` (e.g. `dot-billing-dkp-signing-v2`) and a
   `valid_from` date; add `valid_to` to the old entry rather than deleting it,
   so packs signed under the old key still verify.
3. Replace this file and update `DKP_KEY_ID` in `.env`.

Do not reuse a `key_id` for two different keys.
