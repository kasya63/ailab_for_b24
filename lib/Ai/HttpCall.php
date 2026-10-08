<?php
namespace Local\AiLab\Ai;

/** Готовый HTTP-запрос к провайдеру. */
final class HttpCall
{
    /** @param array<string, string> $headers имя => значение */
    public function __construct(
        public readonly string $url,
        public readonly array $headers,
        public readonly string $body,
        public readonly int $timeout,
    ) {
    }
}
