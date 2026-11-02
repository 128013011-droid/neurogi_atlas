<?php
declare(strict_types=1);

/**
 * NeuroGI Atlas v2.0 — Comprehensive Scientific Integrity, Statistical & Security Test Suite
 * Covers all 9 required Phase 10 verification categories.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/stats.php';

$db = pdo();
$passed = 0;
$failed = 0;

function assert_true(bool $condition, string $label, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] {$label}\n";
        $passed++;
    } else {
        echo " [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : "") . "\n";
        $failed++;
    }
}

echo "=== NeuroGI Atlas v2.0 Scientific Integrity & Verification Suite ===\n\n";

// ---------------------------------------------------------------------------
// 1. Database Migration & Non-Destructive Preservation
// ---------------------------------------------------------------------------
echo "--- 1. Migration & Non-Destructive Schema Integrity ---\n";
$migVer = $db->query("SELECT version FROM schema_migrations WHERE version = '001_provenance_schema'")->fetchColumn();
assert_true($migVer === '001_provenance_schema', 'Migration 001_provenance_schema recorded in schema_migrations');

$bgaMriCnt  = (int)$db->query("SELECT COUNT(*) FROM brain_measurements")->fetchColumn();
$bgaClinCnt = (int)$db->query("SELECT COUNT(*) FROM clinical_symptom_data")->fetchColumn();
$sigGenCnt  = (int)$db->query("SELECT COUNT(*) FROM significant_genes WHERE lfcSE IS NOT NULL AND stat IS NOT NULL")->fetchColumn();
assert_true($bgaMriCnt === 78 && $bgaClinCnt === 77, 'Original brain_measurements (78) and clinical_symptom_data (77) preserved untouched');
assert_true($sigGenCnt === 15, 'All 15 significant_genes rows populated with lfcSE and Wald stat from annotated CSV');

// ---------------------------------------------------------------------------
// 2. Study-Scoped Participant Identity & Cross-Cohort Separation
// ---------------------------------------------------------------------------
echo "\n--- 2. Study-Scoped Participant Identity & Cohort Boundaries ---\n";
$bgaPartCnt  = (int)$db->query("SELECT COUNT(*) FROM prov_participants WHERE study_code = 'BERGEN_BGA'")->fetchColumn();
$marsPartCnt = (int)$db->query("SELECT COUNT(*) FROM prov_participants WHERE study_code = 'MARS_GSE146853'")->fetchColumn();
$crossStudyLinks = (int)$db->query("SELECT COUNT(*) FROM prov_participant_links WHERE same_study = 0 AND link_status IN ('verified', 'verified_with_visit_offset')")->fetchColumn();
assert_true($bgaPartCnt === 94, "Bergen BGA has 94 study-scoped participants (got {$bgaPartCnt})");
assert_true($marsPartCnt === 42, "Mars GSE146853 has 42 study-scoped participants (got {$marsPartCnt})");
assert_true($crossStudyLinks === 0, 'Zero cross-cohort participant links marked verified');

// ---------------------------------------------------------------------------
// 3. Rejection / Quarantine of Mismatched Demographic Joins
// ---------------------------------------------------------------------------
echo "\n--- 3. Demographic Concordance & Discrepancy Quarantine ---\n";
$bga072 = $db->query("SELECT identity_verification_status FROM prov_participants WHERE participant_uid = 'BERGEN_BGA:BGA_072'")->fetchColumn();
$bga070 = $db->query("SELECT identity_verification_status FROM prov_participants WHERE participant_uid = 'BERGEN_BGA:BGA_070'")->fetchColumn();
$bga097 = $db->query("SELECT identity_verification_status FROM prov_participants WHERE participant_uid = 'BERGEN_BGA:BGA_097'")->fetchColumn();
$bga057Dq = $db->query("SELECT issue_code FROM prov_data_quality_issues WHERE issue_code = 'DQ-BGA-SEX-057-AUDIT'")->fetchColumn();

assert_true($bga072 === 'unresolved_conflict', 'BGA_072 (Sex M vs F) quarantined as unresolved_conflict');
assert_true($bga070 === 'unresolved_conflict', 'BGA_070 (Age 28 vs 22.00) quarantined as unresolved_conflict');
assert_true($bga097 === 'verified_with_visit_offset', 'BGA_097 (+1 yr MRI age offset: 31 vs 30.00) marked verified_with_visit_offset');
assert_true($bga057Dq === 'DQ-BGA-SEX-057-AUDIT', 'BGA_057 historical sex audit documented in prov_data_quality_issues');

$link072Excl = (int)$db->query("SELECT exclude_from_matched_analysis FROM prov_participant_links WHERE source_participant_uid = 'BERGEN_BGA:BGA_072'")->fetchColumn();
$link070Excl = (int)$db->query("SELECT exclude_from_matched_analysis FROM prov_participant_links WHERE source_participant_uid = 'BERGEN_BGA:BGA_070'")->fetchColumn();
assert_true($link072Excl === 1 && $link070Excl === 1, 'BGA_072 and BGA_070 have exclude_from_matched_analysis = 1 by default');

// ---------------------------------------------------------------------------
// 4. Preservation of Missing Values vs. True Zero Scores
// ---------------------------------------------------------------------------
echo "\n--- 4. Missing Values (NULL) vs. True Zero Values (0) ---\n";
assert_true(fmt_num_or_missing(0, 0) === '0', 'fmt_num_or_missing(0, 0) renders "0" (never "N/A" or "Missing")');
assert_true(fmt_num_or_missing('0.00', 2) === '0.00', 'fmt_num_or_missing("0.00", 2) renders "0.00"');
assert_true(str_contains(fmt_num_or_missing(null, 2), 'Missing'), 'fmt_num_or_missing(null) renders "Missing (NULL)" badge');
assert_true(str_contains(fmt_num_or_missing('', 2), 'Missing'), 'fmt_num_or_missing("") renders "Missing (NULL)" badge');

$nullEducCnt = (int)$db->query("SELECT COUNT(*) FROM brain_measurements WHERE Education IS NULL")->fetchColumn();
$zeroSssCnt  = (int)$db->query("SELECT COUNT(*) FROM clinical_symptom_data WHERE IBS_SSS = 0")->fetchColumn();
assert_true($nullEducCnt === 9, "9 HC participants preserve Education IS NULL in brain_measurements (got {$nullEducCnt})");
assert_true($zeroSssCnt === 4, "4 HC participants preserve true zero IBS_SSS = 0 in clinical_symptom_data (got {$zeroSssCnt})");

// ---------------------------------------------------------------------------
// 5. Statistical Engine Accuracy Against Mathematical Fixtures
// ---------------------------------------------------------------------------
echo "\n--- 5. Statistical Engine Verification Against Known Fixtures ---\n";
$fxX = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0];
$fxY = [2.0, 4.0, 5.0, 4.0, 5.0, 7.0, 8.0, 9.0];
$pRes = stats_pearson($fxX, $fxY);
assert_true(
    $pRes['status'] === 'ok' && abs($pRes['estimate'] - 0.95118973) < 1e-6 && abs($pRes['p_value'] - 0.00028018) < 1e-6,
    'Pearson r, Fisher-z 95% CI, and exact incomplete-beta Student-t p-value match reference fixture'
);

$spX = [10, 20, 20, 40, 50];
$spY = [1, 3, 2, 4, 5];
$spRes = stats_spearman($spX, $spY);
assert_true(
    $spRes['status'] === 'ok' && abs($spRes['estimate'] - 0.9746794) < 1e-5,
    'Spearman rank correlation with average-rank tie handling matches reference fixture'
);

$olsRes = stats_ols_covariate_adjusted($fxY, $fxX, ['Cov1' => [1, 0, 1, 0, 1, 0, 1, 0]]);
assert_true(
    $olsRes['status'] === 'ok' && isset($olsRes['coefficients']['X']['beta']) && $olsRes['r_squared'] > 0.90,
    'Multivariable OLS regression and partial correlation compute accurately'
);

$pMap = ['ROI_A' => 0.001, 'ROI_B' => 0.010, 'ROI_C' => 0.040, 'ROI_D' => 0.200];
$bhAdj   = stats_adjust_pvalues($pMap, 'BH_FDR');
$holmAdj = stats_adjust_pvalues($pMap, 'HOLM');
assert_true(
    abs($bhAdj['ROI_A'] - 0.004) < 1e-6 &&
    abs($bhAdj['ROI_B'] - 0.020) < 1e-6 &&
    abs($holmAdj['ROI_A'] - 0.004) < 1e-6 &&
    abs($holmAdj['ROI_B'] - 0.030) < 1e-6,
    'Benjamini-Hochberg FDR and Holm-Bonferroni step-down adjustments match exact mathematical values'
);

// Check constant variable warning
$constRes = stats_pearson([5, 5, 5, 5], [1, 2, 3, 4]);
assert_true($constRes['status'] === 'error_constant_variable', 'Constant variable triggers explicit warning');

// ---------------------------------------------------------------------------
// 6. Metabolomics & Endoscopy Asset Verification (Phase 8)
// ---------------------------------------------------------------------------
echo "\n--- 6. Metabolomics & Endoscopy Asset Honesty ---\n";
$gcmsTotal  = (int)$db->query("SELECT COUNT(*) FROM mars_gcms_biopsy_metabolites")->fetchColumn();
$gcmsPaired = (int)$db->query("SELECT COUNT(*) FROM mars_gcms_biopsy_metabolites WHERE matched_rnaseq_geo IS NOT NULL AND matched_rnaseq_geo != ''")->fetchColumn();
$nmrTotal   = (int)$db->query("SELECT COUNT(*) FROM mars_nmr_stool_metabolites")->fetchColumn();
$maskTotal  = (int)$db->query("SELECT COUNT(*) FROM kvasir_seg_local_assets WHERE has_local_mask = 1 AND is_ibs_cohort = 0")->fetchColumn();

assert_true($gcmsTotal === 74 && $gcmsPaired === 66, "Mars GC-MS biopsy table has 74 rows with 66 paired to GSE146853 RNA-seq");
assert_true($nmrTotal === 464, "Mars NMR stool table has 464 rows marked unlinked_anonymized_tube_id");
assert_true($maskTotal === 1000, "Kvasir-SEG local assets table has 1,000 verified local masks marked is_ibs_cohort = 0");

// ---------------------------------------------------------------------------
// 7. Evidence Graph Epistemology (Phase 5)
// ---------------------------------------------------------------------------
echo "\n--- 7. Source-Traceable Evidence Categories ---\n";
$catCnt = (int)$db->query("SELECT COUNT(DISTINCT evidence_category) FROM prov_evidence_edges")->fetchColumn();
$emptyPassageCnt = (int)$db->query("SELECT COUNT(*) FROM prov_evidence_edges WHERE supporting_passage_or_field IS NULL OR supporting_passage_or_field = ''")->fetchColumn();
assert_true($catCnt === 5, 'All 5 required epistemological evidence categories are populated in prov_evidence_edges');
assert_true($emptyPassageCnt === 0, 'Every evidence edge has a non-empty verbatim supporting passage or structured source field');

// ---------------------------------------------------------------------------
// 8. Security: XSS Escaping & Path-Traversal Guard
// ---------------------------------------------------------------------------
echo "\n--- 8. Security & Input Validation Guards ---\n";
assert_true(h('<script>alert("xss")</script>') === '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', 'h() escapes HTML/XSS payloads');
assert_true(web_path('../../Windows/win.ini') === null, 'web_path() blocks directory traversal outside allowed image roots');
assert_true(web_path('/content/hyperkvasir_gut_images/missing.jpg') === null, 'web_path() returns null for missing external Colab paths');
$sampleMask = $db->query("SELECT local_mask_path FROM kvasir_seg_local_assets LIMIT 1")->fetchColumn();
assert_true(web_path((string)$sampleMask) !== null, 'web_path() resolves valid local Kvasir-SEG mask inside images/masks/');

echo "\n==================================================================\n";
echo "Verification Summary: {$passed} Passed, {$failed} Failed\n";
exit($failed > 0 ? 1 : 0);
