<?php
session_start();

// 处理同意协议
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agree'])) {
    $_SESSION['install']['agreed'] = true;
    completeStep(0);
    header('Location: /install?step=1');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>免责声明 - 我圈社交平台安装</title>
    <style>
        body {
            font-family: 'Microsoft YaHei', sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 0;
            line-height: 1.6;
        }
        .container {
            max-width: 800px;
            margin: 50px auto;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 30px;
        }
        h1 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
            border-bottom: 2px solid #4080FF;
            padding-bottom: 15px;
        }
        .disclaimer-content {
            height: 300px;
            overflow-y: auto;
            border: 1px solid #eee;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
            background-color: #f9f9f9;
            font-size: 14px;
        }
        .disclaimer-content h2 {
            color: #4080FF;
            font-size: 18px;
            margin-top: 20px;
            margin-bottom: 10px;
        }
        .disclaimer-content p {
            margin-bottom: 15px;
        }
        .disclaimer-content ul {
            margin-bottom: 15px;
            padding-left: 20px;
        }
        .disclaimer-content li {
            margin-bottom: 5px;
        }
        .agree-checkbox {
            margin-bottom: 20px;
        }
        .agree-checkbox label {
            display: flex;
            align-items: center;
            font-weight: bold;
            color: #333;
        }
        .agree-checkbox input[type="checkbox"] {
            margin-right: 10px;
            width: 18px;
            height: 18px;
        }
        .btn-group {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            margin: 0 10px;
        }
        .btn-primary {
            background-color: #4080FF;
            color: #fff;
        }
        .btn-primary:hover:not(:disabled) {
            background-color: #2962FF;
        }
        .btn-primary:disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }
        .btn-secondary {
            background-color: #f5f5f5;
            color: #333;
            border: 1px solid #ddd;
        }
        .btn-secondary:hover {
            background-color: #e8e8e8;
        }
        .warning {
            color: #ff4d4f;
            text-align: center;
            margin-top: 10px;
            font-weight: bold;
        }
    </style>
    <script>
        function toggleAgreeButton() {
            const agreeCheckbox = document.getElementById('agree');
            const nextButton = document.getElementById('nextButton');
            nextButton.disabled = !agreeCheckbox.checked;
        }
    </script>
</head>
<body>
    <div class="container">
        <h1>我圈社交平台安装 - 免责声明</h1>
        
        <div class="disclaimer-content">
            <h2>重要提示</h2>
            <p>在开始安装我圈社交平台之前，请您仔细阅读以下免责声明。安装、使用本软件即表示您已充分理解并同意遵守本声明中的所有条款和条件。如果您不同意本声明的任何条款，请立即停止安装和使用本软件。</p>

            <h2>一、软件使用许可</h2>
            <p><strong>1.1 使用授权</strong>：本软件为开源软件，遵循相关开源许可协议。用户在遵守本协议的前提下，可获得非独占的、不可转让的使用许可，可在个人或商业项目中使用、修改和分发本软件。</p>
            <p><strong>1.2 使用限制</strong>：用户不得将本软件用于任何违反法律法规、侵犯他人权益、危害网络安全或社会公共利益的活动。禁止利用本软件进行以下行为：</p>
            <ul>
                <li>发布、传播违法违规信息或有害内容</li>
                <li>侵犯他人的知识产权、肖像权、隐私权等合法权益</li>
                <li>攻击、破坏网络安全或干扰他人正常使用</li>
                <li>窃取、泄露、篡改用户数据或系统信息</li>
                <li>任何形式的商业欺诈、诈骗或非法牟利行为</li>
            </ul>

            <h2>二、免责条款</h2>
            <p><strong>2.1 软件按"现状"提供</strong>：本软件按"现状"（AS IS）和"可用"（AS AVAILABLE）的基础提供，不对其功能、性能、适用性、无病毒或无错误性作任何明示或暗示的保证。</p>
            <p><strong>2.2 损失免责</strong>：在法律允许的最大范围内，软件提供商不对因使用或无法使用本软件而导致的任何直接、间接、附带、特殊、惩罚性或后果性损失承担责任，包括但不限于：</p>
            <ul>
                <li>数据丢失、损坏或泄露</li>
                <li>业务中断或商业损失</li>
                <li>系统崩溃或硬件损坏</li>
                <li>第三方索赔或法律责任</li>
                <li>利润损失或收入减少</li>
            </ul>
            <p><strong>2.3 使用风险自负</strong>：用户需自行承担使用本软件的全部风险，包括但不限于数据安全、系统稳定性和法律合规性等风险。</p>

            <h2>三、数据安全与隐私保护</h2>
            <p><strong>3.1 数据责任</strong>：用户对自己在上传、发布、存储的所有数据内容承担全部责任。软件提供商不对用户内容的合法性、准确性和安全性负责。</p>
            <p><strong>3.2 账户安全</strong>：在安装过程中创建的管理员账户和密码，以及后续使用的所有用户账户，均由用户自行管理和保管。因账户保管不善导致的任何损失，软件提供商不承担责任。</p>
            <p><strong>3.3 备份建议</strong>：强烈建议用户定期备份重要数据，包括但不限于数据库、上传文件和系统配置。软件提供商不承担因未及时备份而导致的数据丢失责任。</p>
            <p><strong>3.4 安全更新</strong>：用户应及时关注并安装软件的安全更新补丁，以确保系统的安全性。因未及时更新导致的安全问题，软件提供商不承担责任。</p>

            <h2>四、知识产权</h2>
            <p><strong>4.1 著作权归属</strong>：本软件的源代码、文档、界面设计及相关知识产权归原作者或许可方所有。用户在获得授权后使用，但不得侵犯原作者的知识产权。</p>
            <p><strong>4.2 开源协议</strong>：本软件遵循[具体开源协议名称]开源许可协议，用户在使用、修改和分发本软件时，必须遵守该协议的各项规定。</p>
            <p><strong>4.3 用户内容</strong>：用户上传、发布的内容，其知识产权归用户所有。用户需确保其上传的内容不侵犯他人的知识产权，否则需自行承担全部法律责任。</p>

            <h2>五、技术支持与服务</h2>
            <p><strong>5.1 支持范围</strong>：软件提供商提供社区性质的技术支持，支持范围限于软件本身的安装、配置和使用问题。</p>
            <p><strong>5.2 支持方式</strong>：技术支持主要通过官方论坛、社区问答、文档说明等方式提供，不承诺提供一对一的专属技术服务。</p>
            <p><strong>5.3 服务器环境</strong>：软件提供商不提供服务器环境配置、域名注册、SSL证书申请、云服务器购买等第三方服务。用户需自行配置和维护服务器环境。</p>
            <p><strong>5.4 商业支持</strong>：如需定制开发、专业技术支持、系统运维等商业服务，可通过官方渠道联系获取，相关服务可能产生费用。</p>

            <h2>六、软件更新与维护</h2>
            <p><strong>6.1 版本更新</strong>：软件提供商将根据需要不定期发布功能更新、安全修复和性能优化版本，用户可自行选择是否更新。</p>
            <p><strong>6.2 更新通知</strong>：软件不会自动推送更新通知，用户需定期访问官方渠道查看更新信息。</p>
            <p><strong>6.3 版本支持</strong>：旧版本软件可能不再获得技术支持和安全更新，建议用户及时更新到最新稳定版以获得最佳体验和安全保障。</p>
            <p><strong>6.4 更新风险</strong>：版本更新可能带来功能变化和兼容性问题，建议在更新前做好数据备份和测试。</p>

            <h2>七、服务变更与终止</h2>
            <p><strong>7.1 服务变更</strong>：软件提供商保留在无需事先通知的情况下，对软件功能、服务内容、使用条款进行调整或变更的权利。</p>
            <p><strong>7.2 服务终止</strong>：软件提供商有权在发现用户违反本协议或存在严重安全风险时，终止向该用户提供任何形式的技术支持。</p>

            <h2>八、争议解决与法律适用</h2>
            <p><strong>8.1 协议效力</strong>：本协议构成用户与软件提供商之间的完整协议，取代之前的所有口头或书面协议。</p>
            <p><strong>8.2 争议解决</strong>：因本协议引起的任何争议，双方应友好协商解决。协商不成的，任何一方可向软件提供商所在地人民法院提起诉讼。</p>
            <p><strong>8.3 法律适用</strong>：本协议的订立、执行、解释及争议解决均适用中华人民共和国法律。</p>

            <h2>九、其他条款</h2>
            <p><strong>9.1 协议修改</strong>：软件提供商有权随时修改本协议，修改后的协议将在官方渠道公布，用户继续使用本软件即视为同意修改后的协议。</p>
            <p><strong>9.2 可分割性</strong>：如果本协议的任何条款被认定为无效或不可执行，该条款将被视为可分割的，不影响其他条款的效力。</p>
            <p><strong>9.3 完整协议</strong>：本协议（包括软件使用说明、更新日志等相关文档）构成双方就软件使用事宜达成的完整协议。</p>

            <p style="color: #ff4d4f; font-weight: bold; font-size: 15px; padding: 15px; background: #fff1f0; border-left: 4px solid #ff4d4f; margin-top: 20px;">
                重要提醒：在点击"同意并继续"之前，请务必确保您已充分阅读、理解并同意以上所有条款。安装和使用本软件即表示您自愿承担所有相关风险和责任。
            </p>
        </div>
        
        <form method="POST" action="/install/install.php?step=0">
            <div class="agree-checkbox">
                <label>
                    <input type="checkbox" id="agree" name="agree" value="1" onchange="toggleAgreeButton()">
                    我已仔细阅读并同意以上免责声明
                </label>
            </div>
            
            <div class="btn-group">
                <a href="../" class="btn btn-secondary">取消安装</a>
                <button type="submit" id="nextButton" class="btn btn-primary" disabled>同意并继续</button>
            </div>
        </form>
        
        <div id="warningMessage" class="warning" style="display: none;">
            请先同意免责声明才能继续安装
        </div>
    </div>
</body>
</html>
