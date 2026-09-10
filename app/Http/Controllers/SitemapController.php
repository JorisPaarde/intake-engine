<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

/**
 * Publieke XML-sitemap met alleen indexeerbare marketing-URL's.
 * Uitbreiden: named routes toevoegen aan INDEXABLE_ROUTE_NAMES.
 */
final class SitemapController extends Controller
{
    /**
     * Named routes die in de sitemap mogen (geen auth, demo-sessie, health of app).
     *
     * @var list<string>
     */
    private const INDEXABLE_ROUTE_NAMES = [
        'home',
    ];

    public function __invoke(): Response
    {
        $urls = [];

        foreach (self::INDEXABLE_ROUTE_NAMES as $name) {
            if (! Route::has($name)) {
                continue;
            }

            $urls[] = route($name, absolute: true);
        }

        $urls = array_values(array_unique($urls));

        $body = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $body .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $loc) {
            $body .= '  <url>'."\n";
            $body .= '    <loc>'.e($loc).'</loc>'."\n";
            $body .= '  </url>'."\n";
        }

        $body .= '</urlset>'."\n";

        return response($body, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
