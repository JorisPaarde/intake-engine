<?php

declare(strict_types=1);

use App\Domains\Intake\Models\IntakeTemplate;
use Database\Seeders\IntakeTemplateSeeder;

beforeEach(function () {
    $this->seed(IntakeTemplateSeeder::class);
});

test('latest airco customer-visible texts use je-vorm without u/uw', function () {
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $version->load(['sections.questions']);

    $offenders = [];

    foreach ($version->sections as $section) {
        foreach (['title', 'description'] as $field) {
            $text = $section->{$field};

            if (! is_string($text) || $text === '') {
                continue;
            }

            if (preg_match('/\b(u|uw)\b/iu', $text) === 1) {
                $offenders[] = "section:{$section->key}.{$field}: {$text}";
            }
        }

        foreach ($section->questions as $question) {
            foreach (['label', 'help_text', 'photo_instructions'] as $field) {
                $text = $question->{$field};

                if (! is_string($text) || $text === '') {
                    continue;
                }

                if (preg_match('/\b(u|uw)\b/iu', $text) === 1) {
                    $offenders[] = "{$question->key}.{$field}: {$text}";
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('free_group_known is a factual yes/no/unknown question with clear help', function () {
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $freeGroup = $version->sections()
        ->where('key', 'electrical')
        ->firstOrFail()
        ->questions()
        ->where('key', 'free_group_known')
        ->firstOrFail();

    expect($freeGroup->label)->toBe('Is er al een aparte vrije stroomgroep beschikbaar?')
        ->and($freeGroup->help_text)->toContain('niet hetzelfde')
        ->and($freeGroup->help_text)->toContain('Weet ik niet')
        ->and($freeGroup->help_text)->not->toContain('Een vrije groep is een lege plek')
        ->and($freeGroup->options->pluck('value')->all())->toContain('yes', 'no', 'unknown');
});

test('fusebox_photo asks for full cabinet including empty slots without phase judgment', function () {
    $version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
    $fusebox = $version->sections()
        ->where('key', 'electrical')
        ->firstOrFail()
        ->questions()
        ->where('key', 'fusebox_photo')
        ->firstOrFail();

    expect($fusebox->photo_instructions)
        ->toContain('hele kast')
        ->and($fusebox->photo_instructions)->toContain('lege of vrije posities')
        ->and($fusebox->photo_instructions)->not->toMatch('/1-?\s*fase|3-?\s*fase/iu');
});

test('customer wizard runtime copy has no u/uw in notices or intro', function () {
    $paths = [
        resource_path('views/livewire/customer/intake-wizard.blade.php'),
        resource_path('views/livewire/customer/partials/photo-question-field.blade.php'),
        resource_path('views/livewire/customer/follow-up-wizard.blade.php'),
        resource_path('views/emails/customer-intake-link.blade.php'),
        resource_path('views/emails/customer-intake-reminder.blade.php'),
        resource_path('views/emails/customer-follow-up-request.blade.php'),
        app_path('Livewire/Customer/IntakeWizard.php'),
        app_path('Domains/Intake/Mail/CustomerIntakeLinkMail.php'),
        app_path('Domains/Intake/Mail/CustomerIntakeReminderMail.php'),
        app_path('Domains/Intake/Mail/CustomerFollowUpRequestMail.php'),
    ];

    $offenders = [];

    foreach ($paths as $path) {
        $text = file_get_contents($path);
        expect($text)->not->toBeFalse();

        if (preg_match_all('/\b(u|uw)\b/iu', (string) $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$match, $offset]) {
                $line = substr_count(substr((string) $text, 0, $offset), "\n") + 1;
                $offenders[] = basename($path).":{$line}: {$match}";
            }
        }
    }

    expect($offenders)->toBe([]);
});
