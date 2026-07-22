<?php

declare(strict_types=1);

namespace App\Livewire\Customer;

use App\Domains\AI\Actions\SynthesizePipeRoute;
use App\Domains\Intake\Actions\AddPipeRoutePhoto;
use App\Domains\Intake\Actions\StartPipeRouteSession;
use App\Domains\Intake\Actions\StoreRouteSegmentPhoto;
use App\Domains\Intake\Models\Intake;
use App\Domains\Intake\Models\PipeRouteSession;
use App\Domains\Intake\Services\ResolveIntakeByAccessToken;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Begeleide klantflow voor de leidingroute: de klant fotografeert stap voor stap, elke
 * foto wordt beoordeeld (bruikbaar? route zichtbaar?), en de app vraagt steeds om één
 * gerichte vervolgfoto tot de route rond is. De installateur keurt de route later goed.
 *
 * Staat los van de bestaande vragen-wizard (parallel; ADR-0009).
 */
#[Layout('layouts.customer')]
class PipeRouteWizard extends Component
{
    use WithFileUploads;

    /** Vaste startset rollen; de AI-instructie stuurt daarna de vervolgfoto's aan. */
    public const ROLES = [
        'binnenunit-positie' => 'Gewenste plek binnenunit',
        'andere kant van de wand' => 'Andere kant van de wand',
        'aangrenzende ruimte' => 'Aangrenzende ruimte',
        'volledige buitengevel' => 'Volledige buitengevel',
        'gevel tussen doorvoer en buitenunit' => 'Gevel tussen doorvoer en buitenunit',
        'obstakel of hoek' => 'Obstakel of hoek (close-up)',
    ];

    #[Locked]
    public int $intakeId;

    #[Locked]
    public string $token = '';

    #[Locked]
    public int $sessionId;

    public ?TemporaryUploadedFile $photo = null;

    public string $label = 'binnenunit-positie';

    public function mount(string $token): void
    {
        $this->token = $token;

        $intake = request()->attributes->get('customer_intake');

        if (! $intake instanceof Intake) {
            $intake = app(ResolveIntakeByAccessToken::class)->handle($token);
        }

        $this->intakeId = $intake->id;
        $this->sessionId = app(StartPipeRouteSession::class)->handle($intake)->id;
    }

    public function addPhoto(): void
    {
        $maxKilobytes = (int) config('intake.uploads.max_kilobytes', 5120);

        $this->validate([
            'photo' => ['required', 'image', 'max:'.$maxKilobytes],
            'label' => ['required', 'string', 'in:'.implode(',', array_keys(self::ROLES))],
        ]);

        $upload = app(StoreRouteSegmentPhoto::class)->handle($this->intake(), $this->photo);

        app(AddPipeRoutePhoto::class)->handle($this->session(), $upload, $this->label);

        $this->reset('photo');
    }

    public function synthesize(): void
    {
        app(SynthesizePipeRoute::class)->handle($this->session());
    }

    private function intake(): Intake
    {
        return Intake::query()->findOrFail($this->intakeId);
    }

    private function session(): PipeRouteSession
    {
        return PipeRouteSession::query()->findOrFail($this->sessionId);
    }

    public function render(): View
    {
        $session = $this->session()->load('segments.upload');

        return view('livewire.customer.pipe-route-wizard', [
            'session' => $session,
            'roles' => self::ROLES,
            'aiEnabled' => (bool) config('ai.route.enabled', false),
        ]);
    }
}
