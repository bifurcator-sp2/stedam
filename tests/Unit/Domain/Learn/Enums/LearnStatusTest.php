<?php

declare(strict_types=1);

use App\Domain\Learn\Enums\LearnStatus;

it('allows only documented transitions', function (LearnStatus $from, LearnStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    // created
    [LearnStatus::Created, LearnStatus::InProgress, true],
    [LearnStatus::Created, LearnStatus::Scheduled, true],
    [LearnStatus::Created, LearnStatus::Created, false],
    [LearnStatus::Created, LearnStatus::Done, false],
    [LearnStatus::Created, LearnStatus::Graded, false],

    // scheduled
    [LearnStatus::Scheduled, LearnStatus::InProgress, true],
    [LearnStatus::Scheduled, LearnStatus::Scheduled, true],
    [LearnStatus::Scheduled, LearnStatus::Created, true],
    [LearnStatus::Scheduled, LearnStatus::Done, false],
    [LearnStatus::Scheduled, LearnStatus::Graded, false],

    // in_progress
    [LearnStatus::InProgress, LearnStatus::Done, true],
    [LearnStatus::InProgress, LearnStatus::InProgress, false],
    [LearnStatus::InProgress, LearnStatus::Graded, false],
    [LearnStatus::InProgress, LearnStatus::Created, false],

    // done
    [LearnStatus::Done, LearnStatus::Graded, true],
    [LearnStatus::Done, LearnStatus::InProgress, false],
    [LearnStatus::Done, LearnStatus::Done, false],

    // graded — терминальный
    [LearnStatus::Graded, LearnStatus::Graded, false],
    [LearnStatus::Graded, LearnStatus::InProgress, false],
    [LearnStatus::Graded, LearnStatus::Created, false],
]);

it('identifies terminal states', function () {
    expect(LearnStatus::Graded->isTerminal())->toBeTrue()
        ->and(LearnStatus::Done->isTerminal())->toBeFalse()
        ->and(LearnStatus::InProgress->isTerminal())->toBeFalse()
        ->and(LearnStatus::Scheduled->isTerminal())->toBeFalse()
        ->and(LearnStatus::Created->isTerminal())->toBeFalse();
});

it('identifies editable states', function () {
    expect(LearnStatus::InProgress->isEditable())->toBeTrue()
        ->and(LearnStatus::Created->isEditable())->toBeFalse()
        ->and(LearnStatus::Scheduled->isEditable())->toBeFalse()
        ->and(LearnStatus::Done->isEditable())->toBeFalse()
        ->and(LearnStatus::Graded->isEditable())->toBeFalse();
});

it('identifies open states', function () {
    expect(LearnStatus::Created->isOpen())->toBeTrue()
        ->and(LearnStatus::Scheduled->isOpen())->toBeTrue()
        ->and(LearnStatus::InProgress->isOpen())->toBeFalse()
        ->and(LearnStatus::Done->isOpen())->toBeFalse()
        ->and(LearnStatus::Graded->isOpen())->toBeFalse();
});
