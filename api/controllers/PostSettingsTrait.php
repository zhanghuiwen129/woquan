<?php

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\SettingsRepository;
use Hublog\Core\Auth;
use Hublog\Core\Validator;
use Hublog\Core\Security;
use Hublog\Core\Upload;
use Hublog\Core\VideoCompress;
use Hublog\Core\Cache;
use Hublog\Core\Helper;
use Hublog\Core\BadgeService;
use Hublog\Core\Hook;
use Hublog\Core\PostRepository;
use Hublog\Core\PostFormatter;
use Hublog\Core\Moderation;
use Hublog\Core\Exception\HublogException;
use Hublog\Core\SsePush;

trait PostSettingsTrait
{
    /**
     * 检查用户权限配置
     */

    private function checkUserPermission(string $key): bool
    {
        $settings = Cache::remember('site_settings', function () {
            // 全表 key/value 原始行下沉仓储；组装与 3600s 缓存边界仍留在控制器
            $rows = SettingsRepository::fetchKeyValueRows();
            $result = [];
            foreach ($rows as $row) {
                $result[$row['key']] = $row['value'];
            }
            return $result;
        }, 3600);
        return ($settings[$key] ?? '1') === '1';
    }

    /**
     * V64：该帖型后台是否开了开关
     *
     * 取值和 AppController::resolvePostTypes() 同一套白名单，保证
     * 「后台显示得出来」⇔「后端收得下去」不会说反话。
     * 开关串缺失（老站点还没落库）→ 按开启处理，避免升级后老客户端发帖被挡。
     */

    private static function isPostTypeEnabled(array $settings, string $type): bool
    {
        if ($type === 'normal') return true;
        $raw = strtolower(trim((string)($settings['post_types'] ?? '')));
        if ($raw === '') return true;
        foreach (explode(',', $raw) as $piece) {
            if (trim($piece) === $type) return true;
        }
        return false;
    }

    /**
     * V134：帖型配置里是否真的有可展示的内容。
     * question 的 question 字段在 create() 入口已校验非空；team/checkin 的
     * headline/goal 为选填但 need_count/target_days 有值——只要配置数组里有
     * 任一非空字段（含非 0 数字），即视为「这条帖型帖有内容」，允许纯文字发布。
     */

    private static function postTypeConfigHasText(?array $typeConfig): bool
    {
        if ($typeConfig === null) return false;
        foreach ($typeConfig as $v) {
            if ($v !== null && $v !== '' && $v !== 0 && $v !== '0') return true;
        }
        return false;
    }

    /**
     * 获取站点配置（带缓存）
     */

    private function getSiteSettings(): array
    {
        return Cache::remember('site_settings', function () {
            $rows = SettingsRepository::fetchKeyValueRows();
            $result = [];
            foreach ($rows as $row) {
                $result[$row['key']] = $row['value'];
            }
            return $result;
        }, 3600);
    }

    /**
     * V89：自主声明枚举白名单。只收 ai/marketing/fiction/opinion/repost，
     * 其余（含乱传值）一律落空串=未声明，避免脏数据进库。
     */

    private function sanitizeDeclaration(string $value): string
    {
        $value = trim($value);
        return in_array($value, ['ai', 'marketing', 'fiction', 'opinion', 'repost', 'original'], true) ? $value : '';
    }
}
