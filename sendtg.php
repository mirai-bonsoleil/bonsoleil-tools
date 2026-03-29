<?php
date_default_timezone_set("Asia/Tokyo");
session_start();
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION["csrf_token"];

$bot_token = "8214011535:AAE1yPMs2KpyZaUp2zoB-S1WBpF4KJacw9I";
$chat_id   = "8579868590";
$result = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!hash_equals($csrf, $_POST["csrf_token"] ?? "")) {
        http_response_code(403);
        exit("Forbidden");
    }
    $text = trim($_POST["text"] ?? "");
    if ($text !== "") {
        $ch = curl_init("https://api.telegram.org/bot{$bot_token}/sendMessage");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                "chat_id"    => $chat_id,
                "text"       => $text,
                "parse_mode" => "HTML",
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);
        $result = ($res["ok"] ?? false) ? "sent" : ($res["description"] ?? "error");
    }
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Send Telegram</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: #111; color: #ddd; font-family: sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
.card { background: #1e1e1e; border: 1px solid #2a2a2a; border-radius: 10px; padding: 20px; width: 90%; max-width: 440px; }
h1 { font-size: 15px; color: #aaa; margin-bottom: 14px; }
textarea { width: 100%; background: #111; color: #ddd; border: 1px solid #333; border-radius: 4px; padding: 10px; font-size: 14px; min-height: 120px; resize: vertical; font-family: sans-serif; margin-bottom: 12px; }
button { background: #2a4a5a; color: #7bd; font-size: 14px; padding: 8px 24px; border: none; border-radius: 4px; cursor: pointer; }
.msg { font-size: 12px; margin-top: 10px; padding: 8px; border-radius: 4px; }
.msg-ok { background: #2a4a2a; color: #7c7; }
.msg-err { background: #4a2a2a; color: #c77; }
</style>
</head>
<body>
<div class="card">
  <h1>Send Telegram</h1>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <textarea name="text" placeholder="メッセージを入力..." autofocus></textarea>
    <button type="submit">送信</button>
  </form>
  <?php if ($result === "sent"): ?>
    <div class="msg msg-ok">送信しました</div>
  <?php elseif ($result): ?>
    <div class="msg msg-err">エラー: <?= htmlspecialchars($result) ?></div>
  <?php endif; ?>
</div>
</body>
</html>
