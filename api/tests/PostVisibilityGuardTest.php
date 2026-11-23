<?php

declare(strict_types=1);

namespace Hublog\Tests;

use Hublog\Controllers\PostController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 发帖守卫：可见性规则与「双路径」守卫顺序（V167）
 *
 * 与 PostControllerScheduledPrivateTest 的关系：
 *   那个文件是 V161 缺陷的回归护栏（守住 create 路径），用正则 + 行扫描实现。
 *   本文件补的是它没覆盖的三块增量：
 *     1) update 路径 —— V161 缺陷（变量赋值晚于守卫）在 edit 侧是**同构结构**，
 *        属于同类缺陷的复发高发区，此前只有"文案是否存在"级别的检查。
 *     2) VISIBILITY_TYPES 常量 —— 前端依这四个值渲染选项，改名/漏项会让
 *        用户发不出帖或发到错误的可见范围。
 *     3) 分析手段升级为 token 级：PhpToken 会把注释单独切出来，
 *        从根上消除"注释里提到同一句文案导致误命中"这类问题
 *        （V165 就在现有文件里踩过这个坑：第一次命中到第 517 行的解释性注释）。
 */
final class PostVisibilityGuardTest extends TestCase
{
    /**
     * 取某个方法体内、排除注释之后的有效 token 序列。
     *
     * @return list<\PhpToken>
     */
    private function methodTokens(string $methodName): array
    {
        // 逻辑源码视图：PostController + 全部 Post*Trait。合并后 token->line
        // 是全局行号，但同一方法内赋值/守卫的相对顺序不变，行号断言仍然成立。
        $src   = SourceView::postController();
        $this->assertNotSame('', $src, 'PostController 逻辑源码视图不应为空');
        $all   = \PhpToken::tokenize($src);

        // 定位方法名 token（T_FUNCTION 之后跳过空白/引用符，取第一个 T_STRING）
        $nameIdx = null;
        foreach ($all as $i => $tok) {
            if ($tok->id !== T_FUNCTION) {
                continue;
            }
            for ($j = $i + 1; $j < count($all); $j++) {
                $id = $all[$j]->id;
                if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                    continue;
                }
                if ($id === T_STRING && $all[$j]->text === $methodName) {
                    $nameIdx = $i;
                    break 2;
                }
                break; // 第一个非空白 token 若不是方法名就放弃这个 function 位置
            }
        }
        $this->assertNotNull($nameIdx, "未找到方法 {$methodName}");

        // 从方法名往后做花括号配对，定出方法体范围
        $open    = null;
        $depth   = 0;
        $endIdx  = null;
        $total   = count($all);
        for ($i = $nameIdx; $i < $total; $i++) {
            if ($all[$i]->text === '{') {
                if ($open === null) {
                    $open = $i;
                }
                $depth++;
            } elseif ($all[$i]->text === '}') {
                $depth--;
                if ($open !== null && $depth === 0) {
                    $endIdx = $i;
                    break;
                }
            }
        }
        $this->assertNotNull($endIdx, "方法 {$methodName} 体未闭合");

        // 切片 + 剔除注释（T_COMMENT / T_DOC_COMMENT）—— 这是本方法相对正则扫描的核心价值
        $out = [];
        for ($i = $open; $i <= $endIdx; $i++) {
            if ($all[$i]->id === T_COMMENT || $all[$i]->id === T_DOC_COMMENT) {
                continue;
            }
            $out[] = $all[$i];
        }
        return $out;
    }

    /**
     * 在 token 序列里找「某个变量被赋值」的首个行号。
     * 赋值 = T_VARIABLE 紧跟一个 '='（再后紧跟的不是 '='，排除 == 比较）。
     */
    private function firstAssignLine(array $tokens, string $varName): ?int
    {
        $count = count($tokens);
        for ($i = 0; $i < $count - 2; $i++) {
            if ($tokens[$i]->id !== T_VARIABLE || $tokens[$i]->text !== $varName) {
                continue;
            }
            // 跳过变量与 '=' 之间的空白 token（tokenize 会把空格单独切出来）
            $k = $i + 1;
            while ($k < $count && $tokens[$k]->id === T_WHITESPACE) {
                $k++;
            }
            if (($tokens[$k]->text ?? '') === '=' && ($tokens[$k + 1]->text ?? '') !== '=') {
                return $tokens[$i]->line;
            }
        }
        return null;
    }

    /** 找含指定文案的字符串字面量首次出现的行号（注释已被剔除，不会误命中） */
    private function firstStringLine(array $tokens, string $needle): ?int
    {
        foreach ($tokens as $tok) {
            if ($tok->id === T_CONSTANT_ENCAPSED_STRING && str_contains($tok->text, $needle)) {
                return $tok->line;
            }
        }
        return null;
    }

    // ==================== 可见性枚举 ====================

    public function testVisibilityTypesAreComplete(): void
    {
        $this->assertSame(
            ['public', 'circle', 'friend', 'private'],
            (new ReflectionClass(PostController::class))->getConstant('VISIBILITY_TYPES'),
            '可见范围是 V56 定死的四档：所有人 / 仅本圈 / 仅好友 / 仅自己'
        );
    }

    // ==================== create 路径 ====================

    public function testCreateAssignsScheduledBeforePrivateGuard(): void
    {
        $tokens     = $this->methodTokens('validateCreateInput');
        $assignLine = $this->firstAssignLine($tokens, '$isScheduled');
        $guardLine  = $this->firstStringLine($tokens, '私密动态不支持定时发布');

        $this->assertNotNull($assignLine, '未找到 $isScheduled 的赋值');
        $this->assertNotNull($guardLine, '未找到私密+定时守卫');

        $this->assertLessThan(
            $guardLine,
            $assignLine,
            "V161 缺陷回归：\$isScheduled（第 {$assignLine} 行）必须在守卫（第 {$guardLine} 行）之前赋值，"
            . '否则守卫读到的恒为未定义值，形同虚设。'
        );
    }

    public function testCreateRejectsCircleVisibilityWithoutCircle(): void
    {
        $tokens = $this->methodTokens('validateCreateInput');
        $guard  = $this->firstStringLine($tokens, '仅圈内可见的动态必须发布到圈子');

        $this->assertNotNull(
            $guard,
            '「仅圈内可见但未指定圈子」必须被拦截——否则这条动态没有任何人可见，且列表查询会异常'
        );
    }

    // ==================== update 路径（本文件的主要增量） ====================

    public function testUpdateGuardVariablesAssignedBeforeUse(): void
    {
        $tokens = $this->methodTokens('resolveUpdateFields');

        $visLine   = $this->firstAssignLine($tokens, '$effectiveVisibility');
        $schedLine = $this->firstAssignLine($tokens, '$effectiveScheduled');
        $guardLine = $this->firstStringLine($tokens, '私密动态不支持定时发布');

        $this->assertNotNull($visLine, '未找到 $effectiveVisibility 的赋值');
        $this->assertNotNull($schedLine, '未找到 $effectiveScheduled 的赋值');
        $this->assertNotNull($guardLine, '未找到私密+定时守卫');

        // 这正是 V161 缺陷在 create 侧的确切形态：变量晚于守卫 → 恒 falsy → 永不拦截。
        // edit 侧是两个变量一起判定，必须两个都先落地。
        $this->assertLessThan(
            $guardLine,
            $visLine,
            "\$effectiveVisibility（第 {$visLine} 行）必须在守卫（第 {$guardLine} 行）之前赋值"
        );
        $this->assertLessThan(
            $guardLine,
            $schedLine,
            "\$effectiveScheduled（第 {$schedLine} 行）必须在守卫（第 {$guardLine} 行）之前赋值"
        );
    }

    public function testUpdateGuardUsesMergedExistingState(): void
    {
        // 守卫必须基于「既有帖子 + 本次增量」合并后的结果判定。
        // 只看增量会漏掉：帖子本来是 private，本次改标题不算改可见性，
        // 但再传一个 scheduled_at —— 组合起来就成了 illegal 的「私密定时帖」。
        $tokens = $this->methodTokens('resolveUpdateFields');
        $body   = implode('', array_map(fn($t) => $t->text, $tokens));

        $this->assertStringContainsString("\$existing['visibility']", $body, '守卫应回看既有可见范围');
        $this->assertStringContainsString('array_key_exists', $body, '判定应能区分「未传」与「传空」');
    }

    public function testBothPathsShareIdenticalRejectionMessage(): void
    {
        $create = implode('', array_map(fn($t) => $t->text, $this->methodTokens('validateCreateInput')));
        $update = implode('', array_map(fn($t) => $t->text, $this->methodTokens('resolveUpdateFields')));

        $this->assertStringContainsString('私密动态不支持定时发布', $create);
        $this->assertStringContainsString('私密动态不支持定时发布', $update);
    }
}
