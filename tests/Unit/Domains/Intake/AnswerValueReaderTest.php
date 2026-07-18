<?php

declare(strict_types=1);

use App\Domains\Intake\Services\AnswerValueReader;
use App\Enums\QuestionType;

it('treats livewire radio boolean values as filled', function (mixed $bool) {
    $reader = new AnswerValueReader;

    expect($reader->isFilled(['bool' => $bool], QuestionType::Boolean))->toBeTrue()
        ->and($reader->readComparable(['bool' => $bool], QuestionType::Boolean))->toBeIn([true, false]);
})->with([
    'true' => [true],
    'false' => [false],
    'int-1' => [1],
    'int-0' => [0],
    'string-1' => ['1'],
    'string-0' => ['0'],
]);

it('rejects missing or invalid boolean values', function (?array $value) {
    $reader = new AnswerValueReader;

    expect($reader->isFilled($value, QuestionType::Boolean))->toBeFalse();
})->with([
    'null' => [null],
    'bool-null' => [['bool' => null]],
    'bool-yes' => [['bool' => 'yes']],
    'bool-2' => [['bool' => 2]],
    'empty' => [[]],
]);
