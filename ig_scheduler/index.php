<?php
session_start();
$accounts = require __DIR__ . "/config.php";
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION["csrf_token"];

$stages = ["draft", "schedule", "posted"];
$stage  = in_array($_GET["stage"] ?? "", $stages) ? $_GET["stage"] : "schedule";
$data_dir = __DIR__ . "/data";
$move_targets = ["draft" => ["schedule"], "schedule" => ["draft", "posted"], "posted" => ["schedule"]];

// 移動処理
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // CSRF検証
    if (!hash_equals($csrf_token, $_POST["csrf_token"] ?? "")) {
        http_response_code(403);
        exit("Forbidden: invalid CSRF token");
    }

    $action = $_POST["action"] ?? "";
    $id     = preg_match('/^[\w\-\.]{1,100}$/', $_POST["id"] ?? "") ? $_POST["id"] : "";
    $from   = $_POST["from"] ?? "";
    $to     = $_POST["to"] ?? "";

    if ($action === "move" && $id && in_array($from, $stages) && in_array($to, $stages) && in_array($to, $move_targets[$from] ?? [])) {
        $from_raw = file_get_contents("$data_dir/$from.json");
        $to_raw   = file_get_contents("$data_dir/$to.json");
        if ($from_raw === false || $to_raw === false) { http_response_code(500); exit("Data file not found"); }
        $from_data = json_decode($from_raw, true) ?? ["posts" => []];
        $to_data   = json_decode($to_raw, true) ?? ["posts" => []];
        $idx = array_search($id, array_column($from_data["posts"], "id"));
        if ($idx !== false) {
            $post = $from_data["posts"][$idx];
            array_splice($from_data["posts"], $idx, 1);
            if ($to === "posted") {
                $post["posted_at"] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
                array_unshift($to_data["posts"], $post);
            } else {
                $to_data["posts"][] = $post;
            }
            file_put_contents("$data_dir/$from.json", json_encode($from_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            file_put_contents("$data_dir/$to.json",   json_encode($to_data,   JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        header("Location: ?stage=$to");
        exit;
    }

    if ($action === "delete" && $id && in_array($from, $stages)) {
        $raw = file_get_contents("$data_dir/$from.json");
        if ($raw === false) { http_response_code(500); exit("Data file not found"); }
        $data = json_decode($raw, true) ?? ["posts" => []];
        $hosting_dir = __DIR__ . "/../ig_hosting";
        foreach ($data["posts"] as $p) {
            if ($p["id"] === $id) {
                foreach ($p["image_urls"] ?? [] as $url) {
                    $file = $hosting_dir . "/" . basename($url);
                    if (is_file($file)) unlink($file);
                }
                break;
            }
        }
        $data["posts"] = array_values(array_filter($data["posts"], fn($p) => $p["id"] !== $id));
        file_put_contents("$data_dir/$from.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        header("Location: ?stage=$from");
        exit;
    }

    if ($action === "create_draft" && isset($_FILES["image"]) && $_FILES["image"]["error"] === UPLOAD_ERR_OK) {
        $caption = trim($_POST["caption"] ?? "");
        $acct_name = $_POST["account_name"] ?? "";
        if (!$acct_name || !isset($accounts[$acct_name])) {
            header("Location: ?stage=draft&error=" . urlencode("invalid account"));
            exit;
        }

        // Validate image
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES["image"]["tmp_name"]);
        $allowed = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
        if (!isset($allowed[$mime])) {
            header("Location: ?stage=draft&error=" . urlencode("unsupported image format"));
            exit;
        }

        // Generate ID and filename
        $post_id = "draft_" . time();
        $ext = $allowed[$mime];
        $filename = $post_id . "." . $ext;
        $hosting_dir = __DIR__ . "/../ig_hosting";
        $dest = $hosting_dir . "/" . $filename;

        if (!move_uploaded_file($_FILES["image"]["tmp_name"], $dest)) {
            header("Location: ?stage=draft&error=" . urlencode("upload failed"));
            exit;
        }

        // Build image URL
        $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
        $host = $_SERVER["HTTP_HOST"];
        $base = dirname(dirname($_SERVER["SCRIPT_NAME"]));
        $image_url = "$scheme://$host$base/ig_hosting/$filename";

        // Add to draft.json
        $draft_path = "$data_dir/draft.json";
        $draft = json_decode(file_get_contents($draft_path), true) ?? ["posts" => []];
        $draft["posts"][] = [
            "id" => $post_id,
            "account_name" => $acct_name,
            "caption" => $caption,
            "image_urls" => [$image_url],
            "created_at" => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ];
        file_put_contents($draft_path, json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        header("Location: ?stage=draft");
        exit;
    }

    if ($action === "publish" && $id && $from === "schedule") {
        $raw = file_get_contents("$data_dir/schedule.json");
        if ($raw === false) { http_response_code(500); exit("Data file not found"); }
        $sched = json_decode($raw, true) ?? ["posts" => []];
        $post = null;
        $idx = null;
        foreach ($sched["posts"] as $i => $p) {
            if ($p["id"] === $id) { $post = $p; $idx = $i; break; }
        }
        if ($post === null) { header("Location: ?stage=schedule"); exit; }

        $caption = $post["caption"] ?? "";
        $image_url = ($post["image_urls"] ?? [])[0] ?? "";
        if (!$image_url) { header("Location: ?stage=schedule&error=no_image"); exit; }

        $acct_name = $post["account_name"] ?? "";
        if (!$acct_name || !isset($accounts[$acct_name])) {
            header("Location: ?stage=schedule&error=" . urlencode("unknown account: $acct_name"));
            exit;
        }
        $acct = $accounts[$acct_name];
        $ig_id = $acct["ig_account_id"];
        $tk = $acct["access_token"];

        // Step 1: Create media container
        $ch = curl_init("https://graph.instagram.com/v22.0/{$ig_id}/media");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                "image_url"    => $image_url,
                "caption"      => $caption,
                "access_token" => $tk,
            ]),
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);
        $creation_id = $res["id"] ?? null;
        if (!$creation_id) {
            $err = urlencode($res["error"]["message"] ?? "container failed");
            header("Location: ?stage=schedule&error=$err");
            exit;
        }

        // Step 2: Wait for container to be ready
        for ($i = 0; $i < 10; $i++) {
            sleep(2);
            $ch = curl_init("https://graph.instagram.com/v22.0/{$creation_id}?fields=status_code&access_token={$tk}");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $status = json_decode(curl_exec($ch), true);
            curl_close($ch);
            if (($status["status_code"] ?? "") === "FINISHED") break;
            if (($status["status_code"] ?? "") === "ERROR") {
                header("Location: ?stage=schedule&error=" . urlencode("container error"));
                exit;
            }
        }

        // Step 3: Publish
        $ch = curl_init("https://graph.instagram.com/v22.0/{$ig_id}/media_publish");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                "creation_id"  => $creation_id,
                "access_token" => $tk,
            ]),
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);
        if (empty($res["id"])) {
            $err = urlencode($res["error"]["message"] ?? "publish failed");
            header("Location: ?stage=schedule&error=$err");
            exit;
        }

        // Move to posted
        $post["posted_at"] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $post["ig_media_id"] = $res["id"];
        array_splice($sched["posts"], $idx, 1);
        file_put_contents("$data_dir/schedule.json", json_encode($sched, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $posted = json_decode(file_get_contents("$data_dir/posted.json"), true) ?? ["posts" => []];
        array_unshift($posted["posts"], $post);
        file_put_contents("$data_dir/posted.json", json_encode($posted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        header("Location: ?stage=posted");
        exit;
    }
}

$data  = json_decode(file_get_contents("$data_dir/$stage.json"), true);
$posts = $data["posts"] ?? [];
if ($stage === "posted") $posts = array_reverse($posts);

// カウント
$counts = [$stage => count($posts)];
foreach (array_diff($stages, [$stage]) as $s) {
    $d = json_decode(file_get_contents("$data_dir/$s.json"), true);
    $counts[$s] = count($d["posts"] ?? []);
}

$stage_labels = ["draft" => "Draft", "schedule" => "Schedule", "posted" => "Posted"];
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>IG Scheduler</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: #111; color: #ddd; font-family: sans-serif; }
nav { display: flex; gap: 0; border-bottom: 1px solid #333; }
nav a { padding: 12px 20px; text-decoration: none; color: #888; font-size: 14px; position: relative; }
nav a.active { color: #fff; }
nav a.active::after { content: ''; position: absolute; bottom: -1px; left: 0; right: 0; height: 2px; background: #fff; }
nav a .badge { background: #444; color: #ccc; font-size: 11px; padding: 1px 6px; border-radius: 10px; margin-left: 4px; }
nav a.active .badge { background: #555; color: #fff; }
.container { padding: 16px; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
.card { background: #1e1e1e; border-radius: 8px; overflow: hidden; border: 1px solid #2a2a2a; }
.card img { width: 100%; aspect-ratio: 4/5; object-fit: cover; display: block; cursor: pointer; }
.card .body { padding: 10px; }
.card .caption { font-size: 12px; color: #aaa; line-height: 1.5; max-height: 120px; overflow-y: auto; margin-bottom: 8px; white-space: pre-wrap; }
.card .meta { font-size: 11px; color: #666; margin-bottom: 8px; }
.card .actions { display: flex; gap: 6px; flex-wrap: wrap; }
.btn { font-size: 11px; padding: 4px 10px; border: none; border-radius: 4px; cursor: pointer; }
.btn-move { background: #2a4a2a; color: #7c7; }
.btn-delete { background: #4a2a2a; color: #c77; }
.btn-publish { background: #2a3a5a; color: #7ad; }
.empty { color: #555; text-align: center; padding: 60px 0; font-size: 14px; }
.create-form { background: #1e1e1e; border: 1px solid #2a2a2a; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
.create-form h2 { font-size: 14px; color: #aaa; margin-bottom: 12px; }
.create-form label { display: block; font-size: 12px; color: #888; margin-bottom: 4px; }
.create-form select,
.create-form textarea,
.create-form input[type="file"] { width: 100%; background: #111; color: #ddd; border: 1px solid #333; border-radius: 4px; padding: 8px; font-size: 13px; margin-bottom: 12px; font-family: sans-serif; }
.create-form textarea { min-height: 100px; resize: vertical; }
.create-form .preview-img { max-width: 200px; max-height: 250px; border-radius: 6px; margin-bottom: 12px; display: none; }
.btn-create { background: #2a4a2a; color: #7c7; font-size: 13px; padding: 8px 20px; border: none; border-radius: 4px; cursor: pointer; }
.lightbox { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.92); z-index: 100; align-items: center; justify-content: center; }
.lightbox.active { display: flex; }
.lightbox img { max-height: 90vh; max-width: 90vw; object-fit: contain; border-radius: 8px; }
.lightbox .close { position: absolute; top: 16px; right: 20px; font-size: 28px; cursor: pointer; color: #fff; }
</style>
</head>
<body>
<nav>
<?php foreach ($stages as $s): ?>
  <a href="?stage=<?= $s ?>" class="<?= $s === $stage ? 'active' : '' ?>">
    <?= $stage_labels[$s] ?><span class="badge"><?= $counts[$s] ?></span>
  </a>
<?php endforeach; ?>
</nav>
<div class="container">
<?php if (!empty($_GET["error"])): ?>
  <div style="background:#4a2a2a;color:#f99;padding:10px 16px;border-radius:6px;margin-bottom:12px;font-size:13px;">エラー: <?= htmlspecialchars($_GET["error"]) ?></div>
<?php endif; ?>
<?php if ($stage === "draft"): ?>
  <div class="create-form">
    <h2>+ 新規ドラフト</h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
      <input type="hidden" name="action" value="create_draft">
      <label>アカウント</label>
      <select name="account_name" required>
        <?php foreach (array_keys($accounts) as $name): ?>
          <option value="<?= htmlspecialchars($name) ?>">@<?= htmlspecialchars($name) ?></option>
        <?php endforeach; ?>
      </select>
      <label>画像</label>
      <input type="file" name="image" accept="image/jpeg,image/png,image/webp" required onchange="previewFile(this)">
      <img class="preview-img" id="preview">
      <label>キャプション</label>
      <textarea name="caption" placeholder="キャプションを入力..."></textarea>
      <button class="btn-create" type="submit">ドラフト作成</button>
    </form>
  </div>
<?php endif; ?>
<?php if (empty($posts)): ?>
  <div class="empty">投稿なし</div>
<?php else: ?>
  <div class="grid">
  <?php foreach ($posts as $post): ?>
    <?php
      $id  = $post['id'] ?? '';
      $cap = $post['caption'] ?? '';
      $imgs = $post['image_urls'] ?? [];
      $img = is_array($imgs) ? ($imgs[0] ?? '') : $imgs;
      $img = str_replace('/var/www/bizeny/', 'https://bizeny.bon-soleil.com/', $img);
      $posted_at = $post['posted_at'] ?? '';
      $acct_name = $post['account_name'] ?? '';
    ?>
    <div class="card">
      <?php if ($img): ?>
        <img src="<?= htmlspecialchars($img) ?>" onclick="openLb(this.src)" loading="lazy">
      <?php endif; ?>
      <div class="body">
        <?php if ($acct_name): ?><div class="meta">@<?= htmlspecialchars($acct_name) ?></div><?php endif; ?>
        <div class="caption"><?= htmlspecialchars($cap) ?></div>
        <?php if ($posted_at): ?><div class="meta">📅 <?= htmlspecialchars($posted_at) ?></div><?php endif; ?>
        <div class="actions">
          <?php foreach ($move_targets[$stage] as $target): ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
              <input type="hidden" name="action" value="move">
              <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
              <input type="hidden" name="from" value="<?= $stage ?>">
              <input type="hidden" name="to" value="<?= $target ?>">
              <button class="btn btn-move" type="submit"><?= array_search($target, $stages) < array_search($stage, $stages) ? "←" : "→" ?> <?= $stage_labels[$target] ?></button>
            </form>
          <?php endforeach; ?>
          <?php if (true): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('削除しますか？')">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
              <input type="hidden" name="from" value="<?= $stage ?>">
              <button class="btn btn-delete" type="submit">削除</button>
            </form>
          <?php endif; ?>
          <?php if ($stage === "schedule"): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('IGに即時投稿しますか？')">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
              <input type="hidden" name="action" value="publish">
              <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
              <input type="hidden" name="from" value="schedule">
              <button class="btn btn-publish" type="submit">投稿</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>

<div class="lightbox" id="lb" onclick="closeLb()">
  <span class="close">✕</span>
  <img id="lb-img" src="">
</div>
<script>
function openLb(src) { document.getElementById('lb-img').src = src; document.getElementById('lb').classList.add('active'); }
function closeLb() { document.getElementById('lb').classList.remove('active'); }
function previewFile(input) {
  var preview = document.getElementById('preview');
  if (input.files && input.files[0]) {
    var reader = new FileReader();
    reader.onload = function(e) { preview.src = e.target.result; preview.style.display = 'block'; };
    reader.readAsDataURL(input.files[0]);
  } else { preview.style.display = 'none'; }
}
</script>
</body>
</html>
