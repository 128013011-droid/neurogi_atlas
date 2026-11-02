<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$pageTitle = 'Endoscopy Reference Benchmark (Kvasir-SEG & HyperKvasir) | NeuroGI Atlas';
$activeNav = 'gut_images';

$db = pdo();

$mode  = trim((string)($_GET['mode'] ?? 'local_masks'));
if (!in_array($mode, ['local_masks', 'staging'], true)) {
    $mode = 'local_masks';
}
$q     = trim((string)($_GET['q'] ?? ''));
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 24;
$offset = ($page - 1) * $limit;

if ($mode === 'local_masks') {
    $where = "WHERE 1=1";
    $params = [];
    if ($q !== '') {
        $where .= " AND (image_id LIKE :q OR original_label LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }
    $stCnt = $db->prepare("SELECT COUNT(*) FROM kvasir_seg_local_assets $where");
    $stCnt->execute($params);
    $totalImages = (int)$stCnt->fetchColumn();

    $stRows = $db->prepare("SELECT * FROM kvasir_seg_local_assets $where ORDER BY image_id ASC LIMIT :lim OFFSET :off");
    foreach ($params as $k => $v) {
        $stRows->bindValue($k, $v);
    }
    $stRows->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stRows->bindValue(':off', $offset, PDO::PARAM_INT);
    $stRows->execute();
    $images = $stRows->fetchAll();
} else {
    $where = "WHERE 1=1";
    $params = [];
    if ($q !== '') {
        $where .= " AND (image_id LIKE :q OR body_region LIKE :q OR bbox_validation LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }
    $stCnt = $db->prepare("SELECT COUNT(*) FROM hyperkvasir_import_staging $where");
    $stCnt->execute($params);
    $totalImages = (int)$stCnt->fetchColumn();

    $stRows = $db->prepare("SELECT * FROM hyperkvasir_import_staging $where ORDER BY image_id ASC LIMIT :lim OFFSET :off");
    foreach ($params as $k => $v) {
        $stRows->bindValue($k, $v);
    }
    $stRows->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stRows->bindValue(':off', $offset, PDO::PARAM_INT);
    $stRows->execute();
    $images = $stRows->fetchAll();
}

$totalPages = max(1, (int)ceil($totalImages / $limit));

require_once __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="page-hero-inner">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:1rem;">
      <div style="max-width:820px;">
        <div class="hero-badge" style="margin-bottom:0.6rem;">PHASE 8 &bull; ENDOSCOPY ASSET HONESTY &amp; NON-IBS REFERENCE SCOPE</div>
        <h2>Gastrointestinal Endoscopy Reference Benchmark</h2>
        <p>
          Explore <strong>1,000 locally stored Kvasir-SEG binary segmentation masks &amp; bounding-box annotations</strong> (<code>images/masks/*.jpg</code> + <code>kavsir_bboxes.json</code>) alongside <strong>1,000 staged HyperKvasir metadata records</strong>.
        </p>
      </div>
      <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
        <a href="gut_images.php?mode=local_masks" class="btn <?= $mode === 'local_masks' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
          1. Local Kvasir-SEG Masks (1,000 Files Present)
        </a>
        <a href="gut_images.php?mode=staging" class="btn <?= $mode === 'staging' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
          2. HyperKvasir Colab Staging Table (1,000 Rows)
        </a>
      </div>
    </div>
  </div>
</section>

<div class="container">

  <!-- Non-IBS Reference Scope & Asset Audit Banner -->
  <div class="prov-banner">
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:1rem;">
      <div>
        <strong>Clinical &amp; Scientific Scope Boundary:</strong><br>
        Irritable Bowel Syndrome (IBS) is a disorder of gut–brain interaction (DGBI) characterized by macroscopically normal colonic mucosa on routine endoscopy.
        The <strong>Kvasir-SEG</strong> (Jha et al., 2020) and <strong>HyperKvasir</strong> (Borgli et al., 2020) datasets from Simula Research Laboratory are <strong>colorectal polyp segmentation benchmarks</strong> and <strong>do NOT originate from IBS cohort participants</strong>.
      </div>
      <div>
        <strong>Local vs. Staged File Audit:</strong><br>
        &bull; <code>kvasir_seg_local_assets</code>: <strong>1,000 real `.jpg` segmentation masks</strong> are physically present in <code>images/masks/</code> with verified bounding boxes from <code>kavsir_bboxes.json</code>.<br>
        &bull; <code>hyperkvasir_import_staging</code>: <strong>1,000 metadata rows</strong> (999 unique IDs + 1 duplicate row <code>0004a718-526c-41c2-80e6-522750c3ee8b</code>) referencing external Google Colab paths (<code>/content/hyperkvasir_gut_images/...</code>) without local raw RGB frames.
      </div>
      <div>
        <strong>Licence &amp; Attribution:</strong><br>
        Creative Commons Attribution 4.0 International (CC-BY 4.0), Simula Research Laboratory / University of Oslo.<br>
        <strong>Participant Linkage:</strong> Marked <code>Not Applicable (Reference Benchmark Only)</code> — never linked to Bergen BGA or Mars GSE146853 subjects.
      </div>
    </div>
  </div>

  <!-- Search / Filter Bar -->
  <div class="table-card" style="padding:1rem 1.25rem; margin-bottom:1.5rem;">
    <form method="get" action="gut_images.php" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
      <input type="hidden" name="mode" value="<?= h($mode) ?>">
      <div style="display:flex; gap:0.5rem; flex:1; min-width:260px;">
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search by image UUID (e.g. cju0qkwl35piu0993l0dewei2 or 0004a718)..." class="form-input" style="max-width:420px;">
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <?php if ($q !== ''): ?>
          <a href="gut_images.php?mode=<?= urlencode($mode) ?>" class="btn btn-outline btn-sm">Clear</a>
        <?php endif; ?>
      </div>
      <div style="font-size:0.82rem; color:var(--navy-subtle);">
        Showing <strong><?= number_format($totalImages) ?></strong> records in <code><?= $mode === 'local_masks' ? 'kvasir_seg_local_assets' : 'hyperkvasir_import_staging' ?></code> (Page <?= $page ?> of <?= $totalPages ?>)
      </div>
    </form>
  </div>

  <!-- Image Grid -->
  <div class="table-card" style="margin-bottom:2rem;">
    <div class="table-card-header">
      <h3>
        <?= $mode === 'local_masks' ? 'Verified Local Kvasir-SEG Segmentation Masks & Bounding Boxes' : 'HyperKvasir Metadata Staging Table (External Colab Paths)' ?>
      </h3>
      <span class="badge <?= $mode === 'local_masks' ? 'badge-green' : 'badge-amber' ?>">
        <?= $mode === 'local_masks' ? 'Local Binary Masks Verified in images/masks/' : 'Metadata Only (Raw RGB Not Stored Locally)' ?>
      </span>
    </div>

    <div style="padding:1.25rem;">
      <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(265px, 1fr)); gap:1.25rem; margin-bottom:1.75rem;">
        <?php foreach ($images as $img): ?>
          <?php if ($mode === 'local_masks'): ?>
            <?php
              $maskUrl = web_path($img['local_mask_path']);
              $bboxes = json_decode((string)$img['bbox_json'], true) ?: [];
              $w = max(1, (int)$img['image_width']);
              $h = max(1, (int)$img['image_height']);
            ?>
            <div style="background:#ffffff; border:1px solid var(--border-light); border-radius:var(--radius-md); padding:0.85rem; box-shadow:var(--shadow-sm); display:flex; flex-direction:column;">
              <div style="position:relative; width:100%; height:165px; background:#0f172a; border-radius:var(--radius-sm); overflow:hidden; margin-bottom:0.75rem;">
                <?php if ($maskUrl): ?>
                  <img src="<?= h($maskUrl) ?>" alt="Kvasir-SEG Mask <?= h($img['image_id']) ?>" style="width:100%; height:100%; object-fit:contain;">
                  <!-- Overlay SVG Bounding Box -->
                  <svg viewBox="0 0 <?= $w ?> <?= $h ?>" preserveAspectRatio="xMidYMid meet" style="position:absolute; inset:0; width:100%; height:100%; pointer-events:none;">
                    <?php foreach ($bboxes as $bb): ?>
                      <?php
                        $xmin = (int)($bb['xmin'] ?? 0);
                        $ymin = (int)($bb['ymin'] ?? 0);
                        $bw   = max(1, (int)($bb['xmax'] ?? 0) - $xmin);
                        $bh   = max(1, (int)($bb['ymax'] ?? 0) - $ymin);
                      ?>
                      <rect x="<?= $xmin ?>" y="<?= $ymin ?>" width="<?= $bw ?>" height="<?= $bh ?>" fill="none" stroke="#14b8a6" stroke-width="<?= max(3, round($w / 140)) ?>"/>
                    <?php endforeach; ?>
                  </svg>
                <?php else: ?>
                  <div style="color:#94a3b8; font-size:0.8rem; padding:2rem; text-align:center;">Mask missing</div>
                <?php endif; ?>
              </div>

              <div style="font-size:0.82rem; font-weight:700; color:var(--navy-dark); margin-bottom:0.25rem; word-break:break-all;">
                <code><?= h($img['image_id']) ?></code>
              </div>
              <div style="font-size:0.76rem; color:var(--navy-subtle); margin-bottom:0.5rem;">
                <span class="badge badge-amber">Reference Only (Non-IBS)</span>
                <span class="badge badge-teal"><?= (int)$img['bbox_count'] ?> BBox<?= (int)$img['bbox_count'] > 1 ? 'es' : '' ?></span>
              </div>
              <div style="font-size:0.75rem; color:var(--navy-muted); border-top:1px solid var(--border-light); padding-top:0.5rem; margin-top:auto;">
                Dimensions: <strong><?= $w ?> &times; <?= $h ?> px</strong><br>
                Label: <code><?= h($img['original_label']) ?></code> (Colorectal)<br>
                <?php if (!empty($bboxes[0])): ?>
                  BBox 1: <code>[<?= (int)$bboxes[0]['xmin'] ?>, <?= (int)$bboxes[0]['ymin'] ?> &rarr; <?= (int)$bboxes[0]['xmax'] ?>, <?= (int)$bboxes[0]['ymax'] ?>]</code>
                <?php endif; ?>
              </div>
            </div>

          <?php else: ?>
            <?php
              $localSrc = web_path($img['preview_path'] ?: $img['image_path']);
              $isDup = ($img['image_id'] === '0004a718-526c-41c2-80e6-522750c3ee8b');
            ?>
            <div style="background:#ffffff; border:1px solid <?= $isDup ? '#fda4af' : 'var(--border-light)' ?>; border-radius:var(--radius-md); padding:0.85rem; box-shadow:var(--shadow-sm); display:flex; flex-direction:column;">
              <div style="width:100%; height:130px; background:#f1f5f9; border-radius:var(--radius-sm); display:flex; flex-direction:column; align-items:center; justify-content:center; gap:0.3rem; color:var(--navy-subtle); margin-bottom:0.75rem; border:1px dashed var(--border-card); padding:0.5rem; text-align:center;">
                <span style="font-size:0.75rem; font-weight:700; color:var(--navy-dark);">External Colab Staging Record</span>
                <code style="font-size:0.66rem; word-break:break-all;"><?= h((string)$img['image_path']) ?></code>
                <span class="badge badge-navy" style="font-size:0.65rem;">Raw RGB Frame Not Stored Locally</span>
              </div>
              <div style="font-size:0.82rem; font-weight:700; color:var(--navy-dark); margin-bottom:0.25rem; word-break:break-all;">
                <code><?= h((string)$img['image_id']) ?></code>
                <?php if ($isDup): ?>
                  <span class="badge badge-rose">Duplicate Staging Row</span>
                <?php endif; ?>
              </div>
              <div style="font-size:0.75rem; color:var(--navy-muted); border-top:1px solid var(--border-light); padding-top:0.5rem; margin-top:auto;">
                Region: <strong><?= h((string)$img['body_region']) ?></strong><br>
                Dimensions: <?= (int)$img['image_width'] ?> &times; <?= (int)$img['image_height'] ?> px<br>
                BBox Status: <span class="badge badge-teal"><?= h((string)$img['bbox_validation']) ?></span>
              </div>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
        <div style="display:flex; justify-content:center; align-items:center; gap:0.5rem;">
          <?php if ($page > 1): ?>
            <a href="gut_images.php?mode=<?= urlencode($mode) ?>&amp;q=<?= urlencode($q) ?>&amp;page=<?= $page - 1 ?>" class="btn btn-outline btn-sm">&larr; Previous</a>
          <?php endif; ?>
          <span style="font-size:0.85rem; color:var(--navy-subtle); padding:0 0.5rem;">
            Page <?= $page ?> of <?= $totalPages ?>
          </span>
          <?php if ($page < $totalPages): ?>
            <a href="gut_images.php?mode=<?= urlencode($mode) ?>&amp;q=<?= urlencode($q) ?>&amp;page=<?= $page + 1 ?>" class="btn btn-outline btn-sm">Next &rarr;</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
