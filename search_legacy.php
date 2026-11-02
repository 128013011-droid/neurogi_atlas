<?php
require __DIR__ . '/db.php';
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// 1) Studies from the atlas CSV that match the search (e.g. "IBS")
function csv_rows(string $path): array {
    $rows = [];
    if (!is_readable($path) || ($h = fopen($path, 'r')) === false) return $rows;
    $head = fgetcsv($h, 0, ',', '"', '');
    while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) if (count($r) === count($head)) $rows[] = array_combine($head, $r);
    fclose($h);
    return $rows;
}
$q = trim($_GET['q'] ?? '');
$studies = $q === '' ? [] : array_values(array_filter(csv_rows(__DIR__ . '/data/studies.csv'),
    fn($r) => stripos(implode(' ', $r), $q) !== false));

// 2) Images from the database, for datasets linked to this keyword
$images = []; $linkedIds = []; $total = 0; $error = '';
if ($q !== '') {
    try {
        $db = pdo();
        $st = $db->prepare("SELECT DISTINCT study_id FROM atlas_dataset_link WHERE LOWER(:q) LIKE CONCAT('%', LOWER(keyword), '%')");
        $st->execute([':q' => $q]);
        $linkedIds = $st->fetchAll(PDO::FETCH_COLUMN);
        if ($linkedIds) {
            $in = implode(',', array_fill(0, count($linkedIds), '?'));
            $c = $db->prepare('SELECT COUNT(*) FROM `' . IMAGE_TABLE . "` WHERE study_id IN ($in)");
            $c->execute($linkedIds);
            $total = (int)$c->fetchColumn();
            $s = $db->prepare('SELECT image_id, study_id, participant_id, modality, body_region, image_path, preview_path FROM `' . IMAGE_TABLE . "` WHERE study_id IN ($in) ORDER BY image_id LIMIT 12");
            $s->execute($linkedIds);
            $images = $s->fetchAll();
        }
    } catch (PDOException $ex) {
        $error = 'Could not read the database. Check db.php and that atlas_dataset_link exists (run link_table.sql).';
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Search - NeuroGI Evidence Atlas</title><link rel="stylesheet" href="style.css">
<style>.g{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.8rem}
.c{background:#fff;border:1px solid var(--line);border-radius:8px;padding:.6rem;font-size:.8rem;word-break:break-all}
.c img{width:100%;height:140px;object-fit:cover;border-radius:6px;background:#eaf1f1}
.ph{height:140px;display:flex;align-items:center;justify-content:center;background:#eaf1f1;border-radius:6px;color:var(--mute);text-align:center}</style></head>
<body><header><h1>Search the atlas</h1>
<p class="lede">Search a disorder such as IBS to see matching studies and linked endoscopy images. <a href="index.php">Back to the atlas</a></p></header>
<main>
<form class="f" method="get"><label>Disorder or keyword<input type="search" name="q" value="<?= e($q) ?>" placeholder="IBS"></label>
<div class="actions"><button type="submit">Search</button></div></form>
<?php if ($q !== ''): ?>
  <h2>Studies (<?= count($studies) ?>)</h2>
  <?php if (!$studies): ?><p>No atlas studies match "<?= e($q) ?>".</p><?php else: ?>
  <div class="wrap"><table><tr><th>PMID</th><th>Year</th><th>Disorder</th><th>Design</th><th>Neuroimaging</th><th>Microbiome</th><th>Endoscopy</th></tr>
  <?php foreach ($studies as $r): ?><tr><td><?= e($r['pmid']) ?></td><td><?= e($r['year']) ?></td><td><?= e($r['disorder']) ?></td><td><?= e($r['design']) ?></td><td><?= e($r['neuroimaging']) ?></td><td><?= e($r['microbiome']) ?></td><td><?= e($r['endoscopy'] ?? '') ?></td></tr><?php endforeach; ?>
  </table></div><?php endif; ?>

  <h2>Linked endoscopy images (<?= $total ?>)</h2>
  <?php if ($error): ?><p class="note"><?= e($error) ?></p>
  <?php elseif (!$linkedIds): ?><p>No dataset is linked to "<?= e($q) ?>" yet. Add a row to atlas_dataset_link to link one.</p>
  <?php else: ?>
  <p class="note">Reference images from the public dataset (<?= e(implode(', ', $linkedIds)) ?>). They are not from IBS patients or from the participants in the studies above.</p>
  <div class="g"><?php foreach ($images as $r): $src = web_path($r['preview_path'] ?: $r['image_path']); ?>
  <div class="c"><?php if ($src): ?><img src="<?= e($src) ?>" alt="Endoscopy image <?= e($r['image_id']) ?>" loading="lazy"><?php else: ?><div class="ph">Image file not found on server</div><?php endif; ?>
  <div><strong><?= e($r['modality']) ?></strong>, <?= e($r['body_region']) ?></div><div>Study: <?= e($r['study_id']) ?></div></div>
  <?php endforeach; ?></div>
  <p><a class="btn alt" href="gut_images.php?study=<?= e(urlencode((string)$linkedIds[0])) ?>">See all images</a></p>
  <?php endif; ?>
<?php endif; ?>
</main></body></html>
