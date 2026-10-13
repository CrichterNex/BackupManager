<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\CompanyAccess;
use App\Support\ApiException;
use App\Support\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The token only identifies the user. Company, role and active state are read fresh from the database on
 * every request, so disabling a user or changing a role takes effect immediately.
 *
 * By default the company is the user's own. A user may name another company with the X-Acting-Company header if they
 * are a platform admin (any company, as admin) or hold an access grant for it (that company, with the granted role).
 * Anyone else sending the header is refused, not ignored, so a mistake or an attempt is visible instead of quietly
 * working on the wrong data.
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
        $user->actingRole = null;
        $acting = $request->header(self::ACTING_HEADER);
        if ($acting !== null && $acting !== '') {
            if (! ctype_digit($acting)) {
                throw ApiException::notFound('Company', 'COMPANY_001');
            }
            $target = (int) $acting;
            if ($target !== $user->company_id) {
                $company = Company::find($target);
                if (! $company) {
                    throw ApiException::notFound('Company', 'COMPANY_001');
                }
                if ($user->is_platform_admin && $user->role === 'company_admin') {
                    $user->actingRole = 'company_admin';
                } else {
                    $grant = CompanyAccess::where('user_id', $user->id)->where('company_id', $target)->first();
                    if (! $grant) {
                        throw ApiException::forbidden('You do not have access to that company');
                    }
                    if (! $company->is_active) {
                        throw ApiException::forbidden('That company is disabled');
                    }
                    $user->actingRole = $grant->role;
                }
                $companyId = $target;
            }
        }
        CompanyContext::set($companyId);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        CompanyContext::set(null);
    }
}
