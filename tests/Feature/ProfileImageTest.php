<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_accepts_optional_profile_image(): void
    {
        config(['filesystems.profile_image_disk' => 'public']);
        Storage::fake('public');

        $image = UploadedFile::fake()->image('avatar.jpg', 100, 100);

        $response = $this->post('/api/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'profile_image' => $image,
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.name', 'Jane Doe')
            ->assertJsonPath('user.email', 'jane@example.com')
            ->assertJsonStructure(['user' => ['id', 'name', 'email', 'profile_image', 'profile_image_url']]);

        $user = User::where('email', 'jane@example.com')->first();
        $this->assertNotNull($user->profile_image);
        $this->assertStringStartsWith('profile_images/', $user->profile_image);
        Storage::disk('public')->assertExists($user->profile_image);
    }

    public function test_registration_without_profile_image_still_works(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.profile_image', null)
            ->assertJsonPath('user.profile_image_url', null);

        $user = User::where('email', 'john@example.com')->first();
        $this->assertNull($user->profile_image);
    }

    public function test_add_user_to_business_with_profile_image_when_creating_new_user(): void
    {
        config(['filesystems.profile_image_disk' => 'public']);
        Storage::fake('public');

        $owner = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $owner->id]);
        $business->users()->attach($owner->id, ['is_active' => true]);

        $image = UploadedFile::fake()->image('newuser.jpg', 100, 100);

        $response = $this->actingAs($owner, 'sanctum')
            ->post('/api/business-users', [
                'email' => 'newstaff@example.com',
                'name' => 'New Staff',
                'is_active' => true,
                'profile_image' => $image,
            ], [
                'X-Business-Id' => $business->id,
                'Accept' => 'application/json',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.is_new_user', true)
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email', 'profile_image', 'profile_image_url']]]);

        $user = User::where('email', 'newstaff@example.com')->first();
        $this->assertNotNull($user->profile_image);
        Storage::disk('public')->assertExists($user->profile_image);
    }

    public function test_authenticated_user_can_update_profile_with_name_and_image(): void
    {
        config(['filesystems.profile_image_disk' => 'public']);
        Storage::fake('public');

        $user = User::factory()->create([
            'name' => 'Old Name',
            'profile_image' => null,
        ]);

        $image = UploadedFile::fake()->image('new-avatar.jpg', 200, 200);

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/user', [
                '_method' => 'PUT',
                'name' => 'New Name',
                'profile_image' => $image,
            ], [
                'Accept' => 'application/json',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('user.name', 'New Name')
            ->assertJsonStructure(['user' => ['id', 'name', 'email', 'profile_image', 'profile_image_url']]);

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertNotNull($user->profile_image);
        Storage::disk('public')->assertExists($user->profile_image);
    }

    public function test_authenticated_user_can_update_profile_name_only(): void
    {
        $user = User::factory()->create(['name' => 'Before']);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/user', [
                'name' => 'After',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('user.name', 'After');

        $user->refresh();
        $this->assertSame('After', $user->name);
    }

    public function test_update_profile_rejects_invalid_image(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/user', [
                '_method' => 'PUT',
                'profile_image' => UploadedFile::fake()->create('document.pdf', 100),
            ], [
                'Accept' => 'application/json',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['profile_image']);
    }
}
