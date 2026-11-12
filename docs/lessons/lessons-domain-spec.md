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
`locale`, `saved (bool)`, `published (bool)`, `complexity (?Complexity)`,
`taxonomyIds (list<TaxonomyId>)`.

### 3.1. Фабрики

- `Lesson::draft(UserId, locale)` — пустой черновик (`saved=false`,
  `published=false`, `title=''`, `complexity=null`, таксономии пусты).
- `Lesson::fromPersistence(...)` — реконструкция из хранилища.

### 3.2. Инварианты

1. Опубликованный урок нельзя **структурно** менять (добавлять/перемещать
   own-блоки) — `assertNotPublishedForStructureChange()` → `LessonIsPublished`.
2. `complexity` — **производная** от complexity привязанных заданий
   (см. §3.3); вручную не задаётся.
3. Владелец — единственный, кто может менять; admin-override проверяется
   в Application (`assertCanBeEditedBy(userId, isAdmin)`).

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
| `Lessons\CreateLessonAction` | `CreateLessonData` | создать черновик урока |
| `Lessons\UpdateLessonAction` | `UpdateLessonData` | шапка + таксономии (+`published`) |
| `Lessons\SaveLessonAction` | — | полный save с проверкой `LessonRulesChecker` |
| `Lessons\DeleteLessonAction` | — | удалить урок (idempotent) |
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

### 8.2. `UpdateLessonAction`

- Права: только владелец (`LessonAccessDeniedError::notOwner`).
- Опубликованный урок: применяется только если запрос снимает публикацию
  (`published=false`); иначе — no-op (возврат как есть).

### 8.3. `DeleteLessonAction`

- `__invoke(LessonId)` → `LessonRepository::delete(id)`.
- Чистка own-блоков и pivot — каскадом (модель + FK `CASCADE`, см. миграцию
  `2026_11_22_100000_add_cascade_deletes_for_lessons`).
- Idempotent: отсутствие урока — не ошибка.

---

## 9. Физическая схема и каскады (справочно)

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
