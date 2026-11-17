# Спецификация контекста Lessons (Уроки · Блоки · Задания)

> Документ описывает ограниченные контексты **Lessons**, **LessonBlocks**,
> **Tasks** и **TasksProc** — их доменные сущности, инварианты, порты
> Application-слоя и Actions. Всё ниже является нормативным: код домена
> и application-слоя обязан соответствовать этому тексту. При расхождении
> источника правды и документа — исправлять документ и код вместе.
>
> Отдельный документ про сеялки этих контекстов — `docs/lessonsSeed/`.

---

## 1. Назначение и границы

Контексты «уроки и задания» описывают **авторский контент**:

| Контекст      | Отвечает за |
|---------------|-------------|
| `Lessons`     | шапку урока, публикацию, таксономии урока, пересчёт complexity |
| `LessonBlocks`| состав урока: собственные блоки (`own`) и привязанные глобальные задания (`shared`) |
| `Tasks`       | обычные (не процедурные) задания: глобальность, привязка к таксономии, complexity |
| `TasksProc`   | процедурные задания: универсальность (без таксономии), ручная complexity, только admin |

Контексты **не знают**:

- о прохождениях (`Learn`) — только публичный API через id/порты;
- об HTTP, БД, файловом хранилище — только через порты;
- о схеме физических таблиц: таблица `lesson_blocks` играет **две роли**
  (own-блок урока / глобальное задание), но домен это скрывает за сущностями.

### 1.1. Две роли таблицы `lesson_blocks`

| Роль | Признак | Домен |
|------|---------|-------|
| own-блок урока | `lesson_id IS NOT NULL` | `LessonBlocks` (`LessonBlock::own`) |
| глобальное обычное задание | `lesson_id IS NULL`, `is_proc = false` | `Tasks` (`Task`) |
| глобальное процедурное задание | `lesson_id IS NULL`, `is_proc = true` | `TasksProc` (`ProcTask`) |

Позиция задания **в уроке** в физической схеме — в pivot `lesson_lesson_block_as_test`
(`order`). Таксономии задания — в pivot `lesson_block_taxonomy`.

---

## 2. Понятия

| Понятие | Тип | Смысл |
|---------|-----|-------|
| `LessonId` | VO | Идентификатор урока (≥ 0; `0` — сентинел «нет урока») |
| `LessonType` | VO | Тип урока — код справочника `lesson_types.code` (`class_work`, `home_work`, …) |
| `LessonBlockId` | VO | Идентификатор строки `lesson_blocks` (own-блок или задание) |
| `BlockId` | VO | Идентификатор прототипа `blocks` |
| `TaskId` | VO | Идентификатор обычного задания (> 0) |
| `ProcTaskId` | VO | Идентификатор процедурного задания (> 0) |
| `UserId` | VO | Владелец урока/задания |
| `TaxonomyId` | VO | Идентификатор таксономии |
| `Complexity` | VO | Сложность `[0.0, 1.0]` |
| `Lesson` | агрегат | Урок (скалярное состояние + таксономии) |
| `LessonBlock` | сущность | Блок урока ИЛИ глобальное задание (две фабрики: `own` / `shared`) |
| `Task` | агрегат | Обычное задание |
| `ProcTask` | агрегат | Процедурное задание |

---

## 3. Агрегат `Lesson`

Поля: `id (?LessonId)`, `userId (UserId)`, `title`, `description (?string)`,
`locale`, `type (LessonType)`, `saved (bool)`, `published (bool)`,
`complexity (?Complexity)`, `taxonomyIds (list<TaxonomyId>)`.

### 3.0. Тип урока (`type`)

- `type` — **обязательное** поле: VO `App\Domain\Lessons\ValueObjects\LessonType`.
- Хранит **строковый код** из справочника `lesson_types.code`
  (`class_work`, `home_work`, `ind_work`, `control_work`), а не id строки
  справочника и не результат join'а с `course_theme_lessons` (там `type_id`
  — параметр планирования курса, ортогонален полю урока).
- VO валидирует только **форму** кода: непустой, ≤ 32 символов,
  `snake_case` (`^[a-z][a-z0-9_]*$`). Существование кода в справочнике
  проверяет HTTP-слой (`exists:lesson_types,code`), не домен.
- Константа `LessonType::DEFAULT === 'class_work'`; фабрика
  `LessonType::default()`.
- Физически: `lessons.type` — `NOT NULL DEFAULT 'class_work'`
  (миграция `2026_11_26_100000_add_type_to_lessons_table`); индекс
  `lessons_type_idx`. FK на `lesson_types` **нет** намеренно: тип —
  значение-код, справочник пересидируется независимо от уроков.
- Справочник для выбора в UI (`code` + локализованные `title`/`description`)
  отдаётся ключом `lesson_types`: в карточке — `GET /api/lessons/{id}` (§8.2.1),
  в списке — `GET /api/lessons` (§8.2.2).

### 3.1. Фабрики

- `Lesson::draft(UserId, locale, ?LessonType $type = null)` — пустой черновик
  (`saved=false`, `published=false`, `title=''`, `complexity=null`,
  таксономии пусты). `$type === null` → `LessonType::default()` (`class_work`).
- `Lesson::fromPersistence(...)` — реконструкция из хранилища; `type` обязателен.

### 3.2. Инварианты

1. Опубликованный урок нельзя **структурно** менять (добавлять/перемещать
   own-блоки) — `assertNotPublishedForStructureChange()` → `LessonIsPublished`.
2. `complexity` — **производная** от complexity привязанных заданий
   (см. §3.3); вручную не задаётся.
3. Владелец — единственный, кто может менять; admin-override проверяется
   в Application (`assertCanBeEditedBy(userId, isAdmin)`).
4. `type` обязателен всегда; **сменить** тип можно только у неопубликованного
   урока. «Опубликован» — строго `published === true`. Правило живёт в
   агрегате: `Lesson::update(...)` при заданном `type` и `published === true`
   бросает `LessonIsPublishedEdit` → HTTP 422
   (`LessonController::save` не дублирует проверку). Снятие публикации в том
   же запросе смену типа **не** открывает: снятие публикации атомарно (п.5).
5. Редактирование по состоянию `published` (единое правило для всех полей
   шапки — `title`, `description`, `locale`, `type`, `saved`, `taxonomy_ids`):
   - `published = true` + `published = false` в запросе → **атомарное снятие
     публикации**: применяется только `published = false`, все остальные
     поля запроса игнорируются (фронт вправе отправить объект урока целиком).
     Содержимое меняется следующим запросом.
   - `published = true` без `published = false` (в том числе с любыми
     правками) → `Lesson::update()` → `LessonIsPublishedEdit` → HTTP 422,
     ничего не сохраняется. Молчаливого no-op нет: раньше такой `PUT`
     возвращал 200 без изменений, из-за чего клиент не видел отказа.
   - `published = false` → урок редактируется нормально (включая публикацию
     тем же запросом).
   - Состав блоков (`blocks`) меняется только у неопубликованного урока:
     `SaveLessonAction` не вызывает `SyncBlocksAction`, если после обновления
     урок опубликован; снятие публикации состав блоков не трогает (§8.3).

### 3.3. Расчёт complexity

`Lesson::recalculateComplexity(array $taskComplexities)` → `Complexity::averageOf()`.
Правила (перенесены 1:1 из модели):

- учитываются **только задания** (`pivot`), собственные info-блоки — нет;
- задания с `complexity = null` игнорируются;
- если валидных значений нет — `complexity = null`.

Физически пересчёт триггерится `saving`-хуком модели `Lesson`
(см. `App\Models\Lesson::booted`): при любом `save()` модели complexity
пересчитывается из свежего pivot. Именно поэтому после `attachToLesson`
достаточно вызвать `LessonRepository::save(getById(...))`.

---

## 4. Сущность `LessonBlock`

Одна сущность — две роли, различие задаётся фабриками и флагом `isGlobal()`.

### 4.1. Фабрики

- `LessonBlock::own(userId, blockId, lessonId, order, settings)` — собственный
  блок урока (`lesson_id = lessonId`).
- `LessonBlock::shared(userId, blockId, settings, complexity, isProc)` — глобальное
  задание (`lesson_id = NULL`); `isProc` определяет домен-владелец (Tasks/TasksProc).

### 4.2. Поведение

- `replaceSettings(array)` — заменить settings (без пересчёта бизнес-полей);
  используется инфраструктурой после синхронизации file-sets.
- `reorder(int)` — позиция own-блока.
- `isGlobal()` — признак «глобальное задание» (`lesson_id IS NULL`).

Настройки (`settings`) — непрозрачный массив, привязанный к прототипу `Block`
по `blockId`. Нормализация и синхронизация file-sets — забота репозитория
(порт `LessonBlockRepositoryInterface`).

---

## 5. Агрегат `Task` (обычное задание)

### 5.1. Инварианты

1. **Глобальность**: не принадлежит ни одному уроку (`lesson_id IS NULL`).
2. **Таксономия обязательна**: минимум одна запись `lesson_block_taxonomy`
   (`TaxonomyRequired`).
3. Владелец может менять; admin — override (`assertCanBeModifiedBy`).
4. Если задание уже используется в уроках, **менять набор таксономий нельзя**
   (`TaskInUse::taxonomyChangeDenied`) — иначе нарушится согласованность уроков.

### 5.2. `update(...)`

Возвращает `bool` — изменилась ли complexity (сигнал «пересчитать уроки»).
`taxonomyTouched`/`complexityTouched` — присутствовало ли поле в запросе.

---

## 6. Агрегат `ProcTask` (процедурное задание)

### 6.1. Инварианты

1. **Нет привязки к таксономии** — принципиально: генератор подбирает параметры
   по `test_resolver` раздела урока, а не по таксономии задания.
2. `complexity` управляется вручную: по ней генератор выбирает сложность задачи.
3. Создавать/менять может **только admin** (`ProcTaskAccessDenied`).

Именно из-за отсутствия таксономии `Tasks` и `TasksProc` — **разные домены**,
хоть физически лежат в одной таблице.

---

## 7. Порты (Application-слой)

Регистрация биндингов — `AppServiceProvider::register()`.

> ⚠️ В проекте **два разных** порта с именем `LessonRepositoryInterface`:
> - `App\Application\Learn\Ports\LessonRepositoryInterface` — read-only для Learn
>   (`isPublished`), реализация `EloquentLessonRepository`;
> - `App\Application\Lessons\Ports\LessonRepositoryInterface` — write-side,
>   реализация `EloquentLessonAggregateRepository`.
> Не путать.

### 7.1. `App\Application\Lessons\Ports\LessonRepositoryInterface`

| Метод | Назначение |
|-------|------------|
| `save(Lesson): Lesson` | INSERT/UPDATE строки урока + синхронизация таксономий |
| `getById(LessonId): Lesson` | загрузка; отсутствие → `LessonNotFound` |
| `findById(LessonId): ?Lesson` | nullable-вариант |
| `findOwnerId(LessonId): ?UserId` | владелец без полной загрузки |
| `taxonomyIdsOf(LessonId): list<int>` | таксономии урока |
| `expandTaxonomiesWithDescendants(list<int>): list<int>` | раскрытие по дереву (`WITH RECURSIVE`) |
| `pluckUserOwnedLessonIds(UserId, list<int>): list<int>` | контроль владения набором |
| `pluckAllIds(): list<int>` | **dev/seeding** — все id (включая soft-deleted) |
| `delete(LessonId): void` | **dev/seeding** — forceDelete; отсутствие — не ошибка |

### 7.1.1. `App\Application\Lessons\Ports\LessonTypeQueryInterface`

Read-side справочника `lesson_types` для выбора `type` в интерфейсе.
HTTP-слой к таблице справочника **не обращается** — получает готовые DTO.

| Метод | Назначение |
|-------|------------|
| `allOrdered(): list<LessonTypeOption>` | все типы по `sort`, затем id; `title`/`description` локализованы (текущая локаль → fallback → первая доступная) |

Реализация — `App\Infrastructure\Persistence\Queries\EloquentLessonTypeQuery`
(переводы грузятся только для текущей локали и `app.fallback_locale`).
`LessonTypeOption` — readonly-DTO Application-слоя
(`code`, `title`, `description`, `color`, `sort`, `next_lesson_threshold`, `time_gap`), не модель.

### 7.2. `App\Application\LessonBlocks\Ports\LessonBlockRepositoryInterface`

| Метод | Назначение |
|-------|------------|
| `save(LessonBlock): LessonBlock` | INSERT/UPDATE блока/задания |
| `getById` / `findById` | загрузка |
| `findAllOrdered(LessonId): list<LessonBlock>` | объединение own + attached, по `order` |
| `findOwn(LessonId): list<LessonBlock>` | собственные блоки |
| `pluckAttachedTaskIds(LessonId): list<int>` | привязанные задания по `pivot.order` |
| `attachToLesson(LessonBlockId, LessonId, int)` | привязка задания на позицию (сдвиг ≥ order) |
| `replaceAttachments(LessonId, list<{id,order}>)` | замена набора привязок |
| `placeOwnAt(LessonBlockId, LessonId, int): LessonBlock` | own-блок на позицию (SAFE_OFFSET) |
| `detachFromLesson(LessonBlockId, LessonId): void` | отвязка задания |
| `delete(LessonBlockId): void` | удаление блока |
| `deleteOwnExcept(LessonId, list<int>): void` | удалить own-блоки, кроме указанных |
| `maxOrderInLesson(LessonId): ?int` | максимальный order (own ∪ pivot) |
| `settingsSchemaForCode(string): array` | схема настроек прототипа |
| `extractPublicDefaults(settings, schema): array` | публичные defaults через `SettingsNormalizer` |
| `syncFileSetsFromSettings(id, settings, code): array` | синхронизация FileSet'ов |
| `cloneFileSetsFromPrototype(id, settings, protoId, code): array` | клон FileSet'ов с прототипа |
| `syncTaskTaxonomies(LessonBlockId, list<TaxonomyId>)` | синхронизация таксономий задания |
| `taskTaxonomies(LessonBlockId): list<TaxonomyId>` | таксономии задания |
| `findBlockPrototype(int): ?{id,code,settings,is_test,is_proc}` | прототип `Block` |
| `pluckAttachedComplexities(LessonId): list<float\|null>` | complexity привязанных (для пересчёта) |
| `isTaskUsedInLessons(LessonBlockId): bool` | используется ли задание в уроках |
| `pluckBlockPrototypeIds(?bool isTest, ?bool isProc): list<int>` | **dev/seeding** — шаблоны |
| `pluckLeafTaxonomyIds(): list<int>` | **dev/seeding** — листья таксономий |
| `taxonomyHasResolver(int): bool` | **dev/seeding** — задан ли `test_resolver` |

### 7.3. `App\Application\Tasks\Ports\TaskRepositoryInterface`

| Метод | Назначение |
|-------|------------|
| `save(Task): Task` | INSERT/UPDATE задания + таксономии |
| `getById` / `findById` | загрузка (`lesson_id IS NULL`, `is_proc=false`) |
| `isUsedInLessons(TaskId): bool` | используется в уроках |
| `pluckUsageLessonIds(TaskId): list<int>` | уроки, где используется |
| `recalculateComplexityOfUsages(TaskId): list<int>` | пересчёт уроков |
| `delete(TaskId): void` | удаление |
| `detachFromAllLessons(TaskId): list<LessonId>` | отвязать от всех уроков |
| `pluckAllIds(): list<int>` | **dev/seeding** — все обычные задания |
| `pluckIdsGroupedByTaxonomy(list<int>): array<int,list<int>>` | **dev/seeding** — пул по таксономиям |
| `deleteAll(): int` | **dev/seeding** — forceDelete всех обычных |

### 7.4. `App\Application\TasksProc\Ports\ProcTaskRepositoryInterface`

| Метод | Назначение |
|-------|------------|
| `save(ProcTask): ProcTask` | INSERT/UPDATE proc-задания |
| `getById` / `findById` | загрузка (`lesson_id IS NULL`, `is_proc=true`) |
| `recalculateComplexityOfUsages(ProcTaskId): list<int>` | пересчёт уроков |
| `pluckAllIds(): list<int>` | **dev/seeding** — все proc-задания |
| `delete(ProcTaskId): void` | **dev/seeding** — forceDelete |

---

## 8. Actions (Application-слой)

Актуальные write-side Actions контекстов (read-only Learn-Actions — отдельный
контекст, см. `learn-domain-spec.md`).

| Action | DTO | Роль |
|--------|-----|------|
| `Lessons\CreateLessonAction` | `CreateLessonData` | создать черновик урока (тип по умолчанию — `class_work`) |
| `Lessons\UpdateLessonAction` | `UpdateLessonData` | шапка + таксономии + `type` (+`published`) |
| `Lessons\SaveLessonAction` | — | полный save с проверкой `LessonRulesChecker` |
| `Lessons\DeleteLessonAction` | — | удалить урок (idempotent) |
| `Lessons\ListLessonsAction` | `LessonListFilter` | каталог уроков (read, `LessonsQueryInterface`) |
| `Lessons\ListLessonTypesAction` | — | справочник типов (read, `LessonTypeQueryInterface`) |
| `LessonBlocks\AddBlockAction` | `AddBlockData` | добавить блок/задание (`own`/`shared`) |
| `LessonBlocks\SyncBlocksAction` | `SyncBlocksData` | привести состав блоков к списку |
| `Tasks\CreateTaskAction` | `CreateTaskData` | создать обычное задание |
| `Tasks\UpdateTaskAction` | `UpdateTaskData` | обновить обычное задание |
| `Tasks\DeleteTaskAction` | — | удалить обычное задание |
| `TasksProc\CreateProcTaskAction` | `CreateProcTaskData` | создать proc-задание (admin) |
| `TasksProc\UpdateProcTaskAction` | `UpdateProcTaskData` | обновить proc-задание (admin) |

### 8.1. `AddBlockData` (расширение для seeding)

```
AddBlockData(
    int       $blockId,        // id прототипа Block
    ?int      $order = null,   // null — в конец
    bool      $isAdmin = false,// admin-override прав + смягчение publish-гейта
    ?array    $settings = null // null — defaults прототипа
                               // задано — прогон через extractPublicDefaults
)
```

`AddBlockAction::__invoke(LessonId, UserId, AddBlockData)`:

1. `assertCanBeEditedBy(actor, isAdmin)`.
2. publish-гейт: при `isAdmin=false` — `LessonIsPublished` для опубликованного;
   при `isAdmin=true` (seeding/dev) — гейт пропускается.
3. `findBlockPrototype(blockId)` → `BlockId`, `code`, прототип-settings.
4. Если `$data->settings` заданы — `extractPublicDefaults($data->settings, proto.settings)`,
   иначе `proto.settings`.
5. `is_test → shared` (глобальное задание через `LessonBlock::shared` + `attachToLesson`),
   иначе `own` (`LessonBlock::own` + file-sets + `placeOwnAt`).
6. Post-save: `LessonRepository::save(getById(...))` → `saving`-хук пересчитывает complexity.

### 8.2. `CreateLessonAction` / `CreateLessonData`

```
CreateLessonData(
    ?string $locale = null,    // null — app.locale (владелец решает в HTTP)
    ?string $type   = null     // code из lesson_types; null — class_work
)
```

- `type`, если задан, прогоняется через VO `LessonType` (форма кода);
  `null` → `LessonType::default()`.
- HTTP `POST /api/lessons/make`: `type` — `sometimes|string|max:32|exists:lesson_types,code`.
  Не передан → урок создаётся как `class_work`.

### 8.2.1. Справочник типов в ответе `GET /api/lessons/{id}`

`LessonController::show` дополнительно отдаёт список доступных типов для
select'а — ключ **`lesson_types`** в `LessonResource`:

```json
{
  "id": 12,
  "type": "home_work",
  "...": "...",
  "lesson_types": [
    {"code": "class_work",  "title": "Классная работа", "description": "...", "color": "#4CAF50", "sort": 10},
    {"code": "home_work",   "title": "Домашняя работа", "description": "...", "color": "#2196F3", "sort": 20}
  ]
}
```

- Источник — `ListLessonTypesAction` → `LessonTypeQueryInterface::allOrdered()`
  (таблица `lesson_types`, порядок `sort`, затем `id`); HTTP-слой получает
  DTO `LessonTypeOption` и сериализует его `LessonTypeResource`.
- `title`/`description` локализованы на момент сборки DTO (текущая локаль →
  `app.fallback_locale` → первая доступная); контекст локали задаёт
  middleware `SetLocale` (`Accept-Language` / `?locale=`).
- `code` — то же значение, что нужно отправить в `type` при
  `POST /api/lessons/make` и `PUT /api/lessons/{id}`.
- Ключ присутствует в ответах `show` (§8.2.1) и `list` (§8.2.2, на уровне
  ответа); в `make`/`save` (`LessonResource`) справочника нет.
- В `show` первым элементом идёт тип самого урока (`optionByCode`), затем
  остальные типы без дубля; в `list` порядок — как у `allOrdered()`.
- Справочник отдаётся независимо от `published`: смену `type`
  фронтенд предлагает только для неопубликованного урока (§3.2, п.4).

### 8.2.2. Справочник типов в ответе `GET /api/lessons`

Список уроков (`LessonController::list`) отдаёт справочник **один раз на
ответ** — рядом с `data` и `meta`, а не в каждом элементе. Порядок ключей
ответа: `data`, `meta`, `lesson_types`.

```json
{
  "data": [
    {"id": 12, "type": "home_work", "...": "..."},
    {"id": 15, "type": "ind_work",  "...": "..."}
  ],
  "meta": {"current_page": 1, "per_page": 20, "has_more_pages": false, "from": 1, "to": 2},
  "lesson_types": [
    {"code": "class_work",  "title": "Классная работа", "description": "...", "color": "#4CAF50", "sort": 10},
    {"code": "home_work",   "title": "Домашняя работа", "description": "...", "color": "#2196F3", "sort": 20}
  ]
}
```

- Источник и локализация — те же, что в §8.2.1 (`ListLessonTypesAction` →
  `LessonTypeQueryInterface::allOrdered()` → `LessonTypeResource`).
- Элементы `data` (`LessonListResource`) содержат только код `type`;
  локализованное название фронт берёт из `lesson_types` по `code`.
- Справочник отдаётся и для пустой страницы (`data: []`) — чтобы фильтр по
  типу не «пропадал» при поиске без результатов.

### 8.2.3. Отдельный справочник `GET /api/lessons/types`

Полный справочник одним ответом — для селектов вне контекста урока
(редактор курса, фильтры, настройки):

```
GET /api/lessons/types
Authorization: auth:sanctum
Accept-Language: ru
```

```json
{
  "data": [
    {
      "id": 1,
      "code": "class_work",
      "title": "Классная работа",
      "description": "Работа выполняется на уроке",
      "color": "#4CAF50",
      "sort": 10,
      "next_lesson_threshold": 0.75,
      "time_gap": 12
    }
  ]
}
```

| Поле | Тип | Смысл |
|------|-----|-------|
| `id` | int | id записи справочника |
| `code` | string | машинный код — то же значение, что `lessons.type` |
| `title` | string \| null | локализованное название |
| `description` | string \| null | локализованное описание |
| `color` | string \| null | UI-подсказка цвета |
| `sort` | int | порядок в селектах (список уже отсортирован: `sort`, затем `id`) |
| `next_lesson_threshold` | float | базовый порог допуска к следующему уроку, `0..1` (`course-api-spec` §0.6) |
| `time_gap` | int | дефолтный разрыв между уроками, целые часы (`lesson_types.time_gap`, `course-api-spec` §0.4) |

- Источник — `ListLessonTypesAction` → `LessonTypeQueryInterface::allOrdered()`,
  ответ — `LessonTypeResource`. Тот же DTO, что в §8.2.1–8.2.2, поэтому формат
  элемента идентичен.
- Локализация — `title`/`description` по текущей локали (`Accept-Language` /
  `?locale=`, middleware `SetLocale`), затем `app.fallback_locale`, затем
  первый доступный перевод. Выбор перевода — в `EloquentLessonTypeQuery`,
  чтобы последний случай не зависел от набора загруженных переводов.
- Пагинации нет: справочник короткий и стабильный.
- Маршрут объявлен до `/api/lessons/{lesson}`: иначе `types` ушёл бы в
  биндинг урока.

### 8.3. `UpdateLessonAction` и `SaveLessonAction`

`PUT /api/lessons/{id}` → `SaveLessonAction` → `UpdateLessonAction`
(+ `SyncBlocksAction`, если урок не опубликован).

- Права: только владелец (`LessonAccessDeniedError::notOwner`).
- Состояние `published` (см. §3.2, п.5):
  - урок `published = true` + `{"published": false}` → атомарное снятие
    публикации; прочие поля запроса (`title`, `description`, `locale`, `type`,
    `saved`, `taxonomy_ids`, `blocks`) **игнорируются** — это позволяет
    фронту отправлять объект урока целиком;
  - урок `published = true` без `published = false` (в том числе с правками)
    → 422 `LessonIsPublishedEdit`, транзакция откатывается, урок не меняется
    (а не прежний молчаливый 200);
  - урок `published = false` → обычное редактирование, публикация тем же
    запросом разрешена.
- Проверка живёт **в агрегате** `Lesson::update()`: при `published === true`
  и `published = false` — только снятие публикации и немедленный выход, иначе
  `LessonIsPublishedEdit`. `UpdateLessonAction` не делает своей проверки и не
  возвращает «урок как есть»; `LessonController::save` тоже не дублирует
  проверку, `guard()` маппит исключение в 422.
- `UpdateLessonData.type` (`null` = не менять) — код из справочника; прогоняется
  через VO `LessonType`. HTTP `PUT /api/lessons/{id}`: `type` —
  `sometimes|string|max:32|exists:lesson_types,code`.
- Смена типа у опубликованного урока запрещена дважды: общим правилом
  публикации и инвариантом 4 агрегата (`type` при `published === true`).
- Блоки: `SaveLessonAction` вызывает `SyncBlocksAction` только если урок
  не опубликован. Снятие публикации в запросе состав блоков **не** меняет —
  `blocks` из такого запроса игнорируются; менять состав следует следующим
  запросом после снятия публикации.

### 8.4. `DeleteLessonAction`

- `__invoke(LessonId)` → `LessonRepository::delete(id)`.
- Чистка own-блоков и pivot — каскадом (модель + FK `CASCADE`, см. миграцию
  `2026_11_22_100000_add_cascade_deletes_for_lessons`).
- Idempotent: отсутствие урока — не ошибка.

---

## 9. Физическая схема и каскады (справочно)

- `lessons.type` — `varchar(32) NOT NULL DEFAULT 'class_work'` + индекс
  `lessons_type_idx` (миграция `2026_11_26_100000_add_type_to_lessons_table`).
  FK на `lesson_types.code` нет: это значение-код, а не ссылка.
- `lessons → learn_statuses` : **CASCADE** (миграция `2026_11_22_...`).
- `lessons → lesson_lesson_block_as_test` : **CASCADE**.
- `lesson_blocks` — `SoftDeletes`; `forceDelete` каскадно чистит FileSet'ы
  (трейты модели `LessonBlock`).
- Позиция блоков требует **двухфазного SAFE_OFFSET-сдвига** (PostgreSQL UNIQUE
  на `order`); реализовано в `EloquentLessonBlockRepository` и
  `BlockOrderPlanner`.

---

## 10. Правила для изменений

1. Любое поведение, описанное здесь, должно совпадать с кодом. Меняешь
   контракт порта/Action — обнови этот документ тем же изменением.
2. Dev-/seeding-методы портов (`pluckAllIds`, `delete`, `deleteAll`,
   `pluckBlockPrototypeIds`, `pluckLeafTaxonomyIds`, `taxonomyHasResolver`,
   `pluckIdsGroupedByTaxonomy`) — **не** часть публичного HTTP-API: не вызывать
   из контроллеров.
3. Не смешивать `Learn\Ports\LessonRepositoryInterface` и
   `Lessons\Ports\LessonRepositoryInterface`.
