<?php
/**
 * Кубик «AI Lab: запрос к ИИ».
 *
 * Ставит задачу в очередь AI Lab и ждёт, как «Пауза»: процесс засыпает до ответа.
 * Будит его обработчик очереди (CBPRuntime::sendExternalEvent) или страховочный таймер
 * на «срок ожидания + 2 минуты». В обоих случаях итог берётся из журнала задач,
 * поэтому разминувшееся событие не теряет результат.
 *
 * Результаты: status, error_text, log_id + поля ответа сценария (ADDITIONAL_RESULT).
 * При любом сбое кубик не падает: status ≠ success, поля ответа пустые, процесс идёт дальше.
 */
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Loader;
use Local\AiLab\Bp\BpBridge;
use Local\AiLab\Bp\DocumentMap;
use Local\AiLab\Bp\Timeline;
use Local\AiLab\Data\EntityCatalog;
use Local\AiLab\Files\FileExtractor;
use Local\AiLab\Scenario\ElementInputs;
use Local\AiLab\Data\FieldMeta;
use Local\AiLab\Data\ValueFormatter;
use Local\AiLab\Model\ScenarioTable;
use Local\AiLab\Queue\Queue;
use Local\AiLab\Queue\TaskRepository;
use Local\AiLab\Queue\TaskStatus;
use Local\AiLab\Scenario\RunInput;
use Local\AiLab\Scenario\Scenario;

Loader::includeModule('local.ailab');

class CBPAilabRequestActivity extends CBPActivity implements IBPEventActivity, IBPActivityExternalEventListener
{
    public const DEFAULT_TIMEOUT_MIN = 10;
    /** Страховочный таймер срабатывает позже срока задачи: обработчик успевает сам вернуть timeout. */
    private const TIMER_GRACE = 120;
    public const BLANK_INPUT_ROWS = 3;

    protected $taskId = 0;
    protected $subscriptionId = 0;
    protected $isInEventActivityMode = false;

    public function __construct($name)
    {
        parent::__construct($name);
        $this->arProperties = [
            'Title'           => '',
            'ScenarioCode'    => '',
            'DocumentFields'  => [],
            'Inputs'          => [],
            'Params'          => [],
            'Snippet'         => '',
            'TimeoutMinutes'  => self::DEFAULT_TIMEOUT_MIN,
            'TimelineComment' => 'N',
            'FileMode'        => FileExtractor::MODE_NAME,
            'ResultFields'    => [],
            'status'          => '',
            'error_text'      => '',
            'log_id'          => 0,
        ];
        $this->setPropertiesTypes([
            'status'     => ['Type' => 'string'],
            'error_text' => ['Type' => 'string'],
            'log_id'     => ['Type' => 'int'],
        ]);
    }

    /* ========================= Выполнение ========================= */

    public function execute()
    {
        if ($this->isInEventActivityMode) {
            return CBPActivityExecutionStatus::Closed;
        }
        $this->resetResults();

        if (!Loader::includeModule('local.ailab')) {
            $this->finishWith('error', 'Модуль local.ailab не установлен');
            return CBPActivityExecutionStatus::Closed;
        }

        try {
            $scenario = Scenario::loadByCode((string)$this->getRawProperty('ScenarioCode'));
            $this->taskId = Queue::enqueue($scenario, $this->buildInput(), [
                'origin'      => Queue::ORIGIN_BP,
                'timeout'     => $this->timeoutSeconds(),
                'workflow_id' => $this->getWorkflowInstanceId(),
                'activity'    => $this->name,
                'document_id' => DocumentMap::toString((array)$this->getDocumentId()),
                'user_id'     => $this->startedBy(),
            ]);
        } catch (\Throwable $e) {
            $this->finishWith('error', $e->getMessage());
            return CBPActivityExecutionStatus::Closed;
        }

        $this->setResult(['log_id' => (int)$this->taskId]);
        $this->writeToTrackingService(sprintf(
            'AI Lab: запрос №%d поставлен в очередь (сценарий «%s»), ждём ответа до %s.',
            $this->taskId, $scenario->code, date('d.m.Y H:i', time() + $this->timeoutSeconds())
        ));

        $this->Subscribe($this);
        $this->isInEventActivityMode = false;

        return CBPActivityExecutionStatus::Executing;
    }

    public function Subscribe(IBPActivityExternalEventListener $eventHandler)
    {
        $this->isInEventActivityMode = true;

        $schedulerService = $this->workflow->getService('SchedulerService');
        $this->subscriptionId = (int)$schedulerService->subscribeOnTime(
            $this->workflow->getInstanceId(),
            $this->name,
            time() + $this->timeoutSeconds() + self::TIMER_GRACE
        );
        $this->workflow->addEventHandler($this->name, $eventHandler);
    }

    public function Unsubscribe(IBPActivityExternalEventListener $eventHandler)
    {
        if ($this->subscriptionId > 0) {
            $schedulerService = $this->workflow->getService('SchedulerService');
            $schedulerService->unSubscribeOnTime($this->subscriptionId);
            $this->subscriptionId = 0;
        }
        $this->workflow->removeEventHandler($this->name, $eventHandler);
    }

    public function OnExternalEvent($eventParameters = [])
    {
        if ($this->executionStatus == CBPActivityExecutionStatus::Closed) {
            return;
        }
        Loader::includeModule('local.ailab');

        $isTimer = (($eventParameters['SchedulerService'] ?? null) === 'OnAgent');
        $task = $this->taskId > 0 ? TaskRepository::get((int)$this->taskId) : null;
        $ready = $task !== null && TaskStatus::isFinal((string)$task['STATUS']);

        if (!$ready && !$isTimer && $task !== null) {
            return; // ответ ещё не готов — ждём дальше
        }

        // Отписываемся до любых действий с задачей: отмена ниже не должна будить нас повторно.
        $this->Unsubscribe($this);

        if ($ready) {
            $this->applyTask($task);
        } else {
            if ($task !== null) {
                Queue::cancel((int)$this->taskId, 'Бизнес-процесс перестал ждать: истекло время ожидания', false);
            }
            $this->finishWith('timeout', sprintf(
                'Ответ не получен за %d мин. Проверьте обработчик очереди: AI Lab → Журнал, задача №%d.',
                (int)ceil($this->timeoutSeconds() / 60), (int)$this->taskId
            ));
        }

        $this->workflow->closeActivity($this);
    }

    public function cancel()
    {
        if (!$this->isInEventActivityMode) {
            $this->Unsubscribe($this);
            if ($this->taskId > 0 && Loader::includeModule('local.ailab')) {
                Queue::cancel((int)$this->taskId, 'Бизнес-процесс остановлен', false);
            }
        }
        return CBPActivityExecutionStatus::Closed;
    }

    protected function reInitialize()
    {
        parent::reInitialize();
        $this->resetResults();
        $this->taskId = 0;
        $this->subscriptionId = 0;
    }

    /* ========================= Входы и результат ========================= */

    private function buildInput(): RunInput
    {
        $input = new RunInput();
        $input->snippet = self::stringify($this->parseValue((string)$this->getRawProperty('Snippet')));

        $codes = array_values(array_filter((array)$this->getRawProperty('DocumentFields'), 'is_string'));
        if ($codes) {
            $entity = DocumentMap::entity((array)$this->getDocumentId());
            if ($entity === null) {
                throw new \RuntimeException('Поля документа можно передавать только из CRM, смарт-процессов и списков');
            }
            ElementInputs::add($input, $entity[0], $entity[1], $codes, $this->fileMode());
        }

        foreach ((array)$this->getRawProperty('Inputs') as $row) {
            if (!is_array($row)) {
                continue;
            }
            [$label, $expression] = self::normalizeInput((string)($row['label'] ?? ''), (string)($row['value'] ?? ''));
            if ($label === '' && trim($expression) === '') {
                continue;
            }
            if ($label === '') {
                $label = $this->expressionTitle($expression);
            }
            $input->addInput($label, self::stringify($this->parseValue($expression)), '', trim((string)($row['description'] ?? '')));
        }

        foreach ((array)$this->getRawProperty('Params') as $name => $expression) {
            $input->params[(string)$name] = self::stringify($this->parseValue($expression));
        }
        return $input;
    }

    private function applyTask(array $task): void
    {
        $status = (string)$task['STATUS'];
        $bpStatus = match ($status) {
            TaskStatus::SUCCESS => 'success',
            TaskStatus::INVALID => 'invalid',
            TaskStatus::TIMEOUT => 'timeout',
            default             => 'error',
        };
        $error = (string)$task['ERROR_TEXT'];
        if ($status === TaskStatus::CANCELLED && $error === '') {
            $error = 'Задача отменена';
        }

        if ($bpStatus === 'success') {
            $this->setResult(BpBridge::resultValues($task));
            $this->setPropertiesTypes(BpBridge::resultTypes($task));
            $this->setResult(['status' => 'success', 'error_text' => '']);
            $this->writeToTrackingService(sprintf(
                'AI Lab: ответ по запросу №%d получен за %s с, токены %d / %d. %s',
                (int)$task['ID'],
                number_format((int)$task['DURATION_MS'] / 1000, 1, ',', ' '),
                (int)$task['INPUT_TOKENS'],
                (int)$task['OUTPUT_TOKENS'],
                BpBridge::summary($task)
            ));
        } else {
            $this->finishWith($bpStatus, $error !== '' ? $error : TaskStatus::label($status));
        }

        if ($this->getRawProperty('TimelineComment') === 'Y') {
            $this->timelineComment($task, $bpStatus);
        }
    }

    private function timelineComment(array $task, string $bpStatus): void
    {
        $text = sprintf('AI Lab, сценарий «%s»: ', (string)$task['SCENARIO_CODE'])
            . ($bpStatus === 'success' ? BpBridge::summary($task, 1500) : TaskStatus::label((string)$task['STATUS']) . '. ' . (string)$task['ERROR_TEXT']);
        try {
            Timeline::comment((array)$this->getDocumentId(), $text, $this->startedBy() ?: (int)$this->getTemplateUserId());
        } catch (\Throwable $e) {
            $this->writeToTrackingService('AI Lab: комментарий в таймлайн не добавлен: ' . $e->getMessage(), 0, CBPTrackingType::Error);
        }
    }

    private function finishWith(string $status, string $error): void
    {
        $this->setResult(['status' => $status, 'error_text' => $error]);
        $this->writeToTrackingService(
            sprintf('AI Lab: %s%s. %s', $status, $this->taskId > 0 ? ' по запросу №' . (int)$this->taskId : '', $error),
            0,
            CBPTrackingType::Error
        );
    }

    private function resetResults(): void
    {
        $empty = ['status' => '', 'error_text' => '', 'log_id' => 0];
        foreach (array_keys((array)$this->getRawProperty('ResultFields')) as $code) {
            $empty[(string)$code] = null;
        }
        $this->setResult($empty);
    }

    /** Результаты пишутся только через setProperties: никакой магии __set и пересечений со свойствами класса. */
    private function setResult(array $values): void
    {
        $this->setProperties($values);
    }

    private function fileMode(): string
    {
        $mode = (string)$this->getRawProperty('FileMode');
        return isset(FileExtractor::MODES[$mode]) ? $mode : FileExtractor::MODE_NAME;
    }

    private function timeoutSeconds(): int
    {
        $minutes = (int)$this->getRawProperty('TimeoutMinutes');
        return max(1, min(1440, $minutes > 0 ? $minutes : self::DEFAULT_TIMEOUT_MIN)) * 60;
    }

    private function startedBy(): int
    {
        try {
            $user = $this->getRootActivity()->__get('TargetUser');
            $user = is_array($user) ? reset($user) : $user;
            return preg_match('/(\d+)/', (string)$user, $m) ? (int)$m[1] : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Выражение БП целиком: {=Variable:X}, {=A123:field}, {{Имя}}. */
    public static function isExpressionText(string $text): bool
    {
        $text = trim($text);
        return (bool)preg_match('/^\{=\s*[a-z0-9_]+\s*:\s*[a-z0-9_.]+(\s*>\s*[a-z0-9_:]+(\s*,\s*[a-z0-9_]+)?)?\s*\}$/i', $text)
            || (bool)preg_match('/^\{\{.+\}\}$/s', $text);
    }

    /**
     * Название — обычный текст. Если выражение по ошибке попало в название, а значение пустое,
     * переносим его в значение. @return array{0: string, 1: string} [название, выражение значения]
     */
    public static function normalizeInput(string $label, string $value): array
    {
        $label = trim($label);
        if (trim($value) === '' && self::isExpressionText($label)) {
            return ['', $label];
        }
        return [$label, $value];
    }

    /** Код поля из выражения: {=Variable:EXPENSE_ID} → EXPENSE_ID, {{Статья}} → Статья. */
    public static function expressionCode(string $expression): ?string
    {
        $expression = trim($expression);
        if (preg_match('/^\{=\s*([a-z0-9_]+)\s*:\s*([a-z0-9_.]+)/i', $expression, $m)) {
            return $m[2];
        }
        if (preg_match('/^\{\{(.+)\}\}$/s', $expression, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /** Человеческое имя переменной процесса, если выражение ссылается на неё. */
    private function expressionTitle(string $expression): string
    {
        $code = self::expressionCode($expression);
        if ($code === null) {
            return 'Значение';
        }
        if (preg_match('/^\{=\s*Variable\s*:/i', trim($expression))) {
            try {
                $type = $this->getVariableType($code);
                if (is_array($type) && trim((string)($type['Name'] ?? '')) !== '') {
                    return trim((string)$type['Name']);
                }
            } catch (\Throwable) {
            }
        }
        return $code;
    }

    private static function stringify(mixed $value): string
    {
        if (is_array($value)) {
            return implode('; ', array_filter(array_map([self::class, 'stringify'], $value), static fn($v) => $v !== ''));
        }
        if ($value instanceof \Bitrix\Main\Type\DateTime) {
            return $value->format('d.m.Y H:i');
        }
        if ($value instanceof \Bitrix\Main\Type\Date) {
            return $value->format('d.m.Y');
        }
        if (is_bool($value)) {
            return $value ? 'да' : 'нет';
        }
        $s = (string)$value;
        if (preg_match('/^user_(\d+)$/', $s, $m)) {
            return ValueFormatter::format(new FieldMeta('U', 'U', 'user'), (int)$m[1]);
        }
        return $s;
    }

    /* ========================= Настройка в дизайнере ========================= */

    public static function ValidateProperties($arTestProperties = [], CBPWorkflowTemplateUser $user = null)
    {
        $errors = [];
        if (trim((string)($arTestProperties['ScenarioCode'] ?? '')) === '') {
            $errors[] = ['code' => 'NotExist', 'parameter' => 'ScenarioCode', 'message' => 'Выберите сценарий AI Lab'];
        }
        return array_merge($errors, parent::ValidateProperties($arTestProperties, $user));
    }

    public static function GetPropertiesDialog(
        $documentType,
        $activityName,
        $arWorkflowTemplate,
        $arWorkflowParameters,
        $arWorkflowVariables,
        $arCurrentValues = null,
        $formName = '',
        $popupWindow = null,
        $siteId = ''
    ) {
        if (!Loader::includeModule('local.ailab')) {
            return '<tr><td colspan="2">Модуль local.ailab не установлен.</td></tr>';
        }

        if (is_array($arCurrentValues)) {
            $ignored = [];
            $props = self::propertiesFromRequest((array)$documentType, $arCurrentValues, $ignored);
        } else {
            $activity = CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);
            $props = is_array($activity['Properties'] ?? null) ? $activity['Properties'] : [];
        }

        $dialog = new \Bitrix\Bizproc\Activity\PropertiesDialog(__FILE__, [
            'documentType'       => $documentType,
            'activityName'       => $activityName,
            'workflowTemplate'   => $arWorkflowTemplate,
            'workflowParameters' => $arWorkflowParameters,
            'workflowVariables'  => $arWorkflowVariables,
            'currentValues'      => [],
            'formName'           => $formName,
            'siteId'             => $siteId,
        ]);
        $dialog->setRuntimeData(['ailab' => self::dialogData((array)$documentType, $props)]);

        return $dialog;
    }

    public static function GetPropertiesDialogValues(
        $documentType,
        $activityName,
        &$arWorkflowTemplate,
        &$arWorkflowParameters,
        &$arWorkflowVariables,
        $arCurrentValues,
        &$errors
    ) {
        $errors = [];
        if (!Loader::includeModule('local.ailab')) {
            $errors[] = ['code' => 'NotExist', 'parameter' => 'ScenarioCode', 'message' => 'Модуль local.ailab не установлен'];
            return false;
        }

        $properties = self::propertiesFromRequest(
            (array)$documentType,
            (array)$arCurrentValues,
            $errors,
            is_array($arWorkflowVariables) ? $arWorkflowVariables : [],
            is_array($arWorkflowParameters) ? $arWorkflowParameters : []
        );
        if ($errors) {
            return false;
        }
        $errors = self::ValidateProperties($properties, new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser));
        if ($errors) {
            return false;
        }

        $currentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);
        $currentActivity['Properties'] = $properties;

        return true;
    }

    /** Свойства кубика из полей окна настроек. */
    public static function propertiesFromRequest(
        array $documentType,
        array $request,
        array &$errors,
        array $variables = [],
        array $parameters = []
    ): array
    {
        $code = trim((string)($request['ailab_scenario'] ?? ''));
        $scenario = null;
        if ($code !== '') {
            $row = ScenarioTable::getList(['filter' => ['=CODE' => $code], 'limit' => 1])->fetch();
            $scenario = $row ? Scenario::fromRow($row) : null;
            if ($scenario === null) {
                $errors[] = ['code' => 'NotExist', 'parameter' => 'ScenarioCode', 'message' => "Сценарий «{$code}» не найден"];
            }
        }

        // Поля документа: сверяем со списком полей, если он доступен; если нет — сохраняем коды как есть,
        // а при выполнении EntityReader проверит их и назовёт неизвестное поле в error_text.
        $requested = array_values(array_filter(
            self::requestedDocumentFields($request),
            static fn($c) => $c !== 'ID' && preg_match('/^[A-Z][A-Z0-9_]*$/', $c)
        ));
        $documentFields = $requested;
        $entityKey = DocumentMap::entityKey($documentType);
        if ($entityKey !== null) {
            try {
                $available = EntityCatalog::fields($entityKey);
                $documentFields = array_values(array_filter($requested, static fn($c) => isset($available[$c])));
            } catch (\Throwable) {
                $documentFields = $requested;
            }
        }

        $inputs = [];
        $count = max(0, min(100, (int)($request['ailab_in_count'] ?? 0)));
        for ($i = 0; $i < $count; $i++) {
            [$label, $value] = self::normalizeInput(
                (string)($request['ailab_in_label_' . $i] ?? ''),
                self::requestValue($request, 'ailab_in_value_' . $i)
            );
            if ($label === '' && trim($value) === '') {
                continue;
            }
            if ($label === '') {
                $label = self::titleFromTemplate($value, $variables, $parameters);
            }
            $inputs[] = [
                'label'       => $label,
                'description' => trim((string)($request['ailab_in_desc_' . $i] ?? '')),
                'value'       => $value,
            ];
        }

        $params = [];
        if ($scenario !== null) {
            foreach ($scenario->params() as $name) {
                $params[$name] = self::requestValue($request, 'ailab_param__' . $scenario->id . '__' . $name);
            }
        }

        $timeout = (int)($request['ailab_timeout'] ?? self::DEFAULT_TIMEOUT_MIN);

        return [
            'ScenarioCode'    => $scenario?->code ?? $code,
            'DocumentFields'  => $documentFields,
            'Inputs'          => $inputs,
            'Params'          => $params,
            'Snippet'         => self::requestValue($request, 'ailab_snippet'),
            'TimeoutMinutes'  => $timeout >= 1 && $timeout <= 1440 ? $timeout : self::DEFAULT_TIMEOUT_MIN,
            'TimelineComment' => ($request['ailab_timeline'] ?? '') === 'Y' ? 'Y' : 'N',
            'FileMode'        => isset(FileExtractor::MODES[$request['ailab_file_mode'] ?? '']) ? (string)$request['ailab_file_mode'] : FileExtractor::MODE_NAME,
            'ResultFields'    => $scenario !== null ? BpBridge::resultFields($scenario) : [],
        ];
    }

    /** Данные для окна настроек. */
    public static function dialogData(array $documentType, array $props): array
    {
        $scenarios = [];
        foreach (ScenarioTable::getList(['order' => ['NAME' => 'ASC']])->fetchAll() as $row) {
            try {
                $s = Scenario::fromRow($row);
                $scenarios[] = [
                    'id'     => $s->id,
                    'code'   => $s->code,
                    'name'   => $s->name,
                    'active' => $s->active,
                    'params' => $s->params(),
                    'fields' => array_keys(BpBridge::resultFields($s)),
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        $documentFields = [];
        $entityKey = DocumentMap::entityKey($documentType);
        if ($entityKey !== null) {
            try {
                foreach (EntityCatalog::fields($entityKey) as $code => $meta) {
                    if ($code !== 'ID') {
                        $documentFields[$code] = $meta->title . ' · ' . $meta->label();
                    }
                }
            } catch (\Throwable) {
                $documentFields = [];
            }
        }

        return [
            'props' => $props + [
                'ScenarioCode' => '', 'DocumentFields' => [], 'Inputs' => [], 'Params' => [],
                'Snippet' => '', 'TimeoutMinutes' => self::DEFAULT_TIMEOUT_MIN, 'TimelineComment' => 'N',
                'FileMode' => FileExtractor::MODE_NAME,
            ],
            'fileModes'      => FileExtractor::MODES,
            'scenarios'      => $scenarios,
            'documentFields' => $documentFields,
            'isCrm'          => $entityKey !== null && str_starts_with($entityKey, 'crm:'),
            'blankRows'      => self::BLANK_INPUT_ROWS,
            'adminUrl'       => '/bitrix/admin/ailab_scenarios.php?lang=' . (defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru'),
        ];
    }

    /**
     * Выбранные поля документа. Галочки дублируются в скрытое поле ailab_doc_fields_list (через запятую):
     * так значение доходит до сохранения тем же путём, что и обычные текстовые поля.
     * @return string[]
     */
    public static function requestedDocumentFields(array $request): array
    {
        $codes = [];
        if (array_key_exists('ailab_doc_fields_list', $request)) {
            $codes = explode(',', (string)$request['ailab_doc_fields_list']);
        } else {
            foreach ((array)($request['ailab_doc_fields'] ?? []) as $value) {
                $codes = array_merge($codes, explode(',', (string)$value));
            }
        }
        return array_values(array_unique(array_filter(array_map('trim', $codes), static fn($c) => $c !== '')));
    }

    /** Название входа по выражению: имя переменной или параметра шаблона, иначе код. */
    private static function titleFromTemplate(string $expression, array $variables, array $parameters): string
    {
        $code = self::expressionCode($expression);
        if ($code === null) {
            return 'Значение';
        }
        if (preg_match('/^\{=\s*(Variable|Template|Parameter)\s*:/i', trim($expression), $m)) {
            $pool = strcasecmp($m[1], 'Variable') === 0 ? $variables : $parameters;
            if (trim((string)($pool[$code]['Name'] ?? '')) !== '') {
                return trim((string)$pool[$code]['Name']);
            }
        }
        return $code;
    }

    /** Значение поля, нарисованного CBPDocument::ShowParameterField (у некоторых типов выражение лежит в *_text). */
    private static function requestValue(array $request, string $name): string
    {
        $value = $request[$name] ?? '';
        if (is_array($value)) {
            $value = implode(', ', $value);
        }
        $value = (string)$value;
        if (trim($value) === '' && isset($request[$name . '_text'])) {
            $value = (string)$request[$name . '_text'];
        }
        return $value;
    }
}
