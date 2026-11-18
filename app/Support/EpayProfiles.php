<?php

declare(strict_types=1);

namespace App\Support;

use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Immutable credential snapshots; callers serialize current selection in the configuration action. */
class EpayProfiles
{
    public const CURRENT_KEY = 'epay_profile_id';

    public const PROFILE_PREFIX = 'epay_profile_';

    public const LOCK_KEY = 'epay_configuration_lock';

    public const ATTEMPT_KEY = 'epay_profile_id';

    public const GATEWAYS = ['epay_wechat', 'epay_alipay'];

    /** @return array{api_url: string, pid: string, key: string} */
    public function credentials(): array
    {
        $values = DB::table('system_settings')->where('group', 'payment')
            ->whereIn('key', ['epay_api_url', 'epay_pid', 'epay_key'])->get()->keyBy('key');
        $result = [];
        foreach (['api_url', 'pid', 'key'] as $name) {
            $row = $values->get('epay_'.$name);
            $result[$name] = $row === null || $row->value === null ? ''
                : ((bool) $row->is_encrypted ? Crypt::decryptString($row->value) : $row->value);
        }

        return $result;
    }

    public function currentId(): ?string
    {
        $value = DB::table('system_settings')->where('group', 'payment')->where('key', self::CURRENT_KEY)->value('value');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array{id: string, api_url: string, pid: string, key: string} */
    public function find(string $id): array
    {
        if (! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $id)) {
            throw new DomainException('Invalid payment credential profile.');
        }
        $row = DB::table('system_settings')->where('group', 'payment')->where('key', self::PROFILE_PREFIX.$id)->first();
        if ($row === null || ! $row->is_encrypted) {
            throw new DomainException('Payment credential profile is unavailable.');
        }
        $data = json_decode(Crypt::decryptString($row->value), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data) || ($data['version'] ?? null) !== 1) {
            throw new DomainException('Invalid payment credential profile.');
        }
        foreach (['api_url', 'pid', 'key'] as $name) {
            if (! isset($data[$name]) || ! is_string($data[$name]) || $data[$name] === '') {
                throw new DomainException('Incomplete payment credential profile.');
            }
        }

        return ['id' => $id, 'api_url' => $data['api_url'], 'pid' => $data['pid'], 'key' => $data['key']];
    }

    /** @return array{api_url: string, pid: string, key: string} */
    public function currentCredentials(): array
    {
        $id = $this->currentId();
        if ($id === null) {
            return $this->credentials();
        }
        $profile = $this->find($id);

        return ['api_url' => $profile['api_url'], 'pid' => $profile['pid'], 'key' => $profile['key']];
    }
}
