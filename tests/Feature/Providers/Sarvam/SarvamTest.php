<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Providers\SarvamProvider;
use Laravel\Ai\Transcription;
use Tests\Fixtures\Agents\ProviderOptionsAgent;
use Tests\Fixtures\Agents\StructuredAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.sarvam.key' => 'test-key']);
    Http::preventStrayRequests();
});

test('sarvam resolves with configurable model defaults', function (): void {
    $provider = Ai::textProvider('sarvam');

    expect($provider)->toBeInstanceOf(SarvamProvider::class)
        ->and($provider->defaultTextModel())->toBe('sarvam-105b')
        ->and($provider->cheapestTextModel())->toBe('sarvam-105b-conversations')
        ->and($provider->smartestTextModel())->toBe('sarvam-105b')
        ->and($provider->defaultTranscriptionModel())->toBe('saaras:v4');

    $provider = Ai::build(['driver' => 'sarvam', 'key' => 'test-key', 'models' => [
        'text' => ['default' => 'custom', 'cheapest' => 'small', 'smartest' => 'large'],
        'transcription' => ['default' => 'saaras:v3'],
    ]]);

    expect($provider->defaultTextModel())->toBe('custom')
        ->and($provider->cheapestTextModel())->toBe('small')
        ->and($provider->smartestTextModel())->toBe('large')
        ->and($provider->defaultTranscriptionModel())->toBe('saaras:v3');
});

test('sarvam chat uses subscription authentication and maps responses', function (): void {
    Http::fake(['*' => sarvamChatResponse('नमस्ते')]);

    $response = agent()->prompt('Hello', provider: Lab::Sarvam);

    expect($response->text)->toBe('नमस्ते')
        ->and($response->meta->provider)->toBe('sarvam');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.sarvam.ai/v1/chat/completions'
        && $request->hasHeader('api-subscription-key', 'test-key')
        && ! $request->hasHeader('Authorization')
        && $request['model'] === 'sarvam-105b');
});

test('sarvam chat honors a custom base url and provider options', function (): void {
    config(['ai.providers.sarvam.url' => 'https://proxy.test/']);
    Http::fake(['*' => sarvamChatResponse('Hello')]);

    (new class extends ProviderOptionsAgent
    {
        public function providerOptions(Lab|string $provider): array
        {
            return ['reasoning_effort' => 'high'];
        }
    })->prompt('Hello', provider: 'sarvam');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://proxy.test/v1/chat/completions'
        && $request['reasoning_effort'] === 'high');
});

test('sarvam supports structured output', function (): void {
    Http::fake(['*' => sarvamChatResponse('{"symbol":"Au"}')]);

    $response = (new StructuredAgent)->prompt('Gold?', provider: 'sarvam');

    expect($response['symbol'])->toBe('Au');
    Http::assertSent(fn (Request $request): bool => $request['response_format']['type'] === 'json_schema');
});

test('sarvam executes tools and sends their results', function (): void {
    Http::fake(['*' => Http::sequence([fakeDeepSeekToolCallResponse(), sarvamChatResponse('72019')])]);

    $response = (new ToolUsingAgent(fixed: true))->prompt('Give me a number', provider: 'sarvam');

    expect($response->text)->toBe('72019');
    Http::assertSentCount(2);
    expect(collect(Http::recorded()[1][0]['messages'])->pluck('role')->all())->toContain('tool');
});

test('sarvam streams without undocumented stream options', function (): void {
    $chunks = [
        ['model' => 'sarvam-105b', 'choices' => [['delta' => ['content' => 'Hello'], 'finish_reason' => null]]],
        ['choices' => [['delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 1]],
    ];
    $payload = implode('', array_map(fn ($chunk) => 'data: '.json_encode($chunk)."\n\n", $chunks))."data: [DONE]\n\n";
    Http::fake(['*' => Http::response($payload, 200, ['Content-Type' => 'text/event-stream'])]);

    $response = agent()->stream('Hello', provider: 'sarvam');
    iterator_to_array($response);

    expect($response->text)->toBe('Hello');
    Http::assertSent(fn (Request $request): bool => $request['stream'] === true && ! isset($request['stream_options']));
});

test('sarvam rejects chat attachments', function (): void {
    agent()->prompt('Describe', attachments: [new RemoteImage('https://example.com/image.png')], provider: 'sarvam');
})->throws(InvalidArgumentException::class, 'do not support attachments');

test('sarvam transcribes multipart audio with language and options', function (): void {
    Http::fake(['*' => Http::response(['transcript' => 'नमस्ते', 'language_code' => 'hi-IN'])]);

    $response = Transcription::fromBase64(base64_encode('audio-bytes'), 'audio/wav')
        ->language('hi')->withProviderOptions(['mode' => 'verbatim'])
        ->generate(provider: Lab::Sarvam);

    expect($response->text)->toBe('नमस्ते')
        ->and($response->meta->model)->toBe('saaras:v4')
        ->and($response->usage->audioSeconds)->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.sarvam.ai/speech-to-text'
        && $request->hasHeader('api-subscription-key', 'test-key')
        && str_contains($request->body(), 'audio.wav')
        && str_contains($request->body(), 'audio-bytes')
        && str_contains($request->body(), 'hi-IN')
        && str_contains($request->body(), 'verbatim'));
});

test('sarvam transcription honors custom url and explicit model', function (): void {
    config(['ai.providers.sarvam.url' => 'https://proxy.test/']);
    Http::fake(['*' => Http::response(['transcript' => 'Hello'])]);

    Transcription::fromBase64(base64_encode('audio'))->generate(provider: 'sarvam', model: 'saaras:v3');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://proxy.test/speech-to-text'
        && str_contains($request->body(), 'saaras:v3')
        && ! str_contains($request->body(), 'language_code'));
});

test('sarvam rejects synchronous diarization before sending audio', function (): void {
    try {
        Transcription::fromBase64(base64_encode('audio'))->diarize()->generate(provider: 'sarvam');
    } finally {
        Http::assertNothingSent();
    }
})->throws(InvalidArgumentException::class, 'batch API');

test('sarvam rejects malformed transcription responses', function (): void {
    Http::fake(['*' => Http::response(['request_id' => '123'])]);
    Transcription::fromBase64(base64_encode('audio'))->generate(provider: 'sarvam');
})->throws(AiException::class, 'did not contain a transcript');

function sarvamChatResponse(string $text): PromiseInterface
{
    return Http::response([
        'id' => 'sarvam-123',
        'model' => 'sarvam-105b',
        'choices' => [['message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ]);
}

test('sarvam transcription preserves BCP-47 language codes', function (): void {
    Http::fake(['*' => Http::response(['transcript' => 'Hello'])]);

    Transcription::fromBase64(base64_encode('audio'))->language('en-IN')->generate(provider: 'sarvam');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'en-IN'));
});

test('sarvam transcription maps rate limits to failover exceptions', function (): void {
    Http::fake(['*' => Http::response(['error' => ['code' => 'rate_limit_error', 'message' => 'Too many requests']], 429)]);

    Transcription::fromBase64(base64_encode('audio'))->generate(provider: 'sarvam');
})->throws(RateLimitedException::class);
