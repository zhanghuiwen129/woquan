<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use think\facade\View;
use think\facade\Request;
use think\facade\Db;
use think\facade\Session;

class Chat extends AdminController
{
    
    public function index()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $status = Request::param('status/d', -1);
        
        $where = [];
        if ($keyword) {
            $escapedKeyword = Db::escape($keyword);
            $where[] = ['c.content', 'like', "%{$escapedKeyword}%"];
        }
        if ($status >= 0) {
            $where[] = ['c.status', '=', $status];
        }
        
        $chats = Db::name('chats')
            ->alias('c')
            ->leftJoin('user u1', 'c.from_user_id = u1.id')
            ->leftJoin('user u2', 'c.to_user_id = u2.id')
            ->field('c.*, u1.username as from_username, u1.nickname as from_nickname, u1.avatar as from_avatar, u2.username as to_username, u2.nickname as to_nickname, u2.avatar as to_avatar')
            ->where($where)
            ->order('c.create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'chats' => $chats,
            'keyword' => $keyword,
            'status' => $status,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);

        return View::fetch('admin/chat/index');
    }
    
    public function messages()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $chatId = Request::param('chat_id/d', 0);
        
        $where = [];
        if ($keyword) {
            $where[] = ['m.content', 'like', "%{$keyword}%"];
        }
        if ($chatId > 0) {
            $where[] = ['m.chat_id', '=', $chatId];
        }
        
        $messages = Db::name('messages')
            ->alias('m')
            ->leftJoin('user u', 'm.user_id = u.id')
            ->field('m.*, u.username, u.nickname, u.avatar')
            ->where($where)
            ->order('m.create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'messages' => $messages,
            'keyword' => $keyword,
            'chatId' => $chatId,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);

        return View::fetch('admin/chat/messages');
    }
    
    public function sensitive()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $keyword = Request::param('keyword', '');
        $status = Request::param('status/d', -1);
        
        $where = [];
        if ($keyword) {
            $where[] = ['content', 'like', "%{$keyword}%"];
        }
        if ($status >= 0) {
            $where[] = ['status', '=', $status];
        }
        
        $sensitiveMessages = Db::name('sensitive_messages')
            ->where($where)
            ->order('create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page
            ]);
        
        View::assign([
            'sensitiveMessages' => $sensitiveMessages,
            'keyword' => $keyword,
            'status' => $status,
            'admin_name' => Session::get('admin_name', '管理员')
        ]);

        return View::fetch('admin/chat/sensitive');
    }
    
    public function deleteChat()
    {
        $id = Request::param('id/d');
        
        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        try {
            Db::startTrans();
            
            Db::name('messages')->where('chat_id', $id)->delete();
            Db::name('chats')->where('id', $id)->delete();
            
            Db::commit();
            return json(['code' => 200, 'msg' => '删除成功']);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 500, 'msg' => '删除失败：' . $e->getMessage()]);
        }
    }
    
    public function deleteMessage()
    {
        $id = Request::param('id/d');
        
        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        try {
            $result = Db::name('messages')->where('id', $id)->delete();
            
            if ($result) {
                return json(['code' => 200, 'msg' => '删除成功']);
            }
            
            return json(['code' => 500, 'msg' => '删除失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '删除失败：' . $e->getMessage()]);
        }
    }
    
    public function ignoreSensitive()
    {
        $id = Request::param('id/d');
        
        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        try {
            $result = Db::name('sensitive_messages')->where('id', $id)->update(['status' => 1]);
            
            if ($result !== false) {
                return json(['code' => 200, 'msg' => '操作成功']);
            }
            
            return json(['code' => 500, 'msg' => '操作失败']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '操作失败：' . $e->getMessage()]);
        }
    }
}
