<?php

use App\Mail\ContactMessageReceived;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the marketing pages', function (string $url) {
    $this->get($url)->assertOk();
})->with([
    'landing' => ['/'],
    'features' => ['/features'],
    'pricing' => ['/pricing'],
    'contact' => ['/contact'],
    'about' => ['/about'],
    'solutions' => ['/solutions'],
]);

it('renders every industry solution page', function () {
    foreach (config('marketing.solutions') as $industry) {
        $this->get('/solutions/'.$industry)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('marketing/Solution')
                ->where('industry', $industry));
    }
});

it('returns 404 for an unknown industry', function () {
    $this->get('/solutions/unknown')->assertNotFound();
});

it('sends a contact message to the configured address', function () {
    Mail::fake();
    config(['marketing.contact_email' => 'sales@example.com']);

    $this->post('/contact', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'company' => 'Acme',
        'team_size' => '6-15',
        'message' => 'We need a call center for 10 agents.',
    ])->assertSessionHasNoErrors()->assertSessionHas('status');

    Mail::assertSent(ContactMessageReceived::class, fn (ContactMessageReceived $mail) => $mail->hasTo('sales@example.com')
        && $mail->data['email'] === 'jane@example.com');
});

it('validates contact messages', function () {
    $this->post('/contact', [
        'name' => '',
        'email' => 'not-an-email',
        'message' => '',
    ])->assertSessionHasErrors(['name', 'email', 'message']);
});

it('renders the legal pages with the configured identity', function (string $url, string $component) {
    config(['legal.company.rccm' => 'MA.BKO.2024.B.1234', 'legal.apdp_reference' => null]);

    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component($component)
        ->where('legal.company.rccm', 'MA.BKO.2024.B.1234')
        ->where('legal.apdp_reference', null)
        ->has('legal.subprocessors'));
})->with([
    'privacy' => ['/confidentialite', 'marketing/legal/Privacy'],
    'notice' => ['/mentions-legales', 'marketing/legal/Notice'],
    'trust' => ['/confiance', 'marketing/legal/Trust'],
]);

it('lists retention per plan on the trust center', function () {
    $this->get('/confiance')->assertInertia(fn (Assert $page) => $page
        ->has('plans', count(config('billing.plans')))
        ->where('plans.0.recordingDays', null));
});
