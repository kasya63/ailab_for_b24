<?php
namespace Local\AiLab\Admin;

use Bitrix\Main\Type\DateTime;
use Local\AiLab\Ai\AdapterFactory;
use Local\AiLab\Json;
use Local\AiLab\Model\ConnectionTable;
use Local\AiLab\Secret;

/** Проверка и сохранение формы подключения. */
final class ConnectionService
{
    /** Эти заголовки модуль ставит сам. */
    private const RESERVED_HEADERS = [
        'authorization', 'x-api-key', 'anthropic-version', 'content-type', 'content-length', 'host', 'accept',
    ];

    private const STRUCTURED_MODES = ['json_schema', 'json_object', 'prompt'];
    private const TOKEN_FIELDS = ['max_tokens', 'max_completion_tokens'];

    public static function defaults(): array
    {
        return [
            'NAME'               => '',
            'ADAPTER'            => 'anthropic',
            'BASE_URL'           => '',
            'MODEL'              => '',
            'TIMEOUT'            => '120',
            'MAX_TOKENS'         => '2048',
            'ACTIVE'             => 'Y',
            'P_STRUCTURED_MODE'  => 'json_schema',
            'P_MAX_TOKENS_FIELD' => 'max_tokens',
            'P_SEND_TEMPERATURE' => 'Y',
            'P_PROMPT_CACHE'     => 'Y',
            'P_EXTRA_HEADERS'    => '',
            'P_EXTRA_BODY'       => '',
        ];
    }

    public static function rowToForm(array $row): array
    {
        $params = json_decode((string)($row['PARAMS'] ?? ''), true);
        $params = is_array($params) ? $params : [];

        $headers = [];
        foreach ((array)($params['extra_headers'] ?? []) as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        return [
            'NAME'               => (string)$row['NAME'],
            'ADAPTER'            => (string)$row['ADAPTER'],
            'BASE_URL'           => (string)$row['BASE_URL'],
            'MODEL'              => (string)$row['MODEL'],
            'TIMEOUT'            => (string)(int)$row['TIMEOUT'],
            'MAX_TOKENS'         => (string)(int)$row['MAX_TOKENS'],
            'ACTIVE'             => $row['ACTIVE'] === 'Y' ? 'Y' : '',
            'P_STRUCTURED_MODE'  => (string)($params['structured_mode'] ?? 'json_schema'),
            'P_MAX_TOKENS_FIELD' => (string)($params['max_tokens_field'] ?? 'max_tokens'),
            'P_SEND_TEMPERATURE' => ($params['send_temperature'] ?? true) ? 'Y' : '',
            'P_PROMPT_CACHE'     => ($params['prompt_cache'] ?? true) ? 'Y' : '',
            'P_EXTRA_HEADERS'    => implode("\n", $headers),
            'P_EXTRA_BODY'       => empty($params['extra_body']) ? '' : Json::encode($params['extra_body'], true),
        ];
    }

    /**
     * @return array{0: int, 1: string[]} ID и ошибки (пустой массив — сохранено)
     */
    public static function save(array $in): array
    {
        $id = max(0, (int)($in['ID'] ?? 0));
        $errors = [];

        $adapter = (string)($in['ADAPTER'] ?? '');
        if (!AdapterFactory::exists($adapter)) {
            $errors[] = 'Выберите провайдера.';
        }

        $name = trim((string)($in['NAME'] ?? ''));
        if ($name === '') {
            $errors[] = 'Укажите название подключения.';
        }

        $model = trim((string)($in['MODEL'] ?? ''));
        if ($model === '') {
            $errors[] = 'Укажите модель: её идентификатор есть в кабинете провайдера.';
        }

        $baseUrl = rtrim(trim((string)($in['BASE_URL'] ?? '')), '/');
        if ($baseUrl === '') {
            $baseUrl = AdapterFactory::defaultBaseUrl($adapter);
        }
        if ($baseUrl !== '' && !self::isAllowedUrl($baseUrl)) {
            $errors[] = 'Адрес API должен начинаться с https:// (http:// допускается только для localhost).';
        }

        $params = self::params($in, $errors);

        if ($id > 0 && !ConnectionTable::getById($id)->fetch()) {
            $errors[] = "Подключение #{$id} не найдено.";
        }
        if ($errors) {
            return [$id, $errors];
        }

        $fields = [
            'NAME'       => $name,
            'ADAPTER'    => $adapter,
            'BASE_URL'   => $baseUrl,
            'MODEL'      => $model,
            'TIMEOUT'    => self::intInRange($in['TIMEOUT'] ?? '', 120, 5, 600),
            'MAX_TOKENS' => self::intInRange($in['MAX_TOKENS'] ?? '', 2048, 16, 64000),
            'PARAMS'     => Json::encode($params),
            'ACTIVE'     => ($in['ACTIVE'] ?? '') === 'Y' ? 'Y' : 'N',
            'UPDATED_AT' => new DateTime(),
        ];

        $newKey = trim((string)($in['API_KEY'] ?? ''));
        if ($newKey !== '') {
            $fields['API_KEY_ENC'] = Secret::encrypt($newKey);
        } elseif (($in['API_KEY_CLEAR'] ?? '') === 'Y') {
            $fields['API_KEY_ENC'] = '';
        }

        if ($id > 0) {
            $result = ConnectionTable::update($id, $fields);
        } else {
            $fields['CREATED_AT'] = new DateTime();
            $fields += ['API_KEY_ENC' => ''];
            $result = ConnectionTable::add($fields);
            $id = (int)$result->getId();
        }

        if (!$result->isSuccess()) {
            return [$id, $result->getErrorMessages()];
        }
        return [$id, []];
    }

    public static function delete(int $id): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('Не передан ID подключения');
        }
        $result = ConnectionTable::delete($id);
        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }
    }

    private static function params(array $in, array &$errors): array
    {
        $mode = (string)($in['P_STRUCTURED_MODE'] ?? 'json_schema');
        $tokensField = (string)($in['P_MAX_TOKENS_FIELD'] ?? 'max_tokens');

        $headers = [];
        foreach (preg_split('/\R/u', (string)($in['P_EXTRA_HEADERS'] ?? '')) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^([A-Za-z0-9-]+)\s*:\s*(.+)$/', $line, $m)) {
                $errors[] = 'Строка заголовка должна выглядеть как «Имя: значение»: ' . $line;
                continue;
            }
            if (in_array(strtolower($m[1]), self::RESERVED_HEADERS, true)) {
                $errors[] = "Заголовок {$m[1]} модуль задаёт сам, уберите его из дополнительных.";
                continue;
            }
            $headers[$m[1]] = trim($m[2]);
        }

        $extraBody = [];
        $rawBody = trim((string)($in['P_EXTRA_BODY'] ?? ''));
        if ($rawBody !== '') {
            $decoded = Json::decodeObject($rawBody);
            if ($decoded === null) {
                $errors[] = 'Дополнительные параметры запроса должны быть JSON-объектом, например {"reasoning_effort": "low"}.';
            } else {
                $extraBody = $decoded;
            }
        }

        return [
            'structured_mode'  => in_array($mode, self::STRUCTURED_MODES, true) ? $mode : 'json_schema',
            'max_tokens_field' => in_array($tokensField, self::TOKEN_FIELDS, true) ? $tokensField : 'max_tokens',
            'send_temperature' => ($in['P_SEND_TEMPERATURE'] ?? '') === 'Y',
            'prompt_cache'     => ($in['P_PROMPT_CACHE'] ?? '') === 'Y',
            'extra_headers'    => (object)$headers,
            'extra_body'       => (object)$extraBody,
        ];
    }

    private static function isAllowedUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        $host = strtolower(trim((string)parse_url($url, PHP_URL_HOST), '[]'));

        return $scheme === 'https'
            || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true));
    }

    private static function intInRange(mixed $raw, int $default, int $min, int $max): int
    {
        $raw = trim((string)$raw);
        if (!preg_match('/^\d+$/', $raw)) {
            return $default;
        }
        return max($min, min($max, (int)$raw));
    }
}
