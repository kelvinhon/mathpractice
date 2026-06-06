/**
 * script.js — 數學練習應用（繁體中文版）
 *
 * 通過 fetch API（AJAX）驅動整個單頁應用，頁面不會重新整理。
 * 正確答案和算式數字只存於服務端 PHP Session，客戶端不含任何答案。
 *
 * 功能：
 *   · 計算題多頁練習（含難度等級）
 *   · 應用題（含算式輸入 □ op □ = □ 和最終答案）
 *   · 粵語語音朗讀（Web Speech API，可選配 Google Cloud TTS）
 *   · 首頁按鈕（含確認對話框）
 */

'use strict';

/* ══════════════════════════════════════════════════════════════════
   模組狀態
   ══════════════════════════════════════════════════════════════════ */

let pages            = [];      // [{questions:[{id,display}]}]
let totalPages       = 0;
let currentPageIndex = 0;
let pageCompleted    = [];      // boolean[]
let pageResults      = [];      // [{id,correct}][] 每頁批改結果

let wordProblems          = [];     // [{id,emoji,text,formulaOps}]  計算題流程的應用題
let wordProblemsCompleted = false;

// 應用題練習模式（screen-wp-quiz）專用狀態
let wpQuizPages         = [];   // [{problems:[{id,emoji,text,formulaOps}]}]
let wpQuizTotalPages    = 0;
let wpQuizCurrentPage   = 0;
let wpQuizPageCompleted = [];   // boolean[]
let wpQuizPageResults   = [];   // [{id,correct}][]


/* ══════════════════════════════════════════════════════════════════
   屏幕切換
   ══════════════════════════════════════════════════════════════════ */

function showScreen(id) {
    document.querySelectorAll('.screen').forEach(el => {
        el.classList.toggle('active', el.id === id);
    });
}


/* ══════════════════════════════════════════════════════════════════
   載入遮罩
   ══════════════════════════════════════════════════════════════════ */

function showLoading() { document.getElementById('loading-overlay').removeAttribute('hidden'); }
function hideLoading() { document.getElementById('loading-overlay').setAttribute('hidden', ''); }


/* ══════════════════════════════════════════════════════════════════
   確認對話框（點擊「首頁」按鈕時彈出）
   ══════════════════════════════════════════════════════════════════ */

function showConfirm(onConfirm) {
    const modal = document.getElementById('confirm-modal');
    modal.removeAttribute('hidden');
    document.getElementById('modal-confirm').onclick = () => {
        modal.setAttribute('hidden', '');
        onConfirm();
    };
    document.getElementById('modal-cancel').onclick = () => {
        modal.setAttribute('hidden', '');
    };
}

function goHome() {
    showConfirm(() => resetQuiz());
}


/* ══════════════════════════════════════════════════════════════════
   首頁：難度等級按鈕組
   ══════════════════════════════════════════════════════════════════ */

function initLevelButtons() {
    const buttons     = document.querySelectorAll('.btn-level');
    const hiddenInput = document.getElementById('selected-level');
    buttons.forEach(btn => {
        btn.addEventListener('click', () => {
            buttons.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            hiddenInput.value = btn.dataset.level;
        });
    });
}


/* ══════════════════════════════════════════════════════════════════
   開始練習
   ══════════════════════════════════════════════════════════════════ */

async function startQuiz() {
    const pagesInput  = document.getElementById('pages-input');
    const pagesVal    = pagesInput.value.trim();

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

    const level = parseInt(document.getElementById('selected-level').value, 10);

    showLoading();

    try {
        const response = await fetch('api.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'generate', level, totalPages: totalPagesReq }),
        });

        if (!response.ok) throw new Error('伺服器錯誤：' + response.status);

        const data = await response.json();
        if (data.error) throw new Error(data.error);

        pages                 = data.pages;
        totalPages            = data.totalPages;
        currentPageIndex      = 0;
        pageCompleted         = new Array(totalPages).fill(false);
        pageResults           = new Array(totalPages).fill(null);
        wordProblems          = data.wordProblems || [];
        wordProblemsCompleted = false;

        gotoPage(0);
        showScreen('screen-quiz');

    } catch (err) {
        alert('無法載入題目，請重新整理頁面後再試。\n\n' + err.message);
    } finally {
        hideLoading();
    }
}


/* ══════════════════════════════════════════════════════════════════
   計算題頁導航
   ══════════════════════════════════════════════════════════════════ */

function gotoPage(pageIndex) {
    currentPageIndex = pageIndex;
    renderQuestions(pages[pageIndex].questions);

    if (pageCompleted[pageIndex] && pageResults[pageIndex]) {
        applyResults(pageResults[pageIndex], true);
    }

    updatePageIndicator();
    updateNavButtons();
    hideQuizMessages();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function updatePageIndicator() {
    const el = document.getElementById('page-indicator');
    if (el) el.textContent = `第 ${currentPageIndex + 1} 頁 / 共 ${totalPages} 頁`;
}

function updateNavButtons() {
    const btnPrev   = document.getElementById('btn-prev');
    const btnSubmit = document.getElementById('btn-submit');
    const btnNext   = document.getElementById('btn-next');

    const isFirst = currentPageIndex === 0;
    const isLast  = currentPageIndex === totalPages - 1;
    const isDone  = pageCompleted[currentPageIndex];
    const hasWp   = wordProblems.length > 0;

    toggleHidden(btnPrev,   isFirst);
    toggleHidden(btnSubmit, isDone);
    // 最後一頁完成後：有應用題則顯示「下一頁」進入應用題，否則不顯示
    const showNext = isDone && (!isLast || (isLast && hasWp));
    toggleHidden(btnNext, !showNext);
}

function toggleHidden(el, hide) {
    if (!el) return;
    hide ? el.setAttribute('hidden', '') : el.removeAttribute('hidden');
}


/* ══════════════════════════════════════════════════════════════════
   渲染計算題卡片
   ══════════════════════════════════════════════════════════════════ */

function renderQuestions(questions) {
    const container = document.getElementById('questions-container');
    container.innerHTML = '';

    questions.forEach((q, index) => {
        const card = document.createElement('div');
        card.className  = 'question-card';
        card.dataset.id = q.id;

        const badge = document.createElement('span');
        badge.className   = 'q-number';
        badge.textContent = index + 1;

        const text = document.createElement('span');
        text.className   = 'q-text';
        text.textContent = q.display + ' =';

        const input = document.createElement('input');
        input.type         = 'number';
        input.className    = 'q-input';
        input.id           = `input-${q.id}`;
        input.min          = '0';
        input.max          = '10';
        input.inputMode    = 'numeric';
        input.pattern      = '[0-9]*';
        input.autocomplete = 'off';
        input.setAttribute('aria-label', `第 ${index + 1} 題答案：${q.display}`);
        input.addEventListener('input', () => clearCardState(q.id));

        const statusIcon = document.createElement('span');
        statusIcon.className = 'q-status';
        statusIcon.id        = `status-${q.id}`;

        card.append(badge, text, input, statusIcon);
        container.appendChild(card);
    });

    const firstInput = document.getElementById('input-0');
    if (firstInput) firstInput.focus();
}


/* ══════════════════════════════════════════════════════════════════
   提交計算題答案
   ══════════════════════════════════════════════════════════════════ */

async function submitAnswers() {
    const answers  = {};
    let   hasBlank = false;

    pages[currentPageIndex].questions.forEach(q => {
        const input = document.getElementById(`input-${q.id}`);
        const val   = input ? input.value.trim() : '';
        if (val === '') hasBlank = true;
        else answers[q.id] = val;
    });

    if (hasBlank) {
        showMessage('msg-incomplete');
        hideMessage('msg-wrong');
        document.getElementById('msg-incomplete')
            .scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    hideQuizMessages();

    showLoading();

    try {
        const response = await fetch('api.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'check', pageIndex: currentPageIndex, answers }),
        });

        if (!response.ok) throw new Error('伺服器錯誤：' + response.status);

        const data = await response.json();
        if (data.error) throw new Error(data.error);

        applyResults(data.results);

        if (data.allCorrect) {
            pageCompleted[currentPageIndex] = true;
            pageResults[currentPageIndex]   = data.results;
            updateNavButtons();

            if (currentPageIndex === totalPages - 1) {
                if (wordProblems.length > 0) {
                    setTimeout(() => showWordProblemsScreen(), 600);
                } else {
                    setTimeout(() => showScreen('screen-success'), 600);
                }
            }
        } else {
            showMessage('msg-wrong');
            const firstWrong = document.querySelector('.question-card.state-wrong');
            if (firstWrong) firstWrong.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

    } catch (err) {
        alert('無法提交答案，請重試。\n\n' + err.message);
    } finally {
        hideLoading();
    }
}


/* ══════════════════════════════════════════════════════════════════
   計算題批改結果（✅/❌，不顯示正確答案）
   ══════════════════════════════════════════════════════════════════ */

function applyResults(results, lockAll = false) {
    results.forEach(r => {
        const card   = document.querySelector(`.question-card[data-id="${r.id}"]`);
        const input  = document.getElementById(`input-${r.id}`);
        const status = document.getElementById(`status-${r.id}`);
        if (!card || !input) return;

        card.classList.remove('state-correct', 'state-wrong', 'state-reset');

        if (r.correct || lockAll) {
            card.classList.add('state-correct');
            input.setAttribute('disabled', '');
            if (status) status.textContent = '✅';
        } else {
            card.classList.add('state-wrong');
            input.removeAttribute('disabled');
            if (status) status.textContent = '❌';
            input.select();
        }
    });
}

function clearCardState(id) {
    const card   = document.querySelector(`.question-card[data-id="${id}"]`);
    const status = document.getElementById(`status-${id}`);
    if (card) { card.classList.remove('state-correct', 'state-wrong'); card.classList.add('state-reset'); }
    if (status) status.textContent = '';
    hideMessage('msg-wrong');
    hideMessage('msg-incomplete');
}


/* ══════════════════════════════════════════════════════════════════
   應用題頁
   ══════════════════════════════════════════════════════════════════ */

function showWordProblemsScreen() {
    renderWordProblems(wordProblems);

    // 若已完成，恢復鎖定狀態（全部 ✅，輸入框禁用）
    if (wordProblemsCompleted) {
        wordProblems.forEach(wp => {
            const card   = document.querySelector(`.wp-card[data-id="${wp.id}"]`);
            const status = document.getElementById(`wp-status-${wp.id}`);
            if (card) {
                card.classList.add('state-correct');
                card.querySelectorAll('.formula-num').forEach(inp => inp.setAttribute('disabled', ''));
            }
            if (status) status.textContent = '✅';
        });
    }

    hideWpMessages();
    showScreen('screen-word-problems');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

/**
 * renderWordProblems — 動態生成應用題卡片
 * 每張卡片包含：
 *   · 題號徽章
 *   · 🔊 朗讀按鈕 + 題目文字
 *   · 算式輸入列（數字框 + 運算符 + 等號 + 答案框）
 *   · 狀態圖示（✅/❌）
 */
function renderWordProblems(problems, containerId = 'word-problems-container') {
    const container = document.getElementById(containerId);
    container.innerHTML = '';

    problems.forEach((wp, index) => {
        // ── 卡片 ──────────────────────────────────────────────
        const card = document.createElement('div');
        card.className  = 'wp-card';
        card.dataset.id = wp.id;

        // 題號徽章
        const badge = document.createElement('div');
        badge.className   = 'wp-badge';
        badge.textContent = `第 ${index + 1} 題`;

        // 朗讀按鈕 + 題目文字（同一行）
        const textRow = document.createElement('div');
        textRow.className = 'wp-text-row';

        const speakBtn = document.createElement('button');
        speakBtn.className = 'btn-speak';
        speakBtn.title     = '聆聽題目（粵語）';
        speakBtn.setAttribute('aria-label', '朗讀題目');
        speakBtn.textContent = '🔊';
        speakBtn.addEventListener('click', () => speakCantonese(wp.text, speakBtn));

        const textEl = document.createElement('p');
        textEl.className   = 'wp-text';
        textEl.textContent = wp.text;

        textRow.append(speakBtn, textEl);

        // 算式填寫提示
        const hint = document.createElement('p');
        hint.className   = 'wp-formula-hint';
        hint.textContent = '請填寫算式：';

        // 算式輸入列（□ op □ = □）
        const formulaRow = buildFormulaRow(wp);

        // 狀態圖示
        const status = document.createElement('div');
        status.className = 'wp-status-icon';
        status.id        = `wp-status-${wp.id}`;

        card.append(badge, textRow, hint, formulaRow, status);
        container.appendChild(card);
    });

    // 聚焦第一個算式數字框
    if (problems.length > 0) {
        const first = document.getElementById(`wp-${problems[0].id}-num-0`);
        if (first) first.focus();
    }
}

/**
 * buildFormulaRow — 根據 wp.formulaOps 建立算式輸入列
 * formulaOps=['+']      → [input] + [input] = [result]
 * formulaOps=['+','-']  → [input] + [input] - [input] = [result]
 */
function buildFormulaRow(wp) {
    const row  = document.createElement('div');
    row.className = 'formula-row';

    const wpId = wp.id;
    const ops  = wp.formulaOps;

    // 第一個數字框
    row.appendChild(makeNumBox(wpId, 0));

    // 每個運算符和後續數字框
    ops.forEach((op, i) => {
        const opSpan = document.createElement('span');
        opSpan.className   = 'formula-op';
        opSpan.textContent = op;
        row.appendChild(opSpan);
        row.appendChild(makeNumBox(wpId, i + 1));
    });

    // 等號
    const eq = document.createElement('span');
    eq.className   = 'formula-eq';
    eq.textContent = '=';
    row.appendChild(eq);

    // 結果框（答案欄）
    const resultBox = document.createElement('input');
    resultBox.type         = 'number';
    resultBox.className    = 'formula-num formula-result';
    resultBox.id           = `wp-${wpId}-result`;
    resultBox.min          = '0';
    resultBox.max          = '10';
    resultBox.inputMode    = 'numeric';
    resultBox.pattern      = '[0-9]*';
    resultBox.autocomplete = 'off';
    resultBox.setAttribute('aria-label', '算式答案');
    resultBox.addEventListener('input', () => clearWpCardState(wpId));
    row.appendChild(resultBox);

    return row;
}

/** makeNumBox — 建立算式中的單個數字輸入框 */
function makeNumBox(wpId, idx) {
    const input = document.createElement('input');
    input.type         = 'number';
    input.className    = 'formula-num';
    input.id           = `wp-${wpId}-num-${idx}`;
    input.min          = '0';
    input.max          = '10';
    input.inputMode    = 'numeric';
    input.pattern      = '[0-9]*';
    input.autocomplete = 'off';
    input.setAttribute('aria-label', `算式數字 ${idx + 1}`);
    input.addEventListener('input', () => clearWpCardState(wpId));
    return input;
}


/* ══════════════════════════════════════════════════════════════════
   提交應用題答案
   收集每題的算式數字（nums[]）和最終答案（result）
   發送至服務端；服務端從 Session 取正確值對比，不返回正確答案
   ══════════════════════════════════════════════════════════════════ */

async function submitWordProblems() {
    const answers  = {};
    let   hasBlank = false;

    wordProblems.forEach(wp => {
        const numCount = wp.formulaOps.length + 1;
        const nums     = [];

        // 收集所有算式數字框
        for (let i = 0; i < numCount; i++) {
            const input = document.getElementById(`wp-${wp.id}-num-${i}`);
            const val   = input ? input.value.trim() : '';
            if (val === '') hasBlank = true;
            else nums.push(val);
        }

        // 收集結果框（最終答案）
        const resultInput = document.getElementById(`wp-${wp.id}-result`);
        const result      = resultInput ? resultInput.value.trim() : '';
        if (result === '') hasBlank = true;

        if (!hasBlank) {
            answers[wp.id] = { nums, result };
        }
    });

    if (hasBlank) {
        showMessage('wp-msg-incomplete');
        hideMessage('wp-msg-wrong');
        document.getElementById('wp-msg-incomplete')
            .scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    hideWpMessages();

    showLoading();

    try {
        const response = await fetch('api.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'checkWordProblems', answers }),
        });

        if (!response.ok) throw new Error('伺服器錯誤：' + response.status);

        const data = await response.json();
        if (data.error) throw new Error(data.error);

        applyWpResults(data.results);

        if (data.allCorrect) {
            wordProblemsCompleted = true;
            setTimeout(() => showScreen('screen-success'), 600);
        } else {
            showMessage('wp-msg-wrong');
            const firstWrong = document.querySelector('.wp-card.state-wrong');
            if (firstWrong) firstWrong.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

    } catch (err) {
        alert('無法提交答案，請重試。\n\n' + err.message);
    } finally {
        hideLoading();
    }
}

/**
 * applyWpResults — 為應用題卡片更新 ✅/❌ 圖示和顏色
 * 不顯示正確答案；若答錯，整張卡片高亮顯示 ❌
 */
function applyWpResults(results) {
    results.forEach(r => {
        const card   = document.querySelector(`.wp-card[data-id="${r.id}"]`);
        const status = document.getElementById(`wp-status-${r.id}`);
        if (!card) return;

        card.classList.remove('state-correct', 'state-wrong', 'state-reset');
        const allInputs = card.querySelectorAll('.formula-num');

        if (r.correct) {
            card.classList.add('state-correct');
            allInputs.forEach(inp => inp.setAttribute('disabled', ''));
            if (status) status.textContent = '✅';
        } else {
            card.classList.add('state-wrong');
            allInputs.forEach(inp => inp.removeAttribute('disabled'));
            if (status) status.textContent = '❌';
            // 聚焦第一個數字框，方便修改
            if (allInputs[0]) allInputs[0].focus();
        }
    });
}

/** clearWpCardState — 孩子修改答案時，清除該卡片的錯誤高亮 */
function clearWpCardState(id) {
    const card   = document.querySelector(`.wp-card[data-id="${id}"]`);
    const status = document.getElementById(`wp-status-${id}`);
    if (card) { card.classList.remove('state-correct', 'state-wrong'); card.classList.add('state-reset'); }
    if (status) status.textContent = '';
    // 同時清除兩種應用題畫面的訊息橫幅
    ['wp-msg-wrong', 'wp-msg-incomplete',
     'wp-quiz-msg-wrong', 'wp-quiz-msg-incomplete'].forEach(hideMessage);
}


/* ══════════════════════════════════════════════════════════════════
   應用題練習模式（screen-wp-quiz）
   由首頁「應用題練習」按鈕進入。每頁 4 道應用題，支援多頁分頁。
   重用既有的 renderWordProblems / applyWpResults / speakCantonese。
   ══════════════════════════════════════════════════════════════════ */

/** 啟動應用題練習模式 */
async function startWpQuiz() {
    // 重用首頁的頁數驗證（與「數學練習」相同）
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

    const level = parseInt(document.getElementById('selected-level').value, 10);

    showLoading();
    try {
        const resp = await fetch('api.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'generateWpPages', level, totalPages: totalPagesReq }),
        });
        if (!resp.ok) throw new Error('伺服器錯誤：' + resp.status);

        const data = await resp.json();
        if (data.error) throw new Error(data.error);

        wpQuizPages         = data.pages;
        wpQuizTotalPages    = data.totalPages;
        wpQuizCurrentPage   = 0;
        wpQuizPageCompleted = new Array(data.totalPages).fill(false);
        wpQuizPageResults   = new Array(data.totalPages).fill(null);

        gotoWpQuizPage(0);
        showScreen('screen-wp-quiz');

    } catch (err) {
        alert('無法載入題目，請重新整理頁面後再試。\n\n' + err.message);
    } finally {
        hideLoading();
    }
}

/** 跳轉到應用題練習模式指定頁並渲染卡片 */
function gotoWpQuizPage(pageIndex) {
    wpQuizCurrentPage = pageIndex;

    // 渲染 4 道應用題卡片到 wp-quiz-container
    renderWordProblems(wpQuizPages[pageIndex].problems, 'wp-quiz-container');

    // 若該頁已全對，恢復鎖定狀態（✅、輸入框禁用）
    if (wpQuizPageCompleted[pageIndex] && wpQuizPageResults[pageIndex]) {
        applyWpResults(wpQuizPageResults[pageIndex]);
    }

    updateWpQuizPageIndicator();
    updateWpQuizNavButtons();
    hideWpQuizMessages();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function updateWpQuizPageIndicator() {
    const el = document.getElementById('wp-quiz-page-indicator');
    if (el) el.textContent = `第 ${wpQuizCurrentPage + 1} 頁 / 共 ${wpQuizTotalPages} 頁`;
}

function updateWpQuizNavButtons() {
    const btnPrev   = document.getElementById('wp-quiz-btn-prev');
    const btnSubmit = document.getElementById('wp-quiz-btn-submit');
    const btnNext   = document.getElementById('wp-quiz-btn-next');

    const isFirst = wpQuizCurrentPage === 0;
    const isLast  = wpQuizCurrentPage === wpQuizTotalPages - 1;
    const isDone  = wpQuizPageCompleted[wpQuizCurrentPage];

    toggleHidden(btnPrev,   isFirst);
    toggleHidden(btnSubmit, isDone);
    toggleHidden(btnNext,   !(isDone && !isLast));
}

/** 提交應用題練習模式當前頁的算式和答案 */
async function submitWpQuizPage() {
    const answers  = {};
    let   hasBlank = false;

    const problems = wpQuizPages[wpQuizCurrentPage].problems;

    problems.forEach(wp => {
        const numCount = wp.formulaOps.length + 1;
        const nums     = [];

        for (let i = 0; i < numCount; i++) {
            const input = document.getElementById(`wp-${wp.id}-num-${i}`);
            const val   = input ? input.value.trim() : '';
            if (val === '') hasBlank = true;
            else nums.push(val);
        }

        const resultInput = document.getElementById(`wp-${wp.id}-result`);
        const result      = resultInput ? resultInput.value.trim() : '';
        if (result === '') hasBlank = true;

        if (!hasBlank) answers[wp.id] = { nums, result };
    });

    if (hasBlank) {
        showMessage('wp-quiz-msg-incomplete');
        hideMessage('wp-quiz-msg-wrong');
        document.getElementById('wp-quiz-msg-incomplete')
            .scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    hideWpQuizMessages();

    showLoading();
    try {
        const resp = await fetch('api.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'checkWpPage', pageIndex: wpQuizCurrentPage, answers }),
        });
        if (!resp.ok) throw new Error('伺服器錯誤：' + resp.status);

        const data = await resp.json();
        if (data.error) throw new Error(data.error);

        applyWpResults(data.results);

        if (data.allCorrect) {
            wpQuizPageCompleted[wpQuizCurrentPage] = true;
            wpQuizPageResults[wpQuizCurrentPage]   = data.results;
            updateWpQuizNavButtons();

            if (wpQuizCurrentPage === wpQuizTotalPages - 1) {
                setTimeout(() => showScreen('screen-success'), 600);
            }
        } else {
            showMessage('wp-quiz-msg-wrong');
            const firstWrong = document.querySelector('#wp-quiz-container .wp-card.state-wrong');
            if (firstWrong) firstWrong.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

    } catch (err) {
        alert('無法提交答案，請重試。\n\n' + err.message);
    } finally {
        hideLoading();
    }
}

function hideWpQuizMessages() {
    hideMessage('wp-quiz-msg-incomplete');
    hideMessage('wp-quiz-msg-wrong');
}


/* ══════════════════════════════════════════════════════════════════
   粵語語音朗讀（TTS）
   優先使用瀏覽器 Web Speech API（zh-HK / Cantonese）
   若不可用，則呼叫 tts.php（需設定 Google Cloud TTS API 金鑰）
   ══════════════════════════════════════════════════════════════════ */

// 快取已生成的雲端語音 ArrayBuffer，避免重複網路請求
const ttsCache = new Map();
// 目前正在播放的 AudioBufferSource，用於停止重疊播放
let currentAudioSrc = null;

/**
 * speakCantonese — 以粵語朗讀文字
 * @param {string}      text  — 要朗讀的題目文字
 * @param {HTMLElement} btnEl — 🔊 按鈕元素（用於播放動畫）
 */
async function speakCantonese(text, btnEl) {
    // 停止目前正在播放的音訊
    stopCurrentSpeech();

    // 標記按鈕為「播放中」（顯示脈動動畫）
    btnEl.classList.add('speaking');
    const onEnd = () => btnEl.classList.remove('speaking');

    if (window.speechSynthesis) {
        try {
            // Chrome 需等待 voiceschanged 事件才能取得語音清單
            const voices    = await loadVoices();
            const bestVoice = pickCantoneseVoice(voices);
            await useBrowserTts(text, bestVoice, onEnd);
            return;
        } catch (e) {
            // 瀏覽器 TTS 失敗，嘗試雲端
        }
    }

    await tryCloudTts(text, onEnd);
}

/**
 * loadVoices — 取得瀏覽器語音清單
 * Chrome 的語音清單是非同步載入的，需等待 voiceschanged 事件。
 */
function loadVoices() {
    return new Promise(resolve => {
        const voices = window.speechSynthesis.getVoices();
        if (voices.length > 0) { resolve(voices); return; }

        let resolved = false;
        const handler = () => {
            if (resolved) return;
            resolved = true;
            window.speechSynthesis.removeEventListener('voiceschanged', handler);
            resolve(window.speechSynthesis.getVoices());
        };
        window.speechSynthesis.addEventListener('voiceschanged', handler);
        // 超時保護：若 2 秒內未觸發 voiceschanged，直接返回空陣列
        setTimeout(() => { if (!resolved) { resolved = true; resolve([]); } }, 2000);
    });
}

/**
 * pickCantoneseVoice — 從語音清單中選出最佳粵語語音
 * 優先順序：zh-HK > 含 Cantonese/Hong Kong 關鍵字 > 任意 zh 語音
 */
function pickCantoneseVoice(voices) {
    if (!voices.length) return null;
    return voices.find(v => v.lang === 'zh-HK' || v.lang.startsWith('zh-HK'))
        || voices.find(v => /cantonese|hong.?kong/i.test(v.name))
        || voices.find(v => v.lang.startsWith('zh'))
        || null;
}

/**
 * useBrowserTts — 使用 Web Speech API 朗讀
 * 稍慢語速（0.85）和稍高音調（1.1），更適合兒童聆聽。
 */
function useBrowserTts(text, voice, onEnd) {
    return new Promise((resolve, reject) => {
        window.speechSynthesis.cancel();

        const utter  = new SpeechSynthesisUtterance(text);
        utter.lang   = 'zh-HK';
        utter.rate   = 0.85;   // 稍慢，適合兒童
        utter.pitch  = 1.1;    // 稍高，友善親切
        if (voice) utter.voice = voice;

        utter.onend  = () => { onEnd(); resolve(); };
        utter.onerror = e  => { onEnd(); reject(e); };

        window.speechSynthesis.speak(utter);
    });
}

/**
 * tryCloudTts — 呼叫 tts.php 使用 Google Cloud TTS（需設定 API 金鑰）
 * 若 tts.php 未設定，回傳 { useBrowser: true }，前端改用瀏覽器後備語音。
 */
async function tryCloudTts(text, onEnd) {
    // 檢查快取
    if (ttsCache.has(text)) {
        await playAudioBuffer(ttsCache.get(text), onEnd);
        return;
    }

    try {
        const resp = await fetch('tts.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ text }),
        });

        if (!resp.ok) {
            const errData = await resp.json().catch(() => ({}));
            if (errData.useBrowser) {
                // 雲端未設定，改用瀏覽器後備（不指定特定語音）
                try { await useBrowserTts(text, null, onEnd); } catch(e) { onEnd(); }
            } else {
                onEnd();
            }
            return;
        }

        const arrayBuf = await resp.arrayBuffer();
        ttsCache.set(text, arrayBuf);
        await playAudioBuffer(arrayBuf, onEnd);

    } catch (e) {
        // 網路錯誤，嘗試瀏覽器後備
        try { await useBrowserTts(text, null, onEnd); } catch(e2) { onEnd(); }
    }
}

/**
 * playAudioBuffer — 使用 Web Audio API 播放 MP3 二進位資料
 * ArrayBuffer 只能解碼一次，使用 slice(0) 複製後再解碼。
 */
function playAudioBuffer(arrayBuf, onEnd) {
    return new Promise(resolve => {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            ctx.decodeAudioData(
                arrayBuf.slice(0),   // 複製，保留快取原件
                decoded => {
                    const src    = ctx.createBufferSource();
                    src.buffer   = decoded;
                    currentAudioSrc = src;
                    src.connect(ctx.destination);
                    src.onended = () => { onEnd(); ctx.close(); resolve(); };
                    src.start(0);
                },
                () => { onEnd(); resolve(); }   // 解碼失敗
            );
        } catch (e) { onEnd(); resolve(); }
    });
}

/** stopCurrentSpeech — 停止目前正在播放的所有語音 */
function stopCurrentSpeech() {
    if (window.speechSynthesis) window.speechSynthesis.cancel();
    if (currentAudioSrc) {
        try { currentAudioSrc.stop(); } catch(e) {}
        currentAudioSrc = null;
    }
}


/* ══════════════════════════════════════════════════════════════════
   重置 / 再來一次
   ══════════════════════════════════════════════════════════════════ */

function resetQuiz() {
    stopCurrentSpeech();
    pages                 = [];
    totalPages            = 0;
    currentPageIndex      = 0;
    pageCompleted         = [];
    pageResults           = [];
    wordProblems          = [];
    wordProblemsCompleted = false;

    // 應用題練習模式狀態
    wpQuizPages         = [];
    wpQuizTotalPages    = 0;
    wpQuizCurrentPage   = 0;
    wpQuizPageCompleted = [];
    wpQuizPageResults   = [];

    document.getElementById('questions-container').innerHTML     = '';
    document.getElementById('word-problems-container').innerHTML = '';
    document.getElementById('wp-quiz-container').innerHTML        = '';
    hideQuizMessages();
    hideWpMessages();
    hideWpQuizMessages();
    showScreen('screen-home');
}


/* ══════════════════════════════════════════════════════════════════
   消息輔助函數
   ══════════════════════════════════════════════════════════════════ */

function showMessage(id) { const el = document.getElementById(id); if (el) el.removeAttribute('hidden'); }
function hideMessage(id) { const el = document.getElementById(id); if (el) el.setAttribute('hidden', ''); }
function hideQuizMessages() { hideMessage('msg-incomplete'); hideMessage('msg-wrong'); }
function hideWpMessages()   { hideMessage('wp-msg-incomplete'); hideMessage('wp-msg-wrong'); }


/* ══════════════════════════════════════════════════════════════════
   初始化：DOM 載入完成後綁定所有按鈕事件
   使用 addEventListener 而非 onclick 屬性，符合 CSP 安全策略。
   ══════════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', () => {

    // 首頁
    document.getElementById('btn-start').addEventListener('click', startQuiz);
    document.getElementById('btn-start-wp').addEventListener('click', startWpQuiz);
    initLevelButtons();

    // 計算題頁
    document.getElementById('btn-home-quiz').addEventListener('click', goHome);
    document.getElementById('btn-submit').addEventListener('click', submitAnswers);
    document.getElementById('btn-prev').addEventListener('click', () => {
        if (currentPageIndex > 0) gotoPage(currentPageIndex - 1);
    });
    document.getElementById('btn-next').addEventListener('click', () => {
        if (currentPageIndex < totalPages - 1) {
            gotoPage(currentPageIndex + 1);
        } else if (wordProblems.length > 0) {
            showWordProblemsScreen();
        }
    });

    // 應用題頁
    document.getElementById('btn-home-wp').addEventListener('click', goHome);
    document.getElementById('wp-btn-submit').addEventListener('click', submitWordProblems);
    document.getElementById('wp-btn-prev').addEventListener('click', () => {
        gotoPage(totalPages - 1);
        showScreen('screen-quiz');
    });

    // 應用題練習頁（screen-wp-quiz）
    document.getElementById('wp-quiz-btn-home').addEventListener('click', goHome);
    document.getElementById('wp-quiz-btn-submit').addEventListener('click', submitWpQuizPage);
    document.getElementById('wp-quiz-btn-prev').addEventListener('click', () => {
        if (wpQuizCurrentPage > 0) gotoWpQuizPage(wpQuizCurrentPage - 1);
    });
    document.getElementById('wp-quiz-btn-next').addEventListener('click', () => {
        if (wpQuizCurrentPage < wpQuizTotalPages - 1) gotoWpQuizPage(wpQuizCurrentPage + 1);
    });

    // 成功頁
    document.getElementById('btn-tryagain').addEventListener('click', resetQuiz);
    document.getElementById('btn-home-success').addEventListener('click', resetQuiz);
});
