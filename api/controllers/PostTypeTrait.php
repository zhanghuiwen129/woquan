<?php

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
use Hublog\Core\SsePush;

trait PostTypeTrait
{
    /**
     * V57：建投票（含选项），并在发布者自己的票投上第一个选项
     *
     * @param array $config ['options'=>string[], 'multi'=>int, 'max_choice'=>int, 'expires_at'=>?string]
     */

    private function createPollForPost(int $postId, int $userId, array $config): void
    {
        // V63：建投票时就记下所属动态，之后查投票不再依赖「谁投过票」
        $pollId = PostRepository::insertPoll(
            $postId,
            mb_substr((string)$config['question'], 0, 255) ?: mb_substr('投票', 0, 255),
            $config['multi'],
            $config['max_choice'],
            $config['expires_at'],
            Helper::now()
        );

        $optionIds = [];
        $sort = 0;
        foreach ($config['options'] as $opt) {
            $optionIds[] = PostRepository::insertPollOption($pollId, mb_substr($opt, 0, 255), $sort++, Helper::now());
        }

        // 发布者默认投第一项，避免「0 票的投票」看起来像没启动
        if ($optionIds && (int)$config['multi'] === 0) {
            PostRepository::insertPollVote($pollId, $optionIds[0], $userId, $postId, Helper::now());
        }
    }

    /**
     * V57：组装投票数据（选项 + 票数 + 当前用户投过哪些）
     *
     * @return array|null 无投票时返回 null
     */

    private function buildPollData(int $postId, int $currentUserId): ?array
    {
        static $cache = [];
        $key = $postId . ':' . $currentUserId;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        // V63：polls 已有 post_id，直接用动态ID定位投票。
        // 旧逻辑是 `SELECT poll_id FROM poll_votes WHERE post_id=? AND user_id=?`，
        // 等于要求「这个人自己投过票才认得这是投票帖」—— 其他人打开这条动态就看不到投票，
        // 多选投票（发布时不写首票）更是谁都看不到。这里保留旧查询作为老数据的兜底。
        $poll = PostRepository::findPollByPostId($postId);
        if (!$poll) {
            $row = PostRepository::findPollIdByVotePost($postId);
            if ($row) {
                $poll = PostRepository::findPollById((int)$row['poll_id']);
            }
        }
        if (!$poll) {
            return ($cache[$key] = null);
        }
        $pollId = (int)$poll['id'];

        $options = PostRepository::fetchPollOptions($pollId);

        $counts = PostRepository::countPollVotesByOption($pollId);
        $countMap = [];
        foreach ($counts as $c) {
            $countMap[(int)$c['option_id']] = (int)$c['cnt'];
        }
        // 匿名投票表里没记录 user_id，无法判定「我投过」，统一按未投处理
        $myVoted = PostRepository::fetchPollMyVotedOptions($pollId, $currentUserId);
        $myOptionIds = array_map(fn($v) => (int)$v['option_id'], $myVoted);

        $total = array_sum($countMap);
        $pollOptions = [];
        foreach ($options as $o) {
            $oid = (int)$o['id'];
            $cnt = $countMap[$oid] ?? 0;
            $pollOptions[] = [
                'id' => $oid,
                'content' => $o['content'],
                'votes' => $cnt,
                'percent' => $total > 0 ? round($cnt / $total * 100, 1) : 0,
                'my_vote' => in_array($oid, $myOptionIds, true)
            ];
        }

        return ($cache[$key] = [
            'id' => (int)$poll['id'],
            'question' => $poll['question'],
            'multi' => (int)$poll['multi'],
            'max_choice' => (int)$poll['max_choice'],
            'total_votes' => $total,
            'expires_at' => $poll['expires_at'],
            'closed' => !empty($poll['expires_at']) && strtotime($poll['expires_at']) < time(),
            'options' => $pollOptions
        ]);
    }

    /**
     * V57：投票 / 撤回投票
     *
     * body: post_id + option_id（多选时用逗号分隔或传多个）
     * 同一 option_id 再次提交 = 撤回该选项的票；全撤回后返回 total_votes=0
     */

    public function pollVote(Request $request): Response
    {
        $user = Auth::check($request);
        $postId = $request->getInt('post_id');
        if ($postId <= 0) {
            return Response::error('参数错误', 400);
        }

        $post = PostRepository::findActivePostId($postId, (int)$user['id']);
        if (!$post) {
            return Response::error('动态不存在', 404);
        }

        $rawOptions = $request->getString('option_id');
        if ($rawOptions === '') {
            $rawOptions = $request->getString('options');
        }
        if ($rawOptions === '') {
            return Response::error('请选择投票选项', 400);
        }
        $optionIds = array_values(array_unique(array_filter(array_map('intval', preg_split('/[^0-9]+/', $rawOptions)))));
        if (!$optionIds) {
            return Response::error('投票选项无效', 400);
        }

        // V63：同 buildPollData —— 优先按 polls.post_id 定位，老数据回退 poll_votes 反查
        $poll = PostRepository::findPollSummaryByPostId($postId);
        if (!$poll) {
            $row = PostRepository::findPollIdByVotePost($postId);
            if ($row) {
                $poll = PostRepository::findPollSummaryById((int)$row['poll_id']);
            }
        }
        if (!$poll) {
            return Response::error('该动态不是投票帖', 404);
        }
        $pollId = (int)$poll['id'];
        if (!empty($poll['expires_at']) && strtotime($poll['expires_at']) < time()) {
            return Response::error('投票已结束', 400);
        }

        $validIds = array_map('intval', array_column(
            PostRepository::fetchPollOptionIds($pollId),
            'id'
        ));
        $optionIds = array_values(array_intersect($optionIds, $validIds));
        if (!$optionIds) {
            return Response::error('投票选项无效', 400);
        }

        // 已投过的选项再提交一次 = 撤回
        $existing = PostRepository::fetchExistingPollVotes($pollId, (int)$user['id'], $optionIds);
        $existingIds = array_map(fn($v) => (int)$v['option_id'], $existing);
        $toRemove = array_values(array_intersect($optionIds, $existingIds));
        $toAdd = array_values(array_diff($optionIds, $existingIds));

        $multi = (int)$poll['multi'] === 1;
        if (!$multi && (count($toAdd) > 1 || count($toRemove) > 1)) {
            return Response::error('该投票为单选', 400);
        }
        $maxChoice = max(1, (int)$poll['max_choice']);
        if ($multi) {
            $currentCount = PostRepository::countUserPollVotes($pollId, (int)$user['id']);
            if (count($toAdd) > 0 && $currentCount - count($toRemove) + count($toAdd) > $maxChoice) {
                return Response::error('最多只能选 ' . $maxChoice . ' 项', 400);
            }
        }

        foreach ($toRemove as $oid) {
            // 值全部绑定，不再把转型后的 option_id 拼进 SQL
            PostRepository::deletePollVote($pollId, (int)$user['id'], $oid);
        }
        foreach ($toAdd as $oid) {
            try {
                PostRepository::insertPollVote($pollId, $oid, (int)$user['id'], $postId, Helper::now());
            } catch (\Throwable $e) {
                // 唯一键冲突（重复投票）直接忽略
            }
        }

        $data = $this->buildPollData($postId, (int)$user['id']);
        return Response::success($data, ($toAdd ? '投票成功' : '已撤回'));
    }

    /* ==================== V59：问答 / 组队 / 打卡 ==================== */

    private function buildQuestionData(int $postId, int $currentUserId): ?array
    {
        $row = PostRepository::findQuestionByPostId($postId);
        if (!$row) {
            return null;
        }
        // V139：多回答 —— 回答存 question_answers（一帖多人各答一条，作者采纳其一）。
        // 旧的单条 answer/answered_by 仅作兼容字段保留（migrate86 已把历史数据灌入新表）。
        $answerRows = PostRepository::fetchQuestionAnswers($postId) ?: [];

        $answers = [];
        $accepted = null;
        $myAnswered = false;
        foreach ($answerRows as $a) {
            $uid = (int)$a['user_id'];
            $item = [
                'id' => (int)$a['id'],
                'content' => $a['content'],
                'is_accepted' => (int)$a['is_accepted'] > 0,
                'is_mine' => $uid === $currentUserId,
                'created_at' => $a['created_at'],
                'accepted_at' => $a['accepted_at'] ?? null,
                'user' => [
                    'id' => $uid,
                    'nickname' => $a['nickname'] ?? ('用户' . $uid),
                    'username' => $a['username'] ?? '',
                    'avatar' => $a['avatar'] ?? '',
                    'vip_level' => (int)($a['vip_level'] ?? 0)
                ]
            ];
            if ($item['is_accepted'] && $accepted === null) {
                $accepted = $item;
            }
            if ($item['is_mine']) {
                $myAnswered = true;
            }
            $answers[] = $item;
        }

        $status = (int)$row['status'];
        // 兼容旧字段：answer 取「已采纳那条」，未采纳时取首条，避免老前端白屏
        $legacyAnswer = $accepted ? $accepted['content'] : ($answers ? $answers[0]['content'] : null);

        return [
            'question' => $row['question'],
            'status' => $status,
            'answer' => $legacyAnswer,
            'answer_count' => count($answers),
            'answers' => $answers,
            'accepted_answer_id' => $accepted ? $accepted['id'] : null,
            'answered_at' => $row['answered_at'] ?? null,
            'is_mine' => $myAnswered,
            // V139：只要问题未解决且自己还没答过，任何人都可以回答
            // （旧逻辑 answered_by === 0 导致全站只有第一个人能答，第二个人直接没入口）
            'can_answer' => $status === 0 && !$myAnswered
        ];
    }

    private function buildTeamData(int $postId, int $currentUserId): ?array
    {
        $row = PostRepository::findTeamByPostId($postId);
        if (!$row) {
            return null;
        }
        $joined = PostRepository::findTeamMember($postId, $currentUserId);
        $membersRaw = PostRepository::fetchTeamMembers($postId) ?: [];
        $need = (int)$row['need_count'];
        $joinedCount = (int)$row['joined_count'];
        return [
            'headline' => $row['headline'],
            'need_count' => $need,
            'joined_count' => $joinedCount,
            'deadline' => $row['deadline'] ?? null,
            'city' => $row['city'],
            'contact' => $row['contact'],
            'joined' => !empty($joined),
            'is_full' => $need > 0 && $joinedCount >= $need,
            'is_owner' => $joinedCount > 0 && (int)($membersRaw[0]['user_id'] ?? 0) === $currentUserId,
            'members' => array_map(function ($m) {
                return [
                    'user_id' => (int)$m['user_id'],
                    'nickname' => $m['nickname'] ?? ('用户' . $m['user_id']),
                    'avatar' => $m['avatar'],
                    'joined_at' => $m['joined_at']
                ];
            }, $membersRaw)
        ];
    }

    private function buildCheckinData(int $postId, int $currentUserId): ?array
    {
        $cfg = PostRepository::findCheckinConfig($postId);
        if (!$cfg) {
            return null;
        }
        $today = date('Y-m-d');
        $total = PostRepository::countMyCheckins($postId, $currentUserId);
        $doneToday = PostRepository::countTodayCheckins($postId, $currentUserId, $today);
        $target = (int)$cfg['target_days'];
        return [
            'goal' => $cfg['goal'],
            'target_days' => $target,
            'my_total' => $total,
            'done_today' => $doneToday > 0,
            'percent' => $target > 0 ? (int)min(100, round($total * 100 / $target)) : 0
        ];
    }

    private function findTypePost(int $postId, string $type, int $viewerId = 0): ?array
    {
        if ($postId <= 0) {
            return null;
        }
        $row = PostRepository::findTypePostRow($postId, $type, $viewerId);
        return $row ?: null;
    }

    /** 问答：回答问题（禁止回答自己的问题） */

    public function questionAnswer(Request $request): Response
    {
        $user = Auth::check($request);
        $postId = $request->getInt('post_id');
        $content = trim((string)$request->getString('content'));
        if ($content === '') {
            return Response::error('请输入你的回答', 400);
        }
        if (mb_strlen($content) > 500) {
            return Response::error('回答不能超过 500 字', 400);
        }
        $post = $this->findTypePost($postId, 'question', (int)$user['id']);
        if (!$post) {
            return Response::error('这不是问答帖', 404);
        }
        // V140：不再禁止「回答自己发布的问题」——作者常需要另起一条回答作补充说明
        // （不想改原问题正文），且 can_answer 本就按「问题未解决 + 自己没答过」判定，
        // 与是否作者无关。此前这条拦截会让作者本人一点「发布回答」就失败。
        // V139：多人可答 —— 写独立回答表，不再 UPDATE 覆盖 questions.answer
        // （旧实现会把上一个人的答案整个覆盖掉，第二个人等于抢答成功）
        $dup = PostRepository::findQuestionAnswerByUser($postId, (int)$user['id']);
        if ($dup) {
            return Response::error('你已经回答过这个问题了', 400);
        }
        // 回答正文与发帖同口径：敏感词过滤后再 purify，禁止原始 content 直接落库
        $fAnswer = Moderation::filter($content, 'post', (int)$user['id'], $postId);
        if (!$fAnswer['ok']) {
            return Response::error($fAnswer['msg'], 403);
        }
        $answerContent = Security::purify($fAnswer['text']);
        PostRepository::insertQuestionAnswer($postId, (int)$user['id'], $answerContent, Helper::now());
        // 兼容旧读路径：仅首次回答时同步 questions 旧字段，之后不再动，
        // 否则 questions.answer 会变成最后一个人的答案，与「采纳」语义冲突
        $q = PostRepository::findQuestionLegacyAnswer($postId);
        if ($q && ($q['answer'] === null || $q['answer'] === '')) {
            PostRepository::updateQuestionLegacyAnswer(
                $postId,
                $answerContent,
                (int)$user['id'],
                Helper::now()
            );
        }
        return Response::success(
            $this->buildQuestionData($postId, (int)$user['id']),
            '回答已发布'
        );
    }

    /** 问答：作者采纳指定回答（仅作者；V139 起必须带 answer_id） */

    public function questionAccept(Request $request): Response
    {
        $user = Auth::check($request);
        $postId = $request->getInt('post_id');
        $answerId = $request->getInt('answer_id');
        $post = $this->findTypePost($postId, 'question', (int)$user['id']);
        if (!$post) {
            return Response::error('这不是问答帖', 404);
        }
        if ((int)$post['user_id'] !== (int)$user['id']) {
            return Response::error('只有发布者可以采纳回答', 403);
        }
        $row = PostRepository::findQuestionStatus($postId);
        if (!$row) {
            return Response::error('问答不存在', 404);
        }
        // V139：必须明确指定采纳哪一条。旧实现不带 answer_id、只把 status 置 1，
        //       等于「谁最后回答谁就算被采纳」，这正是用户反馈的「采用哪条不对」。
        if ($answerId <= 0) {
            return Response::error('请选择要采纳的回答', 400);
        }
        $answer = PostRepository::findQuestionAnswer($answerId, $postId);
        if (!$answer) {
            return Response::error('回答不存在', 404);
        }

        PostRepository::acceptQuestionAnswer(
            $postId,
            $answerId,
            $answer['content'],
            (int)$answer['user_id'],
            Helper::now(),
            Helper::now()
        );
        return Response::success(
            $this->buildQuestionData($postId, (int)$user['id']),
            '已采纳该回答'
        );
    }

    /** 组队：报名加入 */

    public function teamJoin(Request $request): Response
    {
        $user = Auth::check($request);
        $postId = $request->getInt('post_id');
        $post = $this->findTypePost($postId, 'team', (int)$user['id']);
        if (!$post) {
            return Response::error('这不是组队帖', 404);
        }
        $team = PostRepository::findTeamForJoin($postId);
        if (!$team) {
            return Response::error('组队不存在', 404);
        }
        $need = (int)$team['need_count'];
        $joinedCount = (int)$team['joined_count'];
        if ($need > 0 && $joinedCount >= $need) {
            return Response::error('本组已满员了', 400);
        }
        $exists = PostRepository::findTeamMember($postId, (int)$user['id']);
        if ($exists) {
            return Response::success($this->buildTeamData($postId, (int)$user['id']), '你已经在这支队伍里了');
        }
        $teamId = (int)$team['id'];
        PostRepository::joinTeam($teamId, $postId, (int)$user['id'], $joinedCount, Helper::now());
        return Response::success(
            $this->buildTeamData($postId, (int)$user['id']),
            '报名成功，快去联系发起人吧'
        );
    }

    /** 组队：退出（发起人是唯一成员时不允许退，避免队伍变空） */

    public function teamLeave(Request $request): Response
    {
        $user = Auth::check($request);
        $postId = $request->getInt('post_id');
        $post = $this->findTypePost($postId, 'team', (int)$user['id']);
        if (!$post) {
            return Response::error('这不是组队帖', 404);
        }
        $row = PostRepository::findTeamMember($postId, (int)$user['id']);
        if (!$row) {
            return Response::error('你还不在队伍里', 400);
        }
        $count = PostRepository::countTeamMembersForUser($postId, (int)$user['id']);
        $team = PostRepository::findTeamForLeave($postId);
        PostRepository::leaveTeam(
            (int)$row['id'],
            $team ? (int)$team['id'] : 0,
            $team ? (int)$team['joined_count'] : 0
        );
        return Response::success(
            $this->buildTeamData($postId, (int)$user['id']),
            '已退出队伍'
        );
    }

    /** 打卡：今日打卡（同一天只能打一次，由 checkin_logs 唯一键兜底） */

    public function checkinDo(Request $request): Response
    {
        $user = Auth::check($request);
        $postId = $request->getInt('post_id');
        if ($this->findTypePost($postId, 'checkin', (int)$user['id']) === null) {
            return Response::error('这不是打卡帖', 404);
        }
        // 打卡正文同样走敏感词过滤 + purify，杜绝原始内容绕过审核落库
        $rawContent = mb_substr(trim((string)$request->getString('content')), 0, 100);
        $filtered = Moderation::filter($rawContent, 'post', (int)$user['id'], $postId);
        if (empty($filtered['ok'])) {
            return Response::error($filtered['msg'] ?? '内容含有敏感词', 403);
        }
        $content = Security::purify($filtered['text']) ?: null;
        $today = date('Y-m-d');
        try {
            PostRepository::insertCheckinLog($postId, (int)$user['id'], $content, $today, Helper::now());
        } catch (\Throwable $e) {
            return Response::error('今天已经打过卡啦', 400);
        }
        return Response::success(
            $this->buildCheckinData($postId, (int)$user['id']),
            '打卡成功，坚持住！'
        );
    }
}
