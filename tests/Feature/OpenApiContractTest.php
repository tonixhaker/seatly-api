<?php

declare(strict_types=1);

const PUBLIC_ENDPOINTS = [
    ['post', '/api/v1/auth/register'],
    ['post', '/api/v1/auth/login'],
    ['get', '/api/v1/events'],
    ['get', '/api/v1/events/{id}'],
    ['get', '/api/v1/events/{id}/seats'],
];

const PROTECTED_ENDPOINTS = [
    ['post', '/api/v1/auth/logout'],
    ['get', '/api/v1/me'],
    ['post', '/api/v1/orders'],
    ['get', '/api/v1/orders/{id}'],
    ['get', '/api/v1/my/tickets'],
    ['post', '/api/v1/organizer/events'],
    ['put', '/api/v1/organizer/events/{id}'],
    ['post', '/api/v1/organizer/events/{id}/publish'],
    ['get', '/api/v1/organizer/events/{id}/stats'],
    ['post', '/api/v1/organizer/check-in'],
];

function openApiFile(): string
{
    return (string) file_get_contents(base_path('docs/openapi.json'));
}

/**
 * @return array<array-key, mixed>
 */
function openApiSpec(): array
{
    $decoded = json_decode(openApiFile(), true, 512, JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}

function openApiValue(string $path): mixed
{
    return data_get(openApiSpec(), $path);
}

function openApiOperation(string $method, string $path): mixed
{
    return openApiValue('paths.'.$path.'.'.$method);
}

function openApiResponseSchema(string $method, string $path, string $status): mixed
{
    return data_get(openApiOperation($method, $path), 'responses.'.$status.'.content.application/json.schema');
}

it('describes every single-resource response as a bare reference with no data wrapper', function (): void {
    expect(openApiResponseSchema('get', '/api/v1/me', '200'))->toBe(['$ref' => '#/components/schemas/UserResource'])
        ->and(openApiResponseSchema('get', '/api/v1/events/{id}', '200'))->toBe(['$ref' => '#/components/schemas/EventDetailResource'])
        ->and(openApiResponseSchema('post', '/api/v1/orders', '201'))->toBe(['$ref' => '#/components/schemas/OrderResource']);
});

it('describes the event list as exactly data, links and meta', function (): void {
    $schema = openApiResponseSchema('get', '/api/v1/events', '200');

    expect(array_keys((array) data_get($schema, 'properties')))->toBe(['data', 'links', 'meta'])
        ->and(data_get($schema, 'properties.data.items'))->toBe(['$ref' => '#/components/schemas/EventResource'])
        ->and(data_get($schema, 'properties.data.type'))->toBe('array');
});

it('documents both order 422 bodies as anyOf branches carrying VALIDATION_FAILED and SEATS_NOT_HELD', function (): void {
    $branches = (array) data_get(openApiResponseSchema('post', '/api/v1/orders', '422'), 'anyOf');

    $codes = array_map(
        fn (mixed $branch): mixed => data_get($branch, 'properties.error.properties.code.enum.0'),
        $branches,
    );

    expect($branches)->toHaveCount(2)
        ->and($codes)->toEqualCanonicalizing(['VALIDATION_FAILED', 'SEATS_NOT_HELD']);
});

it('documents a 402 PAYMENT_DECLINED body on the order endpoint', function (): void {
    $schema = openApiResponseSchema('post', '/api/v1/orders', '402');

    expect(data_get($schema, 'properties.error.properties.code.enum'))->toBe(['PAYMENT_DECLINED'])
        ->and(data_get($schema, 'properties.error.required'))->toBe(['code', 'message']);
});

it('types starts_at as a date-time string in every schema that carries it', function (): void {
    foreach (['CreateEventRequest', 'UpdateEventRequest', 'EventResource', 'EventDetailResource'] as $schema) {
        expect(openApiValue('components.schemas.'.$schema.'.properties.starts_at.type'))->toBe('string')
            ->and(openApiValue('components.schemas.'.$schema.'.properties.starts_at.format'))->toBe('date-time');
    }
});

it('documents all fifteen endpoints with their method and path, and nothing else', function (): void {
    $endpoints = array_merge(PUBLIC_ENDPOINTS, PROTECTED_ENDPOINTS);

    $documented = [];

    foreach ((array) openApiValue('paths') as $path => $operations) {
        foreach (array_keys((array) $operations) as $method) {
            $documented[] = [$method, $path];
        }
    }

    expect($endpoints)->toHaveCount(15)
        ->and($documented)->toEqualCanonicalizing($endpoints);
});

it('declares a global bearer security requirement backed by an http bearer scheme', function (): void {
    expect(openApiValue('security'))->toBe([['bearerAuth' => []]])
        ->and(openApiValue('components.securitySchemes.bearerAuth.type'))->toBe('http')
        ->and(openApiValue('components.securitySchemes.bearerAuth.scheme'))->toBe('bearer');
});

it('opts the five public endpoints out of the global security requirement', function (): void {
    foreach (PUBLIC_ENDPOINTS as [$method, $path]) {
        expect(data_get(openApiOperation($method, $path), 'security'))->toBe([], $method.' '.$path);
    }
});

it('leaves the ten protected endpoints inheriting the global security requirement', function (): void {
    foreach (PROTECTED_ENDPOINTS as [$method, $path]) {
        expect(data_get(openApiOperation($method, $path), 'security'))->toBeNull($method.' '.$path);
    }
});

it('documents a 401 UNAUTHENTICATED body on every protected endpoint', function (): void {
    foreach (PROTECTED_ENDPOINTS as [$method, $path]) {
        $schema = openApiResponseSchema($method, $path, '401');

        expect(data_get($schema, 'properties.error.properties.code.enum'))->toBe(['UNAUTHENTICATED'], $method.' '.$path);
    }
});

it('leaks no Laravel-shaped validation body into the document', function (): void {
    expect(openApiFile())->not->toContain('"errors"');
    expect(openApiFile())->not->toContain('Errors overview');
});

it('gives every operation a non-empty summary', function (): void {
    foreach (array_merge(PUBLIC_ENDPOINTS, PROTECTED_ENDPOINTS) as [$method, $path]) {
        expect(data_get(openApiOperation($method, $path), 'summary'))->toBeString()->not->toBe('', $method.' '.$path);
    }
});
