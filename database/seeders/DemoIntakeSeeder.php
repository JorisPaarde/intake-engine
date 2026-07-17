<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Intake\Actions\CreateIntake;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeAnswer;
use App\Enums\IntakeStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoIntakeSeeder extends Seeder
{
    public function run(): void
    {
        $installer = User::query()->where('email', 'installateur@example.com')->firstOrFail();
        $createIntake = app(CreateIntake::class);

        if (Intake::query()->where('customer_email', 'anna.open@example.com')->doesntExist()) {
            $createIntake->handle($installer, [
                'customer_name' => 'Anna de Vries',
                'customer_email' => 'anna.open@example.com',
                'customer_phone' => '0612345678',
                'address_line' => 'Voorbeeldstraat 1',
                'address_postal_code' => '1234AB',
                'address_city' => 'Utrecht',
                'internal_note' => 'Open demo-intake (nog niet gestart).',
                'template_key' => 'airco',
            ]);
        }

        if (Intake::query()->where('customer_email', 'bert.partial@example.com')->doesntExist()) {
            $partial = $createIntake->handle($installer, [
                'customer_name' => 'Bert Jansen',
                'customer_email' => 'bert.partial@example.com',
                'customer_phone' => '0687654321',
                'address_line' => 'Demostraat 12',
                'address_postal_code' => '5678CD',
                'address_city' => 'Amersfoort',
                'internal_note' => 'Gedeeltelijk ingevulde demo-intake.',
                'template_key' => 'airco',
            ]);

            $partial->update([
                'status' => IntakeStatus::InProgress,
                'started_at' => now()->subDay(),
                'current_section_key' => 'building',
                'progress_percent' => 25,
            ]);

            IntakeAnswer::query()->create([
                'intake_id' => $partial->id,
                'question_key' => 'request_reason',
                'section_instance_key' => null,
                'value' => ['text' => 'Slaapkamer wordt te warm in de zomer.'],
                'answered_at' => now()->subHours(5),
            ]);

            IntakeAnswer::query()->create([
                'intake_id' => $partial->id,
                'question_key' => 'cooling_heating',
                'section_instance_key' => null,
                'value' => ['value' => 'cooling'],
                'answered_at' => now()->subHours(5),
            ]);

            IntakeAnswer::query()->create([
                'intake_id' => $partial->id,
                'question_key' => 'indoor_unit_count',
                'section_instance_key' => null,
                'value' => ['number' => 1],
                'answered_at' => now()->subHours(4),
            ]);
        }

        if (Intake::query()->where('customer_email', 'claire.done@example.com')->doesntExist()) {
            $completed = $createIntake->handle($installer, [
                'customer_name' => 'Claire Bakker',
                'customer_email' => 'claire.done@example.com',
                'customer_phone' => null,
                'address_line' => 'Klaarweg 5',
                'address_postal_code' => '9012EF',
                'address_city' => 'Hilversum',
                'internal_note' => 'Afgeronde demo-intake (zonder echte foto’s).',
                'template_key' => 'airco',
            ]);

            $completed->update([
                'status' => IntakeStatus::Completed,
                'started_at' => now()->subDays(5),
                'completed_at' => now()->subDay(),
                'current_section_key' => 'closing',
                'progress_percent' => 100,
                'completeness_snapshot' => [
                    'is_complete' => true,
                    'missing' => [],
                    'note' => 'Demo snapshot zonder uploads.',
                ],
            ]);
        }
    }
}
