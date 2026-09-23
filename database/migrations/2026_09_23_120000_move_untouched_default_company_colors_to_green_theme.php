<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BL-107: nieuwe huisstijl. Bedrijven die nog exact de oude standaardkleuren
 * hebben (nooit zelf gekozen of uit een logo afgeleid) krijgen de nieuwe
 * bosgroene standaard. Zelf ingestelde tenantkleuren blijven ongemoeid.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('companies')
            ->where('primary_color', '#0071E3')
            ->where('accent_color', '#005EC0')
            ->where('on_primary_color', '#FFFFFF')
            ->update([
                'primary_color' => '#15392F',
                'accent_color' => '#315F4F',
            ]);
    }

    public function down(): void
    {
        DB::table('companies')
            ->where('primary_color', '#15392F')
            ->where('accent_color', '#315F4F')
            ->where('on_primary_color', '#FFFFFF')
            ->update([
                'primary_color' => '#0071E3',
                'accent_color' => '#005EC0',
            ]);
    }
};
