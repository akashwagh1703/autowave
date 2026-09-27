<?php

namespace Tests\Feature\Foundation;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WelcomePageTest extends TestCase
{
    public function test_marketing_host_renders_the_welcome_inertia_page(): void
    {
        $this->get($this->marketingUrl())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->where('app.name', config('app.name'))
                ->where('auth.user', null)
                ->where('appUrl', rtrim(config('app.url'), '/'))
            );
    }

    public function test_www_host_redirects_to_the_marketing_host(): void
    {
        $this->get('http://www.'.config('autowave.root_domain').'/pricing?plan=pro')
            ->assertRedirect($this->marketingUrl('/pricing?plan=pro'))
            ->assertStatus(301);
    }

    public function test_responses_include_security_headers(): void
    {
        $this->get($this->marketingUrl())
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_health_endpoint_is_available(): void
    {
        $this->get($this->marketingUrl('/up'))->assertOk();
    }
}
