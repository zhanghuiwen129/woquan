<?php
/**
 * 可替换点契约护栏（P1-2，unit）
 *
 * 三件事：
 *   1. 两个接口的方法集 / 参数 / 返回类型与设计逐字对齐（防止契约被无意改写）；
 *   2. 两个适配器 final + 实现接口 + 方法签名镜像接口（防止委托漏方法）；
 *   3. 零运行时改动证据：静态调用点计数与四个高压文件哈希冻结。
 *
 * 注意：本文件刻意不出现字面量形式的静态调用点，否则会污染被冻结的计数。
 */

declare(strict_types=1);

namespace Hublog\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

final class StaticAdapterContractTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** 期望的数据库契约：方法名 => [参数名 => [类型, 是否可选], 返回类型] */
    private const DB_CONTRACT = [
        'query' => [['sql', 'string', false], ['params', 'array', true], 'PDOStatement'],
        'fetchOne' => [['sql', 'string', false], ['params', 'array', true], '?array'],
        'fetchAll' => [['sql', 'string', false], ['params', 'array', true], 'array'],
        'fetchColumn' => [['sql', 'string', false], ['params', 'array', true], 'mixed'],
        'insert' => [['table', 'string', false], ['data', 'array', false], 'int'],
        'update' => [
            ['table', 'string', false], ['data', 'array', false],
            ['where', 'string', false], ['whereParams', 'array', true], 'int',
        ],
        'delete' => [['table', 'string', false], ['where', 'string', false], ['params', 'array', true], 'int'],
        'beginTransaction' => ['void'],
        'commit' => ['void'],
        'rollBack' => ['void'],
    ];

    /** 期望的缓存契约 */
    private const CACHE_CONTRACT = [
        'set' => [['key', 'string', false], ['value', 'mixed', false], ['ttl', 'int', true], 'bool'],
        'get' => [['key', 'string', false], 'mixed'],
        'delete' => [['key', 'string', false], 'bool'],
        'deleteByPrefix' => [['prefix', 'string', false], 'void'],
        'has' => [['key', 'string', false], 'bool'],
        'remember' => [
            ['key', 'string', false], ['callback', 'callable', false], ['ttl', 'int', true], 'mixed',
        ],
        'nsVersion' => [['ns', 'string', false], 'int'],
        'bumpNsVersion' => [['ns', 'string', false], 'void'],
        'bumpNsVersionThrottled' => [['ns', 'string', false], ['window', 'int', true], 'void'],
    ];

    /**
     * 四个高压文件的基线 sha1（本轮零修改的证据）。
     * 存 LF 归一化后的哈希（与 HEAD blob 一致），不存 Windows CRLF 工作区哈希——
     * 否则 core.autocrlf=true 的本机绿灯、Linux CI（LF 检出）必红。
     */
    private const FROZEN_HASHES = [
        'api/core/Database.php' => '0d51967f819ee2c5223ee7949e032babe6668b91',
        'api/core/Cache.php' => 'dd393284225da394bd3eed9da780188e4d26fb79',
        'api/core/License.php' => 'df0eb53967ba6a72980420530eea039b3a2ab301',
        'api/core/MigrationBatchC.php' => '4e989a810c9f6e5867d72514aebc21cd73ce9f9e',
    ];

    public function testAutoloadExposesBothInterfacesAndBothAdapters(): void
    {
        $this->assertTrue(interface_exists('Hublog\Core\DatabaseInterface'));
        $this->assertTrue(interface_exists('Hublog\Core\CacheInterface'));
        $this->assertTrue(class_exists('Hublog\Core\StaticDatabaseAdapter'));
        $this->assertTrue(class_exists('Hublog\Core\StaticCacheAdapter'));
    }

    public function testDatabaseInterfaceMethodSetMatchesDesign(): void
    {
        $this->assertContract('Hublog\Core\DatabaseInterface', self::DB_CONTRACT);
        $ref = new ReflectionClass('Hublog\Core\DatabaseInterface');
        $this->assertTrue($ref->isInterface());
        // 契约只声明实例方法：未来可用构造注入替换实现
        foreach ($ref->getMethods() as $method) {
            $this->assertFalse($method->isStatic(), $method->getName() . ' 必须是实例方法');
        }
    }

    public function testCacheInterfaceMethodSetMatchesDesign(): void
    {
        $this->assertContract('Hublog\Core\CacheInterface', self::CACHE_CONTRACT);
        $ref = new ReflectionClass('Hublog\Core\CacheInterface');
        $this->assertTrue($ref->isInterface());
        foreach ($ref->getMethods() as $method) {
            $this->assertFalse($method->isStatic(), $method->getName() . ' 必须是实例方法');
        }
    }

    public function testAdaptersAreFinalAndImplementTheirContracts(): void
    {
        $db = new ReflectionClass('Hublog\Core\StaticDatabaseAdapter');
        $cache = new ReflectionClass('Hublog\Core\StaticCacheAdapter');
        $this->assertTrue($db->isFinal());
        $this->assertTrue($cache->isFinal());
        $this->assertTrue($db->implementsInterface('Hublog\Core\DatabaseInterface'));
        $this->assertTrue($cache->implementsInterface('Hublog\Core\CacheInterface'));
        $this->assertSame(0, $db->getConstructor() === null ? 0 : $db->getConstructor()->getNumberOfRequiredParameters());
        $this->assertSame(0, $cache->getConstructor() === null ? 0 : $cache->getConstructor()->getNumberOfRequiredParameters());
    }

    public function testAdapterSignaturesMirrorTheirInterfaces(): void
    {
        foreach ([
            'Hublog\Core\DatabaseInterface' => 'Hublog\Core\StaticDatabaseAdapter',
            'Hublog\Core\CacheInterface' => 'Hublog\Core\StaticCacheAdapter',
        ] as $interface => $adapter) {
            $expected = $this->signatureMap($interface);
            $actual = $this->signatureMap($adapter);
            $this->assertSame($expected, $actual, $adapter . ' 签名必须与 ' . $interface . ' 完全一致');
        }
    }

    public function testStaticCallSitesRemainFrozen(): void
    {
        $needles = [
            'Database' . '::' => 1938,
            'Cache' . '::' => 408,
        ];
        $counts = array_fill_keys(array_keys($needles), 0);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::ROOT . '/api', FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $info) {
            if (!$info->isFile() || strtolower($info->getExtension()) !== 'php') {
                continue;
            }
            $raw = (string)file_get_contents($info->getPathname());
            foreach ($needles as $needle => $_) {
                $counts[$needle] += substr_count($raw, $needle);
            }
        }
        foreach ($needles as $needle => $expected) {
            $this->assertSame($expected, $counts[$needle], '静态调用点计数被改动: ' . $needle);
        }
    }

    public function testHighRiskFilesAreByteForByteUnchanged(): void
    {
        foreach (self::FROZEN_HASHES as $path => $hash) {
            $raw = (string)file_get_contents(self::ROOT . '/' . $path);
            // 与行数门禁同口径：先把 CRLF/CR 归一为 LF 再哈希，兼容 core.autocrlf 差异。
            $normalized = str_replace(["\r\n", "\r"], "\n", $raw);
            $this->assertSame($hash, sha1($normalized), $path . ' 本轮必须零修改（LF 归一化比对）');
        }
    }

    /** 按设计表逐项断言方法存在、非静态、参数与返回类型一致。 */
    private function assertContract(string $class, array $contract): void
    {
        $ref = new ReflectionClass($class);
        $names = [];
        foreach ($ref->getMethods() as $method) {
            $names[] = $method->getName();
        }
        sort($names);
        $expected = array_keys($contract);
        sort($expected);
        $this->assertSame($expected, $names, $class . ' 方法集与契约不一致');

        foreach ($contract as $name => $spec) {
            $method = $ref->getMethod($name);
            $this->assertFalse($method->isStatic());
            if (is_string($spec)) {
                $expectedReturn = $spec;
                $expectedParams = [];
            } else {
                $expectedReturn = $spec[count($spec) - 1];
                $expectedParams = array_slice($spec, 0, -1);
            }
            $params = $method->getParameters();
            $this->assertCount(count($expectedParams), $params, $name . ' 参数个数不一致');
            foreach ($expectedParams as $i => [$pName, $pType, $optional]) {
                $this->assertSame($pName, $params[$i]->getName(), $name . ' 第 ' . ($i + 1) . ' 个参数名不一致');
                $this->assertSame($pType, (string)$params[$i]->getType(), $name . ' 参数类型不一致');
                $this->assertSame($optional, $params[$i]->isOptional(), $name . ' 参数可选性不一致');
            }
            $this->assertSame($expectedReturn, (string)$method->getReturnType(), $name . ' 返回类型不一致');
        }

        // 缓存契约的默认 ttl 必须由全局常量提供（调用时求值）
        if ($class === 'Hublog\Core\CacheInterface') {
            foreach (['set', 'remember'] as $name) {
                $param = $ref->getMethod($name)->getParameters()[2];
                $this->assertTrue($param->isDefaultValueAvailable());
                $constant = $param->getDefaultValueConstantName();
                $this->assertNotNull($constant);
                $this->assertStringEndsWith('CACHE_TTL', $constant);
            }
            $window = $ref->getMethod('bumpNsVersionThrottled')->getParameters()[1];
            $this->assertSame(20, $window->getDefaultValue());
        }
    }

    /** @return array<string,array{static:bool,params:list<array{name:string,type:string,optional:bool,constant:?string,default:mixed}>,return:string}> */
    private function signatureMap(string $class): array
    {
        $ref = new ReflectionClass($class);
        $out = [];
        foreach ($ref->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            $params = [];
            foreach ($method->getParameters() as $param) {
                $hasDefault = $param->isDefaultValueAvailable();
                $constant = $hasDefault ? $param->getDefaultValueConstantName() : null;
                $params[] = [
                    'name' => $param->getName(),
                    'type' => (string)$param->getType(),
                    'optional' => $param->isOptional(),
                    'constant' => $constant === null ? null : (str_ends_with($constant, 'CACHE_TTL') ? 'CACHE_TTL' : $constant),
                    'default' => ($hasDefault && $constant === null) ? $param->getDefaultValue() : null,
                ];
            }
            $out[$method->getName()] = [
                'static' => $method->isStatic(),
                'params' => $params,
                'return' => (string)$method->getReturnType(),
            ];
        }
        ksort($out);
        return $out;
    }
}
