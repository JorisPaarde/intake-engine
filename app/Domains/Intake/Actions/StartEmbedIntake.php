<?php

declare(strict_types=1);

namespace App\Domains\Intake\Actions;

use App\Domains\Intake\Models\Intake;
use App\Enums\ContributionMode;
use App\Enums\IntakeSource;
use App\Enums\IntakeStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class StartEmbedIntake
{
    public function __construct(
        private readonly CreateIntake $createIntake,
        private readonly EnrichIntakeAddress $enrichIntakeAddress,
        private readonly SendCustomerIntakeLink $sendCustomerIntakeLink,
    ) {}

    /**
     * @param  array{
     *     customer_name: string,
     *     customer_email: string,
     *     customer_phone?: string|null,
     *     address_postal_code: string,
     *     address_house_number: int|string,
     *     address_house_number_addition?: string|null,
     * }  $data
     * @return array{intake: Intake, created: bool}
     */
    public function handle(Company $company, array $data, ?string $refererHost = null): array
    {
        $creator = $this->oldestCompanyUser($company);
        $email = strtolower(trim((string) $data['customer_email']));

        $existing = $this->findReusableOpenIntake($company, $email);

        if ($existing !== null) {
            return ['intake' => $existing, 'created' => false];
        }

        $this->assertWithinDailyLimit($company);

        $postalCode = strtoupper((string) preg_replace('/\s+/', '', trim((string) $data['address_postal_code'])));
        $houseNumber = (int) $data['address_house_number'];
        $addition = strtoupper(trim((string) ($data['address_house_number_addition'] ?? '')));
        $addition = $addition === '' ? null : $addition;

        // Straat/plaats volgen uit BAG via EnrichIntakeAddress; CreateIntake eist niet-lege waarden.
        $placeholderLine = trim($postalCode.' '.$houseNumber.($addition !== null ? ' '.$addition : ''));

        $activityProperties = [];
        if (is_string($refererHost) && $refererHost !== '') {
            $activityProperties['embed_referer_host'] = $refererHost;
        }

        $intake = DB::transaction(function () use (
            $creator,
            $data,
            $email,
            $postalCode,
            $houseNumber,
            $addition,
            $placeholderLine,
            $activityProperties,
        ): Intake {
            return $this->createIntake->handle($creator, [
                'customer_name' => $data['customer_name'],
                'customer_email' => $email,
                'customer_phone' => $data['customer_phone'] ?? null,
                'address_line' => $placeholderLine !== '' ? $placeholderLine : 'Adres volgt',
                'address_postal_code' => $postalCode,
                'address_house_number' => $houseNumber,
                'address_house_number_addition' => $addition,
                'address_city' => 'Onbekend',
                'workflow_mode' => ContributionMode::Customer,
                'source' => IntakeSource::Embed,
                'activity_actor_type' => 'embed',
                'activity_properties' => $activityProperties,
            ]);
        });

        $this->enrichIntakeAddress->handle($intake);
        $fresh = $intake->fresh() ?? $intake;

        // Backup-mail; geen AI-job (BL-152). Fail-soft zit in SendCustomerIntakeLink.
        $this->sendCustomerIntakeLink->handle($fresh, $creator);

        return ['intake' => $fresh, 'created' => true];
    }

    private function oldestCompanyUser(Company $company): User
    {
        $user = User::query()
            ->where('company_id', $company->id)
            ->orderBy('id')
            ->first();

        if ($user === null) {
            throw new HttpException(404);
        }

        return $user;
    }

    private function findReusableOpenIntake(Company $company, string $normalizedEmail): ?Intake
    {
        $openStatuses = array_map(
            static fn (IntakeStatus $status): string => $status->value,
            array_values(array_filter(
                IntakeStatus::cases(),
                static fn (IntakeStatus $status): bool => $status->isCustomerAccessible(),
            )),
        );

        $candidates = Intake::query()
            ->where('company_id', $company->id)
            ->whereIn('status', $openStatuses)
            ->whereRaw('lower(customer_email) = ?', [$normalizedEmail])
            ->orderByDesc('id')
            ->get();

        foreach ($candidates as $candidate) {
            if ($candidate->isTokenValid()) {
                return $candidate;
            }
        }

        return null;
    }

    private function assertWithinDailyLimit(Company $company): void
    {
        $limit = max(1, (int) $company->embed_daily_limit);
        $createdToday = Intake::query()
            ->where('company_id', $company->id)
            ->where('source', IntakeSource::Embed)
            ->whereDate('created_at', now()->toDateString())
            ->count();

        if ($createdToday >= $limit) {
            throw new HttpException(
                429,
                'Het maximum aantal opnames via dit formulier voor vandaag is bereikt. Probeer het morgen opnieuw of neem contact op met je installateur.',
            );
        }
    }
}
