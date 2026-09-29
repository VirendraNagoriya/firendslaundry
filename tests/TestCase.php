<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The suite asserts on behaviour, not on whether someone has run
        // `npm run build` on this machine.
        $this->withoutVite();
    }
}
