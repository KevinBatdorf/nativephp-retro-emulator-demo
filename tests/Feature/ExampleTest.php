<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /** The app is native-only, so the web root only redirects to start_url. */
    public function test_the_web_root_redirects_to_the_native_start_url(): void
    {
        $this->get('/')->assertRedirect('/home');
    }
}
