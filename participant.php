<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$pageTitle = 'Verified Participant Explorer | NeuroGI Atlas';
$activeNav = 'participant';

$db = pdo();

// Resolve requested participant by ?uid= or ?id=
$reqUid = trim((string)($_GET['uid'] ?? ''));
$reqId  = trim((string)($_GET['id'] ?? ''));
$studyFilter = trim((string)($_GET['study'] ?? 'ALL'));
$statusFilter = trim((string)($_GET['status'] ?? 'ALL'));

$selectedParticipant = null;
if ($reqUid !== '') {
    $stmt = $db->prepare("SELECT p.*, s.title AS study_title, s.short_name AS study_short_name, s.institution, s.country, s.primary_accession, s.pmid, s.doi, s.license AS study_license
                          FROM prov_participants p
                          JOIN prov_studies s ON s.study_code = p.study_code
                          WHERE p.participant_uid = :uid LIMIT 1");
    $stmt->execute([':uid' => $reqUid]);
    $selectedParticipant = $stmt->fetch() ?: null;
} elseif ($reqId !== '') {
    $stmt = $db->prepare("SELECT p.*, s.title AS study_title, s.short_name AS study_short_name, s.institution, s.country, s.primary_accession, s.pmid, s.doi, s.license AS study_license
                          FROM prov_participants p
                          JOIN prov_studies s ON s.study_code = p.study_code
                          WHERE p.local_participant_id = :id OR p.participant_uid = :id
                          ORDER BY FIELD(p.study_code, 'BERGEN_BGA', 'MARS_GSE146853')
                          LIMIT 1");
    $stmt->execute([':id' => $reqId]);
    $selectedParticipant = $stmt->fetch() ?: null;
}

// Fetch summary counts for the registry header
$summaryRows = $db->query("
    SELECT study_code, identity_verification_status, COUNT(*) AS cnt
    FROM prov_participants
    GROUP BY study_code, identity_verification_status
")->fetchAll();

$countsByStudyStatus = [];
foreach ($summaryRows as $r) {
    $countsByStudyStatus[$r['study_code']][$r['identity_verification_status']] = (int)$r['cnt'];
}

// Fetch participant list for registry table
$whereClauses = ["p.study_code IN ('BERGEN_BGA', 'MARS_GSE146853')"];
$params = [];
if (in_array($studyFilter, ['BERGEN_BGA', 'MARS_GSE146853'], true)) {
    $whereClauses[] = "p.study_code = :study";
    $params[':study'] = $studyFilter;
}
if (in_array($statusFilter, ['verified', 'verified_with_visit_offset', 'unresolved_conflict', 'single_table_only'], true)) {
    $whereClauses[] = "p.identity_verification_status = :status";
    $params[':status'] = $statusFilter;
}
$whereSql = implode(' AND ', $whereClauses);

$stmtList = $db->prepare("
    SELECT p.*,
           (SELECT COUNT(*) FROM prov_data_quality_issues dq WHERE dq.participant_uid = p.participant_uid) AS dq_count
    FROM prov_participants p
    WHERE $whereSql
    ORDER BY FIELD(p.identity_verification_status, 'unresolved_conflict', 'verified_with_visit_offset', 'verified', 'single_table_only'),
             p.study_code ASC, p.local_participant_id ASC
");
$stmtList->execute($params);
$participantList = $stmtList->fetchAll();

// If a participant is selected, load their visits, linkage decisions, DQ flags, raw tables, and source records
$visits = [];
$links = [];
$dqIssues = [];
$sourceRecords = [];
$bgaBrainRow = null;
$bgaClinRow = null;
$marsSamples = [];
$marsGcmsRows = [];

if ($selectedParticipant) {
    $pUid = $selectedParticipant['participant_uid'];
    $localId = $selectedParticipant['local_participant_id'];

    $stV = $db->prepare("SELECT * FROM prov_visits WHERE participant_uid = :u ORDER BY timepoint_order ASC, visit_code ASC");
    $stV->execute([':u' => $pUid]);
    $visits = $stV->fetchAll();

    $stL = $db->prepare("SELECT * FROM prov_participant_links WHERE source_participant_uid = :u1 OR target_participant_uid = :u2 ORDER BY id ASC");
    $stL->execute([':u1' => $pUid, ':u2' => $pUid]);
    $links = $stL->fetchAll();

    $stDq = $db->prepare("SELECT * FROM prov_data_quality_issues WHERE participant_uid = :u ORDER BY FIELD(severity, 'critical', 'warning', 'info')");
    $stDq->execute([':u' => $pUid]);
    $dqIssues = $stDq->fetchAll();

    $stSr = $db->prepare("SELECT * FROM prov_source_records WHERE participant_uid = :u ORDER BY id ASC");
    $stSr->execute([':u' => $pUid]);
    $sourceRecords = $stSr->fetchAll();

    if ($selectedParticipant['study_code'] === 'BERGEN_BGA') {
        $stB = $db->prepare("SELECT * FROM brain_measurements WHERE `Subject` = :id LIMIT 1");
        $stB->execute([':id' => $localId]);
        $bgaBrainRow = $stB->fetch() ?: null;

        $stC = $db->prepare("SELECT * FROM clinical_symptom_data WHERE participant_id = :id LIMIT 1");
        $stC->execute([':id' => $localId]);
        $bgaClinRow = $stC->fetch() ?: null;
    } elseif ($selectedParticipant['study_code'] === 'MARS_GSE146853') {
        $stM = $db->prepare("SELECT * FROM sample_metadata WHERE patientid = :pid OR sample_title LIKE :like_pid ORDER BY sample_title ASC");
        $stM->execute([':pid' => $localId, ':like_pid' => $localId . '-%']);
        $marsSamples = $stM->fetchAll();

        $stG = $db->prepare("SELECT * FROM mars_gcms_biopsy_metabolites WHERE participant_uid = :u ORDER BY visit_number ASC, excel_row_num ASC");
        $stG->execute([':u' => $pUid]);
        $marsGcmsRows = $stG->fetchAll();
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="page-hero-inner">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:1rem;">
      <div>
        <div class="hero-badge" style="margin-bottom:0.6rem;">PHASE 3 &bull; STUDY-SCOPED PARTICIPANT PROVENANCE</div>
        <h2>Verified Participant Explorer</h2>
        <p>
          Inspect study-scoped participant profiles, visit coverage matrices, demographic concordance decisions, and raw source payloads.
          Cross-cohort linkage is strictly forbidden unless supported by original study documentation.
        </p>
      </div>
      <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
        <a href="analysis.php" class="btn btn-primary">Run Matched Analysis &rarr;</a>
        <a href="search.php" class="btn btn-outline">Search Atlas</a>
      </div>
    </div>

    <!-- Quick Evaluator Presets -->
    <div style="margin-top:1.1rem; padding-top:0.9rem; border-top:1px solid var(--border-light); display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap; font-size:0.8rem;">
      <strong style="color:var(--navy-dark);">Quick Audit Presets:</strong>
      <a href="participant.php?uid=BERGEN_BGA:BGA_046" class="badge badge-green" style="text-decoration:none;">BGA_046 (Verified Complete)</a>
      <a href="participant.php?uid=BERGEN_BGA:BGA_072" class="badge badge-rose" style="text-decoration:none;">BGA_072 (Sex Conflict: M vs F)</a>
      <a href="participant.php?uid=BERGEN_BGA:BGA_070" class="badge badge-rose" style="text-decoration:none;">BGA_070 (6-Yr Age Conflict: 28 vs 22)</a>
      <a href="participant.php?uid=BERGEN_BGA:BGA_097" class="badge badge-amber" style="text-decoration:none;">BGA_097 (+1 Yr Visit Offset)</a>
      <a href="participant.php?uid=BERGEN_BGA:BGA_057" class="badge badge-teal" style="text-decoration:none;">BGA_057 (Historical Sex Audit)</a>
      <a href="participant.php?uid=BERGEN_BGA:BGA_076" class="badge badge-blue" style="text-decoration:none;">BGA_076 (HC True Zero IBS-SSS=0)</a>
      <a href="participant.php?uid=BERGEN_BGA:BGA_069" class="badge badge-navy" style="text-decoration:none;">BGA_069 (Missing Education=NULL)</a>
      <a href="participant.php?uid=MARS_GSE146853:10007541" class="badge badge-green" style="text-decoration:none;">Mars 10007541 (RNA-seq + GC-MS V1/V2)</a>
      <a href="participant.php?uid=MARS_GSE146853:10007600" class="badge badge-amber" style="text-decoration:none;">Mars 10007600 (Flagged Missing Age/BMI)</a>
    </div>
  </div>
</section>

<div class="container">

  <?php if ($selectedParticipant): ?>
    <?php
      $pUid = $selectedParticipant['participant_uid'];
      $localId = $selectedParticipant['local_participant_id'];
      $studyCode = $selectedParticipant['study_code'];
      $vStatus = $selectedParticipant['identity_verification_status'];
      $isUnresolved = ($vStatus === 'unresolved_conflict');
    ?>

    <!-- Breadcrumb & Back link -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:0.5rem;">
      <div style="font-size:0.85rem; color:var(--navy-muted);">
        <a href="participant.php">&larr; All Study-Scoped Participants</a> &bull;
        <code><?= h($pUid) ?></code>
      </div>
      <div style="display:flex; gap:0.5rem;">
        <?php if ($studyCode === 'BERGEN_BGA' && in_array($vStatus, ['verified', 'verified_with_visit_offset'], true)): ?>
          <a href="analysis.php?study=BERGEN_BGA&amp;highlight=<?= urlencode($localId) ?>" class="btn btn-outline btn-sm">Highlight in Brain–Symptom Scatter Plot</a>
        <?php endif; ?>
        <a href="#registry-list" class="btn btn-outline btn-sm">Browse Participant Registry</a>
      </div>
    </div>

    <!-- Source Study & Scope Banner -->
    <div class="prov-banner">
      <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:1rem;">
        <div>
          <div style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--teal-dark); letter-spacing:0.04em; margin-bottom:0.25rem;">
            Study-Scoped Participant Identity &bull; <?= h($selectedParticipant['study_short_name']) ?>
          </div>
          <h3 style="font-size:1.35rem; color:var(--navy-dark); margin-bottom:0.35rem;">
            <?= h($localId) ?>
            <span style="font-size:0.9rem; font-weight:500; color:var(--navy-subtle);">
              (Scoped UID: <code><?= h($pUid) ?></code>)
            </span>
          </h3>
          <div style="font-size:0.84rem; color:var(--navy-muted);">
            <strong>Source Study:</strong> <?= h($selectedParticipant['study_title']) ?> (<?= h($selectedParticipant['institution'] ?? '') ?>, <?= h($selectedParticipant['country'] ?? '') ?>)
            <?php if (!empty($selectedParticipant['doi'])): ?>
              &bull; <strong>DOI:</strong> <a href="https://doi.org/<?= h($selectedParticipant['doi']) ?>" target="_blank" rel="noopener"><?= h($selectedParticipant['doi']) ?></a>
            <?php endif; ?>
            <?php if (!empty($selectedParticipant['primary_accession'])): ?>
              &bull; <strong>Accession:</strong> <code><?= h($selectedParticipant['primary_accession']) ?></code>
            <?php endif; ?>
          </div>
        </div>
        <div style="text-align:right;">
          <div style="margin-bottom:0.35rem;"><?= render_verification_badge($vStatus) ?></div>
          <div style="font-size:0.76rem; color:var(--navy-subtle);">
            Cohort: <strong><?= h($selectedParticipant['harmonized_cohort'] ?? 'Unknown') ?></strong>
            (Raw: <code><?= h($selectedParticipant['raw_cohort_label'] ?? 'N/A') ?></code>)
          </div>
        </div>
      </div>

      <div style="margin-top:0.85rem; padding-top:0.75rem; border-top:1px dashed var(--teal-border); font-size:0.82rem; color:var(--navy-dark);">
        <?php if ($studyCode === 'BERGEN_BGA'): ?>
          <strong>Cross-Modality Boundary Rule:</strong> This participant belongs exclusively to the <strong>Bergen Brain-Gut-Microbiota Axis (`BERGEN_BGA`)</strong> cohort (structural brain MRI, RBANS cognitive battery, and IBS-SSS clinical symptom distress).
          Colonic mucosal RNA-seq (`GSE146853`), GC-MS biopsy metabolites, NMR stool metabolites (Mayo Clinic cohort), and Kvasir/HyperKvasir endoscopy images <strong>do NOT belong to this participant</strong> and are strictly marked <em>Not Applicable (Separate Study Cohort)</em>.
        <?php else: ?>
          <strong>Cross-Modality Boundary Rule:</strong> This participant belongs exclusively to the <strong>Mars et al. Longitudinal IBS Multi-Omics (`MARS_GSE146853`, Cell 2020)</strong> cohort at Mayo Clinic (colonic mucosal biopsy RNA-seq and paired GC-MS biopsy SCFAs/bile acids across Visit 1 &amp; Visit 2).
          Bergen Brain MRI, RBANS cognition, IBS-SSS scores (`BERGEN_BGA`), and Kvasir endoscopy images <strong>do NOT belong to this participant</strong>. Note that Supplementary Data II NMR stool samples use anonymized tube IDs (`id_on_tube`) and cannot be joined at the participant level without a clinical key.
        <?php endif; ?>
      </div>
    </div>

    <!-- Data Quality Flags & Linkage Audit Alerts -->
    <?php foreach ($dqIssues as $dq): ?>
      <div class="dq-alert <?= $dq['severity'] === 'critical' ? 'dq-alert-critical' : '' ?>">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem; margin-bottom:0.3rem;">
          <strong>
            [<?= h(strtoupper($dq['severity'])) ?> &bull; <?= h($dq['issue_code']) ?>]
            <?= h($dq['category']) ?> (Field: <code><?= h($dq['field_name'] ?? 'Multiple') ?></code>)
          </strong>
          <span class="badge <?= $dq['excluded_by_default'] ? 'badge-rose' : 'badge-amber' ?>">
            <?= $dq['excluded_by_default'] ? 'Excluded from Matched Analysis by Default' : 'Preserved with Sensitivity Toggle' ?>
          </span>
        </div>
        <div style="margin-bottom:0.25rem;"><strong>Observed Values:</strong> <?= h($dq['observed_values']) ?></div>
        <div style="margin-bottom:0.25rem;"><strong>Root Cause Assessment:</strong> <?= h($dq['root_cause']) ?></div>
        <div><strong>Resolution Policy:</strong> <?= h($dq['resolution_policy']) ?></div>
      </div>
    <?php endforeach; ?>

    <!-- Visit & Modality Coverage Matrix -->
    <div class="table-card" style="margin-bottom:1.75rem;">
      <div class="table-card-header">
        <h3>Visit &amp; Modality Coverage Matrix</h3>
        <span style="font-size:0.8rem; color:var(--navy-subtle);">Explicit timepoint and measurement availability across all platform domains</span>
      </div>
      <div class="table-scroll">
        <table class="coverage-matrix">
          <thead>
            <tr>
              <th style="text-align:left;">Visit / Timepoint</th>
              <th>Age at Visit</th>
              <th>Demographics</th>
              <th>Brain MRI (36 ROIs)</th>
              <th>RBANS Cognition (6)</th>
              <th>IBS-SSS &amp; B1–R2 (14)</th>
              <th>Colonic RNA-seq (GSE146853)</th>
              <th>Biopsy GC-MS SCFAs (10)</th>
              <th>Stool NMR &amp; Endoscopy</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($studyCode === 'BERGEN_BGA'): ?>
              <tr>
                <td style="text-align:left;">
                  <strong>T0_BASELINE</strong><br>
                  <span style="font-size:0.75rem; color:var(--navy-subtle);">Clinical Symptom Intake (<code>clinical_symptom_data</code>)</span>
                </td>
                <td><?= $bgaClinRow ? h((string)$bgaClinRow['age']) . ' yrs' : '<span style="color:#94a3b8;">—</span>' ?></td>
                <?php if ($isUnresolved): ?>
                  <td class="cov-conflict">Conflict (<?= h((string)($bgaClinRow['sex'] ?? '')) ?>, Age <?= h((string)($bgaClinRow['age'] ?? '')) ?>)</td>
                <?php elseif ($bgaClinRow): ?>
                  <td class="cov-present">Present (Sex: <?= h((string)$bgaClinRow['sex']) ?>)</td>
                <?php else: ?>
                  <td class="cov-missing">Not in Clinical Table</td>
                <?php endif; ?>
                <td class="cov-missing">See T_MRI_ACQ</td>
                <td class="cov-missing">See T_MRI_ACQ</td>
                <?php if ($bgaClinRow): ?>
                  <td class="<?= $isUnresolved ? 'cov-conflict' : 'cov-present' ?>">
                    Present (IBS-SSS = <?= h((string)$bgaClinRow['ibs_sss']) ?>)
                  </td>
                <?php else: ?>
                  <td class="cov-missing">Missing (Brain-Only ID)</td>
                <?php endif; ?>
                <td class="cov-unapplicable">Not Applicable (Mars Cohort)</td>
                <td class="cov-unapplicable">Not Applicable (Mars Cohort)</td>
                <td class="cov-unapplicable">Not Applicable</td>
              </tr>
              <tr>
                <td style="text-align:left;">
                  <strong>T_MRI_ACQ</strong><br>
                  <span style="font-size:0.75rem; color:var(--navy-subtle);">Structural MRI &amp; RBANS Session (<code>brain_measurements</code>)</span>
                </td>
                <td><?= $bgaBrainRow ? h((string)$bgaBrainRow['Age']) . ' yrs' : '<span style="color:#94a3b8;">—</span>' ?></td>
                <?php if ($isUnresolved): ?>
                  <td class="cov-conflict">Conflict (<?= h((string)($bgaBrainRow['Gender'] ?? '')) ?>, Age <?= h((string)($bgaBrainRow['Age'] ?? '')) ?>)</td>
                <?php elseif ($bgaBrainRow): ?>
                  <td class="cov-present">
                    Present (Sex: <?= h((string)$bgaBrainRow['Gender']) ?>, Educ: <?= $bgaBrainRow['Education'] === null ? 'NULL [Missing]' : h((string)$bgaBrainRow['Education']) . 'y' ?>)
                  </td>
                <?php else: ?>
                  <td class="cov-missing">Not in MRI Table</td>
                <?php endif; ?>
                <?php if ($bgaBrainRow): ?>
                  <td class="<?= $isUnresolved ? 'cov-conflict' : 'cov-present' ?>">Present (36 ROIs + eTIV)</td>
                  <td class="<?= $isUnresolved ? 'cov-conflict' : 'cov-present' ?>">Present (6 RBANS Scores)</td>
                <?php else: ?>
                  <td class="cov-missing">Missing (Clinical-Only ID)</td>
                  <td class="cov-missing">Missing (Clinical-Only ID)</td>
                <?php endif; ?>
                <td class="cov-missing">See T0_BASELINE</td>
                <td class="cov-unapplicable">Not Applicable (Mars Cohort)</td>
                <td class="cov-unapplicable">Not Applicable (Mars Cohort)</td>
                <td class="cov-unapplicable">Not Applicable</td>
              </tr>
            <?php else: ?>
              <?php foreach ([1 => 'VISIT_1 (Baseline Sigmoidoscopy)', 2 => 'VISIT_2 (6-Month Follow-up Sigmoidoscopy)'] as $vNum => $vTitle): ?>
                <?php
                  $rnaRow = null;
                  foreach ($marsSamples as $ms) {
                      $tp = (int)($ms['timepoint'] ?? 0);
                      if ($tp === $vNum || str_ends_with((string)$ms['sample_title'], '-' . $vNum)) {
                          $rnaRow = $ms;
                          break;
                      }
                  }
                  $gcmsRow = null;
                  foreach ($marsGcmsRows as $gr) {
                      if ((int)$gr['visit_number'] === $vNum) {
                          $gcmsRow = $gr;
                          break;
                      }
                  }
                ?>
                <tr>
                  <td style="text-align:left;"><strong><?= h($vTitle) ?></strong></td>
                  <td>
                    <?php if ($rnaRow && (float)$rnaRow['age'] > 0): ?>
                      <?= h((string)$rnaRow['age']) ?> yrs
                    <?php elseif ($rnaRow && (float)$rnaRow['age'] == 0.0): ?>
                      <span class="badge badge-amber">Missing (0.00 in GEO)</span>
                    <?php else: ?>
                      <span style="color:#94a3b8;">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="<?= $rnaRow ? 'cov-present' : ($gcmsRow ? 'cov-present' : 'cov-missing') ?>">
                    <?php if ($rnaRow): ?>
                      Sex: <?= h((string)$rnaRow['gender']) ?>, BMI: <?= (float)$rnaRow['bmi'] > 0 ? h((string)$rnaRow['bmi']) : 'Missing (0.00)' ?>
                    <?php elseif ($gcmsRow): ?>
                      Cohort: <?= h((string)$gcmsRow['raw_cohort']) ?>
                    <?php else: ?>
                      Not Collected at V<?= $vNum ?>
                    <?php endif; ?>
                  </td>
                  <td class="cov-unapplicable">Not Applicable (Bergen Cohort)</td>
                  <td class="cov-unapplicable">Not Applicable (Bergen Cohort)</td>
                  <td class="cov-unapplicable">Not Applicable (Bergen Cohort)</td>
                  <td class="<?= $rnaRow ? 'cov-present' : 'cov-missing' ?>">
                    <?= $rnaRow ? 'Present (' . h((string)$rnaRow['geo_accession']) . ' / ' . h((string)$rnaRow['sample_title']) . ')' : 'No Biopsy RNA-seq at V' . $vNum ?>
                  </td>
                  <td class="<?= $gcmsRow ? 'cov-present' : 'cov-missing' ?>">
                    <?= $gcmsRow ? 'Present (Sample ' . h((string)$gcmsRow['short_sample_id']) . ', Row #' . (int)$gcmsRow['excel_row_num'] . ')' : 'No Biopsy GC-MS at V' . $vNum ?>
                  </td>
                  <td class="cov-unapplicable">Stool NMR uses Unlinked Tube IDs</td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- CASE 1: UNRESOLVED IDENTITY CONFLICT (Side-by-Side Unmerged View) -->
    <?php if ($isUnresolved && $studyCode === 'BERGEN_BGA'): ?>
      <div class="dq-alert dq-alert-critical" style="margin-bottom:1.5rem;">
        <h4 style="font-size:1rem; margin-bottom:0.35rem; color:#881337;">
          Unresolved Cross-Table Identity Conflict — Records Displayed Separately (Not Combined)
        </h4>
        <p style="margin:0;">
          In accordance with Phase 2 &amp; Phase 3 scientific integrity rules, participant identifier <code><?= h($localId) ?></code> cannot be verified across <code>brain_measurements</code> and <code>clinical_symptom_data</code> because of an unresolved demographic discrepancy
          (<?= h($selectedParticipant['verification_notes']) ?>).
          The two source records are therefore preserved and displayed separately below, and this ID is <strong>automatically excluded from matched Brain–Symptom analyses</strong>.
        </p>
      </div>

      <div class="split-conflict-grid">
        <!-- Left: Brain Measurements Record -->
        <div class="table-card" style="margin-bottom:0; border:2px solid #fda4af;">
          <div class="table-card-header" style="background:#fff1f2;">
            <h3 style="color:#9f1239;">Source Record A: <code>brain_measurements</code> (MRI &amp; RBANS)</h3>
            <span class="badge badge-rose">Unmerged Record</span>
          </div>
          <div style="padding:1.25rem;">
            <?php if ($bgaBrainRow): ?>
              <table class="data-table">
                <tbody>
                  <tr><th>Subject</th><td><code><?= h((string)$bgaBrainRow['Subject']) ?></code></td></tr>
                  <tr><th>Group</th><td><?= h((string)$bgaBrainRow['Group']) ?></td></tr>
                  <tr style="background:#ffe4e6;"><th>Gender (MRI Table)</th><td><strong><?= h((string)$bgaBrainRow['Gender']) ?></strong></td></tr>
                  <tr style="background:#ffe4e6;"><th>Age (MRI Table)</th><td><strong><?= h((string)$bgaBrainRow['Age']) ?> years (integer)</strong></td></tr>
                  <tr><th>Education</th><td><?= fmt_num_or_missing($bgaBrainRow['Education'], 1, ' years') ?></td></tr>
                  <tr><th>IBS_SSS (in MRI Table)</th><td><?= fmt_num_or_missing($bgaBrainRow['IBS_SSS'], 0) ?></td></tr>
                  <tr><th>Fullscale RBANS</th><td><?= fmt_num_or_missing($bgaBrainRow['Fullscale_RBANS'], 1) ?></td></tr>
                  <tr><th>eTIV (Raw Intracranial Volume)</th><td><?= fmt_num_or_missing($bgaBrainRow['eTIV'], 1, ' mm³') ?></td></tr>
                  <tr><th>Left Thalamus (eTIV Prop / Derived mm³)</th><td><?= fmt_num_or_missing($bgaBrainRow['Left-Thalamus'], 6) ?> (<?= number_format((float)$bgaBrainRow['Left-Thalamus'] * (float)$bgaBrainRow['eTIV'], 1) ?> mm³)</td></tr>
                  <tr><th>Right Thalamus (eTIV Prop / Derived mm³)</th><td><?= fmt_num_or_missing($bgaBrainRow['Right-Thalamus'], 6) ?> (<?= number_format((float)$bgaBrainRow['Right-Thalamus'] * (float)$bgaBrainRow['eTIV'], 1) ?> mm³)</td></tr>
                  <tr><th>Left Hippocampus (eTIV Prop / Derived mm³)</th><td><?= fmt_num_or_missing($bgaBrainRow['Left-Hippocampus'], 6) ?> (<?= number_format((float)$bgaBrainRow['Left-Hippocampus'] * (float)$bgaBrainRow['eTIV'], 1) ?> mm³)</td></tr>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>

        <!-- Right: Clinical Symptom Record -->
        <div class="table-card" style="margin-bottom:0; border:2px solid #fda4af;">
          <div class="table-card-header" style="background:#fff1f2;">
            <h3 style="color:#9f1239;">Source Record B: <code>clinical_symptom_data</code> (IBS-SSS &amp; B1–R2)</h3>
            <span class="badge badge-rose">Unmerged Record</span>
          </div>
          <div style="padding:1.25rem;">
            <?php if ($bgaClinRow): ?>
              <table class="data-table">
                <tbody>
                  <tr><th>participant_id</th><td><code><?= h((string)$bgaClinRow['participant_id']) ?></code></td></tr>
                  <tr><th>category</th><td><?= h((string)$bgaClinRow['category']) ?></td></tr>
                  <tr style="background:#ffe4e6;"><th>sex (Clinical Table)</th><td><strong><?= h((string)$bgaClinRow['sex']) ?></strong></td></tr>
                  <tr style="background:#ffe4e6;"><th>age (Clinical Table)</th><td><strong><?= h((string)$bgaClinRow['age']) ?> years (decimal)</strong></td></tr>
                  <tr><th>ibs_sss Total Score</th><td><?= fmt_num_or_missing($bgaClinRow['ibs_sss'], 0) ?></td></tr>
                  <tr><th>B1 / B2 (Bloating &amp; Distension)</th><td>B1: <?= fmt_num_or_missing($bgaClinRow['B1'], 0) ?> / B2: <?= fmt_num_or_missing($bgaClinRow['B2'], 0) ?></td></tr>
                  <tr><th>H1 / H2 (Pain Frequency &amp; Severity)</th><td>H1: <?= fmt_num_or_missing($bgaClinRow['H1'], 0) ?> / H2: <?= fmt_num_or_missing($bgaClinRow['H2'], 0) ?></td></tr>
                  <tr><th>F1 / F2 (Fatigue &amp; Interference)</th><td>F1: <?= fmt_num_or_missing($bgaClinRow['F1'], 0) ?> / F2: <?= fmt_num_or_missing($bgaClinRow['F2'], 0) ?></td></tr>
                  <tr><th>R1 / R2 (Psychosocial Burden / Resilience)</th><td>R1: <?= fmt_num_or_missing($bgaClinRow['R1'], 0) ?> / R2: <?= fmt_num_or_missing($bgaClinRow['R2'], 0) ?></td></tr>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>
      </div>

    <!-- CASE 2: VERIFIED OR SINGLE-MODALITY BERGEN_BGA PARTICIPANT -->
    <?php elseif ($studyCode === 'BERGEN_BGA'): ?>
      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap:1.25rem; margin-bottom:1.5rem;">
        <!-- Demographics & Linkage Summary -->
        <div class="table-card" style="margin-bottom:0;">
          <div class="table-card-header">
            <h3>Demographics &amp; Concordance</h3>
            <?= render_verification_badge($vStatus) ?>
          </div>
          <div style="padding:1rem;">
            <table class="data-table">
              <tbody>
                <tr><th>Study-Scoped UID</th><td><code><?= h($pUid) ?></code></td></tr>
                <tr><th>Cohort / Group</th><td><strong><?= h($selectedParticipant['harmonized_cohort'] ?? '') ?></strong> (Subtype specificity: <code><?= h($selectedParticipant['subtype_specificity']) ?></code>)</td></tr>
                <tr><th>Sex</th><td><?= h($selectedParticipant['harmonized_sex']) ?> (Raw: <code><?= h($selectedParticipant['raw_sex'] ?? '') ?></code>)</td></tr>
                <tr><th>Age Summary</th><td><?= h($selectedParticipant['raw_age_summary'] ?? '') ?></td></tr>
                <tr>
                  <th>Education (Years)</th>
                  <td>
                    <?php if ($bgaBrainRow): ?>
                      <?= fmt_num_or_missing($bgaBrainRow['Education'], 1, ' yrs') ?>
                    <?php else: ?>
                      <span class="badge badge-navy" style="background:#f1f5f9;color:#475569;">Not Collected in Clinical-Only Record</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <tr><th>Verification Notes</th><td style="font-size:0.8rem; color:var(--navy-muted);"><?= h($selectedParticipant['verification_notes'] ?? '') ?></td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Clinical Symptom Distress & IBS-SSS -->
        <div class="table-card" style="margin-bottom:0;">
          <div class="table-card-header">
            <h3>IBS-SSS &amp; Clinical Symptom Distress</h3>
            <span style="font-size:0.78rem; color:var(--navy-subtle);">Scale: 0–500 (True 0 preserved)</span>
          </div>
          <div style="padding:1rem;">
            <?php if ($bgaClinRow): ?>
              <table class="data-table">
                <tbody>
                  <tr>
                    <th>IBS-SSS Total Score (0–500)</th>
                    <td>
                      <strong style="font-size:1.05rem; color:var(--teal-dark);"><?= fmt_num_or_missing($bgaClinRow['ibs_sss'], 0) ?></strong>
                      <?php if ((int)$bgaClinRow['ibs_sss'] === 0): ?>
                        <span class="badge badge-blue">True Zero (Asymptomatic Control)</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <tr><th>B1 / B2 (Bloating &amp; Abdominal Distension)</th><td>B1: <?= fmt_num_or_missing($bgaClinRow['B1'], 0) ?> &bull; B2: <?= fmt_num_or_missing($bgaClinRow['B2'], 0) ?></td></tr>
                  <tr><th>H1 / H2 (Pain Frequency &amp; Severity)</th><td>H1: <?= fmt_num_or_missing($bgaClinRow['H1'], 0) ?> &bull; H2: <?= fmt_num_or_missing($bgaClinRow['H2'], 0) ?></td></tr>
                  <tr><th>F1 / F2 (Fatigue &amp; Daily Life Interference)</th><td>F1: <?= fmt_num_or_missing($bgaClinRow['F1'], 0) ?> &bull; F2: <?= fmt_num_or_missing($bgaClinRow['F2'], 0) ?></td></tr>
                  <tr><th>R1 / R2 (Psychosocial Distress &amp; Resilience)</th><td>R1: <?= fmt_num_or_missing($bgaClinRow['R1'], 0) ?> &bull; R2: <?= fmt_num_or_missing($bgaClinRow['R2'], 0) ?></td></tr>
                </tbody>
              </table>
            <?php elseif ($bgaBrainRow): ?>
              <div class="dq-alert">
                <strong>Single-Modality Participant (Brain MRI Only):</strong>
                <code><?= h($localId) ?></code> is present in <code>brain_measurements</code> (where composite <code>IBS_SSS = <?= fmt_num_or_missing($bgaBrainRow['IBS_SSS'], 0) ?></code> is recorded), but does not have an itemized B1–R2 record in <code>clinical_symptom_data</code>.
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if ($bgaBrainRow): ?>
        <!-- RBANS Cognitive Scores -->
        <div class="table-card" style="margin-bottom:1.5rem;">
          <div class="table-card-header">
            <h3>RBANS Neuropsychological Battery</h3>
            <span style="font-size:0.78rem; color:var(--navy-subtle);">Age-normed standard index scores (Mean = 100, SD = 15)</span>
          </div>
          <div class="table-scroll">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Fullscale RBANS</th>
                  <th>Memory Index</th>
                  <th>Visuospatial Index</th>
                  <th>Verbal Skills Index</th>
                  <th>Attention Index</th>
                  <th>Recall Index</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td><strong><?= fmt_num_or_missing($bgaBrainRow['Fullscale_RBANS'], 1) ?></strong></td>
                  <td><?= fmt_num_or_missing($bgaBrainRow['Memory_Index'], 1) ?></td>
                  <td><?= fmt_num_or_missing($bgaBrainRow['Visuospatial_Index'], 1) ?></td>
                  <td><?= fmt_num_or_missing($bgaBrainRow['Verbalskills_Index'], 1) ?></td>
                  <td><?= fmt_num_or_missing($bgaBrainRow['Attention_Index'], 1) ?></td>
                  <td><?= fmt_num_or_missing($bgaBrainRow['Recall_Index'], 1) ?></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Structural Brain MRI Volumes (Stored eTIV-Normalized vs Derived Raw mm3) -->
        <?php
          $eTIV = (float)$bgaBrainRow['eTIV'];
          $roiCols = [
              'Left-Thalamus', 'Right-Thalamus', 'Left-Hippocampus', 'Right-Hippocampus',
              'Left-Amygdala', 'Right-Amygdala', 'Left-Caudate', 'Right-Caudate',
              'Left-Putamen', 'Right-Putamen', 'Left-Pallidum', 'Right-Pallidum',
              'Left-Accumbens-area', 'Right-Accumbens-area', 'Brain-Stem',
              'Left-Cerebellum-Cortex', 'Right-Cerebellum-Cortex',
              'Left-Cerebellum-White-Matter', 'Right-Cerebellum-White-Matter',
              'Left-VentralDC', 'Right-VentralDC', 'Left-Lateral-Ventricle', 'Right-Lateral-Ventricle',
              '3rd-Ventricle', '4th-Ventricle', 'CSF',
              'CC_Posterior', 'CC_Mid_Posterior', 'CC_Central', 'CC_Mid_Anterior', 'CC_Anterior'
          ];
        ?>
        <div class="table-card" style="margin-bottom:1.75rem;">
          <div class="table-card-header">
            <div>
              <h3>FreeSurfer Structural Brain MRI Measurements (36 Variables)</h3>
              <div style="font-size:0.78rem; color:var(--navy-subtle); margin-top:0.15rem;">
                <strong>Normalization Provenance:</strong> Stored regional values in <code>brain_measurements</code> are <strong>eTIV-normalized proportions</strong> ($\text{Volume}_{\text{mm}^3} / \text{eTIV}$).
                Estimated Total Intracranial Volume (<code>eTIV</code>) for <?= h($localId) ?> is <strong><?= number_format($eTIV, 1) ?> mm&sup3;</strong>. Derived raw $\text{mm}^3$ values are shown alongside stored proportions.
              </div>
            </div>
            <span class="badge badge-teal">eTIV = <?= number_format($eTIV, 0) ?> mm&sup3;</span>
          </div>
          <div class="table-scroll" style="max-height:420px; overflow-y:auto;">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Brain Region (FreeSurfer ASEG ROI)</th>
                  <th>Stored Value (eTIV-Normalized Proportion)</th>
                  <th>Derived Raw Volume ($\text{Proportion} \times \text{eTIV}$, $\text{mm}^3$)</th>
                  <th>Normalization Status</th>
                </tr>
              </thead>
              <tbody>
                <tr style="background:#f0fdfa;">
                  <td><strong>eTIV (Estimated Total Intracranial Volume)</strong></td>
                  <td><code><?= number_format($eTIV, 1) ?></code> (Raw $\text{mm}^3$)</td>
                  <td><strong><?= number_format($eTIV, 1) ?> mm&sup3;</strong></td>
                  <td><span class="badge badge-navy">Raw Unnormalized Reference</span></td>
                </tr>
                <?php foreach ($roiCols as $roi): ?>
                  <?php if (array_key_exists($roi, $bgaBrainRow)): ?>
                    <?php
                      $propVal = $bgaBrainRow[$roi];
                      $rawMm3 = ($propVal !== null && $propVal !== '') ? ((float)$propVal * $eTIV) : null;
                    ?>
                    <tr>
                      <td><strong><?= h($roi) ?></strong></td>
                      <td><code><?= fmt_num_or_missing($propVal, 7) ?></code></td>
                      <td><?= $rawMm3 !== null ? number_format($rawMm3, 1) . ' mm³' : 'Missing' ?></td>
                      <td><span class="badge badge-green">Pre-divided by eTIV (Do not re-divide)</span></td>
                    </tr>
                  <?php endif; ?>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

    <!-- CASE 3: MARS_GSE146853 LONGITUDINAL PARTICIPANT -->
    <?php elseif ($studyCode === 'MARS_GSE146853'): ?>
      <div class="table-card" style="margin-bottom:1.5rem;">
        <div class="table-card-header">
          <h3>Longitudinal Sigmoid Colon Biopsy RNA-seq Samples (GSE146853)</h3>
          <a href="genes.php" class="btn btn-outline btn-sm">Open Transcriptomics Explorer &rarr;</a>
        </div>
        <div class="table-scroll">
          <table class="data-table">
            <thead>
              <tr>
                <th>GEO Accession</th>
                <th>Sample Title</th>
                <th>Derived Visit</th>
                <th>Raw Condition Label</th>
                <th>Harmonized Subtype</th>
                <th>Age (Years)</th>
                <th>Sex</th>
                <th>BMI ($\text{kg/m}^2$)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($marsSamples as $ms): ?>
                <?php
                  $tp = (int)($ms['timepoint'] ?? 0);
                  if ($tp === 0 && preg_match('/-(\d+)$/', (string)$ms['sample_title'], $m)) {
                      $tp = (int)$m[1];
                  }
                  $isZeroAge = ((float)$ms['age'] == 0.0);
                  $isZeroBmi = ((float)$ms['bmi'] == 0.0);
                ?>
                <tr>
                  <td><code><?= h((string)$ms['geo_accession']) ?></code></td>
                  <td><strong><?= h((string)$ms['sample_title']) ?></strong></td>
                  <td>Visit <?= $tp ?></td>
                  <td><code><?= h((string)$ms['cohort']) ?></code></td>
                  <td><span class="badge badge-teal"><?= h((string)($selectedParticipant['harmonized_cohort'] ?? '')) ?></span></td>
                  <td><?= $isZeroAge ? '<span class="badge badge-amber">Missing (Stored as 0.00 in GEO)</span>' : fmt_num_or_missing($ms['age'], 1) ?></td>
                  <td><?= ($ms['gender'] !== null && $ms['gender'] !== '') ? h((string)$ms['gender']) : '<span class="badge badge-amber">Missing (Empty)</span>' ?></td>
                  <td><?= $isZeroBmi ? '<span class="badge badge-amber">Missing (Stored as 0.00 in GEO)</span>' : fmt_num_or_missing($ms['bmi'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="table-card" style="margin-bottom:1.75rem;">
        <div class="table-card-header">
          <div>
            <h3>Matched Colonic Mucosal Biopsy GC-MS Metabolomics (Mars et al. Supplementary Data III)</h3>
            <div style="font-size:0.78rem; color:var(--navy-subtle);">Same-patient, same-visit colonic biopsy short-chain fatty acids (SCFAs) linked via <code>patient_id + visit_number</code></div>
          </div>
          <span class="badge badge-green"><?= count($marsGcmsRows) ?> Biopsy GC-MS Records</span>
        </div>
        <div class="table-scroll">
          <?php if ($marsGcmsRows): ?>
            <table class="data-table">
              <thead>
                <tr>
                  <th>Excel Row</th>
                  <th>Visit</th>
                  <th>Biopsy Sample ID</th>
                  <th>Matched RNA-seq GEO</th>
                  <th>Cohort</th>
                  <th>Acetate</th>
                  <th>Propionate</th>
                  <th>Butyrate</th>
                  <th>Iso-Butyrate</th>
                  <th>Valerate</th>
                  <th>Lactate</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($marsGcmsRows as $gr): ?>
                  <tr>
                    <td>#<?= (int)$gr['excel_row_num'] ?></td>
                    <td>Visit <?= (int)$gr['visit_number'] ?></td>
                    <td><code><?= h((string)$gr['short_sample_id']) ?></code></td>
                    <td>
                      <?php if (!empty($gr['matched_rnaseq_geo'])): ?>
                        <span class="badge badge-green"><?= h((string)$gr['matched_rnaseq_geo']) ?></span>
                      <?php else: ?>
                        <span class="badge badge-navy">GC-MS Biopsy Only</span>
                      <?php endif; ?>
                    </td>
                    <td><?= h((string)$gr['raw_cohort']) ?></td>
                    <td><?= fmt_num_or_missing($gr['acetate'], 2) ?></td>
                    <td><?= fmt_num_or_missing($gr['propionate'], 2) ?></td>
                    <td><?= fmt_num_or_missing($gr['butyrate'], 2) ?></td>
                    <td><?= fmt_num_or_missing($gr['iso_butyrate'], 2) ?></td>
                    <td><?= fmt_num_or_missing($gr['valerate'], 2) ?></td>
                    <td><?= fmt_num_or_missing($gr['lactate'], 2) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php else: ?>
            <div style="padding:1.25rem; color:var(--navy-muted); font-size:0.88rem;">
              No biopsy GC-MS metabolomics row exists in Supplementary Data III for participant <code><?= h($localId) ?></code> (RNA-seq only participant).
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Source Record Payloads & Linkage Decisions -->
    <div class="table-card" style="margin-bottom:2.25rem;">
      <div class="table-card-header">
        <h3>Audited Source Records &amp; Raw Payloads (<code>prov_source_records</code>)</h3>
        <span style="font-size:0.78rem; color:var(--navy-subtle);">Preserving original source filenames, row identifiers, and untransformed JSON records</span>
      </div>
      <div style="padding:1rem;">
        <?php foreach ($sourceRecords as $sr): ?>
          <details style="margin-bottom:0.65rem; border:1px solid var(--border-light); border-radius:var(--radius-sm); padding:0.65rem 0.9rem; background:#f8fafc;">
            <summary style="cursor:pointer; font-weight:600; font-size:0.85rem; color:var(--navy-dark);">
              Dataset: <code><?= h($sr['dataset_code']) ?></code> &bull;
              Source: <code><?= h($sr['source_file_or_table']) ?></code> &bull;
              Original Row ID: <code><?= h($sr['original_row_id']) ?></code>
            </summary>
            <pre style="margin-top:0.65rem; padding:0.75rem; background:#0f172a; color:#e2e8f0; border-radius:var(--radius-sm); font-size:0.76rem; overflow-x:auto;"><?= h($sr['raw_payload_json']) ?></pre>
          </details>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- Study-Scoped Participant Registry Table -->
  <div id="registry-list" class="table-card">
    <div class="table-card-header" style="flex-wrap:wrap; gap:1rem;">
      <div>
        <h3>Study-Scoped Participant Registry (<?= count($participantList) ?> Shown)</h3>
        <div style="font-size:0.8rem; color:var(--navy-subtle);">
          Bergen BGA (`BERGEN_BGA`: 94 unique IDs — 54 exact verified, 5 +1yr visit offset, 2 unresolved conflicts, 33 single-table) &bull;
          Mars et al. (`MARS_GSE146853`: 42 unique patients — 36 dual RNA-seq+GC-MS, 6 single-modality)
        </div>
      </div>
      <form method="get" action="participant.php" style="display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center;">
        <select name="study" class="form-select" style="width:auto; padding:0.4rem 0.7rem; font-size:0.82rem;">
          <option value="ALL" <?= $studyFilter === 'ALL' ? 'selected' : '' ?>>All Studies (136 Participants)</option>
          <option value="BERGEN_BGA" <?= $studyFilter === 'BERGEN_BGA' ? 'selected' : '' ?>>Bergen Brain-Gut Study (BERGEN_BGA: 94)</option>
          <option value="MARS_GSE146853" <?= $studyFilter === 'MARS_GSE146853' ? 'selected' : '' ?>>Mars et al. Multi-Omics (MARS_GSE146853: 42)</option>
        </select>
        <select name="status" class="form-select" style="width:auto; padding:0.4rem 0.7rem; font-size:0.82rem;">
          <option value="ALL" <?= $statusFilter === 'ALL' ? 'selected' : '' ?>>All Verification Statuses</option>
          <option value="verified" <?= $statusFilter === 'verified' ? 'selected' : '' ?>>Verified Concordant (90)</option>
          <option value="verified_with_visit_offset" <?= $statusFilter === 'verified_with_visit_offset' ? 'selected' : '' ?>>Verified (+1 Yr Visit Offset) (5)</option>
          <option value="unresolved_conflict" <?= $statusFilter === 'unresolved_conflict' ? 'selected' : '' ?>>Unresolved Conflict [Excluded] (2)</option>
          <option value="single_table_only" <?= $statusFilter === 'single_table_only' ? 'selected' : '' ?>>Single-Modality Only (39)</option>
        </select>
        <button type="submit" class="btn btn-primary" style="padding:0.4rem 0.85rem; font-size:0.82rem;">Filter</button>
      </form>
    </div>

    <div class="table-scroll" style="max-height:600px; overflow-y:auto;">
      <table class="data-table">
        <thead>
          <tr>
            <th>Study-Scoped UID</th>
            <th>Source Study</th>
            <th>Local ID</th>
            <th>Cohort / Subtype</th>
            <th>Sex</th>
            <th>Age Summary</th>
            <th>Verification Status</th>
            <th>DQ Flags</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($participantList as $p): ?>
            <tr style="<?= ($selectedParticipant && $selectedParticipant['participant_uid'] === $p['participant_uid']) ? 'background:#f0fdfa;' : '' ?>">
              <td><code><?= h($p['participant_uid']) ?></code></td>
              <td><span class="badge badge-navy"><?= h($p['study_code']) ?></span></td>
              <td><strong><?= h($p['local_participant_id']) ?></strong></td>
              <td><?= h($p['harmonized_cohort'] ?? '') ?> <span style="font-size:0.75rem; color:var(--navy-subtle);">(<?= h($p['raw_cohort_label'] ?? '') ?>)</span></td>
              <td><?= h($p['harmonized_sex']) ?></td>
              <td style="font-size:0.82rem;"><?= h($p['raw_age_summary'] ?? '') ?></td>
              <td><?= render_verification_badge($p['identity_verification_status']) ?></td>
              <td>
                <?php if ((int)$p['dq_count'] > 0): ?>
                  <span class="badge badge-amber"><?= (int)$p['dq_count'] ?> Flag<?= (int)$p['dq_count'] > 1 ? 's' : '' ?></span>
                <?php else: ?>
                  <span style="font-size:0.76rem; color:var(--navy-subtle);">Clean</span>
                <?php endif; ?>
              </td>
              <td>
                <a href="participant.php?uid=<?= urlencode($p['participant_uid']) ?>" class="btn btn-outline btn-sm" style="padding:0.25rem 0.6rem; font-size:0.78rem;">
                  Inspect Profile
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
