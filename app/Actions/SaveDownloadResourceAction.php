<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DownloadAccessLevel;
use App\Models\DownloadResource;
use App\Models\User;
use App\Support\OperationsAudit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SaveDownloadResourceAction
{
    public function execute(User $actor, array $input, string $reason, ?int $id = null, ?UploadedFile $file = null): DownloadResource
    {
        Gate::forUser($actor)->authorize('download.manage');
        $data = validator($input + ['file' => $file, 'reason' => $reason], [
            'category' => ['required', 'string', 'max:64'], 'title' => ['required', 'string', 'max:255'], 'version' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:10000'], 'storage_type' => ['required', Rule::in(['local', 'external_url'])],
            'external_url' => ['required_if:storage_type,external_url', 'nullable', 'url:https', 'max:2000'],
            'access_level' => ['required', Rule::enum(DownloadAccessLevel::class)], 'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'], 'file' => [$id === null && ($input['storage_type'] ?? '') === 'local' ? 'required' : 'nullable', 'file', 'max:51200'],
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'external_url.required_if' => '使用外部链接时，请填写下载地址。',
            'external_url.url' => '外部下载地址必须是有效的 HTTPS 链接。',
            'external_url.max' => '外部下载地址不能超过 2000 个字符。',
        ])->validate();
        unset($data['file'], $data['reason']);
        // Immutable versions: private file I/O precedes the short metadata transaction.
        if ($data['storage_type'] === 'local' && $file !== null) {
            $data['file_path'] = $file->store('resources', 'downloads');
            abort_unless(is_string($data['file_path']), 500);
            $data['file_size'] = $file->getSize();
            $data['sha256'] = hash_file('sha256', $file->getRealPath());
        }

        $uploadedPath = $data['file_path'] ?? null;
        try {
            return DB::transaction(function () use ($actor, $data, $reason, $id): DownloadResource {
                $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('download.manage');
                $resource = $id === null ? new DownloadResource : DownloadResource::whereKey($id)->lockForUpdate()->firstOrFail();
                $before = ['access_level' => $resource->exists ? $resource->access_level->value : null, 'is_active' => $resource->is_active];
                if ($data['storage_type'] === 'local') {
                    abort_unless(isset($data['file_path']) || ($resource->storage_type === 'local' && $resource->file_path !== null), 422);
                    $data['external_url'] = null;
                } else {
                    $data['file_path'] = null;
                    $data['file_size'] = null;
                    $data['sha256'] = null;
                }
                $resource->fill([
                    'category' => $data['category'], 'title' => $data['title'], 'version' => $data['version'] ?? null,
                    'description' => $data['description'] ?? null, 'storage_type' => $data['storage_type'],
                    'file_path' => $data['storage_type'] === 'local' ? ($data['file_path'] ?? $resource->file_path) : null, 'external_url' => $data['external_url'] ?? null,
                    'file_size' => $data['storage_type'] === 'local' ? ($data['file_size'] ?? $resource->file_size) : null, 'sha256' => $data['storage_type'] === 'local' ? ($data['sha256'] ?? $resource->sha256) : null,
                    'access_level' => $data['access_level'], 'sort_order' => $data['sort_order'], 'is_active' => $data['is_active'],
                ])->save();
                OperationsAudit::record($actor, 'download.save', DownloadResource::class, $resource->id, $reason, $before,
                    ['access_level' => $resource->access_level->value, 'is_active' => $resource->is_active, 'sha256' => $resource->sha256]);

                return $resource;
            });
        } catch (\Exception $exception) {
            // Metadata/audit failure must not leave a newly uploaded private orphan.
            if ($uploadedPath !== null) {
                Storage::disk('downloads')->delete($uploadedPath);
            }
            throw $exception;
        }
    }
}
