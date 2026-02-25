<?php
declare (strict_types = 1);

namespace app\controller;

use think\facade\Db;
use think\facade\View;

class Faq extends BaseFrontendController
{
    // FAQ页面
    public function index()
    {
        // 获取当前登录用户信息
        $userId = session('user_id') ?: cookie('user_id');

        if (!$userId) {
            return redirect('/login');
        }

        $currentUser = [
            'id' => $userId,
            'username' => session('username', '') ?: cookie('username', ''),
            'nickname' => session('nickname', '') ?: cookie('nickname', ''),
            'avatar' => session('avatar', '') ?: cookie('avatar', '')
        ];

        // 配置信息已在基类中加载
        View::assign([
            'currentUser' => $currentUser,
            'isLogin' => !empty($userId)
        ]);
        return View::fetch('index/faq');
    }
    /**
     * 获取FAQ分类列表
     */
    public function getFaqCategories()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取FAQ分类列表
            $categories = Db::name('faq_category')
                ->field('id, name, description, sort, status')
                ->where('status', 1)
                ->order('sort', 'asc')
                ->order('create_time', 'desc')
                ->select();

            // 如果没有分类配置，使用默认分类
            if (empty($categories)) {
                $categories = [
                    [
                        'id' => 1,
                        'name' => '账号相关',
                        'description' => '账号注册、登录、找回密码等问题',
                        'sort' => 1,
                        'status' => 1
                    ],
                    [
                        'id' => 2,
                        'name' => '功能使用',
                        'description' => '如何使用各种功能',
                        'sort' => 2,
                        'status' => 1
                    ],
                    [
                        'id' => 3,
                        'name' => '安全设置',
                        'description' => '账号安全、隐私设置等问题',
                        'sort' => 3,
                        'status' => 1
                    ],
                    [
                        'id' => 4,
                        'name' => '积分等级',
                        'description' => '积分获取、等级提升等问题',
                        'sort' => 4,
                        'status' => 1
                    ],
                    [
                        'id' => 5,
                        'name' => '其他问题',
                        'description' => '其他常见问题',
                        'sort' => 5,
                        'status' => 1
                    ]
                ];
            }

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => ['list' => $categories]
            ]);
        } catch (\Exception $e) {
            return $this->error('获取FAQ分类列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取FAQ列表
     */
    public function getFaqList()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取请求参数
            $page = input('page', 1, 'intval');
            $limit = input('limit', 10, 'intval');
            $categoryId = input('category_id', 0, 'intval');
            $offset = ($page - 1) * $limit;

            // 构建查询条件
            $where = ['status' => 1];
            if ($categoryId) {
                $where['category_id'] = $categoryId;
            }

            // 获取FAQ总数
            $total = Db::name('faq')
                ->where($where)
                ->count();

            // 获取FAQ列表
            $faqs = Db::name('faq')
                ->where($where)
                ->field('id, category_id, question, answer, sort, status, create_time')
                ->order('sort', 'asc')
                ->order('create_time', 'desc')
                ->limit($offset, $limit)
                ->select();

            // 如果没有FAQ配置，使用默认FAQ
            if (empty($faqs)) {
                // 根据分类ID返回默认FAQ
                if ($categoryId == 1) {
                    // 账号相关
                    $faqs = [
                        [
                            'id' => 1,
                            'category_id' => 1,
                            'question' => '如何注册账号？',
                            'answer' => '您可以通过手机号注册账号，需要填写手机号、验证码和密码，然后点击注册按钮即可。',
                            'sort' => 1,
                            'status' => 1,
                            'create_time' => time()
                        ],
                        [
                            'id' => 2,
                            'category_id' => 1,
                            'question' => '忘记密码怎么办？',
                            'answer' => '您可以点击登录页面的"忘记密码"链接，通过手机号验证码重置密码。',
                            'sort' => 2,
                            'status' => 1,
                            'create_time' => time()
                        ]
                    ];
                } elseif ($categoryId == 2) {
                    // 功能使用
                    $faqs = [
                        [
                            'id' => 3,
                            'category_id' => 2,
                            'question' => '如何发布动态？',
                            'answer' => '登录后，点击首页的"发布"按钮，填写内容和选择图片，然后点击发布即可。',
                            'sort' => 1,
                            'status' => 1,
                            'create_time' => time()
                        ],
                        [
                            'id' => 4,
                            'category_id' => 2,
                            'question' => '如何关注用户？',
                            'answer' => '在用户主页或推荐列表中，点击"关注"按钮即可关注该用户。',
                            'sort' => 2,
                            'status' => 1,
                            'create_time' => time()
                        ]
                    ];
                } else {
                    // 默认返回一些常见FAQ
                    $faqs = [
                        [
                            'id' => 1,
                            'category_id' => 1,
                            'question' => '如何注册账号？',
                            'answer' => '您可以通过手机号注册账号，需要填写手机号、验证码和密码，然后点击注册按钮即可。',
                            'sort' => 1,
                            'status' => 1,
                            'create_time' => time()
                        ],
                        [
                            'id' => 3,
                            'category_id' => 2,
                            'question' => '如何发布动态？',
                            'answer' => '登录后，点击首页的"发布"按钮，填写内容和选择图片，然后点击发布即可。',
                            'sort' => 1,
                            'status' => 1,
                            'create_time' => time()
                        ]
                    ];
                }
                $total = count($faqs);
            }

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => [
                    'list' => $faqs,
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'has_more' => ($offset + $limit) < $total
                ]
            ]);
        } catch (\Exception $e) {
            return $this->error('获取FAQ列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 搜索FAQ
     */
    public function searchFaq()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取请求参数
            $keyword = input('keyword', '', 'trim');
            $page = input('page', 1, 'intval');
            $limit = input('limit', 10, 'intval');
            $offset = ($page - 1) * $limit;

            if (empty($keyword)) {
                return json([
                    'code' => 400,
                    'msg' => '搜索关键词不能为空'
                ]);
            }

            // 构建查询条件
            $where = [
                'status' => 1,
                'question|answer' => ['like', '%' . $keyword . '%']
            ];

            // 获取搜索结果总数
            $total = Db::name('faq')
                ->where($where)
                ->count();

            // 获取搜索结果列表
            $faqs = Db::name('faq')
                ->where($where)
                ->field('id, category_id, question, answer, sort, status, create_time')
                ->order('sort', 'asc')
                ->order('create_time', 'desc')
                ->limit($offset, $limit)
                ->select();

            // 如果没有搜索结果，返回默认提示
            if (empty($faqs)) {
                return json([
                    'code' => 200,
                    'msg' => 'success',
                    'data' => [
                        'list' => [],
                        'total' => 0,
                        'page' => $page,
                        'limit' => $limit,
                        'has_more' => false
                    ]
                ]);
            }

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => [
                    'list' => $faqs,
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'has_more' => ($offset + $limit) < $total
                ]
            ]);
        } catch (\Exception $e) {
            return $this->error('搜索FAQ失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取FAQ详情
     */
    public function getFaqDetail()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取请求参数
            $id = input('id', 0, 'intval');

            if (!$id) {
                return json([
                    'code' => 400,
                    'msg' => 'FAQ ID不能为空'
                ]);
            }

            // 获取FAQ详情
            $faq = Db::name('faq')
                ->where('id', $id)
                ->where('status', 1)
                ->find();

            if (!$faq) {
                // 如果FAQ不存在，返回默认FAQ
                return json([
                    'code' => 404,
                    'msg' => 'FAQ不存在'
                ]);
            }

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => $faq
            ]);
        } catch (\Exception $e) {
            return $this->error('获取FAQ详情失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取热门FAQ
     */
    public function getHotFaqs()
    {
        try {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Content-Type: application/json; charset=utf-8');

            // 获取热门FAQ列表
            $hotFaqs = Db::name('faq')
                ->where('status', 1)
                ->where('is_hot', 1)
                ->field('id, category_id, question, answer, sort, status, create_time')
                ->order('sort', 'asc')
                ->order('create_time', 'desc')
                ->limit(10)
                ->select();

            // 如果没有热门FAQ配置，使用默认热门FAQ
            if (empty($hotFaqs)) {
                $hotFaqs = [
                    [
                        'id' => 1,
                        'category_id' => 1,
                        'question' => '如何注册账号？',
                        'answer' => '您可以通过手机号注册账号，需要填写手机号、验证码和密码，然后点击注册按钮即可。',
                        'sort' => 1,
                        'status' => 1,
                        'is_hot' => 1,
                        'create_time' => time()
                    ],
                    [
                        'id' => 2,
                        'category_id' => 1,
                        'question' => '忘记密码怎么办？',
                        'answer' => '您可以点击登录页面的"忘记密码"链接，通过手机号验证码重置密码。',
                        'sort' => 2,
                        'status' => 1,
                        'is_hot' => 1,
                        'create_time' => time()
                    ],
                    [
                        'id' => 3,
                        'category_id' => 2,
                        'question' => '如何发布动态？',
                        'answer' => '登录后，点击首页的"发布"按钮，填写内容和选择图片，然后点击发布即可。',
                        'sort' => 3,
                        'status' => 1,
                        'is_hot' => 1,
                        'create_time' => time()
                    ],
                    [
                        'id' => 4,
                        'category_id' => 2,
                        'question' => '如何关注用户？',
                        'answer' => '在用户主页或推荐列表中，点击"关注"按钮即可关注该用户。',
                        'sort' => 4,
                        'status' => 1,
                        'is_hot' => 1,
                        'create_time' => time()
                    ]
                ];
            }

            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => ['list' => $hotFaqs]
            ]);
        } catch (\Exception $e) {
            return $this->error('获取热门FAQ失败: ' . $e->getMessage());
        }
    }
}
