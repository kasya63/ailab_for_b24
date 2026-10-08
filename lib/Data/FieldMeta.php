<?php
namespace Local\AiLab\Data;

/** Описание поля сущности: код, подпись, нормализованный тип и настройки для форматирования. */
final class FieldMeta
{
    public function __construct(
        public readonly string $code,
        public readonly string $title,
        public readonly string $type,
        public readonly bool $multiple = false,
        public readonly array $settings = [],
    ) {
    }

    public function label(): string
    {
        return TypeLabels::label($this->type);
    }

    public function isFile(): bool
    {
        return $this->type === 'file';
    }

    public function isTextual(): bool
    {
        return in_array($this->type, ['string', 'text', 'html', 'url', 'address'], true);
    }
}
