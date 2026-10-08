<?php
namespace Local\AiLab;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\UserGroupTable;

/**
 * Уведомления администраторам портала (группа 1) в мессенджер.
 * У каждого повода своя пауза: одна и та же беда не пишет чаще раза в час.
 */
final class Notifier
{
    public static function admins(string $key, string $message, int $cooldownSec = 3600): bool
    {
        if (!Settings::flag('notify_enabled')) {
            return false;
        }
        $option = 'notify_last_' . substr(md5($key), 0, 16);
        if ((int)Option::get(Settings::MODULE_ID, $option, '0') > time() - $cooldownSec) {
            return false;
        }
        Option::set(Settings::MODULE_ID, $option, (string)time());

        if (!Loader::includeModule('im')) {
            return false;
        }
        $sent = 0;
        foreach (self::adminIds() as $userId) {
            $id = \CIMNotify::Add([
                'TO_USER_ID'     => $userId,
                'FROM_USER_ID'   => 0,
                'NOTIFY_TYPE'    => defined('IM_NOTIFY_SYSTEM') ? IM_NOTIFY_SYSTEM : 4,
                'NOTIFY_MODULE'  => Settings::MODULE_ID,
                'NOTIFY_EVENT'   => 'alert',
                'NOTIFY_TAG'     => 'LOCAL_AILAB|' . $key,
                'NOTIFY_MESSAGE' => 'AI Lab: ' . $message . ' [URL=/bitrix/admin/ailab_tasks.php?lang=ru]Журнал AI Lab[/URL]',
            ]);
            $sent += $id ? 1 : 0;
        }
        return $sent > 0;
    }

    /** @return int[] активные администраторы, не больше 20 */
    private static function adminIds(): array
    {
        $rows = UserGroupTable::getList([
            'select' => ['USER_ID'],
            'filter' => ['=GROUP_ID' => 1, '=USER.ACTIVE' => 'Y'],
            'limit'  => 20,
        ])->fetchAll();
        return array_values(array_unique(array_map(static fn($r) => (int)$r['USER_ID'], $rows)));
    }
}
