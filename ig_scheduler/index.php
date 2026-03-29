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

    if ($action === "edit" && $id && in_array($from, ["draft", "schedule"])) {
        $raw = file_get_contents("$data_dir/$from.json");
        if ($raw === false) { http_response_code(500); exit("Data file not found"); }
        $data = json_decode($raw, true) ?? ["posts" => []];
        foreach ($data["posts"] as &$p) {
            if ($p["id"] === $id) {
                $p["caption"] = trim($_POST["caption"] ?? $p["caption"]);
                $sa = trim($_POST["scheduled_at"] ?? "");
                $p["scheduled_at"] = ($sa && strtotime($sa) > time()) ? $sa : ($p["scheduled_at"] ?? null);
                break;
            }
        }
        unset($p);
        file_put_contents("$data_dir/$from.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        header("Location: ?stage=$from");
        exit;
    }

    if ($action === "create_draft" && isset($_FILES["images"])) {
        $caption = trim($_POST["caption"] ?? "");
        $acct_name = $_POST["account_name"] ?? "";
        if (!$acct_name || !isset($accounts[$acct_name])) {
            header("Location: ?stage=draft&error=" . urlencode("invalid account"));
            exit;
        }

        $allowed = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $hosting_dir = __DIR__ . "/../ig_hosting";
        $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
        $host = $_SERVER["HTTP_HOST"];
        $base = dirname(dirname($_SERVER["SCRIPT_NAME"]));
        $post_id = "draft_" . time();
        $image_urls = [];

        $file_count = count($_FILES["images"]["name"]);
        if ($file_count < 1 || $_FILES["images"]["error"][0] !== UPLOAD_ERR_OK) {
            header("Location: ?stage=draft&error=" . urlencode("no image selected"));
            exit;
        }
        if ($file_count > 10) {
            header("Location: ?stage=draft&error=" . urlencode("max 10 images"));
            exit;
        }

        for ($i = 0; $i < $file_count; $i++) {
            if ($_FILES["images"]["error"][$i] !== UPLOAD_ERR_OK) continue;
            $mime = $finfo->file($_FILES["images"]["tmp_name"][$i]);
            if (!isset($allowed[$mime])) {
                header("Location: ?stage=draft&error=" . urlencode("unsupported format: " . $_FILES["images"]["name"][$i]));
                exit;
            }
            $ext = $allowed[$mime];
            $filename = $post_id . "_" . $i . "." . $ext;
            if (!move_uploaded_file($_FILES["images"]["tmp_name"][$i], "$hosting_dir/$filename")) {
                header("Location: ?stage=draft&error=" . urlencode("upload failed"));
                exit;
            }
            $image_urls[] = "$scheme://$host$base/ig_hosting/$filename";
        }

        $draft_path = "$data_dir/draft.json";
        $draft = json_decode(file_get_contents($draft_path), true) ?? ["posts" => []];
        $draft["posts"][] = [
            "id" => $post_id,
            "account_name" => $acct_name,
            "caption" => $caption,
            "image_urls" => $image_urls,
            "created_at" => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            "scheduled_at" => (($sa = trim($_POST["scheduled_at"] ?? "")) && strtotime($sa) > time()) ? $sa : null,
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
        $image_urls = $post["image_urls"] ?? [];
        if (empty($image_urls)) { header("Location: ?stage=schedule&error=no_image"); exit; }

        $acct_name = $post["account_name"] ?? "";
        if (!$acct_name || !isset($accounts[$acct_name])) {
            header("Location: ?stage=schedule&error=" . urlencode("unknown account: $acct_name"));
            exit;
        }
        $acct = $accounts[$acct_name];
        $ig_id = $acct["ig_account_id"];
        $tk = $acct["access_token"];
        $is_carousel = count($image_urls) > 1;

        if ($is_carousel) {
            // Carousel: create child containers
            $children = [];
            foreach ($image_urls as $img_url) {
                $ch = curl_init("https://graph.instagram.com/v22.0/{$ig_id}/media");
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query([
                        "image_url"      => $img_url,
                        "is_carousel_item" => "true",
                        "access_token"   => $tk,
                    ]),
                    CURLOPT_RETURNTRANSFER => true,
                ]);
                $res = json_decode(curl_exec($ch), true);
                curl_close($ch);
                if (empty($res["id"])) {
                    $err = urlencode($res["error"]["message"] ?? "child container failed");
                    header("Location: ?stage=schedule&error=$err");
                    exit;
                }
                $children[] = $res["id"];
            }

            // Wait for all children
            foreach ($children as $child_id) {
                for ($i = 0; $i < 10; $i++) {
                    sleep(2);
                    $ch = curl_init("https://graph.instagram.com/v22.0/{$child_id}?fields=status_code&access_token={$tk}");
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $status = json_decode(curl_exec($ch), true);
                    curl_close($ch);
                    if (($status["status_code"] ?? "") === "FINISHED") break;
                    if (($status["status_code"] ?? "") === "ERROR") {
                        header("Location: ?stage=schedule&error=" . urlencode("child container error"));
                        exit;
                    }
                }
            }

            // Create carousel container
            $ch = curl_init("https://graph.instagram.com/v22.0/{$ig_id}/media");
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    "media_type"   => "CAROUSEL",
                    "children"     => implode(",", $children),
                    "caption"      => $caption,
                    "access_token" => $tk,
                ]),
                CURLOPT_RETURNTRANSFER => true,
            ]);
            $res = json_decode(curl_exec($ch), true);
            curl_close($ch);
            $creation_id = $res["id"] ?? null;
        } else {
            // Single image
            $ch = curl_init("https://graph.instagram.com/v22.0/{$ig_id}/media");
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    "image_url"    => $image_urls[0],
                    "caption"      => $caption,
                    "access_token" => $tk,
                ]),
                CURLOPT_RETURNTRANSFER => true,
            ]);
            $res = json_decode(curl_exec($ch), true);
            curl_close($ch);
            $creation_id = $res["id"] ?? null;
        }

        if (!$creation_id) {
            $err = urlencode($res["error"]["message"] ?? "container failed");
            header("Location: ?stage=schedule&error=$err");
            exit;
        }

        // Wait for container to be ready
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

        // Publish
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

        // Get permalink
        $media_id = $res["id"];
        $ch = curl_init("https://graph.instagram.com/v22.0/{$media_id}?fields=permalink&access_token={$tk}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $pres = json_decode(curl_exec($ch), true);
        curl_close($ch);

        // Move to posted
        $post["posted_at"] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $post["ig_media_id"] = $media_id;
        $post["permalink"] = $pres["permalink"] ?? "";
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
.btn-edit { background: #3a3a2a; color: #cc7; }
.edit-form { display: none; padding: 8px 0 0; }
.edit-form.active { display: block; }
.edit-form textarea { width: 100%; background: #111; color: #ddd; border: 1px solid #333; border-radius: 4px; padding: 6px; font-size: 12px; min-height: 60px; resize: vertical; font-family: sans-serif; margin-bottom: 6px; }
.edit-form input[type="datetime-local"] { width: 100%; background: #111; color: #ddd; border: 1px solid #333; border-radius: 4px; padding: 6px; font-size: 12px; margin-bottom: 6px; color-scheme: dark; }
.edit-form .btn-save { background: #2a4a2a; color: #7c7; font-size: 11px; padding: 4px 12px; border: none; border-radius: 4px; cursor: pointer; }
.empty { color: #555; text-align: center; padding: 60px 0; font-size: 14px; }
.btn-new-draft { background: #2a4a2a; color: #7c7; font-size: 13px; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; margin-bottom: 12px; }
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.8); z-index: 200; align-items: center; justify-content: center; }
.modal-overlay.active { display: flex; }
.modal { background: #1e1e1e; border: 1px solid #333; border-radius: 10px; padding: 20px; width: 90%; max-width: 480px; max-height: 90vh; overflow-y: auto; position: relative; }
.modal h2 { font-size: 15px; color: #ccc; margin-bottom: 14px; }
.modal .close { position: absolute; top: 12px; right: 16px; font-size: 22px; cursor: pointer; color: #888; }
.modal label { display: block; font-size: 12px; color: #888; margin-bottom: 4px; }
.modal select,
.modal textarea,
.modal input[type="file"] { width: 100%; background: #111; color: #ddd; border: 1px solid #333; border-radius: 4px; padding: 8px; font-size: 13px; margin-bottom: 12px; font-family: sans-serif; }
.modal textarea { min-height: 100px; resize: vertical; }
.btn-create { background: #2a4a2a; color: #7c7; font-size: 13px; padding: 8px 20px; border: none; border-radius: 4px; cursor: pointer; }
.thumbs { display: flex; gap: 4px; padding: 4px 6px; }
.thumbs img { width: 40px; height: 40px; object-fit: cover; border-radius: 4px; opacity: 0.7; cursor: pointer; }
.thumbs img:first-child { opacity: 1; }
.thumbs .more { width: 40px; height: 40px; background: #333; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 11px; color: #aaa; }
.lightbox { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.92); z-index: 100; align-items: center; justify-content: center; flex-direction: column; gap: 8px; }
.lightbox.active { display: flex; }
.lightbox img { max-height: 85vh; max-width: 90vw; object-fit: contain; border-radius: 8px; }
.lightbox .close { position: absolute; top: 16px; right: 20px; font-size: 28px; cursor: pointer; color: #fff; z-index: 101; }
.lb-nav { position: absolute; top: 50%; transform: translateY(-50%); font-size: 36px; color: #fff; cursor: pointer; user-select: none; padding: 20px; opacity: 0.7; }
.lb-nav:hover { opacity: 1; }
.lb-prev { left: 10px; }
.lb-next { right: 10px; }
.lb-counter { font-size: 12px; color: #888; }
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
  <button class="btn-new-draft" onclick="openModal()">+ 新規Draft</button>
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
      $permalink = $post['permalink'] ?? '';
      $scheduled_at = $post['scheduled_at'] ?? '';
    ?>
    <?php $imgs_json = htmlspecialchars(json_encode($imgs), ENT_QUOTES); ?>
    <div class="card">
      <?php if ($img): ?>
        <div style="position:relative">
          <img src="<?= htmlspecialchars($img) ?>" onclick='openLb(<?= $imgs_json ?>, 0)' loading="lazy">
          <?php if (count($imgs) > 1): ?>
            <span style="position:absolute;top:6px;right:6px;background:rgba(0,0,0,.7);color:#fff;font-size:10px;padding:2px 6px;border-radius:10px;"><?= count($imgs) ?>枚</span>
          <?php endif; ?>
        </div>
        <?php if (count($imgs) > 1): ?>
        <div class="thumbs">
          <?php foreach (array_slice($imgs, 0, 3) as $ti => $thumb): ?>
            <img src="<?= htmlspecialchars($thumb) ?>" onclick='openLb(<?= $imgs_json ?>, <?= $ti ?>)' loading="lazy">
          <?php endforeach; ?>
          <?php if (count($imgs) > 3): ?>
            <div class="more" onclick='openLb(<?= $imgs_json ?>, 3)'>+<?= count($imgs) - 3 ?></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>
      <div class="body">
        <?php if ($acct_name): ?><div class="meta">@<?= htmlspecialchars($acct_name) ?></div><?php endif; ?>
        <div class="caption"><?= htmlspecialchars($cap) ?></div>
        <?php if ($scheduled_at): ?><div class="meta" style="color:#d9a;">🕐 <?= htmlspecialchars($scheduled_at) ?></div><?php endif; ?>
        <?php if ($posted_at): ?><div class="meta">📅 <?= htmlspecialchars($posted_at) ?><?php if ($permalink): ?> · <a href="<?= htmlspecialchars($permalink) ?>" target="_blank" style="color:#7ad;text-decoration:none;">IG↗</a><?php endif; ?></div><?php endif; ?>
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
          <?php if ($stage !== "posted"): ?>
            <button class="btn btn-edit" type="button" onclick="toggleEdit('ef-<?= htmlspecialchars($id) ?>')">編集</button>
          <?php endif; ?>
        </div>
        <?php if ($stage !== "posted"): ?>
        <div class="edit-form" id="ef-<?= htmlspecialchars($id) ?>">
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
            <input type="hidden" name="from" value="<?= $stage ?>">
            <textarea name="caption"><?= htmlspecialchars($cap) ?></textarea>
            <input type="datetime-local" name="scheduled_at" value="<?= htmlspecialchars($scheduled_at) ?>">
            <button class="btn-save" type="submit">保存</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>

<div class="modal-overlay" id="draft-modal" onclick="if(event.target===this)closeModal()">
  <div class="modal">
    <span class="close" onclick="closeModal()">✕</span>
    <h2>+ 新規Draft</h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
      <input type="hidden" name="action" value="create_draft">
      <label>アカウント</label>
      <select name="account_name" required>
        <?php foreach (array_keys($accounts) as $name): ?>
          <option value="<?= htmlspecialchars($name) ?>">@<?= htmlspecialchars($name) ?></option>
        <?php endforeach; ?>
      </select>
      <label>画像（複数選択可・最大10枚）</label>
      <input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" required multiple onchange="previewFiles(this)">
      <div id="previews" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;"></div>
      <label>キャプション</label>
      <textarea name="caption" placeholder="キャプションを入力..."></textarea>
      <label>送信予定日時（任意）</label>
      <input type="datetime-local" name="scheduled_at" id="scheduled_at" min="" style="width:100%;background:#111;color:#ddd;border:1px solid #333;border-radius:4px;padding:8px;font-size:13px;margin-bottom:12px;color-scheme:dark;">
      <button class="btn-create" type="submit">ドラフト作成</button>
    </form>
  </div>
</div>

<div class="lightbox" id="lb" onclick="if(event.target===this)closeLb()">
  <span class="close" onclick="closeLb()">✕</span>
  <span class="lb-nav lb-prev" onclick="lbNav(-1)">‹</span>
  <img id="lb-img" src="">
  <span class="lb-nav lb-next" onclick="lbNav(1)">›</span>
  <div class="lb-counter" id="lb-counter"></div>
</div>
<script>
var lbImages = [], lbIdx = 0;
function openLb(imgs, idx) {
  lbImages = Array.isArray(imgs) ? imgs : [imgs];
  lbIdx = idx || 0;
  lbShow();
  document.getElementById('lb').classList.add('active');
}
function closeLb() { document.getElementById('lb').classList.remove('active'); }
function lbNav(dir) {
  lbIdx = (lbIdx + dir + lbImages.length) % lbImages.length;
  lbShow();
}
function lbShow() {
  document.getElementById('lb-img').src = lbImages[lbIdx];
  var counter = document.getElementById('lb-counter');
  counter.textContent = lbImages.length > 1 ? (lbIdx + 1) + ' / ' + lbImages.length : '';
  document.querySelector('.lb-prev').style.display = lbImages.length > 1 ? '' : 'none';
  document.querySelector('.lb-next').style.display = lbImages.length > 1 ? '' : 'none';
}
document.addEventListener('keydown', function(e) {
  if (!document.getElementById('lb').classList.contains('active')) return;
  if (e.key === 'ArrowLeft') lbNav(-1);
  if (e.key === 'ArrowRight') lbNav(1);
  if (e.key === 'Escape') closeLb();
});
function previewFiles(input) {
  var container = document.getElementById('previews');
  container.innerHTML = '';
  if (!input.files) return;
  Array.from(input.files).forEach(function(file) {
    var reader = new FileReader();
    reader.onload = function(e) {
      var img = document.createElement('img');
      img.src = e.target.result;
      img.style.cssText = 'max-width:120px;max-height:150px;border-radius:6px;object-fit:cover;';
      container.appendChild(img);
    };
    reader.readAsDataURL(file);
  });
}
function openModal() { document.getElementById('draft-modal').classList.add('active'); updateScheduleMin(); }
function closeModal() { document.getElementById('draft-modal').classList.remove('active'); }
function toggleEdit(id) {
  document.getElementById(id).classList.toggle('active');
}
function updateScheduleMin() {
  var sa = document.getElementById('scheduled_at');
  if (sa) {
    var now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    sa.min = now.toISOString().slice(0, 16);
  }
}
</script>
</body>
</html>
