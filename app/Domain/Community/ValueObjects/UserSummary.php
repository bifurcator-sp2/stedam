<?php

declare(strict_types=1);

namespace App\Domain\Community\ValueObjects;

/**
 * Пользователь в списках Community (GET /users).
 *
 * Лёгкая проекция для карточек: идентификатор, ФИО и код страны.
 * Поля профиля могут отсутствовать (строки user_data нет).
 */
final readonly class UserSummary
{
    public function __construct(
        public int $id,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $middleName = null,
        public ?int $countryId = null,
        public ?string $countryName = null,
    ) {}

    /**
     * @return array{
     *     id: int,
     *     first_name: ?string,
     *     last_name: ?string,
     *     middle_name: ?string,
     *     country: ?array{id: int, name: string}
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'middle_name' => $this->middleName,
            'country' => $this->countryId !== null && $this->countryName !== null
                ? ['id' => $this->countryId, 'name' => $this->countryName]
                : null,
        ];
    }
}
