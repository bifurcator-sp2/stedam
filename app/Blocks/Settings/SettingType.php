<?php

namespace App\Blocks\Settings;

enum SettingType: string
{
case String = 'string';
case Int = 'int';
case Float = 'float';
case Bool = 'bool';
case Select = 'select';
case MultiSelect = 'multiselect';
case Object = 'object';
case Array = 'array';
case Json = 'json';
case Color = 'color';

    public function tsType(): string
{
    return match ($this) {
        self::String, self::Select => 'string',
        self::Int, self::Float => 'number',
        self::Bool => 'boolean',
        self::MultiSelect => 'string[]',
        self::Object => 'object',
        self::Array => 'unknown[]',
        self::Json => 'unknown',
    };
}
}
