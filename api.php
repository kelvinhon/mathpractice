<?php
/**
 * api.php — JSON API 端點（繁體中文版）
 *
 * 支援操作（POST，JSON body 傳入 action 欄位）：
 *   generate          → 生成所有計算題頁及應用題；答案存入 Session，不返回客戶端
 *   check             → 核對計算題頁答案；不返回正確答案
 *   checkWordProblems → 核對應用題算式及最終答案；不返回正確答案或提示
 *
 * 安全措施：
 *   · 所有正確答案、算式數字僅存於 PHP Session，絕不出現在任何回應中
 *   · 提交值強制轉為整數，防止注入攻擊
 *   · 僅接受 POST 請求
 *   · Session 存有生成時間戳，可供超時控制擴充
 */

session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => '不支援該請求方法']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data) || empty($data['action'])) {
    http_response_code(400);
    echo json_encode(['error' => '請求格式錯誤']);
    exit;
}

switch ($data['action']) {
    case 'generate':
        echo json_encode(generateQuiz($data));
        break;
    case 'check':
        echo json_encode(checkPage($data));
        break;
    case 'checkWordProblems':
        echo json_encode(checkWordProblems($data));
        break;
    case 'generateWpPages':
        echo json_encode(generateWpPages($data));
        break;
    case 'checkWpPage':
        echo json_encode(checkWpPage($data));
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => '未知操作']);
}
exit;


// ============================================================================
// generateQuiz — 生成所有計算題頁及應用題
//
// 客戶端收到（無答案）：
//   { totalPages, pages:[...], wordProblems:[{id,emoji,text,formulaOps}] 或 [] }
//
// 寫入 Session（含答案）：
//   quiz.answers[頁][題]  = 正確答案
//   quiz.wpData[題]       = {nums, ops, answer}
// ============================================================================
function generateQuiz(array $data): array
{
    $level      = (int)($data['level']      ?? 1);
    $totalPages = (int)($data['totalPages'] ?? 1);

    if ($level < 1 || $level > 3) {
        http_response_code(400);
        return ['error' => '難度等級無效'];
    }
    if ($totalPages < 1 || $totalPages > 50) {
        http_response_code(400);
        return ['error' => '練習頁數無效（1–50）'];
    }

    // ── 生成計算題 ───────────────────────────────────────────
    $sessionAnswers = [];
    $clientPages    = [];

    for ($p = 0; $p < $totalPages; $p++) {
        $pageQ = [];
        $pageA = [];
        for ($i = 0; $i < 12; $i++) {
            [$display, $answer] = generateCalcQuestion($level);
            $pageQ[] = ['id' => $i, 'display' => $display];
            $pageA[$i] = $answer;
        }
        $clientPages[]      = ['questions' => $pageQ];
        $sessionAnswers[$p] = $pageA;
    }

    // ── 生成應用題（頁數 > 1 時） ────────────────────────────
    $clientWordProblems = [];
    $sessionWpData      = [];

    if ($totalPages > 1) {
        [$clientWordProblems, $sessionWpData] = generateWordProblems($level);
    }

    // ── 寫入 Session ─────────────────────────────────────────
    $_SESSION['quiz'] = [
        'answers'    => $sessionAnswers,
        'wpData'     => $sessionWpData,   // 算式正確資料，只存於 Session
        'level'      => $level,
        'totalPages' => $totalPages,
        'createdAt'  => time(),
    ];

    return [
        'totalPages'   => $totalPages,
        'pages'        => $clientPages,
        'wordProblems' => $clientWordProblems,  // 只含題目文字與符號，無正確數字
    ];
}


// ============================================================================
// checkPage — 核對計算題頁答案（不返回正確答案）
// ============================================================================
function checkPage(array $data): array
{
    if (empty($_SESSION['quiz']['answers'])) {
        http_response_code(400);
        return ['error' => '工作階段已逾期，請重新開始練習'];
    }

    $pageIndex = (int)($data['pageIndex'] ?? 0);
    $submitted = $data['answers'] ?? [];
    $quiz      = $_SESSION['quiz'];

    if ($pageIndex < 0 || $pageIndex >= (int)$quiz['totalPages']) {
        http_response_code(400);
        return ['error' => '頁碼無效'];
    }

    $correctAnswers = $quiz['answers'][$pageIndex];
    $results        = [];
    $allCorrect     = true;

    for ($i = 0; $i < 12; $i++) {
        $submittedVal = isset($submitted[(string)$i]) ? (int)$submitted[(string)$i] : null;
        $isCorrect    = ($submittedVal !== null && $submittedVal === (int)$correctAnswers[$i]);
        if (!$isCorrect) $allCorrect = false;
        // 不返回正確答案
        $results[] = ['id' => $i, 'correct' => $isCorrect];
    }

    return ['allCorrect' => $allCorrect, 'results' => $results];
}


// ============================================================================
// checkWordProblems — 核對應用題算式數字及最終答案
//
// 驗證邏輯：
//   1. 每個算式數字框的值必須與題目正確數字相符（按順序）
//   2. 結果框的值必須與正確最終答案相符
//
// 回應只包含每題的 correct 布林值，絕不包含正確答案或任何提示。
// ============================================================================
function checkWordProblems(array $data): array
{
    if (empty($_SESSION['quiz']['wpData'])) {
        http_response_code(400);
        return ['error' => '沒有應用題資料，工作階段可能已逾期'];
    }

    $wpData    = $_SESSION['quiz']['wpData'];
    $submitted = $data['answers'] ?? [];
    $results   = [];
    $allCorrect = true;

    foreach ($wpData as $id => $expected) {
        $ans = $submitted[(string)$id] ?? [];

        // 強制轉為整數，防止字串注入
        $submittedNums   = array_map('intval', $ans['nums'] ?? []);
        $submittedResult = isset($ans['result']) ? (int)$ans['result'] : null;

        // 驗證數字數量是否正確
        $expectedNums = $expected['nums'];
        $numsOk       = (count($submittedNums) === count($expectedNums));

        // 逐一驗證每個算式數字（順序一致）
        if ($numsOk) {
            foreach ($expectedNums as $i => $correctNum) {
                if ($submittedNums[$i] !== (int)$correctNum) {
                    $numsOk = false;
                    break;
                }
            }
        }

        // 驗證最終答案
        $resultOk  = ($submittedResult !== null && $submittedResult === (int)$expected['answer']);

        $isCorrect = $numsOk && $resultOk;
        if (!$isCorrect) $allCorrect = false;

        // 只返回正確與否，不返回正確答案
        $results[] = ['id' => (int)$id, 'correct' => $isCorrect];
    }

    return ['allCorrect' => $allCorrect, 'results' => $results];
}


// ============================================================================
// generateWpPages — 應用題練習模式：生成所有頁，每頁 4 道應用題
//
// 請求參數：{ level, totalPages }
// Session 寫入（與計算題的 quiz 互不干擾）：
//   $_SESSION['wpQuiz']['pages'][頁][題] = {nums, ops, answer}
// 客戶端收到（無答案）：
//   { totalPages, pages:[{problems:[{id,emoji,text,formulaOps}]}] }
// ============================================================================
function generateWpPages(array $data): array
{
    $level      = (int)($data['level']      ?? 1);
    $totalPages = (int)($data['totalPages'] ?? 1);

    if ($level < 1 || $level > 3) {
        http_response_code(400);
        return ['error' => '難度等級無效'];
    }
    if ($totalPages < 1 || $totalPages > 50) {
        http_response_code(400);
        return ['error' => '練習頁數無效（1–50）'];
    }

    $sessionPages = [];
    $clientPages  = [];

    for ($p = 0; $p < $totalPages; $p++) {
        [$clientWp, $sessionWp] = buildWpPageSet($level);
        $clientPages[]    = ['problems' => $clientWp];
        $sessionPages[$p] = $sessionWp;
    }

    // 存入獨立的 wpQuiz Session
    $_SESSION['wpQuiz'] = [
        'pages'      => $sessionPages,
        'level'      => $level,
        'totalPages' => $totalPages,
        'createdAt'  => time(),
    ];

    return [
        'totalPages' => $totalPages,
        'pages'      => $clientPages,
    ];
}


// ============================================================================
// buildWpPageSet — 組合 4 道應用題（重用既有的 generateWordProblems）
//
// 既有 generateWordProblems 每次返回 2 道（一加一減）。呼叫兩次得 4 道，
// 重新編號為 0–3，客戶端與 Session 順序保持一致。
// ============================================================================
function buildWpPageSet(int $level): array
{
    [$c1, $s1] = generateWordProblems($level);
    [$c2, $s2] = generateWordProblems($level);

    $clientAll  = array_merge($c1, $c2);                          // 4 題（題目，無答案）
    $sessionAll = array_merge(array_values($s1), array_values($s2)); // 4 題（含答案）

    $client  = [];
    $session = [];
    foreach ($clientAll as $i => $item) {
        $item['id']  = $i;          // 重新編號 0–3
        $client[$i]  = $item;
        $session[$i] = $sessionAll[$i];
    }

    return [$client, $session];
}


// ============================================================================
// checkWpPage — 核對應用題練習模式某一頁的算式及最終答案
//
// 請求參數：{ pageIndex, answers: { "0": {nums:[..], result:".."}, ... } }
// 回應：{ allCorrect, results:[{id, correct}] }  —— 絕不返回正確答案
// ============================================================================
function checkWpPage(array $data): array
{
    if (empty($_SESSION['wpQuiz']['pages'])) {
        http_response_code(400);
        return ['error' => '工作階段已逾期，請重新開始練習'];
    }

    $pageIndex = (int)($data['pageIndex'] ?? 0);
    $submitted = $data['answers'] ?? [];
    $quiz      = $_SESSION['wpQuiz'];

    if ($pageIndex < 0 || $pageIndex >= (int)$quiz['totalPages']) {
        http_response_code(400);
        return ['error' => '頁碼無效'];
    }

    $wpData     = $quiz['pages'][$pageIndex];
    $results    = [];
    $allCorrect = true;

    foreach ($wpData as $id => $expected) {
        $ans = $submitted[(string)$id] ?? [];

        // 強制轉整數，防止字串注入
        $submittedNums   = array_map('intval', $ans['nums'] ?? []);
        $submittedResult = isset($ans['result']) ? (int)$ans['result'] : null;

        // 驗證數字個數與每個算式數字
        $expectedNums = $expected['nums'];
        $numsOk       = (count($submittedNums) === count($expectedNums));
        if ($numsOk) {
            foreach ($expectedNums as $i => $correctNum) {
                if ($submittedNums[$i] !== (int)$correctNum) { $numsOk = false; break; }
            }
        }

        // 驗證最終答案
        $resultOk  = ($submittedResult !== null && $submittedResult === (int)$expected['answer']);
        $isCorrect = $numsOk && $resultOk;
        if (!$isCorrect) $allCorrect = false;

        $results[] = ['id' => (int)$id, 'correct' => $isCorrect];
    }

    return ['allCorrect' => $allCorrect, 'results' => $results];
}


// ============================================================================
// generateWordProblems — 根據難度等級生成兩道應用題
//
// 返回：[$clientProblems, $sessionWpData]
//   $clientProblems — 含題目文字和算式符號（formulaOps），無正確數字
//   $sessionWpData  — 含正確數字（nums）和答案（answer），只存入 Session
// ============================================================================
function generateWordProblems(int $level): array
{
    switch ($level) {
        case 2:  return generateWpLevel2();
        case 3:  return generateWpLevel3();
        default: return generateWpLevel1();
    }
}


// ── 一級應用題：兩個數，加法或減法 ─────────────────────────────────────────────
function generateWpLevel1(): array
{
    // 加法題目模板（使用日常物品，適合 5–7 歲兒童）
    $addTpl = [
        ['emoji' => '✏️', 'tpl' => '你有 {a} 支鉛筆，老師又給了你 {b} 支鉛筆，現在你有多少支鉛筆？'],
        ['emoji' => '🍬', 'tpl' => '小明有 {a} 顆糖果，媽媽又給了他 {b} 顆，小明一共有多少顆糖果？'],
        ['emoji' => '🍎', 'tpl' => '桌上有 {a} 個蘋果，媽媽又放了 {b} 個，桌上一共有多少個蘋果？'],
        ['emoji' => '🎈', 'tpl' => '小朋友有 {a} 個氣球，爸爸又買了 {b} 個，一共有多少個氣球？'],
        ['emoji' => '📚', 'tpl' => '書架上有 {a} 本書，老師又放了 {b} 本，書架上一共有多少本書？'],
        ['emoji' => '🏷️', 'tpl' => '小明有 {a} 張貼紙，小紅給了他 {b} 張，小明一共有多少張貼紙？'],
        ['emoji' => '🚗', 'tpl' => '桌上有 {a} 輛小汽車，又放了 {b} 輛，桌上一共有多少輛小汽車？'],
        ['emoji' => '🧹', 'tpl' => '你有 {a} 個橡皮擦，同學給了你 {b} 個，你一共有多少個橡皮擦？'],
        ['emoji' => '🧸', 'tpl' => '小明有 {a} 個玩具，奶奶又送了他 {b} 個，小明一共有多少個玩具？'],
    ];

    // 減法題目模板
    $subTpl = [
        ['emoji' => '✏️', 'tpl' => '你有 {a} 支鉛筆，借了 {b} 支給同學，你還有多少支鉛筆？'],
        ['emoji' => '🍬', 'tpl' => '小明有 {a} 顆糖果，吃掉了 {b} 顆，還剩下多少顆糖果？'],
        ['emoji' => '🍎', 'tpl' => '樹上有 {a} 個蘋果，摘了 {b} 個，樹上還有多少個蘋果？'],
        ['emoji' => '🎈', 'tpl' => '小朋友有 {a} 個氣球，飛走了 {b} 個，還剩下多少個氣球？'],
        ['emoji' => '📚', 'tpl' => '書架上有 {a} 本書，借走了 {b} 本，書架上還有多少本書？'],
        ['emoji' => '🏷️', 'tpl' => '小明有 {a} 張貼紙，用掉了 {b} 張，還剩下多少張貼紙？'],
        ['emoji' => '🚗', 'tpl' => '桌上有 {a} 輛小汽車，拿走了 {b} 輛，桌上還有多少輛小汽車？'],
        ['emoji' => '🧹', 'tpl' => '你有 {a} 個橡皮擦，送了 {b} 個給同學，你還有多少個橡皮擦？'],
        ['emoji' => '🧸', 'tpl' => '小明有 {a} 個玩具，送了 {b} 個給弟弟，小明還有多少個玩具？'],
    ];

    $at = $addTpl[random_int(0, count($addTpl) - 1)];
    $st = $subTpl[random_int(0, count($subTpl) - 1)];

    // 加法數字：a≥1, b≥1, a+b≤10
    $a1 = random_int(1, 9);
    $b1 = random_int(1, 10 - $a1);

    // 減法數字：a≥2, b≥1, a-b≥1（結果至少為1）
    $a2 = random_int(2, 10);
    $b2 = random_int(1, $a2 - 1);

    return buildWpPair(
        [$at['emoji'], str_replace(['{a}','{b}'], [$a1,$b1], $at['tpl']), ['+'], [$a1,$b1], $a1+$b1],
        [$st['emoji'], str_replace(['{a}','{b}'], [$a2,$b2], $st['tpl']), ['-'], [$a2,$b2], $a2-$b2]
    );
}


// ── 二級應用題：三個數，相同運算符 ─────────────────────────────────────────────
function generateWpLevel2(): array
{
    $addTpl = [
        ['emoji' => '✏️', 'tpl' => '小明有 {a} 支鉛筆，小紅有 {b} 支，小華有 {c} 支，他們一共有多少支鉛筆？'],
        ['emoji' => '🍬', 'tpl' => '小明有 {a} 顆糖果，媽媽給了他 {b} 顆，奶奶又給了他 {c} 顆，小明一共有多少顆糖果？'],
        ['emoji' => '🎈', 'tpl' => '小朋友買了 {a} 個紅氣球、{b} 個黃氣球和 {c} 個藍氣球，一共有多少個氣球？'],
        ['emoji' => '📚', 'tpl' => '書架上有 {a} 本故事書、{b} 本科學書和 {c} 本圖畫書，書架上一共有多少本書？'],
        ['emoji' => '🏷️', 'tpl' => '小明有 {a} 張貼紙，小紅給了他 {b} 張，小華又給了他 {c} 張，小明一共有多少張貼紙？'],
        ['emoji' => '🚗', 'tpl' => '停車場有 {a} 輛紅車、{b} 輛藍車和 {c} 輛白車，停車場一共有多少輛車？'],
    ];

    $subTpl = [
        ['emoji' => '🍬', 'tpl' => '小明有 {a} 顆糖果，給了小紅 {b} 顆，再給了小華 {c} 顆，小明還剩多少顆糖果？'],
        ['emoji' => '🍎', 'tpl' => '籃子裡有 {a} 個蘋果，媽媽用了 {b} 個，小明吃了 {c} 個，籃子裡還剩多少個蘋果？'],
        ['emoji' => '✏️', 'tpl' => '你有 {a} 支鉛筆，借了 {b} 支給小紅，又借了 {c} 支給小明，你還有多少支鉛筆？'],
        ['emoji' => '🎈', 'tpl' => '小朋友有 {a} 個氣球，飛走了 {b} 個，又送了 {c} 個給朋友，還剩多少個氣球？'],
        ['emoji' => '📚', 'tpl' => '書架上有 {a} 本書，借走了 {b} 本，又送了 {c} 本，書架上還剩多少本書？'],
        ['emoji' => '🚗', 'tpl' => '桌上有 {a} 輛小汽車，弟弟拿走了 {b} 輛，妹妹拿走了 {c} 輛，桌上還有多少輛小汽車？'],
    ];

    $at = $addTpl[random_int(0, count($addTpl) - 1)];
    $st = $subTpl[random_int(0, count($subTpl) - 1)];

    // 加法：a,b,c≥1, a+b+c≤10
    $a1 = random_int(1, 8);
    $b1 = random_int(1, 9 - $a1);
    $c1 = random_int(1, 10 - $a1 - $b1);

    // 減法：a≥3, b,c≥1, a-b-c≥1（b+c ≤ a-1）
    $a2 = random_int(3, 10);
    $b2 = random_int(1, $a2 - 2);
    $c2 = random_int(1, $a2 - $b2 - 1);

    return buildWpPair(
        [$at['emoji'], str_replace(['{a}','{b}','{c}'], [$a1,$b1,$c1], $at['tpl']), ['+','+'], [$a1,$b1,$c1], $a1+$b1+$c1],
        [$st['emoji'], str_replace(['{a}','{b}','{c}'], [$a2,$b2,$c2], $st['tpl']), ['-','-'], [$a2,$b2,$c2], $a2-$b2-$c2]
    );
}


// ── 三級應用題：三個數，混合運算符 ─────────────────────────────────────────────
function generateWpLevel3(): array
{
    // 加減混合（a + b - c）
    $asTpl = [
        ['emoji' => '✏️', 'tpl' => '你有 {a} 支鉛筆，老師又給了你 {b} 支，你借了 {c} 支給同學，現在你有多少支鉛筆？'],
        ['emoji' => '🍎', 'tpl' => '籃子裡有 {a} 個蘋果，媽媽放進去 {b} 個，小明拿走了 {c} 個，籃子裡還有多少個蘋果？'],
        ['emoji' => '🎈', 'tpl' => '小朋友有 {a} 個氣球，又買了 {b} 個，送了 {c} 個給朋友，現在有多少個氣球？'],
        ['emoji' => '🍬', 'tpl' => '小明有 {a} 顆糖果，媽媽給了他 {b} 顆，他吃掉了 {c} 顆，現在小明有多少顆糖果？'],
        ['emoji' => '📚', 'tpl' => '書架上有 {a} 本書，放進了 {b} 本新書，借走了 {c} 本，書架上還有多少本書？'],
        ['emoji' => '🚗', 'tpl' => '車場有 {a} 輛小汽車，又開進來 {b} 輛，開走了 {c} 輛，車場現在有多少輛小汽車？'],
    ];

    // 減加混合（a - b + c）
    $saTpl = [
        ['emoji' => '✏️', 'tpl' => '你有 {a} 支鉛筆，借了 {b} 支給同學，老師又給了你 {c} 支，現在你有多少支鉛筆？'],
        ['emoji' => '🍎', 'tpl' => '籃子裡有 {a} 個蘋果，拿走了 {b} 個，媽媽又放進去 {c} 個，籃子裡現在有多少個蘋果？'],
        ['emoji' => '🎈', 'tpl' => '小朋友有 {a} 個氣球，飛走了 {b} 個，爸爸又買了 {c} 個，現在有多少個氣球？'],
        ['emoji' => '🍬', 'tpl' => '小明有 {a} 顆糖果，吃了 {b} 顆，奶奶又給了他 {c} 顆，現在小明有多少顆糖果？'],
        ['emoji' => '📚', 'tpl' => '書架上有 {a} 本書，借走了 {b} 本，老師又放進去 {c} 本，書架上現在有多少本書？'],
        ['emoji' => '🧸', 'tpl' => '小明有 {a} 個玩具，送了 {b} 個給弟弟，爸爸又買了 {c} 個給他，小明現在有多少個玩具？'],
    ];

    $at = $asTpl[random_int(0, count($asTpl) - 1)];
    $st = $saTpl[random_int(0, count($saTpl) - 1)];

    // 加減（a+b-c）：a,b,c≥1, a+b-c≥1
    $a1   = random_int(1, 9);
    $b1   = random_int(1, 10 - $a1);
    $sum1 = $a1 + $b1;
    $c1   = random_int(1, $sum1 - 1);   // 結果 = sum - c ≥ 1

    // 減加（a-b+c）：a≥2, b≥1, a-b≥1, c≥1, a-b+c≤10
    $a2   = random_int(2, 10);
    $b2   = random_int(1, $a2 - 1);
    $diff = $a2 - $b2;
    $c2   = random_int(1, 10 - $diff);

    return buildWpPair(
        [$at['emoji'], str_replace(['{a}','{b}','{c}'], [$a1,$b1,$c1], $at['tpl']), ['+','-'], [$a1,$b1,$c1], $sum1-$c1],
        [$st['emoji'], str_replace(['{a}','{b}','{c}'], [$a2,$b2,$c2], $st['tpl']), ['-','+'], [$a2,$b2,$c2], $diff+$c2]
    );
}


// ── 輔助：組合兩道應用題並隨機決定順序 ─────────────────────────────────────────
// $wp = [$emoji, $text, $ops, $nums, $answer]
function buildWpPair(array $wp1, array $wp2): array
{
    [$emoji1, $text1, $ops1, $nums1, $ans1] = $wp1;
    [$emoji2, $text2, $ops2, $nums2, $ans2] = $wp2;

    if (random_int(0, 1) === 0) {
        $client  = [
            ['id' => 0, 'emoji' => $emoji1, 'text' => $text1, 'formulaOps' => $ops1],
            ['id' => 1, 'emoji' => $emoji2, 'text' => $text2, 'formulaOps' => $ops2],
        ];
        $session = [
            0 => ['nums' => $nums1, 'ops' => $ops1, 'answer' => $ans1],
            1 => ['nums' => $nums2, 'ops' => $ops2, 'answer' => $ans2],
        ];
    } else {
        $client  = [
            ['id' => 0, 'emoji' => $emoji2, 'text' => $text2, 'formulaOps' => $ops2],
            ['id' => 1, 'emoji' => $emoji1, 'text' => $text1, 'formulaOps' => $ops1],
        ];
        $session = [
            0 => ['nums' => $nums2, 'ops' => $ops2, 'answer' => $ans2],
            1 => ['nums' => $nums1, 'ops' => $ops1, 'answer' => $ans1],
        ];
    }

    return [$client, $session];
}


// ============================================================================
// 計算題生成（一級/二級/三級）
// ============================================================================
function generateCalcQuestion(int $level): array
{
    switch ($level) {
        case 2:  return generateLevel2();
        case 3:  return generateLevel3();
        default: return generateLevel1();
    }
}

// 一級：兩個數
function generateLevel1(): array
{
    $op = random_int(0, 1) ? '+' : '-';
    if ($op === '+') { $a = random_int(0, 10); $b = random_int(0, 10 - $a); }
    else             { $a = random_int(0, 10); $b = random_int(0, $a); }
    return ["$a $op $b", ($op === '+') ? $a + $b : $a - $b];
}

// 二級：三個數，相同符號
function generateLevel2(): array
{
    $op = random_int(0, 1) ? '+' : '-';
    if ($op === '+') {
        $a = random_int(0, 8); $b = random_int(0, 10 - $a); $c = random_int(0, 10 - $a - $b);
        return ["$a + $b + $c", $a + $b + $c];
    } else {
        $a = random_int(0, 10); $b = random_int(0, $a); $c = random_int(0, $a - $b);
        return ["$a - $b - $c", $a - $b - $c];
    }
}

// 三級：三個數，混合符號
function generateLevel3(): array
{
    if (random_int(0, 1) === 0) {
        $a = random_int(0, 10); $b = random_int(0, 10 - $a);
        $sum = $a + $b; $c = random_int(max(0, $sum - 10), $sum);
        return ["$a + $b - $c", $sum - $c];
    } else {
        $a = random_int(0, 10); $b = random_int(0, $a);
        $diff = $a - $b; $c = random_int(0, 10 - $diff);
        return ["$a - $b + $c", $diff + $c];
    }
}
