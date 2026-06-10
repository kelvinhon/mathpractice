<?php
/**
 * index.php — 數學練習 主頁面（繁體中文版）
 *
 * 單頁面應用外殼。題目生成、答案提交和批改均通過
 * AJAX 呼叫 api.php 完成，頁面不會重新整理。
 */

session_start();

header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
?>
<!DOCTYPE html>
<html lang="zh-Hant-HK">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>數學練習 — 10以內加減法</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__ . '/style.css') ?: time(); ?>" />
</head>
<body>

    <!-- ===================================================
         首頁（screen-home）
         標題、難度等級選擇、練習頁數輸入、開始按鈕。
    =================================================== -->
    <div id="screen-home" class="screen active">
        <div class="card home-card">

            <div class="emoji-row" aria-hidden="true">🌟 ➕ ➖ 🌟</div>
            <h1 class="home-title">數學練習</h1>
            <p class="home-subtitle">10以內加減法</p>

            <!-- 難度等級選擇 -->
            <div class="form-group">
                <label class="form-label">難度等級</label>
                <div class="level-buttons" role="group" aria-label="難度等級">
                    <button class="btn btn-level active" data-level="1">
                        一級
                        <span class="level-desc">兩個數</span>
                    </button>
                    <button class="btn btn-level" data-level="2">
                        二級
                        <span class="level-desc">三個數<br/>相同符號</span>
                    </button>
                    <button class="btn btn-level" data-level="3">
                        三級
                        <span class="level-desc">三個數<br/>混合符號</span>
                    </button>
                </div>
                <input type="hidden" id="selected-level" value="1" />
            </div>

            <!-- 練習頁數輸入 -->
            <div class="form-group">
                <label class="form-label" for="pages-input">練習頁數</label>
                <input
                    type="number"
                    id="pages-input"
                    class="pages-input"
                    min="1"
                    value="1"
                    inputmode="numeric"
                    pattern="[0-9]*"
                />
            </div>

            <p id="msg-pages-empty" class="message message-warn" hidden>⚠️ 請輸入練習頁數</p>
            <p id="msg-pages-zero"  class="message message-warn" hidden>⚠️ 練習頁數必須大於 0</p>

            <!-- 兩個練習按鈕：數學練習（計算題） / 應用題練習 -->
            <div class="home-buttons">
                <button id="btn-start"    class="btn btn-start">🧮 數學練習</button>
                <button id="btn-start-wp" class="btn btn-start">📖 應用題練習</button>
            </div>
        </div>
    </div>


    <!-- ===================================================
         計算題頁（screen-quiz）
         每頁 12 道計算題；含首頁按鈕、頁碼、翻頁導航。
    =================================================== -->
    <div id="screen-quiz" class="screen">

        <div class="quiz-header">
            <button id="btn-home-quiz" class="btn btn-home" title="回首頁">🏠 首頁</button>
            <div id="page-indicator" class="page-indicator">第 1 頁 / 共 1 頁</div>
            <div class="header-spacer"></div>
        </div>

        <h2 class="quiz-title">🧮 請回答以下題目！</h2>

        <div id="questions-container" class="questions-grid"></div>

        <p id="msg-incomplete" class="message message-warn" hidden>
            ⚠️ 請填寫所有答案後再提交！
        </p>
        <p id="msg-wrong" class="message message-error" hidden>
            還有一些題目答錯了，請修改後再提交。
        </p>

        <div class="nav-row">
            <button id="btn-prev"   class="btn btn-nav"    hidden>← 上一頁</button>
            <button id="btn-submit" class="btn btn-submit"        >✅ 提交答案</button>
            <button id="btn-next"   class="btn btn-next"   hidden >下一頁 →</button>
        </div>

    </div>


    <!-- ===================================================
         應用題頁（screen-word-problems）
         頁數 > 1 時出現，含 2 道應用題。
         每道題需填寫完整算式（數字框）及最終答案。
    =================================================== -->
    <div id="screen-word-problems" class="screen">

        <div class="quiz-header">
            <button id="btn-home-wp" class="btn btn-home" title="回首頁">🏠 首頁</button>
            <div class="page-indicator wp-indicator">📖 應用題</div>
            <div class="header-spacer"></div>
        </div>

        <h2 class="quiz-title">📝 請閱讀題目並填寫算式和答案！</h2>

        <!-- 2 道應用題卡片由 script.js 動態生成 -->
        <div id="word-problems-container" class="wp-container"></div>

        <p id="wp-msg-incomplete" class="message message-warn" hidden>
            ⚠️ 請填寫所有數字框和答案後再提交！
        </p>
        <p id="wp-msg-wrong" class="message message-error" hidden>
            還有一些題目答錯了，請修改後再提交。
        </p>

        <div class="nav-row">
            <button id="wp-btn-prev"   class="btn btn-nav"   >← 上一頁</button>
            <button id="wp-btn-submit" class="btn btn-submit">✅ 提交答案</button>
        </div>

    </div>


    <!-- ===================================================
         應用題練習頁（screen-wp-quiz）
         由首頁「應用題練習」按鈕進入。每頁 4 道應用題，支援多頁。
         題目卡片由 script.js 動態生成並注入 #wp-quiz-container。
    =================================================== -->
    <div id="screen-wp-quiz" class="screen">

        <div class="quiz-header">
            <button id="wp-quiz-btn-home" class="btn btn-home" title="回首頁">🏠 首頁</button>
            <div id="wp-quiz-page-indicator" class="page-indicator wp-indicator">第 1 頁 / 共 1 頁</div>
            <div class="header-spacer"></div>
        </div>

        <h2 class="quiz-title">📝 請閱讀題目並填寫算式和答案！</h2>

        <!-- 每頁 4 道應用題卡片由 script.js 動態注入 -->
        <div id="wp-quiz-container" class="wp-container"></div>

        <p id="wp-quiz-msg-incomplete" class="message message-warn" hidden>
            ⚠️ 請填寫所有數字框和答案後再提交！
        </p>
        <p id="wp-quiz-msg-wrong" class="message message-error" hidden>
            還有一些題目答錯了，請修改後再提交。
        </p>

        <div class="nav-row">
            <button id="wp-quiz-btn-prev"   class="btn btn-nav"    hidden>← 上一頁</button>
            <button id="wp-quiz-btn-submit" class="btn btn-submit"        >✅ 提交答案</button>
            <button id="wp-quiz-btn-next"   class="btn btn-next"   hidden >下一頁 →</button>
        </div>

    </div>


    <!-- ===================================================
         成功頁（screen-success）
    =================================================== -->
    <div id="screen-success" class="screen">
        <div class="card success-card">
            <div class="star-burst" aria-hidden="true">🎉 🌟 🎉</div>
            <h1 class="success-title">太棒了！</h1>
            <p class="success-msg">恭喜你全部答對了！</p>
            <div class="star-burst" aria-hidden="true">🏆 ⭐ 🏆</div>
            <div class="success-buttons">
                <button id="btn-tryagain"     class="btn btn-tryagain">🔄 再來一次</button>
                <button id="btn-home-success" class="btn btn-home btn-home-lg">🏠 回首頁</button>
            </div>
        </div>
    </div>


    <!-- ===================================================
         確認對話框（confirm-modal）
         點擊「首頁」按鈕時彈出，防止誤觸導致進度丟失。
    =================================================== -->
    <div id="confirm-modal" class="modal-overlay" hidden>
        <div class="modal-box">
            <p class="modal-text">🏠 確定要回首頁嗎？<br/>你的答題進度將會丟失。</p>
            <div class="modal-buttons">
                <button id="modal-cancel"  class="btn btn-nav">取消</button>
                <button id="modal-confirm" class="btn btn-start">確定</button>
            </div>
        </div>
    </div>


    <!-- 載入遮罩：AJAX 請求進行中時顯示旋轉圖示 -->
    <div id="loading-overlay" hidden>
        <div class="spinner"></div>
    </div>

    <script src="script.js?v=<?php echo @filemtime(__DIR__ . '/script.js') ?: time(); ?>"></script>
</body>
</html>
