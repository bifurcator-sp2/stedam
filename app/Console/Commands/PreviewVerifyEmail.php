<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\VerifyEmailForSpa;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;

class PreviewVerifyEmail extends Command
{
    /**
     * Имя и описание команды.
     *
     * @var string
     */
    protected $signature = 'email:preview-verify {--locale=* : Языки для генерации (по умолчанию ru и en)}';

    /**
     * Описание команды.
     *
     * @var string
     */
    protected $description = 'Рендерит HTML-письмо подтверждения email для каждой локали и сохраняет в storage/app/email-previews';

    public function handle(): int
    {
        // 1. Получаем список локалей из опции или используем значения по умолчанию
        $locales = $this->option('locale') ?: ['ru', 'en'];

        // 2. Находим любого пользователя для подстановки в шаблон (имя, email)
        //    Если пользователей нет, создаем временный объект
        $user = User::first() ?? new User([
                'name'  => 'Test User',
                'email' => 'test@example.com',
            ]);

        // 3. Создаем директорию для сохранения, если её нет
        $outputDir = storage_path('app/email-previews');
        if (!File::exists($outputDir)) {
            File::makeDirectory($outputDir, 0755, true);
        }

        $this->info("Генерация превью письма 'VerifyEmailForSpa'...");

        foreach ($locales as $locale) {
            // 4. Устанавливаем локаль приложения
            App::setLocale($locale);

            // 5. Создаем нотификацию с нужной локалью
            //    (метод ->locale() гарантирует, что письмо будет на нужном языке)
            $notification = (new VerifyEmailForSpa())->locale($locale);

            // 6. Рендерим HTML-содержимое письма
            //    Метод toMail() возвращает объект MailMessage, у которого есть метод render()
            $mailMessage = $notification->toMail($user);
            $html = $mailMessage->render();

            // 7. Формируем имя файла и сохраняем
            $filename = "{$outputDir}/verify-email-{$locale}.html";
            File::put($filename, $html);

            $this->line("  - Локаль '{$locale}' сохранена в: {$filename}");
        }

        $this->info('Готово! Откройте файлы в браузере для просмотра.');

        return self::SUCCESS;
    }
}
