<?php

return [
    /*
    | Strict-Transport-Security max-age in seconds, sent on HTTPS responses only. 0 turns the header off.
    | includeSubDomains and preload are deliberately not sent: the Dot platforms share a parent domain with
    | roughly 27 sibling subdomains that are not all ready for HTTPS-only. Raise the age once the site has been
    | stable on HTTPS.
    */
    'hsts_seconds' => (int) env('SECURITY_HSTS_SECONDS', 15552000),   // 180 days
];
