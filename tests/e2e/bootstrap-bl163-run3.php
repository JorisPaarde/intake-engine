<?php

declare(strict_types=1);

/**
 * Bootstrap intake for BL-163 screenshots: 3 rooms (bedroom/living/bedroom),
 * L×B 3×5 → derived 15 m², known-summary ready.
 */

use App\Domains\Intake\Actions\SaveIntakeAnswer;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\IntakeTemplate;
use App\Domains\Intake\Services\DossierManager;
use App\Domains\Intake\Support\PrefillSources;
use App\Enums\IntakeStatus;
use App\Models\User;
use Database\Seeders\IntakeTemplateSeeder;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$app->make(IntakeTemplateSeeder::class)->run();

$version = IntakeTemplate::query()->where('key', 'airco')->firstOrFail()->latestPublishedVersion();
$user = User::factory()->create([
    'email' => 'bl163-run3-'.uniqid('', true).'@example.com',
    'password' => bcrypt('password'),
]);

$intake = Intake::factory()->create([
    'created_by' => $user->id,
    'company_id' => $user->company_id,
    'intake_template_version_id' => $version->id,
    'status' => IntakeStatus::Sent,
    'customer_name' => 'Joris Test',
    'customer_email' => 'bl163-customer@example.com',
    'address_line' => 'Bernadottelaan',
    'address_postal_code' => '2037GR',
    'address_house_number' => '273',
    'address_city' => 'Haarlem',
    'customer_access_enabled' => true,
]);

$save = app(SaveIntakeAnswer::class);
$save->handle($intake, 'indoor_unit_count', null, ['number' => 3], PrefillSources::AI_TEXT);
$save->handle($intake, 'room_type', 'room-1', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
$save->handle($intake, 'room_type', 'room-2', ['value' => 'living_room'], PrefillSources::AI_TEXT);
$save->handle($intake, 'room_type', 'room-3', ['value' => 'bedroom'], PrefillSources::AI_TEXT);
foreach (['room-1', 'room-2', 'room-3'] as $room) {
    $save->handle($intake, 'room_length_m', $room, ['number' => 3], PrefillSources::AI_TEXT);
    $save->handle($intake, 'room_width_m', $room, ['number' => 5], PrefillSources::AI_TEXT);
}
app(DossierManager::class)->initialize($intake->fresh() ?? $intake);

$intake = $intake->fresh();
$path = storage_path('framework/bl163-run3-token.txt');
file_put_contents($path, $intake->access_token);

echo json_encode([
    'token' => $intake->access_token,
    'url' => url('/o/'.$intake->access_token),
    'token_file' => $path,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
