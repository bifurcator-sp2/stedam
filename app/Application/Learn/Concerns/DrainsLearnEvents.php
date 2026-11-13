<?php

declare(strict_types=1);

namespace App\Application\Learn\Concerns;

use App\Domain\Learn\Entities\Learn;
use App\Domain\Learn\Events\LearnStatusChanged;
use Illuminate\Support\Facades\Event;

/**
 * Единообразная разводка доменных событий Learn во внешние порты (§17.1).
 *
 * Инвариант: pullEvents() ОЧИЩАЕТ буфер агрегата → каждое событие уходит
 * ровно один раз. Поэтому порядок в drainLearnEvents строгий:
 *
 *   1) аудит (StatusLoggerInterface) — чтобы не потерять запись, если
 *      подписчик шины упадёт;
 *   2) Event::dispatch() — для смежных контекстов (Course).
 *
 * Требует от использующего класса свойство $statusLogger
 * (App\Application\Learn\Ports\StatusLoggerInterface).
 */
trait DrainsLearnEvents
{
    private function drainLearnEvents(Learn $learn): void
    {
        foreach ($learn->pullEvents() as $event) {
            $this->logLearnEvent($event);
        }
    }

    /**
     * Первичный статус только что созданного прохождения.
     *
     * Домен не порождает это событие (id появляется лишь после save —
     * Learn::recordStatusChange, §17.5), поэтому его отправляет
     * Application-слой сразу после сохранения, через те же порты.
     */
    private function drainInitialStatus(Learn $learn): void
    {
        $this->logLearnEvent($learn->recordInitialStatus());
    }

    /**
     * Единая точка разводки: сначала аудит, затем шина.
     * Порядок важен — падение подписчика не должно терять запись аудита.
     */
    private function logLearnEvent(LearnStatusChanged $event): void
    {
        $this->statusLogger->log(
            learnId: $event->learnId,
            lessonId: $event->lessonId,
            status: $event->status,
            statusDate: $event->statusDate,
            scheduleDate: $event->scheduleDate?->value,
        );

        Event::dispatch($event);
    }
}
