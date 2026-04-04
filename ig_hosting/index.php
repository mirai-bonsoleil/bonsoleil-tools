<?php
$dir = __DIR__;
$schedulerData = dirname($dir) . '/ig_scheduler/data/';
$trashDir = $dir . '/trash/';

// --- AJAX: trashに移動 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'trash') {
    $filename = basename($_POST['filename'] ?? '');
    $filepath = $dir . '/' . $filename;
    if ($filename && is_file($filepath) && preg_match('/\.(jpg|jpeg|png)$/i', $filename)) {
        if (!is_dir($trashDir)) mkdir($trashDir, 0755, true);
        if (rename($filepath, $trashDir . $filename)) {
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Move failed']);
        }
    } else {
        echo json_encode(['ok' => false, 'error' => 'Invalid file']);
    }
    exit;
}

// --- AJAX: 物理削除 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $filename = basename($_POST['filename'] ?? '');
    $filepath = $trashDir . $filename;
    if ($filename && is_file($filepath) && preg_match('/\.(jpg|jpeg|png)$/i', $filename)) {
        if (unlink($filepath)) {
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Delete failed']);
        }
    } else {
        echo json_encode(['ok' => false, 'error' => 'Invalid file']);
    }
    exit;
}

// --- JSON管理下の画像を収集 ---
$managed = [];
foreach (['draft.json', 'schedule.json', 'posted.json'] as $f) {
    $path = $schedulerData . $f;
    if (!file_exists($path)) continue;
    $data = json_decode(file_get_contents($path), true);
    foreach ($data['posts'] ?? [] as $post) {
        foreach ($post['image_urls'] ?? [] as $url) {
            $managed[] = basename($url);
        }
    }
}
$managed = array_unique($managed);

// --- 画像一覧 ---
$files = array_values(array_filter(glob($dir . '/*.{jpg,jpeg,png}', GLOB_BRACE), 'is_file'));
usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
$images = array_map('basename', $files);

// --- trash内の画像 ---
$trashFiles = is_dir($trashDir) ? array_values(array_filter(glob($trashDir . '*.{jpg,jpeg,png}', GLOB_BRACE), 'is_file')) : [];
usort($trashFiles, fn($a, $b) => filemtime($b) - filemtime($a));
$trashImages = array_map('basename', $trashFiles);
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ig_hosting viewer</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: #111; color: #ccc; font-family: sans-serif; padding: 16px; }
  h1 { font-size: 14px; color: #888; margin-bottom: 8px; }
  h2 { font-size: 13px; color: #666; margin: 20px 0 8px; }
  .stats { font-size: 11px; color: #555; margin-bottom: 16px; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 8px; }
  .item { background: #222; border-radius: 6px; overflow: hidden; position: relative; }
  .item img { width: 100%; aspect-ratio: 4/5; object-fit: cover; display: block; cursor: pointer; }
  .item .name { font-size: 10px; color: #888; padding: 4px 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .item.unmanaged { border: 1px solid #553333; }
  .item .trash-btn, .item .delete-btn { position: absolute; top: 4px; right: 4px; background: rgba(180,40,40,0.85); color: #fff; border: none; border-radius: 50%; width: 24px; height: 24px; font-size: 14px; cursor: pointer; display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s; }
  .item:hover .trash-btn, .item:hover .delete-btn { opacity: 1; }
  .item .badge { position: absolute; top: 4px; left: 4px; font-size: 9px; padding: 2px 6px; border-radius: 3px; }
  .badge.managed { background: #2a4a2a; color: #6c6; }
  .badge.unmanaged { background: #4a2a2a; color: #c66; }
  .lightbox { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.9); z-index: 100; align-items: center; justify-content: center; flex-direction: column; gap: 12px; }
  .lightbox.active { display: flex; }
  .lightbox img { max-height: 85vh; max-width: 90vw; object-fit: contain; border-radius: 8px; }
  .lightbox .lb-name { font-size: 12px; color: #aaa; }
  .lightbox .close { position: absolute; top: 16px; right: 20px; font-size: 24px; cursor: pointer; color: #fff; }
  .section-trash { opacity: 0.5; margin-top: 32px; }
  .section-trash h2 { color: #844; }
</style>
</head>
<body>
<div style="display:flex;justify-content:space-between;align-items:center;"><h1>ig_hosting — <?= count($images) ?> files</h1><a href="../ig_scheduler/" style="font-size:12px;color:#888;text-decoration:none;">ig_scheduler →</a></div>
<div class="stats">managed: <?= count(array_intersect($images, $managed)) ?> / unmanaged: <?= count(array_diff($images, $managed)) ?></div>

<div class="grid">
<?php foreach ($images as $name):
    $isManaged = in_array($name, $managed);
?>
  <div class="item <?= $isManaged ? '' : 'unmanaged' ?>">
    <img src="<?= htmlspecialchars($name) ?>" loading="lazy" onclick="openLb('<?= htmlspecialchars($name) ?>')">
    <div class="name"><?= htmlspecialchars($name) ?></div>
    <?php if ($isManaged): ?>
      <span class="badge managed">managed</span>
    <?php else: ?>
      <span class="badge unmanaged">unmanaged</span>
      <button class="trash-btn" onclick="trashFile('<?= htmlspecialchars($name) ?>', this)" title="trashに移動">✕</button>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>

<?php if (!empty($trashImages)): ?>
<div class="section-trash">
  <h2>trash — <?= count($trashImages) ?> files</h2>
  <div class="grid">
  <?php foreach ($trashImages as $name): ?>
    <div class="item">
      <img src="trash/<?= htmlspecialchars($name) ?>" loading="lazy" onclick="openLb('trash/<?= htmlspecialchars($name) ?>')">
      <div class="name"><?= htmlspecialchars($name) ?></div>
      <button class="delete-btn" onclick="deleteFile('<?= htmlspecialchars($name) ?>', this)" title="完全に削除">✕</button>
    </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="lightbox" id="lb">
  <span class="close" onclick="closeLb()">✕</span>
  <img id="lb-img" src="">
  <div class="lb-name" id="lb-name"></div>
</div>

<script>
function openLb(name) {
  document.getElementById('lb-img').src = name;
  document.getElementById('lb-name').textContent = name;
  document.getElementById('lb').classList.add('active');
}
function closeLb() {
  document.getElementById('lb').classList.remove('active');
}
document.getElementById('lb').addEventListener('click', e => {
  if (e.target === document.getElementById('lb')) closeLb();
});

function trashFile(filename, btn) {
  if (!confirm(filename + ' をtrashに移動しますか？')) return;
  const item = btn.closest('.item');
  fetch('', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'action=trash&filename=' + encodeURIComponent(filename)
  })
  .then(r => r.json())
  .then(data => {
    if (data.ok) {
      item.style.transition = 'opacity 0.3s';
      item.style.opacity = '0';
      setTimeout(() => location.reload(), 300);
    } else {
      alert('Error: ' + (data.error || 'unknown'));
    }
  })
  .catch(e => alert('Error: ' + e));
}

function deleteFile(filename, btn) {
  if (!confirm(filename + ' を完全に削除しますか？\nこの操作は取り消せません。')) return;
  const item = btn.closest('.item');
  fetch('', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'action=delete&filename=' + encodeURIComponent(filename)
  })
  .then(r => r.json())
  .then(data => {
    if (data.ok) {
      item.style.transition = 'opacity 0.3s';
      item.style.opacity = '0';
      setTimeout(() => location.reload(), 300);
    } else {
      alert('Error: ' + (data.error || 'unknown'));
    }
  })
  .catch(e => alert('Error: ' + e));
}
</script>
</body>
</html>
