<?php
namespace Local\AiLab\Scenario;

use Local\AiLab\Ai\AiClient;
use Local\AiLab\Ai\AiRequest;
use Local\AiLab\Ai\AiResponse;
use Local\AiLab\Ai\Connection;
use Local\AiLab\Json;

/**
 * Синхронный прогон сценария: собрать промт → отправить → проверить по схеме.
 * Если ответ не прошёл проверку, делается одна повторная попытка с перечнем ошибок.
 * Сетевые сбои здесь не повторяются: повторы с паузой — работа очереди (этап 3).
 */
final class Runner
{
    public static function run(Scenario $scenario, RunInput $input, ?Connection $connection = null): RunResult
    {
        $result = new RunResult();
        $result->built = PromptBuilder::build($scenario, $input);
        $connection ??= Connection::load($scenario->connectionId);

        $request = $result->built->request;
        $problems = [];

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $response = AiClient::send($connection, $request);
            $result->attempts[] = $response;

            if ($response->status === AiResponse::STATUS_ERROR) {
                $result->status = RunResult::STATUS_ERROR;
                $result->error = $response->error;
                return $result;
            }

            if ($response->status === AiResponse::STATUS_INVALID) {
                $problems = [$response->error];
            } elseif ($scenario->schema->isEmpty()) {
                $result->status = RunResult::STATUS_SUCCESS;
                $result->text = $response->text;
                return $result;
            } else {
                $validation = $scenario->schema->validate($response->data ?? [], $result->built->refSets());
                $result->validation = $validation;
                if ($validation->ok) {
                    $result->status = RunResult::STATUS_SUCCESS;
                    $result->data = $validation->data;
                    return $result;
                }
                $problems = $validation->errors;
            }

            if ($attempt === 1) {
                $request = self::correctionRequest($request, $response, $problems);
            }
        }

        $result->status = RunResult::STATUS_INVALID;
        $result->error = implode('; ', $problems);
        return $result;
    }

    /** Повторный запрос с перечнем ошибок проверки. Используется и очередью. */
    public static function correctionRequest(AiRequest $request, AiResponse $previous, array $problems): AiRequest
    {
        $retry = clone $request;
        $text = "Твой предыдущий ответ не прошёл проверку:\n- " . implode("\n- ", $problems);
        if ($previous->data !== null) {
            $text .= "\nПредыдущий ответ: " . Json::encode($previous->data);
        }
        $text .= "\nОтветь заново строго по формату ответа.";
        $retry->userParts[] = AiRequest::textPart($text);
        return $retry;
    }
}
