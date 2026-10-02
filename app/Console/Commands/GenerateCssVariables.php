<?php

namespace App\Console\Commands;

use App\Blocks\Settings\CssVariablesGenerator;
use Illuminate\Console\Command;

class GenerateCssVariables extends Command
{
    protected $signature = 'blocks:css-vars {--path=public/_tokens.css}';
    protected $description = 'Генерирует CSS-переменные из PHP-палитры';

    public function handle(): int
    {
        $path = $this->option('path')??'public/_tokens.css';
        $path = base_path($path);
        CssVariablesGenerator::write($path);

        $this->info("CSS-переменные записаны в {$path}");
        return self::SUCCESS;
    }
}
