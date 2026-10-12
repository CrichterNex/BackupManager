<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\ApiException;
use App\Support\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The token only identifies the user. Company, role and active state are read fresh from the database on
 * every request, so disabling a user or changing a role takes effect immediately.
 *
 * By default the company is the user's own. A platform admin may name another company with the
 * X-Acting-Company header and then works inside it as an admin. Anyone else sending that header is refused,
 * not ignored, so a mistake or an attempt is visible instead of quietly working on the wrong data.
 */
class ResolveTenant
{
    public const ACTING_HEADER = 'X-Acting-Company';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $user?->loadMissing('company');
        if (! $user || ! $user->active || ! $user->company?->is_active) {
            throw ApiException::unauthorized('Account is disabled');
        }

        $companyId = $user->company_id;
        $acting = $request->header(self::ACTING_HEADER);
        if ($acting !== null && $acting !== '') {
            if (! $user->is_platform_admin || $user->role !== 'company_admin') {
                throw ApiException::forbidden('Only platform admins can work inside another company');
            }
            if (! ctype_digit($acting) || ! Company::whereKey((int) $acting)->exists()) {
                throw ApiException::notFound('Company', 'COMPANY_001');
            }
            $companyId = (int) $acting;
        }
        CompanyContext::set($companyId);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        CompanyContext::set(null);
    }
}
