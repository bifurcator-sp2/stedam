<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Элемент списка уведомлений (лёгкая проекция).
 *
 * `type` — уже нормализованное КОРОТКОЕ имя класса уведомления
 * (например, `RequestedActionNotification`), не FQCN: контроллер
 * прогоняет его через App\Enums\NotificationType::shortNameOf().
 *
 * `date` — относительное время по локали приложения (`diffForHumans`),
 * напр. «5 минут назад»; локаль выставляет App\Http\Middleware\SetLocale.
 *
 * `read` — флаг прочтения (в БД `read_at IS NOT NULL`).
 *
 * @property array{id: string, type: string|null, data: array<string, mixed>, created_at: string, read_at: string|null} $resource
 */
class NotificationListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'type' => $this->resource['type'],
            'data' => $this->resource['data'],
            'date' => Carbon::parse($this->resource['created_at'])->diffForHumans(),
            'read' => $this->resource['read_at'] !== null,
        ];
    }
}
