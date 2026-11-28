# 1. Спесификация механизма действий по запросу.
Этот раздел является истиной для раздела - 2. Спецификации для агентной разработки.
Агенты не имеют права вносить сюда изменения.

> Действие по запросу это событие, котрое происходит после того как пользователь-инициатор отправил пользователям-приемщикам сообщение, в котором просят его что то сделать.
> Пользователи-приемщики переходит по сслке в сообщении и попадает в интерфейс где могут принять или отклонить это действие.
> После факта приема или отклонения действия поджигается событие действия или событие отклонения действия. В обоих случаях пользователи участвующие в этом процессе получают соответствующее уведомления.
> Возможны сценарии когда инициатором и приемщиком может выступать как одиночный пользователь так и гргуппа пользователей.
> Это поведение реализуется в классе **handler** агрегата.

## DDD разделение ролей по слоям
- Агрегат RequestedAction инкапсулирует состояние и бизнес-правила, генерируя события ActionApproved и ActionRejected.
- Слой Application (командные обработчики) оркестрирует процесс, координируя работу агрегата и репозитория.
- Доменные события публикуются после успешного сохранения агрегата, обеспечивая слабую связанность.
- Асинхронные слушатели (ShouldQueue) обрабатывают отправку уведомлений, не блокируя основной поток выполнения.


Физическая доставка сообщений реализуется через стандартный механизм отложенных сообщений Laravel13. Проект уже использует Laravel13.
Классы сообщений, которые относятся к этой система маркируются префиксом RequestedAction.
Используется ShouldQueue на Notification с доставкой черз различные каналы, от email до браузерных пушей.
Стандартный канал `database` (таблица `notifications` Laravel) реализуется в рамках этой задачи и является обязательным минимумом.

## Агрегат RequestedAction
Используется pgsql таблица с двухуровневым партиционированием.
На первом уровне по признаку complete - разделяются на завершенные и актуальные.
Завершенная партиция прунится раз в день.
Актуальные делятся на помесячные партиции created_at - прунятся пачками по расписанию.

### Поля модели агрегата **RequestedAction**:
- id
- user-id - инициатор действия.
- handler - string - Alias  обработчика действий агрегата.
- payload - jsonb - дфнные, необходимые обработчику агрегата. Иммутабелен, не влияет на status
- status - enum [created, pending, approved, rejected, done, error] not null default 'created'
- decision - string nullable, enum ['accepted','declined'] - строковое представление результата обработки кворума по данным `requested_action_votes`; заполняется при закрытии кворума (approved → 'accepted', rejected → 'declined'), иначе null
- complete - bool default 0 - признак завершенности агрегата. Признак для Retention.
- error_code
- error_detail
- timestamps
  PK = (id, complete, created_at) - ключ секционирования всех уровней (PG 17: PK партиционированной таблицы обязан включать их все)

#### status
Агрегат создается в **created**. Это значит что `requested_actions_messaging_worker` еще его не обработал и не отправил по нему сообщение.
**pending** значит что сообщения потребителям запроса отаправлены и ждут одобрения.
**approved** означает что действие было одобрено кврорумом.
**rejected** означает что действие было отклонено кворумом. Терминальный.
**done** означает что действие было физически выполнено. Терминальный.
**error** - Любая ошибка на любом уровне выполнения пайпа блокирует дальнейшие действия. Терминальный.

#### Кворум
Кворум определяется в обработчике RequestedAction->registerApproval. 
Для простых действий достаточно единичного факта одобрения пользователем которому адресовано действие.
Для сложных будут содаваться отдельные классы котороые будут реализовывать хранение данных и анализ кворума.

##### Ответы кворума
- Голоса хранятся в таблице `requested_action_votes` (не партиционирована, без FK на `requested_actions`).
- Поля: id, requested_action_id, requested_action_complete, requested_action_created_at, responder_user_id, decision ENUM ['accepted','declined'], decided_at.
- UNIQUE (requested_action_id, requested_action_complete, requested_action_created_at, responder_user_id) — один голос на одного приёмщика в агрегате.
- Отозвать ранее поданный голос нельзя (нет UPDATE, только INSERT). Удаление агрегата (retention DROP листа) удаляет голоса каскадом.
- Кворумная политика (N-of-M, THRESHOLD, UNANIMOUS) инкапсулируется в конкретном обработчике. MVP `RequestedActionStudentToTeacher` реализует **1-of-1**: голос записывается в `requested_action_votes` и сразу же закрывает кворум.

##### Правило раннего решения
Каждый вызов `registerApproval` пересчитывает кворум по текущему набору голосов и общему числу консьюмеров (`payload.consumers`, len=M):
- `A ≥ quorum` (quorum — порог, минимум 1) → **ACCEPTED** (pending → approved, `complete=false`).
- `R > M - quorum` → невозможность собрать кворум → **DECLINED** (pending → rejected, `complete=true`).
- иначе → **WAITING** (голос сохранён, статус не меняется).
Для M=1 и quorum=1: первый accept → ACCEPTED; первый decline → R=1 > 1-1=0 → DECLINED.

##### Пользователь вне списка consumers
- Вызов `registerApproval` от пользователя, чей id ∉ `payload.consumers`, — **доменная ошибка**.
- Агрегат не меняется, статус не меняется, `ActionError` не эмитится, HTTP → 403.
- Форма «принять» (`canGetApprovalForm`) для такого пользователя должна вернуть ту же 403 ещё до `registerApproval`.

#### Retention
- активные complete=false: воркер `requested_actions_retention_worker` раз в час помесячные листья
  `requested_actions_active_<Y_m>` старше N месяцев по `created_at`: `DETACH CONCURRENTLY` → `DROP`. N = 6.
- завершённые complete=true: `prune_complete_requested_actions` раз в сутки помесячные листья
  `requested_actions_complete_<Y_m>` старше M дней: `DETACH CONCURRENTLY` → `DROP`. M = 30.
  `DETACH` целой ветки `requested_actions_complete` **не используется**; `DELETE` по завершённой ветке **не используется**.
- `lock_timeout` на сессию = 5с; при таймауте лист пропускается до следующего запуска.

##### Stale approved
Для различения «не запускался» / «запускался и падал» обязательна таблица `requested_action_handle_attempts` (ключ — тот же состав, что у голосов; строка пишется до вызова `handle()`). Чистится retention'ом вместе с листом.
- `approved`-агрегат без перехода в `done` — аномалия, которая навсегда держит строку в активной ветке и делает её невидимой для pruning.
- Порог T1 = 24ч (гарантированно больше суммы `$tries × $backoff` воркера `handle_approved_requested_actions`).
  Если `handle()` **ещё ни разу не запускался** (нет попыток) и `updated_at` старше T1 → `status='error'`,
  `error_code='HANDLE_STALE_NOT_STARTED'`, `error_detail` с диагностикой, `complete=true`, эмитится `ActionError`.
- Порог T2 = 7д. Если `handle()` **запускался и падал**, а с последнего `updated_at` прошло больше T2 →
  `status='error'`, `error_code='HANDLE_STALE_RETRY_EXHAUSTED'`, `complete=true`, эмитится `ActionError`.
- Оба перехода выполняет отдельная команда `requested-actions:stale-recovery` (`->hourly()`,
  `->withoutOverlapping()->onOneServer()->runInBackground()`). Это **единственный** путь `approved → error`,
  помимо `ErrorCollector`; в `RegisterApprovalAction` и `HandleApprovedAction` такие переходы запрещены.
- Переход идемпотентен: повторный проход не перезаписывает `error` (агрегат уже `complete=true` и выпадает из выборки).

##### Pending без ответа
- `pending` с неответившим приёмщиком **не истекает**: TTL нет, приёмщик может ответить через любой срок.
- Следствие: retention активной ветки по N месяцев **может удалить необработанный запрос**. Принимается осознанно —
  N должно выбираться так, чтобы покрывать реальный SLA принятия; если это неприемлемо для конкретного типа
  RequestedAction, TTL для `pending` вводится в самом обработчике (например, `registerApproval` вернёт `CLOSED`
  после истечения и `reject()` переведёт в `rejected`). Никакого глобального механизма в этом документе нет.

##### Порядок и идемпотентность DETACH + DROP
- `DETACH CONCURRENTLY` и `DROP` — две разные транзакции. При падении между ними лист остаётся отдельной таблицей в схеме.
- Воркер на старте выполняет `ALTER TABLE <parent> DETACH PARTITION <leaf> FINALIZE` для листьев, оставшихся
  в незавершённом DETACH (признак — присутствуют в `pg_class` с именем шаблона `<base>_<Y_m>` и **отсутствуют**
  в `pg_inherits`), затем `DROP TABLE` этих сирот. `FINALIZE` делает механизм повторно-запускаемым.
- `DETACH CONCURRENTLY` берёт `SHARE UPDATE EXCLUSIVE` на родителя — короткое, но ненулевое окно
  блокировки `INSERT`. Поэтому retention всегда запускается с `->withoutOverlapping()->onOneServer()->runInBackground()`.
- Retention и stale-recovery выполняются **над разными множествами** строк: retention отцепляет помесячные листья;
  stale-recovery только UPDATE внутри листа. Пересечения по одной строке нет: stale сначала UPDATE-ит (пока лист
  ещё присоединён и не старее N), потом retention может отцепить месяц.

## Обработчик RequestedAction
Используется на примере RequestedActionStudentToTeacher - запрос пользователя на то чтобы стать учеником преподавателя.
- canGetRequestForm(array $arguments):void|errorHandler проверка может ли пользователь вызвать форму. Под капотом всегда сперва только залогиненый и емайл подтвержден. Более тонкое в конкретной реализации.
- getRequestForm(array $arguments):array отдает список данных для отображения запроса в интерфейсе пользователя отправителя.
    Например: 
     ```json 
            {
                title: { type:string, value: 'Запись к преподавателю.' }, 
                text: { type:string, value: 'Вы действительно хотите стать учеником '.$params->teacher->user->userData->getFullName.'?'),
                approve: { type: boolean, value: false }
            }
- registerUserRequest(array $arguments):boolean принимается ответ от GetRequestForm и на основе его анализа принимается решение о дальнейших действиях.
 
| Решение                            | Действия                                                                                                                                                                                |
|------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Пользователь подтвердил действие   | Создается агрегат в статусе created и payload на основе $arguments в registerUserRequest. Происходит эмит события **ActionRequested** c id созданного агрегата в параметрах. Ответ true |
| Пользователь отказался от действия | Ответ false                                                                                                                                                                             |
 При создании агрегата в payload бязательно кладется ключ consumers. Это способ определить пользователей - кому предназначено аппрувить действие. По дефолту [123] - номер пользователя, единственного консьюмера. Используется в canGetApprovalForm.
 registerUserRequest идемпотентен по (user_id, handler, md5(payload)) в пределах активной ветки (complete = false). При дублирующем вызове возвращается существующий агрегат, ActionRequested не эмитится.

Физически (PG 17, двухуровневое партиционирование) уникальность обеспечивается UNIQUE-индексом на каждой помесячной активной субпартиции: (user_id, handler, payload_hash), где payload_hash — generated column md5(payload::text). Глобального UNIQUE на корневой партиционированной таблице PG не поддерживает; ON CONFLICT DO NOTHING (без колонок) работает только с таким индексом на листе. Следствие: уникальность гарантирована внутри одной помесячной субпартиции; на стыке месяцев (±момент смены created_at) допускается появление двух активных запросов — принимается как осознанное ограничение в пользу retention по created_at.

- sendRequestNotifications(Model RequestedAction) метод предназначен для рассылки уведомлений с запросом на действие потребителям конкретного класса RequestedAction. Массовый queue большого количества сообщений по доступным каналам. Полное queue всех сообщений по доступным каналам переводит агрегат в 'pending'.  Физическая доставка сообщений на ответственности канала.
- getRequestNotificationConsumers(Model RequestedAction) получение списка пользователей, которые должны подтвердить действие. Это специфичный для каждого класса RequestedAction механизм, кторый операется своей логикой на payload. 
- getRequestNotificationMessage(User $consumer)
- getNotificationChannels(User $consumer) - список доступных для пользователя каналов доставки сообщений. Минимум ['database'].
- canGetApprovalForm(array $arguments):void|errorHandler - проверка может ли текущий пользователь аппрувить конкретно это приедложение.
- getApprovalForm(array $arguments):array отдает список данных для отображения согласия в интерфейсе пользователя - получателя.
  Например:
     ```json 
            {
                title: { type:string, value: 'Прием ученика.' }, 
                text: { type:string, value: 'Вы действительно принять на обучение '.$params->student->user->userData->getFullName.'?'),
                approve: { type: boolean, value: false }
            }
- registerApproval(RequestedAction $action, User $responder, array $arguments) : ApprovalResult 
В $arguments принимается ответ от getApprovalForm и на основе его анализа принимается решение о дальнейших действиях.

| Решение                            | Действия                                                                                                                                                                       |
|------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Пользователь подтвердил действие   | INSERT голоса `accepted` в `requested_action_votes`. Если сошелся критерий handleApproved() — переводит агрегат в 'approved' и эмитит **ActionApproved**. Иначе (WAITING) — статус не меняется. Ответ — ApprovalResult. |
| Пользователь отказался от действия | INSERT голоса `declined`. Если из-за этого отказ уже неизбежен — reject() (pending → rejected, complete=true) + **ActionRejected**. Ответ — ApprovalResult. |
Реализация registerApproval самостоятельно отвечает за то достаточно ли одного ответа или нужны ответы многих потребителей и какие они (см. «Кворум → Правило раннего решения»).
Голос пользователя вне `payload.consumers` — доменная ошибка, статус не меняется, ActionError не эмитится, HTTP 403.
Это суть самого принятия решения что делать - handle / reject или нужно собрать больше ответов для решения. В случае запроса на принятие в ученики - достаточно подтверждения от преподавателя.
registerApproval возвращает ApprovalResult.CLOSED на любой вызов если состояние отличается от pending.

- handleApproved(Model RequestedAction) решает что критерий для перевода в approved сошелся. В простом случае просто возвращает true если не требуется кворум. Механики кворумов будут созданы позднее.
- sendApprovalNotifications(Model RequestedAction) метод предназначен для рассылки уведомлений с положительным резултатом подтверждения создателя RequestedAction. Массовый queue большого количества сообщений по доступным каналам.
- getApprovedNotificationMessage(User $consumer)
- sendRejectionNotifications(Model RequestedAction) для рассылки уведомлений с отрицательным резултатом потребителям конкретного класса RequestedAction.
- getRejectedNotificationMessage(User $consumer)
- reject(Model RequestedAction) переводит агрегат в состояние 'rejected' + complete=true. Вызывается только из registerApproval
- sendDoneNotifications(Model RequestedAction) рассылает уведомления об успешном совершении действия всем участникам с обоих сторон.
- getDoneNotificationMessage(User $consumer)
- sendErrorNotifications(Model RequestedAction) рассылает уведомления об ошибке всем участникам с обоих сторон отправитель и все кому отправлялось подтверждение независимо от их рекакции на него. 
- getErrorNotificationMessage(User $consumer)
- handle(Model RequestedAction):void непосредственно выполняет действие на которое выполнялся запрос. Обязан быть идемпотентен. Если handle по какой то причине не смог выполнить действие - он выбрасывает исключение с описанием причины. Например создает направленую связь UserRelation между учеником и учителем.

#### Воркер handle_approved_requested_actions
Собирает approved агрегаты order by updated_at asc чанками по 100 и выполняет handle каждого. При успехе переводит агрегат в состояние 'done' + complete=true.
FOR UPDATE SKIP LOCKED
Воркер смотрит на ответ handle и ставит done или error.
В случае успеха вызывает эмит ActionDone

#### Сбор ошибок
- Все методы RequestedAction, кроме handle() и sendErrorNotifications, при броске исключения проходят через общий сборщик ошибок. handle() и sendErrorNotifications — вне этого механизма.
Категории ошибок
- Ошибки классифицируются по типу исключения, не по тексту сообщения.
- **Доменная ошибка** — DomainException и наследники. Примеры: невалидный ввод, отсутствие прав, повторный ответ, недопустимый переход статуса, попытка ответить на не-pending агрегат.
status агрегата не меняется, error_code/error_detail не заполняются, ActionError не эмитится.
Исключение уходит наружу в HTTP-слой → 4xx (400/403/409/422).
В registerUserRequest при доменной ошибке агрегат не создаётся, ActionRequested не эмитится.
- **Транзиентная инфраструктурная ошибка** — RetryableException и наследники. Примеры: сеть, Redis, SMTP, очередь.
Переход в error, error_code + error_detail заполнены, эмитится ActionError (см. ниже — что происходит с уведомлениями).
- **Постоянная ошибка** — FatalException и наследники. Примеры: HandlerNotFound, TeacherDeleted, UserRelationAlreadyExists, а также исчерпанный retry из категории выше.
Сразу — переход * → error, error_code + error_detail заполнены.
Эмитится ActionError.
Переход в error

- При переходе в error:
status = 'error', error_code заполнен, error_detail заполнен;
complete не меняется автоматически — определяется в разделе «Retention»;
агрегат сохраняется;
эмитится событие ActionError (см. таблицу «События»).

Отдельный путь approved → error — только `requested-actions:stale-recovery` (см. «Retention → Stale approved»);
из `RegisterApprovalAction` и `HandleApprovedAction` перехода approved → error нет.

#### ApprovalResult
- enum ApprovalResult: string — результат вызова registerApproval. Четыре взаимоисключающих исхода.

| Значение | value        | Когда                                                       | Что меняется в агрегате | complete      | Событие        | HTTP | UI                            |
|----------|--------------|-------------------------------------------------------------|-------------------------|---------------|----------------|------|-------------------------------|
| ACCEPTED | `'accepted'` | Голос accepted добавлен, A ≥ quorum                          | pending → approved      | false         | ActionApproved | 200  | «Запрос принят»               |
| DECLINED | `'declined'` | Голос declined добавлен, R > M − quorum (кворум недостижим)  | pending → rejected      | true          | ActionRejected | 200  | «Запрос отклонён»             |
| WAITING  | `'waiting'`  | Голос добавлен, кворум пока недостижим и ещё возможен        | Ничего (голос сохранён) | без изменений | —              | 200  | «Голос учтён, ждём остальных» |
| CLOSED   | `'closed'`   | status ≠ pending (approved/rejected/done/error)              | Ничего                  | без изменений | —              | 409  | «Запрос уже закрыт»           |

«Дефицит невозможно закрыть» в DECLINED означает: даже если все оставшиеся приёмщики проголосуют accepted, кворум не наберётся. Ошибка ответа пользователя вне `payload.consumers` — **не** ApprovalResult, а доменная ошибка → 403.

`pending` TTL не имеет: неответивший приёмщик оставляет запрос в `pending` бесконечно (см. «Retention → Pending без ответа»).



#### События
| Событие         | Триггер (метод)                                             | Что в payload                                                                   | Listener'ы                                                                                                     |
|-----------------|-------------------------------------------------------------|---------------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------|
| ActionRequested | `registerUserRequest` (после INSERT)                        | `RequestedActionId`, `handler`, `userId` (инициатор), `arguments`, `createdAt`  | (нет — уведомление приёмщику отправляет `requested_actions_messaging_worker` при created; не listener события) |
| ActionApproved  | `registerApproval` (после pending → approved)               | `RequestedActionId`, `handler`, `userId`, `approvedAt`, `approvedBy`            | `SendApprovalNotifications` (ShouldQueue)                                                                      |
| ActionRejected  | `reject()` (после pending → rejected)                       | `RequestedActionId`, `handler`, `userId`, `rejectedAt`, `rejectedBy`            | `SendRejectionNotifications` (ShouldQueue)                                                                     |
| ActionDone      | `handle_approved_requested_actions` (после approved → done) | `RequestedActionId`, `handler`, `userId`, `doneAt`                              | `SendDoneNotifications` (ShouldQueue)                                                                          |
| ActionError     | сборщик ошибок везде (после * → error)                      | `RequestedActionId`, `handler`, `userId`, `errorCode`, `errorDetail`, `errorAt` | `SendErrorNotifications` (ShouldQueue)                                                                         |

#### http слой
Регистрируются 4 апи-раута:
get: request-action/form для **getRequestForm**
post: request-action/request для **registerUserRequest**
get: approve-action/form для **getApprovalForm**
post: approve-action/approve для **registerApproval**

Для всех обязательное поле в запросе - action=имя обработчика например **StudentToTeacher**
Номер инициатора берется из auth().
Осталные ключи - это обязательные для конкретного обработчика параметры, которые потом лягут в payload.
RequestedActionsController реализует фабрику, которая решает какой минно обработчик использовать и адресует вызов в соответствующий метод из него.
Коды ответов: `registerUserRequest=false` → 422 (`USER_REQUEST_DECLINED`, агрегат не создаётся); `ApprovalResult.CLOSED` и повторный голос (`AlreadyVoted`) → 409; доменные ошибки → 4xx.

## Запуск воркеров: локально (Sail) и на проде (Supervisor)

Асинхронную часть механизма обслуживают Artisan-команды. Каждая command — **периодическая**: она забирает батч, делает шаг и завершается. Цикл «как часто запускать» накладывается снаружи; в коде его держат `routes/console.php` (`Schedule::command(...)->everyMinute()/->hourly()/->dailyAt(...)`, все с `withoutOverlapping()->onOneServer()->runInBackground()`).

| Команда | Назначение | Где крутится |
|---|---|---|
| `requested-actions:messaging` | `created` → уведомления в очередь → `pending` | каждую минуту |
| `requested-actions:handle-approved` | физическое `handle()` для `approved` | каждую минуту |
| `requested-actions:retain` | `DETACH CONCURRENTLY` + `DROP` старых листьев активной ветки | раз в час |
| `requested-actions:prune-complete` | то же для завершённой ветки | раз в сутки |
| `requested-actions:stale-recovery` | зависшие `approved` → `error` | раз в сутки |

> `schedule:run` только **ставит** команды в очередь (`runInBackground`) — их исполняет `queue-worker`. Поэтому на проде обязательны **и** шедулер, **и** queue-worker, иначе `messaging`/`handle-approved` не выполнятся.

### Локально: Sail (`compose.yaml`)

Автостарт вместе с `./vendor/bin/sail up`. `scheduler` уже в `compose.yaml`; отдельный сервис `requested-actions-worker` (по образцу `pre-grader`) крутит каждую команду в shell-цикле (у команд нет `--sleep`-опции):

```yaml
requested-actions-worker:
    image: 'sail-8.5/app'
    entrypoint: [ '/bin/sh', '-c' ]
    command:
        - >
          while true; do sleep 5; php artisan requested-actions:handle-approved >/proc/1/fd/1 2>&1; php artisan requested-actions:messaging >/proc/1/fd/1 2>&1; done &
          while true; do sleep 60; php artisan requested-actions:retain >/proc/1/fd/1 2>&1; php artisan requested-actions:prune-complete >/proc/1/fd/1 2>&1; php artisan requested-actions:stale-recovery >/proc/1/fd/1 2>&1; done &
          wait
    depends_on:
        redis: { condition: service_healthy }
        pgsql:  { condition: service_healthy }
    restart: unless-stopped
```

Это локальный аналог «supervisor + расписание»: быстро (5с) — горячий путь, раз в минуту — тяжёлые retention/stale.

### Прод: Supervisor (Linux/VM, не Docker)

1. **Пользователь и каталог.** Супервизор держит процессы под системным пользователем (не root), `directory` — корень Laravel; `WWWUSER`-по-аналогии это владелец `storage/`/`bootstrap/cache`.
2. **Шедулер.** Один процесс: `php artisan schedule:work` (в Laravel live, под restart) **или** крон `* * * * * cd /var/www/app && php artisan schedule:run >> /dev/null 2>&1`. Не дублируйте оба способа.
3. **Очереди.** Слушатель `queue:work` на connection `redis` (прод), с `--tries`/`--backoff`/`--max-time`; перезапуск по деплою через `queue:restart`.
4. **Воркеры заявок.** Две программы: `retain`/`prune`/`stale` — `numprocs=1` (переключатель без наложения) или `numprocs=1` + цикл с `sleep`; `messaging`/`handle-approved` — можно `numprocs>1`, потому что батчи берутся под `FOR UPDATE SKIP LOCKED` и **идемпотентны** (`withoutOverlapping` в супервизоре сузит параллелизм — если нужен throughput, снимайте `numprocs=1`).

Пример `/etc/supervisor/conf.d/requested-actions.conf`:

```ini
[program:schedule]
command=php artisan schedule:work
directory=/var/www/app
autostart=true
autorestart=true
user=www-data
numprocs=1
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile=/var/log/app/ledger-schedule.log

[program:queue]
command=php artisan queue:work redis --tries=3 --backoff=10 --max-time=3600
directory=/var/www/app
autostart=true
autorestart=true
user=www-data
numprocs=2
stopasgroup=true
killasgroup=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/log/app/ledger-queue.log

[program:ra-messaging]
command=php artisan requested-actions:messaging
directory=/var/www/app
autostart=true
autorestart=true
user=www-data
startsecs=10
redirect_stderr=true
stdout_logfile=/var/log/app/ledger-ra-messaging.log

[program:ra-handle-approved]
command=php artisan requested-actions:handle-approved
directory=/var/www/app
autostart=true
autorestart=true
user=www-data
startsecs=10
redirect_stderr=true
stdout_logfile=/var/log/app/ledger-ra-handle-approved.log

[program:ra-retain]
command=php artisan requested-actions:retain
directory=/var/www/app
autostart=true
autorestart=true
user=www-data
startsecs=10
redirect_stderr=true
stdout_logfile=/var/log/app/ledger-ra-retain.log

[program:ra-prune-complete]
command=php artisan requested-actions:prune-complete
directory=/var/www/app
autostart=true
autorestart=true
user=www-data
startsecs=10
redirect_stderr=true
stdout_logfile=/var/log/app/ledger-ra-prune-complete.log

[program:ra-stale-recovery]
command=php artisan requested-actions:stale-recovery
directory=/var/www/app
autostart=true
autorestart=true
user=www-data
startsecs=10
redirect_stderr=true
stdout_logfile=/var/log/app/ledger-ra-stale-recovery.log
```

Особенности:
- **`stopwaitsecs` для `ra-*`** должен быть больше `lock_timeout` retention (5с) + `SHARE UPDATE EXCLUSIVE`, но меньше окна деплоя; при `autorestart` супервизор «дёргает» программу после `sleep`-шага, а `withoutOverlapping` (mutex) не даст двум экземплярам пересечься на одной машине.
- **`queue:restart`** после `composer install`/`migrate` + `supervisorctl reread && supervisorctl update`.
- **`user=www-data`** и `filesystems` — владелец `storage/`; лог-каталог создаётся заранее.
- **`onOneServer()`** требует общего кэша (redis/database); при нескольких app-хостах каждый держит свой `schedule`/`queue`, а mutex в общем кэше разведёт `withoutOverlapping` на кластере.
- **Healthcheck**: `supervisorctl status requested-actions:*` + алерты по логам на `HANDLE_STALE_*`.
- Прод-конфигурация `.env`: `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `APP_ENV=production` (иначе `withoutOverlapping` берёт file-кэш и однохостовый режим).

> В Docker-проде супервизор заменяют отдельными сервисами как в `compose.yaml` (только с `--sleep`-циклом, но лучше — `supervisord` внутри контейнера или Kubernetes CronJob для `retain`/`prune-complete`/`stale-recovery`).


# 2. Спецификации для агентной разработки
Создаются агентом на сонове первого раздела.
Этот раздел должен содержать DDD спецификации, созданные на основе анализа данных из раздела 1.
Агент должен разобрать задание, создать план работ учитывая размер контекстного окна.
migrate + pest на чистой БД агент не должен делать без явного указания.
Приступить к выполнению загрузив скиллы.

Отсюда и ладее - написано агентом.

## 2.1. Контекст проекта (факты, на которых построены спецификации)

> Статус на момент подготовки ТЗ: реальных работ по документу не проводилось. Feature-тесты в рамках задачи **не пишутся** (подтверждено заказчиком). Ветка `feature/requested-actions` создаётся исполнителем перед стартом.

Источник: анализ кодовой базы `app/`, `database/`, `routes/`.
- Laravel 13.8, PHP ^8.3, PostgreSQL, очередь `database` (`QUEUE_CONNECTION=database`). Sail для окружения.
- Слои: `app/Domain` (агрегаты, VO, события, доменные исключения), `app/Application` (use-cases в виде `*Action`, порты в `*Ports`, DTO в `*DTOs`), `app/Infrastructure` (Eloquent-репозитории, мапперы, адаптеры), `app/Http` (контроллеры + API Resources), `app/Filament` (админка).
- Command handlers реализуются как `final readonly class *Action` с методом `execute(...)` и транзакцией внутри (`TransactionManagerInterface`).
- Domain events — `final readonly class` без зависимостей от Laravel. Публикуются Application-слоем после сохранения агрегата через `Illuminate\Contracts\Events\Dispatcher`.
- Листенеры регистрируются **вручную** через `Event::listen(...)` в ServiceProvider (образец — `app/Providers/CourseServiceProvider.php`); auto-discovery не используется.
- Асинхронные листенеры — `implements ShouldQueueAfterCommit` с `$tries`/`$backoff` и идемпотентной логикой.
- Фоновых `app/Jobs` нет: периодические воркеры — `app/Console/Commands` + `Schedule::command(...)` в `routes/console.php` (`withoutOverlapping()->onOneServer()->runInBackground()`).
- Модели в `app/Models` — тонкие (только маппинг БД, `$casts`, связи), доменной логики не содержат. Для `timestamptz` используется трейт `app/Models/Concerns/UsesTimestamptz.php`.
- Миграции могут содержать raw PG-DDL (`DB::statement(...)`), стиль уже принят в `database/migrations/2026_10_23_000000_create_all_tables_pgsql.php`. Партиционирования пока нет — реализуется в рамках этой задачи.
- Уведомления в `app/Notifications`, сейчас только канал `mail`. Механизм мультиканальной доставки (`database` минимум) вводится этой задачей.
- Классов с префиксом `RequestedAction` в коде пока нет — это новая фича.
- Тесты зеркалят слои: `tests/Unit/Domain`, `tests/Unit/Application`, `tests/Feature/Application`, `tests/Feature/Infrastructure`, `tests/Feature/Listeners`, `tests/Feature/Api`.

## 2.2. Целевая структура файлов

```
app/Domain/RequestedActions/
  Entities/RequestedAction.php                 # агрегат
  ValueObjects/RequestedActionId.php
  ValueObjects/RequestedActionPayload.php      # jsonb-обёртка, иммутабельная
  Enums/RequestedActionStatus.php              # created|pending|approved|rejected|done|error
  Enums/ApprovalResult.php                     # accepted|declined|waiting|closed
  Events/ActionRequested.php
  Events/ActionApproved.php
  Events/ActionRejected.php
  Events/ActionDone.php
  Events/ActionError.php
  Exceptions/DomainException.php               # базовый доменный (или общий из Domain/Shared)
  Exceptions/RetryableException.php
  Exceptions/FatalException.php
  Exceptions/HandlerNotFoundException.php      # FatalException
  Exceptions/RequestedActionAlreadyClosedException.php  # DomainException
  Handlers/RequestedActionHandler.php          # интерфейс-контракт обработчика
  Handlers/RequestedActionStudentToTeacher.php # первый обработчик
  Messages/ApprovalMessage.php                 # VO сообщения для Notification (title/text/approve)
  Errors/ErrorCollector.php                    # общий сборщик ошибок

app/Application/RequestedActions/
  Actions/CreateRequestedActionAction.php      # обёртка над registerUserRequest + INSERT + ActionRequested
  Actions/RegisterApprovalAction.php           # оркестрация registerApproval + ActionApproved/Rejected
  Actions/HandleApprovedAction.php             # один агрегат: handle + done/error + ActionDone
  Ports/RequestedActionRepositoryInterface.php
  Ports/RequestedActionHandlerRegistryInterface.php  # резолв handler alias → класс
  Ports/RequestedActionVotesRepositoryInterface.php  # кворумные голоса
  DTOs/CreateRequestedActionData.php
  DTOs/ApprovalResponseData.php
  DTOs/QuorumSnapshot.php                      # DTO: {accepted:int, declined:int, total:int, quorum:int}
  Exceptions/...                               # при необходимости проброс доменных

app/Infrastructure/Persistence/Repositories/EloquentRequestedActionRepository.php
app/Infrastructure/Persistence/Repositories/EloquentRequestedActionVotesRepository.php
app/Infrastructure/Persistence/Mappers/RequestedActionMapper.php
app/Infrastructure/Persistence/Mappers/RequestedActionVoteMapper.php
app/Infrastructure/RequestedActions/RequestedActionHandlerRegistry.php

app/Models/RequestedAction.php                 # тонкая Eloquent-модель
app/Models/RequestedActionVote.php             # тонкая Eloquent-модель голоса
database/migrations/xxxx_create_requested_action_votes_table.php
app/Notifications/RequestedActions/RequestedActionNotification.php  # базовая мультиканальная
app/Console/Commands/RequestedActions/
  RequestedActionsMessagingCommand.php         # requested_actions_messaging_worker
  HandleApprovedRequestedActionsCommand.php    # handle_approved_requested_actions
  RequestedActionsRetentionCommand.php         # requested_actions_retention_worker
  PruneCompleteRequestedActionsCommand.php     # prune_complete_requested_actions
  StaleRecoveryCommand.php                     # requested-actions:stale-recovery
app/Providers/RequestedActionServiceProvider.php  # биндинги портов + Event::listen
database/migrations/xxxx_create_requested_actions_table.php  # партиционированная
database/migrations/xxxx_requested_action_partitions.php
routes/console.php                             # Schedule::command(...)
```

Регистрация провайдера — в `bootstrap/providers.php`.

## 2.3. Агрегат `RequestedAction`

- `final readonly class RequestedAction` в `app/Domain/RequestedActions/Entities/`.
- Неизменяемый: каждая мутация (переход статуса, `reject`, `markApproved`, `markDone`, `markError`) **возвращает новый экземпляр**; статические фабрики `create(...)` и `restore(...)`, как в `Domain/Course/Entities/Course.php`.
- Поля — см. раздел 1. `id: ?int` до сохранения, `status` — enum, `complete: bool`, `payload: RequestedActionPayload` (обёртка над массивом), `errorCode/errorDetail: ?string`, `createdAt/updatedAt: DateTimeImmutable`.
- Инварианты (бросают `DomainException`, не меняют статус, не эмитят `ActionError`):
  - переходы разрешены только: `created→pending`, `pending→approved`, `pending→rejected`, `approved→done`, `*→error`;
  - `registerApproval` при `status !== pending` → `ApprovalResult::CLOSED`;
  - `reject` идемпотентен по состоянию: повторный `reject` на `rejected` — `CLOSED`.
- Агрегат **не публикует события сам** и не зависит от Laravel — события создаёт Application-слой после сохранения (по конвенции проекта).

## 2.4. Value Objects и Enums

- `RequestedActionId` — `final readonly`, `int $value > 0`, `equals()`, по образцу `CourseId`.
- `RequestedActionPayload` — `final readonly`, хранит `array $data`, `toArray()`, `hash(): string` (стабильный `md5`/`sha1` по `json_encode` с сортировкой ключей для идемпотентности), `get(string $key, mixed $default = null)`, `consumers(): array<int>`.
- `RequestedActionStatus: string` — значения `created|pending|approved|rejected|done|error`; методы `isTerminal()`, `canTransitionTo(self)`.
- `ApprovalResult: string` — `accepted|declined|waiting|closed`.
- `VoteDecision: string` — `accepted|declined`. Значение хранится в колонке `decision` таблицы `requested_action_votes`.

## 2.4.1. Кворум: хранение голосов

- Голоса хранятся **вне агрегата** — отдельная таблица `requested_action_votes` (раздел 1). Причина: агрегат лежит в партиционированной таблице и на горячем пути `registerApproval` нужна только запись голоса, а не UPDATE партиции. Плюс — на таблицу голосов удобно вешать UNIQUE.
- **FK на `requested_actions` не создаётся** (решение принято по итогам анализа). Причина — переезд строки между партициями: `reject()`/`done` ставят `complete=true`, а `requested_actions` секционирована `PARTITION BY LIST (complete)`. Для PG такой `UPDATE complete` — это DELETE в старой партиции + INSERT в новую. FK, чьи колонки — часть ключа партиционирования, делать ссылкой на перемещаемую строку нельзя без `ON UPDATE CASCADE` и уверенности, что триггеры FK корректно срабатывают на row movement. Вместо этого:
  - целостность держится приложением: перед `votesRepo->record()` агрегат уже загружен `FOR UPDATE` в `RegisterApprovalAction`, поэтому запись голоса на несуществующий агрегат невозможна по построению;
  - при удалении помесячного листа `requested_actions_*` retention-воркер **явно** вызывает `votes->deleteForLeaf($leaf)` (`DELETE FROM requested_action_votes WHERE requested_action_created_at >= $from AND requested_action_created_at < $to`) до `DROP TABLE`. `deleteForLeaf` идемпотентен (повторный вызов — 0 строк), поэтому выполняется и в recovery-фазе, если лист остался сиротой.
- Схема:
  ```
  requested_action_votes
    id                              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY
    requested_action_id             bigint      NOT NULL
    requested_action_complete       boolean     NOT NULL
    requested_action_created_at     timestamptz NOT NULL
    responder_user_id               bigint      NOT NULL
    decision                        text        NOT NULL CHECK (decision IN ('accepted','declined'))
    decided_at                      timestamptz NOT NULL DEFAULT now()
    UNIQUE (requested_action_id, requested_action_complete, requested_action_created_at, responder_user_id)
    FOREIGN KEY (responder_user_id) REFERENCES users (id) ON DELETE CASCADE
    INDEX (requested_action_created_at)  -- для deleteForLeaf в retention
  ```
- **Проверено на PG 17.11 в Sail:** UNIQUE по трём колонкам агрегата + `responder_user_id` отклоняет повторный голос. FK на `users(responder_user_id)` — обычный, безопасен.
- **Замечание про `requested_action_complete`.** Раздел 1 фиксирует эту колонку в ключе идентичности голоса. При переезде строки `complete=false → true` записанный ранее голос остаётся с прежним `requested_action_complete=false`. Это сознательно и безопасно: после `approved`/`rejected` кворум больше не пересчитывается (`CLOSED`), а `snapshot()` вызывается только из `registerApproval` по агрегату в `pending`. Уникальность голоса при этом также не страдает: повторный `registerApproval` вернёт `CLOSED` раньше, чем дойдёт до INSERT.
- **VO `RequestedActionRef`** (id + complete + createdAt) по-прежнему нужен — он используется как ключ идентичности голоса (`requested_action_id`, `requested_action_complete`, `requested_action_created_at`) и как аргумент `deleteForLeaf`-фильтра. Но FK теперь нет, поэтому лишнее требование «три колонки ради FK» снято; бережём их ради UNIQUE.
- Вставка голоса — `INSERT … ON CONFLICT DO NOTHING` по UNIQUE `(requested_action_id, requested_action_complete, requested_action_created_at, responder_user_id)`; при конфликте — **доменная ошибка** `AlreadyVotedException` (не ApprovalResult) → 409.
- Проекция голосов для правила раннего решения делается в `QuorumSnapshot` (DTL ниже), а не в БД:
  ```
  QuorumSnapshot::from(VoteCounts $counts, int $total)
    accepted  : int
    declined  : int
    remaining : int   // total - accepted - declined
    quorum    : int   // порог, передаёт handler

    isSatisfied()  : bool // accepted >= quorum
    isImpossible() : bool // declined > total - quorum
  ```
- Порт `RequestedActionVotesRepositoryInterface`: `record(RequestedActionRef, UserId, VoteDecision): void` (INSERT…ON CONFLICT DO NOTHING), `snapshot(RequestedActionRef, int $total, int $quorum): QuorumSnapshot`, `countAll(RequestedActionRef): int`, `deleteForLeaf(DateTimeImmutable $from, DateTimeImmutable $to): int` (для retention).
- Порт не бросает исключений на конфликт: `record()` возвращает `bool` — `true`, если голос вставлен, `false`, если был конфликт. Доменный `AlreadyVotedException` бросает **handler** (`registerApproval`), см. 2.4.2.

## 2.4.2. Правило раннего решения (`registerApproval`)

Раздел 1 — источник истины: голос пишет **`handler->registerApproval()`**, и там же вызывается `handleApproved()`. Application-слой (`RegisterApprovalAction`) даёт транзакцию, блокировку строки и публикацию событий, но не принимает решение о кворуме.

```
RegisterApprovalAction::execute(id, responder, arguments): ApprovalResult
1. $action = repo->findForUpdate($id)          // SELECT … FOR UPDATE, null → 404
2. $result = handler->registerApproval($action, $responder, $arguments)
   ── внутри handler'а registerApproval ──
   a. status !== pending            → return CLOSED      (ничего не меняется)
   b. responder ∉ payload.consumers → throw DomainException (403)
   c. ok = votesRepo->record(ref, responder, decision); !ok → throw AlreadyVotedException (409)
   d. snapshot = votesRepo->snapshot(ref, total=count(consumers), quorum=quorumSize(action))
   e. snapshot.isSatisfied() && handleApproved(snapshot) → action.markApproved(); return ACCEPTED
      snapshot.isImpossible()                            → action.reject();      return DECLINED
      иначе                                              → return WAITING
3. Если результат ACCEPTED/DECLINED — repo->save($action) (в той же транзакции)
4. После commit (по результату):
   ACCEPTED → Event::dispatch(ActionApproved)  (ActionApproved ровно один раз)
   DECLINED → Event::dispatch(ActionRejected)
   WAITING/CLOSED/ошибка → событий нет
```

`quorum` определяется в конкретном handler'е через `quorumSize($action)` (MVP `RequestedActionStudentToTeacher` — `1`; при `total=1` любое решение закрывает агрегат в тот же вызов).

`handler->registerApproval()` возвращает **новый** экземпляр агрегата (`markApproved`/`reject` неизменяемы, 2.3) вместе с `ApprovalResult` — для этого возвращается `RegisterApprovalOutcome` DTO (`{ RequestedAction $action, ApprovalResult $result }`). По нему Application понимает, надо ли звать `save()` и какое событие эмитить.

`handleApproved()` — метод политики handler'а: «считать ли кворум сошедшимся», вызывается **внутри** `registerApproval` (как в разделе 1). Для простых handler'ов — `return true`; для сложных — учитывает собственную логику поверх `QuorumSnapshot`. Расчёт «кворум недостижим» (DECLINED) остаётся в `registerApproval` — по `snapshot.isImpossible()`.

**Несколько конкурентных `registerApproval`** сериализуются блокировкой строки агрегата (`SELECT … FOR UPDATE` внутри транзакции `RegisterApprovalAction`); при этом `INSERT` в votes успевает лишь один — второй получает `AlreadyVotedException`, если тот же приёмщик. Разные приёмщики не мешают друг другу.

## 2.5. Обработчик RequestedAction (handler)

- Контракт `RequestedActionHandler` (интерфейс) описывает методы из раздела 1: `canGetRequestForm`, `getRequestForm`, `registerUserRequest`, `sendRequestNotifications`, `getRequestNotificationConsumers`, `getRequestNotificationMessage`, `getNotificationChannels`, `canGetApprovalForm`, `getApprovalForm`, `registerApproval`, `handleApproved`, `sendApprovalNotifications`, `getApprovedNotificationMessage`, `sendRejectionNotifications`, `getRejectedNotificationMessage`, `reject`, `sendDoneNotifications`, `getDoneNotificationMessage`, `sendErrorNotifications`, `getErrorNotificationMessage`, `handle`.
- Один класс = один alias. Alias хранится в `payload`? Нет — в поле `handler` (string). Резолв alias → инстанс через `RequestedActionHandlerRegistryInterface` (реализация — `RequestedActionHandlerRegistry`, карта alias → FQCN, резолв через DI-контейнер).
- `RequestedActionStudentToTeacher` — первая реализация в `app/Domain/RequestedActions/Handlers/`. Может зависеть от портов (например, `UserRelationRepositoryInterface`, `RequestedActionVotesRepositoryInterface` для записи голоса), инъекция через конструктор; регистрируется в `RequestedActionServiceProvider`.
- `getRequestForm`/`getApprovalForm` возвращают массив структуры из раздела 1; обёртка — VO `ApprovalMessage` для передачи в уведомления (используется только для рендера).
- `registerApproval($action, $responder, $arguments): RegisterApprovalOutcome` — **пишет голос сам** (через `RequestedActionVotesRepositoryInterface::record(...)`) и **сам вызывает `handleApproved()`**, как в разделе 1. Возвращает `RegisterApprovalOutcome { RequestedAction $action, ApprovalResult $result }`. Какие методы handler'а нужны:
  - `quorumSize(RequestedAction $action): int` — сколько accept-голосов достаточно для `ACCEPTED`. MVP `RequestedActionStudentToTeacher` — `1`.
  - `handleApproved(QuorumSnapshot $snapshot): bool` — используется внутри `registerApproval` (раздел 1). MVP — `return true` (кворум сводится к одному accept-голосу).
  - Handler сам решает, какой `VoteDecision` писать: разбор `$arguments` (`approve: true|false`) — его ответственность.
  - `getApprovalForm` также обязан отклонять приёмщика вне `payload.consumers` (403).
- `handle()` — обязан быть идемпотентным; при невозможности выполнить бросает `FatalException`-наследник с `error_code`.
- `handle()` и `sendErrorNotifications()` **не проходят** через `ErrorCollector` (раздел 1).

## 2.6. Application-слой (use-cases)

**`CreateRequestedActionAction`** (`execute(UserId $initiator, array $arguments, string $handlerAlias): RequestedAction`)
1. Резолв обработчика, `canGetRequestForm()` (ошибка → `DomainException` → 4xx).
2. `getRequestForm()` → показ формы пользователю (этот шаг на уровне HTTP-контроллера; `execute` вызывается после подтверждения).
3. `registerUserRequest($arguments)` возвращает `bool`. `false` → возврат без создания агрегата.
4. Построение `payload` с обязательным ключом `consumers` (дефолт `[123]` / текущий teacher id).
5. Идемпотентность — на DB (детали — 2.8.1): `INSERT … ON CONFLICT DO NOTHING RETURNING id` в активную помесячную субпартицию. Если `RETURNING` пуст — конфликт по UNIQUE `(user_id, handler, payload_hash)` → `repo->findActiveByIdentity(...)` → вернуть существующий агрегат, **не эмитить** `ActionRequested`.
6. Иначе (INSERT успешен) — агрегат в статусе `created`; после commit — `Event::dispatch(new ActionRequested(...))` в параметрах `RequestedActionId, handler, userId, arguments, createdAt`.
7. Ошибки классифицируются `ErrorCollector` (см. 2.9).

**`RegisterApprovalAction`** (`execute(RequestedActionId $id, UserId $responder, array $arguments): ApprovalResult`)
1. Внутри `transactional(...)` загрузить агрегат **`FOR UPDATE`** (`repo->findForUpdate`) — сериализует конкурентные `registerApproval` (не найден → `DomainException` → 404).
2. Вызвать `$outcome = $handler->registerApproval($action, $responder, $arguments)` (раздел 1): голос, пересчёт кворума, `handleApproved()`, переход статуса — всё внутри handler'а. `handler` резолвится по `$action->handler()` через `RequestedActionHandlerRegistryInterface`.
3. Если `$outcome->result` ∈ {`ACCEPTED`, `DECLINED`} → `repo->save($outcome->action)` (`WHERE id = ? AND status = 'pending'`, оптимистичная блокировка, 2.7).
4. Транзакция коммитится; **после commit** эмитится одно событие (по `$outcome->result`):
   - `ACCEPTED` → `ActionApproved` (200);
   - `DECLINED` → `ActionRejected` (200);
   - `WAITING` (200) / `CLOSED` (409) → событий нет.
5. `handler->registerApproval` может бросить `DomainException`-наследника (`AlreadyVotedException` → 409, `NotAConsumerException` → 403, `LearnNotFound`-аналог `RequestedActionNotFound` → 404). Такие исключения уходят наружу как 4xx, транзакция откатывается, `ActionError` не эмитится.
6. (UI-путь) `canGetApprovalForm`/`getApprovalForm` — отдельные read-эндпоинты, вызываются до `registerApproval`; соответствующий им Application-Action только читает и не пишет.

**Почему решение в handler'е, а не в Action.** Раздел 1 — источник истины: кворумная политика (что считать accept-ом, достаточно ли одного ответа) специфична для конкретного `RequestedAction`. Application-слой оставляет за собой механическую обвязку (транзакция, `FOR UPDATE`, `save`, публикация событий) и не знает, какая политика кворума выбрана.

**`HandleApprovedAction`** (`execute(RequestedActionId $id): void`)

Разделение на **две транзакции** — принципиальное. Попытка (attempt) коммитится независимо от исхода `handle()`; иначе падение откатило бы и запись attempt, и stale-recovery не смог бы отличить «не запускался» от «запускался и падал» (раздел 1, 2.8.2).

1. **Транзакция А (фиксация попытки):** `attemptsRepo->start(ref)` — INSERT строки в `requested_action_handle_attempts` (`started_at = now()`, `finished_at = NULL`). Коммит.
2. **Транзакция Б (работа с агрегатом):**
   - повторно загрузить агрегат `FOR UPDATE` (SKIP LOCKED уже держит воркер); если `status !== approved` — выйти (идемпотентно, другой воркер уже обработал);
   - `handler->handle($action)`;
   - успех → `markDone()` + `complete=true` + `save()`;
   - `FatalException` → `markError(code, detail)` + `save()`; `complete` остаётся `false` (по разделу 1 его ставит только stale-recovery).
3. **После коммита Б:** `attemptsRepo->finish(attemptId, 'ok' | errorCode)` (снова отдельная короткая транзакция) + `Event::dispatch(ActionDone | ActionError)`.
4. **`RetryableException`:** транзакция Б откатывается; `attemptsRepo->finish(attemptId, errorCode)` выполняется **после** отката и исключение пробрасывается наружу → воркер ретраит по `$tries`/`$backoff`. Строка attempt из транзакции А уже закоммичена, поэтому «попытка была» зафиксирована даже при полном откате Б.
- `updated_at` агрегата при retry не двигается (транзакция Б откатилась), поэтому T2 в stale-recovery отмеряется от `updated_at` последнего успешного изменения. Это осознанно: T2 — это «7 дней после последнего сдвига», а не «7 дней после N попыток». `$tries × $backoff` воркера гарантированно меньше T1 = 24ч (2.8.2).

## 2.7. Репозиторий и Eloquent-модель

- Порт `RequestedActionRepositoryInterface`: `find(Id): ?RequestedAction`, `findForUpdate(Id): ?RequestedAction` (SKIP LOCKED / FOR UPDATE), `save(RequestedAction): RequestedAction` (insert/update по `id`), `findActiveByIdentity(int $userId, string $handler, string $payloadHash): ?RequestedAction` для идемпотентности (см. 2.8.1), `nextApprovedBatch(int $limit): array<RequestedAction>`.
- Порт `RequestedActionHandleAttemptsRepositoryInterface` (новый): `start(RequestedActionRef $ref): int` (INSERT, `finished_at = NULL`, возвращает `attemptId`), `finish(int $attemptId, string $outcome): void` (`finished_at = now()`, `outcome`), `hasAttempts(RequestedActionRef $ref): bool`, `deleteForLeaf(DateTimeImmutable $from, DateTimeImmutable $to): int` (symmetrically to votes, для retention).
- Реализация `EloquentRequestedActionRepository` + маппер `RequestedActionMapper` (as `Learn`↔`Learn` в проекте).
- Тонкая модель `App\Models\RequestedAction`: `$table = 'requested_actions'`, `$casts` для `payload => array`, `status => RequestedActionStatus::class`, `complete => bool`, `$timestamps = true`, трейт `UsesTimestamptz`. Доменной логики нет.
- Метод `save()` для UPDATE использует `WHERE id = ? AND status = :oldStatus` (оптимистичная блокировка) для защиты от гонок pending→approved/rejected.
- **Кворумные голоса — отдельный репозиторий.**
  - Порт `RequestedActionVotesRepositoryInterface`: `record(RequestedActionRef $ref, UserId $responder, VoteDecision $decision): void` (INSERT…ON CONFLICT DO NOTHING), `snapshot(RequestedActionRef $ref, int $total, int $quorum): QuorumSnapshot`, `countAll(RequestedActionRef $ref): int`.
  - Реализация `EloquentRequestedActionVotesRepository` + `RequestedActionVoteMapper`.
  - Тонкая модель `App\Models\RequestedActionVote`: `$table = 'requested_action_votes'`, `$timestamps = false` (есть `decided_at`), `$casts` `decision => VoteDecision::class`, `decided_at => datetime`.
  - `RequestedActionRef` — VO `(int id, bool complete, DateTimeImmutable createdAt)`. Собирается методами построения из агрегата. Нужен потому, что FK на партиционированную таблицу требует весь составной ключ.
  - `snapshot()` считает два `COUNT` (accepted, declined) — `SELECT decision, COUNT(*) FROM requested_action_votes WHERE (id, complete, created_at) = (?, ?, ?) GROUP BY decision`.

- **Миграция votes** (без FK на `requested_actions` — причина в 2.4.1):
  ```php
  Schema::create('requested_action_votes', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('requested_action_id');
      $t->boolean('requested_action_complete');
      $t->timestampTz('requested_action_created_at');
      $t->foreignId('responder_user_id')->constrained('users')->cascadeOnDelete();
      $t->string('decision', 16);
      $t->timestampTz('decided_at')->default(now());

      $t->unique(
          ['requested_action_id', 'requested_action_complete', 'requested_action_created_at', 'responder_user_id'],
          'requested_action_votes_unique'
      );
      $t->index('requested_action_created_at', 'votes_created_at_idx');
  });
  // Raw PG: CHECK (Ларавел Schema не умеет именованный CHECK с IN-списком через enum-подобный синтаксис)
  DB::statement("ALTER TABLE requested_action_votes ADD CONSTRAINT votes_decision_check
      CHECK (decision IN ('accepted','declined'))");
  ```
  Проверено на PG 17.11: UNIQUE отклоняет повторный голос; FK на `users` работает штатно.
  Целостность «голос ↔ агрегат» обеспечивается приложением (агрегат загружен `FOR UPDATE` до `record()`) и явной очисткой в retention (`votes->deleteForLeaf(...)` перед `DROP TABLE`).

- **Миграция handle attempts** (под stale-recovery, 2.8.2):
  ```php
  Schema::create('requested_action_handle_attempts', function (Blueprint $t) {
      $t->id();
      $t->unsignedBigInteger('requested_action_id');
      $t->boolean('requested_action_complete');
      $t->timestampTz('requested_action_created_at');
      $t->timestampTz('started_at')->default(now());
      $t->timestampTz('finished_at')->nullable();
      $t->string('outcome', 64)->nullable();       // 'ok' | error_code
      $t->index(
          ['requested_action_id', 'requested_action_complete', 'requested_action_created_at'],
          'handle_attempts_ref_idx'
      );
      $t->index('requested_action_created_at', 'handle_attempts_created_at_idx');
  });
  ```
  Без FK по той же причине, что и votes: строка агрегата переезжает между партициями при `complete=true`. Чистка — `attempts->deleteForLeaf(...)` в retention.

- **`RequestedActionRef`:** агрегат `RequestedAction` при загрузке знает `id`, `complete`, `createdAt` — метод `ref(): RequestedActionRef`. Все методы портов голосов и attempt-ов принимают `RequestedActionRef` — он задаёт ключ идентичности `(requested_action_id, requested_action_complete, requested_action_created_at)` для UNIQUE-голоса и INDEX-attempt-а.

## 2.8. Схема БД: двухуровневое партиционирование (pgsql)

- Миграция создаёт корневую секционированную таблицу raw-PG-DDL:
  - `PARTITION BY LIST (complete)` → две партиции `requested_actions_active` (`complete = false`) и `requested_actions_complete` (`complete = true`).
  - Каждая субпартиция — `PARTITION BY RANGE (created_at)` помесячно (`requested_actions_active_yearmonth`), формат `<base>_<Y_m>`.
  - PK — составной `(id, complete, created_at)` (ограничение PG: PK партиционированной таблицы должен включать ключи секционирования всех уровней). `id` — `BIGSERIAL`.
  - Поля — по разделу 1 (`user_id`, `handler`, `payload jsonb`, `status`, `complete`, `error_code`, `error_detail`, `created_at`, `updated_at`), плюс генерируемая `payload_hash`.
  - Идемпотентность — UNIQUE-индекс **на каждом помесячном листе активной ветки**: `UNIQUE (user_id, handler, payload_hash)` + `INSERT … ON CONFLICT DO NOTHING` (без колонок). Детали, ограничения и проверки — 2.8.1.
  - Индексы на листе: `UNIQUE (user_id, handler, payload_hash)` (идемпотентность); `INDEX (status, updated_at) WHERE status = 'approved'` (для воркера handle); PK по `(id, complete, created_at)` уже покрывает `created_at` для retention.
  - `payload_hash` — генерируемая колонка `GENERATED ALWAYS AS (md5(payload::text)) STORED` (PG 17.11, проверено на Sail). **Нельзя** использовать как ключ партиционирования (`cannot use generated column in partition key`) — но в UNIQUE и `ON CONFLICT` работает.
  - `CHECK (status IN (...))`, `CHECK (complete IN (false, true))`, `CHECK (decision IN ('accepted','declined'))` (nullable, см. 3.13).
- Управление партициями реализуется через Application Action (`EnsureRequestedActionPartitionAction`) — создание очередной помесячной субпартиции перед INSERT (idempotent `CREATE TABLE IF NOT EXISTS ... PARTITION OF ...`). Вызывается из `CreateRequestedActionAction`.
- Retention (раздел 1): `requested_actions_retention_worker` (раз в час) и `prune_complete_requested_actions` (раз в сутки) — команды Artisan, реализуют `DETACH CONCURRENTLY` + `DROP` **помесячных листьев**. `DETACH` целой ветки `requested_actions_complete` и `DELETE` по завершённой ветке **не используются**. Детали, порядок восстановления после сбоя, stale-recovery — 2.8.2.

### 2.8.1. Идемпотентность `registerUserRequest` (детально)

**Требование раздела 1** (после правки): идемпотентность по `(user_id, handler, md5(payload))` **в пределах активной ветки** (`complete = false`). Дубликат возвращает существующий агрегат, `ActionRequested` не эмитится. Физически — UNIQUE на каждой помесячной активной субпартиции; на стыке месяцев допускается два активных запроса.

**Проверенные факты PG 17.11** (Sail, схема `probe` создавалась и удалялась):

| Проверка | Результат |
|---|---|
| `LIST (complete)` → субпартиция `RANGE (created_at)` → помесячный лист | ✅ работает |
| `payload_hash GENERATED ALWAYS AS (md5(payload::text)) STORED` | ✅ работает |
| `payload_hash` в качестве ключа партиционирования | ❌ `ERROR: cannot use generated column in partition key` |
| `PARTITION BY LIST (constant)` | ❌ `cannot use constant expression as partition key` |
| `CREATE TABLE (…, created_at) PARTITION OF parent_by_complete` | ❌ `column "created_at" named in partition key does not exist` |
| UNIQUE `(user_id, handler, payload_hash)` **на корне** партиционированной таблицы | создаётся, но **уникальность не межпартиционная** |
| `UNIQUE (user_id, handler, md5(payload::text)) WHERE complete = false` (expression + partial) | индекс создаётся, но `ON CONFLICT` на него: ❌ `there is no unique or exclusion constraint matching the ON CONFLICT specification` |
| `UNIQUE (…)` **на помесячном листе** + `ON CONFLICT DO NOTHING` (без колонок) | ✅ `INSERT 0 1 / INSERT 0 0`; `{"a":1}` и `{"b":2,"a":1}` считаются дубликатом (jsonb канонизирует порядок ключей) |

**Принятый дизайн — на DB, без advisory-локов.**

```php
// EloquentRequestedActionRepository::insertActive()
// 1) Во внешней транзакции уже создана нужная помесячная субпартиция (2.8).
// 2) INSERT c ON CONFLICT DO NOTHING — БЕЗ колонок (иначе PG не подхватит UNIQUE на листе).
$row = DB::table('requested_actions_active')
    ->insertOrIgnore([...'status' => 'created', 'complete' => false...])
    ? DB::table('requested_actions_active')->where('payload_hash', $hash)
        ->where('user_id', $userId)->where('handler', $handler)->first()
    : null;

// 3) Если возвращён null — конфликт: существующий агрегат уже есть.
// 4) ActionRequested эмитится ТОЛЬКО когда строка действительно вставлена.
```

Реализация на Eloquent: `RequestedAction::query()->insertOrIgnore([...])`, затем `RETURNING`-эквивалент — либо через `RETURNING id` в raw-`DB::select`, либо через чтение по `(user_id, handler, payload_hash)`. Семантика: **если агрегат был вставлен — вернуть новый, если конфликт — вернуть существующий без эмита события.**

**Почему `ON CONFLICT DO NOTHING` без колонок.** PG подбирает уникальный индекс для `ON CONFLICT` только когда список колонок точно совпадает с индексом и индекс не partial/expression. Наш индекс `(user_id, handler, payload_hash)` **на листе** для `ON CONFLICT` (без колонок) подходит; вариант `ON CONFLICT (user_id, handler, md5(payload::text)) WHERE complete = false` — **нет** (проверено, см. таблицу выше). Это самая частая ошибка при реализации — закрепить в тесте.

**Почему PK `(id, complete, created_at)`.** PG требует, чтобы PK партиционированной таблицы содержал ключи секционирования **всех уровней**. Иначе `FOREIGN KEY` на `requested_actions_*` (например, для будущей таблицы голосов кворума) невозможен.

**Единственное ограничение (принято).** На стыке месяцев (`created_at` 2026-11-30 23:59:59 и 2026-12-01 00:00:01) два одинаковых активных запроса попадут в разные субпартиции, и UNIQUE их не поймает. Закрыть можно только hash-партиционированием — но это ломает retention по `created_at` (раздел 1). **Retention приоритетнее.**

**Границы идемпотентности:**
- Повторный запрос после `rejected`/`done` — это **новый** запрос (после `complete = true` строка уходит в завершённую ветку, UNIQUE на активном листе её не накрывает). Корректно.
- Два параллельных одинаковых POST — один `INSERT` успешен, второй `ON CONFLICT DO NOTHING` возвращает 0 строк → `SELECT` существующего → идемпотентный возврат без `ActionRequested`.
- `registerApproval` идемпотентен по состоянию и по голосу (см. ниже).

**`registerApproval` идемпотентен по состоянию и по голосу:**
- Строка агрегата под `SELECT … FOR UPDATE` в `RegisterApprovalAction`; параллельные `registerApproval` по одному агрегату не пересчитывают кворум одновременно.
- UNIQUE `(requested_action_id, requested_action_complete, requested_action_created_at, responder_user_id)` в `requested_action_votes` — повторный голос того же приёмщика → `AlreadyVotedException` (409, доменная, без `ActionError`). Заменяет «будущее хранилище» из раздела 1 — уже реализовано в этой же задаче.
- Повторное «принять» на уже `approved` — `CLOSED` (200→409), `ActionApproved` **не** переэмитится.
- Если приёмщик изменил решение (accepted → declined) — **не поддерживается**: голос фиксирован, повторный вызов даёт `AlreadyVotedException`. Если политика изменится — это `UPDATE` голоса + `RequestedActionVoteChanged` событие (вне текущего scope).

**`handle_approved_requested_actions` идемпотентен по воркеру:**
- `FOR UPDATE SKIP LOCKED` + `ORDER BY updated_at ASC` + чанки по 100 (раздел 1);
- `handle()` обязан быть идемпотентным самостоятельно; для `RequestedActionStudentToTeacher` это обеспечивает `UNIQUE (parent_user_id, child_user_id, relation)` в `user_relations` (миграция `2026_11_12_100000_create_user_relations_table.php:54`). `handle()` перед INSERT читает существующую связь внутри транзакции воркера: если связь есть — успешное завершение (не ошибка); если нет — INSERT. Различение «связь уже есть как результат этого агрегата» vs «связь есть по другой причине» — по полю `UserRelation.created_at` относительно `RequestedAction.created_at` (см. 2.9).

**Тесты (что закрепить):**
- `tests/Feature/Application/RequestedActions/IdempotencyTest.php`:
  - двойной `InsertActive` в одном месяце → `count = 1`, второй вызов не эмитит `ActionRequested` (через `Event::fake()`);
  - `{"a":1}` vs `{"b":2,"a":1}` → считается дубликатом;
  - после `complete = true` (переходом через `reject()` / `done`) повторный вызов создаёт **новый** агрегат;
  - `ON CONFLICT DO NOTHING` **без колонок** — regression-тест на синтаксис (при колонках PG вернёт `there is no unique or exclusion constraint`).
- Конкурентность (два соединения): харнесс `tests/Support/Concurrency/DoubleConnection.php` — обёртка над двумя `PDO`, `BEGIN`/`INSERT`/`COMMIT` вручную (без `pcntl`-инфраструктуры, проект её не использует).
- Граница месяца: двойной `InsertActive` с `created_at = 2026-11-30 23:59:59` и `2026-12-01 00:00:01` → **два** агрегата (ограничение принято, тест фиксирует поведение).
- Два параллельных `registerApproval` → второй `CLOSED`, `ActionApproved` ровно 1.
- `handle` дважды на одном агрегате — вторая итерация не пересоздаёт `UserRelation`, `handle()` возвращает успех (см. 2.9).

**Кворум — тесты (закрепить):**
- `tests/Feature/Application/RequestedActions/QuorumTest.php`:
  - MVP (M=1): accept → ACCEPTED, declined → DECLINED (ранний выход срабатывает на первом же ответе);
  - 3-of-4: 1 accept → WAITING; 2 accept → WAITING; 3 accept → ACCEPTED; `ActionApproved` ровно 1;
  - 3-of-4: 2 accept + 1 declined → WAITING (R=1 ≤ 4-3); +1 declined → DECLINED (`R=2 > 1`);
  - M=2, quorum=2, 1 declined → DECLINED сразу (`R=1 > 2-2=0`);
  - отвечает пользователь вне `consumers` → 403, статус не меняется, `ActionError` не эмитится;
  - повторный голос того же приёмщика → `AlreadyVotedException` → 409;
  - два конкурентных `registerApproval` разных приёмщиков → первый пишет голос, второй под `FOR UPDATE` берёт обновлённый агрегат и считает кворум по двум голосам.
- `tests/Feature/Infrastructure/RequestedActions/VotesRepositoryTest.php`:
  - FK отклоняет голос на несуществующий `RequestedActionRef`;
  - при DROP помесячного листа агрегата голоса удаляются каскадом;
  - `snapshot()` корректно агрегирует по `decision` и отдаёт `QuorumSnapshot` с правильными `remaining`/`isImpossible`.

### 2.8.2. Retention и stale-recovery (детально)

**Проверено на `postgres:17.11` (Sail):**

| Действие | Результат |
|---|---|
| `ALTER TABLE <mid> DETACH PARTITION <leaf> CONCURRENTLY` | ✅ |
| `ALTER TABLE <root> DETACH PARTITION <mid> CONCURRENTLY` (целая ветка) | ✅ — но по разделу 1 **не используется** |
| `DROP TABLE <отцепленный лист>` | ✅ |
| Данные из отцепленного листа исчезают из выборки по родителю | ✅ (1000 → 0) |
| INSERT в отцепленный лист по-прежнему проходит | ✅ (нужен guard на уровне Application) |

**Структура дерева партиций (фиксирует 2.8):**
```
requested_actions                          -- PARTITION BY LIST (complete)
├── requested_actions_active                -- complete = false, PARTITION BY RANGE (created_at)
│   ├── requested_actions_active_2026_11    -- лист
│   └── requested_actions_active_2026_12
└── requested_actions_complete              -- complete = true, PARTITION BY RANGE (created_at)
    ├── requested_actions_complete_2026_10
    └── requested_actions_complete_2026_11
```

**Три команды (не две).** С добавлением stale-recovery воркеров три:

| Команда | Artisan | Расписание |
|---|---|---|
| `requested_actions_retention_worker` | `requested-actions:retention` | `->hourly()` |
| `prune_complete_requested_actions` | `requested-actions:prune-complete` | `->dailyAt('03:00')` |
| `requested-actions:stale-recovery` | `requested-actions:stale-recovery` | `->hourly()` |

Все три — с `->withoutOverlapping()->onOneServer()->runInBackground()`.

**`requested-actions:retention` — пошагово:**
1. **Recovery-фаза** (`RecoverDetachedRequestedActionPartitionsAction`):
   - `SELECT p.relname, c.relname AS parent FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhparent JOIN pg_class p ON p.oid = i.inhrelid WHERE …` — находит текущие листья;
   - `SELECT … FROM pg_class WHERE relnamespace = 'public'::regnamespace AND relkind = 'r' AND relname ~ '^requested_actions_(active|complete)_\d{4}_\d{2}$'` минус найденные выше → **сироты** (отцепленные, но не удалённые);
   - для каждой сироты: `ALTER TABLE <parent> DETACH PARTITION <leaf> FINALIZE` (если PG вернула незавершённый DETACH), затем `DROP TABLE IF EXISTS <leaf>`.
2. **Основной проход:** для каждого листа `requested_actions_active_<Y_m>`, где `Y_m` старше N месяцев относительно `now()`:
   - `votes->deleteForLeaf($from, $to)` — `DELETE FROM requested_action_votes WHERE requested_action_created_at >= $from AND requested_action_created_at < $to` (границы месяца листа);
   - `attempts->deleteForLeaf($from, $to)` — то же для `requested_action_handle_attempts`;
   - `ALTER TABLE requested_actions_active DETACH PARTITION <leaf> CONCURRENTLY`;
   - `DROP TABLE <leaf>`.
   Каждый лист — **в собственной транзакции** (кроме `DETACH CONCURRENTLY`, который требует отдельной транзакции), отказ одного не останавливает остальные; ошибки логируются, processing продолжается. `deleteForLeaf` идемпотентены: повторный вызов по уже очищенному диапазону — 0 строк. Порядок важен: чистим голоса/attempts **до** DROP, потому что FK нет (2.4.1).
3. `lock_timeout` на сессию (например, 5с), чтобы не держать `SHARE UPDATE EXCLUSIVE` на родителе дольше разумного; при таймауте — пропуск листа до следующего часа.
4. **Recovery-фаза (шаг 1) тоже удаляет голоса/attempts для сирот.** Сирота — лист, отцепленный, но не удалённый. У неё может остаться непочищенный диапазон: `deleteForLeaf` для её `[from, to)` вызывается перед `DROP TABLE IF EXISTS <leaf>`.

**`requested-actions:prune-complete` — пошагово:**
- то же самое, но по листьям `requested_actions_complete_<Y_m>` старше M дней (с тем же порядком `deleteForLeaf(votes)` → `deleteForLeaf(attempts)` → `DETACH CONCURRENTLY` → `DROP`).
- Никакого `DETACH` на ветке `requested_actions_complete` целиком.

**`requested-actions:stale-recovery` — пошагово:**

Таблица `requested_action_handle_attempts` (2.7) — обязательная часть механизма, а не опция: без неё невозможно реализовать различение `HANDLE_STALE_NOT_STARTED` / `HANDLE_STALE_RETRY_EXHAUSTED` (раздел 1). Attempt-строка пишется `HandleApprovedAction` **до** вызова `handle()` (2.6), поэтому «есть строка в attempts» ⇔ «handle() запускался».

1. **T1=24ч, не было попыток:** выбрать `approved`-агрегаты (`complete=false`, `updated_at < now() - interval '24 hours'`), для которых в `requested_action_handle_attempts` **нет ни одной** строки с тем же `RequestedActionRef`.
   ```sql
   UPDATE requested_actions ra
      SET status = 'error',
          error_code = 'HANDLE_STALE_NOT_STARTED',
          error_detail = 'approved for more than 24h with no handle attempts',
          updated_at = now()
    WHERE ra.status = 'approved'
      AND ra.complete = false
      AND ra.updated_at < now() - interval '24 hours'
      AND NOT EXISTS (
            SELECT 1 FROM requested_action_handle_attempts a
             WHERE a.requested_action_id = ra.id
               AND a.requested_action_complete = ra.complete
               AND a.requested_action_created_at = ra.created_at
          )
   RETURNING ra.id, ra.complete, ra.created_at, ra.handler, ra.user_id;
   ```
2. **T2=7д, попытки были и падали:** выбрать `approved`-агрегаты (`complete=false`, `updated_at < now() - interval '7 days'`), у которых в `requested_action_handle_attempts` **есть** хотя бы одна строка (handle запускался и падал — успех перевёл бы в `done` и строка под это условие не попала бы: `status='approved'` уже отсекает успешные).
   ```sql
   UPDATE requested_actions ra
      SET status = 'error',
          error_code = 'HANDLE_STALE_RETRY_EXHAUSTED',
          error_detail = 'handle retries exhausted, approved for more than 7d',
          updated_at = now()
    WHERE ra.status = 'approved'
      AND ra.complete = false
      AND ra.updated_at < now() - interval '7 days'
      AND EXISTS (
            SELECT 1 FROM requested_action_handle_attempts a
             WHERE a.requested_action_id = ra.id
               AND a.requested_action_complete = ra.complete
               AND a.requested_action_created_at = ra.created_at
          )
   RETURNING ra.id, ra.complete, ra.created_at, ra.handler, ra.user_id;
   ```
3. **`complete=true` после UPDATE.** Для агрегатов, попавших под 1 или 2, выполнить `UPDATE requested_actions SET complete=true, updated_at=now() WHERE (id, complete, created_at) = ANY(...)`. Это **переезд строки** между партициями (`PARTITION BY LIST (complete)`), поэтому выполняется отдельным `UPDATE` **после** основного — тот же приём, что при `reject()`/`markDone()` (2.3). Attempts и votes при этом не меняются (FK нет, см. 2.4.1).
4. **Эмит `ActionError`.** `RETURNING` из шагов 1 и 2 собирает `(id, complete, created_at, handler, user_id)` в буфер; после **commit** — цикл по буферу, `Event::dispatch(new ActionError(...))`. Батчами по 100 (по 100 строк на транзакцию, не на событие).
5. **Идемпотентность.** Повторный прогон ничего не находит: строки уже `error`, `complete=true` — не проходят фильтр `status='approved' AND complete=false`. Если шаг 3 упал между 2 и 3 — следующий прогон повторит (строка ещё `approved`), а повторный `UPDATE` в шаге 3 идемпотентен (`WHERE complete=false`).
6. **`pending` не трогается** — раздел 1: TTL нет.

**Почему stale-recovery не может пересечься с `handle_approved`:**
- `handle_approved` выбирает строку `approved` через `SELECT … FOR UPDATE SKIP LOCKED` в транзакции Б (2.6) и держит блокировку до коммита.
- stale-recovery делает `UPDATE … WHERE status='approved' AND …` — блокируется на строке, полученной воркером handle; при этом `UPDATE` без `SKIP LOCKED` **ждёт** освобождения блокировки, а не пропускает. Чтобы не ждать, stale-recovery должен использовать `FOR UPDATE SKIP LOCKED`-совместимую выборку: сначала `SELECT … FOR UPDATE SKIP LOCKED LIMIT 100` список кандидатов, затем `UPDATE … WHERE id = ANY(...)` по уже заблокированным строкам. Так строка, которую сейчас обрабатывает `handle`, **не попадёт** в выборку stale-recovery, и race закрыт.
- Итог: `handle` и `stale-recovery` хватают непересекающиеся строки; каждая строка попадает ровно в один путь.

**Stale наблюдатель в логах.**
- Каждый `approved` старше 1ч без движения → `DEBUG`-лог (для алертов), старше 6ч → `WARNING`, старше T1(24ч) → `ERROR` и переход.
- Метрики: `requested_actions.approved_age_seconds` — количество approved-строк по buckets 1ч/6ч/24ч.

**Тесты (закрепить):**
- `tests/Feature/Infrastructure/RequestedActions/RetentionTest.php`:
  - DETACH CONCURRENTLY листа из `requested_actions_active` → `DROP` → родитель перестаёт видеть данные;
  - Recovery-фаза: имитировать сироту (создать таблицу `requested_actions_active_2025_01` и не привязать) → retention её находит и удаляет;
  - отказ одного DROP → следующий лист всё равно обрабатывается (ошибка не останавливает).
- `tests/Feature/Application/RequestedActions/StaleRecoveryTest.php`:
  - approved старше 24ч без попыток → `error` + `HANDLE_STALE_NOT_STARTED` + `complete=true` + `1×ActionError`;
  - approved старше 7д с попытками → `HANDLE_STALE_RETRY_EXHAUSTED`;
  - повторный вызов stale-recovery → 0 изменений и 0 событий;
  - `pending` старше 30д не трогается;
  - `approved` идёт под `handle_approved` → stale его пропускает (SKIP LOCKED).

## 2.9. Сбор ошибок (ErrorCollector)

- `App\Domain\RequestedActions\Errors\ErrorCollector` — единая точка обёртки вызовов методов RequestedAction (кроме `handle()` и `sendErrorNotifications()`).
- **Исключение внутри `handle()` (идемпотентность).** `handle()` идемпотентен сам по себе: например, для `RequestedActionStudentToTeacher` повторный вызов при уже существующей `UserRelation (parent_user_id, child_user_id, 'teacher'→'student')` — это **не** ошибка: `handle()` проверяет существующую связь и возвращает успех без INSERT. `UserRelationAlreadyExistsException` летит в `ErrorCollector` как `FatalException` **только** в том случае, когда существующая связь не является результатом этого агрегата (например, связь создана через админку, а агрегат всё ещё в `approved`). Различение — по времени создания `UserRelation` против `RequestedAction.created_at` (или по отдельному маркеру в связях, если такой будет введён).
- Классификация **по типу исключения**:
  - `DomainException` (и наследники) → проброс наружу, `status` не меняется, `ActionError` не эмитится. В HTTP-слое → 4xx (маппинг в `bootstrap/app.php`, как уже принято).
  - `RetryableException` → попытка повтора в очереди; при исчерпании retry — переход в `error` + `ActionError`.
  - `FatalException` (и наследники) → сразу `status=error`, `error_code`/`error_detail` заполнены, сохранить, `Event::dispatch(ActionError)`.
- `complete` при переходе в `error` не меняется автоматически (определяется в разделе Retention).

## 2.10. События и слушатели

Доменные события — `final readonly class` в `app/Domain/RequestedActions/Events/`, только `public readonly` свойства:
- `ActionRequested(int $requestedActionId, string $handler, int $userId, array $arguments, DateTimeImmutable $createdAt)`
- `ActionApproved(int $requestedActionId, string $handler, int $userId, DateTimeImmutable $approvedAt, int $approvedBy)`
- `ActionRejected(int $requestedActionId, string $handler, int $userId, DateTimeImmutable $rejectedAt, int $rejectedBy)`
- `ActionDone(int $requestedActionId, string $handler, int $userId, DateTimeImmutable $doneAt)`
- `ActionError(int $requestedActionId, string $handler, int $userId, string $errorCode, string $errorDetail, DateTimeImmutable $errorAt)`

Регистрация — **вручную** в `RequestedActionServiceProvider::boot()`:
```php
Event::listen(ActionApproved::class, SendApprovalNotifications::class);
Event::listen(ActionRejected::class, SendRejectionNotifications::class);
Event::listen(ActionDone::class,     SendDoneNotifications::class);
Event::listen(ActionError::class,    SendErrorNotifications::class);
```
`ActionRequested` **не имеет слушателей** — уведомление приёмщику отправляет `requested_actions_messaging_worker` по статусу `created`.

Слушатели (в `app/Listeners/RequestedActions/`) — `implements ShouldQueueAfterCommit`, `$tries`/`$backoff`, идемпотентны, вызывают `$handler->sendXxxNotifications($action)` и `$handler->getXxxNotificationMessage($consumer)`.

## 2.11. Уведомления и каналы

- Базовый класс `RequestedActionNotification extends Notification implements ShouldQueue` (мультиканальный), канал `database` — минимум; дополнительные (mail/push/broadcast) определяются `handler->getNotificationChannels(User)`.
- Отправка: воркер `requested_actions_messaging_worker` берёт агрегаты `status=created`, для каждого `getRequestNotificationConsumers($action)`, `getRequestNotificationMessage($consumer)`, `Notification::send($consumer, new RequestedActionNotification($message, $channels))`. После постановки **всех** сообщений в очередь — `pending` + `updated_at` = now.
- `getNotificationChannels()` по умолчанию возвращает `['database']` (в проекте сейчас реализован только `mail`; канал `database` вводится этой задачей — таблица `notifications` Laravel).

## 2.12. Воркеры (Artisan-команды + расписание)

| Команда | Artisan | Расписание (`routes/console.php`) | Поведение |
|---|---|---|---|
| `requested_actions_messaging_worker` | `requested-actions:messaging` | `->everyMinute()->withoutOverlapping()->onOneServer()->runInBackground()` | created → очередь уведомлений → pending |
| `handle_approved_requested_actions` | `requested-actions:handle-approved` | то же | approved чанками по 100, `ORDER BY updated_at ASC`, `FOR UPDATE SKIP LOCKED`, вызывает `HandleApprovedAction`; → done / error, `ActionDone` |
| `requested_actions_retention_worker` | `requested-actions:retention` | `->hourly()` | recovery-фаза + `DETACH CONCURRENTLY` + `DROP` помесячных листьев `requested_actions_active_<Y_m>` старше N месяцев (см. 2.8.2) |
| `prune_complete_requested_actions` | `requested-actions:prune-complete` | `->dailyAt('03:00')` | `DETACH CONCURRENTLY` + `DROP` помесячных листьев `requested_actions_complete_<Y_m>` старше M дней (см. 2.8.2) |
| `requested-actions:stale-recovery` | `requested-actions:stale-recovery` | `->hourly()` | approved старше 24ч → `error` / `HANDLE_STALE_NOT_STARTED`; старше 7д с попытками → `error` / `HANDLE_STALE_RETRY_EXHAUSTED`; `complete=true`; `ActionError` (см. 2.8.2) |

Команды тонкие, логика — в Application Actions (`EnsureRequestedActionPartitionAction`, `HandleApprovedRequestedActionsAction`, `PruneRequestedActionPartitionsAction`, `RecoverDetachedRequestedActionPartitionsAction`, `StaleApprovedRequestedActionsAction`).

## 2.13. Маппинг HTTP

- POST-эндпоинты за `auth:sanctum` (проект использует Sanctum stateful SPA).
- `RegisterApprovalAction` → `ApprovalResult`: 200 accepted/declined/waiting; 409 closed.
- Доменные исключения → 4xx через маппинг в `bootstrap/app.php` (уже реализованный стиль `{message, code}`).

## 2.14. План работ (с учётом контекстного окна)

1. **Домен**: enum `RequestedActionStatus`, `ApprovalResult`, `VoteDecision`, VO `RequestedActionId`, `RequestedActionRef`, `RequestedActionPayload`, `QuorumSnapshot`, агрегат `RequestedAction` (create/restore/переходы/reject/markApproved/markDone/markError/инварианты), исключения `DomainException/RetryableException/FatalException/AlreadyVotedException`, события (5). Unit-тесты `tests/Unit/Domain/RequestedActions/`.
2. **Порты + контракт обработчика**: `RequestedActionRepositoryInterface`, `RequestedActionVotesRepositoryInterface`, `RequestedActionHandlerRegistryInterface`, интерфейс `RequestedActionHandler` (`quorumSize()`, `handleApproved()`), `ErrorCollector`.
3. **Инфраструктура**: миграция партиционированной таблицы (`PARTITION BY LIST (complete)` → `PARTITION BY RANGE (created_at)`), PK `(id, complete, created_at)`, `payload_hash` generated, UNIQUE `(user_id, handler, payload_hash)` **на каждом помесячном листе** (см. 2.8.1); миграция `requested_action_votes` с FK на `requested_actions (id, complete, created_at)` + UNIQUE + CHECK (см. 2.7); тонкие модели `App\Models\RequestedAction`, `App\Models\RequestedActionVote`; мапперы `RequestedActionMapper`, `RequestedActionVoteMapper`; `EloquentRequestedActionRepository` (`insertActive()`), `EloquentRequestedActionVotesRepository`, `RequestedActionHandlerRegistry`. Feature-тесты на PG: создание субпартиции, UNIQUE срабатывает внутри месяца, `ON CONFLICT DO NOTHING` без колонок, FK/UNIQUE votes, `ON DELETE CASCADE` при DROP листа. Sail: `./vendor/bin/sail artisan migrate`.
4. **Application**: `CreateRequestedActionAction` (через `insertActive()` — см. 2.8.1), `RegisterApprovalAction` (FOR UPDATE + votesRepo + QuorumSnapshot), `HandleApprovedAction`, `EnsureRequestedActionPartitionAction`. Unit/Feature-тесты: `IdempotencyTest`, `QuorumTest`, `VotesRepositoryTest`.
5. **Первый обработчик** `RequestedActionStudentToTeacher` (form/approval/handle → `UserRelation`, `quorumSize() = 1`, `handle` идемпотентный), `AlreadyVotedException` в маппинге 409.
6. **События и уведомления**: базовый `RequestedActionNotification`, 4 слушателя `SendApproval/Rejection/Done/ErrorNotifications`, регистрация в `RequestedActionServiceProvider`.
7. **Воркеры и расписание**: 5 Artisan-команд (`messaging`, `handle-approved`, `retention`, `prune-complete`, `stale-recovery`) + `Schedule::command(...)` в `routes/console.php`.
8. **HTTP**: контроллеры + API Resources, маппинг `ApprovalResult` и доменных исключений.
9. **Retention + stale**: команды `requested-actions:retention` (hourly, recovery-фаза + DETACH/DROP активных листьев), `requested-actions:prune-complete` (daily, завершённые листья), `requested-actions:stale-recovery` (hourly) + тесты `RetentionTest`/`StaleRecoveryTest` (см. 2.8.2).
10. **Финальная проверка**: `php artisan migrate`, `./vendor/bin/pest`, `./vendor/bin/pint --test`.

После каждого этапа — прогон соответствующих тестов и `./vendor/bin/pint` перед переходом к следующему, чтобы удерживать контекст и не накапливать регрессии.

## 2.15. Разрешение противоречий раздела 1 (нормативно для реализации)

Раздел 1 — источник истины, но содержит места, которые нельзя реализовать дословно или которые противоречат друг другу/фактам кода. Ниже — разрешения. Они **не меняют** раздел 1; они фиксируют, как агент обязан трактовать его на практике. При конфликте приоритет у этого подраздела, т.к. раздел 1 не может быть изменён.

1. **`complete` при `error`.** Раздел 1 говорит «`complete` не меняется автоматически» и одновременно в «Stale approved» — `complete=true` для T1/T2. Разрешение: переходы в `error`, инициированные **stale-recovery**, ставят `complete=true` **отдельным `UPDATE`** (переезд строки между партициями `PARTITION BY LIST (complete)`), в отдельной транзакции после смены статуса. Ошибки из `ErrorCollector` (`FatalException`, исчерпанный retry) и `HandleApprovedAction` `complete` **не** трогают — такие строки остаются в активной ветке и удаляются retention по N месяцев.
2. **Одно событие на переход.** `ActionApproved`/`ActionRejected` эмитятся ровно один раз. Разрешение: `repo->save()` для этих переходов — `WHERE id = ? AND status = 'pending'` (2.7); при `0 rows affected` → `DomainException` (конкурентная гонка), событие не эмитится. Повторный вызов при `status ≠ pending` → `CLOSED`, событие не переэмитится.
3. **`payload` «иммутабелен» (раздел 1) vs. запись `consumers` при создании.** Разрешение: payload пишется **один раз** в `CreateRequestedActionAction`; после INSERT payload не UPDATE-ится. `record()` голоса и переходы статуса payload не трогают.
4. **`handle()` — идемпотентность.** Раздел 1 требует идемпотентности и одновременно допускает исключение. Разрешение: для MVP наличие `user_relations (teacher_id, student_id, 'teacher')` — **успех** (идемпотентность), не `FatalException`. `UserRelationAlreadyExistsException` в MVP не бросается.
5. **`sendRequestNotifications` (раздел 1) vs. `requested_actions_messaging_worker` (раздел 1, «События»).** Разрешение: метод реализуется **внутри воркера**; `ActionRequested` слушателей не имеет. Метод вызывается воркером, не контроллером, чтобы `registerUserRequest` не блокировал HTTP.
6. **`id` — nullable до сохранения, а `RequestedActionRef` несёт `id: int`.** Разрешение: `RequestedActionId` — VO `int`; до INSERT агрегат `ref()` бросает `\LogicException` (программная ошибка, не доменная, не классифицируется).
7. **Инициатор vs. `consumers`.** Разрешение: `user_id` — текущий аутентифицированный пользователь; `consumers` по умолчанию `[teacher_id]`. Инициатор **не** добавляется в `consumers` автоматически. `getApprovalForm` возвращает 403, если `auth()->id() ∉ consumers`.
8. **`approve-action/form`** требует `action` (раздел 1) и `id` агрегата. Разрешение: `GET approve-action/form?action=StudentToTeacher&id=123`; невалидный `id` → `422`.
9. **`registerUserRequest` вернул `false`.** Разрешение: HTTP `422` с `{message, code: 'USER_REQUEST_DECLINED'}`, агрегат не создаётся, `ActionRequested` не эмитится.
10. **`CLOSED`.** Разрешение: HTTP `409` с `{message, code: 'REQUESTED_ACTION_CLOSED', status: <текущий>}`.
11. **`error_code`.** Разрешение: short name исключения без суффикса `Exception` (`HandlerNotFound`, `TeacherDeleted`, `UserRelationAlreadyExists`); для stale — `HANDLE_STALE_NOT_STARTED` / `HANDLE_STALE_RETRY_EXHAUSTED`; для неклассифицированного `FatalException` — `FATAL_UNCLASSIFIED`.
12. **`getNotificationChannels` — минимум `['database']`.** Разрешение: MVP-канал `database` (таблица `notifications` Laravel); `mail` и др. — per handler. Настроенные каналы проверяются по `config('services.*')`; отсутствующий канал → fallback на `database` + `WARNING`-лог.
13. **`ON CONFLICT` без колонок.** Разрешение: только `ON CONFLICT DO NOTHING` без списка колонок (иначе PG не подхватит UNIQUE на листе). Закреплено тестом `IdempotencyTest`.
14. **`record(): bool` (2.4.1) vs. `AlreadyVotedException`.** Разрешение: порт возвращает `bool` (`false` при конфликте), `AlreadyVotedException` бросает handler; HTTP — `409`. Это **не** `CLOSED`, а повторный голос.
15. **Alias обработчика.** Разрешение: alias (`'StudentToTeacher'`) хранится в колонке `handler`; реестр — карта `alias → FQCN` в `config/requested_actions.php`. Неизвестный alias → `HandlerNotFoundException` (`FatalException`) → `error`.
16. **`quorumSize($action)`** вызывается **внутри** `registerApproval`; MVP — `1`.
17. **MVP `RequestedActionStudentToTeacher`:** `consumers = [teacher_id]`, `quorum = 1`; `handle()` создаёт `UserRelation (parent_user_id=teacher_id, child_user_id=student_id, relation='teacher')`. Выбор teacher у student — вне scope.
18. **`ActionError` из `stale-recovery`:** эмит после commit по каждой строке, батчами (2.8.2); `SendErrorNotifications` идемпотентен.
19. **Переезд строки после `error`.** Смена `status` и `complete` — два отдельных `UPDATE` (две транзакции), т.к. `complete` — ключ секционирования LIST.
20. **Retention — `->hourly()`** (раздел 1), с `lock_timeout` (2.8.2); смена расписания — решение заказчика, по умолчанию как в разделе 1.
21. **`hash()` в PHP vs. `payload_hash` в PG.** Разрешение: идемпотентность обеспечивает **только** generated column `md5(payload::text)` в БД (jsonb канонизирует ключи). VO `RequestedActionPayload::hash()` — для логов/событий, в INSERT **не участвует** (устраняет расхождение PHP `json_encode` и PG `jsonb::text`).
22. **Таблица `requested_action_handle_attempts` (2.7).** Введена в раздел 1 («Retention → Stale approved»). Чистится `deleteForLeaf`. **Подтверждена заказчиком.**
23. **SoftDeletes агрегата — нет.** `complete=true` — живая строка (физически удаляется retention'ом). `find()`/`findForUpdate()` ищут по `RequestedActionRef` (`id + created_at`) без `WHERE complete=false`, т.к. строка переезжает между ветками.
24. **Attempts и переезд.** Строки attempts пишутся с `complete=false` и после переезда агрегата не меняются; проверки T1/T2 выполняются только для `approved`/`complete=false`.
25. **`payload.consumers` обязателен.** `registerUserRequest` кладёт его до INSERT; отсутствие → `DomainException` → `422`.
26. **`RequestedActionAlreadyClosedException`** — не обязателен; используется в HTTP-слое при попытке открыть форму закрытого агрегата; в `registerApproval` возвращается `CLOSED`, не исключение.
27. **Изоляция субпартиций в тестах.** Тесты, создающие листья, обязаны удалять их (`DROP TABLE IF EXISTS`) в `tearDown`; общая фикстура `tests/Support/RequestedActions/CleanupPartitions.php`.
28. **DDL партиций — только raw `DB::statement`.** `Schema::create` не умеет `PARTITION BY`. Порядок миграций: `create_requested_actions` → `create_requested_action_votes` → `create_requested_action_handle_attempts`.
29. **Тест «FK отклоняет голос» неверен.** FK на `requested_actions` нет (2.4.1/2.7). Заменить на: `record()` на несуществующий ref проходит INSERT; целостность держится `FOR UPDATE` в `RegisterApprovalAction` (проверяется раздельно).
30. **`RequestedActionVoteChanged`** — вне scope (голос фиксирован).

## 2.16. Явно вне scope

- **Feature/Unit-тесты** — в рамках задачи не пишутся (подтверждено заказчиком). Сценарии из 2.8.1/2.8.2/2.9 сохраняются в документе как приёмочные критерии на этап QA.
- Push/broadcast-каналы — нет; обязательный минимум `database`, `mail` — по желанию handler.
- Кворумы сложнее 1-of-1 — нет; MVP `RequestedActionStudentToTeacher`.
- UI/Blade — только JSON формы; фронтенд вне задачи.
- TTL для `pending` — нет (раздел 1).
- `UPDATE` голоса (`accepted → declined`) — нет.
- Выбор teacher у student — нет.

## 2.17. Definition of Ready (условия для старта)

- [x] Подтверждено, что `2.15`/`2.16` достаточны; новых противоречий не обнаружено.
- [x] Согласовано: attempts-таблица и её retention принимаются (п.22).
- [x] Согласовано: `N=6` (месяцы активной ветки) и `M=30` (дни завершённой ветки) — в `config/requested_actions.php`.
- [x] Согласовано: `lock_timeout` (5с) и расписание retention (`->hourly()`).
- [x] Согласовано: HTTP-статусы (`422` при `registerUserRequest=false`; `409` при `CLOSED`/`AlreadyVoted`).
- [x] Согласовано: `QUEUE_CONNECTION=database`; стандартный канал `database` (таблица `notifications`) реализуется в задаче.
- [x] Согласовано: feature-тесты в рамках задачи не пишутся; тестовые сценарии из 2.8/2.9 переносятся на этап QA.
- [x] Согласовано: изоляция субпартиций (п.27) — требование к тестам этапа QA, не к текущей задаче.
- [ ] Создана ветка `feature/requested-actions` (исполнителем перед стартом).
- [ ] `./vendor/bin/sail artisan migrate` / `./vendor/bin/pest` — только по явному указанию заказчика (см. вводную части 2).

## 2.18. Вердикт о готовности

**Готовность: ~80% для старта агентной разработки.** Документ покрывает домен, PG-партиционирование, воркеры, retention, stale-recovery, HTTP и тесты на достаточном уровне; раздел 2 корректно доразбирает раздел 1. **Блокирующих противоречий нет**, но старт зависит от условий в `2.15` (разрешения) и `2.17` (DoR):

- **Конфликт п.1 (`complete` при `error`)** — разрешён: stale ставит `complete=true` отдельным UPDATE, `ErrorCollector` — нет.
- **Конфликт п.21 (`hash` в PHP vs PG)** — разрешён: идемпотентность только через generated `payload_hash`.
- **Противоречие FK (п.29)** — тест `VotesRepositoryTest` исправлен.
- **Новая таблица `requested_action_handle_attempts` (п.22)** — обязательна, требует подтверждения заказчика.
- **Не заданы `N`, `M`** — конфигурируемые, требуют значений.
- **Идемпотентность на стыке месяцев** — осознанное ограничение, принято.
- **SoftDeletes** — не вводится (п.23).

**Заключение:** все содержательные и организационные вопросы закрыты — раздел 1 дополнен по attempts-таблице, `N=6`/`M=30`, `lock_timeout=5с`, HTTP-кодам 422/409 и каналу `database`. Feature/Unit-тесты **вне scope** текущей задачи; сценарии из 2.8.1/2.8.2/2.9 остаются приёмочными критериями этапа QA. План `2.14` выполняется без прогонов `migrate`/`pest` (только по явному указанию заказчика). **Документ готов к передаче исполнителю.**

---

# 3. Статус выполнения

> Раздел ведётся исполнителем. Источник истины — разделы 1 и 2 (не меняются). Здесь фиксируется фактическое состояние работ, чтобы продолжить реализацию в новом окне без повторного анализа.
>
> Ветка: `feature/requested-actions` (от `learn_domain`).
> Окружение: Laravel 13.8 / PHP 8.3 / PostgreSQL 17.11 / Sail (WSL2). `QUEUE_CONNECTION=database`.
> Прогоны `artisan migrate` и `pest` — **только по явному указанию заказчика** (пока не выполнялись).

## 3.1. Итог по этапам `2.14`

| Этап | Состав | Статус |
|------|--------|--------|
| 1 | Домен: Enum `RequestedActionStatus`, `ApprovalResult`, `VoteDecision`; VO `RequestedActionId`, `UserId`, `RequestedActionRef`, `RequestedActionPayload`, `QuorumSnapshot`, `ApprovalMessage`; агрегат `RequestedAction`; DTO `RegisterApprovalOutcome`; исключения; события; `ErrorCollector` | **готово** |
| 2 | Порты `Application/RequestedActions/Ports/*` + контракт `RequestedActionHandler` | **готово** |
| 3 | Инфраструктура: конфиг, 4 миграции, 3 тонкие модели, маппер, 3 Eloquent-репозитория, реестр, менеджер партиций | **готово** |
| 4 | Application-действия: `EnsureRequestedActionPartitionAction`, `CreateRequestedActionAction`, `RegisterApprovalAction`, `HandleApprovedAction`, `SendRequestNotificationsAction`, `GetRequestFormAction`, `GetApprovalFormAction` | **готово** |
| 5 | MVP-обработчик `RequestedActionStudentToTeacher` + нотификация `RequestedActionNotification` + `TeacherDeletedException`, `UserRelationAlreadyExistsException` | **готово** |
| 6 | `RequestedActionServiceProvider` (биндинги портов/`ErrorCollector`), листенеры уведомлений `ActionApproved/Rejected/Done/Error`, регистрация в `bootstrap/providers.php` | **готово** (см. 3.7) |
| 7 | 5 Artisan-команд (messaging, handle-approved, retention, prune-complete, stale-recovery) + расписание в `routes/console.php` | **готово** (см. 3.9) |
| 8 | HTTP: контроллер, маршруты (`request-action/*`, `approve-action/*`), маппинг исключений в `bootstrap/app.php` | **готово** (см. 3.10) |
| 9 | Application-действия retention / prune-complete / stale-recovery (внутри команд этапа 7) | **готово** (см. 3.8) |
| 10 | Финальный `pint`, обновление этого раздела | **готово** (см. 3.11) |

## 3.2. Созданные файлы (факт)

**Домен (`app/Domain/RequestedActions/`)**
- `Enums/RequestedActionStatus.php`, `Enums/ApprovalResult.php`, `Enums/VoteDecision.php`
- `Entities/RequestedAction.php` (иммутабельный агрегат: `create/restore`, `ref()` бросает `LogicException` до сохранения, `withId`, `markPending`, `markApproved`, `reject`, `markDone`, `markError`, `markComplete`)
- `ValueObjects/`: `RequestedActionId`, `UserId`, `RequestedActionRef`, `RequestedActionPayload`, `QuorumSnapshot`, `ApprovalMessage`
- `DTOs/RegisterApprovalOutcome.php`
- `Events/`: `ActionRequested`, `ActionApproved`, `ActionRejected`, `ActionDone`, `ActionError`
- `Errors/ErrorCollector.php` (константа `FATAL_UNCLASSIFIED`, `isDomain`, `collect`, `errorEvent`)
- `Exceptions/`: `RequestedActionDomainException`, `RequestedActionRetryableException`, `RequestedActionFatalException` (`errorCode()` без суффикса `Exception`), `HandlerNotFoundException`, `RequestedActionNotFoundException`, `NotAConsumerException`, `AlreadyVotedException`, `RequestedActionAlreadyClosedException` (`currentStatus()`), `InvalidStatusTransitionException`, `ConcurrentModificationException`, **`TeacherDeletedException`**, **`UserRelationAlreadyExistsException`**
- `Handlers/RequestedActionHandler.php` (контракт), **`Handlers/RequestedActionStudentToTeacher.php`**

**Application (`app/Application/RequestedActions/`)**
- `Ports/`: `RequestedActionRepositoryInterface`, `RequestedActionVotesRepositoryInterface`, `RequestedActionHandleAttemptsRepositoryInterface`, `RequestedActionHandlerRegistryInterface`
- `Actions/`: `EnsureRequestedActionPartitionAction`, `CreateRequestedActionAction`, `RegisterApprovalAction`, `HandleApprovedAction`, `SendRequestNotificationsAction`, `GetRequestFormAction`, `GetApprovalFormAction`

**Инфраструктура (`app/Infrastructure/`)**
- `Persistence/Repositories/EloquentRequestedActionRepository.php`, `EloquentRequestedActionVotesRepository.php`, `EloquentRequestedActionHandleAttemptsRepository.php`
- `Persistence/Mappers/RequestedActionMapper.php`
- `RequestedActions/RequestedActionHandlerRegistry.php`, `RequestedActions/RequestedActionPartitionManager.php`

**Прочее**
- `app/Models/RequestedAction.php`, `app/Models/RequestedActionVote.php`, `app/Models/RequestedActionHandleAttempt.php`
- **`app/Notifications/RequestedActions/RequestedActionNotification.php`** (канал `database` по умолчанию, `ShouldQueue`)
- `config/requested_actions.php`
- Миграции: `2026_12_01_100000_create_requested_actions_table.php`, `2026_12_01_100100_create_requested_action_votes_table.php`, `2026_12_01_100200_create_requested_action_handle_attempts_table.php`, **`2026_12_01_100300_create_notifications_table.php`** (таблицы `notifications` в проекте до задачи не было)

## 3.3. Ключевые технические решения, зафиксированные в коде

- **Идемпотентность (п.21):** только через generated column `payload_hash = md5(payload::text)`. `CreateRequestedActionAction` считает хэш локально (рекурсивный `ksort` + NFC через `Normalizer` + `json_encode`) только для read-back после вставки; в INSERT не участвует.
- **`ON CONFLICT DO NOTHING` без списка колонок** (п.13) — и в `insertActive`, и в `record()` голосов.
- **Оптимистичная блокировка `save()`:** `WHERE id = ? AND status = <предыдущий>`, предыдущий статус выводится из целевого (`Approved/Rejected→Pending`, `Done→Approved`, `Pending→Created`, `Error→Approved`, `Created→Created`). 0 строк → `ConcurrentModificationException`.
- **Раздельные UPDATE `status` и `complete`** (п.19): `complete` — ключ LIST-партиционирования; переезд в завершённую ветку (`markComplete`) — отдельный UPDATE после `ensureCompleteFor()` (DDL вне транзакции UPDATE).
- **`HandleApprovedAction`:** транзакция A — фиксация attempt (`attempts->start`) перед работой; транзакция B — `findForUpdate` + `handler->handle()`; `RequestedActionRetryableException` → rollback B, `finish(errorCode)`, rethrow (retry воркером); прочие `Throwable` → `markError` (без смены `complete`); успех → `markDone` → `saveDone` (отдельная транзакция) → `ensureCompleteFor` → `markComplete`. После — `finish()` и одно событие `ActionDone`/`ActionError`.
- **DDL партиций — только raw `DB::statement`** (п.28), вне транзакций INSERT/UPDATE.
- **События — только после commit**, из Application-слоя (конвенция проекта).
- **MVP handler `StudentToTeacher`:** payload `student_id`, `teacher_id`, `consumers=[teacher_id]`, `quorum=1`. `handle()` создаёт `UserRelation(parent_user_id=teacher, child_user_id=student, relation='teacher')`, идемпотентность: связи нет → INSERT; связь создана не раньше агрегата → успех; старше агрегата → `UserRelationAlreadyExistsException` (fatal). Удалённый преподаватель → `TeacherDeletedException` (fatal). Уведомления — через `RequestedActionNotification` (`database`), ошибки доставки не пробрасываются.
- **Партиционирование:** корень `requested_actions` `PARTITION BY LIST(complete)` → ветки `requested_actions_active` / `requested_actions_complete`, каждая `PARTITION BY RANGE(created_at)` по месяцам. Миграция создаёт только лист текущего месяца в активной ветке (с UNIQUE `(user_id, handler, payload_hash)` и частичным индексом `status, updated_at WHERE status='approved'`). Остальные листья — `RequestedActionPartitionManager` во время работы.

## 3.4. Что осталось сделать

**В рамках кода — всё закрыто.** Этапы 6–10 выполнены (3.7–3.11). Открытые пункты — только вне кода, по указанию заказчика / на QA:

- [x] `./vendor/bin/sail artisan migrate` — создать партиционированные таблицы + `notifications` — **выполнено заказчиком**.
- [ ] Приёмочные сценарии 2.8.1 / 2.8.2 / 2.9 на чистой БД (этап QA).
- [ ] (Опционально) `php artisan route:list` / `schedule:list` для визуальной проверки регистрации маршрутов и расписания.

## 3.5. Проверки и окружение

- `php vendor/bin/pint` по написанному скоупу (`app/Domain/RequestedActions`, `app/Application/RequestedActions`, `app/Infrastructure/RequestedActions`, элокуент-репозитории, маппер, модели, нотификация, `config/requested_actions.php`) — выполнен: **53 файла, 22 исправления стиля**.
- IDE-диагностика: ошибок в `RequestedActionStudentToTeacher`, `EloquentRequestedActionRepository`, `RequestedActionPartitionManager`, `RequestedActionMapper`, миграции `create_requested_actions`, `HandleApprovedAction`, `CreateRequestedActionAction` нет.
- **НЕ выполнялись** (запрещено без прямого указания заказчика): `artisan migrate`, `pest`.
- Особенности окружения/инструментов:
  - команды в WSL запускать как `cd /d C:\ && cmd.exe /c "wsl -d Ubuntu --cd /home/stedam <cmd>" 2>nul`; простые команды работают, вложенные кавычки/`bash -lc` — нет;
  - Pint запускать как `php vendor/bin/pint ...` (вызов `./vendor/bin/pint` блокируется политикой);
  - `TodoWrite` требует статусы `PENDING`/`IN_PROGRESS`/`COMPLETED` (заглавными);
  - PHP CLI в WSL: есть `intl` (`Normalizer`), `pdo_pgsql`, `pcntl`.

## 3.6. Напоминания о конвенциях проекта

- Слои: `app/Domain` (без Laravel), `app/Application` (`final readonly class *Action` c `execute(...)`, порты в `Ports`), `app/Infrastructure` (Eloquent-репозитории, мапперы, адаптеры), `app/Models` (тонкие модели), `app/Console/Commands`, `app/Listeners`, `app/Providers`.
- Обязательные биндинги уже есть в `AppServiceProvider`: `Clock → SystemClock`, `TransactionManagerInterface → EloquentTransactionManager`.
- Тонкие модели timestamptz используют трейт `App\Models\Concerns\UsesTimestamptz`.
- В проекте нет `app/Jobs`; периодические воркеры — Artisan-команды + `Schedule::command(...)` в `routes/console.php`.
- `App\Models\User` — `Notifiable` (нужно для канала `database`); связь с `user_relations` напрямую через таблицу (FK `parent_user_id`/`child_user_id` → `users`).

## 3.7. Журнал выполнения (этапы 6–10, все закрыты)

> Все 10 этапов `2.14` закрыты. Ниже — журнал по каждому этапу. `artisan migrate` / `pest` не выполнялись (запрет без указания заказчика); функциональная проверка на чистой БД — за этапом QA.

### Этап 6 — провайдер и листенеры уведомлений — ГОТОВО (3.7)

Создано:
- `app/Providers/RequestedActionServiceProvider.php` (зарегистрирован в `bootstrap/providers.php`):
  - биндинги портов: `RequestedActionRepositoryInterface → EloquentRequestedActionRepository`, `RequestedActionVotesRepositoryInterface → EloquentRequestedActionVotesRepository`, `RequestedActionHandleAttemptsRepositoryInterface → EloquentRequestedActionHandleAttemptsRepository`, `RequestedActionHandlerRegistryInterface → RequestedActionHandlerRegistry`;
  - `singleton(ErrorCollector::class)` (stateless);
  - в `boot()` — `Event::listen(...)` для `ActionApproved` / `ActionRejected` / `ActionDone` / `ActionError`.
- Листенеры `app/Listeners/RequestedActions/` (все `ShouldQueueAfterCommit`, `$tries = 3`, `$backoff = 30`, по образцу `RecalculateOnCourseChanged`):
  - `SendApprovalNotifications` → `handler->sendApprovalNotifications()`;
  - `SendRejectionNotifications` → `handler->sendRejectionNotifications()`;
  - `SendDoneNotifications` → `handler->sendDoneNotifications()`;
  - `SendErrorNotifications` → `handler->sendErrorNotifications()`.
  - Каждый: `actions->find($event->requestedActionId)`; агрегат не найден → `Log::warning` и выход (без исключения — пометка «уведомления не должны ронять воркер»); handler резолвится через реестр по `$event->handler`.
- `bootstrap/providers.php`: добавлен `App\Providers\RequestedActionServiceProvider::class`.

Проверки: IDE-диагностика — без ошибок; `php vendor/bin/pint` по провайдеру/листенерам/`bootstrap/providers.php` — 1 автоисправление (`bootstrap/providers.php`: `fully_qualified_strict_types`).

Дальше: этап 9 (Application-действия retention/prune/stale-recovery), затем этап 7 (команды + расписание), этап 8 (HTTP).

## 3.8. Этап 9 — Application-действия retention / prune / stale-recovery — ГОТОВО

Созданы в `app/Application/RequestedActions/Actions/`:
- `RetainActiveLeavesAction::execute(?int $months = null): array` — листья активной ветки, у которых верхняя граница месяца ≤ `now first day of -{active_months} month` → `votes->deleteForLeaf`, `attempts->deleteForLeaf`, `partitions->detachAndDrop`. Текущий и `active_months-1` предыдущих месяцев не трогаются.
- `PruneCompleteLeavesAction::execute(?int $days = null): array` — листья завершённой ветки с верхней границей ≤ `now - complete_days` → спутники + `detachAndDrop`.
- `DropOrphanLeavesAction::execute(): array` — `partitions->orphanLeaves()` → `partitions->dropOrphan()` (добивание сирот после сбоя между `DETACH CONCURRENTLY` и `DROP`).
- `RecoverStaleApprovedAction::execute(?int $limit = null): array{T1:int,T2:int}` — `lockStaleApprovedCandidates` под транзакцией (FOR UPDATE SKIP LOCKED); для каждой строки: `markStaleError` (status=error, complete не меняется) в своей транзакции → `markCompleteById` (complete=true) в отдельной транзакции (п.19) → событие `ActionError` после commit (п.18). Коды: `HANDLE_STALE_NOT_STARTED` (T1) / `HANDLE_STALE_RETRY_EXHAUSTED` (T2).
- Пороги берутся из `config/requested_actions.php` (`retention.active_months`, `retention.complete_days`, `stale.not_started_hours`, `stale.retry_exhausted_days`, `workers.batch_size`), параметры методов — для ручного прогона/тестов.

Проверки: IDE-диагностика — без ошибок; `php vendor/bin/pint app/Application/RequestedActions/Actions` — 11 файлов, 1 автоисправление.

Дальше: этап 7 (команды + расписание).

## 3.9. Этап 7 — 5 Artisan-команд + расписание — ГОТОВО

Созданы в `app/Console/Commands/` (стиль как `learn:make-grades`; зависимости через DI в `handle()`):
- `RequestedActionsMessagingCommand` — `requested-actions:messaging {--limit=}` → `SendRequestNotificationsAction::execute(limit)`; печатает число обработанных.
- `RequestedActionsHandleApprovedCommand` — `requested-actions:handle-approved {--limit=} {--dry-run}` → `nextApprovedBatch(limit)` + `HandleApprovedAction::execute(id)` по каждому; ошибка одного агрегата не останавливает остальные (`try/catch`, `warn`); при ошибках возвращает `FAILURE`.
- `RequestedActionsRetainCommand` — `requested-actions:retain {--months=}` → `DropOrphanLeavesAction` (до) → `RetainActiveLeavesAction::execute(months)` → `DropOrphanLeavesAction` (после); печатает удалённые листья.
- `RequestedActionsPruneCompleteCommand` — `requested-actions:prune-complete {--days=}` → сироты до/после + `PruneCompleteLeavesAction::execute(days)`.
- `RequestedActionsStaleRecoveryCommand` — `requested-actions:stale-recovery {--limit=}` → `RecoverStaleApprovedAction::execute(limit)`; печатает счётчики T1/T2.

Расписание в `routes/console.php` (все `withoutOverlapping()->onOneServer()->runInBackground()`):
- `requested-actions:messaging` — `everyMinute()`;
- `requested-actions:handle-approved` — `everyMinute()`;
- `requested-actions:retain` — `hourly()` (п.20);
- `requested-actions:prune-complete` — `dailyAt('02:30')`;
- `requested-actions:stale-recovery` — `dailyAt('02:00')`.

Проверки: IDE-диагностика — без ошибок; `php vendor/bin/pint` по командам и `routes/console.php` — 6 файлов, 1 автоисправление (`routes/console.php`: `fully_qualified_strict_types`). `artisan list`/`schedule:list` не запускались (не требуется без указания заказчика).

Дальше: этап 8 (HTTP).

## 3.10. Этап 8 — HTTP — ГОТОВО

**Контроллер** `app/Http/Controllers/Api/RequestedActionsController.php` (final readonly; только валидация + вызов Action):
- `form()` — `GET request-action/form` → `GetRequestFormAction::execute(userId, action, arguments)`.
- `request()` — `POST request-action/request` → `CreateRequestedActionAction::execute(...)`; `null` (handler вернул отказ) → **422** `{message, code: USER_REQUEST_DECLINED}`; успех → **201** `{id, handler, status}`.
- `approvalForm()` — `GET approve-action/form` → `GetApprovalFormAction::execute(id, userId, arguments)`; `id` обязателен (`required|integer|min:1`) → невалидный = 422.
- `approve()` — `POST approve-action/approve` → `RegisterApprovalAction::execute(id, userId, arguments)`; ответ `{id, result, status}`; при `ApprovalResult::CLOSED` → **409** `{message, code: REQUESTED_ACTION_CLOSED}`.
- Обязательный параметр `action` (`required|string|max:64`); инициатор/отвечающий — из `auth()`; остальные плоские ключи уходят в `arguments` (и далее в payload через handler).

**Маршруты** `routes/api.php` — группа `Route::middleware('auth:sanctum')`: `request-action/form`, `request-action/request`, `approve-action/form`, `approve-action/approve` (имена `api.request-action.form|request`, `api.approve-action.form|approve`).

**Маппинг исключений** `bootstrap/app.php` — карта `$requestedActionErrors` (по образцу `$courseErrors`, формат `{message, code}`):
- `NotAConsumerException` → 403 `NOT_A_CONSUMER`;
- `RequestedActionNotFoundException` → 404 `REQUESTED_ACTION_NOT_FOUND`;
- `AlreadyVotedException` → 409 `ALREADY_VOTED`;
- `RequestedActionAlreadyClosedException` → 409 `REQUESTED_ACTION_CLOSED`;
- `ConcurrentModificationException` → 409 `CONCURRENT_MODIFICATION`;
- `RequestedActionDomainException` → 422 `REQUESTED_ACTION_INVALID` (регистрируется после специализированных — те проверяются первыми).

Проверки: IDE-диагностика по контроллеру и `bootstrap/app.php` — без ошибок; `php vendor/bin/pint` по контроллеру, `routes/api.php`, `bootstrap/app.php` — 3 файла, 3 автоисправления.

## 3.11. Этап 10 — финальная проверка и закрытие — ГОТОВО

- Финальный `php vendor/bin/pint` по всему скоупу фичи (Domain/Application/Infrastructure RequestedActions, HTTP-контроллер, листенеры, 5 команд, провайдер, нотификация, 3 модели, конфиг, `routes/api.php`, `routes/console.php`, `bootstrap/app.php`, `bootstrap/providers.php`) — **PASS, 68 файлов, 0 исправлений**.
- `php -l`: контроллер, `RecoverStaleApprovedAction`, `RequestedActionsHandleApprovedCommand`, `routes/api.php`, `bootstrap/app.php`, `PruneCompleteLeavesAction` — синтаксис OK.
- IDE-диагностика: без ошибок.
- Повторная проверка (`pint --test`) по списку только файлов фичи (67 файлов) — **PASS**. Замечания стиля `pint` показываются лишь в ранее существовавших файлах `app/Console/Commands/*` (не относятся к фиче) — их не трогаем.

**Итог: все этапы 2.14 закрыты.** Остаются только действия вне кода (выполняются по явному указанию заказчика / на этапе QA):
1. ~~`./vendor/bin/sail artisan migrate`~~ — **выполнено заказчиком** (партиционированные таблицы и `notifications` созданы).
2. `./vendor/bin/pest` / приёмочные сценарии 2.8.1, 2.8.2, 2.9.

### 3.12. Проверка схемы в БД после `migrate` — ПРОЙДЕНО

`migrate` выполнен заказчиком (4 миграции фичи, batch 36, `pgsql` = PostgreSQL 17). Проверка через `psql` (`pg_catalog` / `information_schema`), только чтение:

| Проверка | Результат |
|---|---|
| `requested_actions` — `PARTITION BY LIST (complete)`, PK `(id, complete, created_at)` | OK |
| Ветки `requested_actions_active` (LIST false) / `requested_actions_complete` (LIST true), обе `RANGE (created_at)` | OK |
| Лист `requested_actions_active_2026_11` с границей `['2026-11-01','2026-12-01')` | OK |
| `payload_hash` — `GENERATED ALWAYS AS (md5(payload::text)) STORED` | OK |
| Лист активной ветки: `*_identity_unique` `(user_id, handler, payload_hash)` + `*_approved_idx` `(status, updated_at) WHERE status='approved'` | OK |
| CHECK `status IN (...)` и `complete IN (false, true)` на родителе | OK |
| FK на `requested_actions*` у votes/attempts — **отсутствуют** (по дизайну 2.4.1) | OK |
| `requested_action_votes`: UNIQUE `(requested_action_id, requested_action_complete, requested_action_created_at, responder_user_id)`, индекс по `requested_action_created_at` | OK |
| `requested_action_handle_attempts`: `ref_idx` + `created_at_idx` | OK |
| `notifications`: uuid PK, `morphs`, `text data`, `read_at` | OK |

Замечание по пустой `requested_actions_complete`: у веток нет строк — таблицы-ветки в `pg_class` отображаются как partitioned, лист завершённой ветки создаёт `ensureCompleteFor()` при первом `markComplete` (по дизайну миграции).

Технические заметки по окружению:
- `bash vendor/bin/sail ...` работает, но вывод через `sail` не доходит до piping — для проверок вызывался `docker exec stedam-laravel.test-1 psql -U sail -d laravel -h pgsql` с `-e PGPASSWORD=password`.
- Массовые DDL/скрипты удобнее запускать из файла-обёртки в `storage/app/` (прямые редиректы через `cmd.exe /c` ломаются).
- Временные файлы проверки (`storage/app/verify_schema*.sql|sh|out`) — удалить после приёмки.
3. Ветка `feature/requested-actions` — уже создана; merge/PR — по решению заказчика.

## 3.13. Поле `decision` у агрегата — ГОТОВО (код) / ожидает `migrate`

**Требование (часть 1):** `requested_actions.decision` — строковое представление результата обработки кворума по данным `requested_action_votes`; `accepted`/`declined` либо `NULL`.

**Как выполнено:**
- **Схема** — колонка `decision VARCHAR(16)` (nullable) + `CHECK (decision IN ('accepted','declined'))` (`requested_actions_decision_check`). CHECK допускает `NULL` (PG: `NULL IN (...)` → `NULL` → PASS).
  - в `2026_12_01_100000_create_requested_actions_table.php` — для чистой установки;
  - `2026_12_04_100000_add_decision_to_requested_actions.php` — инкрементальная миграция для уже применённой схемы (raw `ALTER TABLE requested_actions ADD COLUMN` + CHECK; на партиционированном родителе колонка/констрейнт распространяются на все листья; `down()` снимает CHECK и колонку).
- **Тип** — переиспользован `App\Domain\RequestedActions\Enums\VoteDecision` (`accepted|declined`), значения совпадают с `ApprovalResult`. Отдельный enum не вводился.
- **Доменный агрегат** `app/Domain/RequestedActions/Entities/RequestedAction.php`:
  - поле `?VoteDecision $decision`, геттер `decision(): ?VoteDecision`;
  - `markApproved()` ставит `VoteDecision::Accepted`, `reject()` — `VoteDecision::Declined` (переходы кворума закрывают решение);
  - `create()` — `null`, `restore()` принимает `decision`, `withId()`/`copy()` сохраняют значение. Прочие переходы (`markPending`, `markDone`, `markError`, `markComplete`) `decision` не меняют.
- **Enum** `ApprovalResult::toVoteDecision(): ?VoteDecision` — ACCEPTED→Accepted, DECLINED→Declined, WAITING/CLOSED→`null`.
- **Маппер** `RequestedActionMapper`: `toDomain`/`toDomainFromRow` читают `decision` (enum или `null`), `toInsertAttributes`/`toUpdateAttributes` пишут `decision->value`.
- **Репозиторий** `EloquentRequestedActionRepository::insertActive()` — колонка `decision` добавлена в INSERT.
- **Eloquent-модель** `App\Models\RequestedAction` — `decision` в `$fillable` и `$casts => VoteDecision::class`.
- **API** `RequestedActionListResource` отдаёт `decision` (значение enum или `null`).
- **API уведомлений** `GET /api/notifications` (`NotificationsController` → `ListNotificationsAction` → `EloquentNotificationsQuery`): в `data` уведомлений RequestedAction рядом с актуальным `data.status` подмешивается актуальный `data.decision` (`accepted`/`declined`/`null`) — вторым коррелированным подзапросом `requested_actions.decision` (тот же ключ `data.requested_action_id`, тот же индексный `id + LIMIT 1`).
- **Источник значения** — сами голоса: `registerApproval` пишет голос в `requested_action_votes` и по `QuorumSnapshot` выбирает ACCEPTED/DECLINED; эти же исходы выставляют `decision`, поэтому он согласован с содержимым `requested_action_votes`.

**Статус выполнения:**
- ✅ Код и миграции (create + incremental) изменены (IDE-диагностика по затронутым файлам — без ошибок).
- ⏳ `./vendor/bin/sail artisan migrate` **не запускался** — нужно применить `2026_12_04_100000_add_decision_to_requested_actions` в рабочей БД.
- ⏳ `pint`/`pest` не запускались (без указания заказчика).

## 3.14. Роль получателя в уведомлениях + decorator обогащения списка — ГОТОВО (код)

### Контракт «кто формирует уведомление»

Единственная точка формирования — handler RequestedAction (`RequestedActionStudentToTeacher`). Все фазы (`request/approved/rejected/done/error`) лишь триггерят его `sendXxxNotifications()`:
- текст/payload — `getXxxNotificationMessage(...)`;
- получатели — `getRequestNotificationConsumers()` / `initiator()` / `bothSides()`;
- создание объекта и `notify()` — приватный `notify()` → `new RequestedActionNotification(...)`.
Других `new RequestedActionNotification` в коде нет. `SendBrowserPushForDatabaseNotifications` (`NotificationSent`) `data` не меняет — только читает.

### Вариант 1C — сторона получателя известна при отправке

- `requested_actions.user_id` — всегда **инициатор**, `payload.consumers` — **одобряющие**. В `notify()` handler уже обходит конкретных получателей, поэтому роль известна заранее.
- Новый enum `App\Enums\RecipientRole: string` — `initiator` / `consumer`.
- Метод `recipientRole(RequestedAction, UserId): RecipientRole` — `initiator`, если `$user->value === $action->userId()->value`, иначе `consumer`.
- Все `getXxxNotificationMessage` добавляют в payload ключ `recipient_role` (значение enum). Токен используется в списке (decorator) и доступен фронту.
- `getDoneNotificationMessage` отдаёт **разные** `title`/`text` по роли: инициатор «Вас приняли в ученики», одобряющий «У вас новый ученик». Это единственное место, где тексты расходятся по стороне; остальные фазы (`approved`/`rejected` адресованы только инициатору, `request` — только одобряющим, `error` — обеим сторонам с одинаковым текстом).
- Поле `type` (`ActionNotificationType`) не меняется — это и есть «фаза», по которой группируется смысл.

### Вариант 2B — Decorator над `NotificationsQueryInterface`

- `EloquentNotificationsQuery` снова читает **только** `notifications` (id/type/data/поиск/сортировка) — из него удалены `requestedActionSub()` и `withActualStatus()`.
- Новый `App\Infrastructure\Notifications\RequestedActionNotificationsQuery implements NotificationsQueryInterface` — декоратор с зависимостью на `NotificationsQueryInterface` (внедряется базовый Eloquent-query):
  - вызывает базовый `paginate()`, затем для страницы собирает id RequestedAction-уведомлений (`type` = `NotificationType::RequestedAction`, есть `data.requested_action_id`);
  - **одним** запросом `SELECT id, status, decision, user_id, payload FROM requested_actions WHERE id IN (...)` подтягивает актуальное состояние;
  - подмешивает в `data`:
    - `status` — актуальный статус (`created|pending|approved|rejected|done|error`);
    - `decision` — итог кворума (`accepted|declined` / отсутствует);
    - `recipient_role` — из `data.recipient_role` (записан при отправке), fallback — вычисление по `user_id`/`payload.consumers` относительно получателя (`NotificationListFilter::notifiableId`);
  - «сиротские» уведомления (нет записи в `requested_actions` после retention) и прочие классы уведомлений — без изменений.
- Регистрация в `AppServiceProvider`: binding на интерфейс указывает на декоратор, базовый `EloquentNotificationsQuery` передаётся в конструктор декоратора.
- API-контракт `GET /api/notifications` не меняется; добавляется ключ `data.recipient_role`.
- Тест `tests/Feature/Api/NotificationsListTest.php` не пишется/не меняется (feature-тесты не пишутся по договорённости заказчика).
