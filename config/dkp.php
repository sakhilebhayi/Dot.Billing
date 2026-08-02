<?php

return [

    /*
    |--------------------------------------------------------------------
    | Dot Knowledge Protocol — signing key
    |--------------------------------------------------------------------
    |
    | Path to the Ed25519 signing key — a single line of base64, the raw
    | 32-byte seed sodium_crypto_sign_seed_keypair() expects, not a PEM
    | envelope. Never committed — see storage/app/private/README.md.
    | Public half is committed at platform.dkp.json (key_id must match).
    |
    */

    'signing_key_path' => env('DKP_SIGNING_KEY_PATH', storage_path('app/private/dkp-signing.key')),

    'key_id' => env('DKP_KEY_ID', 'dot-billing-dkp-signing-v1'),

    'platform' => 'dot-billing',

    'dkp_version' => '1.0.0',

    /*
    |--------------------------------------------------------------------
    | Output
    |--------------------------------------------------------------------
    |
    | Signed packs are written here, not transmitted anywhere — DKP's
    | transport layer (mTLS, tenant topics) is unbuilt ecosystem-wide.
    | See Dot.Brain os/05-Knowledge-Protocol.md §6.
    |
    */

    'output_path' => storage_path('app/dkp/packs'),

];
