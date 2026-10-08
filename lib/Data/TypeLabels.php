<?php
namespace Local\AiLab\Data;

/** Понятные модели и человеку названия типов полей. */
final class TypeLabels
{
    private const LABELS = [
        'string'         => 'строка',
        'text'           => 'текст',
        'html'           => 'текст',
        'integer'        => 'целое число',
        'double'         => 'число',
        'number'         => 'число',
        'boolean'        => 'да/нет',
        'date'           => 'дата',
        'datetime'       => 'дата и время',
        'user'           => 'пользователь',
        'employee'       => 'пользователь',
        'enumeration'    => 'вариант из списка',
        'iblock_enum'    => 'вариант из списка',
        'crm_status'     => 'вариант из списка',
        'crm_category'   => 'воронка',
        'crm_currency'   => 'валюта',
        'crm'            => 'привязка к CRM',
        'crm_company'    => 'компания CRM',
        'crm_contact'    => 'контакт CRM',
        'iblock_element' => 'элемент списка',
        'hlblock'        => 'элемент HL-блока',
        'iblock_section' => 'раздел',
        'file'           => 'файл',
        'disk_file'      => 'файл',
        'money'          => 'деньги',
        'url'            => 'ссылка',
        'address'        => 'адрес',
    ];

    public static function label(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }
}
