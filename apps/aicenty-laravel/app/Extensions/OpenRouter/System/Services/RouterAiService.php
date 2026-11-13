<?php

namespace App\Extensions\OpenRouter\System\Services;

use App\Domains\Entity\Enums\EntityEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Throwable;

class RouterAiService
{
    public const BASE_URL = 'https://openrouter.ai/api/v1/chat/completions';

    public function response($prompt, $model): JsonResponse|string
    {
        $modelId = $this->normalizeModelId($model);
        $response = $this->send($prompt, $modelId);

        if (
            ! $response->successful()
            && str_ends_with($modelId, ':free')
            && $modelId !== 'openrouter/free'
            && in_array($response->status(), [404, 429], true)
        ) {
            $fallback = $this->send($prompt, 'openrouter/free');

            if ($fallback->successful()) {
                return $fallback->body();
            }
        }

        if ($response->successful()) {
            return $response->body();
        }

        $responseBody = trim((string) $response->body());
        $errorMessage = $this->extractErrorMessage($responseBody);

        return response()->json([
            'message' => $errorMessage,
            'error'   => 'Request failed',
            'details' => $responseBody,
        ], $response->status());
    }

    private function send(string $prompt, string $modelId)
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . setting('open_router_api'),
            'HTTP-Referer'  => config('app.url'),
            'X-Title'       => config('app.url'),
        ])
            ->post(self::BASE_URL, [
                'model'    => $modelId,
                'stream'   => true,
                'messages' => [
                    [
                        'role'    => 'user',
                        'content' => $prompt,
                    ],
                ],
            ]);
    }

    private function normalizeModelId($model): string
    {
        $model = trim((string) $model);

        if ($model === '') {
            return $model;
        }

        try {
            return EntityEnum::fromSlug($model)->value;
        } catch (Throwable) {
            return str_replace('__', '.', $model);
        }
    }

    private function extractErrorMessage(string $body): string
    {
        if ($body === '') {
            return __('Request failed');
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return $body;
        }

        $candidates = [
            data_get($decoded, 'message'),
            data_get($decoded, 'details'),
            data_get($decoded, 'error.message'),
            data_get($decoded, 'error'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $candidate = trim($candidate);

            if ($candidate === 'Request failed') {
                continue;
            }

            $nested = json_decode($candidate, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($nested)) {
                $nestedCandidate = data_get($nested, 'message')
                    ?? data_get($nested, 'details')
                    ?? data_get($nested, 'error.message')
                    ?? data_get($nested, 'error');

                if (is_string($nestedCandidate) && trim($nestedCandidate) !== '' && trim($nestedCandidate) !== 'Request failed') {
                    $candidate = trim($nestedCandidate);
                }
            }

            return $candidate;
        }

        return $body;
    }
}
