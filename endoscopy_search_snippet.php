<?php
// Add this to the PHP file that runs your cross-table search (the page with the Brain Measurements,
// Clinical Symptom Data and Studies chips). Requires db.php (IMAGE_TABLE, web_path) and atlas_dataset_link.
//
// Why the images did not appear: your search matches the text "ibs" inside each table.
// The HyperKvasir rows contain no IBS text (only Endoscopy / Gastrointestinal tract / HyperKvasir),
// so a plain text match can never return them. The atlas_dataset_link table supplies the connection.

/** Returns ['count' => int, 'rows' => array, 'datasets' => array] for the search term. */
function search_endoscopy_images(PDO $db, string $q, int $limit = 12): array {
    $out = ['count' => 0, 'rows' => [], 'datasets' => []];
    if (trim($q) === '') return $out;

    $st = $db->prepare("SELECT DISTINCT study_id FROM atlas_dataset_link WHERE LOWER(:q) LIKE CONCAT('%', LOWER(keyword), '%')");
    $st->execute([':q' => $q]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) return $out;

    $in = implode(',', array_fill(0, count($ids), '?'));
    $c = $db->prepare('SELECT COUNT(*) FROM `' . IMAGE_TABLE . "` WHERE study_id IN ($in)");
    $c->execute($ids);
    $s = $db->prepare('SELECT image_id, study_id, modality, body_region, image_path, preview_path FROM `' . IMAGE_TABLE . "` WHERE study_id IN ($in) ORDER BY image_id LIMIT " . (int)$limit);
    $s->execute($ids);
    return ['count' => (int)$c->fetchColumn(), 'rows' => $s->fetchAll(), 'datasets' => $ids];
}

/*
USAGE
1) With your other searches:
     $endo = search_endoscopy_images(pdo(), $q);

2) Add a fourth chip next to the existing ones:
     <span class="chip">Endoscopy Images <?= $endo['count'] ?></span>

3) Add a section after the other card groups:
*/
function render_endoscopy_section(array $endo): void {
    if ($endo['count'] === 0) return; ?>
<section>
  <h2>Endoscopy Images <span><?= (int)$endo['count'] ?></span></h2>
  <p>Reference images from <?= htmlspecialchars(implode(', ', $endo['datasets']), ENT_QUOTES, 'UTF-8') ?>.
     They are not from IBS patients or from the participants in the other tables.</p>
  <div class="cards">
  <?php foreach ($endo['rows'] as $r): $src = web_path($r['preview_path'] ?: $r['image_path']); ?>
    <div class="card">
      <?php if ($src): ?><img src="<?= htmlspecialchars($src, ENT_QUOTES, 'UTF-8') ?>" alt="Endoscopy image" loading="lazy" style="width:100%;height:140px;object-fit:cover">
      <?php else: ?><div>Image file not found on server</div><?php endif; ?>
      <div><?= htmlspecialchars($r['modality'], ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($r['body_region'], ENT_QUOTES, 'UTF-8') ?></div>
      <div><?= htmlspecialchars($r['study_id'], ENT_QUOTES, 'UTF-8') ?></div>
    </div>
  <?php endforeach; ?>
  </div>
  <a href="gut_images.php?study=<?= urlencode((string)$endo['datasets'][0]) ?>">View all <?= (int)$endo['count'] ?> →</a>
</section>
<?php }
