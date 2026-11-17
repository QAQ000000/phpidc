<?php

declare(strict_types=1);

namespace App\Drivers\Kyc;

use App\Contracts\KycDriverInterface;
use App\Data\KycInitiateResult;
use App\Data\KycStatusResult;
use App\Models\KycVerification;
use App\Models\User;
use App\Services\SettingService;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class IdcsmartAliKycDriver implements KycDriverInterface
{
    public const IDENTIFIER = 'idcsmartali';

    public function __construct(private readonly SettingService $settings) {}

    public function ready(): bool
    {
        $url = $this->settings->get('kyc.idcsmartali_endpoint', '') ?? '';
        $parts = parse_url($url);

        return $this->settings->getBool('kyc.enabled') && $this->settings->get('kyc.driver') === self::IDENTIFIER
            && filter_var($url, FILTER_VALIDATE_URL) !== false && is_array($parts) && ($parts['scheme'] ?? '') === 'https'
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && trim($this->settings->get('kyc.idcsmartali_api', '') ?? '') !== '' && trim($this->settings->get('kyc.idcsmartali_key', '') ?? '') !== ''
            && in_array($this->settings->get('kyc.idcsmartali_biz_code'), ['FACE', 'SMART_FACE', 'CERT_PHOTO', 'CERT_PHOTO_FACE'], true);
    }

    public function initiate(User $user, array $payload): KycInitiateResult
    {
        // initialize is the only create request; the Action has already committed its local intent.
        $result = $this->request('initialize', ['outer_order_no' => $payload['request_id'],
            'biz_code' => $this->settings->get('kyc.idcsmartali_biz_code'), 'cert_type' => 'IDENTITY_CARD',
            'cert_name' => $payload['real_name'], 'cert_no' => $payload['id_number'],
            'return_url' => rtrim((string) config('app.url'), '/').'/console/account/kyc/return']);
        $externalId = $result['certify_id'] ?? null;
        if (! in_array($result['status'] ?? null, [200, '200'], true) || ! is_string($externalId) || trim($externalId) === ''
            || strlen($externalId) > 255 || preg_match('/[\x00-\x1F\x7F]/', $externalId)) {
            throw new DomainException('实名初始化未取得可确认的申请编号，请联系管理员核对，勿重复发起。');
        }

        return new KycInitiateResult($externalId);
    }

    public function authorization(KycVerification $verification): KycInitiateResult
    {
        $this->requireRecord($verification);
        $result = $this->request('certify', ['certify_id' => $verification->external_id]);
        $url = $result['url'] ?? null;
        $parts = is_string($url) ? parse_url($url) : false;
        if (! is_string($url) || strlen($url) > 8192 || filter_var($url, FILTER_VALIDATE_URL) === false || ! is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || (isset($result['status']) && ! in_array($result['status'], [200, '200'], true))) {
            throw new DomainException('实名平台未返回有效的 HTTPS 认证链接，请稍后继续或联系管理员。');
        }

        return new KycInitiateResult($verification->external_id, redirectUrl: $url);
    }

    public function queryStatus(KycVerification $verification): KycStatusResult
    {
        $this->requireRecord($verification);
        $result = $this->request('query', ['certify_id' => $verification->external_id]);
        // The supplied source only proves status=200 means verified. Other statuses remain pending.
        $status = in_array($result['status'] ?? null, [200, '200'], true) ? 'verified' : 'pending';

        return new KycStatusResult($verification->external_id, $status, $verification->masked_summary ?? []);
    }

    public function handleCallback(Request $request): KycStatusResult
    {
        throw new DomainException('此实名供应商未提供已核实的签名回调协议，请使用主动查询。');
    }

    private function requireRecord(KycVerification $verification): void
    {
        if ($verification->driver !== self::IDENTIFIER || ! is_string($verification->external_id) || $verification->external_id === '') {
            throw new DomainException('实名申请缺少可查询的外部编号，请联系管理员核对。');
        }
    }

    private function request(string $action, array $params): array
    {
        if (! $this->ready()) {
            throw new DomainException('实名认证服务尚未启用或配置不完整。');
        }
        try {
            $response = Http::connectTimeout(5)->timeout(30)->withoutRedirecting()->asForm()->withHeaders([
                'api' => $this->settings->get('kyc.idcsmartali_api'), 'key' => $this->settings->get('kyc.idcsmartali_key'),
            ])->post($this->settings->get('kyc.idcsmartali_endpoint').'?action='.$action, $params);
        } catch (ConnectionException) {
            // Transport error text can contain identity data or credentials; preserve the pending intent.
            throw new DomainException('实名平台连接未取得确定结果，请稍后查询或联系管理员核对。');
        }
        $body = $response->body();
        $data = json_decode(str_starts_with($body, "\xEF\xBB\xBF") ? substr($body, 3) : $body, true);
        if ($response->status() !== 200 || ! is_array($data) || array_is_list($data)) {
            throw new DomainException('实名平台未返回可确认的结果，请稍后查询或联系管理员核对。');
        }

        return $data;
    }
}
