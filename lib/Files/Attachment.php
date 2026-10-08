<?php
namespace Local\AiLab\Files;

/** Подготовленный файл: текстом в промт или вложением (картинка / PDF). */
final class Attachment
{
    public const KIND_TEXT  = 'text';
    public const KIND_PDF   = 'pdf';
    public const KIND_IMAGE = 'image';

    public string $text = '';
    public string $base64 = '';
    public int $pages = 0;
    /** @var string[] */
    public array $notes = [];

    public function __construct(
        public readonly int $fileId,
        public readonly string $name,
        public readonly string $mime,
        public readonly int $size,
        public string $kind,
    ) {
    }

    public function isNative(): bool
    {
        return $this->kind !== self::KIND_TEXT;
    }

    public function describe(): string
    {
        $parts = [match ($this->kind) {
            self::KIND_PDF   => 'PDF вложением',
            self::KIND_IMAGE => 'изображение вложением',
            default          => 'текстом, ' . number_format(mb_strlen($this->text), 0, ',', ' ') . ' символов',
        }];
        if ($this->pages > 0) {
            $parts[] = $this->pages . ' стр.';
        }
        $parts[] = number_format($this->size / 1024, 0, ',', ' ') . ' КБ';
        return implode(', ', $parts);
    }

    /** Для журнала задачи (без содержимого). */
    public function meta(): array
    {
        return [
            'file_id' => $this->fileId, 'name' => $this->name, 'mime' => $this->mime, 'size' => $this->size,
            'kind' => $this->kind, 'pages' => $this->pages, 'chars' => mb_strlen($this->text), 'notes' => $this->notes,
        ];
    }
}
