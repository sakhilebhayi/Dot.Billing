<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser protections on every response. A header the route already set is left alone, so a stricter route-level
 * value always wins.
 *
 * ENFORCED now: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy and (HTTPS only) Strict-Transport-Security.
 * HSTS is max-age only, with no includeSubDomains and no preload: this domain has many sibling subdomains that are not all ready for it.
 * SECURITY_HSTS_SECONDS in .env changes the age, and 0 turns the header off (see config/security.php).
 *
 * The Content-Security-Policy is REPORT-ONLY (Content-Security-Policy-Report-Only): the browser reports what the policy would have blocked to
 * /csp-report (App\Http\Controllers\CspReportController) and blocks nothing. It is written to be compatible with Livewire and Jetstream, which
 * need inline scripts and 'unsafe-eval' (Alpine evaluates its expressions with the Function constructor). The listed outside origins are the ones
 * the Blade layouts actually load; add one only after a layout starts using it. Enforcing the policy is a later step, taken from the reports.
 *
 * Paystack: paying an invoice or a seminar ticket is a server-side redirect (POST /invoices/{invoice}/pay answers with a redirect to the
 * checkout address Paystack returned; the payer comes back through GET /billing/paystack/callback/{attempt}). No Paystack script, iframe or
 * browser-to-Paystack request exists in this application, so no Paystack origin is listed. If `form-action` is ever added to the policy, it must
 * allow https://checkout.paystack.com, because browsers apply it to the redirect that follows a form post.
 */
class SecurityHeaders
{
    /** Nothing in Dot.Billing uses the camera, the microphone, the location, the Payment Request API or WebUSB. */
    private const PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), payment=(), usb=()';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => self::PERMISSIONS_POLICY,
            'Content-Security-Policy-Report-Only' => $this->policy(),
        ];

        $hstsSeconds = (int) config('security.hsts_seconds', 0);

        if ($request->isSecure() && $hstsSeconds > 0) {
            $headers['Strict-Transport-Security'] = 'max-age='.$hstsSeconds;
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * Outside origins in use, from resources/views: cdn.tailwindcss.com (the Tailwind CDN script in layouts/app.blade.php), Google Fonts
     * (stylesheet host fonts.googleapis.com and font host fonts.gstatic.com, in every layout, the welcome page and the error pages),
     * images.unsplash.com (the hero photograph on the welcome page and the sign-in layout) and ui-avatars.com (Jetstream's default avatar,
     * returned by profile_photo_url on the profile and team pages). The browser makes no request to any other host: Livewire talks to this
     * site only, and there is no Echo or websocket client, so connect-src is 'self'.
     */
    private function policy(): string
    {
        return "default-src 'self'; "
            ."script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com; "
            ."style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
            ."font-src 'self' data: https://fonts.gstatic.com; "
            ."img-src 'self' data: blob: https://images.unsplash.com https://ui-avatars.com; "
            ."connect-src 'self'; "
            ."frame-src 'self'; frame-ancestors 'self'; object-src 'none'; base-uri 'self'; "
            .'report-uri /csp-report';
    }
}
