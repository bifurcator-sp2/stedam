# Резервное копирование и восстановление БД (PostgreSQL + Laravel)

Документ описывает, как в проекте stedam организовать резервное копирование
основной базы `laravel` на двух уровнях:

- **уровень PostgreSQL** — физические и логические бэкапы, WAL, PITR, восстановление
  кластера средствами Postgres;
- **уровень Laravel** — команды `artisan`, миграции/сиды, работа с файловым
  хранилищем (S3/MinIO), безопасные тесты, чек-лист восстановления приложения.

Документ написан по факту инцидента: **2026‑11‑26 18:30 UTC** база `laravel`
была пересоздана с нуля (`migrate:fresh` по `.env`, а не по тестовой БД) —
все строки во всех таблицах пропали, `pg_wal` без архивации не позволил
восстановление, бэкапов не было. Ниже — как сделать так, чтобы это не повторялось.

---

## 0. Текущее состояние (что важно знать)

| Параметр | Значение |
|---|---|
| СУБД | PostgreSQL **17** (`postgres:17`) |
| Контейнер | `stedam-pgsql-1` |
| Том данных | `stedam_sail-pgsql` → `/var/lib/postgresql/data` |
| База | `laravel` (`DB_DATABASE` в `.env`) |
| Пользователь | `sail` / пароль `password` |
| Тестовая база | `testing` (в отдельной БД того же кластера) |
| `wal_level` | `replica` |
| `archive_mode` | **off** |
| `archive_command` | не задан |
| `wal_keep_size` | `0` |
| реплики/слоты | нет (`pg_subscription = 0`) |
| PgBouncer / HA | нет |
| Объектное хранилище | MinIO (`stedam-minio-1`, том `sail-minio`, бакеты `stedam-public`, `stedam-private`) |
| Планировщик | Laravel Scheduler в `stedam-scheduler-1` (`routes/console.php`) |

Вывод: сейчас **ни один** механизм восстановления не работает. Ниже — что включить.

---

## 1. Уровень PostgreSQL

### 1.1 Виды бэкапов и когда что применять

| Вид | Команда | Что даёт | Ограничения |
|---|---|---|---|
| Логический дамп БД | `pg_dump -Fc` | полная копия одной базы, переносимо между версиями | не даёт PITR; время ≈ объёму |
| Логический дамп схемы | `pg_dump -s` | только DDL | — |
| Логический дамп данных | `pg_dump -a` | только COPY | — |
| Дамп всего кластера | `pg_dumpall` | все базы + роли | восстанавливается только целиком |
| Физическая копия | `pg_basebackup` | byte‑for‑byte кластер | нужен `wal_level ≥ replica` |
| WAL‑архив | `archive_mode=on` + `archive_command` | **PITR** (восстановление на момент T) | требует базового бэкапа + непрерывной цепочки WAL |
| Непрерывная репликация | standby + `pg_basebackup -X stream` | горячий резерв, read‑only | отдельный сервер |

Для этого проекта минимальный разумный набор — **ежедневный `pg_dump -Fc`
+ `archive_mode=on` с WAL‑архивом** (или хотя бы `pg_dump` каждого дня
с хранением N дней). Если нужен SLA — физический standby.

### 1.2 Ручной логический бэкап (сразу можно делать)

Дамп одной базы в кастомный формат (сжатый, восстанавливается через `pg_restore`):

```bash
# на хосте WSL
mkdir -p /home/stedam/backups

docker exec stedam-pgsql-1 pg_dump \
  -U sail -d laravel \
  -Fc --no-owner --no-privileges \
  -f /tmp/laravel_$(date +%Y%m%d_%H%M).dump

docker cp stedam-pgsql-1:/tmp/laravel_*.dump /home/stedam/backups/
```

Или сразу в файл на хосте (без промежуточной копии в контейнере):

```bash
docker exec stedam-pgsql-1 pg_dump -U sail -d laravel -Fc \
  | gzip > /home/stedam/backups/laravel_$(date +%F_%H%M).dump.gz
```

Дамп всех баз кластера (роли, глобальные объекты):

```bash
docker exec stedam-pgsql-1 pg_dumpall -U sail | gzip > /home/stedam/backups/all_$(date +%F).sql.gz
```

Проверка целостности дампа:

```bash
docker exec -i stedam-pgsql-1 pg_restore -l < /home/stedam/backups/laravel_XXX.dump.gz 2>/dev/null | head
# или
docker exec stedam-pgsql-1 pg_restore --list /tmp/laravel_XXX.dump | head
```

### 1.3 Автоматический ежедневный бэкап

Способ A — cron на хосте WSL (пользователь `stedam`):

```bash
crontab -e
```

```cron
# Бэкап laravel каждый день в 02:30, хранение 14 дней
30 2 * * * mkdir -p /home/stedam/backups && docker exec stedam-pgsql-1 pg_dump -U sail -d laravel -Fc | gzip > /home/stedam/backups/laravel_$(date +\%F_\%H\%M).dump.gz
# Чистка старых
35 2 * * * find /home/stedam/backups -name 'laravel_*.dump.gz' -mtime +14 -delete
```

Способ B — Laravel Scheduler (удобно, если хотите видеть бэкапы в логах приложения).

> Реализовано в `app/Console/Commands/DbBackupCommand.php`. Сигнатура:
> `db:backup {--connection=} {--disk=} {--path=} {--days=} {--no-prune} {--verify}`.
> Дампы пишутся на диск `local` в `storage/app/private/backups` (см. блок `backup`
> в `config/database.php`), `--verify` проверяет читаемость архива через `pg_restore -l`.
> Помимо расписания (`db:backup --verify` в 01:30, `routes/console.php`) команда
> запускается сервисом `backup` из `compose.yaml` при каждом `sail up`: на локальной
> машине, выключенной ночью, крон в 01:30 не срабатывает, поэтому дамп нужен на старте.
> Сервис one-shot (`restart: 'no'`) и не блокирует остальной стек при падении.

Черновик команды, по которому проектировался подход:

```php
// app/Console/Commands/DbBackupCommand.php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

final class DbBackupCommand extends Command
{
    protected $signature = 'db:backup {--days=14}';

    protected $description = 'Логический бэкап PostgreSQL в storage/app/backups';

    public function handle(): int
    {
        $cfg = config('database.connections.pgsql');
        $dir = storage_path('app/backups');
        @mkdir($dir, 0775, true);

        $file = $dir.'/'.$cfg['database'].'_'.now()->format('Ymd_His').'.dump';

        $result = Process::env(['PGPASSWORD' => (string) $cfg['password']])
            ->timeout(3600)
            ->run([
                'pg_dump',
                '-h', (string) $cfg['host'],
                '-p', (string) $cfg['port'],
                '-U', (string) $cfg['username'],
                '-d', (string) $cfg['database'],
                '-Fc', '--no-owner', '--no-privileges',
                '-f', $file,
            ]);

        if ($result->failed()) {
            $this->error($result->errorOutput());

            return self::FAILURE;
        }

        $this->info('Backup: '.$file.' ('.number_format(filesize($file) / 1024).' KB)');

        // Ротация
        $days = (int) $this->option('days');
        foreach (glob($dir.'/*.dump') ?: [] as $old) {
            if (filemtime($old) < now()->subDays($days)->timestamp) {
                @unlink($old);
            }
        }

        return self::SUCCESS;
    }
}
```

В `routes/console.php`:

```php
Schedule::command('db:backup --days=14')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
```

Требование: `pg_dump` должен быть доступен в контейнере приложения
(`stedam-laravel.test-1`). В образе Sail обычно есть только `psql`‑клиент
для postgres — проверьте:

```bash
docker exec stedam-laravel.test-1 which pg_dump
```

Если нет — либо делайте бэкап из контейнера `stedam-pgsql-1` (способ A),
либо добавьте в `compose.yaml` для `laravel.test` монтирование или установку
`postgresql-client` в Dockerfile. Рекомендую **способ A** — минимум изменений.

### 1.4 Копирование бэкапов за пределы хоста

Бэкап на том же диске ≠ бэкап. Варианты:

- копировать `/home/stedam/backups` на Windows‑диск (`/mnt/c/Backups/stedam/`):

  ```cron
  40 2 * * * cp /home/stedam/backups/laravel_$(date +\%F)*.dump.gz /mnt/c/Backups/stedam/ 2>/dev/null
  ```

- отправлять в MinIO (`stedam-minio-1`) через `mc`:

  ```bash
  docker exec stedam-minio-1 mc cp /data/...  # или
  docker run --rm --network stedam_sail -v /home/stedam/backups:/b minio/mc \
    sh -c "mc alias set local http://minio:9000 sail password && mc mb -p local/backups && mc cp /b/*.dump.gz local/backups/"
  ```

- в S3/внешнее хранилище, если есть аккаунт.

### 1.5 WAL и PITR (для восстановления на момент времени)

Без этого восстановить «состояние на 10 минут назад» нельзя — только последний дамп.

**Шаг 1.** Включить архивацию. В `compose.yaml` (сервис `pgsql`) добавить том и команду:

```yaml
pgsql:
    image: 'postgres:17'
    ports:
        - '${FORWARD_DB_PORT:-5432}:5432'
    environment:
        PGPASSWORD: '${DB_PASSWORD:-secret}'
        POSTGRES_DB: '${DB_DATABASE:-laravel}'
        POSTGRES_USER: '${DB_USERNAME:-sail}'
        POSTGRES_PASSWORD: '${DB_PASSWORD:-secret}'
        # Включаем архивацию WAL
        POSTGRES_INITDB_ARGS: '--data-checksums'
    volumes:
        - 'sail-pgsql:/var/lib/postgresql/data'
        - './docker/pgsql/archive:/var/lib/postgresql/archive'
    command:
        - postgres
        - -c
        - wal_level=replica
        - -c
        - archive_mode=on
        - -c
        - archive_command=test ! -f /var/lib/postgresql/archive/%f && cp %p /var/lib/postgresql/archive/%f
        - -c
        - wal_keep_size=512MB
```

```bash
mkdir -p docker/pgsql/archive
docker compose up -d pgsql
```

Проверка:

```bash
docker exec stedam-pgsql-1 psql -U sail -d laravel -c "show archive_mode; show archive_command;"
docker exec stedam-pgsql-1 ls /var/lib/postgresql/archive | tail
```

**Шаг 2.** Базовый физический бэкап (нужен как стартовая точка PITR):

```bash
docker exec stedam-pgsql-1 pg_basebackup -U sail -D /tmp/base -Ft -z -P -X stream
docker cp stedam-pgsql-1:/tmp/base /home/stedam/backups/base_$(date +%F)
```

**Шаг 3.** Восстановление на момент T:

1. остановить контейнер приложения/воркеров;
2. остановить `pgsql`, сохранить текущий том (не удалять!);
3. развернуть базовый бэкап в новый `PGDATA` (новый том);
4. в `postgresql.conf` (или `recovery.signal` + `postgresql.auto.conf`):

   ```conf
   restore_command = 'cp /var/lib/postgresql/archive/%f %p'
   recovery_target_time = '2026-11-26 18:25:00+00'
   recovery_target_action = 'promote'
   ```

5. создать пустой файл `recovery.signal`, запустить `pgsql`;
6. после `LOG: recovery stopping before commit ...` БД в состоянии на T.

> На практике для локальной разработки достаточно логических дампов —
> PITR нужен, если над базой работают несколько человек и цена потери дня
> данных неприемлема.

### 1.6 Восстановление из логического дампа

Полное пересоздание базы:

```bash
# 1) отключить приложение, чтобы не было соединений
docker compose stop laravel.test queue-worker requested-actions-worker scheduler pre-grader

# 2) пересоздать базу
docker exec stedam-pgsql-1 psql -U sail -d postgres \
  -c "DROP DATABASE IF EXISTS laravel WITH (FORCE);" \
  -c "CREATE DATABASE laravel OWNER sail;"

# 3) восстановить дамп
gunzip -c /home/stedam/backups/laravel_20261126_0230.dump.gz \
  | docker exec -i stedam-pgsql-1 pg_restore -U sail -d laravel --no-owner --no-privileges --clean --if-exists

# если дамп в SQL (pg_dumpall / plain):
gunzip -c /home/stedam/backups/all_20261126.sql.gz \
  | docker exec -i stedam-pgsql-1 psql -U sail -d postgres

# 4) поднять приложение
docker compose start laravel.test queue-worker requested-actions-worker scheduler pre-grader

# 5) проверить
docker exec stedam-pgsql-1 psql -U sail -d laravel \
  -c "select (select count(*) from users) users, (select count(*) from learns) learns, (select max(created_at) from notifications) last_notification;"
```

Восстановление в «другую» базу (не ломая текущую):

```bash
docker exec stedam-pgsql-1 psql -U sail -d postgres -c "CREATE DATABASE laravel_restore OWNER sail;"
gunzip -c backup.dump.gz | docker exec -i stedam-pgsql-1 pg_restore -U sail -d laravel_restore --no-owner
# затем в .env временно  DB_DATABASE=laravel_restore  и проверить приложение
```

### 1.7 Хранение и ретенция

| Уровень | Как часто | Хранить | Куда |
|---|---|---|---|
| Ежедневный `pg_dump -Fc` | 02:30 | 14 дней | `/home/stedam/backups` + Windows/MinIO |
| Недельный `pg_dumpall` | вс 03:00 | 8 недель | Windows/MinIO |
| Месячный (первое число) | 03:00 | 12 месяцев | внешний диск/облако |
| Базовый физический + WAL | раз в неделю | до следующего | внешний диск |

---

## 2. Уровень Laravel

### 2.1 Миграции: где что лежит и как применять

- миграции — `database/migrations/`;
- сиды — `database/seeders/`;
- статус — таблица `migrations`.

Применение:

```bash
docker exec stedam-laravel.test-1 php artisan migrate --force   # прод
docker exec stedam-laravel.test-1 php artisan migrate:status
```

**Опасные команды — только с явным подтверждением и после бэкапа:**

| Команда | Что делает |
|---|---|
| `migrate:fresh` | **DROP всех таблиц** и миграции с нуля |
| `migrate:refresh` | откат всех миграций + повтор |
| `migrate:reset` | откат всех миграций |
| `db:wipe` | удаление таблиц/вьюх/типов |
| `migrate:fresh --seed` | то же + сиды |

**Правило:** перед любым из них — бэкап (§1.2/1.3), и проверить, на какую базу
они смотрят:

```bash
docker exec stedam-laravel.test-1 php artisan db:show
docker exec stedam-laravel.test-1 php artisan tinker --execute='echo config("database.connections.pgsql.database");'
```

### 2.2 Защита от случайного удаления данных

1. **Тесты всегда в отдельной БД.** `phpunit.xml`:

   ```xml
   <env name="APP_ENV" value="testing"/>
   <env name="DB_CONNECTION" value="pgsql"/>
   <env name="DB_DATABASE" value="testing"/>
   ```

   Требование: тестовая база **не совпадает** с рабочей.

2. **Запускать тесты только в контейнере приложения**, где конфиг берётся
   из `phpunit.xml`, а не на хосте WSL с `.env`:

   ```bash
   docker exec stedam-laravel.test-1 php artisan test
   ```

   Запуск `php artisan test` на хосте WSL с рабочим `.env` — при
   `RefreshDatabase`/`DatabaseTruncation` и без `DB_DATABASE=testing` даст
   очистку рабочей базы.

   Отдельно опасно `php artisan test --env=...`: Collision не вычищает `.env`,
   и phpunit наследует рабочее соединение. Такой запуск блокирует слушатель
   `App\Support\Testing\EnsureTestingDatabase` (регистрируется в
   `AppServiceProvider::boot()`): при наличии `--env` и базе вне
   `testing`/`testing_*` он бросает исключение ещё до старта тестов.

   ```bash
   # падает с «Тесты запрещены: подключение «pgsql» смотрит в базу «laravel»»
   docker exec -w /var/www/html stedam-laravel.test-1 php artisan test --env=local
   ```

3. **Guard‑проверка в `tests/TestCase.php`** (страховка, что тесты не уйдут в `laravel`):

   ```php
   protected function setUp(): void
   {
       $db = config('database.connections.'.config('database.default').'.database');

       if (! app()->environment('testing') && ! str_contains((string) getenv('APP_ENV'), 'testing')) {
           // не тест-окружение
       }

       if (config('database.connections.pgsql.database') === 'laravel') {
           throw new RuntimeException('Tests must not run against the application database "laravel".');
       }

       parent::setUp();
   }
   ```

   (в проекте есть `tests/TestCase.php` — туда и добавить).

4. **Guard в artisan‑команде `db:wipe`/`migrate:fresh`** на прод‑окружении уже
   встроен Laravel (`--force`), но локально `--force` не защищает.
   Ограничение на уровне БД — см. §2.4.

### 2.3 Согласованность файлов и БД

В проекте файлы лежат в MinIO (`AWS_BUCKET_PUBLIC` / `AWS_BUCKET_PRIVATE`)
и в БД хранятся ссылки (`file_sets`, `lesson_blocks` и т.д.). Бэкап только БД
даст битые ссылки. Поэтому:

- **бэкапить оба**: дамп БД + снимок бакетов MinIO:
  ```bash
  docker run --rm --network stedam_sail -v /home/stedam/backups:/b minio/mc \
    sh -c "mc alias set local http://minio:9000 sail password && mc mirror local/stedam-public /b/minio/public && mc mirror local/stedam-private /b/minio/private"
  ```
- **восстановление** — сначала БД, затем бакеты (или наоборот, но после обоих
  прогнать `php artisan files:prune` и `php artisan files:clean-temp`
  из `app/Console/Commands/PruneOrphans.php`/`CleanTempFiles.php`).
- локальные `storage/app/public` и `storage/app/private` — если S3 не включён,
  их тоже копировать.

### 2.4 Ограничение прав БД (защита «на уровне сервера»)

Нежелание повторить инцидент — самый надёжный способ: **у рабочего пользователя
не должно быть права DROP на схему**, а миграции выполняются отдельной ролью.

```sql
-- от имени postgres
CREATE ROLE app_rw LOGIN PASSWORD '...';      -- только DML
GRANT CONNECT ON DATABASE laravel TO app_rw;
GRANT USAGE ON SCHEMA public TO app_rw;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO app_rw;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO app_rw;
ALTER DEFAULT PRIVILEGES FOR ROLE sail IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_rw;
```

В `.env` рабочий пользователь — `app_rw`, а `sail` используется только для
миграций/бэкапов. Тогда случайный `migrate:fresh` упадёт с `permission denied`.

Дополнительно, для продакшена — запрет на подключение к `laravel` из тестов:

```sql
REVOKE CONNECT ON DATABASE laravel FROM PUBLIC;
```

### 2.5 Порядок восстановления приложения

1. Остановить всё, что пишет в БД:
   ```bash
   docker compose stop laravel.test queue-worker requested-actions-worker scheduler pre-grader
   ```
2. Очистить кэш конфига/роутов/представлений (чтобы не остались старые биндинги):
   ```bash
   docker exec stedam-laravel.test-1 php artisan optimize:clear
   ```
3. Восстановить БД (§1.6).
4. При необходимости — `migrate --force` для новых миграций (например,
   `push_subscriptions`).
5. Очереди: `php artisan queue:restart`; прерванные jobs лежат в `jobs`/`failed_jobs`
   и уже восстановились вместе с дампом. Проверить `failed_jobs`.
6. Поднять контейнеры, проверить:
   ```bash
   docker exec stedam-laravel.test-1 php artisan db:show
   docker exec stedam-laravel.test-1 php artisan migrate:status
   ```
7. Smoke‑тест: логин, список уведомлений `/api/notifications`, открытие урока.

### 2.6 Регулярные проверки (restore drill)

Бэкап, который никогда не восстанавливали, — это не бэкап.

- **Раз в месяц**: восстановить последний дамп в `laravel_restore`,
  прогнать `php artisan migrate:status` и несколько запросов, затем удалить базу.
- **Раз в квартал**: полное восстановление по §1.6 в тестовом окружении.
- Мониторить: если размер бэкапа упал >50% ночью — сигнал (бэкап пустой/частичный).

---

## 3. Чего НЕ делать

| Нельзя | Почему | Как правильно |
|---|---|---|
| `php artisan test` на хосте WSL с рабочим `.env` | `RefreshDatabase` пойдёт в `laravel` | только `docker exec ... php artisan test`, `DB_DATABASE=testing` |
| `migrate:fresh` без бэкапа | DROP всех таблиц | сначала `db:backup` |
| хранить единственный бэкап на `sail-pgsql` томе | умрёт вместе с томом | копировать в Windows/MinIO/облако |
| удалять «старый» том после инцидента | на нём могут остаться страницы до удаления | сначала `cp -a` тома, потом работаем |
| полагаться на WAL без `archive_mode=on` | сегменты перезаписываются | включить архив или делать дампы |

---

## 4. Быстрый чек-лист

- [ ] `mkdir -p /home/stedam/backups` и cron `pg_dump` в 02:30 с ретенцией 14 дней
- [ ] копия бэкапов на Windows‑диск/MinIO
- [ ] `phpunit.xml`: `DB_DATABASE=testing` (уже есть)
- [ ] guard в `tests/TestCase.php`, запрещающий тесты на базе `laravel`
- [ ] (опционально) `archive_mode=on` + `pg_basebackup` + `recovery.signal`
- [ ] (опционально) роль `app_rw` без права DROP для приложения
- [ ] бэкап бакетов MinIO вместе с БД
- [ ] восстановление раз в месяц проверяется на копии
