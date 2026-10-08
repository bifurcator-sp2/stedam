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
case Icon = 'icon';


}
