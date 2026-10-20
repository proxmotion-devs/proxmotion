<?php

namespace Tests\Feature\Admin;

use App\Models\Cluster;
use App\Models\Location;
use Inertia\Testing\AssertableInertia as Assert;

class LocationManagementTest extends AdminTestCase
{
    public function test_locations_are_listed()
    {
        Location::factory()->create(['name' => 'Frankfurt']);

        $this->get(route('admin.locations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/locations/Index')
                ->has('locations', 1)
                ->where('locations.0.name', 'Frankfurt'));
    }

    public function test_a_location_can_be_created_and_updated()
    {
        $this->post(route('admin.locations.store'), [
            'name' => 'Frankfurt',
            'code' => 'fra1',
            'country_code' => 'de',
        ])->assertRedirect(route('admin.locations.index'));

        $location = Location::query()->sole();
        $this->assertSame('DE', $location->country_code);

        $this->put(route('admin.locations.update', $location), [
            'name' => 'Frankfurt am Main',
            'code' => 'fra1',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Frankfurt am Main', $location->refresh()->name);
        $this->assertNull($location->country_code);
    }

    public function test_a_hostname_template_is_stored_lowercase_and_cleared_when_empty()
    {
        // docs/adr/0031
        $this->post(route('admin.locations.store'), [
            'name' => 'Istanbul',
            'code' => 'ist1',
            'hostname_template' => ' IP-{ip}.{location}.Example.com ',
        ])->assertSessionHasNoErrors();

        $location = Location::query()->sole();
        $this->assertSame('ip-{ip}.{location}.example.com', $location->hostname_template);

        $this->get(route('admin.locations.index'))
            ->assertInertia(fn (Assert $page) => $page->where('locations.0.hostname_template', 'ip-{ip}.{location}.example.com'));

        $this->put(route('admin.locations.update', $location), ['name' => 'Istanbul', 'code' => 'ist1', 'hostname_template' => ''])
            ->assertSessionHasNoErrors();
        $this->assertNull($location->refresh()->hostname_template);
    }

    public function test_hostname_templates_must_name_each_server_validly()
    {
        $post = fn (string $template) => $this->post(route('admin.locations.store'), ['name' => 'X', 'code' => 'x1', 'hostname_template' => $template]);

        $post('server.example.com')->assertSessionHasErrors(['hostname_template' => 'The hostname template needs {ip} or {vmid}, so that each server gets its own name.']);
        $post('{ip}.{customer}.example.com')->assertSessionHasErrors(['hostname_template' => 'The hostname template may only use {ip}, {vmid} and {location}.']);
        $post('ip_{ip}.example.com')->assertSessionHasErrors(['hostname_template' => 'The hostname template must give a valid hostname, such as ip-{ip}.example.com.']);
        $post('-{vmid}.example.com')->assertSessionHasErrors('hostname_template');
        // A label of 64 characters once {ip} is an IPv6 address written out (39 characters).
        $post('ip-{ip}-'.str_repeat('a', 21).'.example.com')->assertSessionHasErrors('hostname_template');
        $this->assertSame(0, Location::query()->count());

        $post('vps{vmid}.example.com')->assertSessionHasNoErrors();
    }

    public function test_codes_must_be_unique_and_slug_like()
    {
        Location::factory()->create(['code' => 'fra1']);

        $this->post(route('admin.locations.store'), ['name' => 'X', 'code' => 'fra1'])
            ->assertSessionHasErrors('code');
        $this->post(route('admin.locations.store'), ['name' => 'X', 'code' => 'Not Valid'])
            ->assertSessionHasErrors('code');
    }

    public function test_locations_in_use_cannot_be_deleted()
    {
        $location = Location::factory()->create();
        Cluster::factory()->for($location)->create();

        $this->delete(route('admin.locations.destroy', $location));

        $this->assertModelExists($location);
    }

    public function test_unused_locations_can_be_deleted()
    {
        $location = Location::factory()->create();

        $this->delete(route('admin.locations.destroy', $location))
            ->assertRedirect(route('admin.locations.index'));

        $this->assertModelMissing($location);
    }
}
