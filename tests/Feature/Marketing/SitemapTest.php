<?php

declare(strict_types=1);

it('serves an xml sitemap with only indexable marketing urls', function () {
    $response = $this->get('/sitemap.xml');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    $body = $response->getContent();
    expect($body)->toBeString();

    $xml = simplexml_load_string((string) $body);
    expect($xml)->not->toBeFalse()
        ->and($xml->getName())->toBe('urlset');

    $locs = [];
    foreach ($xml->url as $url) {
        $locs[] = (string) $url->loc;
    }

    expect($locs)->toBe([route('home')])
        ->and($locs)->not->toContain(url('/login'))
        ->and($locs)->not->toContain(url('/dashboard'))
        ->and($locs)->not->toContain(url('/health'))
        ->and($locs)->not->toContain(url('/demo/beeindigd'))
        ->and($body)->not->toContain('/o/');
});

it('advertises the production sitemap in robots.txt', function () {
    $body = file_get_contents(public_path('robots.txt'));

    expect($body)->toBeString()
        ->and($body)->toContain("User-agent: *\nDisallow:")
        ->and($body)->toContain('Sitemap: https://intake-engine.nl/sitemap.xml')
        ->and($body)->not->toContain('Disallow: /');
});
