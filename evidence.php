<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$pageTitle = 'Source-Traceable Evidence Explorer | NeuroGI Atlas';
$activeNav = 'evidence';

$db = pdo();

$categoryFilter = trim((string)($_GET['category'] ?? 'ALL'));
$entityTypeFilter = trim((string)($_GET['entity_type'] ?? 'ALL'));
$selectedEdgeCode = trim((string)($_GET['edge'] ?? ''));

$allowedCategories = [
    'same_cohort_measured' => [
        'label' => '1. Same-cohort measured association',
        'badge_class' => 'badge-green',
        'color' => '#059669',
        'rule' => 'Direct empirical statistical test computed on verified matched participants or biopsy samples within a single primary study cohort (BERGEN_BGA or MARS_GSE146853).'
    ],
    'cross_study_literature' => [
        'label' => '2. Cross-study literature-supported relationship',
        'badge_class' => 'badge-blue',
        'color' => '#2563eb',
        'rule' => 'Relationship reported in a peer-reviewed publication (PubMed PMID/DOI) with an explicit supporting passage, cohort description, and tissue/modality context.'
    ],
    'curated_contextual' => [
        'label' => '3. Curated contextual link',
        'badge_class' => 'badge-teal',
        'color' => '#0f766e',
        'rule' => 'Structured registry link (such as a ClinicalTrials.gov NCT intervention record or benchmark dataset scope boundary) providing clinical or methodological context.'
    ],
    'computational_hypothesis' => [
        'label' => '4. Computational hypothesis',
        'badge_class' => 'badge-amber',
        'color' => '#d97706',
        'rule' => 'Pathway enrichment or cross-layer mechanistic hypothesis derived from computational analysis that requires targeted experimental validation.'
    ],
    'contradictory_or_inconclusive' => [
        'label' => '5. Contradictory or inconclusive evidence',
        'badge_class' => 'badge-rose',
        'color' => '#e11d48',
        'rule' => 'Empirical null findings, heterogeneous clinical trial outcomes, or discordant directional reports across studies preserved to prevent confirmation bias.'
    ],
];

// Query edges
$where = [];
$params = [];
if (isset($allowedCategories[$categoryFilter])) {
    $where[] = "evidence_category = :cat";
    $params[':cat'] = $categoryFilter;
}
if ($entityTypeFilter !== 'ALL' && $entityTypeFilter !== '') {
    $where[] = "(source_entity_type = :et OR target_entity_type = :et)";
    $params[':et'] = $entityTypeFilter;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $db->prepare("
    SELECT * FROM prov_evidence_edges
    $whereSql
    ORDER BY FIELD(evidence_category,
        'same_cohort_measured',
        'cross_study_literature',
        'curated_contextual',
        'computational_hypothesis',
        'contradictory_or_inconclusive'
    ), edge_code ASC
");
$stmt->execute($params);
$edges = $stmt->fetchAll();

// Determine selected edge for inspector panel
$selectedEdge = null;
if ($selectedEdgeCode !== '') {
    foreach ($edges as $e) {
        if ($e['edge_code'] === $selectedEdgeCode) {
            $selectedEdge = $e;
            break;
        }
    }
    if (!$selectedEdge) {
        $stOne = $db->prepare("SELECT * FROM prov_evidence_edges WHERE edge_code = :c LIMIT 1");
        $stOne->execute([':c' => $selectedEdgeCode]);
        $selectedEdge = $stOne->fetch() ?: null;
    }
}
if (!$selectedEdge && !empty($edges)) {
    $selectedEdge = $edges[0];
}

// Collect distinct entity types for filter dropdown
$entityTypes = $db->query("
    SELECT DISTINCT entity_type FROM (
        SELECT source_entity_type AS entity_type FROM prov_evidence_edges
        UNION
        SELECT target_entity_type AS entity_type FROM prov_evidence_edges
    ) t ORDER BY entity_type ASC
")->fetchAll(PDO::FETCH_COLUMN);

require_once __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="page-hero-inner">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:1rem;">
      <div>
        <div class="hero-badge" style="margin-bottom:0.6rem;">PHASE 5 &bull; SOURCE-TRACEABLE EVIDENCE GRAPH &amp; CURATION TABLE</div>
        <h2>Source-Traceable Evidence Explorer</h2>
        <p>
          Explore curated, source-verified relationships connecting IBS subtypes, colonic mucosal genes, microbial metabolites, brain regions, cognitive measures, clinical trials, and peer-reviewed publications.
          <strong>A shared keyword is a search match, not a biological evidence edge.</strong>
        </p>
      </div>
      <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
        <a href="analysis.php" class="btn btn-primary">Verify Same-Cohort Edges in Analysis &rarr;</a>
        <a href="research.php" class="btn btn-outline">Browse PubMed &amp; Trials</a>
      </div>
    </div>
  </div>
</section>

<div class="container">

  <!-- Evidence Grading & Non-Fabrication Governance Banner -->
  <div class="prov-banner">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:1rem;">
      <div style="flex:1; min-width:300px;">
        <strong>Evidence Classification Policy (No Fabricated Confidence Scores):</strong>
        NeuroGI Atlas does not invent arbitrary numerical probability or confidence scores (e.g., <code>0.87</code>) for biological relationships.
        Instead, every edge is classified into one of <strong>five explicit epistemological categories</strong> based on whether it derives from same-cohort matched measurements, peer-reviewed literature, clinical trial registries, computational enrichment, or null/contradictory findings.
      </div>
      <div style="display:flex; gap:0.4rem; flex-wrap:wrap;">
        <a href="evidence.php" class="badge <?= $categoryFilter === 'ALL' ? 'badge-navy' : 'badge-teal' ?>" style="text-decoration:none; padding:0.35rem 0.7rem;">All Categories (<?= count($edges) ?>)</a>
        <?php foreach ($allowedCategories as $catKey => $catMeta): ?>
          <a href="evidence.php?category=<?= urlencode($catKey) ?>" class="badge <?= $catMeta['badge_class'] ?>" style="text-decoration:none; padding:0.35rem 0.65rem; <?= $categoryFilter === $catKey ? 'outline:2px solid var(--navy-dark);' : '' ?>">
            <?= h($catMeta['label']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Filter Bar -->
  <div class="table-card" style="margin-bottom:1.5rem;">
    <form method="get" action="evidence.php" style="padding:1rem 1.25rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
      <div style="display:flex; gap:0.85rem; flex-wrap:wrap; align-items:center;">
        <div>
          <label class="form-label" for="f-cat" style="margin-bottom:0.2rem;">Evidence Category</label>
          <select id="f-cat" name="category" class="form-select" style="width:auto;">
            <option value="ALL">All 5 Evidence Categories</option>
            <?php foreach ($allowedCategories as $catKey => $catMeta): ?>
              <option value="<?= h($catKey) ?>" <?= $categoryFilter === $catKey ? 'selected' : '' ?>><?= h($catMeta['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label" for="f-et" style="margin-bottom:0.2rem;">Entity Type</label>
          <select id="f-et" name="entity_type" class="form-select" style="width:auto;">
            <option value="ALL">All Entity Types</option>
            <?php foreach ($entityTypes as $et): ?>
              <option value="<?= h((string)$et) ?>" <?= $entityTypeFilter === $et ? 'selected' : '' ?>><?= h((string)$et) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="padding-top:1.15rem;">
          <button type="submit" class="btn btn-primary btn-sm">Apply Filters</button>
          <a href="evidence.php" class="btn btn-outline btn-sm">Reset</a>
        </div>
      </div>
      <div style="font-size:0.82rem; color:var(--navy-muted);">
        Click any edge in the <strong>Interactive Network Graph</strong> or <strong>Accessible Evidence Table</strong> to inspect its verbatim supporting source passage.
      </div>
    </form>
  </div>

  <!-- Top Grid: Interactive SVG Evidence Network Graph + Source Inspector Panel -->
  <div style="display:grid; grid-template-columns: 1.3fr 1fr; gap:1.5rem; margin-bottom:1.75rem;">

    <!-- Left: Interactive SVG Evidence Graph -->
    <div class="table-card" style="margin-bottom:0;">
      <div class="table-card-header">
        <div>
          <h3>Multimodal Evidence Network Graph</h3>
          <div style="font-size:0.78rem; color:var(--navy-subtle);">Nodes represent typed entities; edges are color-coded by the 5 evidence categories</div>
        </div>
        <span class="badge badge-teal"><?= count($edges) ?> Curated Edges</span>
      </div>
      <div style="padding:1rem;">
        <?php
          // Build unique nodes from filtered edges and position them in concentric or layered rings by entity type
          $nodes = [];
          foreach ($edges as $e) {
              $sKey = $e['source_entity_type'] . ':' . $e['source_entity_id'];
              $tKey = $e['target_entity_type'] . ':' . $e['target_entity_id'];
              if (!isset($nodes[$sKey])) {
                  $nodes[$sKey] = [
                      'key' => $sKey,
                      'type' => $e['source_entity_type'],
                      'id' => $e['source_entity_id'],
                      'label' => $e['source_entity_label'],
                  ];
              }
              if (!isset($nodes[$tKey])) {
                  $nodes[$tKey] = [
                      'key' => $tKey,
                      'type' => $e['target_entity_type'],
                      'id' => $e['target_entity_id'],
                      'label' => $e['target_entity_label'],
                  ];
              }
          }
          $nodeList = array_values($nodes);
          $nNodes = count($nodeList);
          $svgW = 680;
          $svgH = 440;
          $cxCenter = $svgW / 2;
          $cyCenter = $svgH / 2;
          $rxRadius = 245;
          $ryRadius = 165;

          foreach ($nodeList as $idx => &$nd) {
              $angle = (2 * M_PI * $idx) / max(1, $nNodes) - (M_PI / 2);
              $nd['x'] = round($cxCenter + $rxRadius * cos($angle), 1);
              $nd['y'] = round($cyCenter + $ryRadius * sin($angle), 1);
              $nodes[$nd['key']] = $nd;
          }
          unset($nd);
        ?>
        <svg viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" width="100%" height="auto" role="img" aria-label="Source-traceable evidence graph connecting gut-brain entities" style="background:#f8fafc; border:1px solid var(--border-light); border-radius:var(--radius-sm);">
          <defs>
            <marker id="arrow-edge" markerWidth="8" markerHeight="8" refX="20" refY="4" orient="auto" markerUnits="strokeWidth">
              <path d="M0,1 L7,4 L0,7 Z" fill="#64748b"/>
            </marker>
          </defs>

          <!-- Edges -->
          <?php foreach ($edges as $e): ?>
            <?php
              $sKey = $e['source_entity_type'] . ':' . $e['source_entity_id'];
              $tKey = $e['target_entity_type'] . ':' . $e['target_entity_id'];
              $n1 = $nodes[$sKey];
              $n2 = $nodes[$tKey];
              $catMeta = $allowedCategories[$e['evidence_category']] ?? ['color' => '#475569'];
              $isSel = ($selectedEdge && $selectedEdge['edge_code'] === $e['edge_code']);
              $dash = in_array($e['evidence_category'], ['computational_hypothesis', 'contradictory_or_inconclusive'], true) ? '5,4' : '';
              $mx = round(($n1['x'] + $n2['x']) / 2, 1);
              $my = round(($n1['y'] + $n2['y']) / 2, 1);
              $edgeUrl = 'evidence.php?' . http_build_query(array_merge($_GET, ['edge' => $e['edge_code']]));
            ?>
            <a href="<?= h($edgeUrl) ?>">
              <line x1="<?= $n1['x'] ?>" y1="<?= $n1['y'] ?>" x2="<?= $n2['x'] ?>" y2="<?= $n2['y'] ?>"
                    stroke="<?= $catMeta['color'] ?>" stroke-width="<?= $isSel ? '3.4' : '2.0' ?>"
                    <?= $dash ? 'stroke-dasharray="' . $dash . '"' : '' ?> marker-end="url(#arrow-edge)">
                <title><?= h($e['edge_code'] . ': ' . $e['source_entity_label'] . ' -> ' . $e['target_entity_label'] . ' (' . $e['evidence_badge_label'] . ')') ?></title>
              </line>
              <circle cx="<?= $mx ?>" cy="<?= $my ?>" r="<?= $isSel ? '6.5' : '4.5' ?>" fill="<?= $catMeta['color'] ?>" stroke="#ffffff" stroke-width="1.5">
                <title>Click to inspect <?= h($e['edge_code']) ?>: <?= h($e['relationship_type']) ?></title>
              </circle>
            </a>
          <?php endforeach; ?>

          <!-- Nodes -->
          <?php foreach ($nodeList as $nd): ?>
            <?php
              $shortLbl = mb_strlen($nd['label']) > 24 ? (mb_substr($nd['label'], 0, 22) . '…') : $nd['label'];
            ?>
            <g transform="translate(<?= $nd['x'] ?>, <?= $nd['y'] ?>)">
              <circle cx="0" cy="0" r="14" fill="#0f172a" stroke="#14b8a6" stroke-width="2.2">
                <title><?= h($nd['type'] . ': ' . $nd['label'] . ' (' . $nd['id'] . ')') ?></title>
              </circle>
              <text x="0" y="4" text-anchor="middle" font-size="8.5" font-weight="700" fill="#ffffff">
                <?= h(strtoupper(substr($nd['type'], 0, 3))) ?>
              </text>
              <rect x="-68" y="17" width="136" height="16" rx="4" fill="#ffffff" fill-opacity="0.92" stroke="#cbd5e1" stroke-width="0.8"/>
              <text x="0" y="28" text-anchor="middle" font-size="9.5" font-weight="600" fill="#0f172a">
                <?= h($shortLbl) ?>
              </text>
            </g>
          <?php endforeach; ?>
        </svg>

        <!-- Graph Legend -->
        <div style="display:flex; flex-wrap:wrap; gap:0.75rem; margin-top:0.75rem; font-size:0.76rem; color:var(--navy-muted);">
          <?php foreach ($allowedCategories as $catMeta): ?>
            <span style="display:inline-flex; align-items:center; gap:0.35rem;">
              <span style="width:12px; height:3px; background:<?= $catMeta['color'] ?>; display:inline-block;"></span>
              <?= h($catMeta['label']) ?>
            </span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Right: Source Inspector Panel for Selected Edge -->
    <div class="table-card" style="margin-bottom:0; border:2px solid var(--teal-border);">
      <div class="table-card-header" style="background:#f0fdfa;">
        <div>
          <h3>Supporting Source &amp; Provenance Inspector</h3>
          <div style="font-size:0.78rem; color:var(--navy-subtle);">
            <?= $selectedEdge ? 'Inspecting Edge ' . h($selectedEdge['edge_code']) : 'Select an relationship to inspect' ?>
          </div>
        </div>
        <?php if ($selectedEdge): ?>
          <?php $catMeta = $allowedCategories[$selectedEdge['evidence_category']] ?? ['badge_class' => 'badge-navy']; ?>
          <span class="badge <?= $catMeta['badge_class'] ?>"><?= h($selectedEdge['evidence_badge_label']) ?></span>
        <?php endif; ?>
      </div>
      <div style="padding:1.25rem;">
        <?php if ($selectedEdge): ?>
          <div style="margin-bottom:1rem; padding-bottom:0.85rem; border-bottom:1px solid var(--border-light);">
            <div style="font-size:0.76rem; text-transform:uppercase; color:var(--navy-subtle); font-weight:700; margin-bottom:0.25rem;">
              Source Entity &rarr; Relationship &rarr; Target Entity
            </div>
            <div style="font-size:1rem; font-weight:700; color:var(--navy-dark);">
              <span class="badge badge-navy"><?= h($selectedEdge['source_entity_type']) ?></span>
              <?= h($selectedEdge['source_entity_label']) ?>
              <span style="color:var(--teal-primary); padding:0 0.35rem;">&mdash;[ <?= h($selectedEdge['relationship_type']) ?> ]&rarr;</span>
              <span class="badge badge-navy"><?= h($selectedEdge['target_entity_type']) ?></span>
              <?= h($selectedEdge['target_entity_label']) ?>
            </div>
          </div>

          <table class="data-table" style="margin-bottom:1rem;">
            <tbody>
              <tr>
                <th style="width:36%;">Original Source Identifier</th>
                <td><code><?= h($selectedEdge['original_source_id']) ?></code> (Study: <code><?= h($selectedEdge['source_study_code']) ?></code>)</td>
              </tr>
              <tr>
                <th>Study Population</th>
                <td><?= h($selectedEdge['study_population'] ?? 'Not specified') ?></td>
              </tr>
              <tr>
                <th>Tissue / Modality</th>
                <td><?= h($selectedEdge['tissue_or_modality'] ?? 'Not specified') ?></td>
              </tr>
              <tr>
                <th>Direction &amp; Effect Estimate</th>
                <td>
                  <strong><?= h($selectedEdge['effect_direction'] ?? '') ?>:</strong>
                  <code><?= h($selectedEdge['effect_estimate'] ?? 'Qualitative / Contextual') ?></code>
                </td>
              </tr>
              <tr>
                <th>Curation Method &amp; Status</th>
                <td><?= h($selectedEdge['curation_method']) ?> (<span class="badge badge-teal"><?= h($selectedEdge['review_status']) ?></span>)</td>
              </tr>
            </tbody>
          </table>

          <div style="background:#f8fafc; border:1px solid var(--border-light); border-left:4px solid var(--teal-primary); padding:0.85rem 1rem; border-radius:var(--radius-sm); margin-bottom:0.85rem; font-size:0.84rem;">
            <strong style="color:var(--navy-dark); display:block; margin-bottom:0.25rem;">Supporting Passage / Structured Source Field:</strong>
            <span style="color:var(--navy-muted);"><?= h($selectedEdge['supporting_passage_or_field']) ?></span>
          </div>

          <div class="dq-alert" style="margin-bottom:0;">
            <strong>Explicit Scientific Limitations:</strong><br>
            <?= h($selectedEdge['limitations']) ?>
          </div>
        <?php else: ?>
          <p style="color:var(--navy-muted); font-size:0.88rem;">No relationships match the current filter.</p>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- Accessible Evidence Table View -->
  <div class="table-card" style="margin-bottom:2rem;">
    <div class="table-card-header">
      <div>
        <h3>Accessible Source-Traceable Evidence Table (<?= count($edges) ?> Relationships)</h3>
        <div style="font-size:0.78rem; color:var(--navy-subtle);">Complete tabular representation of all curated relationships, source identifiers, effect estimates, and limitations</div>
      </div>
      <span class="badge badge-navy">WCAG Accessible Table View</span>
    </div>
    <div class="table-scroll">
      <table class="data-table">
        <thead>
          <tr>
            <th>Edge ID</th>
            <th>Source Entity</th>
            <th>Relationship</th>
            <th>Target Entity</th>
            <th>Evidence Classification</th>
            <th>Original Source ID</th>
            <th>Tissue / Population</th>
            <th>Effect Estimate</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($edges as $e): ?>
            <?php
              $catMeta = $allowedCategories[$e['evidence_category']] ?? ['badge_class' => 'badge-navy'];
              $isSel = ($selectedEdge && $selectedEdge['edge_code'] === $e['edge_code']);
              $edgeUrl = 'evidence.php?' . http_build_query(array_merge($_GET, ['edge' => $e['edge_code']]));
            ?>
            <tr style="<?= $isSel ? 'background:#f0fdfa;' : '' ?>">
              <td><code><?= h($e['edge_code']) ?></code></td>
              <td>
                <span class="badge badge-navy" style="font-size:0.68rem;"><?= h($e['source_entity_type']) ?></span><br>
                <strong><?= h($e['source_entity_label']) ?></strong>
              </td>
              <td style="font-size:0.82rem; font-weight:600; color:var(--teal-dark);"><?= h($e['relationship_type']) ?></td>
              <td>
                <span class="badge badge-navy" style="font-size:0.68rem;"><?= h($e['target_entity_type']) ?></span><br>
                <strong><?= h($e['target_entity_label']) ?></strong>
              </td>
              <td><span class="badge <?= $catMeta['badge_class'] ?>"><?= h($e['evidence_badge_label']) ?></span></td>
              <td style="font-size:0.78rem;"><code><?= h($e['original_source_id']) ?></code></td>
              <td style="font-size:0.78rem; color:var(--navy-muted);">
                <?= h($e['tissue_or_modality'] ?? '') ?><br>
                <span style="color:var(--navy-subtle);"><?= h($e['study_population'] ?? '') ?></span>
              </td>
              <td style="font-size:0.78rem;"><code><?= h($e['effect_estimate'] ?? '') ?></code></td>
              <td>
                <a href="<?= h($edgeUrl) ?>" class="btn btn-outline btn-sm" style="padding:0.25rem 0.6rem; font-size:0.76rem;">
                  Inspect Source
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Epistemological Classification Rules Reference -->
  <div class="table-card" style="margin-bottom:2rem;">
    <div class="table-card-header">
      <h3>Documented Evidence Classification Rules</h3>
      <span style="font-size:0.78rem; color:var(--navy-subtle);">Why NeuroGI Atlas uses categorical evidence provenance instead of unvalidated numeric confidence scores</span>
    </div>
    <div class="table-scroll">
      <table class="data-table">
        <thead>
          <tr>
            <th>Evidence Label</th>
            <th>Classification Rule &amp; Required Provenance</th>
            <th>Permissible Scientific Interpretation</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($allowedCategories as $catKey => $catMeta): ?>
            <tr>
              <td><span class="badge <?= $catMeta['badge_class'] ?>"><?= h($catMeta['label']) ?></span></td>
              <td><?= h($catMeta['rule']) ?></td>
              <td style="font-size:0.82rem; color:var(--navy-muted);">
                <?php if ($catKey === 'same_cohort_measured'): ?>
                  Supports within-study statistical association ($r$, CI, $p$, $q$); can be recomputed live on <a href="analysis.php">analysis.php</a>.
                <?php elseif ($catKey === 'cross_study_literature'): ?>
                  Supports peer-reviewed biological context; never implies participant-level linkage across separate cohorts.
                <?php elseif ($catKey === 'curated_contextual'): ?>
                  Provides clinical trial intervention context or dataset scope boundaries.
                <?php elseif ($catKey === 'computational_hypothesis'): ?>
                  Hypothesis-generating pathway or multi-layer inference requiring wet-lab or independent cohort replication.
                <?php else: ?>
                  Documents null, non-significant, or discordant findings to prevent selective reporting bias.
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
