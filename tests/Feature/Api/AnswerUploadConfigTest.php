<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('returns answer upload config for the frontend', function () {
    config()->set('learn.answers.allowed_mimes', ['image/png', 'image/jpeg', 'image/webp']);
    config()->set('learn.answers.max_bytes', 5_000_000);
    config()->set('learn.answers.transcode', true);
    config()->set('learn.answers.resize', true);
    config()->set('learn.answers.max_side', 1024);
    config()->set('learn.answers.convert_to_webp', true);

    Sanctum::actingAs(User::factory()->create());

    $res = $this->getJson('/api/learn/answers/upload-config')->assertOk();

    expect($res->json())->toBe([
        'allowed_mimes'     => ['image/png', 'image/jpeg', 'image/webp'],
        'accept'            => 'image/png,image/jpeg,image/webp',
        'max_bytes'         => 5_000_000,
        'max_side'          => 1024,
        'converted_to_webp' => true,
    ]);
});

it('reports no resize and no webp when processing disabled', function () {
    config()->set('learn.answers.transcode', false);
    config()->set('learn.answers.resize', true);
    config()->set('learn.answers.convert_to_webp', true);

    Sanctum::actingAs(User::factory()->create());

    $json = $this->getJson('/api/learn/answers/upload-config')->assertOk()->json();

    expect($json['max_side'])->toBeNull()
        ->and($json['converted_to_webp'])->toBeFalse();
});

it('requires authentication', function () {
    $this->getJson('/api/learn/answers/upload-config')->assertUnauthorized();
});
