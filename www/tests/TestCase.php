<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Dev-on-server runs Vite via HMR with no built manifest (public/build is
        // removed so it doesn't shadow the dev server), so view-render tests that
        // hit @vite would throw ViteManifestNotFoundException. Tests don't care
        // about real assets — stub @vite out.
        $this->withoutVite();
    }
}
