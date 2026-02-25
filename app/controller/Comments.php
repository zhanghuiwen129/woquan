<?php
declare (strict_types = 1);

namespace app\controller;

use think\facade\Session;
use app\service\CommentService;

class Comments extends BaseFrontendController
{
    public function list()
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Content-Type: application/json; charset=utf-8');

        $params = [
            'moment_id' => input('moment_id'),
            'page' => intval(input('page', 1)),
            'limit' => intval(input('limit', 10))
        ];

        $currentUserId = session('user_id') ?: cookie('user_id');

        $result = CommentService::getCommentList($params, $currentUserId);

        return json($result);
    }

    public function add()
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Content-Type: application/json; charset=utf-8');

        $currentUserId = session('user_id') ?: cookie('user_id');

        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        $result = CommentService::addComment($data, $currentUserId);

        return json($result);
    }

    public function like()
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Content-Type: application/json; charset=utf-8');

        $currentUserId = session('user_id') ?: cookie('user_id');

        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        $commentId = $data['comment_id'] ?? 0;
        $action = $data['action'] ?? '';

        $result = CommentService::toggleLike($commentId, $action, $currentUserId);

        return json($result);
    }

    public function delete()
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Content-Type: application/json; charset=utf-8');

        $currentUserId = session('user_id') ?: cookie('user_id');

        $commentId = intval(input('comment_id'));

        $result = CommentService::deleteComment($commentId, $currentUserId);

        return json($result);
    }

    public function replies()
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Content-Type: application/json; charset=utf-8');

        $currentUserId = session('user_id') ?: cookie('user_id');

        $commentId = input('comment_id');
        $params = [
            'offset' => intval(input('offset', 0)),
            'limit' => intval(input('limit', 10))
        ];

        $result = CommentService::getReplies($commentId, $params, $currentUserId);

        return json($result);
    }

    public function setTop()
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Content-Type: application/json; charset=utf-8');

        $currentUserId = session('user_id') ?: cookie('user_id');

        $commentId = intval(input('comment_id'));
        $isTop = intval(input('is_top', 0));

        $result = CommentService::setTopComment($commentId, $isTop, $currentUserId);

        return json($result);
    }

    public function setHot()
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Content-Type: application/json; charset=utf-8');

        $currentUserId = session('user_id') ?: cookie('user_id');

        $commentId = intval(input('comment_id'));
        $isHot = intval(input('is_hot', 0));

        $result = CommentService::setHotComment($commentId, $isHot, $currentUserId);

        return json($result);
    }
}
