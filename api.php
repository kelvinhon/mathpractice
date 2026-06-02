<?php
/**
 * api.php — JSON API 端点
 *
 * 支持两个操作（通过 POST 请求的 JSON body 传入 action 字段）：
 *   action=generate  →  生成所有页的题目，将正确答案存入 Session，
 *                       仅将题目文本（不含答案）返回给客户端。
 *   action=check     →  将提交的答案与 Session 中存储的正确答案对比，
 *                       返回每道题是否正确，绝不向客户端暴露正确答案。
 *
 * 安全措施：
 *   - 仅接受 POST 请求。
 *   - 正确答案只存在于 PHP Session，从不出现在 HTML、隐藏字段或 JS 变量中。
 *   - 提交的答案在比较前强制转换为整数，防止注入攻击。
 *   - Session 中存有生成时间戳，可用于超时控制（此处保留扩展点）。
 */

// ── 启动 Session（正确答案存储在此）────────────────────────────────────────
session_start();

// ── 安全响应头 ──────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// 仅接受 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => '不支持该请求方法']);
    exit;
}

// ── 读取并解码请求体 ────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data) || empty($data['action'])) {
    http_response_code(400);
    echo json_encode(['error' => '请求格式错误']);
    exit;
}

// ── 路由 ────────────────────────────────────────────────────────────────────
switch ($data['action']) {
    case 'generate':
        echo json_encode(generateQuiz($data));
        break;
    case 'check':
        echo json_encode(checkPage($data));
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => '未知操作']);
}
exit;


// ============================================================================
// generateQuiz — 生成所有页的题目
//
// 参数（来自请求体）：
//   level      int  1=一级 / 2=二级 / 3=三级
//   totalPages int  练习页数，最小 1
//
// 返回给客户端（无答案）：
//   { totalPages, pages: [ { questions: [{id, display}] }, ... ] }
//
// 同时将正确答案写入 Session：
//   $_SESSION['quiz']['answers'][pageIndex][questionId] = correctAnswer
// ============================================================================
function generateQuiz(array $data): array
{
    $level      = (int)($data['level']      ?? 1);
    $totalPages = (int)($data['totalPages'] ?? 1);

    // 参数范围校验
    if ($level < 1 || $level > 3) {
        http_response_code(400);
        return ['error' => '难度等级无效'];
    }
    if ($totalPages < 1 || $totalPages > 50) {
        http_response_code(400);
        return ['error' => '练习页数无效（1–50）'];
    }

    $sessionAnswers  = [];   // 存入 Session（含答案）
    $clientPages     = [];   // 返回客户端（不含答案）

    for ($p = 0; $p < $totalPages; $p++) {
        $pageQuestions = [];
        $pageAnswers   = [];

        for ($i = 0; $i < 12; $i++) {
            [$display, $answer] = generateQuestion($level);

            // 返回给客户端的仅有题目文本和编号
            $pageQuestions[] = ['id' => $i, 'display' => $display];

            // 答案只存在于 Session，不发送给客户端
            $pageAnswers[$i] = $answer;
        }

        $clientPages[]        = ['questions' => $pageQuestions];
        $sessionAnswers[$p]   = $pageAnswers;
    }

    // 将正确答案和元数据写入 Session
    $_SESSION['quiz'] = [
        'answers'    => $sessionAnswers,
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
// checkPage — 核对某一页的答案
//
// 请求体：{ pageIndex, answers: { "0": "5", "1": "3", ... } }
//
// 返回：{ allCorrect: bool, results: [{id, correct}] }
//   注意：results 中绝不包含正确答案，仅包含 correct 布尔值。
// ============================================================================
function checkPage(array $data): array
{
    // 检查 Session 是否存在（可能已超时）
    if (empty($_SESSION['quiz']['answers'])) {
        http_response_code(400);
        return ['error' => 'Session 已过期，请重新开始练习'];
    }

    $pageIndex = (int)($data['pageIndex'] ?? 0);
    $submitted = $data['answers'] ?? [];

    $quiz       = $_SESSION['quiz'];
    $totalPages = (int)$quiz['totalPages'];

    if ($pageIndex < 0 || $pageIndex >= $totalPages) {
        http_response_code(400);
        return ['error' => '页码无效'];
    }

    // 从 Session 取出该页正确答案（绝不返回给客户端）
    $correctAnswers = $quiz['answers'][$pageIndex];

    $results    = [];
    $allCorrect = true;

    for ($i = 0; $i < 12; $i++) {
        // 将提交值强制转为整数，防止字符串注入
        $submittedVal = isset($submitted[(string)$i])
            ? (int)$submitted[(string)$i]
            : null;

        $correct   = (int)$correctAnswers[$i];
        $isCorrect = ($submittedVal !== null && $submittedVal === $correct);

        if (!$isCorrect) {
            $allCorrect = false;
        }

        // 只返回 correct 布尔值，绝不返回 correctAnswer
        $results[] = ['id' => $i, 'correct' => $isCorrect];
    }

    return ['allCorrect' => $allCorrect, 'results' => $results];
}


// ============================================================================
// generateQuestion — 根据等级生成单道题目
// 返回 [题目字符串, 正确答案整数]
// ============================================================================
function generateQuestion(int $level): array
{
    switch ($level) {
        case 1: return generateLevel1();
        case 2: return generateLevel2();
        case 3: return generateLevel3();
        default: return generateLevel1();
    }
}


// ────────────────────────────────────────────────────────────────────────────
// 一级：两个数的加法或减法，结果在 0–10 之间
// 示例：3 + 2，8 - 4
// ────────────────────────────────────────────────────────────────────────────
function generateLevel1(): array
{
    $op = random_int(0, 1) ? '+' : '-';

    if ($op === '+') {
        $a = random_int(0, 10);
        $b = random_int(0, 10 - $a);   // 确保 a+b ≤ 10
    } else {
        $a = random_int(0, 10);
        $b = random_int(0, $a);         // 确保 a-b ≥ 0
    }

    $answer = ($op === '+') ? $a + $b : $a - $b;
    return ["$a $op $b", $answer];
}


// ────────────────────────────────────────────────────────────────────────────
// 二级：三个数，两个相同的运算符，结果在 0–10 之间
// 示例：1 + 5 + 1，9 - 3 - 2
// ────────────────────────────────────────────────────────────────────────────
function generateLevel2(): array
{
    $op = random_int(0, 1) ? '+' : '-';

    if ($op === '+') {
        // a + b + c，确保总和 ≤ 10
        $a = random_int(0, 8);
        $b = random_int(0, 10 - $a);
        $c = random_int(0, 10 - $a - $b);
        $answer = $a + $b + $c;
    } else {
        // a - b - c，确保 a - b - c ≥ 0（即 b + c ≤ a）
        $a = random_int(0, 10);
        $b = random_int(0, $a);
        $c = random_int(0, $a - $b);
        $answer = $a - $b - $c;
    }

    return ["$a $op $b $op $c", $answer];
}


// ────────────────────────────────────────────────────────────────────────────
// 三级：三个数，两个不同的运算符（加减混合），结果在 0–10 之间
// 模式 A：a + b - c   模式 B：a - b + c
// ────────────────────────────────────────────────────────────────────────────
function generateLevel3(): array
{
    // 随机选择混合模式
    if (random_int(0, 1) === 0) {
        // 模式 A：a + b - c
        // 先取 a 和 b，再确定 c 的范围使结果落在 [0, 10]
        $a   = random_int(0, 10);
        $b   = random_int(0, 10 - $a);   // 使 a+b ≤ 10（避免中间值超出）
        $sum = $a + $b;
        // c 的范围：c ≤ sum（结果 ≥ 0）且 sum - c ≤ 10（结果 ≤ 10）
        $cMin = max(0, $sum - 10);
        $cMax = $sum;
        $c      = random_int($cMin, $cMax);
        $answer = $sum - $c;
        return ["$a + $b - $c", $answer];
    } else {
        // 模式 B：a - b + c
        // 先确保 a ≥ b（中间不为负），再确保最终结果 ≤ 10
        $a    = random_int(0, 10);
        $b    = random_int(0, $a);        // 使 a-b ≥ 0
        $diff = $a - $b;
        $cMax = 10 - $diff;               // 使 diff + c ≤ 10
        $c      = random_int(0, $cMax);
        $answer = $diff + $c;
        return ["$a - $b + $c", $answer];
    }
}
