<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Query-параметры для REST-фильтрации моделей.
 *
 * Поддерживаемые параметры запроса:
 *
 *  - `limit`   (int, по умолчанию 100) — размер страницы пагинации.
 *  - `select`  (string|array) — список колонок и/или имён связей.
 *                `*` или пусто — все колонки.
 *                Отсутствующие в таблице имена, совпадающие с методами-связями,
 *                подгружаются через `with()` (eager loading).
 *  - `filters` — массив фильтров в двух формах:
 *
 *    Форма A (структурированная):
 *      filters[0][key]=is_working
 *      filters[0][operator]=1
 *      filters[0][value]=1
 *
 *    Форма B (короткая):
 *      filters[is_working]=1
 *      filters[status][]=active
 *      filters[status][]=pending
 *
 *    Операторы формы A: = != > < >= <= in like ->
 *
 * Производительность:
 *  - Список колонок таблицы и список relations модели кэшируются (Cache) на 1 час.
 *  - Дополнительно мемоизируются в static-поле на время процесса (FPM-воркер / Octane).
 *  - Проверка колонок идёт по `array_flip` — O(1), без запросов в information_schema.
 *
 * @see \App\Traits\RequestFilterable::scopeFilterFromRequest()
 */
trait RequestFilterable
{
    /**
     * In-process memo: columns по таблице.
     *
     * @var array<string, array<int, string>>
     */
    protected static array $columnsMemo = [];

    /**
     * In-process memo: relations по классу модели.
     *
     * @var array<class-string, array<int, string>>
     */
    protected static array $relationsMemo = [];

    /**
     * Применяет select / with / filters / limit из Request к билдеру.
     *
     * @param  Builder<static>  $query
     * @param  Request          $request
     * @return Builder<static>
     *
     * @throws HttpResponseException при невалидных `limit`/`select`/`filters` (422)
     *
     * @example
     *   User::filterFromRequest($request)->paginate($request->integer('limit', 100));
     */
    public function scopeFilterFromRequest(Builder $query, Request $request): Builder
    {
        $model = $query->getModel();

        $request->merge([
            'limit'   => $request->input('limit', 100),
            'filters' => $request->input('filters', []),
            'select'  => $request->input('select', ['*']),
        ]);

        $this->validateFilterRequest($request);

        // Один поход в кэш схемы за запрос (далее — статический memo).
        $columns = $this->getColumnsCached($model);

        [$select, $with] = $this->resolveSelectAndRelations($request, $model, $columns);
        $query->with($with)->select($select);

        $this->applyRequestFilters($query, $request, $columns);

        return $query;
    }

    /**
     * Хелпер: сразу возвращает ResourceCollection с пагинацией.
     *
     * @template TResource of \Illuminate\Http\Resources\Json\JsonResource
     * @param  Request                  $request
     * @param  class-string<TResource>  $resourceClass
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     *
     * @example
     *   return User::resourceFromRequest($request, UserResource::class);
     */
    public static function resourceFromRequest(Request $request, string $resourceClass)
    {
        $limit = (int) $request->input('limit', 100);

        return $resourceClass::collection(
            static::query()->filterFromRequest($request)->paginate($limit)
        );
    }

    /** @internal */
    protected function validateFilterRequest(Request $request): void
    {
        $validator = Validator::make($request->all(), [
            'filters' => 'nullable|array',
            'select'  => 'nullable|array',
            'limit'   => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            throw new HttpResponseException(
                response()->json(['errors' => $validator->errors()->messages()], 422)
            );
        }
    }

    /**
     * Возвращает список колонок таблицы с кэшированием на 1 час.
     *
     * Ключ кэша включает connection, чтобы избежать коллизий между БД.
     *
     * @param  Model  $model
     * @return array<int, string>
     *
     * @internal
     */
    protected function getColumnsCached(Model $model): array
    {
        $connection = $model->getConnectionName() ?? (string) config('database.default');
        $table      = $model->getTable();
        $memoKey    = "{$connection}.{$table}";

        if (isset(self::$columnsMemo[$memoKey])) {
            return self::$columnsMemo[$memoKey];
        }

        return self::$columnsMemo[$memoKey] = Cache::remember(
            "request-filterable:columns:{$memoKey}",
            now()->addHour(),
            fn () => Schema::connection($model->getConnectionName())
                ->getColumnListing($table)
        );
    }

    /**
     * Возвращает имена публичных методов модели, возвращающих Relation,
     * с кэшированием на 1 час.
     *
     * @param  Model  $model
     * @return array<int, string>
     *
     * @internal
     */
    protected function getRelationsCached(Model $model): array
    {
        $class = $model::class;

        if (isset(self::$relationsMemo[$class])) {
            return self::$relationsMemo[$class];
        }

        return self::$relationsMemo[$class] = Cache::remember(
            "request-filterable:relations:{$class}",
            now()->addHour(),
            fn () => $this->detectRelations($model)
        );
    }

    /**
     * Разделяет запрошенные `select` на колонки и eager-load связи.
     *
     * @param  Request             $request
     * @param  Model               $model
     * @param  array<int, string>  $columns
     * @return array{0: array<int,string>, 1: array<int,string>}  [columns, relations]
     *
     * @internal
     */
    protected function resolveSelectAndRelations(Request $request, Model $model, array $columns): array
    {
        $select = $request->input('select');

        if (empty($select) || $select === '*' || $select === ['*']) {
            return [['*'], []];
        }

        $select = is_string($select) ? [$select] : (array) $select;

        $columnsFlip = array_flip($columns);

        $filtered = [];
        $external = [];

        foreach ($select as $column) {
            if (isset($columnsFlip[$column])) {
                $filtered[] = $column;
            } else {
                $external[] = $column;
            }
        }

        $with = [];
        if (!empty($external)) {
            $relations = $this->getRelationsCached($model);
            $relationsFlip = array_flip($relations);
            foreach ($external as $relation) {
                if (isset($relationsFlip[$relation])) {
                    $with[] = $relation;
                }
            }
        }

        // Первичный ключ всегда нужен Eloquent.
        $filtered[] = $model->getKeyName();
        $filtered   = array_values(array_unique($filtered));

        return [$filtered, $with];
    }

    /**
     * Применяет фильтры формы A и формы B.
     *
     * Колонки проверяются по переданному массиву ($columns) — никаких запросов в БД.
     *
     * @param  Builder<static>     $query
     * @param  Request             $request
     * @param  array<int, string>  $columns
     * @return void
     *
     * @internal
     */
    protected function applyRequestFilters(Builder $query, Request $request, array $columns): void
    {
        $allowedOperators = ['=', '!=', '>', '<', '>=', '<=', 'in', 'like', '->'];

        // O(1) lookup
        $columnsFlip = array_flip($columns);

        $filters = (array) $request->input('filters', []);

        foreach ($filters as $key => $filter) {
            // Форма A: filters[0][key]=...&filters[0][operator]=...&filters[0][value]=...
            if (is_numeric($key)) {
                if (!is_array($filter)) {
                    continue;
                }

                $operator = $filter['operator'] ?? '=';
                if (!is_string($operator)) {
                    $operator = '=';
                }
                $operator = urldecode($operator);

                $column = $filter['key'] ?? '';
                if (!isset($columnsFlip[$column])
                    || !in_array($operator, $allowedOperators, true)) {
                    continue;
                }

                $value = $filter['value'] ?? null;

                if ($operator === 'in' && is_array($value)) {
                    $query->whereIn($column, $value);
                } else {
                    $query->where($column, $operator, $value);
                }

                continue;
            }

            // Форма B: filters[is_working]=1 / filters[status][]=active
            if (!isset($columnsFlip[$key])) {
                continue;
            }

            if (is_array($filter)) {
                $query->whereIn($key, $filter);
            } else {
                $query->where($key, '=', $filter);
            }
        }
    }

    /**
     * Определяет имена публичных методов модели, возвращающих Relation.
     *
     * Не запускает методы, у которых указан return-type, заведомо не совместимый
     * с Relation — это отсекает `newQuery()`, `newCollection()`, `getFullNameAttribute()`
     * и т.п. без их выполнения.
     *
     * @param  Model  $model
     * @return array<int, string>
     *
     * @internal
     */
    protected function detectRelations(Model $model): array
    {
        $relations = [];
        $reflector = new ReflectionClass($model);
        $className = $reflector->getName();

        /** @var ReflectionMethod $method */
        foreach ($reflector->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Только собственные методы модели (не трейты базовых классов)
            if ($method->getDeclaringClass()->getName() !== $className) {
                continue;
            }

            // Скоупы и прочие методы с обязательными аргументами — не relation
            if ($method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            // Если указан конкретный класс-возврат, не совместимый с Relation — пропускаем,
            // НЕ вызывая метод.
            $returnType = $method->getReturnType();
            if ($returnType instanceof ReflectionNamedType
                && !$returnType->isBuiltin()
                && !is_a($returnType->getName(), Relation::class, true)
            ) {
                continue;
            }

            try {
                $result = $method->invoke($model);
            } catch (\Throwable $e) {
                continue;
            }

            if ($result instanceof Relation) {
                $relations[] = $method->getName();
            }
        }

        return $relations;
    }
}
