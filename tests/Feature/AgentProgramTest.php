<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Runs the Python tests for the agent program (tests/agent) when a Python 3 is available on this machine. */
class AgentProgramTest extends TestCase
{
    private function python(): ?string
    {
        $candidates = [base_path('.pgdev/py/python.exe'), 'python3', 'python'];
        foreach ($candidates as $c) {
            $out = [];
            $code = 1;
            @exec(escapeshellarg($c).' -c "import sys; print(sys.version_info >= (3, 8))" 2>&1', $out, $code);
            if ($code === 0 && ($out[0] ?? '') === 'True') {
                return $c;
            }
        }

        return null;
    }

    public function test_the_agent_programs_own_tests_pass(): void
    {
        $python = $this->python();
        if ($python === null) {
            $this->markTestSkipped('No Python 3.8 or newer found, so the agent tests were not run.');
        }
        $out = [];
        exec(escapeshellarg($python).' -m unittest discover -s '.escapeshellarg(base_path('tests/agent')).' 2>&1', $out, $code);
        $this->assertSame(0, $code, "The agent tests failed:\n".implode("\n", array_slice($out, -30)));
    }
}
