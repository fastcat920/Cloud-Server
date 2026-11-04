<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Admin\AliyunDnsController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AliyunDnsControllerTest extends TestCase
{
    public function testRequestSourcesAreNormalizedFromAliyunNestedJsonValues()
    {
        $method = new ReflectionMethod(AliyunDnsController::class, 'stringList');
        $method->setAccessible(true);

        $sources = $method->invoke(new AliyunDnsController(), [
            'RequestSource' => [
                'RequestSource' => ['["default"]', '["telecom", "mobile"]', 'default'],
            ],
        ]);

        $this->assertSame(['default', 'telecom', 'mobile'], $sources);
    }

    public function testHealthTasksAreConvertedToAliyunParameters()
    {
        $method = new ReflectionMethod(AliyunDnsController::class, 'healthTaskParameters');
        $method->setAccessible(true);

        $tasks = $method->invoke(new AliyunDnsController(), [
            ['template_id' => ' mtp-ping ', 'port' => null],
            ['template_id' => 'mtp-http', 'port' => '443'],
        ]);

        $this->assertSame([
            ['TemplateId' => 'mtp-ping', 'Port' => null],
            ['TemplateId' => 'mtp-http', 'Port' => 443],
        ], $tasks);
    }

    public function testEmptyHealthTasksProduceDeletionMarkerWhenRequested()
    {
        $method = new ReflectionMethod(AliyunDnsController::class, 'healthTaskParameters');
        $method->setAccessible(true);

        $tasks = $method->invoke(new AliyunDnsController(), [], true);

        $this->assertSame([['TemplateId' => '']], $tasks);
    }
}
