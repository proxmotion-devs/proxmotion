<?php

namespace Tests\Feature\Admin;

use App\Models\Location;
use Inertia\Testing\AssertableInertia as Assert;

class HelpPageTest extends AdminTestCase
{
    public function test_it_shows_which_setup_steps_are_done()
    {
        Location::factory()->create();

        $this->get(route('admin.help'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Help')
                ->where('setup.locations', true)
                ->where('setup.clusters', false)
                ->where('setup.ip_pools', false)
                ->where('setup.os_templates', false)
                ->where('setup.packages', false)
                ->where('setup.billing_systems', false)
                ->where('docsUrl', null));
    }

    public function test_it_links_to_the_configured_guides()
    {
        config(['proxmotion.docs_url' => 'https://docs.example.com/proxmotion']);

        $this->get(route('admin.help'))
            ->assertInertia(fn (Assert $page) => $page->where('docsUrl', 'https://docs.example.com/proxmotion'));
    }

    public function test_guests_cannot_open_it()
    {
        auth()->logout();

        $this->get(route('admin.help'))->assertRedirect(route('login'));
    }
}
