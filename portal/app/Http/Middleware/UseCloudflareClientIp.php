<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * With a Cloudflare Tunnel, every request reaches PHP from cloudflared on this machine, so the
 * connecting address is always 127.0.0.1. Cloudflare puts the visitor's address in CF-Connecting-IP.
 * We trust that header only when the request really came from the local machine, so rate limits and
 * the audit log see the real visitor and nobody can spoof it from outside.
 */
class UseCloudflareClientIp
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('portal.behind_cloudflare') && in_array($request->server->get('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)) {
            // Once the address is replaced the local proxy is no longer "trusted", so note the scheme first.
            if ($request->headers->get('X-Forwarded-Proto') === 'https') {
                $request->server->set('HTTPS', 'on');
                $request->server->set('SERVER_PORT', 443);
            }
            $ip = $request->headers->get('CF-Connecting-IP');
            if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
                $request->server->set('REMOTE_ADDR', $ip);
            }
        }

        return $next($request);
    }
}
