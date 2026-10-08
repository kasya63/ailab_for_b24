<?php
namespace Local\AiLab\Admin;

use Local\AiLab\Ai\AiResponse;
use Local\AiLab\Json;
use Local\AiLab\Queue\TaskRepository;
use Local\AiLab\Queue\TaskStatus;
use Local\AiLab\Queue\WorkerStatus;
use Local\AiLab\Scenario\BuiltPrompt;
use Local\AiLab\Scenario\RunResult;
use Local\AiLab\Scenario\Source\SourceResult;

/** Вывод для админки. Всё, что приходит снаружи, экранируется здесь. */
final class View
{
    public static function e(mixed $value): string
    {
        return htmlspecialcharsbx((string)$value);
    }

    public static function responseDetails(AiResponse $r): string
    {
        $status = match ($r->status) {
            AiResponse::STATUS_SUCCESS => 'успешно',
            AiResponse::STATUS_INVALID => 'ответ не по формату',
            default                    => 'ошибка' . ($r->retryable ? ', временная: повтор может помочь' : ', повтор не поможет'),
        };

        $rows = [];
        if ($r->error !== '') {
            $rows['Что случилось'] = $r->error;
        }
        $rows['Статус'] = $status;
        $rows['HTTP'] = $r->httpStatus > 0 ? (string)$r->httpStatus : 'нет ответа';
        $rows['Время ответа'] = number_format($r->durationMs / 1000, 1, ',', ' ') . ' с';
        $rows['Модель'] = $r->model !== '' ? $r->model : '—';
        $rows['Токены'] = sprintf(
            'вход %d, выход %d, прочитано из кэша %d, записано в кэш %d',
            $r->inputTokens, $r->outputTokens, $r->cacheReadTokens, $r->cacheWriteTokens
        );
        if ($r->stopReason !== '') {
            $rows['Причина остановки'] = $r->stopReason;
        }

        $html = '<table class="ailab-kv">';
        foreach ($rows as $label => $value) {
            $html .= '<tr><th>' . self::e($label) . '</th><td>' . self::e($value) . '</td></tr>';
        }
        $html .= '</table>';

        foreach ($r->notes as $note) {
            $html .= '<p class="ailab-note">' . self::e($note) . '</p>';
        }

        if ($r->data !== null) {
            $html .= '<div class="ailab-label">Разобранный ответ</div>'
                . '<pre class="ailab-pre">' . self::e(Json::encode($r->data, true)) . '</pre>';
        } elseif ($r->text !== '') {
            $html .= '<div class="ailab-label">Текст ответа</div><pre class="ailab-pre">' . self::e($r->text) . '</pre>';
        }

        $html .= self::details('Запрос, который ушёл провайдеру (без ключа)', Json::prettyOrRaw($r->rawRequest));
        $html .= self::details('Ответ провайдера как есть', Json::prettyOrRaw($r->rawResponse));
        return $html;
    }

    public static function url(string $page, array $params = []): string
    {
        return $page . '?' . http_build_query(['lang' => LANGUAGE_ID] + $params);
    }

    public static function message(string $type, string $title, string $detailsHtml = ''): void
    {
        \CAdminMessage::ShowMessage([
            'TYPE'    => $type,
            'MESSAGE' => self::e($title),
            'DETAILS' => $detailsHtml,
            'HTML'    => true,
        ]);
    }

    /** @param string[] $errors */
    public static function errors(array $errors, string $title = 'Не сохранено'): void
    {
        if ($errors) {
            self::message('ERROR', $title, implode('<br>', array_map([self::class, 'e'], $errors)));
        }
    }

    /**
     * @param array<string, string>|array<string, array<string, string>> $options значение => подпись,
     *        или группа => [значение => подпись] при $grouped
     */
    public static function options(array $options, string $selected, bool $grouped = false): string
    {
        $html = '';
        if ($grouped) {
            foreach ($options as $group => $items) {
                $html .= '<optgroup label="' . self::e($group) . '">' . self::options($items, $selected) . '</optgroup>';
            }
            return $html;
        }
        foreach ($options as $value => $label) {
            $html .= '<option value="' . self::e($value) . '"' . ((string)$value === $selected ? ' selected' : '') . '>'
                . self::e($label) . '</option>';
        }
        return $html;
    }

    public static function number(int|float $n): string
    {
        return number_format($n, 0, ',', ' ');
    }

    public static function sourcePreview(SourceResult $r): string
    {
        $html = '<table class="ailab-kv">'
            . '<tr><th>Строк</th><td>' . self::number($r->rows) . '</td></tr>'
            . '<tr><th>ID для ссылок в ответе</th><td>' . ($r->ids ? self::number(count($r->ids)) : 'нет') . '</td></tr>'
            . '<tr><th>Размер</th><td>' . self::number(mb_strlen($r->text)) . ' символов, ≈ ' . self::number((int)ceil(mb_strlen($r->text) / 3)) . ' токенов</td></tr>'
            . '</table>';
        foreach ($r->warnings as $w) {
            $html .= '<p class="ailab-note">' . self::e($w) . '</p>';
        }
        $lines = explode("\n", $r->text);
        $head = implode("\n", array_slice($lines, 0, 32));
        $html .= '<div class="ailab-label">Так данные увидит модель' . (count($lines) > 32 ? ' (первые 30 строк)' : '') . '</div>'
            . '<pre class="ailab-pre">' . self::e($head) . '</pre>';
        if (count($lines) > 32) {
            $html .= self::details('Показать полностью', $r->text);
        }
        return $html;
    }

    public static function builtPrompt(BuiltPrompt $b, array $sourceTitles): string
    {
        $html = '<table class="ailab-kv">';
        foreach ($b->sources as $sourceId => $res) {
            $html .= '<tr><th>' . self::e($sourceTitles[$sourceId] ?? ('Источник #' . $sourceId)) . '</th><td>'
                . self::number($res->rows) . ' строк, ' . self::number(mb_strlen($res->text)) . ' символов</td></tr>';
        }
        foreach ($b->attachments as $a) {
            $how = match ($a['kind']) {
                'pdf'   => 'PDF целиком' . ($a['pages'] ? ', ' . $a['pages'] . ' стр. (≈ ' . self::number($a['pages'] * 1500) . ' токенов)' : ''),
                'image' => 'изображение целиком',
                default => 'текстом, ' . self::number((int)$a['chars']) . ' символов',
            };
            $html .= '<tr><th>Файл: ' . self::e(($a['label'] !== '' ? $a['label'] . ' — ' : '') . $a['name']) . '</th><td>' . self::e($how) . '</td></tr>';
        }
        $html .= '<tr><th>Весь промт</th><td>' . self::number($b->chars()) . ' символов текста, ≈ ' . self::number($b->approxTokens()) . ' токенов' . ($b->attachments ? ' без учёта вложений' : '') . '</td></tr>'
            . '<tr><th>Постоянная часть</th><td>' . self::number(mb_strlen($b->systemText)) . ' символов — инструкция, данные и формат, кэшируется у провайдера</td></tr>'
            . '<tr><th>Данные заявки</th><td>' . self::number(mb_strlen($b->userText)) . ' символов</td></tr>'
            . '</table>';
        foreach ($b->warnings as $w) {
            $html .= '<p class="ailab-note">' . self::e($w) . '</p>';
        }
        $html .= self::details('Постоянная часть промта (system)', $b->systemText, true);
        $html .= self::details('Данные заявки (user)', $b->userText, true);
        if ($b->request->schema !== null) {
            $html .= self::details('JSON Schema ответа', Json::encode($b->request->schema, true));
        }
        return $html;
    }

    public static function runResult(RunResult $r): string
    {
        $tokens = $r->totalTokens();
        $html = '<table class="ailab-kv">'
            . '<tr><th>Попыток</th><td>' . count($r->attempts) . '</td></tr>'
            . '<tr><th>Время</th><td>' . number_format($r->totalDurationMs() / 1000, 1, ',', ' ') . ' с</td></tr>'
            . '<tr><th>Токены</th><td>вход ' . self::number($tokens['input']) . ', выход ' . self::number($tokens['output'])
            . ', из кэша ' . self::number($tokens['cached']) . '</td></tr>';
        if ($r->error !== '') {
            $html .= '<tr><th>Что не так</th><td>' . self::e($r->error) . '</td></tr>';
        }
        $html .= '</table>';

        if ($r->data !== null) {
            $html .= '<div class="ailab-label">Результат — эти значения получит бизнес-процесс</div>'
                . '<pre class="ailab-pre">' . self::e(Json::encode($r->data, true)) . '</pre>';
        } elseif ($r->text !== '') {
            $html .= '<div class="ailab-label">Ответ</div><pre class="ailab-pre">' . self::e($r->text) . '</pre>';
        }
        if ($r->validation) {
            foreach ($r->validation->warnings as $w) {
                $html .= '<p class="ailab-note">Поправлено: ' . self::e($w) . '</p>';
            }
        }
        foreach ($r->attempts as $i => $attempt) {
            $html .= '<details class="ailab-details"><summary>Попытка ' . ($i + 1) . ': ' . self::e($attempt->status)
                . ', ' . number_format($attempt->durationMs / 1000, 1, ',', ' ') . ' с</summary>'
                . self::responseDetails($attempt) . '</details>';
        }
        return $html;
    }

    public static function taskStatus(string $status): string
    {
        return '<span class="ailab-st ailab-st-' . self::e($status) . '">' . self::e(TaskStatus::label($status)) . '</span>';
    }

    public static function ago(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return 'ни разу';
        }
        $s = max(0, time() - $timestamp);
        return match (true) {
            $s < 60    => $s . ' с назад',
            $s < 3600  => intdiv($s, 60) . ' мин назад',
            $s < 86400 => intdiv($s, 3600) . ' ч назад',
            default    => date('d.m.Y H:i', $timestamp),
        };
    }

    /** Состояние обработчика очереди: строка, если всё в порядке, и предупреждение с инструкцией, если нет. */
    public static function workerBanner(): void
    {
        $counts = TaskRepository::countByStatus(['@STATUS' => [TaskStatus::PENDING, TaskStatus::PROCESSING]]);
        $pending = $counts[TaskStatus::PENDING] ?? 0;
        $processing = $counts[TaskStatus::PROCESSING] ?? 0;
        $beat = WorkerStatus::lastBeat();

        if (WorkerStatus::isAlive()) {
            echo '<p class="ailab-hint">Обработчик очереди отмечался ' . self::e(self::ago($beat))
                . ' · в очереди: ' . $pending . ' · отправлено: ' . $processing . '</p>';
            return;
        }

        $script = dirname(__DIR__, 2) . '/tools/worker.php';
        $details = ($pending > 0 ? '<b>В очереди ждут задач: ' . $pending . '.</b> ' : '')
            . 'Последний раз обработчик отмечался: ' . self::e(self::ago($beat)) . '.<br><br>'
            . 'Добавьте строку в cron пользователя сайта (<code>crontab -u bitrix -e</code>):<br>'
            . '<pre class="ailab-pre">' . self::e(WorkerStatus::cronLine()) . '</pre>'
            . 'Проверить вручную, с подробным выводом:<br>'
            . '<pre class="ailab-pre">' . self::e('sudo -u bitrix php -f ' . $script . ' -- -v') . '</pre>';
        self::message($pending > 0 ? 'ERROR' : 'OK', $beat > 0 ? 'Обработчик очереди давно не запускался' : 'Обработчик очереди ещё не запускался', $details);
    }

    public static function styles(): string
    {
        return <<<'CSS'
<style>
.ailab-kv{border-collapse:collapse;margin:4px 0 8px}
.ailab-kv th{font-weight:normal;color:#535c69;text-align:left;padding:2px 16px 2px 0;vertical-align:top;white-space:nowrap}
.ailab-kv td{padding:2px 0}
.ailab-label{margin:10px 0 4px;font-weight:bold}
.ailab-note{margin:6px 0}
.ailab-pre{max-height:360px;overflow:auto;background:#f5f7f8;border:1px solid #dce0e3;padding:8px;white-space:pre-wrap;word-break:break-word;font-size:12px;margin:0}
.ailab-details{margin-top:8px}
.ailab-details summary{cursor:pointer;color:#2067b0}
.ailab-hint{color:#80868e;font-size:12px;margin-top:4px}
.ailab-actions form{display:inline;margin:0 0 0 10px}
.ailab-empty{padding:16px 0}
.ailab-pre-tall{max-height:640px}
.ailab-grid{border-collapse:collapse;width:100%}
.ailab-grid th{text-align:left;font-weight:normal;color:#535c69;padding:4px 6px;border-bottom:1px solid #dce0e3}
.ailab-grid td{padding:4px 6px;vertical-align:top;border-bottom:1px solid #eef0f2}
.ailab-grid input[type=text],.ailab-grid textarea,.ailab-grid select{width:100%;box-sizing:border-box}
.ailab-fields{columns:3 260px;column-gap:24px}
.ailab-fields label{display:block;break-inside:avoid;padding:2px 0}
.ailab-fields .ailab-type{color:#80868e;font-size:11px}
.ailab-wide{width:100%;box-sizing:border-box}
.ailab-mono{font-family:Consolas,Menlo,monospace;font-size:12px}
.ailab-st{display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;white-space:nowrap;background:#eef0f2;color:#535c69}
.ailab-st-processing{background:#e5f1fb;color:#1f6fb2}
.ailab-st-success{background:#e8f5e3;color:#3a7a1f}
.ailab-st-error{background:#fde9e7;color:#b3261e}
.ailab-st-invalid{background:#fff1dc;color:#9a5b00}
.ailab-st-timeout{background:#f1e9fb;color:#6b3fa0}
.ailab-filter{margin:8px 0 12px}
.ailab-filter select,.ailab-filter input{margin-right:8px}
.ailab-pager{margin:12px 0}
.ailab-pager a,.ailab-pager b{margin-right:8px}
.ailab-cut{max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
</style>
CSS;
    }

    public static function details(string $title, string $body, bool $tall = false): string
    {
        if ($body === '') {
            return '';
        }
        return '<details class="ailab-details"><summary>' . self::e($title) . '</summary>'
            . '<pre class="ailab-pre' . ($tall ? ' ailab-pre-tall' : '') . '">' . self::e($body) . '</pre></details>';
    }
}
