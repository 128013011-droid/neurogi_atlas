<?php
declare(strict_types=1);

$pageTitle = 'Gut-Brain Research & References | NeuroGI Atlas';
$activeNav = 'research';
require_once __DIR__ . '/db.php';

$db = pdo();

// Literature filters
$litQ    = trim((string)($_GET['lit_q'] ?? ''));
$litYear = trim((string)($_GET['lit_year'] ?? 'all'));

$litWheres = [];
$litParams = [];
if ($litQ !== '') {
    $w = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $litQ) . '%';
    $litWheres[] = "(title LIKE ? OR abstract LIKE ? OR pmid LIKE ?)";
    $litParams[] = $w;
    $litParams[] = $w;
    $litParams[] = $w;
}
if ($litYear !== 'all') {
    $litWheres[] = "year LIKE ?";
    $litParams[] = '%' . $litYear . '%';
}
$litWhereSql = $litWheres ? 'WHERE ' . implode(' AND ', $litWheres) : '';
$litStmt = $db->prepare("SELECT pmid, title, year, abstract, source FROM pubmed_articles $litWhereSql ORDER BY year DESC, id ASC LIMIT 100");
$litStmt->execute($litParams);
$articles = $litStmt->fetchAll();

// Trials filters
$trialQ      = trim((string)($_GET['trial_q'] ?? ''));
$trialStatus = trim((string)($_GET['trial_status'] ?? 'all'));

$trialWheres = [];
$trialParams = [];
if ($trialQ !== '') {
    $tw = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $trialQ) . '%';
    $trialWheres[] = "(title LIKE ? OR summary LIKE ? OR conditions LIKE ? OR nct_id LIKE ?)";
    $trialParams[] = $tw;
    $trialParams[] = $tw;
    $trialParams[] = $tw;
    $trialParams[] = $tw;
}
if ($trialStatus !== 'all') {
    $trialWheres[] = "status = ?";
    $trialParams[] = $trialStatus;
}
$trialWhereSql = $trialWheres ? 'WHERE ' . implode(' AND ', $trialWheres) : '';
$trialStmt = $db->prepare("SELECT nct_id, title, status, phase, conditions, start_date, completion_date, enrollment, sponsor, summary, source FROM clinical_trials $trialWhereSql ORDER BY id ASC LIMIT 100");
$trialStmt->execute($trialParams);
$trials = $trialStmt->fetchAll();

// GEO Candidate Studies
$geoStudies = $db->query("SELECT geo_uid, accession, title, summary, organism, study_type, submission_date, samples, source FROM geo_candidates ORDER BY id ASC")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-hero">
  <div class="page-hero-inner">
    <div class="hero-badge" style="margin-bottom:0.6rem;">BIBLIOGRAPHIC &amp; REGISTRY SNAPSHOTS &bull; CONTEXTUAL EVIDENCE</div>
    <h2>Gut–Brain Literature, Clinical Trials &amp; GEO Candidates</h2>
    <p>
      Examine curated peer-reviewed PubMed articles, ClinicalTrials.gov intervention records, and candidate human gut–brain GEO datasets.
      For structured entity-to-entity relationships with verbatim supporting passages, visit the <a href="evidence.php" style="color:var(--teal-primary); font-weight:600;">Source-Traceable Evidence Explorer</a>.
    </p>
  </div>
</div>

<div class="container">
  <div class="prov-banner">
    <strong>Repository Snapshot &amp; Status Provenance Notice:</strong>
    Records in <code>pubmed_articles</code> (100 articles) and <code>clinical_trials</code> (100 NCT studies) represent local database snapshots retrieved via the NCBI E-utilities and ClinicalTrials.gov v2 APIs in <strong>February 2025</strong>.
    Trial recruitment statuses (e.g., <code>RECRUITING</code>, <code>ACTIVE_NOT_RECRUITING</code>) and estimated completion dates (ranging up to 2029) reflect the registry state at retrieval time; always click the external <code>NCT</code> link to verify live recruitment status on ClinicalTrials.gov before citing trial status.
  </div>

<!-- Navigation Tabs -->
<div class="tabs-container" style="margin-bottom: 2rem;">
  <div style="display: flex; gap: 0.5rem; border-bottom: 2px solid var(--border-light); padding-bottom: 0.5rem; overflow-x: auto;">
    <button type="button" class="btn btn-secondary active" data-tab-target="tab-pubmed">
      <strong>PubMed Literature</strong> (<?= count($articles) ?> Articles)
    </button>
    <button type="button" class="btn btn-secondary" data-tab-target="tab-trials">
      <strong>ClinicalTrials.gov</strong> (<?= count($trials) ?> Trials)
    </button>
    <button type="button" class="btn btn-secondary" data-tab-target="tab-geo">
      <strong>GEO Candidates</strong> (<?= count($geoStudies) ?> Studies)
    </button>
  </div>

  <style>
    .tab-pane { display: none; margin-top: 1.5rem; }
    .tab-pane.active { display: block; }
  </style>

  <!-- TAB 1: PubMed Literature -->
  <div id="tab-pubmed" class="tab-pane active">
    <!-- Filter Bar -->
    <div class="search-box-wrap" style="padding: 1rem 1.25rem; margin-bottom: 1.5rem;">
      <form method="GET" action="research.php" style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
        <input type="search" name="lit_q" value="<?= htmlspecialchars($litQ, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search PubMed titles, abstracts, PMIDs..." style="flex: 1; min-width: 260px; padding: 0.55rem 0.85rem; border: 1px solid var(--border-light); border-radius: var(--radius-sm); font-size: 0.88rem;">
        
        <select name="lit_year" class="filter-select" onchange="this.form.submit()">
          <option value="all" <?= $litYear === 'all' ? 'selected' : '' ?>>All Years</option>
          <option value="2026" <?= $litYear === '2026' ? 'selected' : '' ?>>2026</option>
          <option value="2025" <?= $litYear === '2025' ? 'selected' : '' ?>>2025</option>
          <option value="2024" <?= $litYear === '2024' ? 'selected' : '' ?>>2024</option>
          <option value="2023" <?= $litYear === '2023' ? 'selected' : '' ?>>2023</option>
          <option value="2022" <?= $litYear === '2022' ? 'selected' : '' ?>>2022</option>
          <option value="2021" <?= $litYear === '2021' ? 'selected' : '' ?>>2021</option>
        </select>

        <button type="submit" class="btn btn-primary btn-sm">Filter Papers</button>
        <?php if ($litQ !== '' || $litYear !== 'all'): ?>
          <a href="research.php" class="btn btn-secondary btn-sm">Reset</a>
        <?php endif; ?>
      </form>
    </div>

    <!-- Articles Grid -->
    <div style="display: flex; flex-direction: column; gap: 1rem;">
      <?php foreach ($articles as $p): ?>
      <article style="background: white; border: 1px solid var(--border-light); border-radius: var(--radius-md); padding: 1.25rem; box-shadow: var(--shadow-sm);">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 0.5rem;">
          <h3 style="font-size: 1.05rem; font-weight: 700; color: var(--navy-dark); line-height: 1.35;">
            <a href="https://pubmed.ncbi.nlm.nih.gov/<?= urlencode($p['pmid']) ?>/" target="_blank" rel="noopener">
              <?= htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') ?> ↗
            </a>
          </h3>
          <span class="badge badge-teal"><?= htmlspecialchars($p['year'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>

        <div style="font-size: 0.8rem; color: var(--navy-subtle); margin-bottom: 0.75rem;">
          PMID: <code><?= htmlspecialchars($p['pmid'], ENT_QUOTES, 'UTF-8') ?></code> &bull; Source: <?= htmlspecialchars($p['source'], ENT_QUOTES, 'UTF-8') ?>
        </div>

        <div style="font-size: 0.88rem; color: var(--navy-muted); line-height: 1.6;">
          <?= htmlspecialchars(substr((string)$p['abstract'], 0, 360), ENT_QUOTES, 'UTF-8') ?>...
        </div>

        <div style="margin-top: 0.75rem; text-align: right;">
          <a href="https://pubmed.ncbi.nlm.nih.gov/<?= urlencode($p['pmid']) ?>/" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">
            Read on PubMed &rarr;
          </a>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- TAB 2: Clinical Trials -->
  <div id="tab-trials" class="tab-pane">
    <!-- Filter Bar -->
    <div class="search-box-wrap" style="padding: 1rem 1.25rem; margin-bottom: 1.5rem;">
      <form method="GET" action="research.php" style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
        <input type="search" name="trial_q" value="<?= htmlspecialchars($trialQ, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search trials by condition, intervention, NCT ID..." style="flex: 1; min-width: 260px; padding: 0.55rem 0.85rem; border: 1px solid var(--border-light); border-radius: var(--radius-sm); font-size: 0.88rem;">
        
        <select name="trial_status" class="filter-select" onchange="this.form.submit()">
          <option value="all" <?= $trialStatus === 'all' ? 'selected' : '' ?>>All Statuses</option>
          <option value="RECRUITING" <?= $trialStatus === 'RECRUITING' ? 'selected' : '' ?>>Recruiting</option>
          <option value="COMPLETED" <?= $trialStatus === 'COMPLETED' ? 'selected' : '' ?>>Completed</option>
          <option value="ACTIVE_NOT_RECRUITING" <?= $trialStatus === 'ACTIVE_NOT_RECRUITING' ? 'selected' : '' ?>>Active, Not Recruiting</option>
        </select>

        <button type="submit" class="btn btn-primary btn-sm">Filter Trials</button>
        <?php if ($trialQ !== '' || $trialStatus !== 'all'): ?>
          <a href="research.php" class="btn btn-secondary btn-sm">Reset</a>
        <?php endif; ?>
      </form>
    </div>

    <!-- Trials Table -->
    <div class="table-card">
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>NCT ID</th>
              <th>Status</th>
              <th>Phase</th>
              <th>Study Title</th>
              <th>Conditions</th>
              <th>Sponsor</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($trials as $t): ?>
            <tr>
              <td><code><?= htmlspecialchars($t['nct_id'], ENT_QUOTES, 'UTF-8') ?></code></td>
              <td>
                <span class="badge <?= $t['status'] === 'RECRUITING' ? 'badge-green' : ($t['status'] === 'COMPLETED' ? 'badge-blue' : 'badge-navy') ?>">
                  <?= htmlspecialchars($t['status'], ENT_QUOTES, 'UTF-8') ?>
                </span>
              </td>
              <td><?= htmlspecialchars($t['phase'] ?: 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
              <td style="max-width: 320px; font-weight: 500;">
                <a href="https://clinicaltrials.gov/study/<?= urlencode($t['nct_id']) ?>" target="_blank" rel="noopener">
                  <?= htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8') ?> ↗
                </a>
              </td>
              <td style="max-width: 200px;"><?= htmlspecialchars((string)$t['conditions'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)$t['sponsor'], ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <a href="https://clinicaltrials.gov/study/<?= urlencode($t['nct_id']) ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">
                  View
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- TAB 3: GEO Candidate Studies -->
  <div id="tab-geo" class="tab-pane">
    <div class="table-card">
      <div class="table-header">
        <h3>
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="var(--teal-primary)" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m10 15 5-3-5-3v6Z"/></svg>
          Screened Human Gut-Brain Candidate Studies from NCBI GEO (22 Studies)
        </h3>
        <span class="badge badge-teal">Homo sapiens</span>
      </div>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Accession</th>
              <th>Study Title</th>
              <th>Organism</th>
              <th>Study Type</th>
              <th>Samples</th>
              <th>Submission Date</th>
              <th>NCBI Link</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($geoStudies as $gs): ?>
            <tr>
              <td><strong><code><?= htmlspecialchars($gs['accession'], ENT_QUOTES, 'UTF-8') ?></code></strong></td>
              <td style="max-width: 380px; font-weight: 500;">
                <?= htmlspecialchars($gs['title'], ENT_QUOTES, 'UTF-8') ?>
              </td>
              <td><?= htmlspecialchars($gs['organism'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($gs['study_type'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><strong><?= htmlspecialchars($gs['samples'], ENT_QUOTES, 'UTF-8') ?></strong></td>
              <td><?= htmlspecialchars($gs['submission_date'], ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <a href="https://www.ncbi.nlm.nih.gov/geo/query/acc.cgi?acc=<?= urlencode($gs['accession']) ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">
                  NCBI GEO ↗
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
