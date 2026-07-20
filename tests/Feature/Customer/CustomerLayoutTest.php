<?php

declare(strict_types=1);

test('customer layout lets Livewire provide the single Alpine runtime', function () {
    $layout = file_get_contents(resource_path('views/layouts/customer.blade.php'));

    expect($layout)->toBeString()
        ->toContain("@vite(['resources/css/app.css'])")
        ->toContain('@livewireScripts')
        ->not->toContain('resources/js/app.js');
});
