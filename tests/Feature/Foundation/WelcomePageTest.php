<?php

namespace Tests\Feature\Foundation;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WelcomePageTest extends TestCase
{
    public function test_home_page_renders_the_welcome_inertia_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->where('app.name', config('app.name'))
                ->where('auth.user', null)
            );
    }

    public function test_responses_include_security_headers(): void
    {
        $this->get('/')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_health_endpoint_is_available(): void
    {
        $this->get('/up')->assertOk();
    }
}
