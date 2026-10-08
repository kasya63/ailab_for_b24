<?php
namespace Local\AiLab\Ai;

/**
 * Запрос к модели, не зависящий от провайдера.
 *
 * system    — постоянная часть (инструкция, справочники, формат ответа): у Anthropic кэшируется.
 * userParts — переменная часть: данные конкретной заявки, файлы.
 * schema    — JSON Schema ответа; если задана, ответ разбирается в AiResponse::$data.
 */
final class AiRequest
{
    public string $system = '';
    /** @var array<int, array{type: string, text?: string, media_type?: string, data?: string, name?: string}> */
    public array $userParts = [];
    public ?array $schema = null;
    /** Имя схемы для OpenAI: латиница, цифры, _ и -, до 64 символов. */
    public string $schemaName = 'result';
    /** Переопределение модели подключения. */
    public ?string $model = null;
    public ?int $maxTokens = null;
    /** null — не передавать temperature вовсе. */
    public ?float $temperature = 0.0;

    public static function textPart(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    public static function imagePart(string $mediaType, string $base64): array
    {
        return ['type' => 'image', 'media_type' => $mediaType, 'data' => $base64];
    }

    public static function pdfPart(string $base64, string $name = 'document.pdf'): array
    {
        return ['type' => 'pdf', 'data' => $base64, 'name' => $name];
    }
}
