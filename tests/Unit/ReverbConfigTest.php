<?php

namespace Tests\Unit;

use Tests\TestCase;

class ReverbConfigTest extends TestCase
{
    public function test_allowed_origins_are_hostnames_instead_of_urls(): void
    {
        $origins = config('reverb.apps.apps.0.allowed_origins');

        $this->assertNotEmpty($origins);
        foreach ($origins as $origin) {
            $this->assertStringNotContainsString('://', $origin);
        }
    }
}
