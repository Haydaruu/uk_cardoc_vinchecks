<?php

use App\Models\User;

it('allows an authenticated verified user to update their profile', function () {
    $user = User::factory()->create([
        'name' => 'Old Name',
        'phone_number' => null,
    ]);

    $response = $this
        ->actingAs($user)
        ->patch(route('settings.profile.update'), [
            'name' => 'Ahmad Haydar',
            'phone_number' => '081234567890',
        ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'Ahmad Haydar',
        'phone_number' => '081234567890',
    ]);
});

it('requires a name when updating profile', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/settings/profile')
        ->patch(route('settings.profile.update'), [
            'name' => '',
            'phone_number' => '081234567890',
        ]);

    $response
        ->assertRedirect('/settings/profile')
        ->assertSessionHasErrors('name');
});

it('allows phone number to be empty', function () {
    $user = User::factory()->create([
        'phone_number' => '081234567890',
    ]);

    $response = $this
        ->actingAs($user)
        ->patch(route('settings.profile.update'), [
            'name' => $user->name,
            'phone_number' => null,
        ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'phone_number' => null,
    ]);
});

it('prevents guests from updating a profile', function () {
    $response = $this->patch(route('settings.profile.update'), [
        'name' => 'Hacker Name',
        'phone_number' => '123',
    ]);

    $response->assertRedirect();
});