<?php
namespace Local\AiLab\Scenario;

use Local\AiLab\Ai\AiRequest;
use Local\AiLab\Files\FileException;
use Local\AiLab\Files\FileExtractor;
use Local\AiLab\Settings;
use Local\AiLab\Scenario\Source\SourceFactory;

/**
 * Порядок промта:
 *   system (одинаков для всех заявок, кэшируется у провайдера):
 *     1. инструкция сценария  2. данные источников  3. формат ответа
 *   user (своё у каждой заявки):
 *     4. кусок промта из кубика  5. входные данные с типами и пояснениями
 */
final class PromptBuilder
{
    public static function build(Scenario $scenario, RunInput $input): BuiltPrompt
    {
        $built = new BuiltPrompt();

        $sections = [];
        $titles = [];
        foreach ($scenario->sources as $def) {
            if (!$def->active) {
                continue;
            }
            try {
                $result = SourceFactory::make($def->type)->run($def, $input->params);
            } catch (SourceException $e) {
                throw new SourceException('Источник «' . $def->title . '»: ' . $e->getMessage(), 0, $e);
            }
            $built->sources[$def->id] = $result;
            $titles[$def->id] = $def->title;
            foreach ($result->warnings as $warning) {
                $built->warnings[] = $def->title . ': ' . $warning;
            }

            $section = '## ' . $def->title;
            if (trim($def->description) !== '') {
                $section .= "\n" . trim($def->description);
            }
            $sections[] = $section . "\n" . $result->text;
        }

        $system = [];
        if (trim($scenario->instruction) !== '') {
            $system[] = trim($scenario->instruction);
        }
        if ($sections) {
            $system[] = "# Данные\n\n" . implode("\n\n", $sections);
        }
        if (!$scenario->schema->isEmpty()) {
            $system[] = "# Формат ответа\n\n" . $scenario->schema->formatText($titles);
        }
        $built->systemText = implode("\n\n", $system);

        $user = [];
        if (trim($input->snippet) !== '') {
            $user[] = trim($input->snippet);
        }
        $inputs = self::renderInputs($input->inputs);
        if ($inputs !== '') {
            $user[] = "# Входные данные\n\n" . $inputs;
        }
        [$fileText, $nativeParts] = self::files($input, $built);
        if ($fileText !== '') {
            $user[] = $fileText;
        }
        $built->userText = $user ? implode("\n\n", $user) : 'Выполни задачу по инструкции.';

        if ($built->chars() > $scenario->maxPromptChars) {
            throw new SourceException(sprintf(
                'Промт получился %s символов при лимите сценария %s. Сузьте источники или поднимите лимит в настройках сценария.',
                number_format($built->chars(), 0, ',', ' '),
                number_format($scenario->maxPromptChars, 0, ',', ' ')
            ));
        }

        $request = new AiRequest();
        $request->system = $built->systemText;
        $request->userParts = array_merge([AiRequest::textPart($built->userText)], $nativeParts);
        if (!$scenario->schema->isEmpty()) {
            $request->schema = $scenario->schema->jsonSchema();
            $request->schemaName = substr('scenario_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $scenario->code), 0, 64);
        }
        if ($scenario->model !== '') {
            $request->model = $scenario->model;
        }
        if ($scenario->maxTokens > 0) {
            $request->maxTokens = $scenario->maxTokens;
        }
        $built->request = $request;

        return $built;
    }

    /**
     * Файлы: текст — в раздел «# Файлы», PDF и картинки — вложениями после текста.
     * @return array{0: string, 1: list<array>}
     */
    private static function files(RunInput $input, BuiltPrompt $built): array
    {
        $files = array_values(array_filter($input->files, static fn($f) => ($f['mode'] ?? '') !== FileExtractor::MODE_NAME));
        if (!$files) {
            return ['', []];
        }
        $max = Settings::int('files_max_count', 1, 50);
        if (count($files) > $max) {
            throw new SourceException(sprintf('Файлов %d — больше лимита %d на запрос (AI Lab → Настройки)', count($files), $max));
        }

        $blocks = [];
        $parts = [];
        foreach ($files as $file) {
            try {
                $attachment = FileExtractor::prepare((int)$file['file_id'], (string)$file['mode']);
            } catch (FileException $e) {
                throw new SourceException($e->getMessage(), 0, $e);
            }
            $built->attachments[] = ['label' => (string)$file['label']] + $attachment->meta();
            $head = '## ' . (trim((string)$file['label']) !== '' ? trim((string)$file['label']) . ': ' : '') . $attachment->name;
            if ($attachment->isNative()) {
                $parts[] = FileExtractor::part($attachment);
                $blocks[] = $head . "\n" . sprintf('Приложен к сообщению целиком (вложение %d, %s).', count($parts), $attachment->kind === 'pdf' ? 'PDF' : 'изображение');
            } else {
                $blocks[] = $head . "\n" . $attachment->text;
            }
            foreach ($attachment->notes as $note) {
                $built->warnings[] = $attachment->name . ': ' . $note;
            }
        }
        return ["# Файлы\n\n" . implode("\n\n", $blocks), $parts];
    }

    /** @param list<array{label: string, type: string, description: string, value: string}> $inputs */
    public static function renderInputs(array $inputs): string
    {
        $blocks = [];
        foreach ($inputs as $in) {
            $label = trim((string)($in['label'] ?? ''));
            $value = trim((string)($in['value'] ?? ''));
            if ($label === '' && $value === '') {
                continue;
            }
            $head = '## ' . ($label !== '' ? $label : 'Значение');
            $meta = [];
            if (trim((string)($in['type'] ?? '')) !== '') {
                $meta[] = 'Тип: ' . trim($in['type']) . '.';
            }
            if (trim((string)($in['description'] ?? '')) !== '') {
                $meta[] = trim($in['description']);
            }
            $block = $head;
            if ($meta) {
                $block .= "\n" . implode(' ', $meta);
            }
            $blocks[] = $block . "\n" . ($value !== '' ? $value : '(пусто)');
        }
        return implode("\n\n", $blocks);
    }
}
