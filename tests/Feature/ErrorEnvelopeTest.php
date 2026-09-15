<?php

declare(strict_types=1);

use App\Domain\Order\Exceptions\PaymentDeclinedException;
use App\Domain\Shared\Enums\ErrorCode;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\User\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

function envelopeRoute(string $name, Closure $handler): string
{
    $path = 'api/__envelope/'.$name;
    Route::get($path, $handler);

    return '/'.$path;
}

it('maps validation failures to VALIDATION_FAILED with field errors', function (): void {
    $url = envelopeRoute('validation', fn () => throw ValidationException::withMessages([
        'email' => ['The email field is required.'],
    ]));

    $this->getJson($url)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonPath('error.details.email.0', 'The email field is required.');
});

it('maps a missing token to UNAUTHENTICATED and omits empty details', function (): void {
    $url = envelopeRoute('unauthenticated', fn () => throw new AuthenticationException);

    $this->getJson($url)
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED')
        ->assertJsonMissingPath('error.details');
});

it('maps authorization failures to FORBIDDEN without the policy message', function (): void {
    $url = envelopeRoute('forbidden', fn () => throw new AuthorizationException('User 7 does not own event 3.'));

    $response = $this->getJson($url)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');

    expect($response->getContent())->not->toContain('does not own');
});

it('maps a missing model to NOT_FOUND without leaking the model or its key', function (): void {
    $url = envelopeRoute('not-found', function (): never {
        throw (new ModelNotFoundException)->setModel(User::class, [42]);
    });

    $response = $this->getJson($url)
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    expect($response->getContent())
        ->not->toContain('User')
        ->not->toContain('42');
});

it('maps an unhandled exception to INTERNAL_ERROR, hides the message and logs it', function (): void {
    Log::spy();

    $url = envelopeRoute('internal', fn () => throw new RuntimeException('db password is hunter2'));

    $response = $this->getJson($url)
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'INTERNAL_ERROR');

    expect($response->getContent())->not->toContain('hunter2');

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'hunter2'))
        ->once();
});

it('lets a domain exception carry its own code, status and details', function (): void {
    $url = envelopeRoute('domain', fn () => throw new class('This ticket was already checked in.', ['checked_in_at' => '2026-09-14T10:00:00Z']) extends DomainException
    {
        public function errorCode(): ErrorCode
        {
            return ErrorCode::ALREADY_CHECKED_IN;
        }
    });

    $this->getJson($url)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ALREADY_CHECKED_IN')
        ->assertJsonPath('error.message', 'This ticket was already checked in.')
        ->assertJsonPath('error.details.checked_in_at', '2026-09-14T10:00:00Z');
});

it('maps a Form Request rejection to VALIDATION_FAILED with per-field errors', function (): void {
    $path = 'api/__envelope/form-request';
    Route::post($path, fn (EnvelopeTestRequest $request) => response()->json($request->validated()));

    $this->postJson('/'.$path, ['quantity' => 0])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonPath('error.details.email.0', 'The email field is required.')
        ->assertJsonPath('error.details.quantity.0', 'The quantity field must be at least 1.')
        ->assertJsonMissingPath('message')
        ->assertJsonMissingPath('errors');
});

it('maps a throttled request to RATE_LIMITED', function (): void {
    $url = envelopeRoute('throttled', fn () => throw new ThrottleRequestsException);

    $this->getJson($url)
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'RATE_LIMITED');
});

it('maps maintenance mode to SERVICE_UNAVAILABLE', function (): void {
    $url = envelopeRoute('maintenance', fn () => throw new HttpException(503));

    $this->getJson($url)
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
});

it('never returns Laravel\'s default error shape', function (): void {
    $url = envelopeRoute('shape', fn () => throw new RuntimeException('boom'));

    $response = $this->getJson($url)->assertStatus(500);

    expect(array_keys((array) $response->json()))->toBe(['error'])
        ->and(array_keys((array) $response->json('error')))->toBe(['code', 'message']);
});

final class EnvelopeTestRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}

it('maps abort(403) to FORBIDDEN rather than a server error', function (): void {
    $url = envelopeRoute('aborted-403', fn () => abort(403));

    $this->getJson($url)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN')
        ->assertJsonMissingPath('error.details');
});

it('401 UNAUTHENTICATED on a protected route without a JSON Accept header', function (): void {
    $this->get('/api/v1/me', ['Accept' => 'text/html'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED')
        ->assertJsonMissingPath('error.details');

    $this->get('/api/v1/my/tickets')->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

it('renders PaymentDeclinedException as 402 PAYMENT_DECLINED', function (): void {
    $url = envelopeRoute('payment-declined', fn () => throw new PaymentDeclinedException('The card was declined.'));

    $this->getJson($url)
        ->assertStatus(402)
        ->assertJsonPath('error.code', 'PAYMENT_DECLINED')
        ->assertJsonPath('error.message', 'The card was declined.')
        ->assertJsonMissingPath('error.details');
});
