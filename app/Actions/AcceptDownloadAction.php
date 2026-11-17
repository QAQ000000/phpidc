<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DownloadAccessLevel;
use App\Enums\InstanceStatus;
use App\Models\DownloadResource;
use App\Models\Instance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AcceptDownloadAction
{
    public function execute(DownloadResource $resource, ?User $user): DownloadResource
    {
        return DB::transaction(function () use ($resource, $user): DownloadResource {
            if ($user !== null) {
                $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            }
            $resource = DownloadResource::whereKey($resource->id)->lockForUpdate()->firstOrFail();
            abort_unless($resource->is_active, 404);
            abort_unless($resource->access_level === DownloadAccessLevel::PUBLIC || ($user !== null && ($resource->access_level === DownloadAccessLevel::AUTHENTICATED
                || Instance::where('user_id', $user->id)->where('status', InstanceStatus::ACTIVE)->exists())), 403);
            if ($resource->storage_type === 'local') {
                abort_unless($resource->file_path !== null && Storage::disk('downloads')->exists($resource->file_path), 404);
            } else {
                abort_unless($resource->storage_type === 'external_url' && filter_var($resource->external_url, FILTER_VALIDATE_URL) !== false
                    && parse_url($resource->external_url, PHP_URL_SCHEME) === 'https', 404);
            }
            $resource->increment('download_count');

            return $resource;
        });
    }
}
