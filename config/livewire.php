<?php

declare(strict_types=1);

return [
    // Public CMS and sales pages are plain Blade. Filament explicitly renders
    // its own Livewire assets, so visiting a panel must not inject them into CMS responses.
    'inject_assets' => false,
];
