<?php

namespace App\Services;

use App\Models\BackupTestExecution;
use App\Support\ApiException;
use App\Support\CompanyContext;
use App\Support\Constants;
use Illuminate\Support\Facades\DB;

/**
 * Which tests a company uses. The platform admin chooses which tests a company is offered (available), and the company's own admin can turn
 * one of those off (enabled), for example when they do not have the backup solution it needs. A test is in use only when both are on.
 * A company with no settings has every test, so nothing changes for existing companies until someone changes it.
 */
final class CompanyTests
{
    /** @return array<string, array{available: bool, enabled: bool}> */
    public static function states(int $companyId): array
    {
        $rows = DB::table('company_test_settings')->where('company_id', $companyId)->get()->keyBy('test_type');
        $out = [];
        foreach (Constants::TEST_TYPES as $type) {
            $r = $rows[$type] ?? null;
            $out[$type] = ['available' => $r ? (bool) $r->available : true, 'enabled' => $r ? (bool) $r->enabled : true];
        }

        return $out;
    }

    /** Every test with what it is and whether this company can use it, for the settings screens and the forms that offer tests. */
    public static function listing(int $companyId): array
    {
        $out = [];
        foreach (self::states($companyId) as $type => $s) {
            $h = Constants::TEST_HELP[$type] ?? [];
            $out[] = ['type' => $type, 'title' => $h['title'] ?? $type, 'summary' => $h['summary'] ?? '', 'automated' => in_array($type, Constants::ENGINE_TEST_TYPES, true)]
                + $s + ['active' => $s['available'] && $s['enabled']];
        }

        return $out;
    }

    public static function active(string $type, ?int $companyId = null): bool
    {
        $s = self::states($companyId ?? (int) CompanyContext::id())[$type] ?? null;

        return $s !== null && $s['available'] && $s['enabled'];
    }

    /** Refuse to create or queue a test the company does not use, saying whose decision it is. */
    public static function assertActive(string $type): void
    {
        $s = self::states((int) CompanyContext::id())[$type] ?? null;
        $name = Constants::TEST_HELP[$type]['title'] ?? $type;
        if ($s && ! $s['available']) {
            throw ApiException::conflict('TEST_011', "The $name test is not available to your company. Ask your platform administrator if you need it.");
        }
        if ($s && ! $s['enabled']) {
            throw ApiException::conflict('TEST_011', "The $name test is turned off for your company. A company admin can turn it back on from the Tests page.");
        }
    }

    /**
     * Change one or both switches for a company and wind down anything that depended on the test being on.
     *
     * @param  array{available?: bool, enabled?: bool}  $changes
     */
    public static function set(int $companyId, string $type, array $changes, ?int $userId): void
    {
        DB::transaction(function () use ($companyId, $type, $changes, $userId) {
            $row = DB::table('company_test_settings')->where('company_id', $companyId)->where('test_type', $type)->lockForUpdate()->first();
            $new = [
                'available' => $changes['available'] ?? ($row ? (bool) $row->available : true),
                'enabled' => $changes['enabled'] ?? ($row ? (bool) $row->enabled : true),
                'updated_by' => $userId, 'updated_at' => now(),
            ];
            if ($row) {
                DB::table('company_test_settings')->where('id', $row->id)->update($new);
            } else {
                DB::table('company_test_settings')->insert($new + ['company_id' => $companyId, 'test_type' => $type, 'created_at' => now()]);
            }
            if (! ($new['available'] && $new['enabled'])) {
                self::windDown($companyId, $type);
            }
        });
    }

    /** A test that is no longer in use leaves nothing waiting: open manual tests are cancelled and queued automatic ones are skipped, with the reason. */
    private static function windDown(int $companyId, string $type): void
    {
        CompanyContext::run($companyId, function () use ($type) {
            BackupTestExecution::where('manual', true)->where('test_type', $type)->whereIn('test_status', ['pending', 'running'])
                ->update(['test_status' => 'cancelled', 'completed_time' => now(), 'error_message' => 'Cancelled: this test was turned off for the company.', 'updated_at' => now()]);
            foreach (BackupTestExecution::where('manual', false)->where('test_type', $type)->where('test_status', 'pending')->pluck('id') as $id) {
                TestResults::skip($id, 'This test was turned off for the company.');
            }
        });
    }
}
