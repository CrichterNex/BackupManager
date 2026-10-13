<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\ApiClient;
use Tests\TestCase;

/** Two-step sign-in with an authenticator app: setup, sign-in, recovery, resets and enforcement. */
class MfaTest extends TestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['bms.mfa_required' => true]);
        $this->companyId = $this->makeCompany('mfaco');
        $this->makeUser($this->companyId, 'admin', 'company_admin');
        $this->makeUser($this->companyId, 'alice', 'company_technician');
        $this->makeUser($this->companyId, 'bob', 'company_manager');
    }

    private function password(string $user): array
    {
        $this->anonymous();
        $res = $this->postJson('/api/auth/login', ['username' => $user, 'password' => self::PASSWORD]);
        $this->assertSame(200, $res->status());

        return [$res, $res->json('token') ? new ApiClient($this, $res->json('token')) : null];
    }

    /** Set up MFA for a user from scratch, the way the web app does. @return array{0: string, 1: list<string>, 2: ApiClient} */
    private function enrol(string $user): array
    {
        [, $client] = $this->password($user);
        $setup = $client->post('/api/me/mfa/setup');
        $this->assertSame(200, $setup->status());
        $secret = $setup->json('data.secret');
        $enable = $client->post('/api/me/mfa/enable', ['code' => Totp::codeAt($secret, Totp::step())]);
        $this->assertSame(200, $enable->status(), $enable->getContent());

        return [$secret, $enable->json('recovery_codes'), $client];
    }

    private function code(string $secret, int $stepsAhead = 0): string
    {
        return Totp::codeAt($secret, Totp::step() + $stepsAhead);
    }

    private function challenge(string $user): string
    {
        [$res] = $this->password($user);
        $this->assertTrue($res->json('mfa_required'));

        return $res->json('mfa_token');
    }

    private function verify(string $token, array $body)
    {
        $this->anonymous();

        return $this->postJson('/api/auth/mfa/verify', ['mfa_token' => $token] + $body);
    }

    // --- setting up ---

    public function test_setup_gives_a_secret_and_qr_and_changes_nothing_until_a_code_is_proven(): void
    {
        [, $client] = $this->password('alice');
        $setup = $client->post('/api/me/mfa/setup');
        $this->assertSame(200, $setup->status());
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $setup->json('data.secret'));
        $this->assertStringStartsWith('otpauth://totp/BMS:alice?secret='.$setup->json('data.secret'), $setup->json('data.otpauth_uri'));
        $this->assertStringContainsString('<svg', $setup->json('data.qr_svg'));
        $this->assertFalse($client->get('/api/me')->json('data.mfa.enabled'), 'not enabled until proven');
        $this->assertSame(400, $client->post('/api/me/mfa/enable', ['code' => '000000'])->status());
        $this->assertFalse($client->get('/api/me')->json('data.mfa.enabled'));
    }

    public function test_enabling_returns_ten_recovery_codes_once_and_stores_only_hashes(): void
    {
        [$secret, $codes] = $this->enrol('alice');
        $this->assertCount(10, $codes);
        foreach ($codes as $c) {
            $this->assertMatchesRegularExpression('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $c);
        }
        $row = DB::table('users')->where('username', 'alice')->first();
        $this->assertStringNotContainsString($secret, (string) $row->mfa_secret, 'the secret is encrypted at rest');
        $this->assertNull($row->mfa_pending_secret);
        $this->assertStringNotContainsString($codes[0], (string) $row->mfa_recovery_codes, 'recovery codes are only hashed');
        $this->assertCount(10, json_decode($row->mfa_recovery_codes, true));
        $this->assertSame(1, DB::table('audit_trail')->where('action', 'mfa_enabled')->count());
    }

    public function test_secrets_are_never_returned_by_any_listing(): void
    {
        [$secret, $codes] = $this->enrol('alice');
        $admin = $this->adminClient();
        foreach (['/api/users', '/api/me'] as $url) {
            $body = $admin->get($url)->getContent();
            $this->assertStringNotContainsString($secret, $body);
            $this->assertStringNotContainsString('mfa_secret', $body);
            $this->assertStringNotContainsString('mfa_recovery_codes', $body);
        }
        $alice = collect($admin->get('/api/users')->json('data'))->firstWhere('username', 'alice');
        $this->assertTrue($alice['mfa_enabled']);
    }

    // --- required: nothing works until it is set up ---

    public function test_a_user_who_has_not_set_it_up_can_only_set_it_up(): void
    {
        [$res, $client] = $this->password('alice');
        $this->assertTrue($res->json('mfa_setup_required'));
        $this->assertSame(200, $client->get('/api/me')->status());
        $this->assertTrue($client->get('/api/me')->json('data.mfa.setup_required'));
        foreach (['/api/servers', '/api/dashboard', '/api/backup-jobs', '/api/notifications'] as $url) {
            $r = $client->get($url);
            $this->assertSame(403, $r->status(), $url);
            $this->assertSame('MFA_REQUIRED', $r->json('error_code'), $url);
        }
        $this->assertSame(200, $client->post('/api/me/mfa/setup')->status());
    }

    public function test_finishing_setup_unlocks_the_session_immediately(): void
    {
        [, $client] = $this->password('alice');
        $this->assertSame(403, $client->get('/api/servers')->status());
        $secret = $client->post('/api/me/mfa/setup')->json('data.secret');
        $client->post('/api/me/mfa/enable', ['code' => $this->code($secret)]);
        $this->assertSame(200, $client->get('/api/servers')->status());
    }

    public function test_it_applies_to_sessions_that_already_existed(): void
    {
        config(['bms.mfa_required' => false]);
        [, $client] = $this->password('alice');
        $this->assertSame(200, $client->get('/api/servers')->status());
        config(['bms.mfa_required' => true]);
        $this->assertSame(403, $client->get('/api/servers')->status(), 'the same session is now stopped');
    }

    public function test_when_it_is_not_required_nobody_is_forced_but_enrolled_users_are_still_asked(): void
    {
        config(['bms.mfa_required' => false]);
        [$res, $client] = $this->password('bob');
        $this->assertFalse($res->json('mfa_setup_required'));
        $this->assertSame(200, $client->get('/api/servers')->status());
        $secret = $client->post('/api/me/mfa/setup')->json('data.secret');
        $client->post('/api/me/mfa/enable', ['code' => $this->code($secret)]);
        [$again] = $this->password('bob');
        $this->assertTrue($again->json('mfa_required'), 'once on, it is always asked');
    }

    // --- signing in ---

    public function test_the_password_alone_gives_no_session_once_mfa_is_on(): void
    {
        $this->enrol('alice');
        [$res, $client] = $this->password('alice');
        $this->assertNull($client, 'no token');
        $this->assertTrue($res->json('mfa_required'));
        $this->assertNotEmpty($res->json('mfa_token'));
        $this->assertNull(collect($res->headers->getCookies())->first(fn ($c) => $c->getName() === 'auth_token'), 'and no cookie');
        $this->assertStringNotContainsString('"role"', $res->getContent());
    }

    public function test_a_right_code_completes_the_sign_in_and_it_is_audited(): void
    {
        [$secret] = $this->enrol('alice');
        $res = $this->verify($this->challenge('alice'), ['code' => $this->code($secret, 1)]);
        $this->assertSame(200, $res->status());
        $this->assertNotEmpty($res->json('token'));
        $this->assertFalse($res->json('mfa_setup_required'));
        $cookie = collect($res->headers->getCookies())->first(fn ($c) => $c->getName() === 'auth_token');
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(200, (new ApiClient($this, $res->json('token')))->get('/api/servers')->status());
        $login = DB::table('audit_trail')->where('action', 'login')->orderByDesc('id')->first();
        $this->assertSame('code', json_decode($login->details, true)['mfa']);
    }

    public function test_a_wrong_code_is_refused_and_audited(): void
    {
        $this->enrol('alice');
        $res = $this->verify($this->challenge('alice'), ['code' => '000000']);
        $this->assertSame(401, $res->status());
        $this->assertSame('MFA_002', $res->json('error_code'));
        $this->assertNull($res->json('token'));
        $this->assertSame(1, DB::table('audit_trail')->where('action', 'mfa_failed')->where('success', 0)->count());
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        [$secret] = $this->enrol('alice');
        $code = $this->code($secret, 1);
        $this->assertSame(200, $this->verify($this->challenge('alice'), ['code' => $code])->status());
        $this->assertSame(401, $this->verify($this->challenge('alice'), ['code' => $code])->status(), 'captured and replayed');
        $this->travel(30)->seconds();
        $this->assertSame(200, $this->verify($this->challenge('alice'), ['code' => $this->code($secret, 1)])->status(), 'the next code works');
    }

    public function test_five_wrong_codes_lock_the_challenge_even_for_the_right_code(): void
    {
        [$secret] = $this->enrol('alice');
        $token = $this->challenge('alice');
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(401, $this->verify($token, ['code' => '111111'])->status());
        }
        $res = $this->verify($token, ['code' => $this->code($secret, 1)]);
        $this->assertSame(429, $res->status());
        $this->assertSame('MFA_LOCKED', $res->json('error_code'));
    }

    public function test_repeated_failures_lock_the_user_across_new_challenges(): void
    {
        [$secret] = $this->enrol('alice');
        for ($i = 0; $i < 6; $i++) {
            $this->verify($this->challenge('alice'), ['code' => '222222']);
        }
        $this->assertSame(429, $this->verify($this->challenge('alice'), ['code' => $this->code($secret, 1)])->status());
        $this->travel(16)->minutes();
        $this->assertSame(200, $this->verify($this->challenge('alice'), ['code' => $this->code($secret, 1)])->status(), 'the lock expires');
    }

    public function test_a_forged_expired_or_used_challenge_is_refused(): void
    {
        [$secret] = $this->enrol('alice');
        $this->assertSame(401, $this->verify('not-a-token', ['code' => $this->code($secret, 1)])->status());
        $this->assertSame(401, $this->verify(str_repeat('A', 200), ['code' => $this->code($secret, 1)])->status());
        $token = $this->challenge('alice');
        $this->travel(6)->minutes();
        $this->assertSame('MFA_001', $this->verify($token, ['code' => $this->code($secret, 1)])->json('error_code'), 'expired');
        $this->travelBack();
        $fresh = $this->challenge('alice');
        $this->assertSame(200, $this->verify($fresh, ['code' => $this->code($secret, 1)])->status());
        $this->travel(31)->seconds();
        $this->assertSame(401, $this->verify($fresh, ['code' => $this->code($secret, 2)])->status(), 'a challenge works once');
    }

    public function test_a_challenge_for_a_user_disabled_in_the_meantime_is_refused(): void
    {
        [$secret] = $this->enrol('alice');
        $token = $this->challenge('alice');
        DB::table('users')->where('username', 'alice')->update(['active' => 0]);
        $this->assertSame(401, $this->verify($token, ['code' => $this->code($secret, 1)])->status());
    }

    public function test_a_code_or_a_recovery_code_must_be_given(): void
    {
        $this->enrol('alice');
        $this->assertSame(400, $this->verify($this->challenge('alice'), [])->status());
    }

    // --- recovery codes ---

    public function test_a_recovery_code_works_once_and_is_recorded(): void
    {
        [, $codes] = $this->enrol('alice');
        $res = $this->verify($this->challenge('alice'), ['recovery_code' => strtoupper(str_replace('-', ' ', $codes[0]))]);
        $this->assertSame(200, $res->status(), 'case, spaces and the dash are ignored');
        $this->assertSame(401, $this->verify($this->challenge('alice'), ['recovery_code' => $codes[0]])->status(), 'already used');
        $this->assertSame(401, $this->verify($this->challenge('alice'), ['recovery_code' => 'aaaaa-bbbbb'])->status());
        $this->assertSame(9, (new ApiClient($this, $res->json('token')))->get('/api/me')->json('data.mfa.recovery_codes_left'));
        $this->assertSame(1, DB::table('audit_trail')->where('action', 'mfa_recovery_code_used')->count());
    }

    public function test_regenerating_recovery_codes_needs_password_and_code_and_replaces_the_old_ones(): void
    {
        [$secret, $codes, $client] = $this->enrol('alice');
        $this->assertSame(401, $client->post('/api/me/mfa/recovery-codes', ['password' => 'wrong-wrong-wrong', 'code' => $this->code($secret, 1)])->status());
        $this->assertSame(401, $client->post('/api/me/mfa/recovery-codes', ['password' => self::PASSWORD, 'code' => '000000'])->status());
        $new = $client->post('/api/me/mfa/recovery-codes', ['password' => self::PASSWORD, 'code' => $this->code($secret, 1)]);
        $this->assertSame(200, $new->status());
        $this->assertCount(10, $new->json('recovery_codes'));
        $this->assertSame(401, $this->verify($this->challenge('alice'), ['recovery_code' => $codes[1]])->status(), 'the old set is dead');
        $this->assertSame(200, $this->verify($this->challenge('alice'), ['recovery_code' => $new->json('recovery_codes')[0]])->status());
    }

    // --- changing device, turning off ---

    public function test_changing_device_needs_password_and_current_code_and_retires_the_old_secret(): void
    {
        [$old, , $client] = $this->enrol('alice');
        $this->assertSame(400, $client->post('/api/me/mfa/setup')->status(), 'refused without proof');
        $this->assertSame(401, $client->post('/api/me/mfa/setup', ['password' => 'wrong-wrong-wrong', 'code' => $this->code($old, 1)])->status());
        $setup = $client->post('/api/me/mfa/setup', ['password' => self::PASSWORD, 'code' => $this->code($old, 1)]);
        $this->assertSame(200, $setup->status());
        $new = $setup->json('data.secret');
        $this->travel(30)->seconds();
        $this->assertSame(200, $this->verify($this->challenge('alice'), ['code' => $this->code($old, 1)])->status(), 'the old app still works until the new one is proven');
        $this->assertSame(200, $client->post('/api/me/mfa/enable', ['code' => $this->code($new)])->status());
        $this->travel(30)->seconds();
        $this->assertSame(401, $this->verify($this->challenge('alice'), ['code' => $this->code($old, 1)])->status(), 'the old secret no longer works');
        $this->assertSame(200, $this->verify($this->challenge('alice'), ['code' => $this->code($new, 1)])->status());
        $this->assertSame(1, DB::table('audit_trail')->where('action', 'mfa_device_changed')->count());
    }

    public function test_it_cannot_be_turned_off_while_required_but_can_when_optional(): void
    {
        [$secret, , $client] = $this->enrol('alice');
        $this->assertSame(400, $client->post('/api/me/mfa/disable', ['password' => self::PASSWORD, 'code' => $this->code($secret, 1)])->status());
        config(['bms.mfa_required' => false]);
        $this->assertSame(401, $client->post('/api/me/mfa/disable', ['password' => self::PASSWORD, 'code' => '000000'])->status());
        $this->assertSame(200, $client->post('/api/me/mfa/disable', ['password' => self::PASSWORD, 'code' => $this->code($secret, 1)])->status());
        $this->assertNull(DB::table('users')->where('username', 'alice')->value('mfa_secret'));
        $this->assertSame(1, DB::table('audit_trail')->where('action', 'mfa_disabled')->count());
    }

    // --- resets ---

    public function test_an_admin_can_reset_a_members_mfa_which_also_signs_them_out(): void
    {
        [, , $aliceSession] = $this->enrol('alice');
        $this->assertSame(200, $aliceSession->get('/api/me')->status());
        $admin = $this->adminClient();
        $aliceId = (int) DB::table('users')->where('username', 'alice')->value('id');
        $res = $admin->post("/api/users/$aliceId/mfa/reset");
        $this->assertSame(200, $res->status());
        $this->assertFalse($res->json('data.mfa_enabled'));
        $this->assertSame(401, $aliceSession->get('/api/me')->status(), 'every session of theirs ended');
        [$login, $client] = $this->password('alice');
        $this->assertTrue($login->json('mfa_setup_required'), 'they must set it up again');
        $this->assertSame(403, $client->get('/api/servers')->status());
        $this->assertSame(1, DB::table('audit_trail')->where('action', 'mfa_reset')->count());
    }

    private function adminClient(): ApiClient
    {
        $this->enrol('admin');
        $secret = $this->lastSecret();

        return $this->signInClient('admin', $secret);
    }

    private function lastSecret(): string
    {
        return (string) User::where('username', 'admin')->value('mfa_secret');
    }

    private function signInClient(string $user, string $secret): ApiClient
    {
        $this->travel(30)->seconds();
        $res = $this->verify($this->challenge($user), ['code' => $this->code($secret, 1)]);
        $this->assertSame(200, $res->status(), $res->getContent());

        return new ApiClient($this, $res->json('token'));
    }

    public function test_you_cannot_reset_your_own_and_non_admins_cannot_reset_anyone(): void
    {
        $admin = $this->adminClient();
        $adminId = (int) DB::table('users')->where('username', 'admin')->value('id');
        $this->assertSame(400, $admin->post("/api/users/$adminId/mfa/reset")->status());
        [$secret] = $this->enrol('alice');
        $alice = $this->signInClient('alice', $secret);
        $bobId = (int) DB::table('users')->where('username', 'bob')->value('id');
        $this->assertSame(403, $alice->post("/api/users/$bobId/mfa/reset")->status());
    }

    public function test_a_company_admin_cannot_touch_a_platform_admins_password_role_or_mfa(): void
    {
        $pa = (int) DB::table('users')->where('username', 'bob')->value('id');
        DB::table('users')->where('id', $pa)->update(['role' => 'company_admin', 'is_platform_admin' => 1]);
        $admin = $this->adminClient();
        $this->assertSame(403, $admin->patch("/api/users/$pa", ['password' => 'a-brand-new-password-1'])->status(), 'taking over the account by resetting the password');
        $this->assertSame(403, $admin->patch("/api/users/$pa", ['active' => false])->status());
        $this->assertSame(403, $admin->patch("/api/users/$pa", ['role' => 'company_technician'])->status());
        $this->assertSame(403, $admin->post("/api/users/$pa/mfa/reset")->status());
        $this->assertSame(1, (int) DB::table('users')->where('id', $pa)->value('is_platform_admin'));
    }

    public function test_a_platform_admin_can_reset_anyones_mfa_from_any_company(): void
    {
        $other = $this->makeCompany('otherco');
        $this->makeUser($other, 'carol', 'company_admin');
        $this->enrol('carol');
        $root = (int) $this->makeUser($this->companyId, 'root', 'company_admin');
        DB::table('users')->where('id', $root)->update(['is_platform_admin' => 1]);
        $this->enrol('root');
        $rootClient = $this->signInClient('root', $this->rootSecret());
        $carolId = (int) DB::table('users')->where('username', 'carol')->value('id');
        $this->assertSame(200, $rootClient->post("/api/platform/users/$carolId/mfa/reset")->status());
        $this->assertNull(DB::table('users')->where('id', $carolId)->value('mfa_enabled_at'));
        $this->assertNotNull(DB::table('audit_trail')->where('action', 'mfa_reset')->where('company_id', $other)->first(), 'logged in her own company');
    }

    private function rootSecret(): string
    {
        return (string) User::where('username', 'root')->value('mfa_secret');
    }

    public function test_the_operator_command_resets_mfa(): void
    {
        [, , $session] = $this->enrol('alice');
        $this->assertSame(0, Artisan::call('bms:mfa-reset', ['username' => 'alice']));
        $this->assertNull(DB::table('users')->where('username', 'alice')->value('mfa_enabled_at'));
        $this->assertSame(401, $session->get('/api/me')->status());
        $this->assertSame(1, Artisan::call('bms:mfa-reset', ['username' => 'nobody']));
    }

    // --- other ways in are unaffected ---

    public function test_agents_do_not_use_mfa(): void
    {
        $admin = $this->adminClient();
        $server = $admin->post('/api/servers', ['hostname' => 'a-01', 'os_type' => 'linux'])->json('data.id');
        $secret = $admin->post("/api/servers/$server/agent")->json('agent_config.token');
        Auth::forgetGuards();
        $this->flushHeaders();
        $this->withHeaders(['Authorization' => "Bearer $secret"])->postJson('/api/agent/poll', ['version' => '1'])->assertOk();
    }
}
