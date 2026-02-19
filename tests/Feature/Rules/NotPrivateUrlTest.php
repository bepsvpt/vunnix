<?php

use App\Rules\NotPrivateUrl;

function validateNotPrivateUrl(mixed $value): array
{
    $errors = [];

    (new NotPrivateUrl)->validate('webhook_url', $value, function (string $message) use (&$errors): void {
        $errors[] = $message;
    });

    return $errors;
}

it('ignores non-string values', function (): void {
    expect(validateNotPrivateUrl(12345))->toBe([]);
});

it('ignores values without a host component', function (): void {
    expect(validateNotPrivateUrl('mailto:security@example.com'))->toBe([]);
});

it('returns an empty ip list for unresolvable hostnames', function (): void {
    $rule = new NotPrivateUrl;
    $method = new ReflectionMethod(NotPrivateUrl::class, 'resolveHostIps');
    $method->setAccessible(true);

    /** @var array<int, string> $ips */
    $ips = $method->invoke($rule, 'does-not-exist.invalid');

    expect($ips)->toBe([]);
});

it('treats malformed ip strings as private or reserved', function (): void {
    $rule = new NotPrivateUrl;
    $method = new ReflectionMethod(NotPrivateUrl::class, 'isPrivateOrReservedIp');
    $method->setAccessible(true);

    expect($method->invoke($rule, 'not-an-ip'))->toBeTrue();
});
