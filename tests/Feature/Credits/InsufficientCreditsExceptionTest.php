<?php

use Illuminate\Http\Request;
use Saad\AiKit\Credits\InsufficientCreditsException;

it('renders the canonical 402 body with code, legacy reason alias, and balance', function () {
    $response = (new InsufficientCreditsException(balance: 12))->toResponse(Request::create('/'));

    expect($response->getStatusCode())->toBe(402)
        ->and($response->getData(true))->toBe([
            'code' => 'insufficient_credits',
            'reason' => 'insufficient_credits',
            'message' => __('ai-kit::credits.insufficient'),
            'balance' => 12,
        ]);
});

it('omits balance when the gate resolved none and carries custom reasons', function () {
    $exception = new InsufficientCreditsException('subscription_required', message: 'اشترك أولاً.');
    $body = $exception->toResponse(Request::create('/'))->getData(true);

    expect($body)->toBe([
        'code' => 'subscription_required',
        'reason' => 'subscription_required',
        'message' => 'اشترك أولاً.',
    ]);
});
