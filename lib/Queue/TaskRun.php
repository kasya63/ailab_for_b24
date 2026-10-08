<?php
namespace Local\AiLab\Queue;

use Local\AiLab\Ai\AiRequest;
use Local\AiLab\Ai\AiResponse;
use Local\AiLab\Ai\Connection;
use Local\AiLab\Files\FileExtractor;
use Local\AiLab\Json;
use Local\AiLab\Scenario\OutputSchema;
use Local\AiLab\Scenario\PromptBuilder;
use Local\AiLab\Scenario\RunInput;
use Local\AiLab\Scenario\Scenario;

/**
 * Задача в работе у обработчика: собранный запрос, подключение, схема и журнал попыток.
 *
 * Промт собирается один раз — при первом взятии задачи — и сохраняется в BUILT.
 * Повторы после сетевых сбоев уходят с тем же самым промтом и проверяются по тем же ID,
 * даже если справочник за это время поменялся.
 */
final class TaskRun
{
    public AiRequest $request;
    public Connection $connection;
    public OutputSchema $schema;
    /** @var array<int, array<int, true>> */
    public array $refSets = [];
    /** @var list<array> */
    public array $attemptLog = [];
    public bool $correctionUsed = false;

    private function __construct(public readonly array $task)
    {
    }

    public static function start(array $task): self
    {
        $run = new self($task);
        $log = json_decode((string)($task['ATTEMPT_LOG'] ?? ''), true);
        $run->attemptLog = is_array($log) ? $log : [];

        $built = json_decode((string)($task['BUILT'] ?? ''), true);
        if (!is_array($built)) {
            $built = self::build($task);
        }

        $system = PromptStore::get((string)$built['prompt_hash']);
        if ($system === null) {
            throw new \RuntimeException('Не найдена сохранённая часть промта ' . $built['prompt_hash']);
        }

        $run->schema = OutputSchema::fromArray((array)($built['fields'] ?? []));
        $request = new AiRequest();
        $request->system = $system;
        $request->userParts = [AiRequest::textPart((string)$built['user_text'])];
        foreach ((array)($built['attachments'] ?? []) as $meta) {
            if (in_array($meta['kind'] ?? '', ['pdf', 'image'], true)) {
                $request->userParts[] = FileExtractor::reloadPart($meta);
            }
        }
        if (!$run->schema->isEmpty()) {
            $request->schema = $run->schema->jsonSchema();
            $request->schemaName = (string)($built['schema_name'] ?? 'result');
        }
        $request->model = ($built['model'] ?? '') !== '' ? (string)$built['model'] : null;
        $request->maxTokens = (int)($built['max_tokens'] ?? 0) > 0 ? (int)$built['max_tokens'] : null;
        $run->request = $request;

        foreach ((array)($built['ref_sets'] ?? []) as $sourceId => $ids) {
            $run->refSets[(int)$sourceId] = array_fill_keys(array_map('intval', (array)$ids), true);
        }

        $run->connection = Connection::load((int)$built['connection_id']);
        return $run;
    }

    public function id(): int
    {
        return (int)$this->task['ID'];
    }

    public function attempts(): int
    {
        return (int)$this->task['ATTEMPTS'];
    }

    public function deadline(): int
    {
        $deadline = $this->task['DEADLINE_AT'] ?? null;
        return $deadline instanceof \Bitrix\Main\Type\DateTime ? $deadline->getTimestamp() : time() + 600;
    }

    public function log(AiResponse $r, string $kind, array $problems = []): void
    {
        $this->attemptLog[] = [
            'n'           => count($this->attemptLog) + 1,
            'kind'        => $kind,
            'at'          => date('d.m.Y H:i:s'),
            'attempt'     => $this->attempts(),
            'status'      => $r->status,
            'http'        => $r->httpStatus,
            'error'       => $r->error,
            'retryable'   => $r->retryable,
            'duration_ms' => $r->durationMs,
            'model'       => $r->model,
            'in'          => $r->inputTokens,
            'out'         => $r->outputTokens,
            'cached'      => $r->cacheReadTokens,
            'stop'        => $r->stopReason,
            'problems'    => $problems,
            'response'    => mb_substr($r->rawResponse, 0, 30000),
        ];
    }

    /** Поля журнала и итоги по токенам для сохранения в задачу. */
    public function logFields(): array
    {
        $totals = ['CALLS' => 0, 'INPUT_TOKENS' => 0, 'OUTPUT_TOKENS' => 0, 'CACHED_TOKENS' => 0, 'DURATION_MS' => 0];
        foreach ($this->attemptLog as $entry) {
            if (($entry['kind'] ?? '') === 'watchdog') {
                continue;
            }
            $totals['CALLS']++;
            $totals['INPUT_TOKENS'] += (int)($entry['in'] ?? 0);
            $totals['OUTPUT_TOKENS'] += (int)($entry['out'] ?? 0);
            $totals['CACHED_TOKENS'] += (int)($entry['cached'] ?? 0);
            $totals['DURATION_MS'] += (int)($entry['duration_ms'] ?? 0);
        }
        $last = end($this->attemptLog);
        if (is_array($last) && ($last['model'] ?? '') !== '') {
            $totals['MODEL'] = (string)$last['model'];
        }
        return ['ATTEMPT_LOG' => Json::encode($this->attemptLog)] + $totals;
    }

    /** Первая сборка промта из сценария и входов кубика. */
    private static function build(array $task): array
    {
        $scenario = Scenario::load((int)$task['SCENARIO_ID']);
        $input = RunInput::fromArray((array)(json_decode((string)$task['INPUT'], true) ?: []));
        $built = PromptBuilder::build($scenario, $input);

        $sources = [];
        foreach ($scenario->sources as $def) {
            if (isset($built->sources[$def->id])) {
                $res = $built->sources[$def->id];
                $sources[] = ['id' => $def->id, 'title' => $def->title, 'rows' => $res->rows, 'chars' => mb_strlen($res->text)];
            }
        }

        $data = [
            'prompt_hash'   => PromptStore::save($built->systemText),
            'user_text'     => $built->userText,
            'fields'        => $scenario->schema->fields(),
            'schema_name'   => $built->request->schemaName,
            'model'         => (string)($built->request->model ?? ''),
            'max_tokens'    => (int)($built->request->maxTokens ?? 0),
            'connection_id' => $scenario->connectionId,
            'ref_sets'      => array_map(static fn(array $set) => array_keys($set), $built->refSets()),
            'sources'       => $sources,
            'warnings'      => $built->warnings,
            'attachments'   => $built->attachments,
            'chars'         => $built->chars(),
        ];

        TaskRepository::update((int)$task['ID'], [
            'BUILT'         => Json::encode($data),
            'PROMPT_HASH'   => $data['prompt_hash'],
            'CONNECTION_ID' => $scenario->connectionId,
        ]);
        return $data;
    }
}
