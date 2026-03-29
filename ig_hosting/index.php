<?php
$dir = __DIR__;
$files = array_values(array_filter(glob($dir . '/*.{jpg,jpeg,png}', GLOB_BRACE), 'is_file'));
usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
$images = array_map('basename', $files);
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ig_hosting viewer</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: #111; color: #ccc; font-family: sans-serif; padding: 16px; }
  h1 { font-size: 14px; color: #888; margin-bottom: 16px; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 8px; }
  .item { background: #222; border-radius: 6px; overflow: hidden; cursor: pointer; }
  .item img { width: 100%; aspect-ratio: 4/5; object-fit: cover; display: block; }
  .item .name { font-size: 10px; color: #888; padding: 4px 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .lightbox { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.9); z-index: 100; align-items: center; justify-content: center; flex-direction: column; gap: 12px; }
  .lightbox.active { display: flex; }
  .lightbox img { max-height: 85vh; max-width: 90vw; object-fit: contain; border-radius: 8px; }
  .lightbox .lb-name { font-size: 12px; color: #aaa; }
  .lightbox .close { position: absolute; top: 16px; right: 20px; font-size: 24px; cursor: pointer; color: #fff; }
</style>
</head>
<body>
<h1>ig_hosting — <?= count($images) ?> files</h1>
<div class="grid">
<?php foreach ($images as $name): ?>
  <div class="item" onclick="openLb('<?= htmlspecialchars($name) ?>')">
    <img src="<?= htmlspecialchars($name) ?>" loading="lazy">
    <div class="name"><?= htmlspecialchars($name) ?></div>
  </div>
<?php endforeach; ?>
</div>

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
</script>
</body>
</html>
