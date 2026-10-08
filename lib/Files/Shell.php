<?php
namespace Local\AiLab\Files;

/** Запуск внешней программы без shell: аргументы массивом, с таймаутом через coreutils timeout. */
final class Shell
{
    /**
     * @param string[] $command
     * @return array{code: int, stdout: string, stderr: string}
     */
    public static function run(array $command, int $timeoutSec = 60, array $env = []): array
    {
        if (!function_exists('proc_open')) {
            throw new FileException('В PHP отключена функция proc_open — файлы Office и PDF не обработать. Включите её в php.ini (disable_functions).');
        }
        $program = basename((string)($command[0] ?? 'программу'));
        if (is_file('/usr/bin/timeout')) {
            array_unshift($command, '/usr/bin/timeout', (string)max(1, $timeoutSec));
        }
        $env = $env + ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'LANG' => 'C.UTF-8'];

        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($process)) {
            throw new FileException('Не удалось запустить ' . $program);
        }
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return ['code' => (int)$code, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
