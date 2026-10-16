<?php

namespace Tests\Feature\Settings;

use App\Models\SshKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Unit\PublicKeyTest;

class SshKeyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_users_can_add_list_and_delete_their_keys()
    {
        $this->actingAs($this->user)
            ->post(route('ssh-keys.store'), ['name' => 'Laptop', 'public_key' => PublicKeyTest::ED25519."\n"])
            ->assertRedirect(route('ssh-keys.index'));

        $key = SshKey::query()->sole();
        $this->assertSame($this->user->id, $key->user_id);
        $this->assertSame(PublicKeyTest::ED25519, $key->public_key);
        $this->assertSame('SHA256:nvWikPWjXWJFiw36iQyJQvpJbc5NtwfchOzuV9sx2CI', $key->fingerprint);

        $this->get(route('ssh-keys.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/SshKeys')
                ->where('keys.0.name', 'Laptop')
                ->where('keys.0.type', 'ssh-ed25519')
                ->where('keys.0.fingerprint', $key->fingerprint)
                ->missing('keys.0.public_key'));

        $this->delete(route('ssh-keys.destroy', $key->uuid))->assertRedirect(route('ssh-keys.index'));
        $this->assertSame(0, SshKey::query()->count());
    }

    public function test_invalid_and_duplicate_keys_are_refused()
    {
        $this->actingAs($this->user);

        $this->post(route('ssh-keys.store'), ['name' => 'Bad', 'public_key' => 'ssh-ed25519 AAAA'])->assertSessionHasErrors('public_key');
        $this->post(route('ssh-keys.store'), ['name' => 'Two', 'public_key' => PublicKeyTest::ED25519."\n".PublicKeyTest::ECDSA])->assertSessionHasErrors('public_key');

        $this->post(route('ssh-keys.store'), ['name' => 'First', 'public_key' => PublicKeyTest::ED25519]);
        $this->post(route('ssh-keys.store'), ['name' => 'Again', 'public_key' => PublicKeyTest::ED25519.' other comment'])->assertSessionHasErrors('public_key');

        $this->assertSame(['First'], SshKey::query()->pluck('name')->all());
    }

    public function test_the_same_key_can_belong_to_two_users()
    {
        $this->actingAs($this->user)->post(route('ssh-keys.store'), ['name' => 'Mine', 'public_key' => PublicKeyTest::ED25519]);
        $this->actingAs(User::factory()->create())->post(route('ssh-keys.store'), ['name' => 'Theirs', 'public_key' => PublicKeyTest::ED25519]);

        $this->assertSame(2, SshKey::query()->count());
    }

    public function test_users_never_see_or_delete_another_users_keys()
    {
        $other = User::factory()->create();
        $this->actingAs($other)->post(route('ssh-keys.store'), ['name' => 'Theirs', 'public_key' => PublicKeyTest::ED25519]);
        $key = SshKey::query()->sole();

        $this->actingAs($this->user)
            ->get(route('ssh-keys.index'))
            ->assertInertia(fn (Assert $page) => $page->has('keys', 0))
            ->assertDontSee('Theirs');

        $this->delete(route('ssh-keys.destroy', $key->uuid))->assertNotFound();
        $this->assertSame(1, SshKey::query()->count());
    }

    public function test_guests_cannot_manage_keys()
    {
        $this->get(route('ssh-keys.index'))->assertRedirect(route('login'));
        $this->post(route('ssh-keys.store'), ['name' => 'X', 'public_key' => PublicKeyTest::ED25519])->assertRedirect(route('login'));
    }
}
