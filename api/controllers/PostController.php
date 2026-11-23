<?php
/**
 * Hublog 动态发布与管理控制器
 * 优化查询性能，避免 N+1 问题
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\Database;
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
// P1-3：SSE 服务端实时推送，用于通知 push 实时推送
use Hublog\Core\SsePush;

class PostController extends BaseController
{
    /** V56：动态可见范围取值（写库白名单，也是列表过滤的唯一依据） */
    private const VISIBILITY_TYPES = ['public', 'circle', 'friend', 'private'];

    use PostCrudTrait;
    use PostUpdateTrait;
    use PostFeedTrait;
    use PostTypeTrait;
    use PostCommentTrait;
    use PostUploadTrait;
    use PostModerationTrait;
    use PostSettingsTrait;
}
