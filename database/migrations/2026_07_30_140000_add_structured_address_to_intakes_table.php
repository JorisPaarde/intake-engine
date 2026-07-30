<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('intakes', 'address_house_number')) {
            Schema::table('intakes', function (Blueprint $table): void {
                $table->unsignedInteger('address_house_number')
                    ->nullable()
                    ->after('address_postal_code');
            });
        }

        if (! Schema::hasColumn('intakes', 'address_house_number_addition')) {
            Schema::table('intakes', function (Blueprint $table): void {
                $table->string('address_house_number_addition', 20)
                    ->nullable()
                    ->after('address_house_number');
            });
        }

        $this->backfillStructuredAddress();
    }

    public function down(): void
    {
        if (Schema::hasColumn('intakes', 'address_house_number_addition')) {
            Schema::table('intakes', function (Blueprint $table): void {
                $table->dropColumn('address_house_number_addition');
            });
        }

        if (Schema::hasColumn('intakes', 'address_house_number')) {
            Schema::table('intakes', function (Blueprint $table): void {
                $table->dropColumn('address_house_number');
            });
        }
    }

    private function backfillStructuredAddress(): void
    {
        DB::table('intakes')
            ->select(['id', 'address_line'])
            ->whereNull('address_house_number')
            ->orderBy('id')
            ->chunkById(200, function ($intakes): void {
                foreach ($intakes as $intake) {
                    $parsed = $this->parseExistingAddress((string) $intake->address_line);

                    if ($parsed === null) {
                        continue;
                    }

                    $values = [
                        'address_house_number' => $parsed['house_number'],
                        'address_house_number_addition' => $parsed['addition'],
                    ];

                    if ($parsed['canonical_line'] !== null) {
                        $values['address_line'] = $parsed['canonical_line'];
                    }

                    DB::table('intakes')
                        ->where('id', (int) $intake->id)
                        ->update($values);
                }
            });
    }

    /**
     * Alleen een huisnummer aan het einde van de regel is veilig genoeg voor backfill.
     * Het bekende defect "Straat, 273, 273" wordt daarnaast exact genormaliseerd.
     *
     * @return array{house_number: int, addition: string|null, canonical_line: string|null}|null
     */
    private function parseExistingAddress(string $addressLine): ?array
    {
        $addressLine = trim($addressLine);

        if (preg_match('/^(.+?)\s*,\s*(\d{1,6})\s*,\s*\2\s*$/u', $addressLine, $duplicate) === 1) {
            return [
                'house_number' => (int) $duplicate[2],
                'addition' => null,
                'canonical_line' => trim($duplicate[1]).' '.(int) $duplicate[2],
            ];
        }

        if (preg_match('/(?:^|[^\d])(\d{1,6})(?:-([A-Za-z0-9-]{1,20}))?\s*$/u', $addressLine, $matches) !== 1) {
            return null;
        }

        return [
            'house_number' => (int) $matches[1],
            'addition' => isset($matches[2]) && trim($matches[2]) !== ''
                ? strtoupper(trim($matches[2]))
                : null,
            'canonical_line' => null,
        ];
    }
};
