<?php
/**
 * igapi.php — IG Scheduler API
 *
 * POST multipart/form-data  : draft/schedule登録（画像アップロード込み）
 * GET ?stage=&account=      : 投稿一覧取得
 *
 * Header: Authorization: Bearer {api_key}
 */

header("Content-Type: application/json; charset=utf-8");

$config   = require __DIR__ . "/../ig_scheduler/config.php";
$data_dir = __DIR__ . "/../ig_scheduler/data";
$hosting_dir = __DIR__ . "/../ig_hosting";
$hosting_url_base = "https://bon-soleil.com/tools/ig_hosting";
$stages = ["draft", "schedule", "posted"];

// ── 認証 ──────────────────────────────────────────────────────
$auth_header = $_SERVER["HTTP_AUTHORIZATION"] ?? "";
if (!preg_match('/^Bearer\s+(\S+)$/i', $auth_header, $m)) {
    http_response_code(401);
    exit(json_encode(["error" => "Authorization header required"]));
}
if (!in_array($m[1], $config["_api_keys"] ?? [], true)) {
    http_response_code(403);
    exit(json_encode(["error" => "Invalid API key"]));
}

// ── GET: 投稿一覧取得 ─────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $stage   = in_array($_GET["stage"] ?? "schedule", $stages) ? ($_GET["stage"] ?? "schedule") : "schedule";
    $account = $_GET["account"] ?? "";

    $data  = json_decode(file_get_contents("$data_dir/$stage.json"), true) ?? ["posts" => []];
    $posts = $data["posts"];

    if ($account) {
        $posts = array_values(array_filter($posts, fn($p) => ($p["account_name"] ?? "") === $account));
    }

    echo json_encode(["ok" => true, "stage" => $stage, "posts" => $posts], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST: draft/schedule登録 ──────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit(json_encode(["error" => "GET or POST only"]));
}

$account_name = trim($_POST["account_name"] ?? "");
$caption      = trim($_POST["caption"] ?? "");
$stage        = in_array($_POST["stage"] ?? "draft", ["draft", "schedule"]) ? ($_POST["stage"] ?? "draft") : "draft";
$scheduled_at = trim($_POST["scheduled_at"] ?? "");

if (!$account_name || !isset($config[$account_name])) {
    http_response_code(400);
    exit(json_encode(["error" => "Invalid account_name: $account_name"]));
}

// 画像アップロード
$allowed = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$post_id = !empty($_POST["post_id"]) ? preg_replace("/[^a-zA-Z0-9_-]/", "", $_POST["post_id"]) : "api_" . time() . "_" . bin2hex(random_bytes(4));
$image_urls = [];

$files = $_FILES["images"] ?? null;
if (!$files || empty($files["name"][0])) {
    http_response_code(400);
    exit(json_encode(["error" => "images[] required"]));
}

$file_count = count($files["name"]);
if ($file_count > 10) {
    http_response_code(400);
    exit(json_encode(["error" => "Max 10 images"]));
}

for ($i = 0; $i < $file_count; $i++) {
    if ($files["error"][$i] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        exit(json_encode(["error" => "Upload error on file $i: " . $files["error"][$i]]));
    }
    $mime = $finfo->file($files["tmp_name"][$i]);
    if (!isset($allowed[$mime])) {
        http_response_code(400);
        exit(json_encode(["error" => "Unsupported format: $mime"]));
    }
    $ext = $allowed[$mime];
    $filename = $post_id . "_" . $i . "." . $ext;
    if (!move_uploaded_file($files["tmp_name"][$i], "$hosting_dir/$filename")) {
        http_response_code(500);
        exit(json_encode(["error" => "Failed to save image $i"]));
    }
    $image_urls[] = "$hosting_url_base/$filename";
}

// JSON更新
$path = "$data_dir/$stage.json";
$data = json_decode(file_get_contents($path), true) ?? ["posts" => []];

$entry = [
    "id"           => $post_id,
    "account_name" => $account_name,
    "caption"      => $caption,
    "image_urls"   => $image_urls,
    "created_at"   => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    "scheduled_at" => ($scheduled_at && strtotime($scheduled_at) > time()) ? $scheduled_at : null,
];

$data["posts"][] = $entry;

usort($data["posts"], function($a, $b) {
    $sa = $a["scheduled_at"] ?? null;
    $sb = $b["scheduled_at"] ?? null;
    if (!$sa && !$sb) return 0;
    if (!$sa) return 1;
    if (!$sb) return -1;
    return strcmp($sa, $sb);
});

file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

http_response_code(201);
echo json_encode([
    "ok"           => true,
    "id"           => $post_id,
    "stage"        => $stage,
    "image_urls"   => $image_urls,
    "scheduled_at" => $entry["scheduled_at"],
], JSON_UNESCAPED_UNICODE);
