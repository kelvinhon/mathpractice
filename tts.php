<?php
/**
 * tts.php — 粵語文字轉語音（TTS）代理服務
 *
 * 此檔案作為 Google Cloud Text-to-Speech API 的安全代理，
 * 將 API 金鑰保存於伺服器端，避免暴露於前端。
 *
 * ── 設定方法（二選一）──────────────────────────────────────────
 *
 * 方法 A：建立 tts-config.php（與本檔案同目錄）
 *   <?php
 *   define('GOOGLE_TTS_API_KEY', 'your-api-key-here');
 *
 * 方法 B：設定系統環境變數
 *   GOOGLE_TTS_API_KEY=your-api-key-here
 *
 * ── 申請 Google Cloud TTS ───────────────────────────────────────
 *   1. 前往 https://console.cloud.google.com/
 *   2. 建立專案 → 啟用「Cloud Text-to-Speech API」
 *   3. 建立 API 金鑰（Credentials → Create Credentials → API Key）
 *   4. 建議限制金鑰只能呼叫 Cloud Text-to-Speech API
 *   5. 免費方案：每月 100 萬個字元（Neural2 語音另計）
 *
 * ── 未設定 API 金鑰時 ───────────────────────────────────────────
 *   回傳 HTTP 503 和 { useBrowser: true }
 *   前端 script.js 會自動改用瀏覽器內建 Web Speech API（zh-HK）
 *
 * ── 安全措施 ────────────────────────────────────────────────────
 *   · 只接受 POST 請求
 *   · 輸入文字經 strip_tags() 清理，並限制 500 字元
 *   · 回傳 MP3 並快取 1 小時，減少 API 呼叫次數
 */

session_start();

// 安全回應標頭
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// 只接受 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => '不支援該請求方法', 'useBrowser' => true]);
    exit;
}

// 載入設定檔（若存在）
$configFile = __DIR__ . '/tts-config.php';
if (file_exists($configFile)) {
    require $configFile;
}

// 取得 API 金鑰
$apiKey = defined('GOOGLE_TTS_API_KEY')
    ? GOOGLE_TTS_API_KEY
    : (getenv('GOOGLE_TTS_API_KEY') ?: '');

// 未設定 API 金鑰：通知前端改用瀏覽器語音
if (empty($apiKey)) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode([
        'error'      => '雲端語音服務未設定，請使用瀏覽器內建粵語語音',
        'useBrowser' => true,
    ]);
    exit;
}

// 讀取並驗證輸入
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data) || empty($data['text'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => '未提供文字', 'useBrowser' => true]);
    exit;
}

// 清理輸入文字：移除 HTML 標籤，限制 500 字元
$text = mb_substr(strip_tags((string)$data['text']), 0, 500, 'UTF-8');

if (empty(trim($text))) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => '文字為空', 'useBrowser' => true]);
    exit;
}

// 確認伺服器已安裝 cURL 擴充
if (!function_exists('curl_init')) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => '伺服器未啟用 cURL，無法呼叫語音服務', 'useBrowser' => true]);
    exit;
}

/**
 * 呼叫 Google Cloud Text-to-Speech API
 *
 * 語言代碼：yue-Hant-HK（粵語，繁體，香港）
 * 語音性別：FEMALE（女聲，較適合兒童聆聽）
 * 語速：0.85（稍慢，方便兒童理解）
 * 音調：2.0（稍高，親切友善）
 *
 * 更多語音選項（Standard 免費 / Neural2 收費）：
 *   https://cloud.google.com/text-to-speech/docs/voices
 */
$apiUrl  = 'https://texttospeech.googleapis.com/v1/text:synthesize?key=' . urlencode($apiKey);
$payload = [
    'input'       => ['text' => $text],
    'voice'       => [
        'languageCode' => 'yue-Hant-HK',
        'ssmlGender'   => 'FEMALE',
    ],
    'audioConfig' => [
        'audioEncoding' => 'MP3',
        'speakingRate'  => 0.85,
        'pitch'         => 2.0,
    ],
];

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || empty($response)) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode([
        'error'      => '語音 API 呼叫失敗（HTTP ' . $httpCode . '）',
        'useBrowser' => true,
    ]);
    exit;
}

$result = json_decode($response, true);

if (empty($result['audioContent'])) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['error' => '語音 API 回應格式錯誤', 'useBrowser' => true]);
    exit;
}

// 解碼 Base64 音訊並以 MP3 格式回傳
// Cache-Control: 快取 1 小時，相同題目不重複呼叫 API
$audioData = base64_decode($result['audioContent']);

header('Content-Type: audio/mpeg');
header('Content-Length: ' . strlen($audioData));
header('Cache-Control: public, max-age=3600');
echo $audioData;
