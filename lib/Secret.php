<?php
namespace Local\AiLab;

use Bitrix\Main\Application;

/**
 * Шифрование API-ключей (AES-256-GCM).
 *
 * Ключ шифрования лежит в файле вне веб-корня: по умолчанию /home/bitrix/.ailab/secret.key
 * (каталог рядом с DOCUMENT_ROOT). Выгрузка одной только базы ключи API не раскрывает.
 * Путь можно переопределить константой LOCAL_AILAB_PRIVATE_DIR в /local/php_interface/init.php.
 */
final class Secret
{
    private const CIPHER  = 'aes-256-gcm';
    private const PREFIX  = 'v1:';
    private const IV_LEN  = 12;
    private const TAG_LEN = 16;

    private static ?string $key = null;

    public static function privateDir(): string
    {
        if (defined('LOCAL_AILAB_PRIVATE_DIR') && (string)LOCAL_AILAB_PRIVATE_DIR !== '') {
            return rtrim((string)LOCAL_AILAB_PRIVATE_DIR, '/');
        }
        return dirname(rtrim(Application::getDocumentRoot(), '/')) . '/.ailab';
    }

    public static function keyFile(): string
    {
        return self::privateDir() . '/secret.key';
    }

    /** Создаёт каталог и ключ, если их ещё нет. Существующий ключ не трогает. */
    public static function ensureKey(): void
    {
        $dir = self::privateDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf(
                'Не удалось создать каталог %s. Создайте его от пользователя веб-сервера с правами 700 '
                . 'или задайте другой путь константой LOCAL_AILAB_PRIVATE_DIR в /local/php_interface/init.php.',
                $dir
            ));
        }

        $file = self::keyFile();
        if (!is_file($file)) {
            if (@file_put_contents($file, base64_encode(random_bytes(32)) . "\n", LOCK_EX) === false) {
                throw new \RuntimeException('Не удалось записать ключ шифрования в ' . $file);
            }
            @chmod($file, 0600);
        }

        self::$key = null;
        self::key();
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $cipherText = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN);
        if ($cipherText === false) {
            throw new \RuntimeException('Не удалось зашифровать значение');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipherText);
    }

    public static function decrypt(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        if (strncmp($stored, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            throw new \RuntimeException('Неизвестный формат зашифрованного значения');
        }
        $bin = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($bin === false || strlen($bin) <= self::IV_LEN + self::TAG_LEN) {
            throw new \RuntimeException('Зашифрованное значение повреждено');
        }
        $iv = substr($bin, 0, self::IV_LEN);
        $tag = substr($bin, self::IV_LEN, self::TAG_LEN);
        $cipherText = substr($bin, self::IV_LEN + self::TAG_LEN);

        $plain = openssl_decrypt($cipherText, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('API-ключ не расшифровывается: файл ' . self::keyFile() . ' сменился. Введите API-ключ заново.');
        }
        return $plain;
    }

    /** sk-…a1b2 — для показа в интерфейсе. */
    public static function mask(string $plain): string
    {
        $len = mb_strlen($plain);
        if ($len === 0) {
            return '';
        }
        if ($len <= 8) {
            return str_repeat('•', $len);
        }
        return mb_substr($plain, 0, 3) . '…' . mb_substr($plain, -4);
    }

    /** @return array{ok: bool, text: string} */
    public static function status(): array
    {
        try {
            self::key();
            return ['ok' => true, 'text' => 'Ключ шифрования: ' . self::keyFile() . '. Добавьте этот файл в бэкап сервера: без него сохранённые API-ключи придётся вводить заново.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'text' => $e->getMessage()];
        }
    }

    private static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $file = self::keyFile();
        $raw = is_file($file) ? trim((string)@file_get_contents($file)) : '';
        $key = $raw === '' ? false : base64_decode($raw, true);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('Ключ шифрования не найден или повреждён: ' . $file . '. Переустановите модуль, чтобы создать новый.');
        }
        return self::$key = $key;
    }
}
