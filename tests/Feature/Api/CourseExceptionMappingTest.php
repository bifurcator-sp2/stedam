<?php

declare(strict_types=1);

use App\Domain\Course\Exceptions\InvalidCourseTitle;
use App\Domain\Course\Exceptions\InvalidLocale;
use App\Domain\Course\ValueObjects\CourseTitle;
use App\Domain\Course\ValueObjects\Locale;
use Illuminate\Support\Facades\Route;

/**
 * Маппинг VO-исключений курса в HTTP (bootstrap/app.php).
 *
 * Контроллер валидирует ввод сам, поэтому до VO-исключений в обычном
 * сценарии дело не доходит. Но VO бросают их и в обход контроллера —
 * из Action, CLI и очередей; без маппинга пользователь получил бы 500.
 * Проверяем именно маппинг, а не валидацию запроса.
 */
beforeEach(function () {
    Route::middleware('api')->get('/_test/course-invalid-locale', static function () {
        new Locale('russian');

        return response()->json(['ok' => true]);
    });

    Route::middleware('api')->get('/_test/course-invalid-title', static function () {
        new CourseTitle('   ');

        return response()->json(['ok' => true]);
    });
});

it('VO-исключение locale отдаёт 422 с кодом', function () {
    $this->getJson('/_test/course-invalid-locale')
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_locale')
        ->assertJsonPath('message', fn (string $message): bool => $message !== '');
});

it('VO-исключение названия отдаёт 422 с кодом', function () {
    $this->getJson('/_test/course-invalid-title')
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_course_title');
});

it('доменные исключения наследуют InvalidArgumentException', function () {
    expect(new InvalidLocale('x'))->toBeInstanceOf(InvalidArgumentException::class)
        ->and(new InvalidCourseTitle('x'))->toBeInstanceOf(InvalidArgumentException::class);
});
