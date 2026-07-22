<?php

declare(strict_types=1);

use App\Domains\Intake\Models\IntakeAnswer;
use App\Domains\Intake\Models\IntakeAttentionPoint;
use App\Domains\Intake\Models\IntakeExternalFact;
use App\Domains\Intake\Models\IntakeFollowUpItem;
use App\Domains\Intake\Models\IntakeFollowUpRound;
use App\Domains\Intake\Models\IntakeUpload;
use Illuminate\Database\Eloquent\Model;

/**
 * MySQL levert foreign keys als string terug, SQLite als int. Zonder cast slaagt een
 * strikte eigenaarscontrole (`$upload->intake_id !== $intake->id`) daardoor lokaal wél
 * en faalt hij op de server — met een 404 op elke foto als gevolg.
 *
 * Deze test bootst de MySQL-vorm na door de ruwe attributen als string te zetten.
 */
dataset('foreign keys', [
    'upload → intake' => [IntakeUpload::class, 'intake_id'],
    'upload → follow-up item' => [IntakeUpload::class, 'intake_follow_up_item_id'],
    'ronde → intake' => [IntakeFollowUpRound::class, 'intake_id'],
    'item → ronde' => [IntakeFollowUpItem::class, 'intake_follow_up_round_id'],
    'aandachtspunt → intake' => [IntakeAttentionPoint::class, 'intake_id'],
    'antwoord → intake' => [IntakeAnswer::class, 'intake_id'],
    'extern feit → intake' => [IntakeExternalFact::class, 'intake_id'],
]);

test('a foreign key coming back as a string is cast to an integer', function (string $model, string $column) {
    /** @var Model $instance */
    $instance = new $model;
    $instance->setRawAttributes([$column => '29']);

    expect($instance->{$column})->toBe(29)
        ->and($instance->{$column} === 29)->toBeTrue();
})->with('foreign keys');
