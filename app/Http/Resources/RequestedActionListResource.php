<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\RequestedActions\Enums\VoteDecision;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Элемент списка «моих заявок» (GET /requested-actions).
 *
 * Отдаётся автору без утечки внутренних данных: payload отдаётся без ключа
 * `consumers`, а получатели представлены лёгкой проекцией (id + ФИО + email).
 * ФИО/email получателей предзагружаются запросом, чтобы не было N+1.
 *
 * `type` — alias обработчика и его локализованное название (ключ
 * `notifications.requested_action_types.<alias>`); `status` — значение enum, `localized_status` —
 * перевод (`notifications.statuses.<status>`).
 *
 * @property \App\Models\RequestedAction $resource
 */
class RequestedActionListResource extends JsonResource
{
    /** @var array<int, User> */
    private static array $recipients = [];

    /**
     * @param Collection<int, User> $recipients
     */
    public static function withRecipients(Collection $recipients): void
    {
        self::$recipients = $recipients->keyBy('id')->all();
    }

    public function toArray(Request $request): array
    {
        $payload = $this->payload ?? [];
        unset($payload['consumers']);

        return [
            'id' => $this->id,
            'type' => [
                'alias' => $this->handler,
                'label' => trans("notifications.requested_action_types.{$this->handler}"),
            ],
            'status' => $this->status->value,
            'decision' => $this->decisionValue(),
            'localized_status' => trans("notifications.statuses.{$this->status->value}"),
            'complete' => $this->complete,
            'error_code' => $this->error_code,
            'error_detail' => $this->error_detail,
            'recipients' => $this->recipients(),
            'payload' => (object) $payload,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Итоговое решение кворума (колонка `decision`): `accepted`/`declined`/null.
     * Принимаем и enum-каст, и «сырую» строку.
     */
    private function decisionValue(): ?string
    {
        $decision = $this->resource->decision;

        if ($decision instanceof VoteDecision) {
            return $decision->value;
        }

        return is_string($decision) && $decision !== '' ? $decision : null;
    }

    /**
     * Получатели заявки (порядок — как в payload.consumers).
     *
     * @return list<array{id: int, name: ?string, email: ?string}>
     */
    private function recipients(): array
    {
        $consumers = $this->payload['consumers'] ?? [];

        if (! is_array($consumers)) {
            return [];
        }

        $result = [];

        foreach (array_unique(array_map('intval', $consumers)) as $id) {
            $user = self::$recipients[$id] ?? null;

            $result[] = [
                'id' => $id,
                // ФИО из профиля (user_data), fallback — users.name.
                'name' => $user?->getFullName(),
                'email' => $user?->email,
            ];
        }

        return $result;
    }
}
