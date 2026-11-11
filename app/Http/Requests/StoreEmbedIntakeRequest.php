<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Embed form rules/messages (sessionless). Not a FormRequest — validation is done
 * in EmbedIntakeController so failures re-render the form with 422 instead of
 * redirecting back through a session flash.
 */
final class StoreEmbedIntakeRequest
{
    /**
     * @return array<string, list<string>>
     */
    public static function ruleSet(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_email' => ['required', 'string', 'email:rfc', 'max:254'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'address_postal_code' => ['required', 'string', 'max:10', 'regex:/^[1-9]\d{3}\s?[A-Za-z]{2}$/'],
            'address_house_number' => ['required', 'string', 'regex:/^[1-9]\d{0,5}$/'],
            'address_house_number_addition' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\\-\\s]*$/'],
            'privacy_consent' => ['accepted'],
            'website' => ['nullable', 'string', 'max:200'],
            'address_confirmed' => ['nullable', 'in:1,true,on,yes'],
            'embed_action' => ['nullable', 'in:edit'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributeLabels(): array
    {
        return [
            'customer_name' => 'naam',
            'customer_email' => 'e-mailadres',
            'customer_phone' => 'telefoonnummer',
            'address_postal_code' => 'postcode',
            'address_house_number' => 'huisnummer',
            'address_house_number_addition' => 'toevoeging',
            'privacy_consent' => 'privacyvinkje',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messageSet(): array
    {
        return [
            'customer_name.required' => 'Vul je naam in.',
            'customer_email.required' => 'Vul je e-mailadres in.',
            'customer_email.email' => 'Vul een geldig e-mailadres in.',
            'address_postal_code.required' => 'Vul je postcode in.',
            'address_postal_code.regex' => 'Vul een geldige postcode in, bijvoorbeeld 1234 AB.',
            'address_house_number.required' => 'Vul je huisnummer in.',
            'address_house_number.regex' => 'Vul een geldig huisnummer in.',
            'address_house_number_addition.regex' => 'Gebruik alleen letters, cijfers, spaties of een streepje in de toevoeging.',
            'privacy_consent.accepted' => 'Bevestig dat je akkoord gaat met de verwerking van je gegevens en foto’s.',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $normalized = $input;

        foreach ([
            'customer_name',
            'customer_email',
            'customer_phone',
            'address_postal_code',
            'address_house_number_addition',
            'website',
            'embed_action',
        ] as $key) {
            if (! array_key_exists($key, $normalized) || ! is_string($normalized[$key])) {
                continue;
            }

            $value = trim($normalized[$key]);
            $normalized[$key] = $value === '' ? null : $value;
        }

        if (array_key_exists('address_house_number', $normalized)) {
            $houseNumber = $normalized['address_house_number'];
            if (is_int($houseNumber) || is_float($houseNumber)) {
                $normalized['address_house_number'] = (string) (int) $houseNumber;
            } elseif (is_string($houseNumber)) {
                $trimmed = trim($houseNumber);
                $normalized['address_house_number'] = $trimmed === '' ? null : $trimmed;
            }
        }

        if (isset($normalized['address_postal_code'])) {
            $normalized['address_postal_code'] = strtoupper((string) preg_replace(
                '/\s+/',
                '',
                (string) $normalized['address_postal_code'],
            ));
        }

        if (isset($normalized['address_house_number_addition'])) {
            $normalized['address_house_number_addition'] = strtoupper((string) $normalized['address_house_number_addition']);
        }

        if (isset($normalized['customer_email'])) {
            $normalized['customer_email'] = strtolower((string) $normalized['customer_email']);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public static function addressIsConfirmed(array $normalized): bool
    {
        $value = $normalized['address_confirmed'] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (! is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public static function wantsAddressEdit(array $normalized): bool
    {
        return ($normalized['embed_action'] ?? null) === 'edit';
    }
}
