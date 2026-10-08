<?php
namespace Local\AiLab\Queue;

/** Задачу нельзя поставить в очередь (сценарий выключен, суточный лимит). Текст — для человека. */
final class QueueException extends \RuntimeException
{
}
