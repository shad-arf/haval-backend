<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_register_sign_in_and_sign_out(): void
    {
        $registered = $this->postJson('/api/register', [
            'name' => 'Customer', 'username' => 'customer_one', 'phone' => '0770 000 0000',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated()->assertJsonPath('data.role', 'customer');

        $token = $registered->json('token');
        $this->withToken($token)->getJson('/api/account')->assertOk()->assertJsonPath('data.username', 'customer_one')->assertJsonPath('data.phone', '9647700000000');
        $this->withToken($token)->getJson('/api/admin/users')->assertForbidden();
        $this->withToken($token)->postJson('/api/logout')->assertOk();
        $this->withToken($token)->getJson('/api/account')->assertOk()->assertJsonPath('data', null);
        $this->postJson('/api/login', ['identifier' => 'customer_one', 'password' => 'secret123'])->assertOk();
        $this->postJson('/api/login', ['identifier' => '0770 000 0000', 'password' => 'secret123'])->assertOk();
        $this->postJson('/api/login', ['identifier' => '+964 770 000 0000', 'password' => 'secret123'])->assertOk();
    }

    public function test_inactive_account_cannot_sign_in(): void
    {
        User::factory()->create(['username' => 'inactive_user', 'phone' => '07701111111', 'password' => 'secret123', 'is_active' => false]);
        $this->postJson('/api/login', ['identifier' => 'inactive_user', 'password' => 'secret123'])->assertUnprocessable();
    }

    public function test_duplicate_phone_formats_cannot_create_two_accounts(): void
    {
        $first = ['name' => 'First', 'username' => 'first_user', 'phone' => '0770 000 0000', 'password' => 'secret123', 'password_confirmation' => 'secret123'];
        $this->postJson('/api/register', $first)->assertCreated();
        $this->postJson('/api/register', array_merge($first, [
            'username' => 'second_user', 'phone' => '+964 770 000 0000',
        ]))->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->postJson('/api/login', ['identifier' => 'first_user', 'password' => 'wrong'])->assertUnprocessable();
    }

    public function test_customer_can_update_their_own_name_and_phone(): void
    {
        $registered = $this->postJson('/api/register', [
            'name' => 'Original Name', 'username' => 'editable_user', 'phone' => '0770 111 1111',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated();
        $token = $registered->json('token');

        $this->withToken($token)->patchJson('/api/account', ['name' => 'New Name', 'phone' => '0770 222 2222'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.phone', '9647702222222');

        $this->withToken($token)->getJson('/api/account')->assertOk()->assertJsonPath('data.name', 'New Name');
    }

    public function test_customer_can_save_delivery_address_on_profile(): void
    {
        $registered = $this->postJson('/api/register', [
            'name' => 'Address Customer', 'username' => 'address_customer', 'phone' => '0770 123 4567',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated();

        $this->withToken($registered->json('token'))->patchJson('/api/account', [
            'city' => 'Erbil', 'address' => 'Industrial Area',
        ])->assertOk()->assertJsonPath('data.city', 'Erbil')->assertJsonPath('data.address', 'Industrial Area');

        $this->withToken($registered->json('token'))->getJson('/api/account')
            ->assertJsonPath('data.city', 'Erbil')->assertJsonPath('data.address', 'Industrial Area');
    }

    public function test_customer_cannot_take_another_accounts_phone_number(): void
    {
        User::factory()->create(['phone' => '9647703333333']);
        $registered = $this->postJson('/api/register', [
            'name' => 'Someone', 'username' => 'phone_thief', 'phone' => '0770 444 4444',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated();

        $this->withToken($registered->json('token'))->patchJson('/api/account', ['phone' => '0770 333 3333'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_updating_profile_without_a_token_is_unauthenticated(): void
    {
        $this->patchJson('/api/account', ['name' => 'Nobody'])->assertUnauthorized();
    }

    public function test_customer_can_change_their_own_username(): void
    {
        $registered = $this->postJson('/api/register', [
            'name' => 'Renamer', 'username' => 'old_handle', 'phone' => '0770 555 5555',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated();
        $token = $registered->json('token');

        $this->withToken($token)->patchJson('/api/account', ['username' => 'New_Handle'])
            ->assertOk()->assertJsonPath('data.username', 'new_handle');

        $this->postJson('/api/login', ['identifier' => 'new_handle', 'password' => 'secret123'])->assertOk();
    }

    public function test_customer_cannot_take_another_accounts_username(): void
    {
        User::factory()->create(['username' => 'taken_handle']);
        $registered = $this->postJson('/api/register', [
            'name' => 'Someone', 'username' => 'handle_thief', 'phone' => '0770 666 6666',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated();

        $this->withToken($registered->json('token'))->patchJson('/api/account', ['username' => 'taken_handle'])
            ->assertUnprocessable()->assertJsonValidationErrors('username');
    }

    public function test_customer_can_change_their_password_with_the_current_one(): void
    {
        $registered = $this->postJson('/api/register', [
            'name' => 'Changer', 'username' => 'password_changer', 'phone' => '0770 777 7777',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated();
        $token = $registered->json('token');

        $this->withToken($token)->patchJson('/api/account', [
            'current_password' => 'secret123', 'password' => 'newsecret456', 'password_confirmation' => 'newsecret456',
        ])->assertOk();

        $this->postJson('/api/login', ['identifier' => 'password_changer', 'password' => 'newsecret456'])->assertOk();
        $this->postJson('/api/login', ['identifier' => 'password_changer', 'password' => 'secret123'])->assertUnprocessable();
    }

    public function test_changing_password_requires_the_correct_current_password(): void
    {
        $registered = $this->postJson('/api/register', [
            'name' => 'Guesser', 'username' => 'wrong_guesser', 'phone' => '0770 888 8888',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated();

        $this->withToken($registered->json('token'))->patchJson('/api/account', [
            'current_password' => 'not-the-password', 'password' => 'newsecret456', 'password_confirmation' => 'newsecret456',
        ])->assertUnprocessable();

        $this->postJson('/api/login', ['identifier' => 'wrong_guesser', 'password' => 'secret123'])->assertOk();
    }
}
