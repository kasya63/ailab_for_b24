<?php
namespace Local\AiLab\Files;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Local\AiLab\Ai\AiRequest;
use Local\AiLab\Settings;

/**
 * Файл из b_file → то, что можно отдать модели.
 *
 *   режим «text»:   PDF → pdftotext; Word/Excel/PowerPoint → LibreOffice → PDF → pdftotext;
 *                   txt/csv/json/html — как есть. PDF-скан без текста и картинки уходят вложением.
 *   режим «native»: PDF и картинки — вложением (модель видит страницы целиком); Office — текстом.
 *
 * Лимиты из настроек; превышение — ошибка, а не молчаливая обрезка.
 */
final class FileExtractor
{
    public const MODE_NAME   = 'name';
    public const MODE_TEXT   = 'text';
    public const MODE_NATIVE = 'native';

    public const MODES = [
        self::MODE_NAME   => 'только имя файла',
        self::MODE_TEXT   => 'содержимое текстом — дешевле',
        self::MODE_NATIVE => 'PDF и картинки целиком — модель видит сканы, печати, таблицы',
    ];

    private const IMAGES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
    private const OFFICE = ['doc', 'docx', 'odt', 'rtf', 'ppt', 'pptx', 'odp'];
    /** Таблицы конвертируются в HTML: в PDF длинный текст ячейки обрезается шириной колонки. */
    private const SHEETS = ['xls', 'xlsx', 'ods'];
    private const PLAIN  = ['txt', 'csv', 'tsv', 'md', 'json', 'xml', 'html', 'htm', 'log'];

    public static function prepare(int $fileId, string $mode): Attachment
    {
        [$path, $name, $mime, $size] = self::locate($fileId);
        return self::prepareFile($path, $name, $mime, $size, $mode, $fileId);
    }

    /** Без обращения к b_file — по пути на диске. */
    public static function prepareFile(string $path, string $name, string $mime, int $size, string $mode, int $fileId = 0): Attachment
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new FileException("Файл «{$name}» не найден на диске");
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $maxMb = Settings::int('files_max_mb', 1, 200);
        if ($size > $maxMb * 1048576) {
            throw new FileException(sprintf('Файл «%s»: %s МБ — больше лимита %d МБ', $name, self::mb($size), $maxMb));
        }

        if (isset(self::IMAGES[$ext]) || str_starts_with($mime, 'image/')) {
            $imageMax = Settings::int('files_image_max_mb', 1, 20);
            if ($size > $imageMax * 1048576) {
                throw new FileException(sprintf('Изображение «%s»: %s МБ — больше лимита %d МБ для картинок', $name, self::mb($size), $imageMax));
            }
            $attachment = new Attachment($fileId, $name, self::IMAGES[$ext] ?? $mime, $size, Attachment::KIND_IMAGE);
            $attachment->base64 = base64_encode((string)file_get_contents($path));
            if ($mode === self::MODE_TEXT) {
                $attachment->notes[] = 'картинку нельзя перевести в текст — передана как есть';
            }
            return $attachment;
        }

        if ($ext === 'pdf' || $mime === 'application/pdf') {
            $pages = self::pdfPages($path);
            if ($mode === self::MODE_NATIVE) {
                return self::nativePdf($path, $name, $size, $pages, $fileId);
            }
            $text = self::pdfToText($path, $name);
            if (trim($text, " \t\n\r\0\x0B\f") === '') {
                $attachment = self::nativePdf($path, $name, $size, $pages, $fileId);
                $attachment->notes[] = 'в PDF нет текстового слоя (скан) — передан целиком';
                return $attachment;
            }
            $attachment = self::textAttachment($fileId, $name, 'application/pdf', $size, $text);
            $attachment->pages = $pages;
            return $attachment;
        }

        if (in_array($ext, self::OFFICE, true)) {
            return self::textAttachment($fileId, $name, $mime, $size, self::officeToText($path, $name, $ext));
        }

        if (in_array($ext, self::SHEETS, true)) {
            return self::textAttachment($fileId, $name, $mime, $size, self::spreadsheetToText($path, $name, $ext));
        }

        if (in_array($ext, self::PLAIN, true) || str_starts_with($mime, 'text/')) {
            $text = (string)file_get_contents($path);
            if (!mb_check_encoding($text, 'UTF-8')) {
                $text = (string)mb_convert_encoding($text, 'UTF-8', 'Windows-1251');
            }
            if (in_array($ext, ['html', 'htm'], true)) {
                $text = html_entity_decode(strip_tags((string)preg_replace('#<(script|style)\b.*?</\1>#is', '', $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            return self::textAttachment($fileId, $name, $mime, $size, $text);
        }

        throw new FileException(sprintf(
            'Файл «%s»: формат .%s не поддерживается. Подходят PDF, картинки (JPG, PNG, GIF, WEBP), Word, Excel, PowerPoint и текстовые файлы.',
            $name, $ext !== '' ? $ext : '?'
        ));
    }

    /** Часть запроса для вложения. */
    public static function part(Attachment $attachment): array
    {
        return $attachment->kind === Attachment::KIND_PDF
            ? AiRequest::pdfPart($attachment->base64, $attachment->name)
            : AiRequest::imagePart($attachment->mime, $attachment->base64);
    }

    /** Повторная загрузка вложения по сохранённым в задаче сведениям (очередь хранит ссылку, а не содержимое). */
    public static function reloadPart(array $meta): array
    {
        [$path] = self::locate((int)$meta['file_id']);
        $data = base64_encode((string)file_get_contents($path));
        return ($meta['kind'] ?? '') === Attachment::KIND_PDF
            ? AiRequest::pdfPart($data, (string)$meta['name'])
            : AiRequest::imagePart((string)$meta['mime'], $data);
    }

    /** @return array{0: string, 1: string, 2: string, 3: int} [путь, имя, mime, размер] */
    private static function locate(int $fileId): array
    {
        $file = $fileId > 0 ? \CFile::GetFileArray($fileId) : false;
        if (!$file) {
            throw new FileException("Файл #{$fileId} не найден");
        }
        $uploadDir = (string)Option::get('main', 'upload_dir', 'upload');
        $path = Application::getDocumentRoot() . '/' . trim($uploadDir, '/') . '/' . trim((string)$file['SUBDIR'], '/') . '/' . $file['FILE_NAME'];
        if (!is_file($path)) {
            $made = \CFile::MakeFileArray($fileId);
            $path = is_array($made) ? (string)($made['tmp_name'] ?? '') : '';
        }
        $name = (string)($file['ORIGINAL_NAME'] ?: $file['FILE_NAME']);
        if ($path === '' || !is_file($path)) {
            throw new FileException("Файл «{$name}» (#{$fileId}) не найден на диске");
        }
        return [$path, $name, (string)$file['CONTENT_TYPE'], (int)$file['FILE_SIZE']];
    }

    private static function nativePdf(string $path, string $name, int $size, int $pages, int $fileId): Attachment
    {
        $maxPages = Settings::int('files_pdf_max_pages', 1, 1000);
        if ($pages > $maxPages) {
            throw new FileException(sprintf('PDF «%s»: %d стр. — больше лимита %d для передачи целиком. Включите режим «текстом» или поднимите лимит.', $name, $pages, $maxPages));
        }
        $attachment = new Attachment($fileId, $name, 'application/pdf', $size, Attachment::KIND_PDF);
        $attachment->pages = $pages;
        $attachment->base64 = base64_encode((string)file_get_contents($path));
        return $attachment;
    }

    private static function textAttachment(int $fileId, string $name, string $mime, int $size, string $text): Attachment
    {
        $text = trim((string)preg_replace("/[ \t]+\n/", "\n", (string)preg_replace("/\n{3,}/", "\n\n", str_replace(["\r\n", "\r", "\f"], ["\n", "\n", "\n"], $text))));
        $max = Settings::int('files_text_max_chars', 1000, 2000000);
        if (mb_strlen($text) > $max) {
            throw new FileException(sprintf('Файл «%s»: текст %s символов — больше лимита %s. Передайте файл целиком (PDF) или поднимите лимит в настройках.',
                $name, number_format(mb_strlen($text), 0, ',', ' '), number_format($max, 0, ',', ' ')));
        }
        $attachment = new Attachment($fileId, $name, $mime, $size, Attachment::KIND_TEXT);
        $attachment->text = $text;
        return $attachment;
    }

    private static function pdfPages(string $path): int
    {
        try {
            $r = Shell::run([Settings::binary('pdfinfo'), $path], 20);
        } catch (FileException) {
            return 0;
        }
        return preg_match('/^Pages:\s+(\d+)/mi', $r['stdout'], $m) ? (int)$m[1] : 0;
    }

    private static function pdfToText(string $path, string $name): string
    {
        $r = Shell::run([Settings::binary('pdftotext'), '-layout', '-enc', 'UTF-8', $path, '-'], 60);
        if ($r['code'] !== 0) {
            throw new FileException(sprintf('PDF «%s» не прочитан (pdftotext, код %d): %s', $name, $r['code'], trim($r['stderr'])));
        }
        return $r['stdout'];
    }

    /** Word / PowerPoint → PDF (LibreOffice) → текст с сохранением раскладки. */
    private static function officeToText(string $path, string $name, string $ext): string
    {
        return self::withLibreOffice($path, $name, $ext, 'pdf', static fn(string $pdf) => self::pdfToText($pdf, $name));
    }

    /** Excel / ODS → HTML (LibreOffice) → текст: все листы, ячейки через «|», значения как в таблице. */
    private static function spreadsheetToText(string $path, string $name, string $ext): string
    {
        return self::withLibreOffice($path, $name, $ext, 'html', static fn(string $html) => self::htmlTablesToText((string)file_get_contents($html)));
    }

    public static function htmlTablesToText(string $html): string
    {
        $html = (string)preg_replace('#<(head|style|script)\b.*?</\1>#is', '', $html);
        $html = (string)preg_replace('/\s+/u', ' ', $html); // переносы в HTML незначимы — границы строк расставим сами
        $html = (string)preg_replace('#<a\b[^>]*href="\#[^"]*"[^>]*>.*?</a>#is', '', $html); // оглавление листов
        $html = (string)preg_replace('#<h[1-6]\b[^>]*>(.*?)</h[1-6]>#is', "\n### $1\n", $html);
        $html = (string)preg_replace('#</t[dh]>#i', ' | ', $html);
        $html = (string)preg_replace('#</tr>#i', "\n", $html);
        $html = (string)preg_replace('#<br\s*/?>#i', ' ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim((string)preg_replace('/[ \t\x{00A0}]+/u', ' ', $line));
            $line = trim((string)preg_replace('/(\s*\|\s*)+$/u', '', $line));
            $line = (string)preg_replace('/^(\|\s*)+/u', '', $line);
            if ($line !== '' && !preg_match('/^(\|\s*)+$/u', $line) && !preg_match('/^(### )?Overview$/u', $line)) {
                $lines[] = $line;
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Конвертация LibreOffice во временном каталоге с отдельным профилем:
     * параллельные вызовы не мешают друг другу, мусор удаляется в любом случае.
     */
    private static function withLibreOffice(string $path, string $name, string $ext, string $target, callable $read): string
    {
        $dir = rtrim(sys_get_temp_dir(), '/') . '/ailab_' . bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0700, true)) {
            throw new FileException('Не удалось создать временный каталог для конвертации');
        }
        try {
            $source = $dir . '/source.' . $ext;
            if (!@copy($path, $source)) {
                throw new FileException("Файл «{$name}» не скопирован для конвертации");
            }
            $r = Shell::run([
                Settings::binary('soffice'), '--headless', '--norestore', '--nologo', '--nodefault',
                '-env:UserInstallation=file://' . $dir . '/profile',
                '--convert-to', $target, '--outdir', $dir, $source,
            ], 120, ['HOME' => $dir]);
            $result = $dir . '/source.' . $target;
            if (!is_file($result)) {
                throw new FileException(sprintf('Файл «%s» не сконвертирован LibreOffice (код %d): %s',
                    $name, $r['code'], mb_substr(trim($r['stderr'] . ' ' . $r['stdout']), 0, 300)));
            }
            return $read($result);
        } finally {
            self::removeDir($dir);
        }
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir) || !str_contains($dir, '/ailab_')) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    private static function mb(int $bytes): string
    {
        return number_format($bytes / 1048576, 1, ',', ' ');
    }
}
