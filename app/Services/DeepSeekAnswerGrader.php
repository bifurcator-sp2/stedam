<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class DeepSeekAnswerGrader
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey  = (string) config('services.deepseek.api_key');
        $this->baseUrl = rtrim((string) config('services.deepseek.base_url'), '/');
        $this->model   = (string) config('services.deepseek.model');
        $this->timeout = (int) config('services.deepseek.timeout', 120);

        if ($this->apiKey === '') {
            throw new RuntimeException('DeepSeek API key is not configured.');
        }
    }

    /**
     * @param string $questionHtml   Вопрос в HTML с KaTeX.
     * @param string $referenceHtml  Эталонный ответ в HTML с KaTeX.
     * @param string $studentPngPath Путь к PNG с рукописным ответом.
     *
     * @return array{score: float, confidence: float}
     */
    public function grade(string $questionHtml, string $referenceHtml, string $studentPngPath): array
    {
        $imageBase64 = $this->encodeImage($studentPngPath);

        $messages = $this->buildMessages($questionHtml, $referenceHtml, $imageBase64);

        $response = Http::withToken($this->apiKey)
            ->timeout($this->timeout)
            ->acceptJson()
            ->post("{$this->baseUrl}/chat/completions", [
                'model'           => $this->model,
                'messages'        => $messages,
                'response_format' => ['type' => 'json_object'], // JSON-режим DeepSeek[reference:2]
                'temperature'     => 0.0,
                'max_tokens'      => 256, // достаточно для двух чисел
            ]);

        if ($response->failed()) {
            Log::error('DeepSeek grading request failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new RuntimeException('DeepSeek API request failed: '.$response->status());
        }

        $content = $response->json('choices.0.message.content');

        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('DeepSeek returned an empty response.');
        }

        $data = json_decode($content, true);

        if (!is_array($data) || !isset($data['score'], $data['confidence'])) {
            throw new RuntimeException('DeepSeek returned invalid JSON: '.$content);
        }

        // Приводим к float и ограничиваем диапазоном [0, 1].
        $score      = $this->clamp01((float) $data['score']);
        $confidence = $this->clamp01((float) $data['confidence']);

        return [
            'score'      => $score,
            'confidence' => $confidence,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildMessages(string $questionHtml, string $referenceHtml, string $imageBase64): array
    {
        $systemPrompt = <<<'PROMPT'
Ты — строгий, но справедливый эксперт-экзаменатор. Твоя задача — оценить рукописный ответ ученика, сравнив его с эталонным ответом.

Входные данные:
1. Текст вопроса (HTML с KaTeX).
2. Эталонный ответ (HTML с KaTeX).
3. Изображение (PNG) с рукописным ответом ученика.

Правила оценки:
- Оценка (`score`) — число от 0.0 до 1.0:
  - 0.0 — в ответе ученика нет ничего, что соответствует эталону;
  - 1.0 — ответ ученика полностью и идеально соответствует эталону (допускаются незначительные орфографические/почерковые погрешности, не меняющие смысл).
- Уверенность (`confidence`) — число от 0.0 до 1.0:
  - насколько ты уверен в выставленной оценке с учётом качества распознавания почерка и полноты ответа.
- Учитывай предметную область (математика, русский язык и т.д.).
- Если почерк неразборчив или часть ответа обрезана, снижай `confidence`, но не занижай `score` только из-за этого.
- Не добавляй пояснений, рассуждений или текста вне JSON.

Формат ответа — строго JSON:
{"score": <float 0.0-1.0>, "confidence": <float 0.0-1.0>}
PROMPT;

        $userContent = [
            [
                'type' => 'text',
                'text' => "ВОПРОС:\n{$questionHtml}\n\nЭТАЛОННЫЙ ОТВЕТ:\n{$referenceHtml}",
            ],
            [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:image/png;base64,'.$imageBase64,
                ],
            ],
        ];

        return [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userContent],
        ];
    }

    private function encodeImage(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException("Student answer image not found or unreadable: {$path}");
        }

        $mime = mime_content_type($path);
        if ($mime !== 'image/png') {
            throw new InvalidArgumentException("Expected PNG image, got: {$mime}");
        }

        $data = file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException("Failed to read image: {$path}");
        }

        return base64_encode($data);
    }

    private function clamp01(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
