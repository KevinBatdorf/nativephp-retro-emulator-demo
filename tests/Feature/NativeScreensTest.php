<?php

namespace Tests\Feature;

use Tests\TestCase;

class NativeScreensTest extends TestCase
{
    /**
     * Every native route must render. The native precompiler is inactive off
     * device, so this says nothing about layout — but it does catch a view
     * calling a method that no longer exists, which otherwise only shows up as
     * an error screen on the device.
     */
    public function test_every_native_route_renders(): void
    {
        $this->withoutExceptionHandling();

        foreach (['/home', '/dpads', '/systems', '/probe', '/errors'] as $uri) {
            $this->assertSame(200, $this->get($uri)->status(), "{$uri} did not render");
        }
    }
}
