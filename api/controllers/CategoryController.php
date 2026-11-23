<?php
/**
 * Hublog 分类（频道）关注控制器
 *
 * 关注某分类后，该分类发布新文章时，所有关注者会收到 type='category' 的通知
 * （推送逻辑见 PostCrudTrait::notifyCategoryFollowers）。
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\Auth;
use Hublog\Core\CategoryRepository;
use Hublog\Core\Helper;

class CategoryController extends BaseController
{
    /**
     * 关注 / 取消关注某分类（切换）
     * POST /category/follow  body: { category_id }
     */
    public function follow(Request $request): Response
    {
        $user = Auth::check($request);
        $categoryId = $request->getInt('category_id');
        if (!$categoryId) {
            return Response::error('参数错误', 400);
        }

        $cat = CategoryRepository::findActiveCategoryId($categoryId);
        if (!$cat) {
            return Response::error('分类不存在', 404);
        }

        $exists = CategoryRepository::findFollowEdge((int)$user['id'], $categoryId);
        $following = CategoryRepository::toggleFollow(
            $categoryId,
            (int)$user['id'],
            Helper::now(),
            $exists
        );

        $followerCount = $this->followerCount($categoryId);

        return Response::success([
            'following'       => $following,
            'follower_count'  => $followerCount,
        ], $following ? '已关注频道' : '已取消关注');
    }

    /**
     * 查询当前用户是否关注某分类 + 关注人数
     * GET /category/status?category_id=
     */
    public function status(Request $request): Response
    {
        $user = Auth::user($request);
        $categoryId = $request->getInt('category_id');
        if (!$categoryId) {
            return Response::error('参数错误', 400);
        }

        $following = false;
        if ($user) {
            $row = CategoryRepository::findFollowEdge((int)$user['id'], $categoryId);
            $following = !empty($row);
        }

        return Response::success([
            'following'       => $following,
            'follower_count'  => $this->followerCount($categoryId),
        ]);
    }

    /**
     * 当前用户关注的全部分类（用于「我的频道」）
     * GET /category/following
     */
    public function following(Request $request): Response
    {
        $user = Auth::check($request);
        $list = CategoryRepository::fetchFollowingCategories((int)$user['id']);
        return Response::success(['list' => $list]);
    }

    /**
     * 某分类的关注人数
     */
    private function followerCount(int $categoryId): int
    {
        try {
            return CategoryRepository::countFollowers($categoryId);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
