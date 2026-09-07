<?php

/**
 * Cloudflare Free plan checklist for rimboai.com (ops — activate in dashboard).
 *
 * Domain is already orange-cloud proxied. Recommended Free toggles:
 * - Security → Bot Fight Mode: On
 * - Leaked credentials mitigation: On
 * - Manage AI bot access → Block AI training bots: On
 * - Under Attack Mode: Off (unless actively under attack)
 * - SSL/TLS: Full (strict) when origin has a valid certificate
 *
 * App-side: Turnstile on signup + 24h one-account-per-IP (see SignupIpGuard).
 */

return [
    //
];
