<?php

declare(strict_types=1);

namespace App\Domains\Intake\Services;

use App\Domains\Intake\Data\AerialImageCapture;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class PdokAerialImageService
{
    private const PRODUCT_URL = 'https://www.pdok.nl/ogc-webservices/-/article/pdok-luchtfoto-rgb-open-';

    private const SOURCE = 'PDOK Luchtfoto RGB';

    public function capture(?float $longitude, ?float $latitude): ?AerialImageCapture
    {
        if (! $this->enabled() || ! $this->validCoordinates($longitude, $latitude)) {
            return null;
        }

        $width = max(320, min(1600, (int) config('services.pdok.aerial_width', 900)));
        $height = max(240, min(1200, (int) config('services.pdok.aerial_height', 600)));
        $groundWidth = max(50, min(500, (int) config('services.pdok.aerial_ground_width_meters', 180)));
        $groundHeight = (int) round($groundWidth * ($height / $width));
        $layer = (string) config('services.pdok.aerial_layer', 'Actueel_orthoHR');
        $bbox = $this->bbox((float) $longitude, (float) $latitude, $groundWidth, $groundHeight);

        $response = Http::accept('image/jpeg')
            ->connectTimeout($this->timeout())
            ->timeout($this->timeout())
            ->get((string) config('services.pdok.aerial_wms_url'), [
                'service' => 'WMS',
                'version' => '1.3.0',
                'request' => 'GetMap',
                'layers' => $layer,
                'styles' => '',
                'crs' => 'EPSG:3857',
                'bbox' => implode(',', array_map(
                    static fn (float $coordinate): string => number_format($coordinate, 2, '.', ''),
                    [$bbox['min_x'], $bbox['min_y'], $bbox['max_x'], $bbox['max_y']],
                )),
                'width' => $width,
                'height' => $height,
                'format' => 'image/jpeg',
            ])
            ->throw();

        $mimeType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $binary = $response->body();
        $imageInfo = @getimagesizefromstring($binary);

        if ($mimeType !== 'image/jpeg'
            || strlen($binary) > 5 * 1024 * 1024
            || ! is_array($imageInfo)
            || $imageInfo[0] !== $width
            || $imageInfo[1] !== $height) {
            throw new RuntimeException('PDOK aerial response is not the expected JPEG image.');
        }

        return new AerialImageCapture(
            binary: $binary,
            mimeType: $mimeType,
            width: $width,
            height: $height,
            layer: $layer,
            bbox: $bbox,
            groundWidthMeters: $groundWidth,
            groundHeightMeters: $groundHeight,
        );
    }

    public function enabled(): bool
    {
        return (bool) config('services.pdok.aerial_enabled', true);
    }

    public static function productUrl(): string
    {
        return self::PRODUCT_URL;
    }

    public static function sourceName(): string
    {
        return self::SOURCE;
    }

    /** @return array{min_x: float, min_y: float, max_x: float, max_y: float} */
    private function bbox(float $longitude, float $latitude, int $groundWidth, int $groundHeight): array
    {
        $radius = 6378137.0;
        $latitudeRadians = deg2rad(max(-85.0, min(85.0, $latitude)));
        $centerX = $radius * deg2rad($longitude);
        $centerY = $radius * log(tan((M_PI / 4) + ($latitudeRadians / 2)));
        $mercatorScale = 1 / max(0.1, cos($latitudeRadians));
        $halfWidth = ($groundWidth / 2) * $mercatorScale;
        $halfHeight = ($groundHeight / 2) * $mercatorScale;

        return [
            'min_x' => $centerX - $halfWidth,
            'min_y' => $centerY - $halfHeight,
            'max_x' => $centerX + $halfWidth,
            'max_y' => $centerY + $halfHeight,
        ];
    }

    private function timeout(): int
    {
        return max(1, (int) config('services.pdok.aerial_timeout_seconds', 4));
    }

    private function validCoordinates(?float $longitude, ?float $latitude): bool
    {
        return $longitude !== null
            && $latitude !== null
            && $longitude >= -180
            && $longitude <= 180
            && $latitude >= -85
            && $latitude <= 85;
    }
}
