/**
 * script.js — 数学练习应用
 *
 * 通过 fetch API（AJAX）驱动整个单页应用，页面不会刷新。
 * 正确答案只存储在服务端 PHP Session 中，客户端代码中
 * 永远不会出现正确答案。
 *
 * 流程：
 *   首页（选难度+页数）→ 开始练习 → 答题（第1页）
 *     → 提交 → 有错：显示❌，保留答案，可修改重提
 *            → 全对：自动进入下一页
 *   → （所有页全对后）→ 成功页
 */

'use strict';

/* ══════════════════════════════════════════════════════════════════
   模块级状态
   ══════════════════════════════════════════════════════════════════ */

// 服务端返回的所有页题目（不含答案），格式：
//   [ { questions: [{id, display}] }, ... ]
let pages = [];

// 总页数和当前页索引（0-based）
let totalPages = 0;
let currentPageIndex = 0;

// 记录每页是否已全部答对（全对后锁定输入框）
let pageCompleted = [];

// 记录每页提交后的逐题结果，格式：
//   [ [{id, correct}], ... ]  — 仅含布尔值，不含正确答案
let pageResults = [];


/* ══════════════════════════════════════════════════════════════════
   屏幕切换
   ══════════════════════════════════════════════════════════════════ */

/**
 * showScreen — 激活指定屏幕，隐藏其余屏幕。
 * @param {string} id — 要显示的 screen 元素 id
 */
function showScreen(id) {
    document.querySelectorAll('.screen').forEach(el => {
        el.classList.toggle('active', el.id === id);
    });
}


/* ══════════════════════════════════════════════════════════════════
   加载遮罩
   ══════════════════════════════════════════════════════════════════ */

function showLoading() {
    document.getElementById('loading-overlay').removeAttribute('hidden');
}

function hideLoading() {
    document.getElementById('loading-overlay').setAttribute('hidden', '');
}


/* ══════════════════════════════════════════════════════════════════
   首页：难度等级选择
   ══════════════════════════════════════════════════════════════════ */

/**
 * initLevelButtons — 绑定难度等级按钮的点击事件。
 * 点击某个等级按钮时，将其标记为选中（active）并更新隐藏输入的值。
 */
function initLevelButtons() {
    const buttons = document.querySelectorAll('.btn-level');
    const hiddenInput = document.getElementById('selected-level');

    buttons.forEach(btn => {
        btn.addEventListener('click', () => {
            // 移除其他按钮的选中样式
            buttons.forEach(b => b.classList.remove('active'));
            // 选中当前按钮
            btn.classList.add('active');
            // 更新隐藏输入，供 startQuiz() 读取
            hiddenInput.value = btn.dataset.level;
        });
    });
}


/* ══════════════════════════════════════════════════════════════════
   首页：验证并开始练习
   ══════════════════════════════════════════════════════════════════ */

async function startQuiz() {
    // ── 读取并验证练习页数 ──────────────────────────────────────
    const pagesInput = document.getElementById('pages-input');
    const pagesVal   = pagesInput.value.trim();

    hideMessage('msg-pages-empty');
    hideMessage('msg-pages-zero');

    if (pagesVal === '' || isNaN(pagesVal)) {
        showMessage('msg-pages-empty');
        pagesInput.focus();
        return;
    }

    const totalPagesReq = parseInt(pagesVal, 10);

    if (totalPagesReq <= 0) {
        showMessage('msg-pages-zero');
        pagesInput.focus();
        return;
    }

    // ── 读取难度等级 ────────────────────────────────────────────
    const level = parseInt(document.getElementById('selected-level').value, 10);

    // ── 向服务端请求生成所有页的题目 ───────────────────────────
    showLoading();

    try {
        const response = await fetch('api.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            // 注意：这里只发送难度和页数，服务端生成题目并在 Session 中保存答案
            body: JSON.stringify({ action: 'generate', level, totalPages: totalPagesReq }),
        });

        if (!response.ok) throw new Error('服务器错误：' + response.status);

        const data = await response.json();
        if (data.error) throw new Error(data.error);

        // ── 初始化模块状态 ──────────────────────────────────────
        pages            = data.pages;
        totalPages       = data.totalPages;
        currentPageIndex = 0;
        pageCompleted    = new Array(totalPages).fill(false);
        pageResults      = new Array(totalPages).fill(null);

        // ── 渲染第 1 页并切换到答题屏幕 ────────────────────────
        gotoPage(0);
        showScreen('screen-quiz');

    } catch (err) {
        alert('无法加载题目，请刷新页面后重试。\n\n' + err.message);
    } finally {
        hideLoading();
    }
}


/* ══════════════════════════════════════════════════════════════════
   页面导航
   ══════════════════════════════════════════════════════════════════ */

/**
 * gotoPage — 跳转到指定页，渲染题目并更新导航状态。
 * @param {number} pageIndex — 目标页索引（0-based）
 */
function gotoPage(pageIndex) {
    currentPageIndex = pageIndex;
    const page = pages[pageIndex];

    // 渲染题目卡片
    renderQuestions(page.questions);

    // 如果该页已经全对，恢复锁定状态（使用之前保存的结果）
    if (pageCompleted[pageIndex] && pageResults[pageIndex]) {
        applyResults(pageResults[pageIndex], /* lockAll= */ true);
    }

    updatePageIndicator();
    updateNavButtons();
    hideMessages();

    // 滚动到页面顶部
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

/**
 * updatePageIndicator — 更新"第 X 页 / 共 Y 页"文字。
 */
function updatePageIndicator() {
    const el = document.getElementById('page-indicator');
    if (el) el.textContent = `第 ${currentPageIndex + 1} 页 / 共 ${totalPages} 页`;
}

/**
 * updateNavButtons — 根据当前页和完成状态显示/隐藏导航按钮。
 * 规则：
 *   - 上一页：非第一页时显示
 *   - 提交答案：当前页未完成时显示
 *   - 下一页：当前页已完成且不是最后一页时显示
 */
function updateNavButtons() {
    const btnPrev   = document.getElementById('btn-prev');
    const btnSubmit = document.getElementById('btn-submit');
    const btnNext   = document.getElementById('btn-next');

    const isFirst     = currentPageIndex === 0;
    const isLast      = currentPageIndex === totalPages - 1;
    const isDone      = pageCompleted[currentPageIndex];

    // 上一页：非第一页可见
    toggleHidden(btnPrev,   isFirst);

    // 提交答案：当前页未完成时可见
    toggleHidden(btnSubmit, isDone);

    // 下一页：当前页已完成且不是最后页时可见
    toggleHidden(btnNext,   !(isDone && !isLast));
}

/**
 * toggleHidden — 设置元素的 hidden 属性。
 * @param {HTMLElement} el
 * @param {boolean} hide — true 则隐藏，false 则显示
 */
function toggleHidden(el, hide) {
    if (!el) return;
    if (hide) el.setAttribute('hidden', '');
    else      el.removeAttribute('hidden');
}


/* ══════════════════════════════════════════════════════════════════
   渲染题目卡片
   ══════════════════════════════════════════════════════════════════ */

/**
 * renderQuestions — 动态创建 12 张题目卡片并注入容器。
 * @param {Array} questions — [{id, display}] 数组（来自服务端，不含答案）
 */
function renderQuestions(questions) {
    const container = document.getElementById('questions-container');
    container.innerHTML = ''; // 清除上一页的卡片

    questions.forEach((q, index) => {
        // ── 卡片容器 ─────────────────────────────────────────
        const card = document.createElement('div');
        card.className  = 'question-card';
        card.dataset.id = q.id;

        // ── 题号徽章（从 1 开始，更直观）────────────────────
        const badge = document.createElement('span');
        badge.className   = 'q-number';
        badge.textContent = index + 1;

        // ── 题目文本，例如 "3 + 4 =" ─────────────────────────
        // q.display 来自服务端，例如 "3 + 4" 或 "9 - 3 - 2"
        const text = document.createElement('span');
        text.className   = 'q-text';
        text.textContent = q.display + ' =';

        // ── 答案输入框 ────────────────────────────────────────
        const input = document.createElement('input');
        input.type         = 'number';
        input.className    = 'q-input';
        input.id           = `input-${q.id}`;
        input.min          = '0';
        input.max          = '10';
        input.inputMode    = 'numeric';
        input.pattern      = '[0-9]*';
        input.autocomplete = 'off';
        input.setAttribute('aria-label', `第 ${index + 1} 题答案：${q.display}`);

        // 用户修改错题时，清除该卡片的错误高亮
        input.addEventListener('input', () => clearCardState(q.id));

        // ── 状态图标（✅ / ❌），提交后由 applyResults 填充 ─
        const statusIcon = document.createElement('span');
        statusIcon.className = 'q-status';
        statusIcon.id        = `status-${q.id}`;

        card.append(badge, text, input, statusIcon);
        container.appendChild(card);
    });

    // 将焦点移至第一个输入框，方便键盘输入
    const firstInput = document.getElementById('input-0');
    if (firstInput) firstInput.focus();
}


/* ══════════════════════════════════════════════════════════════════
   提交答案
   ══════════════════════════════════════════════════════════════════ */

async function submitAnswers() {
    // ── 步骤 1：收集当前页所有输入框的值 ────────────────────
    const answers  = {};
    let   hasBlank = false;

    pages[currentPageIndex].questions.forEach(q => {
        const input = document.getElementById(`input-${q.id}`);
        const val   = input ? input.value.trim() : '';

        if (val === '') {
            hasBlank = true;
        } else {
            // 发送给服务端；api.php 会将其转为 int 再比较
            answers[q.id] = val;
        }
    });

    // ── 步骤 2：客户端完整性检查（任一为空则提示） ──────────
    if (hasBlank) {
        showMessage('msg-incomplete');
        hideMessage('msg-wrong');
        document.getElementById('msg-incomplete')
            .scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    hideMessages();

    // ── 步骤 3：发送至服务端进行权威性核对 ──────────────────
    // 只发送页码和答案；正确答案由服务端从 Session 中取出对比，
    // 绝不返回给客户端。
    showLoading();

    try {
        const response = await fetch('api.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                action:    'check',
                pageIndex: currentPageIndex,
                answers,
            }),
        });

        if (!response.ok) throw new Error('服务器错误：' + response.status);

        const data = await response.json();
        if (data.error) throw new Error(data.error);

        // ── 步骤 4：应用结果（✅/❌ 图标、边框颜色）──────────
        applyResults(data.results);

        if (data.allCorrect) {
            // 标记该页已完成并保存结果（用于翻页回来时恢复显示）
            pageCompleted[currentPageIndex] = true;
            pageResults[currentPageIndex]   = data.results;

            updateNavButtons();

            if (currentPageIndex === totalPages - 1) {
                // 最后一页全对 → 显示成功页
                setTimeout(() => showScreen('screen-success'), 600);
            }
            // 若非最后一页，updateNavButtons 会显示"下一页"按钮，
            // 让孩子自己点击继续，不自动跳转。
        } else {
            // 有错误 → 显示提示，让孩子修改
            showMessage('msg-wrong');
            // 滚动到第一个错误卡片
            const firstWrong = document.querySelector('.question-card.state-wrong');
            if (firstWrong) {
                firstWrong.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

    } catch (err) {
        alert('无法提交答案，请重试。\n\n' + err.message);
    } finally {
        hideLoading();
    }
}


/* ══════════════════════════════════════════════════════════════════
   应用批改结果
   根据服务端返回的 results（只含 correct 布尔值，无正确答案）
   更新每张卡片的视觉状态。
   ══════════════════════════════════════════════════════════════════ */

/**
 * applyResults — 为每张卡片添加正确/错误样式和 ✅/❌ 图标。
 * @param {Array}   results  — [{id, correct}] 来自服务端
 * @param {boolean} lockAll  — 翻回已完成页时为 true，强制锁定所有输入框
 */
function applyResults(results, lockAll = false) {
    results.forEach(r => {
        const card   = document.querySelector(`.question-card[data-id="${r.id}"]`);
        const input  = document.getElementById(`input-${r.id}`);
        const status = document.getElementById(`status-${r.id}`);

        if (!card || !input) return;

        // 清除旧状态
        card.classList.remove('state-correct', 'state-wrong', 'state-reset');

        if (r.correct || lockAll) {
            // 正确：绿色边框，锁定输入，显示 ✅
            card.classList.add('state-correct');
            input.setAttribute('disabled', '');
            if (status) status.textContent = '✅';
        } else {
            // 错误：红色边框，保持可编辑，显示 ❌
            // 注意：不显示正确答案（需求第4条）
            card.classList.add('state-wrong');
            input.removeAttribute('disabled');
            if (status) status.textContent = '❌';
            // 选中输入框内容，方便孩子直接覆盖输入
            input.select();
        }
    });
}


/* ══════════════════════════════════════════════════════════════════
   清除单张卡片的批改状态
   孩子修改错题时调用，将卡片恢复为中性状态。
   ══════════════════════════════════════════════════════════════════ */

/**
 * clearCardState — 重置指定卡片的视觉状态为中性。
 * @param {number} id — 题目 id
 */
function clearCardState(id) {
    const card   = document.querySelector(`.question-card[data-id="${id}"]`);
    const status = document.getElementById(`status-${id}`);

    if (card) {
        card.classList.remove('state-correct', 'state-wrong');
        card.classList.add('state-reset');
    }
    if (status) status.textContent = '';

    // 隐藏错误提示横幅
    hideMessage('msg-wrong');
    hideMessage('msg-incomplete');
}


/* ══════════════════════════════════════════════════════════════════
   重置 / 再来一次
   ══════════════════════════════════════════════════════════════════ */

function resetQuiz() {
    pages            = [];
    totalPages       = 0;
    currentPageIndex = 0;
    pageCompleted    = [];
    pageResults      = [];

    document.getElementById('questions-container').innerHTML = '';
    hideMessages();
    showScreen('screen-home');
}


/* ══════════════════════════════════════════════════════════════════
   消息显示辅助函数
   ══════════════════════════════════════════════════════════════════ */

function showMessage(id) {
    const el = document.getElementById(id);
    if (el) el.removeAttribute('hidden');
}

function hideMessage(id) {
    const el = document.getElementById(id);
    if (el) el.setAttribute('hidden', '');
}

function hideMessages() {
    hideMessage('msg-incomplete');
    hideMessage('msg-wrong');
}


/* ══════════════════════════════════════════════════════════════════
   初始化
   DOM 加载完成后绑定所有按钮事件。
   使用 addEventListener 而非 onclick 属性，符合 CSP 安全策略。
   ══════════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {
    // 首页按钮
    document.getElementById('btn-start')
        .addEventListener('click', startQuiz);

    // 难度等级切换
    initLevelButtons();

    // 答题页按钮
    document.getElementById('btn-submit')
        .addEventListener('click', submitAnswers);

    document.getElementById('btn-prev')
        .addEventListener('click', () => {
            if (currentPageIndex > 0) gotoPage(currentPageIndex - 1);
        });

    document.getElementById('btn-next')
        .addEventListener('click', () => {
            if (currentPageIndex < totalPages - 1) gotoPage(currentPageIndex + 1);
        });

    // 成功页按钮
    document.getElementById('btn-tryagain')
        .addEventListener('click', resetQuiz);
});
