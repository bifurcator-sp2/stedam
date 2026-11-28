<?php

declare(strict_types=1);

namespace App\Domain\Community\ValueObjects;

/**
 * Тип ребра социального графа (user_relations.relation).
 *
 * Источник правды для строкового значения `relation` — этот enum.
 * Значение = роль «хозяина» ребра (кем является parent_user_id по
 * отношению к child_user_id), см. App\Models\UserRelation:
 *
 *   teacher       teacher        → student
 *   parent        parent         → student
 *   methodologist methodologist  → teacher
 *   headteacher   headteacher    → methodologist
 *
 * Ребро направленное, поэтому один и тот же код `relation` даёт две роли
 * участия — по одной на каждый конец ребра. Например, для ребра parent
 * пользователь-хозяин (parent_user_id) является родителем, а
 * пользователь-подчинённый (child_user_id) — ребёнком.
 *
 * Все пользовательские тексты хранятся в переводах (lang/{locale}/relations.php),
 * ключ — код ребра и сторона участия, поэтому новые языки добавляются
 * без изменения кода:
 *   - label* — раздел профиля «кого я вижу», от моего имени:
 *     я teacher → «Ученики», я student → «Учителя»;
 *   - role*Label — кем я являюсь на этой стороне;
 *   - description — кого я вижу и кем являюсь для них.
 */
enum RelationType: string
{
    case Teacher = 'teacher';
    case Parent = 'parent';
    case Methodologist = 'methodologist';
    case Headteacher = 'headteacher';

    /**
     * Роль пользователя на стороне хозяина ребра (parent_user_id):
     * teacher → «teacher», parent → «parent», ...
     */
    public function ownerRole(): string
    {
        return match ($this) {
            self::Teacher => 'teacher',
            self::Parent => 'parent',
            self::Methodologist => 'methodologist',
            self::Headteacher => 'headteacher',
        };
    }

    /**
     * Роль пользователя на стороне подчинённого (child_user_id):
     * teacher → «student», parent → «student», methodologist → «teacher», ...
     */
    public function counterpartRole(): string
    {
        return match ($this) {
            self::Teacher, self::Parent => 'student',
            self::Methodologist => 'teacher',
            self::Headteacher => 'methodologist',
        };
    }

    /**
     * Код роли участника на указанной стороне ребра.
     */
    public function roleAt(bool $isOwner): string
    {
        return $isOwner ? $this->ownerRole() : $this->counterpartRole();
    }

    /**
     * Название раздела профиля: «кого я вижу в этой связи».
     *
     * Не описание моей роли, а заголовок списка — как я называю этих
     * людей на своей странице:
     *   Outgoing (я — хозяин ребра): я teacher → «Мои ученики» = «Ученики»;
     *   Incoming (я — подчинённый):  я student → «Мои учителя» = «Учителя».
     */
    public function labelAt(bool $isOwner): string
    {
        return $this->sideAt($isOwner)['label'];
    }

    /**
     * Название моей роли на указанной стороне ребра (для описания раздела).
     */
    public function myRoleLabelAt(bool $isOwner): string
    {
        return $this->sideAt($isOwner)['role'];
    }

    /**
     * Кем является человек на противоположном конце ребра.
     */
    public function oppositeRoleLabelAt(bool $isOwner): string
    {
        return $this->sideAt($isOwner)['opposite'];
    }

    /**
     * Кем являются люди на противоположном конце ребра (множественное число).
     */
    public function oppositeRoleLabelPluralAt(bool $isOwner): string
    {
        return $this->sideAt($isOwner)['opposite_plural'];
    }

    /**
     * Описание раздела профиля: кого я вижу и кем является каждый из нас.
     *
     *   Нет связей:
     *     «Пользователей, зарегистрированных как мои <b>Родители</b> на этом
     *      проекте, пока нет.<br>В этой связи я являюсь <b>Учителем</b>,
     *      а они являются моими <b>Руководителями</b>»
     *   Одна связь (Outgoing, я — хозяин ребра, parent):
     *     «Пользователь, зарегистрированный как мой <b>Дети</b> на этом
     *      проекте.<br>Я являюсь по отношению к нему <b>Родителем</b>:
     *      он является моим <b>Ребёнком</b>»
     *   Несколько связей: «Пользователи, зарегистрированные как мои ...»,
     *     «... они являются моими ...»
     *
     * Формы «нет / одна / много» берутся из переводов
     * (relations.description.{none|one|many}), поэтому плюрализация
     * подчиняется правилам языка.
     *
     * Иерархия проговаривается самим текстом: учитель — это «Сотрудник -
     * Учитель» методиста, методист — «Сотрудник - Методист» завуча
     * (см. переводы для сторон member).
     */
    public function descriptionAt(bool $isOwner, int $count): string
    {
        $params = [
            'count' => $count,
            'label' => $this->labelAt($isOwner),
            'role' => $this->myRoleLabelAt($isOwner),
            'opposite' => $this->oppositeRoleLabelAt($isOwner),
            'opposite_plural' => $this->oppositeRoleLabelPluralAt($isOwner),
        ];

        // Нет связей / одна связь — отдельные строки; иначе — выбор формы
        // множественного числа через правила языка (trans_choice).
        if ($count <= 0 || $count === 1) {
            return (string) __('relations.description.'.($count <= 0 ? 'none' : 'one'), $params);
        }

        return (string) trans_choice('relations.description.many', $count, $params);
    }

    /**
     * Заголовок раздела профиля: я — хозяин ребра.
     */
    public function outgoingLabel(): string
    {
        return $this->labelAt(true);
    }

    /**
     * Заголовок раздела профиля: я — подчинённый на ребре.
     */
    public function incomingLabel(): string
    {
        return $this->labelAt(false);
    }

    /**
     * Тексты стороны участия из переводов.
     *
     * @return array{label: string, role: string, opposite: string, opposite_plural: string}
     */
    private function sideAt(bool $isOwner): array
    {
        $key = 'relations.'.$this->value.'.'.($isOwner ? 'owner' : 'member').'.';

        return [
            'label' => (string) __($key.'label'),
            'role' => (string) __($key.'role'),
            'opposite' => (string) __($key.'opposite'),
            'opposite_plural' => (string) __($key.'opposite_plural'),
        ];
    }

    /**
     * Все типы в порядке отображения в UI.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Teacher, self::Parent, self::Methodologist, self::Headteacher];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }
}
