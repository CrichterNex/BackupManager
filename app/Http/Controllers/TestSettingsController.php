<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\CompanyTests;
use App\Support\ApiException;
use App\Support\CompanyContext;
use App\Support\Constants;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Which tests this company uses. A company admin turns off a test they do not need, among those the platform admin has made available. */
class TestSettingsController extends Controller
{
    public function index()
    {
        return response()->json(['success' => true, 'data' => CompanyTests::listing((int) CompanyContext::id())]);
    }

    public function update(Request $request)
    {
        $b = $request->validate(['tests' => ['required', 'array', 'min:1'], 'tests.*' => ['boolean']]);
        $companyId = (int) CompanyContext::id();
        DB::transaction(function () use ($b, $request, $companyId) {
            $states = CompanyTests::states($companyId);
            $changed = [];
            foreach ($b['tests'] as $type => $on) {
                if (! in_array($type, Constants::TEST_TYPES, true)) {
                    throw ApiException::badRequest("\"$type\" is not a test.");
                }
                if ($on && ! $states[$type]['available']) {
                    throw ApiException::badRequest('The '.(Constants::TEST_HELP[$type]['title'] ?? $type).' test is not available to your company, so it cannot be turned on. Ask your platform administrator.');
                }
                if ($states[$type]['enabled'] !== (bool) $on) {
                    CompanyTests::set($companyId, $type, ['enabled' => (bool) $on], $request->user()->id);
                    $changed[$type] = (bool) $on;
                }
            }
            if ($changed) {
                Audit::log($request, 'test_settings_changed', 'company', $companyId, ['enabled' => $changed]);
            }
        });

        return response()->json(['success' => true, 'data' => CompanyTests::listing($companyId)]);
    }
}
