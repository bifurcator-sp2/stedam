<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Элемент списка уведомлений (лёгкая проекция).
 *
 * `type` — уже нормализованное КОРОТКОЕ имя класса уведомления
 * (например, `RequestedActionNotification`), не FQCN: контроллер
 * прогоняет его через App\Enums\NotificationType::shortNameOf().
 *
 * @property array{id: string, type: string|null, data: array<string, mixed>} $resource
 */
class NotificationListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'type' => $this->resource['type'],
            'data' => $this->resource['data'],
        ];
    }
}
