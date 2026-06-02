<?php
/**
 * index.php — 数学练习 主页面
 *
 * 提供完整的单页面应用外壳。题目生成、提交和批改均通过
 * AJAX 调用 api.php 完成，页面本身不会刷新。
 */

// 启动 Session（api.php 需要用 Session 存储正确答案）
session_start();

// 安全响应头
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>数学练习 — 10以内加减法</title>
    <link rel="stylesheet" href="style.css" />
</head>
<body>

    <!-- ===================================================
         首页（screen-home）
         展示标题、难度等级选择、练习页数输入和开始按钮。
    =================================================== -->
    <div id="screen-home" class="screen active">
        <div class="card home-card">

            <div class="emoji-row" aria-hidden="true">🌟 ➕ ➖ 🌟</div>
            <h1 class="home-title">数学练习</h1>
            <p class="home-subtitle">10以内加减法</p>

            <!-- 难度等级选择 -->
            <div class="form-group">
                <label class="form-label">难度等级</label>
                <div class="level-buttons" role="group" aria-label="难度等级">
                    <button class="btn btn-level active" data-level="1">
                        一级
                        <span class="level-desc">两个数</span>
                    </button>
                    <button class="btn btn-level" data-level="2">
                        二级
                        <span class="level-desc">三个数<br/>相同符号</span>
                    </button>
                    <button class="btn btn-level" data-level="3">
                        三级
                        <span class="level-desc">三个数<br/>混合符号</span>
                    </button>
                </div>
                <!-- 用隐藏输入记录当前选中的等级，便于 JS 读取 -->
                <input type="hidden" id="selected-level" value="1" />
            </div>

            <!-- 练习页数输入 -->
            <div class="form-group">
                <label class="form-label" for="pages-input">练习页数</label>
                <input
                    type="number"
                    id="pages-input"
                    class="pages-input"
                    min="1"
                    value="1"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    aria-describedby="msg-pages-empty msg-pages-zero"
                />
            </div>

            <!-- 输入验证提示 -->
            <p id="msg-pages-empty" class="message message-warn" hidden>
                ⚠️ 请输入练习页数
            </p>
            <p id="msg-pages-zero" class="message message-warn" hidden>
                ⚠️ 练习页数必须大于 0
            </p>

            <button id="btn-start" class="btn btn-start">▶ 开始练习</button>
        </div>
    </div>


    <!-- ===================================================
         答题页（screen-quiz）
         显示当前页的 12 道题目、翻页导航和提交按钮。
         题目卡片由 script.js 动态生成并注入 #questions-container。
    =================================================== -->
    <div id="screen-quiz" class="screen">

        <div class="quiz-header">
            <!-- 当前页码指示器，由 JS 动态更新 -->
            <div id="page-indicator" class="page-indicator">第 1 页 / 共 1 页</div>
            <h2 class="quiz-title">🧮 请回答以下题目！</h2>
        </div>

        <!-- 题目卡片注入点 -->
        <div id="questions-container" class="questions-grid"></div>

        <!-- 验证提示：有题目未填写 -->
        <p id="msg-incomplete" class="message message-warn" hidden>
            ⚠️ 请填写所有答案后再提交！
        </p>

        <!-- 提示：有题目答错 -->
        <p id="msg-wrong" class="message message-error" hidden>
            还有一些题目答错了，请修改后再提交。
        </p>

        <!-- 导航栏：上一页 / 提交答案 / 下一页 -->
        <div class="nav-row">
            <button id="btn-prev" class="btn btn-nav" hidden>← 上一页</button>
            <button id="btn-submit" class="btn btn-submit">✅ 提交答案</button>
            <button id="btn-next" class="btn btn-next" hidden>下一页 →</button>
        </div>

    </div>


    <!-- ===================================================
         成功页（screen-success）
         所有页全部答对后展示。
    =================================================== -->
    <div id="screen-success" class="screen">
        <div class="card success-card">
            <div class="star-burst" aria-hidden="true">🎉 🌟 🎉</div>
            <h1 class="success-title">太棒了！</h1>
            <p class="success-msg">恭喜你全部答对了！</p>
            <div class="star-burst" aria-hidden="true">🏆 ⭐ 🏆</div>
            <button id="btn-tryagain" class="btn btn-tryagain">🔄 再来一次</button>
        </div>
    </div>


    <!-- 加载遮罩：AJAX 请求进行中时显示旋转图标 -->
    <div id="loading-overlay" hidden>
        <div class="spinner"></div>
    </div>

    <script src="script.js"></script>
</body>
</html>
