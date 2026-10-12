<?php

namespace App\Http\Middleware;

use App\Models\Agent;
use App\Support\ApiException;
use App\Support\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an agent by its secret. The agent's company comes from the database, so an agent can only ever
 * see and report on its own company's commands, and only its own.
 */
class AgentAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = $request->bearerToken();
        $agent = $secret ? Agent::withoutGlobalScopes()->where('token_hash', hash('sha256', $secret))->where('active', true)->first() : null;
        if (! $agent) {
            throw ApiException::unauthorized('Invalid agent token', 'AGENT_401');
        }
        CompanyContext::set($agent->company_id);
        $request->attributes->set('agent', $agent);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        CompanyContext::set(null);
    }
}
