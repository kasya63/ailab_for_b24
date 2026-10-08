<?php
namespace Local\AiLab\Ai;

final class AdapterFactory
{
    /** @var array<string, class-string<AdapterInterface>> */
    private const MAP = [
        'anthropic' => Adapter\Anthropic::class,
        'openai'    => Adapter\OpenAiCompatible::class,
    ];

    public static function exists(string $code): bool
    {
        return isset(self::MAP[$code]);
    }

    public static function make(string $code): AdapterInterface
    {
        if (!self::exists($code)) {
            throw new \InvalidArgumentException('Неизвестный провайдер: ' . $code);
        }
        $class = self::MAP[$code];
        return new $class();
    }

    /** @return array<string, string> код => название */
    public static function titles(): array
    {
        $titles = [];
        foreach (self::MAP as $code => $class) {
            $titles[$code] = $class::title();
        }
        return $titles;
    }

    public static function defaultBaseUrl(string $code): string
    {
        if (!self::exists($code)) {
            return '';
        }
        $class = self::MAP[$code];
        return $class::defaultBaseUrl();
    }
}
