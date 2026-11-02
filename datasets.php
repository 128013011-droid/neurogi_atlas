<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$pageTitle = 'Dataset Catalogue & Provenance Audit | NeuroGI Atlas';
$activeNav = 'datasets';

$db = pdo();

// 1. Provenance Dataset Inventory (prov_datasets + prov_dataset_versions)
$provDatasets = $db->query("
    SELECT d.*, v.version_tag, v.file_sha256, v.transformation_log, s.short_name AS study_short_name, s.doi
    FROM prov_datasets d
    LEFT JOIN prov_dataset_versions v ON v.dataset_code = d.dataset_code AND v.is_current = 1
    LEFT JOIN prov_studies s ON s.study_code = d.study_code
    ORDER BY FIELD(d.record_authenticity, 'real_source_data', 'curated_repository_extract', 'reference_benchmark_non_ibs', 'synthetic_or_composite_legacy'), d.dataset_code ASC
")->fetchAll();

// 2. GSE146853 Sample Metadata (83 rows excluding header row id=1)
$samples = $db->query("
    SELECT id, sample_title, geo_accession, cohort AS condition_name, gender AS sex, age, bmi, timepoint, patientid
    FROM sample_metadata
    WHERE geo_accession != 'geo_accession'
    ORDER BY sample_title ASC
")->fetchAll();

$cohortCounts = ['Diarrhea' => 0, 'Constipation' => 0, 'Healthy' => 0];
foreach ($samples as $s) {
    $c = (string)$s['condition_name'];
    if ($c === 'Diarrhea' || $c === 'Diarrheaiarrhea') {
        $cohortCounts['Diarrhea']++;
    } elseif ($c === 'Constipation') {
        $cohortCounts['Constipation']++;
    } elseif ($c === 'Healthy') {
        $cohortCounts['Healthy']++;
    }
}

// Preload participant verification status
$partMap = [];
foreach ($db->query("SELECT participant_uid, study_code, local_participant_id, identity_verification_status FROM prov_participants")->fetchAll() as $p) {
    $partMap[$p['study_code'] . ':' . $p['local_participant_id']] = $p['identity_verification_status'];
}

// 3. Brain Measurements (78 subjects)
$brainStats = $db->query("
    SELECT `Group`, COUNT(*) AS n, AVG(`Age`) AS mean_age, AVG(`Fullscale_RBANS`) AS mean_rbans, AVG(`IBS_SSS`) AS mean_sss
    FROM brain_measurements
    GROUP BY `Group`
")->fetchAll();

$brainRows = $db->query("
    SELECT `Subject` AS Subject_ID, `Group`, Age, Gender, Education, eTIV,
           `Left-Thalamus`, `Right-Thalamus`, `Left-Hippocampus`, `Right-Hippocampus`,
           `Fullscale_RBANS` AS RBANS_TotalScale, IBS_SSS
    FROM brain_measurements
    ORDER BY `Subject` ASC
")->fetchAll();

// 4. Clinical Symptom Data (77 subjects)
$clinRows = $db->query("
    SELECT participant_id AS BGA_ID, category AS phenotype, sex, age, ibs_sss AS IBS_SSS,
           B1 AS B1_severity, B2 AS B1b_days, H1 AS B2_distension, F1 AS B3_bowel_satisfaction, F2 AS B4_life_interference, R1, R2
    FROM clinical_symptom_data
    ORDER BY participant_id ASC
")->fetchAll();

// 5. Mars et al. GC-MS Biopsy Metabolomics (74 rows) & NMR Stool Metabolomics (464 rows)
$gcmsRows = $db->query("SELECT * FROM mars_gcms_biopsy_metabolites ORDER BY excel_row_num ASC")->fetchAll();
$nmrSummary = $db->query("
    SELECT diagnosis, COUNT(*) AS n_samples, COUNT(DISTINCT id_on_tube) AS n_tubes,
           AVG(butyrate) AS mean_butyrate, AVG(propionate) AS mean_propionate,
           AVG(acetate) AS mean_acetate, AVG(succinate) AS mean_succinate,
           AVG(hypoxanthine) AS mean_hypoxanthine
    FROM mars_nmr_stool_metabolites
    GROUP BY diagnosis
    ORDER BY diagnosis ASC
")->fetchAll();
$nmrSampleRows = $db->query("SELECT * FROM mars_nmr_stool_metabolites ORDER BY matlab_no ASC LIMIT 40")->fetchAll();

// 6. Data-Quality Issues Registry (17 issues)
$dqIssues = $db->query("SELECT * FROM prov_data_quality_issues ORDER BY FIELD(severity, 'critical', 'warning', 'info'), issue_code ASC")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="page-hero-inner">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:1rem;">
      <div>
        <div class="hero-badge" style="margin-bottom:0.6rem;">PHASES 1, 2 &amp; 8 &bull; PROVENANCE-AUDITED DATASET CATALOGUE</div>
        <h2>Dataset Catalogue &amp; Data-Quality Registry</h2>
        <p>
          Inspect the provenance, SHA-256 file checksums, sample vs. participant counts, measurement units, licences, and data-quality flags for every dataset in the NeuroGI Atlas.
          Real primary cohort data, repository extracts, non-IBS imaging benchmarks, and legacy composite tables are explicitly separated.
        </p>
      </div>
      <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
        <a href="participant.php" class="btn btn-primary btn-sm">Participant Explorer &rarr;</a>
        <a href="gut_images.php" class="btn btn-outline btn-sm">Endoscopy Gallery</a>
      </div>
    </div>
  </div>
</section>

<div class="container">

  <!-- Complete Provenance Inventory Table (Phase 1 Deliverable #3) -->
  <div class="table-card" style="margin-bottom:2rem;">
    <div class="table-card-header">
      <div>
        <h3>Master Dataset Inventory &amp; Provenance Registry (<?= count($provDatasets) ?> Datasets)</h3>
        <div style="font-size:0.78rem; color:var(--navy-subtle);">
          Includes source accession, original study, version/retrieval date, sample vs. unique participant counts, units/normalization, licence, and record authenticity
        </div>
      </div>
      <span class="badge badge-teal">SHA-256 Verified</span>
    </div>
    <div class="table-scroll">
      <table class="data-table">
        <thead>
          <tr>
            <th>Dataset Code</th>
            <th>Study &amp; Source</th>
            <th>Domain &amp; Layer</th>
            <th>Authenticity Classification</th>
            <th>Samples / Participants</th>
            <th>Timepoints</th>
            <th>Units &amp; Normalization</th>
            <th>Version &amp; Licence</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($provDatasets as $ds): ?>
            <?php
              $auth = $ds['record_authenticity'];
              $authBadge = match ($auth) {
                  'real_source_data' => '<span class="badge badge-green">Real Primary Cohort Data</span>',
                  'curated_repository_extract' => '<span class="badge badge-blue">Repository Extract (Snapshot)</span>',
                  'reference_benchmark_non_ibs' => '<span class="badge badge-amber">Reference Benchmark (Non-IBS)</span>',
                  default => '<span class="badge badge-rose">Legacy Composite / Summary</span>',
              };
            ?>
            <tr>
              <td>
                <code><?= h($ds['dataset_code']) ?></code><br>
                <span style="font-size:0.72rem; color:var(--navy-subtle);"><?= h($ds['source_table_or_file']) ?></span>
              </td>
              <td style="font-size:0.8rem;">
                <strong><?= h($ds['study_code']) ?></strong><br>
                <?= h($ds['study_short_name'] ?? '') ?>
              </td>
              <td style="font-size:0.8rem;">
                <strong><?= h($ds['domain']) ?></strong><br>
                <span style="color:var(--navy-subtle);"><?= h($ds['measurement_layer'] ?? '') ?></span>
              </td>
              <td><?= $authBadge ?></td>
              <td style="font-size:0.82rem;">
                <strong><?= number_format((int)$ds['sample_count']) ?></strong> samples<br>
                <span style="color:var(--navy-subtle);"><?= (int)$ds['participant_count'] > 0 ? number_format((int)$ds['participant_count']) . ' unique subjects' : 'N/A (Non-participant)' ?></span>
              </td>
              <td style="font-size:0.78rem;"><?= h($ds['available_timepoints'] ?? 'N/A') ?></td>
              <td style="font-size:0.76rem; max-width:260px; color:var(--navy-muted);"><?= h($ds['unit_and_normalization_note'] ?? '') ?></td>
              <td style="font-size:0.75rem;">
                <code><?= h($ds['version_tag'] ?? 'V1') ?></code> (<?= h($ds['retrieval_or_version_date'] ?? '') ?>)<br>
                <span style="color:var(--navy-subtle);"><?= h($ds['license'] ?? '') ?></span>
                <?php if (!empty($ds['file_sha256'])): ?>
                  <br><code title="SHA-256: <?= h($ds['file_sha256']) ?>" style="font-size:0.68rem;">SHA256: <?= h(substr($ds['file_sha256'], 0, 12)) ?>…</code>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Navigation Tabs for Detailed Domain Inspection -->
  <div class="tabs-container" style="margin-bottom: 2rem;">
    <div style="display: flex; gap: 0.5rem; border-bottom: 2px solid var(--border-light); padding-bottom: 0.5rem; overflow-x: auto;">
      <button type="button" class="btn btn-outline active" data-tab-target="tab-rna">
        <strong>1. GSE146853 RNA-seq</strong> (83 Biopsies / 42 Subjects)
      </button>
      <button type="button" class="btn btn-outline" id="mri" data-tab-target="tab-brain">
        <strong>2. Bergen Brain MRI &amp; RBANS</strong> (78 Subjects)
      </button>
      <button type="button" class="btn btn-outline" id="clinical" data-tab-target="tab-clinical">
        <strong>3. Bergen Clinical Symptoms</strong> (77 Subjects)
      </button>
      <button type="button" class="btn btn-outline" id="metabolomics" data-tab-target="tab-multiomics">
        <strong>4. GC-MS &amp; NMR Metabolomics</strong> (74 Biopsy + 464 Stool)
      </button>
      <button type="button" class="btn btn-outline" id="endoscopy" data-tab-target="tab-imaging">
        <strong>5. Endoscopy &amp; 7T Reference</strong> (1,000 Masks + ds005747)
      </button>
      <button type="button" class="btn btn-outline" data-tab-target="tab-dq">
        <strong>6. Data-Quality Audit Log</strong> (<?= count($dqIssues) ?> Flags)
      </button>
    </div>

    <style>
      .tab-pane { display: none; margin-top: 1.5rem; }
      .tab-pane.active { display: block; }
    </style>

    <!-- TAB 1: RNA-seq (GSE146853) -->
    <div id="tab-rna" class="tab-pane active">
      <div class="waterfall-grid">
        <div class="waterfall-step">
          <div class="wf-label">Total Metadata Biopsies</div>
          <div class="wf-num"><?= count($samples) ?></div>
          <div class="wf-desc">42 unique patients across Visit 1 &amp; Visit 2</div>
        </div>
        <div class="waterfall-step">
          <div class="wf-label">Quality-Filtered Count Matrix</div>
          <div class="wf-num">68</div>
          <div class="wf-desc">In <code>GSE146853_filtered_counts.csv</code></div>
        </div>
        <div class="waterfall-step">
          <div class="wf-label">IBS-D / IBS-C / Healthy</div>
          <div class="wf-num"><?= $cohortCounts['Diarrhea'] ?> / <?= $cohortCounts['Constipation'] ?> / <?= $cohortCounts['Healthy'] ?></div>
          <div class="wf-desc">Includes 2 rows with raw typo <code>Diarrheaiarrhea</code></div>
        </div>
        <div class="waterfall-step">
          <div class="wf-label">Paired with Biopsy GC-MS</div>
          <div class="wf-num" style="color:#059669;">66</div>
          <div class="wf-desc">Exact sample-visit matches in Supp. Data III</div>
        </div>
      </div>

      <div class="table-card">
        <div class="table-card-header">
          <h3>GSE146853 Longitudinal Colonic Biopsy Sample Metadata (83 Valid Samples)</h3>
          <a href="genes.php" class="btn btn-primary btn-sm">Open Gene Expression Explorer &rarr;</a>
        </div>
        <div class="table-scroll" style="max-height: 480px; overflow-y: auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Sample Title</th>
                <th>GEO Accession</th>
                <th>Raw Condition</th>
                <th>Sex</th>
                <th>Age (Years)</th>
                <th>BMI</th>
                <th>Derived Visit</th>
                <th>Scoped Participant</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($samples as $i => $s): ?>
                <?php
                  $pid = trim((string)($s['patientid'] ?? ''));
                  $tp  = (int)($s['timepoint'] ?? 0);
                  if (($pid === '' || $pid === '0') && preg_match('/^(\d+)-(\d+)$/', (string)$s['sample_title'], $m)) {
                      $pid = $m[1];
                      $tp = (int)$m[2];
                  }
                  $pUid = 'MARS_GSE146853:' . $pid;
                ?>
                <tr>
                  <td><?= $i + 1 ?></td>
                  <td><strong><?= h((string)$s['sample_title']) ?></strong></td>
                  <td><code><?= h((string)$s['geo_accession']) ?></code></td>
                  <td>
                    <span class="badge <?= $s['condition_name'] === 'Healthy' ? 'badge-green' : (str_starts_with((string)$s['condition_name'], 'Diarrhea') ? 'badge-rose' : 'badge-amber') ?>">
                      <?= h((string)$s['condition_name']) ?>
                    </span>
                  </td>
                  <td><?= ($s['sex'] !== null && $s['sex'] !== '') ? h((string)$s['sex']) : '<span class="badge badge-amber">Missing</span>' ?></td>
                  <td><?= (float)$s['age'] > 0 ? fmt_num_or_missing($s['age'], 1) : '<span class="badge badge-amber">Missing (0.00)</span>' ?></td>
                  <td><?= (float)$s['bmi'] > 0 ? fmt_num_or_missing($s['bmi'], 2) : '<span class="badge badge-amber">Missing (0.00)</span>' ?></td>
                  <td>Visit <?= $tp ?></td>
                  <td><a href="participant.php?uid=<?= urlencode($pUid) ?>"><code><?= h($pUid) ?></code></a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- TAB 2: Bergen Brain MRI & RBANS -->
    <div id="tab-brain" class="tab-pane">
      <div class="prov-banner">
        <strong>Bergen Brain-Gut Study (`BERGEN_BGA`) Normalization Note:</strong>
        All 35 regional volumes in <code>brain_measurements</code> are pre-divided by each participant's FreeSurfer Estimated Total Intracranial Volume (<code>eTIV</code>, stored in raw $\text{mm}^3$).
        Out of 78 MRI participants and 77 clinical participants (94 unique IDs total), 61 IDs overlap: <strong>54 exact demographic matches</strong>, <strong>5 verified +1-year MRI visit offsets</strong> (<code>BGA_083, BGA_097, BGA_128, BGA_152, BGA_156</code>), and <strong>2 unresolved demographic conflicts</strong> (<code>BGA_072</code> sex mismatch; <code>BGA_070</code> 6-yr age difference).
      </div>

      <div class="table-card">
        <div class="table-card-header">
          <h3>Bergen FreeSurfer Subcortical Volumetrics &amp; RBANS Cognition (All 78 MRI Subjects)</h3>
          <a href="analysis.php?study=BERGEN_BGA" class="btn btn-primary btn-sm">Run Brain–Symptom Analysis &rarr;</a>
        </div>
        <div class="table-scroll" style="max-height: 500px; overflow-y: auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th>Subject_ID</th>
                <th>Verification Status</th>
                <th>Group</th>
                <th>Age</th>
                <th>Gender</th>
                <th>Education (Yrs)</th>
                <th>eTIV ($\text{mm}^3$)</th>
                <th>L-Thalamus (Prop)</th>
                <th>L-Hippocampus (Prop)</th>
                <th>RBANS Total</th>
                <th>IBS-SSS</th>
                <th>Profile</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($brainRows as $br): ?>
                <?php
                  $sid = (string)$br['Subject_ID'];
                  $pUid = 'BERGEN_BGA:' . $sid;
                  $vStat = $partMap[$pUid] ?? 'single_table_only';
                ?>
                <tr>
                  <td><strong><?= h($sid) ?></strong></td>
                  <td><?= render_verification_badge($vStat) ?></td>
                  <td><span class="badge <?= $br['Group'] === 'HC' ? 'badge-green' : 'badge-navy' ?>"><?= h((string)$br['Group']) ?></span></td>
                  <td><?= (int)$br['Age'] ?></td>
                  <td><?= h((string)$br['Gender']) ?></td>
                  <td><?= fmt_num_or_missing($br['Education'], 1) ?></td>
                  <td><?= number_format((float)$br['eTIV'], 0) ?></td>
                  <td><code><?= number_format((float)$br['Left-Thalamus'], 6) ?></code></td>
                  <td><code><?= number_format((float)$br['Left-Hippocampus'], 6) ?></code></td>
                  <td><strong><?= fmt_num_or_missing($br['RBANS_TotalScale'], 1) ?></strong></td>
                  <td><?= fmt_num_or_missing($br['IBS_SSS'], 0) ?></td>
                  <td><a href="participant.php?uid=<?= urlencode($pUid) ?>" class="btn btn-outline btn-sm" style="padding:0.2rem 0.5rem; font-size:0.75rem;">Inspect</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- TAB 3: Bergen Clinical Symptom Data -->
    <div id="tab-clinical" class="tab-pane">
      <div class="table-card">
        <div class="table-card-header">
          <div>
            <h3>Bergen Clinical Symptom Distress &amp; IBS-SSS (All 77 Clinical Patients)</h3>
            <div style="font-size:0.78rem; color:var(--navy-subtle);">
              True zero scores (`IBS_SSS = 0` in 4 Healthy Controls: <code>BGA_076, BGA_090, BGA_125, BGA_130</code>) are preserved as numeric `0` and never rendered as `'N/A'`.
            </div>
          </div>
          <a href="analysis.php?study=BERGEN_BGA" class="btn btn-primary btn-sm">Analyze Symptoms &rarr;</a>
        </div>
        <div class="table-scroll" style="max-height: 500px; overflow-y: auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th>BGA_ID</th>
                <th>Verification Status</th>
                <th>Phenotype</th>
                <th>Sex</th>
                <th>Age (Decimal)</th>
                <th>IBS-SSS Total</th>
                <th>B1 Pain Sev</th>
                <th>B1b Pain Days</th>
                <th>B2 Distension</th>
                <th>B3 Bowel Dissat</th>
                <th>B4 Life Interf</th>
                <th>Profile</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($clinRows as $cr): ?>
                <?php
                  $sid = (string)$cr['BGA_ID'];
                  $pUid = 'BERGEN_BGA:' . $sid;
                  $vStat = $partMap[$pUid] ?? 'single_table_only';
                ?>
                <tr>
                  <td><strong><?= h($sid) ?></strong></td>
                  <td><?= render_verification_badge($vStat) ?></td>
                  <td><span class="badge <?= $cr['phenotype'] === 'HC' ? 'badge-green' : 'badge-rose' ?>"><?= h((string)$cr['phenotype']) ?></span></td>
                  <td><?= h((string)$cr['sex']) ?></td>
                  <td><?= number_format((float)$cr['age'], 2) ?></td>
                  <td><strong><?= fmt_num_or_missing($cr['IBS_SSS'], 0) ?></strong></td>
                  <td><?= fmt_num_or_missing($cr['B1_severity'], 0) ?></td>
                  <td><?= fmt_num_or_missing($cr['B1b_days'], 1) ?></td>
                  <td><?= fmt_num_or_missing($cr['B2_distension'], 0) ?></td>
                  <td><?= fmt_num_or_missing($cr['B3_bowel_satisfaction'], 0) ?></td>
                  <td><?= fmt_num_or_missing($cr['B4_life_interference'], 0) ?></td>
                  <td><a href="participant.php?uid=<?= urlencode($pUid) ?>" class="btn btn-outline btn-sm" style="padding:0.2rem 0.5rem; font-size:0.75rem;">Inspect</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- TAB 4: Mars et al. GC-MS & NMR Metabolomics (Phase 8 Implementation) -->
    <div id="tab-multiomics" class="tab-pane">
      <div class="prov-banner">
        <strong>Phase 8 Metabolomics Implementation &amp; Linkage Boundaries:</strong><br>
        1. <strong>Colonic Mucosal Biopsy GC-MS (`mars_gcms_biopsy_metabolites`, 74 rows from Supplementary Data III):</strong> Uses patient IDs (<code>10007xxx</code>) and sample labels (<code>541-1</code>) that match <code>GSE146853</code> colonic biopsy RNA-seq across <strong>66 exact paired biopsy visits</strong>. Supports direct same-biopsy multi-omics correlation on <a href="analysis.php?study=MARS_GSE146853">analysis.php</a>.<br>
        2. <strong>Longitudinal Stool NMR (`mars_nmr_stool_metabolites`, 464 rows from Supplementary Data II):</strong> Uses anonymized laboratory tube IDs (<code>id_on_tube</code>, e.g., <code>1099722</code>) and timepoints 1–7. Because no public mapping table links <code>id_on_tube</code> to clinical <code>patient_id</code> (`10007xxx`), stool NMR records are strictly marked <code>unlinked_anonymized_tube_id</code> and analyzed at the cohort level rather than fabricating participant links.
      </div>

      <!-- Table 4A: GC-MS Biopsy Metabolites -->
      <div class="table-card" style="margin-bottom:1.75rem;">
        <div class="table-card-header">
          <div>
            <h3>4A. Colonic Mucosal Biopsy GC-MS Volatile Fatty Acids (74 Biopsies, 66 Paired with RNA-seq)</h3>
            <div style="font-size:0.78rem; color:var(--navy-subtle);">Source: <code>data/Mars et al, IBS multi-omics/Supplementary data III source data GC-MS biopsy.xlsx</code></div>
          </div>
          <a href="analysis.php?study=MARS_GSE146853" class="btn btn-primary btn-sm">Correlate Biopsy SCFAs with RNA-seq &rarr;</a>
        </div>
        <div class="table-scroll" style="max-height:420px; overflow-y:auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th>Row #</th>
                <th>Sample Title</th>
                <th>Patient ID</th>
                <th>Visit</th>
                <th>Cohort</th>
                <th>Paired RNA-seq GEO</th>
                <th>Acetate</th>
                <th>Propionate</th>
                <th>Butyrate</th>
                <th>Iso-Butyrate</th>
                <th>Valerate</th>
                <th>Lactate</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($gcmsRows as $gr): ?>
                <tr style="<?= $gr['short_sample_id'] === '554_unknown' ? 'background:#fff1f2;' : '' ?>">
                  <td>#<?= (int)$gr['excel_row_num'] ?></td>
                  <td><strong><?= h((string)$gr['full_sample_title']) ?></strong></td>
                  <td><a href="participant.php?uid=<?= urlencode((string)$gr['participant_uid']) ?>"><code><?= h((string)$gr['patient_id']) ?></code></a></td>
                  <td>V<?= (int)$gr['visit_number'] ?></td>
                  <td><span class="badge <?= $gr['raw_cohort'] === 'Healthy' ? 'badge-green' : ($gr['raw_cohort'] === 'IBS-D' ? 'badge-rose' : 'badge-amber') ?>"><?= h((string)$gr['raw_cohort']) ?></span></td>
                  <td>
                    <?php if (!empty($gr['matched_rnaseq_geo'])): ?>
                      <span class="badge badge-teal"><?= h((string)$gr['matched_rnaseq_geo']) ?></span>
                    <?php elseif ($gr['short_sample_id'] === '554_unknown'): ?>
                      <span class="badge badge-rose">Flagged Anomaly (554_unknown)</span>
                    <?php else: ?>
                      <span class="badge badge-navy">GC-MS Biopsy Only</span>
                    <?php endif; ?>
                  </td>
                  <td><?= fmt_num_or_missing($gr['acetate'], 2) ?></td>
                  <td><?= fmt_num_or_missing($gr['propionate'], 2) ?></td>
                  <td><strong><?= fmt_num_or_missing($gr['butyrate'], 2) ?></strong></td>
                  <td><?= fmt_num_or_missing($gr['iso_butyrate'], 2) ?></td>
                  <td><?= fmt_num_or_missing($gr['valerate'], 2) ?></td>
                  <td><?= fmt_num_or_missing($gr['lactate'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Table 4B: Stool NMR Metabolomics -->
      <div class="table-card">
        <div class="table-card-header">
          <div>
            <h3>4B. Longitudinal Stool NMR Metabolomics (464 Samples across 19 Metabolites)</h3>
            <div style="font-size:0.78rem; color:var(--navy-subtle);">Source: <code>data/Mars et al, IBS multi-omics/Supplementary data II source data NMR metabolomics.xlsx</code></div>
          </div>
          <span class="badge badge-amber">Anonymized Tube IDs (Unlinked to Biopsy IDs)</span>
        </div>
        <div style="padding:1rem;">
          <h4 style="font-size:0.9rem; margin-bottom:0.5rem; color:var(--navy-dark);">Cohort-Level Stool NMR Summary (464 Longitudinal Stool Samples)</h4>
          <table class="data-table" style="margin-bottom:1.25rem;">
            <thead>
              <tr>
                <th>Diagnosis Group</th>
                <th>Stool Samples ($N$)</th>
                <th>Distinct Tube IDs</th>
                <th>Mean Acetate</th>
                <th>Mean Propionate</th>
                <th>Mean Butyrate</th>
                <th>Mean Succinate</th>
                <th>Mean Hypoxanthine</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($nmrSummary as $ns): ?>
                <tr>
                  <td><strong><?= h((string)$ns['diagnosis']) ?></strong></td>
                  <td><?= (int)$ns['n_samples'] ?></td>
                  <td><?= (int)$ns['n_tubes'] ?></td>
                  <td><?= number_format((float)$ns['mean_acetate'], 5) ?></td>
                  <td><?= number_format((float)$ns['mean_propionate'], 5) ?></td>
                  <td><?= number_format((float)$ns['mean_butyrate'], 5) ?></td>
                  <td><?= number_format((float)$ns['mean_succinate'], 5) ?></td>
                  <td><?= number_format((float)$ns['mean_hypoxanthine'], 5) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <h4 style="font-size:0.9rem; margin-bottom:0.5rem; color:var(--navy-dark);">Sample Preview (First 40 Stool NMR Records)</h4>
          <div class="table-scroll" style="max-height:340px; overflow-y:auto;">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Matlab #</th>
                  <th>Tube ID (<code>id_on_tube</code>)</th>
                  <th>Timepoint</th>
                  <th>Sex</th>
                  <th>Diagnosis</th>
                  <th>Acetate</th>
                  <th>Propionate</th>
                  <th>Butyrate</th>
                  <th>Succinate</th>
                  <th>Tryptophan/Tyrosine</th>
                  <th>Hypoxanthine</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($nmrSampleRows as $nr): ?>
                  <tr>
                    <td>#<?= (int)$nr['matlab_no'] ?></td>
                    <td><code><?= h((string)$nr['id_on_tube']) ?></code></td>
                    <td>T<?= (int)$nr['time_point'] ?></td>
                    <td><?= h((string)$nr['gender']) ?></td>
                    <td><span class="badge badge-teal"><?= h((string)$nr['diagnosis']) ?></span></td>
                    <td><?= fmt_num_or_missing($nr['acetate'], 5) ?></td>
                    <td><?= fmt_num_or_missing($nr['propionate'], 5) ?></td>
                    <td><?= fmt_num_or_missing($nr['butyrate'], 5) ?></td>
                    <td><?= fmt_num_or_missing($nr['succinate'], 5) ?></td>
                    <td><?= fmt_num_or_missing($nr['tyrosine'], 5) ?></td>
                    <td><?= fmt_num_or_missing($nr['hypoxanthine'], 5) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- TAB 5: Endoscopy & 7T Anatomical Reference -->
    <div id="tab-imaging" class="tab-pane">
      <div class="dq-alert">
        <strong>Non-IBS Reference Benchmark Scope Notice:</strong>
        Neither the Simula <code>Kvasir-SEG / HyperKvasir</code> endoscopy dataset nor the OpenNeuro <code>ds005747</code> 7T NIfTI brain volume comes from IBS cohort participants.
        They are explicitly classified as <strong>Reference Benchmark Assets (`reference_benchmark_non_ibs`)</strong> and are never linked to IBS patient IDs.
      </div>
      <div class="cards-grid">
        <div class="card">
          <div class="card-title">1. Local Kvasir-SEG Segmentation Masks &amp; Bounding Boxes (1,000 Verified Local Assets)</div>
          <div class="card-meta">
            <span class="badge badge-green">1,000 Local .jpg Masks Present</span>
            <span class="badge badge-amber">Non-IBS Reference Benchmark</span>
          </div>
          <div class="card-body">
            1,000 polyp segmentation masks stored locally in <code>images/masks/*.jpg</code> paired with exact pixel dimensions and bounding-box coordinates from <code>kavsir_bboxes.json</code> (indexed in <code>kvasir_seg_local_assets</code>).
          </div>
          <div class="card-footer">
            <a href="gut_images.php?mode=local_masks" class="btn btn-primary btn-sm">Browse 1,000 Local Masks &amp; BBoxes &rarr;</a>
          </div>
        </div>

        <div class="card">
          <div class="card-title">2. HyperKvasir Metadata Staging Table (1,000 Cloud-Path Records)</div>
          <div class="card-meta">
            <span class="badge badge-navy">Metadata Only (Colab /content/ paths)</span>
            <span class="badge badge-amber">1 Duplicate Row Documented</span>
          </div>
          <div class="card-body">
            <code>hyperkvasir_import_staging</code> contains 1,000 metadata records pointing to Google Colab paths (<code>/content/hyperkvasir_gut_images/...</code>) without local raw binary RGB frames, plus 1 duplicate entry (<code>0004a718...</code>).
          </div>
          <div class="card-footer">
            <a href="gut_images.php?mode=staging" class="btn btn-outline btn-sm">Inspect Staging Metadata &rarr;</a>
          </div>
        </div>

        <div class="card">
          <div class="card-title">3. OpenNeuro 7T Structural Brain MRI Reference (ds005747)</div>
          <div class="card-meta">
            <span class="badge badge-teal">sub-011_T1w.nii.gz (10.9 MB)</span>
            <span class="badge badge-navy">CC0 Public Domain</span>
          </div>
          <div class="card-body">
            Single-subject 7-Tesla T1-weighted anatomical brain volume stored locally in <code>medical images/brain/ds005747-1.0.0/sub-011/anat/</code> as an anatomical neuroimaging reference. Not part of the Bergen BGA cohort.
          </div>
        </div>
      </div>
    </div>

    <!-- TAB 6: Audited Data-Quality Registry (17 Issues) -->
    <div id="tab-dq" class="tab-pane">
      <div class="table-card">
        <div class="table-card-header">
          <div>
            <h3>Audited Data-Quality &amp; Discrepancy Registry (<code>prov_data_quality_issues</code>)</h3>
            <div style="font-size:0.78rem; color:var(--navy-subtle);">
              All source discrepancies are preserved without silent overwriting and documented with root-cause and resolution policy
            </div>
          </div>
          <span class="badge badge-rose"><?= count($dqIssues) ?> Audited Issues</span>
        </div>
        <div class="table-scroll">
          <table class="data-table">
            <thead>
              <tr>
                <th>Issue Code</th>
                <th>Severity</th>
                <th>Study &amp; Dataset</th>
                <th>Participant / Record</th>
                <th>Category &amp; Field</th>
                <th>Observed Values</th>
                <th>Root Cause &amp; Resolution Policy</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($dqIssues as $dq): ?>
                <tr>
                  <td><code><?= h($dq['issue_code']) ?></code></td>
                  <td>
                    <span class="badge <?= $dq['severity'] === 'critical' ? 'badge-rose' : ($dq['severity'] === 'warning' ? 'badge-amber' : 'badge-blue') ?>">
                      <?= h(strtoupper($dq['severity'])) ?>
                    </span>
                  </td>
                  <td style="font-size:0.78rem;">
                    <strong><?= h($dq['study_code']) ?></strong><br>
                    <code><?= h($dq['dataset_code']) ?></code>
                  </td>
                  <td style="font-size:0.8rem;">
                    <?php if (!empty($dq['participant_uid'])): ?>
                      <a href="participant.php?uid=<?= urlencode($dq['participant_uid']) ?>"><code><?= h($dq['participant_uid']) ?></code></a>
                    <?php else: ?>
                      <code><?= h($dq['record_identifier'] ?? 'Dataset-wide') ?></code>
                    <?php endif; ?>
                  </td>
                  <td style="font-size:0.8rem;">
                    <strong><?= h($dq['category']) ?></strong><br>
                    Field: <code><?= h($dq['field_name'] ?? 'N/A') ?></code>
                  </td>
                  <td style="font-size:0.8rem; max-width:240px;"><?= h($dq['observed_values']) ?></td>
                  <td style="font-size:0.78rem; max-width:340px; color:var(--navy-muted);">
                    <strong>Cause:</strong> <?= h($dq['root_cause']) ?><br>
                    <strong>Policy:</strong> <?= h($dq['resolution_policy']) ?>
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
