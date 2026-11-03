<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

it('saves the extra profile details', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Awa Traoré',
        'email' => $user->email,
        'gender' => 'female',
        'job_title' => 'Responsable commercial',
        'department' => 'Ventes',
        'phone' => '+223 70 00 00 00',
    ])->assertSessionHasNoErrors();

    expect($user->fresh())
        ->gender->toBe('female')
        ->job_title->toBe('Responsable commercial')
        ->department->toBe('Ventes');
});

it('rejects an unknown gender', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name, 'email' => $user->email, 'gender' => 'robot',
    ])->assertSessionHasErrors('gender');
});

it('stores a square avatar and serves it to teammates only', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $teammate = User::factory()->create();
    $stranger = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($teammate, ['role' => TeamRole::Member->value]);

    $this->actingAs($user)->post(route('profile.avatar.store'), [
        'avatar' => UploadedFile::fake()->image('me.jpg', 800, 600),
    ])->assertSessionHasNoErrors();

    $path = $user->fresh()->avatar_path;
    Storage::disk('local')->assertExists($path);
    [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($path));
    expect([$width, $height])->toBe([256, 256]);

    $this->actingAs($teammate)->get(route('avatars.show', $user))->assertOk();
    $this->actingAs($stranger)->get(route('avatars.show', $user))->assertNotFound();

    $this->actingAs($user)->delete(route('profile.avatar.destroy'));
    Storage::disk('local')->assertMissing($path);
    expect($user->fresh()->avatar)->toBeNull();
});

it('switches the interface language', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('profile.locale'), ['locale' => 'en'])->assertRedirect();
    expect($user->fresh()->locale)->toBe('en')
        ->and($user->fresh()->preferredLocale())->toBe('en');

    $this->actingAs($user->fresh())
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));

    $this->actingAs($user)->put(route('profile.locale'), ['locale' => 'de'])->assertSessionHasErrors('locale');
});

it('translates backend labels into the user language', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)->get(route('profile.edit'))->assertOk();

    expect(app()->getLocale())->toBe('en')
        ->and(App\Enums\TeamRole::Owner->label())->toBe('Owner');

    app()->setLocale('fr');
    expect(App\Enums\TeamRole::Owner->label())->toBe('Propriétaire');
});
