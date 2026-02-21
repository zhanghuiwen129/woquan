<?php /*a:1:{s:34:"D:\wwwroot\view\index\profile.html";i:1770880600;}*/ ?>
﻿<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>个人主页 - <?php echo htmlentities((string) $name); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/fontawesome/css/font-awesome.min.css">
    <link rel="stylesheet" href="/static/css/profile.css">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: '#165DFF',
                        secondary: '#4080FF',
                        lightBlue: '#E8F3FF',
                        danger: '#F53F3F',
                        'dark-bg': '#18191C',
                        'dark-card': '#25262B',
                        'dark-text': '#F5F6F7',
                        'dark-border': '#323338',
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-100 min-h-screen pb-20 md:pb-0">
<div class="wb-profile-container">
    <!-- 封面区域 -->
    <div class="wb-cover">
        <div class="wb-cover-actions">
            <button class="wb-cover-btn" onclick="uploadCover()">
                <i class="fa fa-camera"></i> 更换封面
            </button>
        </div>
        <input type="file" id="coverInput" accept="image/*" style="display: none;" onchange="handleCoverUpload(this)">
    </div>

    <!-- 用户信息卡片 -->
    <div class="wb-user-card">
        <div class="wb-avatar-section">
            <div class="wb-avatar-wrapper">
                <img src="/user/avatar" class="wb-avatar" id="userAvatar" onerror="this.src='/static/images/default-avatar.png'" alt="头像">
            </div>
            <div class="wb-user-info">
                <h1 class="wb-user-name" id="userName"><?php echo htmlentities((string) (isset($currentUser['nickname']) && ($currentUser['nickname'] !== '')?$currentUser['nickname']:"加载中...")); ?></h1>
                <div class="wb-user-details">
                    <p class="wb-user-id" id="userId">账号: <?php echo htmlentities((string) $currentUser['username']); ?></p>
                    <div class="wb-stats">
                        <div class="wb-stat-item" onclick="location.href='/following'">
                            <span class="wb-stat-number" id="followingCount">-</span>
                            <span class="wb-stat-label">关注</span>
                        </div>
                        <div class="wb-stat-item" onclick="location.href='/followers'">
                            <span class="wb-stat-number" id="followersCount">-</span>
                            <span class="wb-stat-label">粉丝</span>
                        </div>
                        <div class="wb-stat-item" onclick="location.href='/favorites'">
                            <span class="wb-stat-number" id="favoritesCount">-</span>
                            <span class="wb-stat-label">收藏</span>
                        </div>
                        <div class="wb-stat-item">
                            <span class="wb-stat-number" id="momentsCount">-</span>
                            <span class="wb-stat-label">动态</span>
                        </div>
                    </div>
                </div>
                <p class="wb-user-bio" id="userSignature"><?php echo htmlentities((string) (isset($currentUser['bio']) && ($currentUser['bio'] !== '')?$currentUser['bio']:"这个人很懒，什么都没留下")); ?></p>
                <div class="wb-user-tags" id="userTags">
                    <!-- 用户标签将由JS动态加载 -->
                </div>
            </div>
        </div>

        <div class="wb-actions">
            <button class="wb-btn wb-btn-primary" onclick="location.href='/settings'">
                <i class="fa fa-edit"></i> 编辑资料
            </button>
            <button class="wb-btn wb-btn-secondary" onclick="location.href='/message'">
                <i class="fa fa-envelope"></i> 私信
            </button>
        </div>
    </div>

    <!-- 标签页 -->
    <div class="wb-tabs">
        <div class="wb-tab active" data-tab="content">我的内容</div>
        <div class="wb-tab" data-tab="social">社交互动</div>
        <div class="wb-tab" data-tab="services">特权服务</div>
        <div class="wb-tab" data-tab="account">账号管理</div>
    </div>

    <!-- 菜单内容 -->
    <div class="wb-tab-content" id="tab-content">
        <!-- 内容网格 -->
        <div class="wb-menu-grid">
            <div class="wb-menu-item" onclick="showUserMoments()">
                <div class="wb-menu-icon blue">
                    <i class="fa fa-file-text"></i>
                </div>
                <div class="wb-menu-label">我的动态</div>
            </div>
            <div class="wb-menu-item" onclick="location.href='/article'">
                <div class="wb-menu-icon purple">
                    <i class="fa fa-book"></i>
                </div>
                <div class="wb-menu-label">我的文章</div>
            </div>
            <div class="wb-menu-item" onclick="location.href='/favorites'">
                <div class="wb-menu-icon orange">
                    <i class="fa fa-star"></i>
                </div>
                <div class="wb-menu-label">我的收藏</div>
            </div>
            <div class="wb-menu-item" onclick="location.href='/article/drafts'">
                <div class="wb-menu-icon blue">
                    <i class="fa fa-file-text-o"></i>
                </div>
                <div class="wb-menu-label">草稿箱</div>
            </div>
            <div class="wb-menu-item" onclick="location.href='/visitors'">
                <div class="wb-menu-icon purple">
                    <i class="fa fa-eye"></i>
                </div>
                <div class="wb-menu-label">访客记录</div>
            </div>
        </div>
    </div>

    <div class="wb-tab-content hidden" id="tab-social">
        <div class="wb-menu-grid">
            <div class="wb-menu-item" onclick="location.href='/following'">
                <div class="wb-menu-icon blue">
                    <i class="fa fa-user-plus"></i>
                </div>
                <div class="wb-menu-label">我的关注</div>
            </div>
            <div class="wb-menu-item" onclick="location.href='/followers'">
                <div class="wb-menu-icon green">
                    <i class="fa fa-users"></i>
                </div>
                <div class="wb-menu-label">我的粉丝</div>
            </div>
            <div class="wb-menu-item" onclick="location.href='/message'">
                <div class="wb-menu-icon orange">
                    <i class="fa fa-envelope"></i>
                </div>
                <div class="wb-menu-label">
                    消息通知
                    <span class="wb-menu-badge hidden" id="messageBadge">0</span>
                </div>
            </div>
            <div class="wb-menu-item" onclick="location.href='/mentions'">
                <div class="wb-menu-icon purple">
                    <i class="fa fa-at"></i>
                </div>
                <div class="wb-menu-label">@我的</div>
            </div>
        </div>
    </div>

    <div class="wb-tab-content hidden" id="tab-services">
        <div class="wb-menu-list">
            <div class="wb-menu-list-item" onclick="location.href='/wallet'">
                <div class="wb-menu-list-icon gold">
                    <i class="fa fa-wallet"></i>
                </div>
                <div class="wb-menu-list-text">我的钱包</div>
                <i class="fa fa-chevron-right wb-menu-list-arrow"></i>
            </div>
            <div class="wb-menu-list-item" onclick="location.href='/points'">
                <div class="wb-menu-list-icon blue">
                    <i class="fa fa-gift"></i>
                </div>
                <div class="wb-menu-list-text">积分中心</div>
                <i class="fa fa-chevron-right wb-menu-list-arrow"></i>
            </div>
            <div class="wb-menu-list-item" onclick="location.href='/levels'">
                <div class="wb-menu-list-icon orange">
                    <i class="fa fa-trophy"></i>
                </div>
                <div class="wb-menu-list-text">等级中心</div>
                <i class="fa fa-chevron-right wb-menu-list-arrow"></i>
            </div>
            <div class="wb-menu-list-item" onclick="location.href='/themes'">
                <div class="wb-menu-list-icon purple">
                    <i class="fa fa-paint-brush"></i>
                </div>
                <div class="wb-menu-list-text">主题中心</div>
                <i class="fa fa-chevron-right wb-menu-list-arrow"></i>
            </div>
            <div class="wb-menu-list-item" onclick="location.href='/faq'">
                <div class="wb-menu-list-icon green">
                    <i class="fa fa-question-circle"></i>
                </div>
                <div class="wb-menu-list-text">帮助中心</div>
                <i class="fa fa-chevron-right wb-menu-list-arrow"></i>
            </div>
        </div>
    </div>

    <div class="wb-tab-content hidden" id="tab-account">
        <div class="wb-menu-list">
            <div class="wb-menu-list-item" onclick="location.href='/settings'">
                <div class="wb-menu-list-icon blue">
                    <i class="fa fa-cog"></i>
                </div>
                <div class="wb-menu-list-text">账号设置</div>
                <i class="fa fa-chevron-right wb-menu-list-arrow"></i>
            </div>
            <div class="wb-menu-list-item" onclick="location.href='/security'">
                <div class="wb-menu-list-icon green">
                    <i class="fa fa-shield"></i>
                </div>
                <div class="wb-menu-list-text">安全中心</div>
                <i class="fa fa-chevron-right wb-menu-list-arrow"></i>
            </div>
            <div class="wb-menu-list-item" onclick="location.href='/login-logs'">
                <div class="wb-menu-list-icon purple">
                    <i class="fa fa-history"></i>
                </div>
                <div class="wb-menu-list-text">登录记录</div>
                <i class="fa fa-chevron-right wb-menu-list-arrow"></i>
            </div>
            <div class="wb-menu-list-item" onclick="location.href='/search-history'">
                <div class="wb-menu-list-icon orange">
                    <i class="fa fa-search"></i>
                </div>
                <div class="wb-menu-list-text">搜索历史</div>
                <i class="fa fa-chevron-right wb-menu-list-arrow"></i>
            </div>
            <div class="wb-menu-list-item" onclick="logout()" style="color: #eb7350;">
                <div class="wb-menu-list-icon red">
                    <i class="fa fa-sign-out"></i>
                </div>
                <div class="wb-menu-list-text" style="color: #eb7350;">退出登录</div>
                <i class="fa fa-chevron-right wb-menu-list-arrow"></i>
            </div>
        </div>
    </div>
</div>

<script src="/static/js/profile.js"></script>
</body>
</html>
