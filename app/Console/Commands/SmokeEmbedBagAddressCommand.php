<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Intake\Actions\HardDeleteIntake;
use App\Domains\Intake\Actions\StartEmbedIntake;
use App\Domains\Intake\Support\EmbedStagingTest;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Staging/local smoke: embed create for a real BAG address must yield street, city,
 * building year and building type. Unknown addresses must fail with a Dutch message.
 *
 * Uses live PDOK (not Http::fake). Safe to run on staging; cleans up the test intake.
 */
final class SmokeEmbedBagAddressCommand extends Command
{
    protected $signature = 'embed:smoke-bag-address
        {--keep : Keep the created intake (marked as test; default deletes it)}
        {--postal-code=2037GR : Postcode for the known BAG check}
        {--house-number=273 : House number for the known BAG check}
        {--expect-energy-label= : Optional expected EP-Online label letter (e.g. A); requires EP_ONLINE_ENABLED+key}';

    protected $description = 'Smoke-test embed BAG enrichment (2037GR 273) + unknown-address message (local/staging); optional EP-Online label check';

    public function handle(StartEmbedIntake $startEmbedIntake, HardDeleteIntake $hardDeleteIntake): int
    {
        if (! $this->environmentAllowed()) {
            $this->error('Dit commando is alleen bedoeld voor local of staging (APP_ENV).');

            return self::FAILURE;
        }

        if (! (bool) config('services.pdok.enabled', true)) {
            $this->error('PDOK_ENABLED staat uit — smoke kan niet draaien.');

            return self::FAILURE;
        }

        $company = Company::query()
            ->where('embed_public_key', EmbedStagingTest::PUBLIC_KEY)
            ->first();

        if ($company === null || ! $company->embed_enabled) {
            $this->error('Embed staging-testcompany ontbreekt. Draai eerst: php artisan embed:prepare-staging-test');

            return self::FAILURE;
        }

        $postalCode = strtoupper((string) preg_replace('/\s+/', '', (string) $this->option('postal-code')));
        $houseNumber = (int) $this->option('house-number');
        $unique = 'bag-smoke-'.now()->format('YmdHis').'@example.com';

        $this->info("1) Bekend adres {$postalCode} {$houseNumber} via embed…");

        try {
            $created = $startEmbedIntake->handle($company, [
                'customer_name' => 'BAG Smoke Test',
                'customer_email' => $unique,
                'customer_phone' => null,
                'address_postal_code' => $postalCode,
                'address_house_number' => $houseNumber,
                'address_house_number_addition' => null,
            ], parse_url((string) config('app.url'), PHP_URL_HOST) ?: null);
        } catch (Throwable $exception) {
            $this->error('Aanmaken mislukt: '.$exception->getMessage());

            return self::FAILURE;
        }

        $intake = $created['intake']->fresh(['answers', 'externalFacts']) ?? $created['intake'];
        $failures = [];
        $line = trim((string) $intake->address_line);
        $city = trim((string) $intake->address_city);

        if ($line === '' || preg_match('/^\d{4}\s?[A-Z]{2}\b/i', $line) === 1) {
            $failures[] = "straat leeg of nog placeholder: {$intake->address_line}";
        }
        if ($city === '' || strcasecmp($city, 'Onbekend') === 0) {
            $failures[] = "plaats leeg/Onbekend: {$intake->address_city}";
        }
        if ($postalCode === '2037GR' && $houseNumber === 273) {
            if (! str_contains($line, 'Bernadottelaan')) {
                $failures[] = "verwachte straat Bernadottelaan, kreeg: {$line}";
            }
            if (strcasecmp($city, 'Haarlem') !== 0) {
                $failures[] = "verwachte plaats Haarlem, kreeg: {$city}";
            }
        }

        $year = $intake->answers()->where('question_key', 'build_year')->value('value');
        $yearNumber = is_array($year) ? ($year['number'] ?? null) : null;
        if (! is_numeric($yearNumber)) {
            $failures[] = 'bouwjaar-antwoord ontbreekt';
        }

        $type = $intake->answers()->where('question_key', 'building_type')->value('value');
        $typeValue = is_array($type) ? ($type['value'] ?? null) : null;
        if (! is_string($typeValue) || $typeValue === '') {
            $failures[] = 'woningtype-antwoord ontbreekt';
        }

        $this->line('  address_line: '.$intake->address_line);
        $this->line('  address_city: '.$intake->address_city);
        $this->line('  build_year: '.json_encode($year));
        $this->line('  building_type: '.json_encode($type));

        $energyFact = $intake->externalFacts->firstWhere('fact_key', 'energy_label');
        $energyClass = is_array($energyFact?->value) ? ($energyFact->value['value'] ?? null) : null;
        $energyStatus = $intake->externalFacts->firstWhere('fact_key', 'energy_label_status');
        $this->line('  energy_label: '.json_encode($energyClass));
        $this->line('  energy_label_status: '.json_encode(is_array($energyStatus?->value) ? $energyStatus->value : null));
        $this->line('  known_housing_lines: '.json_encode($intake->knownHousingFactLines()));
        $this->line('  customer_url: '.$intake->customerUrl());

        $expectedLabel = strtoupper(trim((string) $this->option('expect-energy-label')));
        if ($expectedLabel !== '') {
            if (! is_string($energyClass) || strtoupper(trim($energyClass)) !== $expectedLabel) {
                $failures[] = "verwacht energielabel {$expectedLabel}, kreeg: ".json_encode($energyClass)
                    .' (EP_ONLINE_ENABLED+key gezet? status='.json_encode(is_array($energyStatus?->value) ? $energyStatus->value : null).')';
            }
        }

        $this->info('2) Onbekend adres 2037GR 1 moet nette 422-melding geven…');
        $unknownOk = false;
        try {
            $startEmbedIntake->handle($company, [
                'customer_name' => 'BAG Smoke Onbekend',
                'customer_email' => 'bag-smoke-unknown-'.now()->format('YmdHis').'@example.com',
                'address_postal_code' => '2037GR',
                'address_house_number' => 1,
            ]);
            $failures[] = '2037GR 1 werd ten onrechte geaccepteerd';
        } catch (ValidationException $exception) {
            $messages = $exception->errors();
            $flat = collect($messages)->flatten()->implode(' | ');
            $this->line('  melding: '.$flat);
            if (str_contains($flat, 'adres niet vinden')) {
                $unknownOk = true;
            } else {
                $failures[] = 'onverwachte validatiemelding voor 2037GR 1: '.$flat;
            }
        } catch (Throwable $exception) {
            $failures[] = '2037GR 1 gaf geen ValidationException: '.$exception::class;
        }

        if (! $this->option('keep')) {
            $hardDeleteIntake->handle($intake);
            $this->line('  testopname verwijderd (--keep om te bewaren)');
        } else {
            $this->warn('  testopname bewaard: #'.$intake->id.' ('.$intake->customer_email.')');
        }

        if ($failures !== [] || ! $unknownOk) {
            foreach ($failures as $failure) {
                $this->error('FAIL: '.$failure);
            }
            if (! $unknownOk && $failures === []) {
                $this->error('FAIL: onbekend-adrescheck');
            }

            return self::FAILURE;
        }

        $this->info('OK — embed BAG-smoke groen (straat/plaats/bouwjaar/woningtype + melding bij 2037GR 1).');

        return self::SUCCESS;
    }

    private function environmentAllowed(): bool
    {
        return app()->environment(['local', 'staging']);
    }
}
