<?php

namespace Hublog\Controllers;

use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\FriendLinkRepository;
use Hublog\Core\Auth;
use Hublog\Core\Cache;
use Hublog\Core\Security;
use Hublog\Core\Helper;

class AdminFriendLinkController extends AdminController
{
    // ==================== 友情链接管理 ====================

    public function friendLinks(Request $request): Response
    {
        $this->requireAdmin($request);

        $links = FriendLinkRepository::fetchAdminFriendLinks();
        return Response::success($links);
    }

    public function saveFriendLink(Request $request): Response
    {
        $this->requireAdmin($request);

        $id = $request->getInt('id');
        $name = trim($request->getString('name'));
        $url = trim($request->getString('url'));
        $description = trim($request->getString('description'));
        $sortOrder = $request->getInt('sort_order', 0);
        $status = $request->getInt('status', 1);

        if ($name === '' || $url === '') {
            return Response::error('网站名称和网址不能为空', 400);
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return Response::error('网址格式不正确', 400);
        }
        if (mb_strlen($name) > 50) {
            return Response::error('网站名称最多 50 个字符', 400);
        }

        $data = [
            'name' => Security::purify($name),
            'url' => Security::purify($url),
            'description' => Security::purify($description),
            'sort_order' => $sortOrder,
            'status' => in_array($status, [0, 1], true) ? $status : 1,
            'updated_at' => Helper::now()
        ];

        if ($id) {
            FriendLinkRepository::updateFriendLink($id, $data);
        } else {
            $data['created_at'] = Helper::now();
            FriendLinkRepository::insertFriendLink($data);
        }

        Cache::delete('friend_links');
        return Response::success(null, '保存成功');
    }

    public function deleteFriendLink(Request $request): Response
    {
        $this->requireAdmin($request);
        $id = $request->getInt('id');
        if (!$id) {
            return Response::error('参数错误', 400);
        }
        FriendLinkRepository::deleteFriendLink($id);
        Cache::delete('friend_links');
        return Response::success(null, '删除成功');
    }

    public function publicFriendLinks(Request $request): Response
    {
        $links = Cache::remember('friend_links', function () {
            return FriendLinkRepository::fetchActiveFriendLinks();
        }, 3600);
        return Response::success($links);
    }
}
