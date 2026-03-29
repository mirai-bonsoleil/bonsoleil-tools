<?php
session_start();
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
        $data["posts"] = array_values(array_filter($data["posts"], fn($p) => $p["id"] !== $id));
        file_put_contents("$data_dir/$from.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        header("Location: ?stage=$from");
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
.empty { color: #555; text-align: center; padding: 60px 0; font-size: 14px; }
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
<?php if (empty($posts)): ?>
  <div class="empty">投稿なし</div>
<?php else: ?>
  <div class="grid">
  <?php foreach ($posts as $post): ?>
    <?php
      $id  = $post['id'] ?? '';
      $cap = $post['caption'] ?? '';
      $imgs = $post['image_urls'] ?? $post['images'] ?? [];
      $img = is_array($imgs) ? ($imgs[0] ?? '') : $imgs;
      $img = str_replace('/var/www/bizeny/', 'https://bizeny.bon-soleil.com/', $img);
      $posted_at = $post['posted_at'] ?? '';
    ?>
    <div class="card">
      <?php if ($img): ?>
        <img src="<?= htmlspecialchars($img) ?>" onclick="openLb(this.src)" loading="lazy">
      <?php endif; ?>
      <div class="body">
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
</script>
</body>
</html>
