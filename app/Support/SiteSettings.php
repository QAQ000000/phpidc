<?php

declare(strict_types=1);

namespace App\Support;

class SiteSettings
{
    /** @return array<string, array{label: string, type: string, default: string}> */
    public static function fields(): array
    {
        return [
            'site.name' => ['label' => '站点名称', 'type' => 'text', 'default' => (string) config('app.name', 'CloudCMS')],
            'site.logo_url' => ['label' => 'Logo 地址', 'type' => 'text', 'default' => ''],
            'site.icp_number' => ['label' => '备案号', 'type' => 'text', 'default' => ''],
            'site.copyright' => ['label' => '页脚版权', 'type' => 'text', 'default' => ''],
            'site.support_url' => ['label' => '客服入口', 'type' => 'text', 'default' => ''],
            'site.announcement' => ['label' => '全局公告（纯文本）', 'type' => 'textarea', 'default' => ''],
            'site.seo_title' => ['label' => '默认 SEO 标题', 'type' => 'text', 'default' => '云产品与使用指南'],
            'site.seo_description' => ['label' => '默认 SEO 简介', 'type' => 'textarea', 'default' => '发现云服务、数字产品与实用的产品使用指南。'],
            'site.llms_description' => ['label' => 'AI 阅读索引简介', 'type' => 'textarea', 'default' => '公开产品、新闻与使用指南。价格以创建账单时的服务端报价为准。'],
        ];
    }
}
