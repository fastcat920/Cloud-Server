<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Support\ContentLocale;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class ContentLocaleTest extends TestCase
{
    public function test_english_content_is_selected_from_x_locale_header(): void
    {
        $plan = new Plan([
            'name' => '中文套餐',
            'name_en' => 'English plan',
            'content' => '中文描述',
            'content_en' => 'English description',
        ]);
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_X_LOCALE' => 'en-US']);

        ContentLocale::localize($plan, ['name', 'content'], $request);

        $this->assertSame('English plan', $plan->name);
        $this->assertSame('English description', $plan->content);
        $this->assertArrayNotHasKey('name_en', $plan->toArray());
        $this->assertArrayNotHasKey('content_en', $plan->toArray());
    }

    public function test_missing_english_content_falls_back_to_chinese(): void
    {
        $plan = new Plan([
            'name' => '中文套餐',
            'name_en' => '',
            'content' => '中文描述',
            'content_en' => null,
        ]);
        $request = Request::create('/?lang=en', 'GET');

        ContentLocale::localize($plan, ['name', 'content'], $request);

        $this->assertSame('中文套餐', $plan->name);
        $this->assertSame('中文描述', $plan->content);
    }

    public function test_chinese_locale_keeps_original_content(): void
    {
        $plan = new Plan([
            'name' => '中文套餐',
            'name_en' => 'English plan',
        ]);
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_X_LOCALE' => 'zh-CN']);

        ContentLocale::localize($plan, ['name'], $request);

        $this->assertSame('中文套餐', $plan->name);
    }

    public function test_localized_config_value_uses_english_with_chinese_fallback(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_X_LOCALE' => 'en-US']);

        $this->assertSame('English site', ContentLocale::value('中文站点', 'English site', $request));
        $this->assertSame('中文站点', ContentLocale::value('中文站点', '', $request));
    }
}
