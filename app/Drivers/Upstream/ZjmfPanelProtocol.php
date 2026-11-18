<?php

declare(strict_types=1);

namespace App\Drivers\Upstream;

use App\Models\Instance;
use DOMDocument;
use DOMElement;
use Illuminate\Validation\ValidationException;

class ZjmfPanelProtocol
{
    public function __construct(private readonly ZjmfClient $client) {}

    public function widgets(Instance $instance, array $types = [], array $readOptions = []): array
    {
        if (count($types) === 1 && str_starts_with($types[0], 'chart:')) {
            // As with the standalone STATUS read, use authenticated sync capabilities for read-only data.
            // The caller Action validates the chart/window and rechecks binding and credentials after HTTP.
            return [(new ZjmfTelemetryProtocol($this->client))->chart($instance, substr($types[0], 6), $readOptions)];
        }
        $context = ['instance_id' => $instance->id];
        // Explicit customer reads use the standard HTTP timeout; async host-sync keeps its separate 3s limit.
        $header = $this->client->request('GET', '/host/header', ['host_id' => $instance->upstream_instance_id, 'source' => 'API'], $context);
        if (($header['status'] ?? null) !== 200 || ($header['data']['host_data']['domainstatus'] ?? null) !== 'Active'
            || (isset($header['data']['host_data']['id']) && (string) $header['data']['host_data']['id'] !== $instance->upstream_instance_id)) {
            throw ValidationException::withMessages(['panel' => '上游服务尚未处于可登录状态，请稍后重试。']);
        }
        // Select before I/O: machine management needs only the header, never telemetry or login HTML.
        // An empty selection preserves the full widget behavior of the frozen capability interface.
        $widgets = [];
        if ($types === [] || array_intersect($types, ['machine_status', 'chart']) !== []) {
            $telemetry = (new ZjmfTelemetryProtocol($this->client))->widgets($instance, $header);
            $widgets = $types === [] ? $telemetry : array_values(array_filter($telemetry, fn ($widget) => in_array($widget['type'], $types, true)));
        }
        if ($types === [] || in_array('cloud_controls', $types, true)) {
            $widgets = array_merge($widgets, (new ZjmfCloudControlProtocol($this->client))->widgets($header));
        }
        if ($types !== [] && ! in_array('login_form', $types, true)) {
            return $widgets;
        }
        $areas = $header['data']['module_client_area'] ?? [];
        if (! is_array($areas) || ! collect($areas)->contains(fn ($area) => is_array($area) && ($area['key'] ?? null) === 'information')) {
            return $widgets;
        }
        // Verified classic API: the header advertises a tab; its content contains the login form.
        $content = $this->client->request('POST', '/zjmf_api/provision/custom/content', ['id' => $instance->upstream_instance_id, 'key' => 'information'], $context);
        if (($content['status'] ?? null) !== 200 || ! is_string($content['data']['html'] ?? null)) {
            throw ValidationException::withMessages(['panel' => '暂时无法取得面板登录入口，请稍后重试。']);
        }
        $html = $content['data']['html'];
        if ($html === '') {
            return $widgets;
        }
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $forms = [];
        foreach ($dom->getElementsByTagName('form') as $form) {
            $login = $this->loginForm($form);
            if ($login !== null) {
                $forms[] = $login;
            }
        }

        // Do not guess between multiple login destinations or render supplier scripts/HTML.
        return array_merge($widgets, count($forms) === 1 ? [['key' => 'information', 'title' => '登录面板', 'type' => 'login_form', 'data' => $forms[0]]] : []);
    }

    /** Read an already-issued code; this content endpoint never generates or redeems one. */
    public function deliveryWidgets(Instance $instance, array $header): array
    {
        $areas = $header['data']['module_client_area'] ?? [];
        if (($header['status'] ?? null) !== 200 || ($header['data']['host_data']['domainstatus'] ?? null) !== 'Active'
            || ! is_array($areas) || ! collect($areas)->contains(fn ($area) => is_array($area) && ($area['key'] ?? null) === 'redeem')) {
            return [];
        }
        $content = $this->client->request('POST', '/zjmf_api/provision/custom/content', ['id' => $instance->upstream_instance_id, 'key' => 'redeem'], ['instance_id' => $instance->id]);
        if (($content['status'] ?? null) !== 200 || ! is_string($content['data']['html'] ?? null)) {
            throw ValidationException::withMessages(['delivery' => '暂时无法读取上游兑换码，请稍后同步重试。']);
        }
        $html = $content['data']['html'];
        if ($html === '') {
            return [];
        }
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $codes = [];
        foreach ($dom->getElementsByTagName('input') as $input) {
            if ($input->getAttribute('id') === 'sub2api-redeem-code') {
                if (strtolower($input->getAttribute('type')) !== 'text' || ! $input->hasAttribute('readonly') || trim($input->getAttribute('value')) === '') {
                    return [];
                }
                $codes[] = $input->getAttribute('value');
            }
        }
        if (count($codes) !== 1) {
            return [];
        }
        $data = ['card_key' => $codes[0]];
        $links = [];
        foreach ($dom->getElementsByTagName('a') as $link) {
            $href = $link->getAttribute('href');
            $url = parse_url($href);
            if (filter_var($href, FILTER_VALIDATE_URL) && ! preg_match('/[\\\\\x00-\x20\x7f]/', $href)
                && strtolower($url['scheme'] ?? '') === 'https'
                && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['query']) && ! isset($url['fragment'])
                && ($url['path'] ?? '') === '/redeem') {
                $links[] = $href;
            }
        }
        if (count($links) === 1) {
            $data['redeem_url'] = $links[0];
        }

        return [['key' => 'redeem', 'title' => '兑换码', 'type' => 'redemption_code', 'data' => $data]];
    }

    private function loginForm(DOMElement $form): ?array
    {
        $action = $form->getAttribute('action');
        if (strtolower($form->getAttribute('method')) !== 'post' || ! filter_var($action, FILTER_VALIDATE_URL)
            || preg_match('/[\\\\\x00-\x20\x7f]/', $action)) {
            return null;
        }
        $url = parse_url($action);
        if (strtolower($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass'])
            || isset($url['fragment']) || ($url['path'] ?? '') !== '/vhost/index.php') {
            return null;
        }
        parse_str($url['query'] ?? '', $query);
        if (($query['c'] ?? null) !== 'session' || ($query['a'] ?? null) !== 'login') {
            return null;
        }
        $fields = [];
        foreach ($form->getElementsByTagName('input') as $input) {
            $name = $input->getAttribute('name');
            if (! in_array($name, ['token', 'username', 'passwd'], true) || isset($fields[$name])
                || strtolower($input->getAttribute('type')) !== 'hidden') {
                return null;
            }
            $fields[$name] = $input->getAttribute('value');
        }
        if (trim($fields['username'] ?? '') === '' || trim($fields['passwd'] ?? '') === '') {
            return null;
        }

        return ['action' => $action, 'fields' => $fields];
    }
}
