# Спецификация домена Learn (Прохождение урока)

> Документ описывает ограниченный контекст **Learn** («Прохождение урока») как он
> реализован: модель, статусы, переходы, инварианты, ответы-изображения, права
> доступа и порты. Всё, что ниже, является нормативным: код домена и
> application-слоя обязан соответствовать этому тексту. При расхождении
> источника правды и документа — исправлять документ и код вместе.

---

## 1. Назначение и границы контекста

**Learn** — факт прохождения конкретным учеником конкретного урока. Контекст
отвечает за жизненный цикл прохождения: назначение, планирование, старт,
автосохранение ответов, завершение, оценивание и архивацию.

Контекст **не знает**:

- о содержании урока и его блоков (снапшот урока — только хэш; сам снапшот
  хранится вне контекста, в приватном S3);
- о пользователях, ролях и графе связей (приходят снаружи как `ViewerContext`);
- о том, как хранятся изображения-ответы (только ссылки-ключи, blob в домен не попадает);
- о БД, Redis, S3 (доступ только через порты).

---

## 2. Понятия

| Понятие             | Тип                         | Смысл |
|---------------------|-----------------------------|-------|
| `Learn`             | сущность (aggregate root)   | Прохождение: один ученик × один урок |
| `LearnId`           | value object                | Идентификатор прохождения (> 0) |
| `UserId`            | value object                | Идентификатор владельца-ученика |
| `LessonId`          | value object                | Идентификатор урока |
| `LearnStatus`       | enum                        | Стадия жизненного цикла |
| `LearnCategory`     | enum                        | Производная категория для листингов |
| `SnapshotHash`      | value object                | SHA-256 хэш снапшота урока |
| `ScheduleDate`      | value object                | Плановая дата прохождения |
| `Grade`             | value object                | Оценка 0..100 |
| `AnswerImage`       | value object                | Ссылка на изображение-ответ |
| `LearnAnswers`      | value object                | Упорядоченный набор `AnswerImage` |
| `LearnStatusChanged`| доменное событие            | Смена статуса (для логгера статусов) |

---

## 3. Сущность `Learn`

### 3.1. Состояние агрегата

| Поле            | Тип                     | Инварианты |
|-----------------|-------------------------|------------|
| `id`            | `?LearnId`              | `null` до сохранения; после — положительный |
| `userId`        | `UserId`                | владелец-ученик, неизменен |
| `lessonId`      | `LessonId`              | неизменен |
| `status`        | `LearnStatus`           | см. §4 |
| `snapshot`      | `?SnapshotHash`         | обязателен в `in_progress` |
| `grade`         | `?Grade`                | обязателен в `graded` |
| `gradedBy`      | `?UserId`               | `null` — автооценка системой |
| `scheduleDate`  | `?ScheduleDate`         | обязателен в `scheduled`; `null` в `created` |
| `startedAt`     | `?DateTimeImmutable`    | фактический момент старта; `null` до первого `start(snapshot, now)` (в `created`/`scheduled`) |
| `answers`       | `LearnAnswers`          | по умолчанию пусто |
| `createdAt`     | `DateTimeImmutable`     | момент создания |
| `archivedAt`    | `?DateTimeImmutable`    | не-`null` — в архиве |
| `statusDate`    | `DateTimeImmutable`     | время последней смены статуса |
| `completedAt`   | `?DateTimeImmutable`    | заполняется при `→ done` |

### 3.2. Создание (фабрики)

Садятся сразу в определённый статус с корректным набором полей:

- **`startNow(userId, lessonId, snapshot, now)`** → `in_progress`.
  Снапшот обязателен (создаётся до перехода в `in_progress`). `startedAt := now`.
- **`schedule(userId, lessonId, scheduleDate, now)`** → `scheduled`, `scheduleDate` задан.
- **`assign(userId, lessonId, now)`** → `created` (материал курса без даты).

### 3.3. Гидратация (`reconstitute`)

Восстанавливает агрегат из БД **без** порождения доменных событий и проверяет
согласованность:

- `in_progress` ⟹ `snapshot !== null`, иначе `SnapshotMissing`;
- `scheduled` ⟹ `scheduleDate !== null`, иначе `InvalidStatusTransition`;
- `created` ⟹ `scheduleDate === null`, иначе `InvalidStatusTransition`;
- `graded` ⟹ `grade !== null`, иначе `InvalidStatusTransition`.

`startedAt` восстанавливается как есть (опционален, `null` по умолчанию) —
инвариант: для `in_progress` он задан, для `created`/`scheduled` — `null`
(заполняется только при старте).

### 3.4. Идентичность

`withId(LearnId)` возвращает копию с присвоенным id. Повторный вызов при уже
заданном id — `LogicException`. Копия не наследует накопленные события.

---

## 4. Статусы и переходы (`LearnStatus`)

### 4.1. Статусы

```
created → scheduled → in_progress → done → graded
```

### 4.2. Матрица разрешённых переходов

| Из \ В        | created | scheduled | in_progress | done | graded |
|---------------|:-------:|:---------:|:-----------:|:----:|:------:|
| **created**   |    —    |     ✓     |      ✓      |  —   |   —    |
| **scheduled** |    ✓    |     ✓*    |      ✓      |  —   |   —    |
| **in_progress**|   —    |     —     |      —      |  ✓   |   —    |
| **done**      |    —    |     —     |      —      |  —   |   ✓    |
| **graded**    |    —    |     —     |      —      |  —   |   —    |

`✓*` — самопереход `scheduled → scheduled` означает **перенос даты** (`reschedule`)
и допустим только в `scheduled`.

Любой недопустимый переход — исключение `InvalidStatusTransition`.

### 4.3. Свойства статуса

- `isTerminal()` — только `graded`.
- `isEditable()` — только `in_progress` (в этом статусе разрешено автосохранение ответов).
- `isOpen()` — `created` или `scheduled`.
- `values()` — единственный источник значений для CHECK/ENUM в БД.

---

## 5. Операции (переходы и мутации)

Каждый переход обновляет `statusDate` и, если агрегат уже сохранён
(`id !== null`), порождает доменное событие `LearnStatusChanged`.

| Метод | Предусловие | Эффект |
|-------|-------------|--------|
| `start(snapshot, now)` | статус допускает переход в `in_progress` | статус → `in_progress`, `snapshot := snapshot`, `startedAt := now` |
| `scheduleAt(scheduleDate, now)` | переход в `scheduled` разрешён | статус → `scheduled`, `scheduleDate := scheduleDate` |
| `reschedule(scheduleDate, now)` | статус = `scheduled` | `scheduleDate := scheduleDate`, `status → scheduled` (самопереход), `statusDate := now`, событие |
| `cancel(now)` | переход в `created` разрешён | статус → `created`, `scheduleDate := null` |
| `markDone(answers, now)` | переход в `done` разрешён | статус → `done`, `answers := answers`, `completedAt := now` |
| `markGraded(grade, gradedBy, now)` | статус ≠ `graded` и переход разрешён | статус → `graded`, `grade := grade`, `gradedBy := gradedBy` |
| `updateAnswers(answers)` | статус `isEditable()` (`in_progress`) | `answers := answers` (полная замена) |
| `archive(now)` | — | `archivedAt := now`, если ещё не в архиве (идемпотентно) |
| `unarchive()` | — | `archivedAt := null` (идемпотентно) |

Правила:

- `markGraded` в `graded` — `LearnAlreadyGraded`.
- `updateAnswers` вне `in_progress` — `InvalidStatusTransition`.
- Архивация **ортогональна** статусу: доступна из любого статуса, **не меняет**
  статус и `statusDate`.
- `markDone` и `markGraded` не требуют предварительного апдейта ответов —
  ответы передаются явно (для `markDone`).

### 5.1. Отклонение от плана (`startedLateFromPlanInMinutes`)

Вычисляемый геттер (не хранится): разница `startedAt − scheduleDate` в минутах,
округлённая до целого.

- `> 0` — ученик начал **позже** плана; `< 0` — раньше.
- `null` — прохождение ещё не начато (`startedAt === null`) **или** план не
  задавался (`scheduleDate === null`: `created`, отменённое планирование).
- `startedAt` заполняется только при переходе в `in_progress` (`start` /
  `startNow`); повторный старт уже идущего прохождения его не перезаписывает
  (Application не вызывает `start` для `in_progress`).

---

## 6. Категории для листингов (`LearnCategory`)

Производное от статуса и (для `scheduled`) даты относительно «сейчас» в часовом
поясе смотрящего:

| Категория          | Условие |
|--------------------|---------|
| `assigned`         | `created` |
| `in_progress`      | `in_progress` |
| `awaiting_grade`   | `done` |
| `graded`           | `graded` |
| `overdue`          | `scheduled` и дата раньше локального дня |
| `scheduled_today`  | `scheduled` и дата — локальный день «сейчас» |
| `scheduled_future` | `scheduled` и дата позже локального дня |

Хелперы: `isAssigned`, `isInProgress`, `isAwaitingGrade`, `isGraded`,
`isScheduledToday`, `isScheduledFuture`, `isOverdue`, `isArchived`.

Сравнение дней — через `ScheduleDate` по локальной дате в заданной таймзоне
(не по абсолютному времени).
`scheduled` всегда имеет `scheduleDate` (инвариант §14.2, гарантируется
`reconstitute`), поэтому ветка «scheduled без даты» в `category()` недостижима:
срабатывание — программерская ошибка (assertion `LogicException`), а не бизнес-ситуация.

---

## 7. Value objects

### 7.1. `AnswerImage`

Ссылка на изображение-ответ ученика.

- `index: int` — позиция задания (блока) среди блоков урока, **≥ 1**;
- `file: string` — ключ объекта в хранилище (относительно диска), **непустой**.

Нарушение — `InvalidAnswerImage`. Blob в домен не попадает: только ссылка.

### 7.2. `LearnAnswers`

Неизменяемый упорядоченный набор `AnswerImage`.

Инварианты:
- индексы **уникальны** (иначе `InvalidAnswerImage`);
- список **упорядочен по `index` возрастанию**.

Операции:
- `fromImages(list<AnswerImage>)` — валидирующее создание;
- `fromArray(raw)` — восстановление из персистентного формата `[{index,file}]`;
  элементы чужой/старой структуры игнорируются (трактуются как «нет ответа»).
  `index < 1` и нечисловой `index` также **пропускаются** молча и не бросают
  `InvalidAnswerImage`: битые исторические данные не должны ронять гидратацию;
- `empty()`, `isEmpty()`, `images()`, `toArray()`, `jsonSerialize()`, `equals()`.

Семантика обновления — **полная замена** (см. `Learn::updateAnswers`).

### 7.3. `Grade`

- `value: float`, **0 ≤ value ≤ 100** (иначе `InvalidGrade`);
- `fromInt(int)`; сравнение с точностью 0.0001.

### 7.4. `ScheduleDate`

Оборачивает `DateTimeImmutable`. Методы сравнения **дня** — по локальной дате в
таймзоне: `isOnLocalDay`, `isBeforeLocalDay`, `isAfterLocalDay`. Отдельно
`isPast(now)` — сравнение **абсолютного времени** (`value < now`), без `$tz`.

### 7.5. `SnapshotHash`

- SHA-256 в hex: `/^[a-f0-9]{64}$/` (иначе `InvalidSnapshotHash`);
- `fromContent(string)`, `shard(len)`, `equals()`, `__toString()`.

### 7.6. `LearnId` / `UserId` / `LessonId`

Обёртки над положительным целым. `value ≤ 0` — `InvalidId` для всех трёх
(`LearnId`, `UserId`, `LessonId`).

---

## 8. Ответы ученика как изображения (сквозной поток)

**Домен** оперирует только `LearnAnswers` (ссылки `AnswerImage`). Файлы живут
вне домена. Поток сохранения ответов:

```
multipart (answers[i].index, answers[i].img)
      │  HTTP: валидация mime/size
      ▼
RawAnswer{ index, UploadedFile }[]                 (Application DTO)
      │
      ▼
AnswerStorageInterface::replaceAll(LearnId, RawAnswer[]) → AnswerImage[]
      │  (полная замена папки прохождения; resize/convert по конфигу)
      ▼
LearnAnswers::fromImages(...)  →  Learn::updateAnswers()/markDone()
```

Свойства:

- **Идемпотентность по индексу:** повторное сохранение перезаписывает файл
  `{index}`; прочие файлы папки прохождения удаляются (последнее автосохранение
  побеждает).
- **Порядок записи:** сначала файлы в хранилище, затем ссылки в агрегат — в БД
  попадают только реально записанные ключи.
- **Хранилище** (Infrastructure) — публичный диск, шардированная раскладка
  `{prefix}/{learnId % 1000:03}/{learnId}/{index}.{ext}`; обработка
  (pass-through / resize / convert-to-webp) — по конфигу `learn.answers`.
- **heic:** при `transcode=false` сохраняется как `heic` (pass-through); при
  `transcode=true` и `convert_to_webp=false` heic перекодируется в `png`
  (Intervention/GD не умеет кодировать heic); при `convert_to_webp=true` —
  в `webp`.

`LearnAnswers` НЕ содержит бинарных данных — только `file`-ключи.

---

## 9. Доменные события

`LearnStatusChanged(learnId, lessonId, status, statusDate, scheduleDate?)`

- Порождается только для сохранённого агрегата (`id !== null`).
- Накапливается в агрегате; забирается через `pullEvents()` (с очисткой) после
  сохранения.
- Application переводит события во внешний порт `StatusLoggerInterface`
  (источник правды по истории статусов — логгер/таблица `learn_statuses`).

---

## 10. Порты (Application) и роли (Context)

### 10.1. `LearnRepositoryInterface`

| Метод | Назначение |
|-------|-----------|
| `save(Learn): Learn` | `id === null` → INSERT (возвращает копию с id); иначе UPDATE |
| `getById(LearnId): Learn` | `LearnNotFound` при отсутствии |
| `findByIdWithinScope(LearnId, visibleUserIds): ?Learn` | `null` — нет такого **или** вне scope |
| `listWithinScope(visibleUserIds, LearnListFilter): PaginatedResult<Learn>` | постраничный список владельцев из scope |
| `findActiveByUserAndLesson(UserId, LessonId): ?Learn` | активное (не терминальное и **не в архиве**) прохождение; для идемпотентности старта |
| `pluckArchivableIds(status, cutoff, limit): list<int>` | id для системной архивации |

Опорная дата для архивации: `graded → complete_date (fallback status_date)`,
`created/scheduled → created_at`.

### 10.2. Прочие порты

- **`SnapshotResolverInterface::ensureFor(LessonId): SnapshotHash`** — возвращает
  хэш существующего снапшота или создаёт новый (кэш/S3/лок — детали снаружи).
- **`StatusLoggerInterface::log(...)`** — приёмник доменных событий смены статуса.
- **`AnswerStorageInterface`** — сохранение/URL изображений-ответов (см. §8).
- **`LessonRepositoryInterface`** — доступ к урокам (существование и т.п.).
- **`TeacherStudentsQueryInterface`** — список учеников учителя с агрегатами.
- `StatusBufferInterface` / `LearnStatusBufferInterface` — буферизация статусных
  событий (инфраструктурное ускорение записи).

### 10.3. `ViewerContext` (кто смотрит и что можно)

Application-понятие, строится `ContextResolverInterface` из ролей и графа связей.

| Поле | Смысл |
|------|-------|
| `viewerId` | кто смотрит |
| `roles: list<ViewerRole>` | роли, релевантные Learn |
| `visibleUserIds: list<int>` | чьи прохождения видны (сюда всегда входит сам смотрящий) |
| `canGrade` / `canAssign` / `canSchedule` / `canCancel` / `canStart` / `canSave` | права на действия в рамках scope |

- `canSeeUser(id)` — входит ли владелец в scope.
- `isReadOnly()` — все `can*` = `false` (например, родитель).
- **Права независимы.** `canAssign`, `canSchedule`, `canCancel`, `canGrade`
  — отдельные capabilities, хотя в текущем `EloquentContextResolver` все они
  выдаются роли `teacher`. Резолвер может их развести; Application не должен
  полагаться на их эквивалентность (в частности, не гейтить действие проверкой
  «оба права»).
- **Гейт `ScheduleLearnAction` по сценарию:** `scheduleDate === null` (assign,
  `created`) требует `canAssign`; `scheduleDate !== null` (schedule/reschedule,
  `scheduled`) требует `canSchedule`. Наличие одного права не заменяет другое.
- **Завершение (`MarkDoneAction`) гейтится `canSave`:** отдельного `canComplete`
  нет; сохранение ответов и завершение — обе части «выполнения учеником».
- Архивация в контексте **отсутствует** — это системное действие (крон), не
  пользовательское.

`ViewerRole`: `student`, `teacher`, `parent`, `methodologist`, `headteacher`.

`RelationType` (рёбра графа `user_relations.relation`):

| Значение | Ребро |
|----------|-------|
| `teaches` | teacher → student |
| `parents` | parent → student |
| `supervises` | methodologist → teacher |
| `heads` | headteacher → methodologist |

---

## 11. Сводка ошибок домена

| Исключение | Когда |
|------------|-------|
| `InvalidStatusTransition` | недопустимый переход; апдейт ответов вне `in_progress`; отсутствие обязательного поля при `reconstitute` |
| `LearnAlreadyGraded` | `markGraded` при уже `graded` |
| `SnapshotMissing` | `reconstitute` для `in_progress` без снапшота |
| `InvalidGrade` | оценка вне 0..100 |
| `InvalidAnswerImage` | `index < 1`, пустой `file`, дубль индекса |
| `InvalidSnapshotHash` | не SHA-256 hex |
| `InvalidId` | `LearnId` ≤ 0 |
| `LearnNotFound` | прохождение не найдено |
| `LearnAccessDenied` (Application) | действие вне прав/scope `ViewerContext` |
| `LessonNotFound` (Application) | урок не существует |

---

## 12. Application — сценарии (Actions)

| Action | Вход | Смысл |
|--------|------|-------|
| `StartLearnAction` | `StartLearnData` | Старт (идемпотентно по активному прохождению user+lesson), `snapshot` через порт |
| `ScheduleLearnAction` | `ScheduleLearnData` | Назначение/планирование учителем |
| `SaveAnswersAction` | `SaveAnswersData` | Автосохранение ответов (own, `in_progress`, гейт `canSave`) через `AnswerStorage` |
| `MarkDoneAction` | `MarkDoneData` | Завершение; ответы — явно или уже сохранённые |
| `GradeLearnAction` | `GradeLearnData` | Оценка учителем |
| `CancelLearnAction` | `LearnId`, `viewerId` | Отмена планирования |
| `GetLearnAction` | `LearnId`, `viewerId` | Чтение одного прохождения в scope |
| `ListLearnsAction` | `LearnListFilter`, `ViewerContext` | Постраничный список |
| `ListTeacherStudentsAction` | — | Ученики учителя с агрегатами |
| `ListParentChildrenAction` | — | Дети родителя |
| `ListParentLearnsAction` | — | Прохождения детей |
| `ArchiveOldLearnsAction` | `ArchiveCriteria` | Системная архивация по расписанию |
| `GetAnswerUploadConfigAction` | — | Контракт загрузки изображений для фронта |

### 12.1. `LearnListFilter`

`statuses: list<LearnStatus>`, `lessonId?`, `dateFrom?`, `dateTo?`,
`page = 1`, `perPage = 20`, `includeArchived = false`. Scope по пользователям
передаётся отдельно (`ViewerContext`), в фильтре не дублируется.
`includeArchived` управляется query-флагом `include_archived`, но **игнорируется
для read-only ролей** (parent): архив видят только кураторы —
teacher/methodologist/headteacher.

### 12.2. Контракт загрузки изображений

`GET /api/learn/answers/upload-config` → `{ allowed_mimes[], accept, max_bytes,
max_side|null, converted_to_webp }`. Единый источник — `config('learn.answers.*')`,
синхронно с серверной валидацией/обработкой.

---

## 13. Конфигурация изображений (`config/learn.php → answers`)

| Ключ (env) | По умолчанию | Смысл |
|------------|--------------|-------|
| `LEARN_ANSWERS_DISK` | `s3_public` | диск хранилища |
| `LEARN_ANSWERS_PREFIX` | `answers` | корневой префикс раскладки |
| `LEARN_ANSWERS_TRANSCODE` | `true` | выключатель обработки (`false` → pass-through) |
| `LEARN_ANSWERS_RESIZE` | `true` | ресайз по большой стороне (при `TRANSCODE`) |
| `LEARN_ANSWERS_MAX_SIDE` | `1024` | большая сторона |
| `LEARN_ANSWERS_CONVERT_TO_WEBP` | `true` | конвертация в webp (при `TRANSCODE`) |
| `LEARN_ANSWERS_QUALITY` | `80` | качество webp |
| `LEARN_ANSWERS_MAX_BYTES` | `10485760` | макс. размер входящего файла |
| `LEARN_ANSWERS_ALLOWED_MIMES` | `image/png,image/jpeg,image/webp,image/heic,image/bmp` | допустимые mime (через запятую) |

---

## 14. Инварианты (контрольный список)

1. Снапшот есть тогда и только тогда, когда агрегат в `in_progress`.
2. `scheduled` всегда имеет `scheduleDate`; `created` — нет (`cancel` его обнуляет).
3. `graded` всегда имеет `grade`; `gradedBy = null` означает автооценку.
4. `completedAt` заполняется при `→ done`, меняясь только при этом переходе.
5. Автосохранение ответов разрешено только в `in_progress`; замена полная.
6. `LearnAnswers`: индексы ≥ 1, уникальны, упорядочены по возрастанию.
7. `LearnCategory` выводится детерминированно из статуса и (для `scheduled`)
   локальной даты в таймзоне смотрящего.
8. Архивация ортогональна статусу, идемпотентна, не меняет `status`/`statusDate`.
9. Переход в `graded` — терминален; повторная оценка — `LearnAlreadyGraded`.
10. Домен не содержит данных о содержимом урока и о blob изображений — только
    хэш снапшота и ключи файлов.
11. Доступ к прохождениям всегда через `ViewerContext.visibleUserIds`;
    вне scope — «не найдено»/`LearnAccessDenied`.
12. События смены статуса доступны только у сохранённого агрегата.
13. `startedAt` заполняется ровно при переходе в `in_progress`; до старта —
    `null`. Отклонение от плана (`startedLateFromPlanInMinutes`) существует,
    только если заданы и `startedAt`, и `scheduleDate`.
