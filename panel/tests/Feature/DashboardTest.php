<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_redirects_to_the_admin_dashboard()
    {
        $this->get(route('home'))->assertRedirect('/admin');
    }

    public function test_there_is_no_client_dashboard()
    {
        $this->get('/dashboard')->assertNotFound();
    }
}
