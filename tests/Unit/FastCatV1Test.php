<?php

namespace Tests\Unit;

use App\Protocols\FastCatV1;
use Tests\TestCase;

class FastCatV1Test extends TestCase
{
    public function testItRemovesBuiltInAutomaticGroupsAndTheirReferences()
    {
        $config = [
            'proxy-groups' => [
                ['name' => 'FastCat', 'type' => 'select', 'proxies' => ['自动选择', '故障转移', '香港', '日本']],
                ['name' => '自动选择', 'type' => 'url-test', 'proxies' => ['香港', '日本']],
                ['name' => '故障转移', 'type' => 'fallback', 'proxies' => ['香港', '日本']],
                ['name' => '香港', 'type' => 'fallback', 'proxies' => ['香港｜主入口', '香港｜备用1']],
            ],
        ];

        $filtered = FastCatV1::removeHiddenProxyGroups($config);

        $this->assertSame(['FastCat', '香港'], array_column($filtered['proxy-groups'], 'name'));
        $this->assertSame(['香港', '日本'], $filtered['proxy-groups'][0]['proxies']);
        $this->assertSame(['香港｜主入口', '香港｜备用1'], $filtered['proxy-groups'][1]['proxies']);
    }

    public function testItLeavesAConfigWithoutProxyGroupsUnchanged()
    {
        $config = ['proxies' => [['name' => '香港']]];

        $this->assertSame($config, FastCatV1::removeHiddenProxyGroups($config));
    }
}
