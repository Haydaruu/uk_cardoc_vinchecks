<?php

use App\Mail\SupportMessage;
use Illuminate\Support\Facades\Mail;

test('valid support message is sent to the configured inbox', function () {
    Mail::fake();

    config([
        'mail.support_address' => 'support@example.com',
    ]);

    $payload = [
        'name' => 'Test Customer',
        'email' => 'customer@example.com',
        'subject' => 'Billing question',
        'message' => 'I need help with a recent payment.',
    ];

    $response = $this->from(route('page.support'))
        ->post(route('page.support.submit'), $payload);

    $response->assertRedirect(route('page.support'))
        ->assertSessionHas('success', true);

    Mail::assertSent(
        SupportMessage::class,
        function (SupportMessage $mail) {
            return $mail->hasTo('support@example.com')
                && $mail->senderName === 'Test Customer'
                && $mail->senderEmail === 'customer@example.com'
                && $mail->supportSubject === 'Billing question'
                && $mail->messageBody === 'I need help with a recent payment.';
        }
    );
});

test('invalid support message is rejected', function () {
    Mail::fake();

    $this->from(route('page.support'))
        ->post(route('page.support.submit'), [
            'name' => '',
            'email' => 'not-an-email',
            'subject' => '',
            'message' => 'Short',
        ])
        ->assertSessionHasErrors([
            'name',
            'email',
            'subject',
            'message',
        ]);

    Mail::assertNothingSent();
});

test('support message is rejected when support inbox is not configured', function () {
    Mail::fake();

    config(['mail.support_address' => null]);

    $this->from(route('page.support'))
        ->post(route('page.support.submit'), [
            'name' => 'Test Customer',
            'email' => 'customer@example.com',
            'subject' => 'Billing question',
            'message' => 'I need help with a recent payment.',
        ])
        ->assertSessionHasErrors(['support']);

    Mail::assertNothingSent();
});