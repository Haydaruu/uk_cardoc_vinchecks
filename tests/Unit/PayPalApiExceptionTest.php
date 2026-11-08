<?php

use App\Exceptions\PayPalApiException;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;

test('it creates a structured exception with formatted summary', function () {
    $exception = new PayPalApiException(
        errorName: 'UNPROCESSABLE_ENTITY',
        errorMessage: 'The requested action could not be performed.',
        debugId: 'debug-12345',
        details: [
            ['issue' => 'ORDER_ALREADY_CAPTURED', 'description' => 'Order has already been captured.'],
        ],
        statusCode: 422,
    );

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception->getMessage())->toContain('PayPal API Error [UNPROCESSABLE_ENTITY]')
        ->and($exception->getMessage())->toContain('debug-12345')
        ->and($exception->getMessage())->toContain('ORDER_ALREADY_CAPTURED')
        ->and($exception->statusCode)->toBe(422)
        ->and($exception->hasIssue('ORDER_ALREADY_CAPTURED'))->toBeTrue()
        ->and($exception->hasIssue('order_already_captured'))->toBeTrue()
        ->and($exception->hasIssue('UNPROCESSABLE_ENTITY'))->toBeTrue()
        ->and($exception->hasIssue('NON_EXISTENT_ISSUE'))->toBeFalse()
        ->and($exception->getIssue())->toBe('ORDER_ALREADY_CAPTURED');
});

test('it parses response from Http client correctly', function () {
    $psr7 = new Psr7Response(
        status: 400,
        headers: [
            'Content-Type' => 'application/json',
            'PayPal-Debug-Id' => 'header-debug-abc',
        ],
        body: json_encode([
            'name' => 'INSTRUMENT_DECLINED',
            'message' => 'The provided payment method was declined.',
            'details' => [
                ['issue' => 'INSTRUMENT_DECLINED'],
            ],
            'links' => [
                ['rel' => 'information_link', 'href' => 'https://developer.paypal.com/docs/api/orders/v2/#error-INSTRUMENT_DECLINED'],
            ],
        ]),
    );

    $response = new Response($psr7);
    $exception = PayPalApiException::fromResponse($response);

    expect($exception->errorName)->toBe('INSTRUMENT_DECLINED')
        ->and($exception->errorMessage)->toBe('The provided payment method was declined.')
        ->and($exception->debugId)->toBe('header-debug-abc')
        ->and($exception->statusCode)->toBe(400)
        ->and($exception->hasIssue('INSTRUMENT_DECLINED'))->toBeTrue()
        ->and($exception->getIssue())->toBe('INSTRUMENT_DECLINED');
});

test('it extracts payer action URL from details or links', function () {
    $psr7 = new Psr7Response(
        status: 422,
        headers: ['Content-Type' => 'application/json'],
        body: json_encode([
            'name' => 'UNPROCESSABLE_ENTITY',
            'message' => 'Payer action required.',
            'details' => [
                [
                    'issue' => 'PAYER_ACTION_REQUIRED',
                    'links' => [
                        [
                            'rel' => 'payer-action',
                            'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=AUTH-CHALLENGE-TOKEN',
                        ],
                    ],
                ],
            ],
        ]),
    );

    $exception = PayPalApiException::fromResponse(new Response($psr7));

    expect($exception->hasIssue('PAYER_ACTION_REQUIRED'))->toBeTrue()
        ->and($exception->getPayerActionUrl())->toBe('https://www.sandbox.paypal.com/checkoutnow?token=AUTH-CHALLENGE-TOKEN');
});

test('it extracts payer action URL from top-level links if details links are absent', function () {
    $psr7 = new Psr7Response(
        status: 422,
        headers: ['Content-Type' => 'application/json'],
        body: json_encode([
            'name' => 'PAYER_ACTION_REQUIRED',
            'message' => 'Customer must verify their payment.',
            'links' => [
                [
                    'rel' => 'payer-action',
                    'href' => 'https://www.sandbox.paypal.com/verify?token=DIRECT-TOKEN',
                ],
            ],
        ]),
    );

    $exception = PayPalApiException::fromResponse(new Response($psr7));

    expect($exception->getPayerActionUrl())->toBe('https://www.sandbox.paypal.com/verify?token=DIRECT-TOKEN');
});
