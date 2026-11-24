<?php

declare(strict_types=1);

namespace App\Domain\RequestedActions\Exceptions;

/**
 * Постоянная ошибка (HandlerNotFound, TeacherDeleted, UserRelationAlreadyExists,
 * исчерпанный retry транзиентной).
 *
 * ErrorCollector: переход * → error, error_code/error_detail заполнены,
 * ActionError эмитится. error_code по умолчанию — short name класса без
 * суффикса Exception (2.15 п.11).
 */
class RequestedActionFatalException extends \RuntimeException
{
    /**
     * Машиночитаемый код для колонки error_code.
     */
    public function errorCode(): string
    {
        $short = (new \ReflectionClass($this))->getShortName();

        return str_ends_with($short, 'Exception')
            ? substr($short, 0, -strlen('Exception'))
            : $short;
    }
}
