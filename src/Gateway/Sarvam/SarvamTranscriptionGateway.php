<?php

namespace Laravel\Ai\Gateway\Sarvam;

use InvalidArgumentException;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\Concerns\CreatesClient;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\ResolvesAudioFilenames;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionUsage;
use Laravel\Ai\Responses\TranscriptionResponse;

class SarvamTranscriptionGateway implements TranscriptionGateway
{
    use CreatesClient;
    use HandlesFailoverErrors;
    use ResolvesAudioFilenames;

    /**
     * {@inheritdoc}
     */
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        int $timeout = 30,
        array $providerOptions = [],
    ): TranscriptionResponse {
        if ($diarize) {
            throw new InvalidArgumentException('Sarvam only supports diarization through its batch API.');
        }

        $body = array_merge(['mode' => 'transcribe'], $providerOptions, array_filter([
            'model' => $model,
            'language_code' => in_array($language, ['hi', 'bn', 'kn', 'ml', 'mr', 'od', 'pa', 'ta', 'te', 'en', 'gu', 'as', 'ur', 'ne', 'kok', 'ks', 'sd', 'sa', 'sat', 'mni', 'brx', 'mai', 'doi'], true) ? $language.'-IN' : $language,
        ], fn ($value): bool => $value !== null));

        $body = array_map(fn ($value) => is_bool($value) ? ($value ? 'true' : 'false') : $value, $body);

        $response = $this->withErrorHandling($provider->name(), fn () => $this->createClient(
            rtrim($provider->additionalConfiguration()['url'] ?? 'https://api.sarvam.ai', '/'),
            ['api-subscription-key' => $provider->providerCredentials()['key']],
            $provider->additionalConfiguration()['headers'] ?? [],
            $timeout,
        )->attach('file', $audio->content(), $this->audioFilename($audio), array_filter(['Content-Type' => $audio->mimeType()]))
            ->post('speech-to-text', $body));

        $data = $response->json();

        if (! is_array($data) || ! is_string($data['transcript'] ?? null)) {
            throw new AiException('Sarvam Error: [invalid_response] The response did not contain a transcript.');
        }

        return new TranscriptionResponse(
            $data['transcript'],
            collect(),
            new TranscriptionUsage,
            new Meta($provider->name(), $model),
        );
    }
}
