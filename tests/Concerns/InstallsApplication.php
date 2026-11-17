<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

trait InstallsApplication
{
    protected function installApplicationForTest(): void
    {
        $storage = sys_get_temp_dir().'/laravel13idc-sales-'.Str::uuid();
        File::ensureDirectoryExists($storage.'/framework/views');
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($storage));
        $this->app->useStoragePath($storage);
        config(['view.compiled' => $storage.'/framework/views']);
        file_put_contents(storage_path('installed.lock'), 'isolated sales test');
    }
}
