<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| User relations (Community section)
|--------------------------------------------------------------------------
|
| Texts shown in the profile section that lists user relations.
| The key is an edge code from Domain\Community\ValueObjects\RelationType.
|
| Every edge has two participation sides: the edge owner (parent_user_id)
| and the member (child_user_id). For each side:
|   - label    — section heading, "whom I see";
|   - role     — who I am on this side;
|   - opposite — who the person on the other end of the edge is,
|     opposite_plural — the same in plural (for the "no relations" form).
| The section description is built from label and role
| (see RelationType::description()).
|
| School hierarchy: a student is subordinate to a teacher and a parent,
| a teacher is an employee of a methodologist, a methodologist is an
| employee of a headteacher.
|
*/

return [

    'teacher' => [
        'owner' => [
            'label' => 'Students',
            'role' => 'Teacher',
            'opposite' => 'Student',
            'opposite_plural' => 'Students',
        ],
        'member' => [
            'label' => 'Teachers',
            'role' => 'Student',
            'opposite' => 'Teacher',
            'opposite_plural' => 'Teachers',
        ],
    ],

    'parent' => [
        'owner' => [
            'label' => 'Children',
            'role' => 'Parent',
            'opposite' => 'Child',
            'opposite_plural' => 'Children',
        ],
        'member' => [
            'label' => 'Parents',
            'role' => 'Child',
            'opposite' => 'Parent',
            'opposite_plural' => 'Parents',
        ],
    ],

    'methodologist' => [
        'owner' => [
            'label' => 'Employees - Teachers',
            'role' => 'Head',
            'opposite' => 'Employee - Teacher',
            'opposite_plural' => 'Employees - Teachers',
        ],
        'member' => [
            'label' => 'Heads - Methodologists',
            'role' => 'Teacher',
            'opposite' => 'Head',
            'opposite_plural' => 'Heads',
        ],
    ],

    'headteacher' => [
        'owner' => [
            'label' => 'Employees - Methodologists',
            'role' => 'Head',
            'opposite' => 'Employee - Methodologist',
            'opposite_plural' => 'Employees - Methodologists',
        ],
        'member' => [
            'label' => 'Heads - Headteachers',
            'role' => 'Methodologist',
            'opposite' => 'Head',
            'opposite_plural' => 'Heads',
        ],
    ],

    // Section description templates.
    // Chosen by the number of relations (:count) with the same view:
    //   none — no relations, one — exactly one, many — plural form.
    // :label — section heading, :role — who I am on this side,
    // :opposite — who the person on the other end of the edge is,
    // :opposite_plural — the same in plural,
    // :count — number of relations.
    'description' => [
        'none' => 'There are no users registered as my <b>:label</b> on this project yet.'
            .'<br>In this relation I am the <b>:role</b>, and they are my <b>:opposite_plural</b>',
        'one' => 'The user registered as my <b>:label</b> on this project.'
            .'<br>I am the <b>:role</b> to them: they are my <b>:opposite</b>',
        'many' => '{1} User registered as my :label on this project.'
            .'|[2,*] Users registered as my :label on this project.'
            .'<br>I am the <b>:role</b> to them: they are my <b>:opposite</b>',
    ],

];
