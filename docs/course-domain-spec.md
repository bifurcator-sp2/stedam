# Спецификация домена Courses (Курсы обучения)

> Документ описывает ограниченный контекст **Courses** («Курсы обучения») —
> агрегат `Course`, темы курса (`CourseTheme`), назначение курса ученику
> (`CourseAssignment`) и три подключаемые стратегии (оценивание, пройденность,
> допуск к уроку). Всё ниже является нормативным: код домена и
> application-слоя обязан соответствовать этому тексту. При расхождении
> источника правды и документа — исправлять документ и код вместе.
>
> Смежные документы:
> - `docs/learn-domain-spec.md` — прохождения уроков (`Learn`), источник
>   события «урок оценён»;
> - `docs/lessons/lessons-domain-spec.md` — авторский контент уроков;
> - `docs/lessonsSeed/lessons-seed-spec.md` — сеялки.
>
> **Статус:** разделы помечены `[MVP]` — реализуется первым, `[позже]` — задел
> на будущее.

---

## 0. Карта реализации (для следующей сессии)

Порядок работ строго по шагам.

| # | Шаг | Где |
|---|-----|-----|
| 1 | Справочник типов уроков: миграция + модель + переводы + сид | `database/migrations`, `app/Models`, `database/seeders` |
| 2 | Миграции: `courses`, `course_themes`, `course_theme_lessons`, `course_assignments` | `database/migrations` |
| 3 | Модели Eloquent | `app/Models` |
| 4 | Домен `Course` (сущности, VO, enum, исключения, события) | `app/Domain/Course` |
| 5 | Порты Application | `app/Application/Course/Ports` |
| 6 | DTO / VO листинга / контекст смотрящего / стратегии | `app/Application/Course/{DTOs,Context,Strategies}` |
| 7 | Actions | `app/Application/Course/Actions` |
| 8 | Инфраструктура: репозитории, запросы, слушатели событий | `app/Infrastructure`, `app/Listeners/Course` |
| 9 | Биндинги `AppServiceProvider` + `config/course.php` | `app/Providers`, `config` |
| 10 | HTTP: контроллеры, ресурсы, маршруты | `app/Http`, `routes/api.php` |
| 11 | Тесты | `tests/Feature/Course`, `tests/Unit/Course` |

---

## 1. Назначение и границы контекста

**Courses** — учебная программа: упорядоченный набор уроков, сгруппированный
в темы, плюс факт назначения программы конкретному ученику.

Контекст отвечает за:

- структуру курса: темы, порядок уроков внутри темы, порядок тем;
- назначение/снятие назначения курса ученику и связанное с ним пакетное
  создание прохождений;
- агрегированные показатели «пройденность» и «оценка курса» — через
  подключаемые стратегии;
- допуск ученика к прохождению конкретного урока в рамках курса;
- автоматический шедулинг следующего урока после оценки предыдущего.

Контекст **не знает**:

- о содержании уроков (только `lesson_id`; тип/сложность — через read-порты
  и справочник);
- о прохождениях (`Learn`) — только через порты и события;
- о ролях пользователей (приходят как `CourseViewerContext`);
- о БД, Redis, S3 (доступ только через порты).

### 1.1. Поставщики и потребители

| Что | Откуда/Куда |
|-----|-------------|
| `lesson_id` | уроки создаются в контексте Lessons; курсы ссылаются по id |
| Тип урока | справочник `lesson_types` (см. §10) |
| Оценка урока | `Learn` → статус `graded` → событие `LearnStatusChanged` |
| Расписание урока | `learns.schedule_date` контекста Learn |
| Роли/scope | `ViewerContext` (`App\Application\Learn\Context`) |

---

## 2. Понятия

| Понятие | Тип | Смысл |
|---------|-----|-------|
| `Course` | агрегат (aggregate root) | Курс обучения |
| `CourseId` | VO | id курса (> 0) |
| `CourseTheme` | сущность внутри агрегата | Тема курса — контейнер уроков |
| `CourseThemeId` | VO | id темы (> 0) |
| `CourseLesson` | сущность внутри агрегата | Связь «тема → урок» с `order` и `time_gap` |
| `CourseAssignment` | агрегат | Назначение курса ученику |
| `CourseAssignmentId` | VO | id назначения (> 0) |
| `CourseGrade` | VO | Оценка курса `[0.0, 1.0]` — степень усвоения |
| `CourseCompletion` | VO | Пройденность `[0.0, 1.0]` |
| `NextLessonThreshold` | VO | Порог открытия следующего урока `(0.0, 1.0]` |
| `TimeGap` | VO | Разрыв в часах между уроками `≥ 0` |
| `CourseTitle` | VO | Непустой заголовок (≤ 255) |
| `CourseDescription` | VO | Описание (`''` допустимо, ≤ 5000) |
| `Locale` | VO | Языковая версия (`ru`, `en`, …) |
| `LessonTypeId` | VO | id справочника типов уроков |
| `GradeStrategyInterface` | Application-порт | Стратегия оценивания курса |
| `CompletionStrategyInterface` | Application-порт | Стратегия пройденности |
| `AdmissionStrategyInterface` | Application-порт | Стратегия допуска к уроку |
| `CourseViewerContext` | Application VO | Кто смотрит и что видит/может |
| `CourseListItem` | Application VO | Элемент листинга |
| `CourseAccessRule` | Application VO | Результат допуска с причиной |

---

## 3. Агрегат `Course`

### 3.1. Состояние

| Поле | Тип | Инварианты |
|------|-----|------------|
| `id` | `?CourseId` | `null` до сохранения |
| `userId` | `?UserId` | автор; `null` — **системный** курс (доступен всем) |
| `title` | `CourseTitle` | непустой |
| `description` | `CourseDescription` | может быть пустым |
| `nextLessonThreshold` | `NextLessonThreshold` | `0.0 < t ≤ 1.0`, по умолчанию `1.0` |
| `locale` | `Locale` | одна языковая версия на курс (см. §3.4) |
| `taxonomyId` | `?TaxonomyId` | узел таксономии; `null` — не привязан |
| `themes` | `list<CourseTheme>` | упорядочены по `order`; `order` уникален |
| `complexity` | `?Complexity` | производная: среднее сложностей всех уроков |
| `createdAt` | `DateTimeImmutable` | момент создания |
| `updatedAt` | `DateTimeImmutable` | см. §3.5 |
| `deletedAt` | `?DateTimeImmutable` | soft delete |

### 3.2. Фабрики

- **`Course::create(?UserId, CourseTitle, CourseDescription, Locale, ?TaxonomyId, NextLessonThreshold, DateTimeImmutable $now)`**
  — новый курс с **пустым** списком тем, `complexity = null`.
- **`Course::reconstitute(...)`** — гидратация из БД без порождения событий;
  проверяет: `order` тем уникален и > 0; `order` уроков внутри темы уникален
  и > 0; `locale` непустая.

### 3.3. Операции над шапкой

| Метод | Эффект |
|-------|--------|
| `rename(CourseTitle, now)` | замена заголовка |
| `changeDescription(CourseDescription, now)` | замена описания |
| `changeLocale(Locale, now)` | только если в курсе нет уроков |
| `changeThreshold(NextLessonThreshold, now)` | новый порог открытия следующего урока |
| `moveToTaxonomy(?TaxonomyId, now)` | см. §3.6 — только если нет уроков |

### 3.4. Локаль

Курс **не имеет** явных языковых версий. `locale` — свойство курса: язык
обучения может менять саму программу (пример: иностранные языки), поэтому
одна модель = один язык. Смена локали — операция редактирования, разрешённая
только на «пустом» курсе.

### 3.5. `updatedAt`

`updatedAt` меняется при:

- прямом изменении шапки (`rename`, `changeDescription`, `changeLocale`,
  `changeThreshold`, `moveToTaxonomy`);
- изменении состава тем (добавить/удалить/переупорядочить);
- изменении состава уроков темы и их порядка;
- изменении `time_gap` / типа связи.

Правило формулируется в домене: **любая мутация агрегата выставляет
`updatedAt := now`**.

### 3.6. Перенос по таксономии

`moveToTaxonomy(TaxonomyId|null)` допустим только если в курсе **нет уроков**.
Темы, не содержащие уроков, переносу не мешают. Нарушение —
`CourseTaxonomyChangeDenied`.

Причина: таксономия определяет подбор контента/отчёты; смену узла при
заполненном курсе нельзя провести согласованно (уроки уже привязаны к другой
ветке).

### 3.7. Complexity курса

`complexity` — **производная** величина: среднее арифметическое сложностей всех
уроков курса (по всем темам, по всем связям).

- `Course::recalculateComplexity(iterable<?float> $lessonComplexities)`.
- `null`-сложности игнорируются (не тянут среднее к нулю).
- Если валидных значений нет — `complexity = null`.
- Пересчёт инициируется Application-слоем после изменений состава уроков
  (`addLesson`, `removeLesson`, `replaceLessons`) через порт чтения сложностей
  (§12.5).

Хранится в БД (`courses.complexity`) для быстрых листингов.

### 3.8. Идентичность

`withId(CourseId)` — копия с присвоенным id; повторный вызов —
`LogicException`. Копия не наследует накопленные события.

---

## 4. Темы (`CourseTheme`) и уроки темы (`CourseLesson`)

### 4.1. `CourseTheme`

| Поле | Тип | Инварианты |
|------|-----|------------|
| `id` | `?CourseThemeId` | `null` до сохранения |
| `title` | `CourseTitle` | непустой |
| `order` | `int` | `≥ 1`, уникален внутри курса |
| `lessons` | `list<CourseLesson>` | упорядочены по `order` |

Фабрика: `CourseTheme::create(CourseTitle $title, int $order)`.

### 4.2. `CourseLesson` (связь)

| Поле | Тип | Инварианты |
|------|-----|------------|
| `lessonId` | `LessonId` | `> 0` |
| `order` | `int` | `≥ 1`, уникален внутри темы |
| `timeGap` | `TimeGap` | `≥ 0` часов, по умолчанию из `config('course.lessons.time_gap', 2)` |
| `typeId` | `?LessonTypeId` | тип урока (справочник §10); `null` — не задан |

Фабрика: `CourseLesson::create(LessonId, int $order, TimeGap, ?LessonTypeId)`.

Связь **не** хранит заголовок и сложность урока — только ссылку и локальные
параметры планирования.

### 4.3. Операции над темами и уроками

| Метод | Эффект |
|-------|--------|
| `addTheme(CourseTitle, ?int $order, now): CourseTheme` | добавить тему; `order = null` → в конец |
| `removeTheme(CourseThemeId, now)` | удалить тему вместе с её уроками-связями |
| `reorderThemes(list<CourseThemeId>, now)` | новый порядок тем |
| `renameTheme(CourseThemeId, CourseTitle, now)` | переименовать тему |
| `addLesson(CourseThemeId, LessonId, ?int $order, ?TimeGap, ?LessonTypeId, now)` | привязать урок к теме |
| `removeLesson(CourseThemeId, LessonId, now)` | отвязать урок |
| `reorderLessons(CourseThemeId, list<LessonId>, now)` | новый порядок уроков в теме |
| `changeTimeGap(CourseThemeId, LessonId, TimeGap, now)` | индивидуальный разрыв |
| `changeLessonType(CourseThemeId, LessonId, ?LessonTypeId, now)` | тип урока в рамках курса |

Правила:

- `order` нормализуется в непрерывный `1..N` (без дыр);
- дубль `order` внутри темы/курса — `InvalidCourseOrder`;
- `removeTheme`/`removeLesson` **не удаляют** сами уроки (`lessons` живут
  независимо) — только связи;
- все мутации выставляют `updatedAt := now` и порождают `CourseChanged`.

### 4.4. Плоский порядок курса

Для допуска к уроку (§8) и шедулинга (§9) используется **плоский** порядок:
все уроки курса, отсортированные по `(theme.order, lesson.order)` со сквозным
`position` от 1.

`Course::flatLessons(): list<FlatLesson>`, где
`FlatLesson{ position, themeId, themeOrder, lessonId, lessonOrder, timeGap, typeId }`.

---

## 5. Состояния курса

У курса **нет** жизненного цикла статусов — есть только soft delete.
Публикации/архивации курса в `[MVP]` нет.

Отдельно выделяется «наличие уроков»:

```
Пустой курс     → можно менять tax_id / locale
Курс с уроками  → tax_id и locale заблокированы
```

`deletedAt != null` исключает курс из всех листингов и действий,
кроме `ForceDeleteCourse`.

---

## 6. Назначение курса (`CourseAssignment`)

### 6.1. Состояние

| Поле | Тип | Инварианты |
|------|-----|------------|
| `id` | `?CourseAssignmentId` | `null` до сохранения |
| `courseId` | `CourseId` | неизменен |
| `studentId` | `UserId` | неизменен |
| `assignedBy` | `UserId` | кто назначил |
| `completion` | `CourseCompletion` | `[0.0, 1.0]`, по умолчанию `0.0` |
| `grade` | `CourseGrade` | `[0.0, 1.0]`, по умолчанию `0.0` |
| `createdAt` | `DateTimeImmutable` | момент назначения |

### 6.2. Инварианты

1. Пара `(courseId, studentId)` уникальна — иначе `CourseAlreadyAssigned`.
2. Назначаемый обязан иметь роль `student` — проверяется в Application
   (`AssignCourseAction`), не в домене.
3. `completion` и `grade` — **производные**, меняются только через стратегии
   (`recalculate(...)`).

### 6.3. Операции

| Метод | Эффект |
|-------|--------|
| `CourseAssignment::assign(CourseId, UserId $studentId, UserId $assignedBy, now)` | новая связь (`completion=0`, `grade=0`) |
| `recalculate(CourseCompletion, CourseGrade, now)` | пересчёт обеих величин |

### 6.4. Пакетное создание прохождений

> Реализация — слушатель доменного события `CourseAssignmentCreated` (§12.2).
> Домен курса про `Learn` не знает.

При создании назначения:

1. обход `Course::flatLessons()` в плоском порядке;
2. для **каждого** урока — `EnrollLessonPort::enroll(..., scheduleDate: null)`
   (реализация — `ScheduleLearnAction` контекста Learn) → прохождение
   `created`;
3. **первый урок** курса сразу `schedule_date = now` → `scheduled`;
4. остальные уроки открываются автоматически по мере оценки предыдущих (§9).

Если у ученика уже есть активное прохождение урока — оно переиспользуется.

### 6.5. Снятие назначения

При удалении назначения (§12.3):

1. перебираются все уроки курса;
2. прохождение удаляется **только если** ученик его ещё не начинал
   (`learns.status ∈ {created, scheduled}` и `started_at IS NULL`);
3. прохождения `in_progress`/`done`/`graded` **не трогаются**;
4. строка назначения удаляется.

---

## 7. Стратегии (Application-порты)

Три независимых порта. Все — чистые вычисления, без БД.

### 7.1. `GradeStrategyInterface` — оценивание

```php
interface GradeStrategyInterface
{
    /** @param list<?float> $lessonGrades оценки прохождений 0..1 (null — не оценён) */
    public function grade(array $lessonGrades): CourseGrade;
}
```

- **Базовая (`AverageGradeStrategy`)**: среднее по **оценённым** урокам;
  `null`-оценки игнорируются. Оценок нет → `0.0`.
- Выбор — `config('course.strategies.grade')`, биндинг в `AppServiceProvider`.

Замечание о масштабе: оценки `Learn\Grade` — `0..100`, `CourseGrade` — `0..1`.
Перевод делает адаптер до вызова стратегии (`GradeNormalizer`); стратегии
работают только в `[0.0, 1.0]`.

### 7.2. `CompletionStrategyInterface` — пройденность

```php
interface CompletionStrategyInterface
{
    /** @param list<?float> $lessonGrades */
    public function completion(array $lessonGrades, int $totalLessons): CourseCompletion;
}
```

- **Базовая (`GradedRatioCompletionStrategy`)**: `count(оценённых) / totalLessons`;
  `totalLessons = 0` → `0.0`.
- **Расширенная (`[позже]` `AggregateCompletionStrategy`)**: среднее из
  агрегатов статистики прохождения уроков. Порт тот же.

### 7.3. `AdmissionStrategyInterface` — допуск к уроку

```php
interface AdmissionStrategyInterface
{
    public function decide(
        float $nextLessonThreshold,     // courses.next_lesson_threshold
        ?float $previousLessonGrade,    // оценка предыдущего урока (0..1); null — первый урок
        ?float $typeThreshold,          // порог типа урока (§8.2); null — не задан
        bool $scheduleReached,          // наступил ли learn.schedule_date
    ): CourseAccessRule;
}
```

- **Базовая (`ThresholdAdmissionStrategy`)**: `previousLessonGrade > threshold`
  **И** `scheduleReached`, где `threshold = max($nextLessonThreshold, $typeThreshold ?? 0.0)`.
  Сравнение строго «больше».
- Для **первого урока** курса `previousLessonGrade = null` → допуск определяется
  только `scheduleReached`.

### 7.4. Синхронный пересчёт

`grade` и `completion` пересчитываются **синхронно** в момент события
«урок оценён» (§12.1), без очередей — расхождение с UI недопустимо.

---

## 8. Допуск к прохождению урока

### 8.1. Правило

Ученик может начать прохождение урока `L` в курсе `C`, если:

1. `L` принадлежит `C` (плоский порядок §4.4);
2. если `L` — **не первый**: оценка прохождения предыдущего урока `> threshold`;
3. `learns.schedule_date` для `L` наступил; «не задан» трактуется как
   «наступил» (для первого урока дата ставится при назначении);
4. курс не удалён soft delete.

`threshold = max(courses.next_lesson_threshold, lesson_types.next_lesson_threshold)`.

### 8.2. Пороги по типу урока

Хранятся в справочнике `lesson_types` (§10), дефолты:

| `code` | Название | `next_lesson_threshold` |
|--------|----------|-------------------------|
| `class_work` | Классная работа | `0.75` |
| `home_work` | Домашняя работа | `0.333` |
| `ind_work` | Самостоятельная работа | `0.50` |
| `control_work` | Контрольная работа | `0.666` |

Значения **редактируемы** (справочник) и живут в БД, не в коде.
Тип конкретного урока в курсе — `course_theme_lessons.type_id` (§10.5).

### 8.3. Результат

```php
final readonly class CourseAccessRule
{
    public function __construct(
        public bool $allowed,
        public string $reason,        // 'ok'|'not_in_course'|'previous_not_passed'|'scheduled_in_future'
        public ?DateTimeImmutable $opensAt = null,
    ) {}
}
```

`reason` — машинный код для фронта; `opensAt` — когда откроется
(если блокирует дата).

### 8.4. Actions

- `GetLessonCanBeLearnedAction(userId, lessonId, courseId): bool` —
  транслирует `CourseAccessRule.allowed` наружу.
- `GetLessonAccessAction(userId, lessonId, courseId): CourseAccessRule` —
  полный результат для UI.

---

## 9. Автоматический шедулинг

> Реализация — слушатель события «урок оценён» (§12.1).

Алгоритм:

1. Ловим `LearnStatusChanged` с `status = graded`.
2. Находим назначение по `(studentId = learn.userId, course)` через связь
   «курс → урок»; если урок не входит ни в один курс — выход.
3. В плоском порядке ищем урок `L_next`, следующий за оцененным.
4. Если `L_next` нет — курс завершён; пересчитываем `grade`/`completion`.
5. Если `L_next` есть:
   - `schedule_date(L_next) := schedule_date(оценённого) + time_gap(L_next)`,
     где `time_gap` берётся из связи `L_next`;
   - `L_next` в `scheduled` → `reschedule`; в `created` → `scheduleAt`;
   - `in_progress`/`done`/`graded` — ничего не делаем (не откатываем).
6. Пересчитываем `grade` и `completion` назначения (§7).

**Первый урок курса не шедулится этим механизмом** — он получает
`schedule_date = now` в момент назначения (§6.4).

`time_gap` применяется к планируемому уроку: берётся из связи `L_next`
(«время до него»). Для перехода **между темами** — `time_gap` первого урока
следующей темы.

### 9.1. Оговорка о базе для `schedule_date` (статус `created`)

**Проблема:** в Learn `created` — статус **без даты**, VO `ScheduleDate` не
nullable (`app/Domain/Learn/ValueObjects/ScheduleDate.php`, принимает только
корректную дату). При назначении курса (§6.4) все уроки кроме первого
создаются как `created` — даты у них нет и не появится до раскрытия.

Значит, при оценке урока `L_k` база для `L_next` зависит от того, каким был
`L_k`:

| Статус оценённого `L_k` | База `schedule_date(L_next)` |
|--------------------------|------------------------------|
| был `scheduled` (дата есть) | `learns.schedule_date(L_k) + time_gap(L_next)` |
| был `created` (даты нет) | `now + time_gap(L_next)` |

`now` — момент события `graded` (`event->statusDate`).

Реализация: `LearnStatePort::scheduleDate(...)` (§12.4) возвращает `null` для
`created`; слушатель `RecalculateOnLessonGraded` при `null` подставляет
`event->statusDate`.

Ограничение: раз `ScheduleDate` не nullable, статус `scheduled` **всегда
несёт дату**, а `created` — никогда. Поэтому «scheduled без даты» не
существует и добавлять его в Learn не требуется: при назначении курса первый
урок сразу получает `now` (§6.4), остальные — `created`; как только
предыдущий оценён, `L_next` переходит `created → scheduled(now + gap)`.


---

## 10. Справочник типов уроков (`lesson_types`)

### 10.1. Назначение

Тип урока ортогонален домену курсов (§11) — живёт в **отдельном справочнике**:

- курсами — для порогов допуска (§8.2);
- приложением/фронтом — для визуальной интерпретации (домашка «под»
  предыдущим уроком, контрольная «в конце»).

### 10.2. Схема `lesson_types`

| Поле | Тип | Инварианты |
|------|-----|------------|
| `id` | bigserial | PK |
| `code` | varchar(32) | уникален, стабильный (`home_work`) |
| `next_lesson_threshold` | float | `0.0 < t ≤ 1.0` |
| `color` | varchar(16) nullable | UI-подсказка |
| `sort` | int | порядок в списках |
| timestamps | timestamptz | |

### 10.3. Переводы `lesson_type_translations`

По образцу `role_translations` / `taxonomy_translations`:

| Поле | Тип |
|------|-----|
| `id` | bigserial |
| `lesson_type_id` | FK → `lesson_types.id`, cascade |
| `locale` | varchar(8) |
| `title` | varchar |
| `description` | text nullable |
| unique | `(lesson_type_id, locale)` |

### 10.4. Модели

`App\Models\LessonType` + `App\Models\LessonTypeTranslation`.

`LessonType`: `translations(): HasMany`, `translation(?string $locale = null)`,
аксессор `title` с fallback на `config('app.fallback_locale')` — как у
`App\Models\Taxonomy`.

Сид: `LessonTypeSeeder` — 4 записи из §8.2 с переводами `ru`/`en`.

### 10.5. Привязка типа к уроку в курсе

Связь `course_theme_lessons.type_id` (FK → `lesson_types.id`, nullable).
`null` — тип не задан, применяется только курсовой порог.

---

## 11. Интерпретация типов — ортогональна

Домен курсов **не содержит** бизнес-логики типа урока: ни в сущностях, ни в
стратегиях. Тип влияет на:

- **порог допуска** (§8.2) — через данные справочника, не через код домена;
- **отображение** — задача HTTP/UI-слоя;
- **последовательность** — уроки всегда строго последовательны, тип не
  отменяет последовательность.

Правило: любой `if ($type === 'home_work')` в домене курсов — нарушение
спецификации.

---

## 12. Интеграция с Learn

### 12.1. Слушатель `LearnStatusChanged` (graded)

`App\Listeners\Course\RecalculateOnLessonGraded`.

Алгоритм:

1. фильтр `status === LearnStatus::Graded`;
2. найти курс(ы), содержащие `event.lessonId`, через
   `CourseAssignmentRepositoryInterface::findByStudentAndLesson`, заодно
   достать назначение и плоский порядок;
3. пересчитать `grade` + `completion` (§7) синхронно (транзакция);
4. шедулинг следующего урока (§9).

**Важно:** в текущем коде Learn `LearnStatusChanged` — доменное событие
агрегата: оно забирается через `pullEvents()` в Actions и передаётся в
`StatusLoggerInterface`, но в шину Laravel **не** публикуется.

Решение — вариант D (§17.1): трейт `App\Application\Learn\Concerns\DrainsLearnEvents`
инкапсулирует существующий цикл `foreach pullEvents()` и добавляет
`Event::dispatch($event)` **после** `StatusLoggerInterface::log(...)`. Пять
Learn-Actions переходят на `$this->drainLearnEvents($learn)`. Аудит не ломается,
курсы получают событие. Подробный план — §17.4.

Слушатель курса обязан соблюдать контракт:

- **не бросать исключения наружу** — ловить всё и `Log::error(...)`: иначе
  упадёт HTTP-запрос `grade` уже после успешного аудита;
- быть **идемпотентным** — пересчёт `grade`/`completion` и шедулинг
  проектируются так, чтобы повторное применение давало тот же результат;
- выходить быстро, если `status !== Graded` или урок не принадлежит ни одному
  курсу ученика.


### 12.2. Слушатель создания назначения

`App\Listeners\Course\EnrollOnAssignmentCreated` — подписка на доменное
событие `CourseAssignmentCreated`, которое публикует `AssignCourseAction`
после успешного `save`. Алгоритм — §6.4.

Каждый урок проходит через
`EnrollLessonPort::enroll(studentId, lessonId, scheduleDate: ?)`.
Реализация — `App\Infrastructure\Course\LearnEnrollAdapter` поверх
`ScheduleLearnAction`.

### 12.3. Слушатель снятия назначения

`App\Listeners\Course\UnenrollOnAssignmentRemoved` — подписка на
`CourseAssignmentRemoved`. Алгоритм — §6.5.

### 12.4. Порты интеграции с Learn

```php
interface EnrollLessonPort
{
    public function enroll(UserId $studentId, LessonId $lessonId, ?ScheduleDate $scheduleDate): void;
    public function unenrollIfNotStarted(UserId $studentId, LessonId $lessonId): bool;
}

interface LearnStatePort
{
    public function grade(UserId $studentId, LessonId $lessonId): ?float;          // 0..1 или null
    public function scheduleDate(UserId $studentId, LessonId $lessonId): ?DateTimeImmutable;
    public function existsActive(UserId $studentId, LessonId $lessonId): bool;
}
```

`unenrollIfNotStarted` требует нового метода Learn-порта
`LearnRepositoryInterface::deleteUnstarted(UserId, LessonId): bool` (§17).

### 12.5. Порт чтения уроков

```php
interface LessonInfoPort
{
    public function exists(LessonId $lessonId): bool;
    public function complexity(LessonId $lessonId): ?float;
    /** @param list<LessonId> $ids @return array<int, ?float> lessonId => complexity */
    public function complexities(array $ids): array;
}
```

---

## 13. Application-слой

### 13.1. Контекст смотрящего `CourseViewerContext`

Отдельно от `Learn\Context\ViewerContext`: видимость курсов определяется не
прохождениями, а авторством и графом «учитель → методист → завуч» (граф
`user_relations`, типы рёбер — `App\Application\Learn\Context\RelationType`).

```php
final readonly class CourseViewerContext
{
    public function __construct(
        public int $viewerId,
        public array $roles,          // list<ViewerRole>
        public bool $canCreate,
        public bool $canSeeSystem,
        public bool $canAssign,
        public CourseVisibility $visibility,
    ) {}
}
```

`CourseVisibility`:

| Поле | Смысл |
|------|-------|
| `authorIds` | `?list<int>` — чьи курсы видны; `null` — все (завуч) |
| `studentIds` | `list<int>` — чьи назначения видны (student/parent) |
| `onlyAssignedCourses` | `true` — student видит только назначенные ему |

Правила построения (`CourseContextResolverInterface`):

| Роль | `authorIds` | `onlyAssigned` | `canCreate` | `canCreateSystem` | `canAssign` |
|------|-------------|----------------|-------------|-------------------|-------------|
| `admin` | `null` (все) | `false` | yes | **yes** | yes |
| `student` | `[]` | `true` | no | no | no |
| `teacher` | `[self]` | `false` | yes | no | yes |
| `methodologist` | `[self] + teachers(self)` | `false` | yes | no | yes |
| `headteacher` | `null` (все) | `false` | yes | no | yes |
| `parent` | `[]` (`studentIds = дети(self)`) | `false` | no | no | no |

`canSeeSystem`: курсы с `user_id = null` видны `teacher`, `methodologist`,
`headteacher`, `student` — но **не** `parent` (у него только назначенные детям).

Права:

| Право | Условие |
|-------|---------|
| `canEdit(Course)` | `course.userId === viewerId`; для системного (`userId = null`) — только `admin` |
| `canCreateCourse(ownerId)` | `ownerId === viewerId` И роль ≥ teacher; системный (`ownerId = null`) — **только `admin`** |
| `canDelete(Course)` | `canEdit` |
| `canAssign` | курс виден + роль ≥ teacher |

> **Редактирование ≠ видимость.** `headteacher`, `methodologist`, `admin`
> видят все курсы, но правят только те, что создали сами. У системных курсов
> (`user_id = null`) автора нет, поэтому единственное исключение из правила —
> `admin`: он их и создаёт, и правит.
>
> Чтобы `admin` правил свой авторский курс, ему нужна роль `teacher` и курс
> должен быть создан именно им. Чужие авторские курсы не правит никто, кроме
> их автора.

### 13.2. `GetList(?tax_id, ?search)`

`GetListCoursesAction(CourseViewerContext $ctx, ?TaxonomyId $taxonomyId, ?string $search, int $page, int $perPage): PaginatedResult<CourseListItem>`.

Правила выборки (в порядке применения):

1. **Scope по роли** (§13.1):
   - `student` — `EXISTS(course_assignments WHERE student_id = viewer)`;
   - `parent` — `EXISTS(... WHERE student_id IN students(children))`;
   - `teacher` / `methodologist` — `user_id IN authorIds` ИЛИ
     (`user_id IS NULL` при `canSeeSystem`);
   - `headteacher` — все.
2. **`tax_id`**: узел таксономии + **все его потомки** (WHY: пользователь
   смотрит программу своей ветки). Дерево раскрывается через
   `WITH RECURSIVE` по `taxonomies.parent_id`.

   > **Проверено:** в БД `lesson_taxonomy` имеет `unique('lesson_id')`,
   > т.е. каждый урок принадлежит **ровно одному** узлу таксономии.
   > Значит фильтр курс→ветка можно строить и через уроки курса
   > (`course.themes.lessons.lesson.taxonomies`), но `courses.tax_id`
   > остаётся : он задаёт ветку **курса** целиком и позволяет фильтровать
   > листинг без джойнов на уроки.
2. **`search`**: `ILIKE '%q%'` по `title` и `description`.
3. **`search`**: `ILIKE '%q%'` по `title` и `description`.
4. Исключаются `deleted_at IS NOT NULL`.
5. Сортировка: `updated_at DESC, id DESC`.

### 13.3. `CourseListItem` (VO листинга)

```php
final readonly class CourseListItem
{
    public function __construct(
        public int $id,
        public ?int $userId,
        public string $title,
        public ?string $description,
        public ?float $complexity,
        public ?int $taxonomyId,
        public string $locale,
        public DateTimeImmutable $createdAt,
    ) {}
}
```

Полей тем/уроков **нет** — листинг лёгкий. Детали — `GetCourseAction`.

### 13.4. Детальный `Course` в VO `CourseDetails`

`GetCourseAction(CourseId, CourseViewerContext): CourseDetails` — агрегат + темы
+ уроки с ссылками и параметрами планирования. Read-only.

### 13.5. Actions (полный список)

| Action | Сигнатура (упрощённо) | Права |
|--------|-----------------------|-------|
| `GetListCoursesAction` | `(CourseViewerContext, ?TaxonomyId, ?string, int, int)` | scope §13.2 |
| `GetCourseAction` | `(CourseId, CourseViewerContext)` | видим |
| `CreateCourseAction` | `(UserId, CreateCourseData)` | `canCreate` (системный — только `admin`) |
| `UpdateCourseAction` | `(CourseId, UpdateCourseData, CourseViewerContext)` | `canEdit` |
| `DeleteCourseAction` | `(CourseId, CourseViewerContext)` | `canDelete` |
| `AddThemeAction` | `(CourseId, AddThemeData, CourseViewerContext)` | `canEdit` |
| `UpdateThemeAction` | `(CourseId, CourseThemeId, UpdateThemeData, CourseViewerContext)` | `canEdit` |
| `RemoveThemeAction` | `(CourseId, CourseThemeId, CourseViewerContext)` | `canEdit` |
| `ReorderThemesAction` | `(CourseId, list<int>, CourseViewerContext)` | `canEdit` |
| `AddLessonToThemeAction` | `(CourseId, CourseThemeId, AddLessonData, CourseViewerContext)` | `canEdit` |
| `RemoveLessonFromThemeAction` | `(CourseId, CourseThemeId, LessonId, CourseViewerContext)` | `canEdit` |
| `ReorderLessonsAction` | `(CourseId, CourseThemeId, list<int>, CourseViewerContext)` | `canEdit` |
| `AssignCourseAction` | `(CourseId, studentId, CourseViewerContext)` | `canAssign` |
| `UnassignCourseAction` | `(CourseId, studentId, CourseViewerContext)` | `canAssign` |
| `GetLessonCanBeLearnedAction` | `(UserId, LessonId, CourseId): bool` | — |
| `GetLessonAccessAction` | `(UserId, LessonId, CourseId): CourseAccessRule` | — |

DTO (readonly, `Application\Course\DTOs`): `CreateCourseData`, `UpdateCourseData`,
`AddThemeData`, `UpdateThemeData`, `AddLessonData`, `ReorderData`.

### 13.6. DTO → домен

Application **не** создаёт VO «на месте» из сырых int — валидация значений
(например `NextLessonThreshold`) выполняется конструктором VO, а исключения
`InvalidArgumentException` транслируются в HTTP 422 на уровне контроллера.

---

## 14. Инфраструктура (схема БД)

### 14.1. `courses`

```sql
id              bigserial PRIMARY KEY
user_id         bigint NULL REFERENCES users(id) ON DELETE CASCADE   -- NULL = системный
title           varchar(255) NOT NULL
description     text NOT NULL DEFAULT ''
next_lesson_threshold float NOT NULL DEFAULT 1.0
locale          varchar(8) NOT NULL
tax_id          bigint NULL REFERENCES taxonomies(id) ON DELETE SET NULL
complexity      float NULL
created_at      timestamptz NOT NULL
updated_at      timestamptz NOT NULL
deleted_at      timestamptz NULL

INDEX (user_id)                      -- листинги «мои курсы»
INDEX (tax_id)                       -- листинги по ветке
INDEX (updated_at DESC)              -- сортировка листинга
INDEX (deleted_at)
```

### 14.2. `course_themes`

```sql
id          bigserial PRIMARY KEY
course_id   bigint NOT NULL REFERENCES courses(id) ON DELETE CASCADE
title       varchar(255) NOT NULL
"order"     integer NOT NULL
created_at  timestamptz NOT NULL
updated_at  timestamptz NOT NULL

UNIQUE (course_id, "order")          -- инвариант §4.1
INDEX (course_id)
```

### 14.3. `course_theme_lessons`

```sql
id          bigserial PRIMARY KEY
theme_id    bigint NOT NULL REFERENCES course_themes(id) ON DELETE CASCADE
lesson_id   bigint NOT NULL REFERENCES lessons(id) ON DELETE CASCADE
type_id     bigint NULL REFERENCES lesson_types(id) ON DELETE SET NULL
"order"     integer NOT NULL
time_gap    integer NOT NULL DEFAULT 2          -- часы
created_at  timestamptz NOT NULL
updated_at  timestamptz NOT NULL

UNIQUE (theme_id, "order")
UNIQUE (theme_id, lesson_id)         -- урок в теме не дублируется
INDEX (lesson_id)                    -- обратный поиск «в каких курсах урок»
```

`time_gap` — **целые часы** (integer, не дробные). Дефолт —
`config('course.lessons.time_gap', 2)`. Применяется к уроку, который нужно
запланировать: `schedule_date(L_next) = schedule_date(L_prev) + interval
'<time_gap> hour'`. Для перехода между темами берётся `time_gap` первого
урока следующей темы.

Дробные разрывы (полчаса) не нужны. `[позже]` при необходимости — сменить тип
на `interval` PostgreSQL без изменения доменного VO (VO хранит `int` часов,
адаптер может расширить).

`ON DELETE CASCADE` у **обоих** FK:

- `theme_id → course_themes` — тема удалена, её связи умирают;
- `lesson_id → lessons` — урок удалён в Lessons, все связи курсов с ним
  снимаются каскадом. Висящих `course_theme_lessons` на мёртвый id быть не
  должно: это сиротские строки, которые ломают листинги «в каких курсах урок»
  и лендинг курса (в курсе появится урок без контента).
- `type_id → lesson_types` — `SET NULL`, справочник не должен тащить за собой
  курсы; удаление типа возвращает уроку «тип не задан» (§10.5).


### 14.4. `course_assignments`

```sql
id          bigserial PRIMARY KEY
course_id   bigint NOT NULL REFERENCES courses(id) ON DELETE CASCADE
student_id  bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE
assigned_by bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT
completion  float NOT NULL DEFAULT 0             -- [0.0, 1.0]
grade       float NOT NULL DEFAULT 0             -- [0.0, 1.0]
created_at  timestamptz NOT NULL
updated_at  timestamptz NOT NULL

UNIQUE (course_id, student_id)
INDEX (student_id)                               -- «мои курсы» (student)
INDEX (course_id)                                -- «ученики курса» (teacher)
```

`CHECK`-констрейнты `completion BETWEEN 0 AND 1`, `grade BETWEEN 0 AND 1` —
`schema`-уровень, защита от рассинхрона при пересчёте.

### 14.5. `lesson_types` + `lesson_type_translations`

См. §10.2–10.3.

---

## 15. Модели Eloquent (тонкие)

| Модель | Таблица | Trait-ы | Связи | Casts |
|--------|---------|---------|-------|-------|
| `App\Models\Course` | `courses` | `SoftDeletes`, `UsesTimestamptz` | `owner()`, `taxonomy()`, `themes()`, `assignments()` | `next_lesson_threshold => float`, `complexity => float`, timestamps |
| `App\Models\CourseTheme` | `course_themes` | `UsesTimestamptz` | `course()`, `lessons()` (`hasMany(CourseThemeLesson)->orderBy('order')`) | `order => int` |
| `App\Models\CourseThemeLesson` | `course_theme_lessons` | `UsesTimestamptz` | `theme()`, `lesson()`, `type()` | `order/time_gap/type_id => int` |
| `App\Models\CourseAssignment` | `course_assignments` | `UsesTimestamptz` | `course()`, `student()`, `assignedBy()` | `completion/grade => float` |
| `App\Models\LessonType` | `lesson_types` | `UsesTimestamptz` | `translations()`, `courseLessons()` | `next_lesson_threshold => float`, `sort => int` |
| `App\Models\LessonTypeTranslation` | `lesson_type_translations` | — | `lessonType()` | — |

Тонкие модели не содержат доменных правил: логика — в `App\Domain\Course`.

---

## 16. Конфигурация `config/course.php`

```php
return [
    'lessons' => [
        // Разрыв в часах по умолчанию между уроками темы (§4.2).
        'time_gap' => (int) env('COURSE_LESSONS_TIME_GAP', 2),
    ],

    'strategies' => [
        // Классы-реализации Application-портов (см. §7).
        'grade'      => \App\Application\Course\Strategies\AverageGradeStrategy::class,
        'completion' => \App\Application\Course\Strategies\GradedRatioCompletionStrategy::class,
        'admission'  => \App\Application\Course\Strategies\ThresholdAdmissionStrategy::class,
    ],

    'pagination' => [
        'per_page' => (int) env('COURSE_PER_PAGE', 20),
    ],
];
```

Биндинги в `AppServiceProvider::register()`:

```php
$this->app->bind(CourseRepositoryInterface::class, EloquentCourseRepository::class);
$this->app->bind(CourseAssignmentRepositoryInterface::class, EloquentCourseAssignmentRepository::class);
$this->app->bind(CourseContextResolverInterface::class, EloquentCourseContextResolver::class);
$this->app->bind(LessonInfoPort::class, EloquentLessonInfoAdapter::class);
$this->app->bind(EnrollLessonPort::class, LearnEnrollAdapter::class);
$this->app->bind(LearnStatePort::class, EloquentLearnStateAdapter::class);

$this->app->bind(GradeStrategyInterface::class,
    fn () => $this->app->make((string) config('course.strategies.grade')));
// completion, admission — аналогично
```

Слушатели — `Event::listen(...)` в `boot()` (или через `EventServiceProvider`
с `$listen`, если он появится).

---

## 17. Требуемые доработки в смежных контекстах

Курсы не должны ломать Learn, но минимальные точки интеграции нужны:
§17.1 — публикация `LearnStatusChanged` в шину (вариант D: трейт),
§17.2 — `deleteUnstarted`, §17.3 — `started_at`, §17.4 — пошаговый план
перевода Learn на трейт, §17.5 — задел `[позже]`.

### 17.1. Публикация `LearnStatusChanged` в шину Laravel [MVP]

**Решение: вариант D — трейт `DrainsLearnEvents`** (Application-слой Learn).

Проблема в текущем коде: цикл `foreach ($learn->pullEvents() as $event) {
$this->statusLogger->log(...); }` продублирован вручную в **пяти** Actions:

| Action | Что порождает |
|--------|---------------|
| `StartLearnAction` (127–138) | `in_progress` |
| `ScheduleLearnAction` (120–131) | `scheduled` / `created` |
| `MarkDoneAction` (64–72) | `done` |
| `GradeLearnAction` (56–64) | **`graded`** ← нужно курсам |
| `CancelLearnAction` (~54) | `created` / `scheduled` |

`pullEvents()` **очищает** буфер агрегата, поэтому второй вызов вернёт `[]`:
шина должна работать на тех же объектах, что уже вынуты из агрегата. Порядок
вызовов: сначала `statusLogger->log(...)` (аудит — не теряем, если слушатель
упадёт), затем `Event::dispatch($event)`.

Новый файл `app/Application/Learn/Concerns/DrainsLearnEvents.php`:

```php
<?php

declare(strict_types=1);

namespace App\Application\Learn\Concerns;

use App\Application\Learn\Ports\StatusLoggerInterface;
use App\Domain\Learn\Entities\Learn;
use Illuminate\Support\Facades\Event;

/**
 * Единообразная разводка доменных событий Learn во внешние порты.
 *
 * Инвариант: pullEvents() очищает буфер агрегата → каждое событие
 * уходит ровно один раз: сначала в аудит (StatusLoggerInterface),
 * затем в шину Laravel (для подписчиков смежных контекстов, напр. Course).
 *
 * Требует от использующего класса свойство $statusLogger.
 */
trait DrainsLearnEvents
{
    private function drainLearnEvents(Learn $learn): void
    {
        foreach ($learn->pullEvents() as $event) {
            $this->statusLogger->log(
                learnId: $event->learnId,
                lessonId: $event->lessonId,
                status: $event->status,
                statusDate: $event->statusDate,
                scheduleDate: $event->scheduleDate?->value,
            );

            Event::dispatch($event);
        }
    }
}
```

В каждом Action:

```php
// было: private function drainEvents(Learn $learn): void { foreach ... }
//       либо голый foreach прямо в __invoke
$this->drainLearnEvents($learn);
```

#### Файлы, которые меняются

| Файл | Изменение |
|------|-----------|
| `app/Application/Learn/Concerns/DrainsLearnEvents.php` (**новый**) | трейт `drainLearnEvents()` (код выше) |
| `app/Application/Learn/Actions/GradeLearnAction.php` | `use DrainsLearnEvents;` + `foreach` 56–64 → `$this->drainLearnEvents($learn);` |
| `app/Application/Learn/Actions/MarkDoneAction.php` | `use DrainsLearnEvents;` + `foreach` 64–72 → вызов трейта |
| `app/Application/Learn/Actions/CancelLearnAction.php` | `use DrainsLearnEvents;` + `foreach` ~54–62 → вызов трейта |
| `app/Application/Learn/Actions/StartLearnAction.php` | `use DrainsLearnEvents;` + приватный `drainEvents()` 127–138 удалить, в 114 — вызов трейта; `statusLogger->log(...)` 105–111 оставить |
| `app/Application/Learn/Actions/ScheduleLearnAction.php` | `use DrainsLearnEvents;` + приватный `drainEvents()` 120–131 удалить, в 104 — вызов трейта; `logInitial()` 109–118 оставить |
| `tests/Feature/Learn/DispatchLearnEventsTest.php` (новый) | 5 кейсов: `Event::fake()` + `assertDispatched(LearnStatusChanged::class)` с ожидаемым статусом |

Итого: **+1 трейт, 5 Actions (по ~8 строк удалить / 1 строку добавить), 1 тест.**

#### Детализация по Actions

Во всех Actions свойство называется `$statusLogger`
(`App\Application\Learn\Ports\StatusLoggerInterface`) — трейт опирается на него.

| Файл | Удалить | Добавить/заменить | Осторожно |
|------|---------|-------------------|-----------|
| `GradeLearnAction` | `foreach` 56–64 | `$this->drainLearnEvents($learn);` | — |
| `MarkDoneAction` | `foreach` 64–72 | `$this->drainLearnEvents($learn);` | — |
| `CancelLearnAction` | `foreach` ~54–62 | `$this->drainLearnEvents($learn);` | — |
| `StartLearnAction` | приватный `drainEvents()` 127–138 | в 114 — `$this->drainLearnEvents($learn);` | **оставить** ручной `statusLogger->log(...)` 105–111: первичная запись `in_progress` для нового агрегата, домен её не порождает (§17.5) |
| `ScheduleLearnAction` | приватный `drainEvents()` 120–131 | в 104 — `$this->drainLearnEvents($existing);` | ветка «новое прохождение» 66–76 (`save → logInitial → return`) **не** вызывает `drainEvents()` — так и оставить: у нового агрегата событий нет. `logInitial()` 109–118 **не** убирается |

**Итог по `logInitial()`:** это не дублирование `drainEvents()`, а фиксация
первичного статуса, который домен не порождает. `Learn::recordStatusChange()`
(стр. 393–399) выходит раньше, если `$this->id === null`:

```php
private function recordStatusChange(DateTimeImmutable $now): void
{
    // Событие осмысленно только для уже сохранённого агрегата:
    // у нового Learn ещё нет id, и логгер некуда писать.
    if ($this->id === null) {
        return;
    }
    ...
}
```

Поэтому аудит первичного статуса пишется вручную сразу после `insert`/`save`.
Вынести это в событие — §17.5, отдельная задача, **не** блокирует курсы.

**Что не меняется:** `LearnStatusChanged`, `StatusLoggerInterface`,
`RedisStatusLogger`, `DbStatusLogger`, `FlushLearnStatusBufferJob`, схема
`learns`/`learn_statuses`, домен `Learn` (ни одной строки — он остаётся
без зависимости от Laravel).

**Идемпотентность и защита:**

| Сценарий | Поведение |
|----------|-----------|
| Повторный `pullEvents()` | Возвращает `[]` — событие не уйдёт второй раз |
| Повторный `grade` (учитель нажал дважды) | `Graded` терминален (`allowedTransitions = []`) → `markGraded` бросит `InvalidStatusTransition` до записи и до события |
| Слушатель упал исключением | Аудит уже записан (`log` до `dispatch`); слушатель курса обязан **не** бросать наружу — ловить и `Log::error` (пересчёт идемпотентен, повторится на следующем событии) |
| Событие в тестах | `Event::fake()` + `assertDispatchedOnce(LearnStatusChanged::class)` |

`tests/Feature/Learn/DispatchLearnEventsTest.php`: по одному кейсу на Action
(`start`, `schedule`, `markDone`, `grade`, `cancel`) — `Event::fake()`, вызов
Action, `assertDispatched(LearnStatusChanged::class)` с ожидаемым статусом.

### 17.2. `LearnRepositoryInterface::deleteUnstarted` [MVP]

```php
public function deleteUnstarted(UserId $userId, LessonId $lessonId): bool;
```

Удаляет активное прохождение **только** если `status ∈ {created, scheduled}`
и `started_at IS NULL`. Возвращает `true`, если удалено. Нужно для §6.5.

Реализация — `WHERE user_id = ? AND lesson_id = ? AND status IN ('created','scheduled')
AND started_at IS NULL` + `delete()`. Race-условие: между `SELECT` и `DELETE`
ученик мог начать урок — поэтому удаление делается одним `DELETE ... RETURNING`
(или `affected > 0`) без предварительного чтения агрегата.

### 17.3. `learns.started_at` [MVP]

Колонка `started_at timestamptz NULL` в `learns` — миграция
`2026_11_20_120000_add_started_at_to_learns.php` **уже существует**.
Проверить наличие колонки перед реализацией §6.5; если её нет в БД —
добавить отдельной миграцией.

`started_at` заполняется при переходе `created|scheduled → in_progress`
(см. `Learn::start()`).

### 17.4. План миграции Learn на трейт (пошагово)

Порядок, чтобы не сломать тесты Learn в процессе:

1. Создать `app/Application/Learn/Concerns/DrainsLearnEvents.php`.
2. `GradeLearnAction` — подключить трейт, заменить `foreach` (56–64).
3. `MarkDoneAction` — то же (64–72).
4. `CancelLearnAction` — то же (~54).
5. `ScheduleLearnAction` — удалить приватный `drainEvents()` (120–131),
   заменить вызов в 104 на трейт. **`logInitial()` (109–118) оставить** —
   первичный статус не порождается доменом (§17.5).
6. `StartLearnAction` — удалить приватный `drainEvents()` (127–138), заменить
   вызов в 114 на трейт. **Ручной `statusLogger->log(...)` (105–111) оставить.**
7. `tests/Feature/Learn/DispatchLearnEventsTest.php` — 5 кейсов.
8. `php artisan test --filter=Learn` — зелёный.
9. Только после этого — подписывать `RecalculateOnLessonGraded` (курсы).

Временное «окно», когда шина уже работает, а слушателя ещё нет — безопасно:
событие просто никого не находит.

`[позже]`: когда Actions Learn вырастут, заменить трейт на сервис
`LearnEventDrainer` (композиция); либо перейти на `EventServiceProvider` и
`dispatchAfterResponse()` для слушателей, не влияющих на пользовательский
ответ.

### 17.5. `[позже]` Начальный статус как доменное событие

`Learn::recordStatusChange()` (393–399) намеренно не порождает событие, пока
у агрегата нет id — поэтому первичная запись статуса делается вручную
(`StartLearnAction` 105–111, `ScheduleLearnAction::logInitial()` 109–118).

Возможный рефакторинг: после `insert` с предвыделенным id (как в
`StartLearnAction`, стр. 99 — «Синхронный INSERT с предвыделенным id») агрегат
уже знает id, и `recordStatusChange()` мог бы породить событие и для первичного
статуса. Требует пересмотра `Learn::start()`/`schedule()`/`assign()`, меняет
порядок «аудит → события» в двух Actions.

Курсам не нужно: им нужен только переход в `graded`, который всегда
порождается доменом. **Не** входит в MVP.

---

## 18. Правила проверки и инварианты БД

1. `courses.title` — `NOT NULL`, длина 1..255 (VO гарантирует, БД страхует).
2. `course_themes.order` — `UNIQUE(course_id, order)`.
3. `course_theme_lessons.order` — `UNIQUE(theme_id, order)`.
4. `course_theme_lessons (theme_id, lesson_id)` — `UNIQUE`.
5. `course_assignments (course_id, student_id)` — `UNIQUE`.
6. `course_assignments.completion/grade` — `CHECK BETWEEN 0 AND 1`.
7. `lesson_types.code` — `UNIQUE`.
8. `lesson_type_translations (lesson_type_id, locale)` — `UNIQUE`.

Все мутации порядка внутри агрегата выполняются **в транзакции** с
двухфазной нормализацией `order` (`SAFE_OFFSET`-сдвиг, как в Lessons) — иначе
`UNIQUE`-констрейнт упадёт на промежуточном состоянии.

---

## 19. Тесты (обязательный минимум)

| # | Что | Где |
|---|-----|-----|
| 1 | Инварианты VO (`CourseGrade`, `CourseCompletion`, `NextLessonThreshold`, `TimeGap`) | `tests/Unit/Course/ValueObjects` |
| 2 | Агрегат: `addTheme`/`addLesson`/`reorder*` (нормализация order), блокировки `tax_id`/`locale` | `tests/Unit/Course/CourseTest` |
| 3 | `flatLessons()` — порядок по (theme.order, lesson.order) | `tests/Unit/Course/CourseFlatOrderTest` |
| 4 | Стратегии: average / ratio / threshold-admission | `tests/Unit/Course/Strategies` |
| 5 | `GetListCoursesAction` — scope по каждой роли + tax_id с потомками + поиск | `tests/Feature/Course/GetListCoursesTest` |
| 6 | `AssignCourseAction` → создано N прохождений, первое scheduled | `tests/Feature/Course/AssignCourseTest` |
| 7 | `UnassignCourseAction` → удалены только `created/scheduled` | `tests/Feature/Course/UnassignCourseTest` |
| 8 | Слушатель graded → пересчёт + шедулинг следующего урока | `tests/Feature/Course/RecalculateOnLessonGradedTest` |
| 9 | `GetLessonCanBeLearned` — первый/не первый урок, порог, дата | `tests/Feature/Course/AdmissionTest` |
| 10 | Матрица прав §13.1: `canEdit`/`canCreate` по всем ролям, системные курсы, чужой авторский курс | `tests/Feature/Application/Course/CreateCourseActionTest` |
| 11 | Удаление: владелец / чужой учитель / `admin` на системном / учитель на системном | `tests/Feature/Application/Course/DeleteCourseActionTest` |
| 12 | Learn: каждое Action публикует `LearnStatusChanged`, аудит раньше шины, без дублей | `tests/Feature/DispatchLearnEventsTest` |
| 13 | `deleteUnstarted` на реальной БД: `started_at` защищает от стирания | `tests/Feature/Infrastructure/Learn/EloquentLearnRepositoryDeleteUnstartedTest` |
| 14 | `started_at` round-trip Eloquent → домен → БД | `tests/Feature/Infrastructure/Learn/EloquentLearnRepositoryStartedAtRoundTripTest` |
| 15 | `RecalculateOnLessonGraded`: пересчёт, фильтр `graded`, отсутствие курса, чужие ученики | `tests/Feature/Listeners/Course/RecalculateOnLessonGradedTest` |

---

## 20. Открытые вопросы и принятые допущения

| # | Вопрос | Принято | Обоснование |
|---|--------|---------|-------------|
| 1 | `time_gap` — часы или минуты | часы, `int` | ответ заказчика (§2 вводных); `config('course.lessons.time_gap', 2)` |
| 2 | Тип урока — где хранится | справочник `lesson_types` + `course_theme_lessons.type_id` | требование «нужен справочник, модель, миграция, локализации» |
| 3 | Порядок допуска | плоский `(theme.order, lesson.order)` | ответ заказчика |
| 4 | Источник события «урок оценён» | `LearnStatusChanged` c `status=graded` | ответ заказчика |
| 5 | Шедулинг следующего | от `schedule_date` предыдущего + `time_gap(L_next)` | ответ заказчика |
| 6 | Первый урок | `schedule_date = now` при назначении, без `time_gap` | ответ заказчика («для первого не нужно шедуленья») |
| 7 | Смена `grade`/`completion` | синхронный пересчёт | ответ заказчика |
| 8 | Таксономия курса | колонка `courses.tax_id`, одна связь | ответ заказчика |
| 9 | Системные курсы (`user_id = null`) | видны всем кроме `parent` | школьные программы; `parent` — только назначенные детям |
| 10 | Уникальность `(course_id, student_id)` | без soft delete у назначений | удаление назначения — реальный `DELETE`, повторное назначение разрешено |
| 11 | `Course::changeLocale` | только на курсе без уроков | смена языка программы ломает уже выданные задания |
| 12 | Масштаб оценок Learn (0..100) vs Course (0..1) | нормализация в адаптере | стратегии живут в `[0..1]` |
| 13 | Как курсы узнают о `graded` | трейт `DrainsLearnEvents` + `Event::dispatch` (§17.1, вариант D) | аудит (Redis) асинхронен — не годится как источник события; домен Learn остаётся без зависимости от Laravel |
| 14 | `time_gap` | **целые часы** (`integer`), не минуты | ответ заказчика |
| 15 | `course_theme_lessons.lesson_id` | `ON DELETE CASCADE` | ответ заказчика; сиротские связи на мёртвый урок ломают листинг |
| 16 | `lesson_taxonomy` уже `unique('lesson_id')` | урок в одном узле таксономии | найдено в БД; `courses.tax_id` — по-прежнему нужен для фильтра листинга без джойнов на уроки |
| 17 | Кто создаёт системные курсы (`user_id = null`) | **только `admin`** | требование заказчика; методист/завуч системные курсы не создают |
| 18 | Кто редактирует авторский курс | **только его создатель** | требование заказчика: `admin`/`methodologist`/`headteacher` видят все курсы, но правят только свои. `seesEverything()` больше не даёт прав на правку |
| 19 | `admin` и авторские курсы | правит только свои, и только если создал их сам | системные — его исключительная зона; чужой авторский курс недоступен никому, кроме автора |


---

## 21. Глоссарий кодов ошибок

| Exception | Где | Когда |
|-----------|-----|-------|
| `InvalidCourseOrder` | Domain | дубль/неположительный `order` |
| `CourseTaxonomyChangeDenied` | Domain | `moveToTaxonomy` на курсе с уроками |
| `CourseLocaleChangeDenied` | Domain | `changeLocale` на курсе с уроками |
| `CourseLessonNotInTheme` | Domain | урок не найден в теме |
| `CourseThemeNotFound` | Domain | тема не найдена в агрегате |
| `CourseAlreadyAssigned` | Domain | повторное назначение пары |
| `InvalidCourseGrade` | Domain | оценка вне `[0..1]` |
| `InvalidCompletion` | Domain | пройденность вне `[0..1]` |
| `InvalidNextLessonThreshold` | Domain | порог не в `(0..1]` |
| `InvalidTimeGap` | Domain | отрицательный разрыв |
| `CourseAccessDenied` | Application | нет права (create/edit/assign) |
| `CourseNotFound` | Application | курс не найден или soft-deleted |

---

## 22. Чек-лист готовности (Definition of Done) [MVP]

- [x] Миграции `lesson_types`, `lesson_type_translations` + `LessonTypeSeeder`.
- [x] Миграции `courses`, `course_themes`, `course_theme_lessons`,
      `course_assignments`. Модели Eloquent (§15) с `UsesTimestamptz`.
- [x] `app/Domain/Course` — все VO/сущности/исключения/события (§3–§6).
      34 файла, 0 ошибок PHPStorm:
      15 VO, 12 исключений, 4 сущности (`Course`, `CourseTheme`, `CourseLesson`,
      `CourseAssignment`), 3 события.
      Решения при реализации:
      VO **не** переиспользуют `Domain\Learn\*`/`Domain\Lessons\*` — по
      конвенции проекта (`Lessons\ValueObjects\LessonId`): контексты
      связываются в Infrastructure.
      `NextLessonThreshold::isSatisfiedBy()` — строгий `>`: ровно порог
      не открывает следующий урок.
      `Course` иммутабелен; `addLesson()` проверяет дубль урока по **всему курсу**.

- [x] `app/Application/Course` — порты, стратегии, контекст, Actions.
      26 файлов, 0 ошибок PHPStorm:

      **Ports (4):** `CourseRepositoryInterface`, `CourseAssignmentRepositoryInterface`,
      `LearnPortInterface`, `LessonReaderInterface`.
      **Strategies (5):** `GradeStrategyInterface` + `AverageLessonGradesStrategy`
      (среднее только по оценённым), `CompletionStrategyInterface` +
      `LessonCountCompletionStrategy` (`ratio(graded, total)`),
      `AdmissionStrategyInterface` + `SequentialAdmissionStrategy`
      (первый урок открыт, дальше — `max(порог курса, порог типа)`, строгий `>`).
      **Context (2):** `CourseViewerContext`, `CourseViewerRole` — матрица прав
      в doc-комментарии enum.
      **Services (2):** `AdmissionChecker`, `CourseGradeRecalculator`.
      **Actions (9):** `CreateCourseAction`, `EditCourseHeaderAction`,
      `EditCourseThemeAction`, `EditCourseLessonAction`, `AssignCourseAction`,
      `UnassignCourseAction`, `RecalculateCourseAction`,
      `RecalculateCourseForAllAction`, `ListCoursesAction`, `DeleteCourseAction`.
      **Exceptions (2):** `CourseNotFound`, `CourseAccessDenied` (Application-уровень).

> **ВАЖНО — Actions не открывают транзакций.** `AssignCourseAction`,
> `UnassignCourseAction`, `DeleteCourseAction` трогают две БД (курсы + Learn)
> через порты. Транзакция — ответственность вызывающего (Controller/Job).
>
> **Побочное исправление:** `AssignCourseAction` потерялся при MCP-сбое
> (файл отсутствовал, хотя создавался). Проект **пересобран и проверен**:
> `CourseAssignment.php` восстановлен с `create()`, без которого Action
> не компилировался.

> **Порядок работы с составом курса (уточнено):**
> 1. Создание — только шапка: `Course::create()` **не** принимает темы
>    (у нового курса нет id, тема без `course_id` не существует — FK).
> 2. Редактирование — `restore()` загруженного курса и уже затем
>    `addTheme()` / `addLesson()`. Все темы восстановленного курса имеют id,
>    поэтому `addLesson()` находит их по `CourseThemeId`.
>
> В домене это защищено: `Course::assertPersisted()` бросает `LogicException`
> на `addTheme()` до `save()`, а `CourseTheme::__construct` не даёт создать
> тему с уроками без id. `Course::restore()` дополнительно требует, чтобы у
> всех тем был id.
- [x] `app/Infrastructure` — репозитории, адаптеры, слушатели.
      7 файлов, 0 ошибок PHPStorm:
      `EloquentCourseRepository` (гидрация/сохранение агрегата, «снеси и
      восстанови» для тем/уроков внутри транзакции),
      `EloquentCourseAssignmentRepository`, `LearnAdapter`,
      `LessonReaderAdapter`,
      `EnrollOnAssignmentCreated`, `UnenrollOnAssignmentRemoved`,
      `RecalculateOnCourseChanged` (все `ShouldQueueAfterCommit`).
- [x] Биндинги + `config/course.php` + `CourseServiceProvider`
      (зарегистрирован в `bootstrap/providers.php`).

> **Найденные при реализации подводные камни:**
> - `readonly` класс не может иметь свойств со значением по умолчанию —
>   слушатели с `$tries`/`$backoff` объявлены обычными `final`.
> - Порог в `taxonomies`: корень — `parent_id = -1`, а не `null`
>   (миграция `2026_10_23_000000`). Адаптер ищет детей по `-1`.
> - `lesson_taxonomy` — таблица-pivot, не `lesson_taxonomies`.
> - Шкала Learn — 0..100, шкала курсов — 0..1. Нормализация только
>   на границе `LearnAdapter`. Сложность урока в БД уже 0..1.
> - `LearnAdapter::enrollLesson` создаёт `created`-прохождение напрямую
>   через модель, минуя `StartLearnAction` (иначе — SnapshotResolver,
>   QuestionsBuilder и прочий старт урока).
- [x] События и публикация. Actions больше не ходят в Learn напрямую —
      они публикуют доменные события, слушатели делают побочные эффекты:

      | Action | Событие | Слушатель |
      |---|---|---|
      | `AssignCourseAction` | `CourseAssignmentCreated` | `EnrollOnAssignmentCreated` |
      | `UnassignCourseAction` | `CourseAssignmentRemoved` | `UnenrollOnAssignmentRemoved` |
      | `EditCourseThemeAction` | `CourseChanged` | `RecalculateOnCourseChanged` |
      | `EditCourseLessonAction` | `CourseChanged` | `RecalculateOnCourseChanged` |
      | `EditCourseHeaderAction` | — (шапка не меняет состав) | — |
      | `DeleteCourseAction` | через `UnassignCourseAction` | — |

      **Три события были пустыми файлами** (MCP-сбой при создании):
      `CourseAssignmentCreated`, `CourseAssignmentRemoved`, `CourseChanged` —
      пересозданы. Без них подписка в `CourseServiceProvider::boot()`
      молча не срабатывала.

> **Почему `EditCourseHeaderAction` без `CourseChanged`:** completion —
> доля пройденных уроков, grade — среднее по урокам. Шапка их не меняет.
> Порог допуска влияет на будущие пуски, но не на уже посчитанные оценки.
> Пересчитывать нечего — лишняя фоновая нагрузка на все назначения.
>
> **`DeleteCourseAction` теперь удаляет курс через `UnassignCourseAction`.**
> Правило «что делать с прохождениями при снятии» должно быть в одном
> месте, иначе назначения остались бы сиротами (FK на курс).
>
> **Транзакция — по-прежнему на вызывающем.** Событие публикуется внутри
> неё, слушатели (`ShouldQueueAfterCommit`) ждут коммита. Если обернуть
> `AssignCourseAction` в транзакцию не удалось — прохождений не будет,
> хотя назначение сохранится. Это известное ограничение, см. §12.
- [x] `EditCourseHeaderAction` проверяет таксономию уроков при смене `tax_id`.
      Двухуровневая проверка:
      1. домен: `countLessons() > 0` → `CourseTaxonomyChangeDenied::hasLessons()`;
      2. Application: даже у пустого курса все уроки с заданной веткой должны
         лежать в поддереве целевой таксономии
         → `CourseTaxonomyChangeDenied::lessonsOutsideTarget()`.
      Уроки без ветки смену не блокируют.
      Для батч-проверки добавлен `LessonReaderInterface::taxonomyIdsOf()`.
- [x] Транзакции в Application через порт `TransactionManagerInterface`
      (`app/Application/Shared/`), реализация
      `Infrastructure/Shared/EloquentTransactionManager` (поверх
      `DatabaseManager`, не фасада `DB`). Биндинг — в `AppServiceProvider`
      рядом с `Clock`.
      Транзакцию открывают: `AssignCourseAction`, `UnassignCourseAction`,
      `DeleteCourseAction`. `EditCourse*` пишут в одну БД — транзакция уже
      внутри `EloquentCourseRepository::save()`.
      `ShouldQueueAfterCommit` у слушателей теперь обоснован: события
      публикуются ВНУТРИ транзакции.

> **Почему интерфейс, а не `DB::transaction()` в Action:** Application не
> должен зависеть от Laravel (тесты, CLI). При этом граница транзакции —
> часть бизнес-правила, а не деталь HTTP-слоя: в контроллере её забудут.
>
> **Вложенных транзакций избегаем.** `DeleteCourseAction` удаляет назначения
> inline через `UnassignCourseAction::removeAssignment()`, а не вызывает
> `UnassignCourseAction::execute()` в цикле — иначе получилось бы по
> SAVEPOINT на каждого ученика. Правило «удалить назначение + опубликовать
> `CourseAssignmentRemoved`» живёт в двух местах и продублировано
> комментариями в обоих файлах.
>
> **Ограничение: транзакция покрывает одно соединение.** Пока курсы и
> `learns` в одной БД — `AssignCourseAction` атомарен. Переезд Learn на
> отдельную БД сделает назначение без прохождений возможным, и это никак не
> проявится. Лечится outbox-паттерном, не транзакцией.
- [x] Тесты §19 — покрыты ВСЕ Actions (Create/List/Edit{Header,Theme,Lesson}/
      Assign/Unassign/Delete/Recalculate{,+ForAll}), три слушателя, стратегии,
      `AdmissionChecker`, `CourseGradeRecalculator`,
      `EloquentTransactionManager`, `LessonReaderAdapter`,
      `EloquentCourseRepository::listForOwner`.
      Инфраструктура: `tests/Support/CourseScene.php` + фейки в
      `tests/Support/Fakes/`.
- [x] `php artisan test --filter=Course` зелёный.
- [x] `php artisan test --filter=Learn` зелёный (не сломали рефакторингом).
      Весь набор: 904 теста (7272 assert).

> **Найденный баг (исправлен).** `EloquentCourseRepository::listForOwner()`
> при `ownerId = null` НЕ ставил фильтр по владельцу — гость видел все чужие
> курсы. Порт не умел выразить «показать всё» отдельно от «только
> системные». Добавлен флаг `$allOwners`, семантика `ownerId = null`
> закреплена в докблоке порта, покрыто тестами
> `EloquentCourseRepositoryListTest`.
- [x] §17.1–17.5 в Learn: трейт `DrainsLearnEvents`, 5 Actions переведены,
      `Learn::recordInitialStatus()` (у новых прохождений событий не было
      вовсе — `recordStatusChange` молча выходил при `id === null`),
      тест `DispatchLearnEventsTest`, `deleteUnstarted`, `started_at`
      (round-trip покрыт `EloquentLearnRepositoryStartedAtRoundTripTest`).
- [x] Права доступа §13.1 переработаны (см. §13.1 и запись ниже).
- [ ] HTTP: ресурс курса (шапка + темы + уроки), листинг, назначение.

> **Переработаны права доступа (§13.1).** Требование: системные курсы
> (`user_id = null`) создаёт только `admin`; редактирование доступно только
> автору курса.
>
> Что было не так:
> - `CourseViewerContext::canEdit()` содержал ветку `seesEverything() → true`,
>   из-за которой `admin`, `methodologist` и `headteacher` правили ЛЮБОЙ
>   курс. Видимость ошибочно приравнивалась к праву правки.
> - `canCreateSystemCourses()` не существовало: создание проверялось через
>   `canEdit`, и методист мог создать системный курс.
>
> Что сделано:
> - `canEdit()` — только по владению (`viewerId === courseOwnerId`).
>   Единственное исключение: системный курс правит `admin`.
> - Добавлен отдельный `canCreate(ownerId)`: обычный курс — только себе
>   (`ownerId === viewerId`), системный — только `admin`.
> - `CourseViewerRole`: `canEditSystemCourses()` сужен до `admin`
>   (было `admin` + `methodologist`), добавлены `canCreate()` и
>   `canCreateSystemCourses()`.
> - `CreateCourseAction` переведён с `canEdit` на `canCreate`.
>
> Итог: `methodologist` и `headteacher` видят все курсы, но правят только
> созданные ими; системные курсы — исключительная зона `admin`. Чтобы
> `admin` правил свой авторский курс, ему нужна роль `teacher` и курс его
> авторства.
>
> **Найденный баг в тестовой инфраструктуре (исправлен).**
> `CourseScene::savedCourseWithLessons(ownerId: null)` трактовал `null` как
> «владелец по умолчанию `UserId(1)`», а не как системный курс — тест на
> системный курс молча получал курс учителя 1. Добавлен явный
> `CourseScene::savedSystemCourse()`, в докблоке старого метода оставлено
> предупреждение. Покрыто тестами `CreateCourseActionTest` (19 тестов,
> матрица прав) и `DeleteCourseActionTest`.

---

## 23. Порядок работ (пошаговый чек-лист для следующей сессии)

Каждый шаг — отдельный коммит, тесты зелёные перед переходом к следующему.

**Шаг 1. Справочник типов уроков**
1. Миграция `create_lesson_types_table` (`code`, `next_lesson_threshold`,
   `color`, `sort`, timestampsTz).
2. Миграция `create_lesson_type_translations_table`.
3. Модели `LessonType`, `LessonTypeTranslation` (§10.4).
4. `LessonTypeSeeder` — 4 записи (§8.2), подключить в `DatabaseSeeder`.

**Шаг 2. Миграции курсов**
1. `create_courses_table` (§14.1).
2. `create_course_themes_table` (§14.2).
3. `create_course_theme_lessons_table` (§14.3).
4. `create_course_assignments_table` (§14.4).
5. Модели §15.

**Шаг 3. Домен**
`app/Domain/Course/ValueObjects/{CourseId, CourseThemeId, CourseAssignmentId,
CourseTitle, CourseDescription, Locale, NextLessonThreshold, TimeGap,
CourseGrade, CourseCompletion, LessonTypeId}` → `Enums/` (если нужны) →
`Entities/{Course, CourseTheme, CourseLesson, FlatLesson}` →
`Entities/CourseAssignment` → `Exceptions/*` (§21) → `Events/CourseChanged`,
`CourseAssignmentCreated`, `CourseAssignmentRemoved`.
Единственный источник значений — enum/VO; проверки в конструкторах.

**Шаг 4. Application**
Порты (§12.4–12.5) → стратегии (§7) → `CourseVisibility`/`CourseViewerContext`
(§13.1) → DTO (§13.5) → Actions (§13.5).

**Шаг 5. Инфраструктура**
Репозитории (`EloquentCourseRepository`, `EloquentCourseAssignmentRepository`),
`EloquentCourseContextResolver`, `EloquentLessonInfoAdapter`,
`EloquentLearnStateAdapter`, `LearnEnrollAdapter`, слушатели (§12.1–12.3).
Биндинги + `config/course.php` (§16).

**Шаг 6. Learn-интеграция**
План §17.4, шаги 1–8. **До** подключения слушателя курса.

**Шаг 7. HTTP**
Контроллеры + ресурсы + маршруты. Транслировать `InvalidArgumentException`/
доменные исключения в 422/403/404.

**Шаг 8. Тесты §19 + прогон**
`php artisan test --filter=Course`, `--filter=Learn`, затем полный прогон.





