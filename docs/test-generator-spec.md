# Спецификация подсистемы TestGenerator (генерация заданий)

> Документ описывает подсистему генерации учебных заданий: слои, контракты,
> правила обнаружения генераторов, детерминизм сидов, HTTP-контракты и
> инварианты. Всё, что ниже, является нормативным: код обязан соответствовать
> этому тексту. При расхождении источника правды и документа — исправлять
> документ и код вместе.

---

## 1. Назначение и границы

**TestGenerator** — подсистема, порождающая учебные задания (математические
задачи) по идентификатору темы и уровню сложности. Задание — это тройка
«условие / эталонное решение / ответ», пригодная для показа ученику и для
проверки его ответа.

Подсистема **отвечает за**:

- обнаружение и каталогизацию доступных генераторов;
- генерацию задания по resolver-пути (`math.algebra.equations.linearEquation`);
- детерминизм: один и тот же сид даёт одно и то же задание;
- предоставление каталога доступных тем для витрины/frontend.

Подсистема **не отвечает за**:

- хранение заданий между прохождениями (материализация — в контексте Learn,
  см. `docs/learn-domain-spec.md` §7.8);
- проверку ответа ученика (это домен Learn);
- привязку «узел дерева таксономий → генератор» (хранится в
  `taxonomies.test_resolver`, разыменовывается через `TestResolverMap`);
- доступ к БД, Redis, S3 в доменной части.

---

## 2. Слои и правило зависимостей

Подсистема разделена на две части. **Направление зависимостей — только
из инфраструктуры в домен**, не наоборот.

### 2.1. Домен — `app/Domain/Blocks/TestGenerator/`

Ни одного обращения к Laravel: ни `app()`, ни фасадов, ни `config()`.

| Файл | Роль |
|---|---|
| `Contracts/MathProblemGeneratorInterface` | Контракт конкретного генератора задания |
| `DTO/MathProblem` | Иммутабельный результат генерации |
| `Random/Seed` | Детерминированные сиды |
| `Ports/ProblemGeneratorInterface` | Порт генерации «по пути» |
| `Ports/GeneratorCatalogInterface` | Порт каталога (что доступно) |
| `Services/GenerateProblemService` | Сценарий: валидация входа → делегирование порту |

### 2.2. Инфраструктура — `app/Infrastructure/Blocks/TestGenerator/`

| Папка | Роль |
|---|---|
| `Generators/**` | Конкретные генераторы + `GeneratorBase` |
| `Discovery/GeneratorDiscovery` | Сканирование папки, вычисление путей, кэш |
| `Resolvers/TestResolverMap` | Карта «путь → класс» из discovery |
| `Support/PathResolver` | Обратные преобразования путь ↔ FQCN |
| `Support/GeneratorOptions` | Списки для Filament Select |
| `Support/KaTeX` | Форматирование формул |
| `Arithmetic/*` | Парсер и решатель арифметических выражений |
| `Container/FilesystemGeneratorCatalog` | Реализация `GeneratorCatalogInterface` |
| `Container/InMemoryProblemGenerator` | Реализация `ProblemGeneratorInterface` |

**Инвариант.** Класс, лежащий в `app/Domain/` этой подсистемы, не может
импортировать ничего из `app/Infrastructure/`. Обратное разрешено.

---

## 3. Понятия

| Понятие | Тип | Смысл |
|---|---|---|
| Resolver-путь | строка | Идентификатор генератора: `math.algebra.equations.linearEquation` |
| Предмет (subject) | строка | Первый сегмент пути: `math` |
| Тема (topic) | строка | Второй+ сегменты пути: `algebra.equations.linearEquation` |
| Генератор | класс | Реализация `MathProblemGeneratorInterface` |
| `MathProblem` | DTO | Результат: `question`, `solution`, `answer`, `topic`, `difficulty` |
| Сид | int | Детерминированный ключ генерации |
| Сложность (difficulty) | float | `0.0 … 1.0` |

### 3.1. Формат resolver-пути

```
<subject>.<тема>.<подтема>….<лист>
```

- Сегменты — результат `Str::camel()` от имён папок и класса.
- Суффикс `Generator` в имени класса отбрасывается.
- Пример: файл
  `Generators/Math/Algebra/Equations/LinearEquationGenerator.php`
  → `math.algebra.equations.linearEquation`.
- Путь **не** хранится в коде генератора — он вычисляется из расположения
  файла (см. §5).

---

## 4. Контракты

### 4.1. `MathProblemGeneratorInterface` (домен)

```php
public function generate(float $difficulty, ?Randomizer $rng = null): MathProblem;
```

Правила:

- `difficulty` вне `[0.0, 1.0]` → `InvalidArgumentException`;
- вся случайность берётся **только** из `$rng`; при `null` — несидированный;
- `question` и `solution` — в разметке KaTeX auto-render
  (inline `\( ... \)`, display `\[ ... \]`);
- `answer` — строка без разметки, пригодная для сравнения.

### 4.2. `ProblemGeneratorInterface` (порт, домен)

```php
public function generate(string $path, float $difficulty, ?Randomizer $rng = null): MathProblem;
public function supports(string $path): bool;
```

- Делегирует конкретному генератору по пути;
- `difficulty` валидируется на уровне `GenerateProblemService`;
- путь нормализуется (обрезка `.` и пробелов);
- неизвестный путь → `InvalidArgumentException` со списком доступных.

### 4.3. `GeneratorCatalogInterface` (порт, домен)

```php
public function availablePaths(): array;      // list<string>
public function pathToClass(): array;         // array<string, class-string>
public function matchedPath(string $path): ?string;
public function subjectFor(string $path): string;
```

- `availablePaths()` — отсортированный список всех путей;
- `matchedPath()` — точное совпадение; `null` — если такого нет;
- `subjectFor()` — первый сегмент пути.

### 4.4. `MathProblem` (DTO)

Поля и инварианты:

| Поле | Инвариант |
|---|---|
| `question` | непустая строка после `trim()` |
| `solution` | непустая строка после `trim()` |
| `answer` | непустая строка после `trim()` |
| `topic` | непустая строка |
| `difficulty` | `0.0 ≤ d ≤ 1.0` |

Нарушение — `InvalidArgumentException` в конструкторе.

---

## 5. Обнаружение генераторов

### 5.1. Правило «добавил файл — он доступен»

Новый генератор регистрируется **самим фактом существования файла**:

1. Класс лежит под `app/Infrastructure/Blocks/TestGenerator/Generators/`;
2. реализует `MathProblemGeneratorInterface`;
3. не абстрактный и инстанцируемый;
4. имя класса уникально по пути (иначе `RuntimeException` о дубликате).

Ручной регистрации нет. Никаких правок конфигов, провайдеров, списков.

### 5.2. Алгоритм `GeneratorDiscovery`

1. Рекурсивно обходит `$basePath`;
2. FQCN = `$baseNamespace` + относительный путь файла;
3. `class_exists()` → `ReflectionClass` → проверка интерфейса и
   инстанцируемости;
4. Путь: папки → `Str::camel()`, класс → отбросить `Generator` → `Str::camel()`;
5. Сортировка `ksort` по путям;
6. Два класса на один путь → `RuntimeException` с указанием обоих.

### 5.3. Кэш

- Результат `scan()` кладётся в `cache()->remember()`;
- TTL — 3600 секунд;
- **Ключ обязан включать `md5($baseNamespace)`**;
- Причина: смена слоя/namespace (например, `Domain` → `Infrastructure`)
  не должна переиспользовать устаревшую карту классов.

Сброс вручную:

```bash
php artisan cache:forget test_generator.discovered_generators
```

### 5.4. Проставление пути генератору

`GeneratorBase` **не** обращается к сервис-контейнеру. Путь проставляется
фабрикой при регистрации:

```php
$generator = $app->make($class);
if ($generator instanceof GeneratorBase) {
    $generator->withPath($path);
}
```

`GeneratorBase::path()` без назначенного пути → `RuntimeException`.
Генератор, вызываемый без `withPath()`, считается ошибкой конфигурации.

---

## 6. Детерминизм (сиды)

### 6.1. Правило

Серия заданий идентифицируется парой `(user_id, learn_id)`. Задание `i`
внутри серии получает собственный сид от `(user_id, learn_id, i, attempt)`.

Следствия (нормативные):

- задание `i` не зависит от количества заданий и от соседей;
- добавление задания в конец не меняет предыдущие;
- тот же ученик на том же прохождении получает ту же серию;
- повторный старт того же `learn_id` даёт ту же серию.

### 6.2. Формат сида

```
crc32("tg:v{schema}:{userId}:{learnId}:{index}:{attempt}")
```

- Префикс `tg:` и разделитель `:` **обязательны** — иначе `(12,3)` и `(1,23)`
  дают одинаковую строку `123`;
- `Seed::SCHEMA_VERSION` меняется при смене логики генерации, чтобы старые
  задания не «переехали» на новые значения;
- `Randomizer` мутабелен и не клонируется: контракт — «один сид (int) → один
  Randomizer», переиспользовать инстанс между вызовами нельзя.

### 6.3. Коллизии

Если два подряд задания серии совпали, используется `withAttempt(seed, n)`,
`n = 1..COLLISION_ATTEMPTS` (3 попытки). Исчерпание попыток — задание
принимается как есть (коллизия допустима, но отслеживается).

---

## 7. HTTP-контракты

Все маршруты — в `routes/api.php`, префикс `/api`.

### 7.1. `GET /api/tasks/catalog`

**Контроллер:** `TaskResolversCatalogController`. **Порт:** `GeneratorCatalogInterface`.

Источники: `availablePaths()` + `config('test_generator.subjects')`.

Правила согласования:

- короткий ключ топика в конфиге (`arithmetic`) сопоставляется по префиксу
  resolver-пути (`math.arithmetic.*`);
- тема в конфиге без генераторов → скрывается;
- генератор без записи в конфиге → попадает в группу `other`;
- предмет без единой доступной темы → не показывается.

```json
{
  "default_subject": "math",
  "default_difficulty": 0.5,
  "subjects": [
    { "key": "math", "title": "Математика",
      "topics": [ {"key": "arithmetic", "title": "Арифметика",
                   "difficulty_range": [0, 1]} ] }
  ]
}
```

### 7.2. `GET /api/tasks/resolvers`

**Контроллер:** `ResolversController`. **Зависимость:** `TestResolverMap`.

Отдаёт все зарегистрированные резолверы + «сироты» — пути, встречающиеся в
дереве таксономий, но без генератора.

Параметры: `subject`, `include_nodes`, `include_orphans`.

```json
{
  "default_subject": "math",
  "default_difficulty": 0.5,
  "resolvers": [
    { "value": "math.arithmetic.operations.addition",
      "label": "Сложение", "segments": ["math","arithmetic","operations","addition"],
      "subject": "math", "available": true, "depth": 4,
      "in_config": true, "in_tree": true, "node_count": 1 }
  ],
  "orphans_in_tree": []
}
```

### 7.3. `GET /api/tasks/random`

**Контроллер:** `TaskGenerateController`. **Порты:** оба.

Порядок разрешения пути:

1. `topic` — прямой resolver-путь; неизвестный → `422`;
2. `test_resolver` — через `TestResolverMap::matchedPath()`;
3. `taxonomy_id` — подъём по `parent_id` до ближайшего непустого
   `test_resolver` (защита от циклов — 64 шага);
4. `subject` — случайный путь из пула предмета;
5. глобальный фолбэк — любой доступный путь;
6. пустой пул → `404`.

Предмет в ответе — первый сегмент итогового пути (ответ не «врёт» о предмете
даже при случайном выборе).

### 7.4. `GET /api/tasks/forlearn`

**Контроллер:** `TaskForLearnController`. **Порт:** `ProblemGeneratorInterface`.

Параметры: `taxonomy_id` (обязателен), `count` (1..20, по умолчанию 5),
`difficulty` (фиксированная), либо `base_difficulty` + `step` (лестница
сложности, по умолчанию шаг 0.1), `subject`.

Сложность каждой задачи клампится в `[0.0, 1.0]`.

```json
{
  "resolver": "math.arithmetic.operations.addition",
  "subject": "math",
  "taxonomy": {"id": 5, "title": "Сложение"},
  "count": 5,
  "tasks": [ {"question": "...", "solution": "...", "answer": "12",
              "topic": "...", "subject": "math", "difficulty": 0.4} ]
}
```

### 7.5. Формат задания в ответе (`MathProblemResource`)

| Поле | Тип | Описание |
|---|---|---|
| `question` | string | Условие с inline KaTeX |
| `solution` | string | Эталонное решение с display KaTeX |
| `answer` | string | Чистый ответ без разметки |
| `topic` | string | Resolver-путь |
| `subject` | string | Первый сегмент пути |
| `difficulty` | float | `0.0 … 1.0` |

---

## 8. Привязка узла дерева к генератору

Поле `taxonomies.test_resolver` хранит resolver-путь (или его префикс).

Правило разрешения (`EloquentProcedureResolverPath`):

1. Взять `test_resolver` узла;
2. Если пусто или не совпадает с зарегистрированным путём — подняться к
   `parent_id`;
3. Повторять до корня (`parent_id < 0`) или 64 шагов;
4. Не найдено → `null` (прохождение получит пустой набор заданий).

`TestResolverMap::matchedPath()` — только **точное** совпадение.
`math.algebra.equations` не «наследует» `math.algebra.equations.linearEquation`.

---

## 9. Сборка в контейнере

`TestGeneratorServiceProvider::register()` регистрирует синглтоны:

| Ключ | Реализация |
|---|---|
| `GeneratorDiscovery` | базовый путь `Infrastructure/Blocks/TestGenerator/Generators` |
| `PathResolver` | над discovery |
| `GeneratorCatalogInterface` | `FilesystemGeneratorCatalog` |
| `ProblemGeneratorInterface` | `InMemoryProblemGenerator` (пути проставлены) |
| `GenerateProblemService` | над портом генерации |
| `TestResolverMap` | над discovery |

Правило: HTTP-слой и application-слой зависят **только от портов**, не от
конкретных классов инфраструктуры.

---

## 10. Как добавить новый генератор

1. Создать файл под `Generators/<Subject>/<Topic>/…/XxxGenerator.php`.
2. Наследовать `GeneratorBase`, реализовать `build()`.
3. Использовать только `$rng`, `rnd()`, `pick()`, `num()` для случайности и
   форматирования.
4. Вернуть `MathProblem` с `topic: $this->path()`.

**Готово.** Генератор доступен во всех API. Ничего больше менять не нужно.

Для новой дисциплины дополнительно:

5. Добавить блок в `config('test_generator.subjects')` с `title` и ярлыками
   топиков (для витрины `/api/tasks/catalog`).

---

## 11. Как заменить способ генерации

Подсистема допускает замену источника генераторов без изменения домена и
HTTP-слоя:

1. Реализовать `ProblemGeneratorInterface` и (при необходимости)
   `GeneratorCatalogInterface` в новом адаптере — например, читающем
   генераторы из БД, из внешнего сервиса или из composer-пакета.
2. Зарегистрировать адаптер в `TestGeneratorServiceProvider`.
3. Контроллеры, `GenerateProblemService`, `MathProblem`, HTTP-контракты —
   **не меняются**.

---

## 12. Тесты

| Файл | Покрывает |
|---|---|
| `tests/Unit/TestGenerator/GeneratorSmokeTest.php` | каждый генератор × каждая сложность: `MathProblem` валиден, KaTeX сбалансирован, UTF-8 не битый; детерминизм по сиду; round-trip `PathResolver` |
| `tests/Feature/Api/LearnAnswersHttpTest.php` | сквозной сценарий: старт прохождения → задания → ответы |

Правило: новый генератор автоматически попадает в `GeneratorSmokeTest`
(провайдер данных строится по discovery) — дополнительных тестов на
«регистрацию» не требуется.
