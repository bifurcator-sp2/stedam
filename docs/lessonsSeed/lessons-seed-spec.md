# Спецификация сеялок контекста Lessons

> Документ описывает консольные команды, наполняющие домен «уроки и задания»
> тестовыми данными. Все команды работают **через домен** (Actions + порты),
> а не через сырой `DB::table`/хардкод. Это нормативно: при изменении
> сеялок документ обновляется тем же изменением.
>
> Доменная часть (сущности, инварианты, порты, Actions) — `docs/lessons/`.

---

## 1. Принцип: стабильность важнее скорости

Сеялки создают данные **через Application-слой** и доменные инварианты:

- `CreateTaskAction` / `CreateProcTaskAction` / `CreateLessonAction` /
  `UpdateLessonAction` / `AddBlockAction` / `DeleteLessonAction`;
- чтение пулов (prototype-блоки, листья таксономий, задания) — через порты
  (`LessonBlockRepositoryInterface`, `TaskRepositoryInterface`,
  `ProcTaskRepositoryInterface`).

Это медленнее bulk-INSERT/TRUNCATE, но **каждая строка согласована**:
settings нормализованы, file-sets синхронизированы, `order` уникален,
`complexity` урока пересчитана `saving`-хуком модели.

⚠️ Производительность: `domain:seed-lessons` на 10 000 уроков — ощутимо
медленнее прежнего TRUNCATE+bulk. Это осознанный размен (принято решение:
стабильность > скорость).

---

## 2. Состав команд

| Команда | Роль | Создаёт через | Удаляет через |
|---------|------|----------------|----------------|
| `taxonomy:seed-resolvers` | проставить `test_resolver` листьям | (вне контекста lessons) | — |
| `tests:seed-tasks` | обычные задания | `CreateTaskAction` | `TaskRepository::deleteAll` (при `--purge`) |
| `tests:seed-proc` | процедурные задания | `CreateProcTaskAction` | `ProcTaskRepository::delete` |
| `lessons:seed-lotr` | уроки с блоками и заданиями | `CreateLessonAction` / `UpdateLessonAction` / `AddBlockAction` | `DeleteLessonAction` |
| `domain:seed-lessons` | оркестратор (полный ресид) | (делегирует подкомандам) | (делегирует подкомандам) |

---

## 3. `domain:seed-lessons` — оркестратор

Полный ресид домена одной командой. **Без TRUNCATE**: удаление уроков и
заданий идёт доменно, подкомандами.

### 3.1. Опции

| Опция | По умолчанию | Смысл |
|-------|--------------|-------|
| `--tasks=N` | 10000 | сколько обычных заданий создать |
| `--lessons=N` | 10000 | сколько уроков создать |
| `--proc=N` | 20 | сколько процедурных заданий создать |

Всегда требует подтверждения (флага `--force` намеренно нет).

### 3.2. Шаги (строгий порядок)

1. `taxonomy:seed-resolvers --force` — каждому листу таксономии ставится
   существующий `test_resolver`.
2. `lessons:seed-lotr --delete-only --force` — удалить все уроки через
   `DeleteLessonAction` (+ чистка сирот `file_sets`).
3. `tests:seed-tasks --purge --count=N --force` — force-удалить обычные
   задания и создать N через `CreateTaskAction`.
4. `tests:seed-proc --count=P --force` — пересоздать proc-задания через
   `CreateProcTaskAction` (команда сама удаляет старые).
5. `lessons:seed-lotr --keep --count=N --force` — создать уроки через
   `CreateLessonAction`/`AddBlockAction`/`UpdateLessonAction`.

### 3.3. Почему такой порядок

- Шаг 2 удаляет уроки **до** purge-заданий, чтобы не оставалось pivot-ссылок
  на удаляемые задания.
- Шаг 3 создаёт пул обычных заданий, который подхватывает шаг 5.
- Шаг 5 идёт с `--keep`, т.к. уроки уже удалены на шаге 2.

---

## 4. `tests:seed-tasks` — обычные задания

Создаёт N обычных (`is_proc=false`) заданий через `CreateTaskAction`.

### 4.1. Опции

| Опция | По умолчанию | Смысл |
|-------|--------------|-------|
| `--count=N` | 10 | сколько создать |
| `--complexity-min` | 0 | нижняя граница complexity |
| `--complexity-max` | 1 | верхняя граница complexity |
| `--template=CODE` | — | явный code блока; иначе random published `is_test && !is_proc` |
| `--purge` | — | force-удалить все обычные задания перед созданием |
| `--force` | — | без подтверждения |

### 4.2. Пул владельцев

Все пользователи с ролью `teacher` (Spatie) + `1`, `3`.
Морф-класс берётся из `User::getMorphClass()`, таблицы — из
`config('permission.table_names.*')`.

### 4.3. Пул прототипов Block

`blocks` где `published=true && is_test=true && is_proc=false`
(+ фильтр по `--template`, если задан).

### 4.4. Пул таксономий

`LessonBlockRepository::pluckLeafTaxonomyIds()` — листья (узлы без живых детей).
Каждое задание получает **одну** случайную таксономию-лист.

### 4.5. Поведение

- Итерируется `--count` раз; для каждого — `CreateTaskAction(actor, CreateTaskData, false)`.
- Ошибка на конкретном задании ловится, копится в отчёт (первые 10), команда
  возвращает `FAILURE`, если были ошибки.
- `--purge` → `TaskRepository::deleteAll()` (force) до создания.

---

## 5. `tests:seed-proc` — процедурные задания

Приводит БД к состоянию «ровно N proc-заданий с линейной complexity».

### 5.1. Опции

| Опция | По умолчанию | Смысл |
|-------|--------------|-------|
| `--count=N` | 20 | сколько должно стать |
| `--min` | 0 | нижняя граница complexity |
| `--max` | 1 | верхняя граница |
| `--template=CODE` | `text_image` | code proc-шаблона Block |
| `--admin-id` | 1 | от чьего имени (admin) |
| `--submit` | — | пометить шаблон `published=true` |
| `--force` | — | без подтверждения |

### 5.2. Поведение

1. Резолв proc-прототипа: `blocks` где `code=$template && is_proc=true && is_test=true`.
   Нет → `FAILURE` с подсказкой.
2. Purge всех proc-заданий через `ProcTaskRepository::delete` (по `pluckAllIds`).
3. Создание N через `CreateProcTaskAction(actor, CreateProcTaskData, isAdmin=true)`
   в одной транзакции. Complexity — линейный ряд центров на `[min,max]`:
   `min + (max-min)/N * (i + 0.5)`.
4. `--submit` → `blocks.published = true`.

`CreateProcTaskAction` требует `isAdmin=true` (инвариант `ProcTask`).

---

## 6. `lessons:seed-lotr` — уроки

Удаляет (опционально) и сеет LOTR-тематические уроки с блоками и заданиями.

### 6.1. Опции

| Опция | По умолчанию | Смысл |
|-------|--------------|-------|
| `--count=N` | 100 | сколько уроков создать |
| `--min-blocks` | 15 | мин. info-блоков на урок |
| `--max-blocks` | 25 | макс. info-блоков |
| `--min-tests` | 5 | мин. заданий (common+proc) |
| `--max-tests` | 10 | макс. заданий |
| `--keep` | — | не удалять существующие уроки |
| `--delete-only` | — | только удалить уроки (доменно) и выйти |
| `--force` | — | без подтверждения |
| `--stop-file=PATH` | — | появился файл → graceful stop после текущего урока |

### 6.2. Сценарий создания одного урока

Порядок строгий — так соблюдаются инварианты (publish-гейт, order):

1. **Черновик**: `CreateLessonAction(owner, CreateLessonData(locale:'ru'))`.
2. **Шапка + таксономия** (без публикации):
   `UpdateLessonAction(id, owner, UpdateLessonData(title, description,
   descriptionTouched:true, saved:true, taxonomyIds:[leaf]))`.
3. **Info-блоки** (own): `random(min-blocks..max-blocks)` раз
   `AddBlockAction(id, owner, AddBlockData(blockId, order:null, isAdmin:true,
   settings: buildInfoBlockSettings(blockId)))`.
   - `isAdmin:true` смягчает publish-гейт (сеялка).
   - settings строятся из прототипа с подменой контентных default-ов
     (`title`/`summary`/`text`/`htmltext`) LOTR-текстами, затем прогон через
     `extractPublicDefaults`.
4. **Задания**: из пулов берётся `sampleUnique`:
   - если у листа есть `test_resolver` → `tests/2` обычных + остаток proc;
   - иначе → все обычные.
   Привязка: `LessonBlockRepository::attachToLesson(taskId, lessonId, order)`
   со сквозным `order`, начиная после last own-блока
   (`maxOrderInLesson()+1`).
5. **Пересчёт complexity**: `LessonRepository::save(getById(id))` → хук модели.
6. **Публикация**: `UpdateLessonAction(id, owner, UpdateLessonData(published:true))`.

### 6.3. Пул info-прототипов

`LessonBlockRepository::pluckBlockPrototypeIds(isTest:false, isProc:false)` +
фильтр `blocks.published=true` (read-side dev-операция).

### 6.4. Пул заданий

- обычные: `TaskRepository::pluckIdsGroupedByTaxonomy(array_keys(leaves))`;
- proc: `ProcTaskRepository::pluckAllIds()`.

Если обычных заданий нет — команда `FAILURE` с подсказкой
`tests:seed-tasks --force`. Если proc нет — warning (уроки с resolver
получат только обычные).

### 6.5. Распределение уроков

`distribute(count, leaves)` — поровну по листьям таксономий (остаток — первым).
Каждому уроку достаётся один лист как таксономия.

### 6.6. Удаление (`--delete-only` или без `--keep`)

`LessonRepository::pluckAllIds()` → цикл `DeleteLessonAction(id)`.
После — `cleanupOrphanFileSets()` (удаление сирот `file_sets`,
где `model = LessonBlock::class` и нет строки `lesson_blocks`).

### 6.7. Graceful stop

- `pcntl` (Linux/Sail): SIGINT/SIGTERM → завершить текущий урок и выйти;
  повторный сигнал — форс-выход `130`.
- Windows/без pcntl: `--stop-file=PATH` — как только файл появляется,
  `shouldStop()` → выйти после текущего урока.

---

## 7. Текстовый корпус

LOTR-тексты вынесены в трейт
`App\Console\Commands\Concerns\LotrTextCorpus`:

- `$lessonTitles`, `$lessonDescriptions` — шапки уроков;
- `$blockTitles`, `$blockSummaries`, `$blockSentences` — контент info-блоков;
- `randomPlainText()`, `randomHtml()`, `pick()`.

---

## 8. Зависимости и предусловия

- `taxonomy:seed-resolvers` должен существовать (используется оркестратором).
- Прототипы в `blocks`:
  - info-блоки: `is_test=false`, `published=true` — иначе `lessons:seed-lotr` `FAILURE`;
  - обычные задания: `is_test=true`, `is_proc=false`, `published=true`;
  - proc-задания: `is_test=true`, `is_proc=true` (по умолчанию `text_image`).
- Пользователи `1`, `3` или роль `teacher` должны существовать.
- Листья таксономий должны существовать (`pluckLeafTaxonomyIds`).

---

## 9. Как запускать (Sail)

```bash
# Полный ресид (интерактивно)
./vendor/bin/sail artisan domain:seed-lessons

# Малый прогон для проверки
./vendor/bin/sail artisan domain:seed-lessons --tasks=100 --lessons=50 --proc=10

# Только задания
./vendor/bin/sail artisan tests:seed-tasks --count=100 --purge --force
./vendor/bin/sail artisan tests:seed-proc  --count=20 --force

# Только уроки
./vendor/bin/sail artisan lessons:seed-lotr --count=20 --keep --force

# Удалить уроки, не создавая
./vendor/bin/sail artisan lessons:seed-lotr --delete-only --force
```

---

## 10. Правила для изменений

1. Сеялки **обязаны** использовать Actions/порты домена, а не прямой SQL
   на запись бизнес-данных. Исключение — обслуживание сирот (`file_sets`) и
   read-side фильтры (например, `blocks.published`), где доменного метода нет.
2. Порядок шагов `domain:seed-lessons` менять только вместе с обоснованием
   (см. §3.3).
3. При добавлении нового порт-метода для seeding — дописать его в таблицу
   §7 документа `docs/lessons/` и в §2 здесь.
