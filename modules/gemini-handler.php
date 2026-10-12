<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'error' => [
            'message' => 'Bạn chưa đăng nhập.'
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'error' => [
            'message' => 'Phương thức không hợp lệ.'
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawInput = file_get_contents('php://input');
$requestData = json_decode($rawInput, true);

$userText = trim($requestData['text'] ?? '');

if ($userText === '') {
    http_response_code(400);
    echo json_encode([
        'error' => [
            'message' => 'Nội dung câu hỏi đang trống.'
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$apiKey = getenv('GEMINI_API_KEY');

if (!$apiKey) {
    http_response_code(500);
    echo json_encode([
        'error' => [
            'message' => 'Chưa cấu hình GEMINI_API_KEY trên server.'
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . urlencode($apiKey);

$payload = json_encode([
    'contents' => [
        [
            'parts' => [
                [
                    'text' => $userText
                ]
            ]
        ]
    ]
], JSON_UNESCAPED_UNICODE);

$ch = curl_init($apiUrl);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($response === false) {
    $error = curl_error($ch);
    curl_close($ch);

    http_response_code(500);
    echo json_encode([
        'error' => [
            'message' => 'Không gọi được Gemini API: ' . $error
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

curl_close($ch);

http_response_code($httpCode);
echo $response;
