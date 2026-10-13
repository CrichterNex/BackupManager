<?php

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\TestCase;

/** The one-time-code maths, checked against the published test vectors so it matches every authenticator app. */
class TotpTest extends TestCase
{
    /** RFC 6238 Appendix B (SHA-1, secret "12345678901234567890"): time => the 8-digit code. We use 6 digits, the last six. */
    private const VECTORS = [59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'];

    private function secret(): string
    {
        return Totp::base32Encode('12345678901234567890');
    }

    public function test_it_matches_the_rfc_6238_test_vectors(): void
    {
        foreach (self::VECTORS as $time => $eight) {
            $this->assertSame(substr($eight, -6), Totp::codeAt($this->secret(), Totp::step($time)), "time $time");
        }
    }

    public function test_base32_matches_rfc_4648_and_round_trips(): void
    {
        $this->assertSame('MZXW6YTBOI', Totp::base32Encode('foobar'));
        $this->assertSame('foobar', Totp::base32Decode('MZXW6YTBOI'));
        $this->assertSame('foobar', Totp::base32Decode('mzxw 6ytb-oi==='), 'spaces, dashes, padding and case are ignored, as apps show secrets in groups');
        $bytes = random_bytes(20);
        $this->assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
        $this->expectException(\InvalidArgumentException::class);
        Totp::base32Decode('not*valid');
    }

    public function test_a_code_is_accepted_for_its_step_and_one_either_side_only(): void
    {
        $now = 1111111109;
        $step = Totp::step($now);
        $code = Totp::codeAt($this->secret(), $step);
        $this->assertSame($step, Totp::verify($this->secret(), $code, 0, $now));
        $this->assertSame($step, Totp::verify($this->secret(), $code, 0, $now + 30), 'a phone clock one step behind');
        $this->assertSame($step, Totp::verify($this->secret(), $code, 0, $now - 30), 'a phone clock one step ahead');
        $this->assertNull(Totp::verify($this->secret(), $code, 0, $now + 90), 'three steps away is too far');
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $now = 1234567890;
        $step = Totp::step($now);
        $code = Totp::codeAt($this->secret(), $step);
        $used = Totp::verify($this->secret(), $code, 0, $now);
        $this->assertNull(Totp::verify($this->secret(), $code, $used, $now), 'the same code again');
        $this->assertNull(Totp::verify($this->secret(), Totp::codeAt($this->secret(), $step - 1), $used, $now), 'and an older one');
        $this->assertSame($step + 1, Totp::verify($this->secret(), Totp::codeAt($this->secret(), $step + 1), $used, $now), 'but the next one works');
    }

    public function test_wrong_and_malformed_codes_are_refused(): void
    {
        $now = 59;
        foreach (['000000', '12345', '1234567', 'abcdef', '', '94 28 70', '９４２８７０'] as $bad) {
            $this->assertNull(Totp::verify($this->secret(), $bad, 0, $now), "'$bad'");
        }
        $this->assertNotNull(Totp::verify($this->secret(), substr(self::VECTORS[59], -6), 0, $now));
        $this->assertNotNull(Totp::verify($this->secret(), substr(chunk_split(substr(self::VECTORS[59], -6), 3, ' '), 0), 0, $now), 'a code typed with a space still works');
    }

    public function test_the_secret_is_random_and_160_bits(): void
    {
        $a = Totp::generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $a);
        $this->assertNotSame($a, Totp::generateSecret());
        $this->assertSame(20, strlen(Totp::base32Decode($a)));
    }

    public function test_the_app_address_names_the_account_and_issuer(): void
    {
        $uri = Totp::uri('JBSWY3DPEHPK3PXP', 'alice@example.com', 'BMS Corp');
        $this->assertSame('otpauth://totp/BMS%20Corp:alice%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=BMS%20Corp&algorithm=SHA1&digits=6&period=30', $uri);
    }
}
