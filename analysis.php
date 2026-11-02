<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/stats.php';

$db = pdo();

// ---------------------------------------------------------------------------
// 1. Parse & Validate Analysis Parameters
// ---------------------------------------------------------------------------
$studyCode = strtoupper(trim((string)($_GET['study'] ?? 'BERGEN_BGA')));
if (!in_array($studyCode, ['BERGEN_BGA', 'MARS_GSE146853'], true)) {
    $studyCode = 'BERGEN_BGA';
}

// Allowed Brain MRI measures for BERGEN_BGA
$bgaBrainMeasures = [
    'Left-Thalamus' => 'Left Thalamus Volume',
    'Right-Thalamus' => 'Right Thalamus Volume',
    'Left-Hippocampus' => 'Left Hippocampus Volume',
    'Right-Hippocampus' => 'Right Hippocampus Volume',
    'Left-Amygdala' => 'Left Amygdala Volume',
    'Right-Amygdala' => 'Right Amygdala Volume',
    'Left-Caudate' => 'Left Caudate Volume',
    'Right-Caudate' => 'Right Caudate Volume',
    'Left-Putamen' => 'Left Putamen Volume',
    'Right-Putamen' => 'Right Putamen Volume',
    'Left-Pallidum' => 'Left Pallidum Volume',
    'Right-Pallidum' => 'Right Pallidum Volume',
    'Left-Accumbens-area' => 'Left Accumbens Area Volume',
    'Right-Accumbens-area' => 'Right Accumbens Area Volume',
    'Left-Cerebellum-Cortex' => 'Left Cerebellum Cortex Volume',
    'Right-Cerebellum-Cortex' => 'Right Cerebellum Cortex Volume',
    'Left-Cerebellum-White-Matter' => 'Left Cerebellum White Matter Volume',
    'Right-Cerebellum-White-Matter' => 'Right Cerebellum White Matter Volume',
    'CSF' => 'Cerebrospinal Fluid (CSF) Volume',
    'WM-hypointensities' => 'White Matter Hypointensities',
    'CC_Posterior' => 'Corpus Callosum Posterior',
    'CC_Mid_Posterior' => 'Corpus Callosum Mid-Posterior',
    'CC_Central' => 'Corpus Callosum Central',
    'CC_Mid_Anterior' => 'Corpus Callosum Mid-Anterior',
    'CC_Anterior' => 'Corpus Callosum Anterior',
    'SubCortGrayVol' => 'Subcortical Gray Matter Volume',
    'TotalGrayVol' => 'Total Gray Matter Volume',
    'CortexVol' => 'Total Cerebral Cortex Volume',
    'eTIV' => 'Estimated Total Intracranial Volume (eTIV, Raw mm³)'
];

// Allowed Clinical & Cognitive Y measures for BERGEN_BGA
$bgaYMeasures = [
    'ibs_sss' => ['label' => 'IBS-SSS Total Symptom Severity Score', 'unit' => 'Points (0–500)', 'source' => 'clinical'],
    'B1' => ['label' => 'Clinical Distress Item B1 (Bloating)', 'unit' => 'Scale Score', 'source' => 'clinical'],
    'B2' => ['label' => 'Clinical Distress Item B2 (Distension)', 'unit' => 'Scale Score', 'source' => 'clinical'],
    'H1' => ['label' => 'Clinical Distress Item H1 (Pain Frequency)', 'unit' => 'Scale Score', 'source' => 'clinical'],
    'H2' => ['label' => 'Clinical Distress Item H2 (Pain Severity)', 'unit' => 'Scale Score', 'source' => 'clinical'],
    'F1' => ['label' => 'Clinical Distress Item F1 (Fatigue)', 'unit' => 'Scale Score', 'source' => 'clinical'],
    'F2' => ['label' => 'Clinical Distress Item F2 (Life Interference)', 'unit' => 'Scale Score', 'source' => 'clinical'],
    'R1' => ['label' => 'Clinical Distress Item R1 (Psychosocial Burden)', 'unit' => 'Scale Score', 'source' => 'clinical'],
    'R2' => ['label' => 'Clinical Distress Item R2 (Resilience)', 'unit' => 'Scale Score', 'source' => 'clinical'],
    'Fullscale_RBANS' => ['label' => 'RBANS Fullscale Total Index', 'unit' => 'Standard Score (Mean 100, SD 15)', 'source' => 'brain'],
    'Memory_Index' => ['label' => 'RBANS Immediate Memory Index', 'unit' => 'Standard Score', 'source' => 'brain'],
    'Visuospatial_Index' => ['label' => 'RBANS Visuospatial/Constructional Index', 'unit' => 'Standard Score', 'source' => 'brain'],
    'Verbalskills_Index' => ['label' => 'RBANS Verbal Skills / Language Index', 'unit' => 'Standard Score', 'source' => 'brain'],
    'Attention_Index' => ['label' => 'RBANS Attention Index', 'unit' => 'Standard Score', 'source' => 'brain'],
    'Recall_Index' => ['label' => 'RBANS Delayed Recall Index', 'unit' => 'Standard Score', 'source' => 'brain'],
];

// Allowed Mars et al. GC-MS Biopsy X measures
$marsXMeasures = [
    'butyrate' => 'Colonic Biopsy Butyrate (GC-MS)',
    'propionate' => 'Colonic Biopsy Propionate (GC-MS)',
    'acetate' => 'Colonic Biopsy Acetate (GC-MS)',
    'iso_butyrate' => 'Colonic Biopsy Iso-Butyrate (GC-MS)',
    'valerate' => 'Colonic Biopsy Valerate (GC-MS)',
    'iso_valerate' => 'Colonic Biopsy Iso-Valerate (GC-MS)',
    'two_methyl_butyrate' => 'Colonic Biopsy 2-Methylbutyrate (GC-MS)',
    'caproate' => 'Colonic Biopsy Caproate (GC-MS)',
    'lactate' => 'Colonic Biopsy Lactate (GC-MS)',
    'two_oh_butyrate' => 'Colonic Biopsy 2-Hydroxybutyrate (GC-MS)'
];

// Allowed Mars et al. RNA-seq Gene Y measures (from significant_genes)
$marsGenesRows = $db->query("SELECT gene_id, gene_symbol, gene_description FROM significant_genes ORDER BY padj ASC")->fetchAll();
$marsYMeasures = [];
foreach ($marsGenesRows as $gRow) {
    $sym = (string)($gRow['gene_symbol'] ?: $gRow['gene_id']);
    $marsYMeasures[$sym] = [
        'gene_id' => (string)$gRow['gene_id'],
        'symbol' => $sym,
        'label' => $sym . ' — ' . (string)$gRow['gene_description'] . ' (' . $gRow['gene_id'] . ')',
        'unit' => 'log2(Raw Read Count + 1)'
    ];
}

// Defaults based on selected study
if ($studyCode === 'BERGEN_BGA') {
    $xVar = trim((string)($_GET['x'] ?? 'Left-Thalamus'));
    if (!isset($bgaBrainMeasures[$xVar])) {
        $xVar = 'Left-Thalamus';
    }
    $yVar = trim((string)($_GET['y'] ?? 'ibs_sss'));
    if ($yVar === 'IBS_SSS') {
        $yVar = 'ibs_sss';
    }
    if ($yVar === 'RBANS_TotalScale') {
        $yVar = 'Fullscale_RBANS';
    }
    if (!isset($bgaYMeasures[$yVar])) {
        $yVar = 'ibs_sss';
    }
    $visitRule = trim((string)($_GET['visit_rule'] ?? 'VERIFIED_INCL_OFFSET'));
    if (!in_array($visitRule, ['VERIFIED_INCL_OFFSET', 'VERIFIED_STRICT_EXACT_AGE'], true)) {
        $visitRule = 'VERIFIED_INCL_OFFSET';
    }
    $cohortFilter = trim((string)($_GET['cohort'] ?? 'ALL'));
    if (!in_array($cohortFilter, ['ALL', 'IBS_ONLY', 'HC_ONLY'], true)) {
        $cohortFilter = 'ALL';
    }
} else {
    $xVar = trim((string)($_GET['x'] ?? 'butyrate'));
    if (!isset($marsXMeasures[$xVar])) {
        $xVar = 'butyrate';
    }
    $yVar = trim((string)($_GET['y'] ?? 'GDNF'));
    if (!isset($marsYMeasures[$yVar])) {
        $yVar = array_key_first($marsYMeasures) ?: 'GDNF';
    }
    $visitRule = trim((string)($_GET['visit_rule'] ?? 'VISIT_1_ONLY'));
    if (!in_array($visitRule, ['VISIT_1_ONLY', 'VISIT_2_ONLY', 'ALL_MATCHED_BIOPSIES'], true)) {
        $visitRule = 'VISIT_1_ONLY';
    }
    $cohortFilter = trim((string)($_GET['cohort'] ?? 'ALL'));
    if (!in_array($cohortFilter, ['ALL', 'IBS_D_ONLY', 'IBS_C_ONLY', 'HC_ONLY'], true)) {
        $cohortFilter = 'ALL';
    }
}

$brainScale = trim((string)($_GET['brain_scale'] ?? 'ETIV_PROPORTION'));
if (!in_array($brainScale, ['ETIV_PROPORTION', 'DERIVED_RAW_MM3'], true)) {
    $brainScale = 'ETIV_PROPORTION';
}

$statMethod = strtolower(trim((string)($_GET['method'] ?? 'pearson')));
if (!in_array($statMethod, ['pearson', 'spearman'], true)) {
    $statMethod = 'pearson';
}

$mtMethod = strtoupper(trim((string)($_GET['mt'] ?? 'BH_FDR')));
if (!in_array($mtMethod, ['BH_FDR', 'HOLM'], true)) {
    $mtMethod = 'BH_FDR';
}

$covAge  = !empty($_GET['cov_age']);
$covSex  = !empty($_GET['cov_sex']);
$covEduc = !empty($_GET['cov_educ']);
$covEtiv = !empty($_GET['cov_etiv']);
$highlightId = trim((string)($_GET['highlight'] ?? ''));

// ---------------------------------------------------------------------------
// 2. Build Eligible Cohort & Exclusion Waterfall
// ---------------------------------------------------------------------------
$waterfall = [];
$eligibleRows = [];
$excludedDetails = [];
$warnings = [];

if ($studyCode === 'BERGEN_BGA') {
    // Load all 94 BERGEN_BGA participants and join with brain_measurements and clinical_symptom_data
    $sql = "
        SELECT p.participant_uid, p.local_participant_id, p.harmonized_cohort, p.harmonized_sex,
               p.identity_verification_status, p.verification_notes,
               b.`Group` AS mri_group, b.Age AS mri_age, b.Gender AS mri_sex, b.Education AS mri_educ, b.eTIV AS mri_etiv,
               c.category AS clin_group, c.age AS clin_age, c.sex AS clin_sex
        FROM prov_participants p
        LEFT JOIN brain_measurements b ON b.`Subject` COLLATE utf8mb4_unicode_ci = p.local_participant_id
        LEFT JOIN clinical_symptom_data c ON c.participant_id COLLATE utf8mb4_unicode_ci = p.local_participant_id
        WHERE p.study_code = 'BERGEN_BGA'
        ORDER BY p.local_participant_id ASC
    ";
    $allParticipants = $db->query($sql)->fetchAll();

    // Also index full brain and clinical rows by Subject / participant_id
    $brainById = [];
    foreach ($db->query("SELECT * FROM brain_measurements")->fetchAll() as $r) {
        $brainById[$r['Subject']] = $r;
    }
    $clinById = [];
    foreach ($db->query("SELECT * FROM clinical_symptom_data")->fetchAll() as $r) {
        $clinById[$r['participant_id']] = $r;
    }

    $nTotal = count($allParticipants); // 94
    $nSingleModality = 0;
    $nUnresolvedConflict = 0;
    $nVisitOffsetExcluded = 0;
    $nCohortExcluded = 0;
    $nMissingVarsExcluded = 0;

    foreach ($allParticipants as $p) {
        $id = $p['local_participant_id'];
        $status = $p['identity_verification_status'];
        $bRow = $brainById[$id] ?? null;
        $cRow = $clinById[$id] ?? null;

        if ($status === 'single_table_only' || !$bRow || !$cRow) {
            $nSingleModality++;
            $excludedDetails[] = ['id' => $id, 'stage' => 'Single-Modality Only', 'reason' => $bRow ? 'Present in brain_measurements only (no clinical_symptom_data row)' : 'Present in clinical_symptom_data only (no brain_measurements row)'];
            continue;
        }
        if ($status === 'unresolved_conflict') {
            $nUnresolvedConflict++;
            $excludedDetails[] = ['id' => $id, 'stage' => 'Unresolved Demographic Conflict', 'reason' => (string)$p['verification_notes']];
            continue;
        }
        if ($visitRule === 'VERIFIED_STRICT_EXACT_AGE' && $status === 'verified_with_visit_offset') {
            $nVisitOffsetExcluded++;
            $excludedDetails[] = ['id' => $id, 'stage' => '+1 Year Visit-Age Offset (Strict Rule)', 'reason' => (string)$p['verification_notes']];
            continue;
        }

        // Cohort filter
        $cohort = (string)$p['harmonized_cohort']; // 'IBS' or 'HC'
        if ($cohortFilter === 'IBS_ONLY' && $cohort !== 'IBS') {
            $nCohortExcluded++;
            continue;
        }
        if ($cohortFilter === 'HC_ONLY' && $cohort !== 'HC' && $cohort !== 'Healthy Control') {
            $nCohortExcluded++;
            continue;
        }

        // Extract X and Y values
        $rawX = $bRow[$xVar] ?? null;
        $eTIV = isset($bRow['eTIV']) && $bRow['eTIV'] !== null ? (float)$bRow['eTIV'] : null;
        if ($rawX === null || $rawX === '') {
            $nMissingVarsExcluded++;
            $excludedDetails[] = ['id' => $id, 'stage' => 'Missing X Variable', 'reason' => "$xVar is NULL"];
            continue;
        }
        $xVal = (float)$rawX;
        if ($xVar !== 'eTIV' && $brainScale === 'DERIVED_RAW_MM3' && $eTIV !== null) {
            $xVal = $xVal * $eTIV;
        }

        $ySource = $bgaYMeasures[$yVar]['source'];
        $rawY = ($ySource === 'clinical') ? ($cRow[$yVar] ?? null) : ($bRow[$yVar] ?? null);
        if ($rawY === null || $rawY === '') {
            $nMissingVarsExcluded++;
            $excludedDetails[] = ['id' => $id, 'stage' => 'Missing Y Variable', 'reason' => "$yVar is NULL"];
            continue;
        }
        $yVal = (float)$rawY;

        // Check optional covariates for missingness
        $educVal = ($bRow['Education'] !== null && $bRow['Education'] !== '') ? (float)$bRow['Education'] : null;
        if ($covEduc && $educVal === null) {
            $nMissingVarsExcluded++;
            $excludedDetails[] = ['id' => $id, 'stage' => 'Missing Covariate (Education)', 'reason' => 'Education is NULL in brain_measurements'];
            continue;
        }

        $eligibleRows[] = [
            'participant_uid' => $p['participant_uid'],
            'local_id' => $id,
            'cohort' => $cohort,
            'sex' => (string)$p['harmonized_sex'],
            'sex_num' => ($p['harmonized_sex'] === 'F') ? 1.0 : 0.0,
            'age_clin' => (float)$cRow['age'],
            'age_mri' => (float)$bRow['Age'],
            'education' => $educVal,
            'etiv' => $eTIV,
            'status' => $status,
            'x' => $xVal,
            'y' => $yVal,
            'brain_row' => $bRow,
            'clin_row' => $cRow
        ];
    }

    $waterfall = [
        ['label' => 'Total Bergen BGA IDs', 'count' => $nTotal, 'desc' => 'Union of 78 MRI and 77 Clinical rows', 'excluded' => false],
        ['label' => 'Single-Modality Excluded', 'count' => -$nSingleModality, 'desc' => '17 MRI-only + 16 Clinical-only IDs', 'excluded' => true],
        ['label' => 'Unresolved Conflicts Excluded', 'count' => -$nUnresolvedConflict, 'desc' => 'BGA_072 (Sex) & BGA_070 (6yr Age)', 'excluded' => true],
        ['label' => 'Visit-Age Offset Rule', 'count' => -$nVisitOffsetExcluded, 'desc' => $visitRule === 'VERIFIED_STRICT_EXACT_AGE' ? '5 +1yr MRI age-offset IDs excluded' : '5 +1yr MRI age-offset IDs retained', 'excluded' => $nVisitOffsetExcluded > 0],
        ['label' => 'Cohort Subset Filter', 'count' => -$nCohortExcluded, 'desc' => 'Filter: ' . $cohortFilter, 'excluded' => $nCohortExcluded > 0],
        ['label' => 'Missing X/Y/Covariate', 'count' => -$nMissingVarsExcluded, 'desc' => $covEduc ? 'Includes 9 HC with Education = NULL' : 'Complete-case check on selected variables', 'excluded' => $nMissingVarsExcluded > 0],
        ['label' => 'Final Eligible Matched N', 'count' => count($eligibleRows), 'desc' => 'Unique verified participants in model', 'excluded' => false],
    ];

    if ($covEtiv && $xVar !== 'eTIV' && $brainScale === 'ETIV_PROPORTION') {
        $warnings[] = 'Double-Normalization Warning: Selected X variable (' . $xVar . ') is already stored as an eTIV-normalized proportion (Volume_mm3 / eTIV). Including eTIV again as an OLS covariate adjusts for total intracranial volume a second time. To test standard linear ICV adjustment, switch Brain Volume Scale to "Derived Raw Volume (mm³)" or uncheck eTIV covariate.';
    }
} else {
    // MARS_GSE146853: Matched Colonic Biopsy GC-MS Metabolomics + GSE146853 RNA-seq
    $allMarsSymbols = array_keys($marsYMeasures);
    $marsCountMatrix = stats_load_mars_gene_counts($allMarsSymbols);
    $selectedGeneMap = $marsCountMatrix[strtoupper($yVar)] ?? [];

    $gcmsAll = $db->query("
        SELECT g.*, p.harmonized_cohort, p.harmonized_sex, p.baseline_age, p.bmi,
               sm.id AS sm_id, sm.sample_title AS sm_sample_title, sm.geo_accession, sm.age AS sm_age, sm.gender AS sm_sex, sm.bmi AS sm_bmi
        FROM mars_gcms_biopsy_metabolites g
        LEFT JOIN prov_participants p ON p.participant_uid = g.participant_uid
        LEFT JOIN sample_metadata sm ON sm.geo_accession COLLATE utf8mb4_unicode_ci = g.matched_rnaseq_geo
        ORDER BY g.patient_id ASC, g.visit_number ASC
    ")->fetchAll();

    $nTotalBiopsies = count($gcmsAll); // 74
    $nUnlinkedOrAnomaly = 0;
    $nNoRnaseqMatch = 0;
    $nVisitFilterExcluded = 0;
    $nCohortExcluded = 0;
    $nMissingExcluded = 0;

    foreach ($gcmsAll as $row) {
        if ($row['short_sample_id'] === '554_unknown') {
            $nUnlinkedOrAnomaly++;
            $excludedDetails[] = ['id' => $row['full_sample_title'], 'stage' => 'Source Sample Anomaly (DQ-GCMS-ROW12-554UNK)', 'reason' => 'Row 12 has conflicting PID 10007589 vs Sample 554_unknown'];
            continue;
        }
        $sTitle = (string)($row['sm_sample_title'] ?: $row['full_sample_title']);
        if (empty($row['matched_rnaseq_geo']) || !isset($selectedGeneMap[$sTitle])) {
            $nNoRnaseqMatch++;
            $excludedDetails[] = ['id' => $row['full_sample_title'], 'stage' => 'GC-MS Biopsy Without Paired RNA-seq', 'reason' => 'No matching GSE146853 sample_title in filtered count matrix for this biopsy visit'];
            continue;
        }
        $vNum = (int)$row['visit_number'];
        if ($visitRule === 'VISIT_1_ONLY' && $vNum !== 1) {
            $nVisitFilterExcluded++;
            continue;
        }
        if ($visitRule === 'VISIT_2_ONLY' && $vNum !== 2) {
            $nVisitFilterExcluded++;
            continue;
        }

        $cohort = (string)$row['raw_cohort']; // 'IBS-D', 'IBS-C', 'Healthy'
        if ($cohortFilter === 'IBS_D_ONLY' && $cohort !== 'IBS-D') {
            $nCohortExcluded++;
            continue;
        }
        if ($cohortFilter === 'IBS_C_ONLY' && $cohort !== 'IBS-C') {
            $nCohortExcluded++;
            continue;
        }
        if ($cohortFilter === 'HC_ONLY' && $cohort !== 'Healthy') {
            $nCohortExcluded++;
            continue;
        }

        $rawX = $row[$xVar] ?? null;
        if ($rawX === null || $rawX === '') {
            $nMissingExcluded++;
            continue;
        }

        $ageVal = ((float)$row['sm_age'] > 0) ? (float)$row['sm_age'] : null;
        if ($covAge && $ageVal === null) {
            $nMissingExcluded++;
            $excludedDetails[] = ['id' => $row['full_sample_title'], 'stage' => 'Missing Age (0.00 in GEO)', 'reason' => 'GSM4914057 (10007600-1) has age = 0.00 (missing)'];
            continue;
        }
        $sexStr = trim((string)$row['sm_sex']);
        if ($covSex && $sexStr === '') {
            $nMissingExcluded++;
            continue;
        }

        $readCount = (float)$selectedGeneMap[$sTitle];
        $yLog2 = log($readCount + 1.0, 2);

        $eligibleRows[] = [
            'participant_uid' => $row['participant_uid'],
            'local_id' => $sTitle . ' (' . $row['matched_rnaseq_geo'] . ')',
            'sample_title' => $sTitle,
            'patient_id' => $row['patient_id'],
            'cohort' => $cohort === 'Healthy' ? 'Healthy Control' : $cohort,
            'sex' => $sexStr !== '' ? $sexStr : 'Missing',
            'sex_num' => ($sexStr === 'F') ? 1.0 : 0.0,
            'age_clin' => $ageVal,
            'age_mri' => $ageVal,
            'education' => null,
            'etiv' => null,
            'status' => 'verified',
            'x' => (float)$rawX,
            'y' => $yLog2,
            'gcms_row' => $row
        ];
    }

    if ($visitRule === 'ALL_MATCHED_BIOPSIES') {
        $warnings[] = 'Repeated-Measures Notice: "ALL_MATCHED_BIOPSIES" includes both Visit 1 and Visit 2 biopsies from longitudinal participants. Because Pearson/Spearman assumes independent observations, use "VISIT_1_ONLY" (1 biopsy per unique participant) for strict independent-sample inference.';
    }

    $waterfall = [
        ['label' => 'Total GC-MS Biopsies', 'count' => $nTotalBiopsies, 'desc' => 'Supplementary Data III rows', 'excluded' => false],
        ['label' => 'Sample ID Anomaly', 'count' => -$nUnlinkedOrAnomaly, 'desc' => 'Row 12 (554_unknown) excluded', 'excluded' => true],
        ['label' => 'Unpaired with RNA-seq', 'count' => -$nNoRnaseqMatch, 'desc' => 'Biopsies without matching GSM RNA-seq', 'excluded' => true],
        ['label' => 'Visit Rule Filter', 'count' => -$nVisitFilterExcluded, 'desc' => 'Rule: ' . $visitRule, 'excluded' => $nVisitFilterExcluded > 0],
        ['label' => 'Cohort Filter', 'count' => -$nCohortExcluded, 'desc' => 'Filter: ' . $cohortFilter, 'excluded' => $nCohortExcluded > 0],
        ['label' => 'Missing Covariates', 'count' => -$nMissingExcluded, 'desc' => 'Excludes 0.00 missing age if Age covariate active', 'excluded' => $nMissingExcluded > 0],
        ['label' => 'Final Eligible Samples', 'count' => count($eligibleRows), 'desc' => 'Paired RNA-seq + GC-MS biopsies', 'excluded' => false],
    ];
}

// ---------------------------------------------------------------------------
// 3. Run Descriptive, Bivariate, Covariate OLS & Multiple-Testing Computations
// ---------------------------------------------------------------------------
$xValues = array_column($eligibleRows, 'x');
$yValues = array_column($eligibleRows, 'y');

$descX = stats_descriptive($xValues);
$descY = stats_descriptive($yValues);

$bivarResult = ($statMethod === 'spearman')
    ? stats_spearman($xValues, $yValues)
    : stats_pearson($xValues, $yValues);

// Simple linear regression line for scatter plot (always computed via OLS on X, Y for visual trendline)
$olsSimple = stats_ols_covariate_adjusted($yValues, $xValues, []);

// Optional Covariate-Adjusted OLS Model
$activeCovariates = [];
foreach ($eligibleRows as $idx => $r) {
    if ($covAge && $r['age_mri'] !== null) {
        $activeCovariates['Age'][$idx] = (float)$r['age_mri'];
    }
    if ($covSex && ($r['sex'] === 'F' || $r['sex'] === 'M')) {
        $activeCovariates['Sex_Female'][$idx] = (float)$r['sex_num'];
    }
    if ($studyCode === 'BERGEN_BGA' && $covEduc && $r['education'] !== null) {
        $activeCovariates['Education_Yrs'][$idx] = (float)$r['education'];
    }
    if ($studyCode === 'BERGEN_BGA' && $covEtiv && $r['etiv'] !== null) {
        $activeCovariates['eTIV_mm3'][$idx] = (float)$r['etiv'];
    }
}
$olsAdjusted = !empty($activeCovariates)
    ? stats_ols_covariate_adjusted($yValues, $xValues, $activeCovariates)
    : null;

// Multiple-Testing Family Comparison across all ROIs (BERGEN_BGA) or all 15 DE Genes (MARS_GSE146853)
$familyRawResults = [];
$familyPMap = [];

if ($studyCode === 'BERGEN_BGA') {
    foreach ($bgaBrainMeasures as $roiCode => $roiLabel) {
        $rx = [];
        $ry = [];
        foreach ($eligibleRows as $er) {
            $bRow = $er['brain_row'];
            if (!isset($bRow[$roiCode]) || $bRow[$roiCode] === null || $bRow[$roiCode] === '') {
                continue;
            }
            $v = (float)$bRow[$roiCode];
            if ($roiCode !== 'eTIV' && $brainScale === 'DERIVED_RAW_MM3' && $er['etiv'] !== null) {
                $v *= (float)$er['etiv'];
            }
            $rx[] = $v;
            $ry[] = $er['y'];
        }
        $res = ($statMethod === 'spearman') ? stats_spearman($rx, $ry) : stats_pearson($rx, $ry);
        $familyRawResults[$roiCode] = [
            'code' => $roiCode,
            'label' => $roiLabel,
            'n' => $res['n'] ?? 0,
            'estimate' => $res['estimate'] ?? null,
            'ci_low' => $res['ci_low'] ?? null,
            'ci_high' => $res['ci_high'] ?? null,
            'p_value' => $res['p_value'] ?? null,
        ];
        if (isset($res['p_value']) && $res['p_value'] !== null) {
            $familyPMap[$roiCode] = (float)$res['p_value'];
        }
    }
} else {
    // Family across all 15 significant DE genes against selected X metabolite
    foreach ($marsYMeasures as $sym => $gMeta) {
        $cMap = $marsCountMatrix[strtoupper($sym)] ?? [];
        $rx = [];
        $ry = [];
        foreach ($eligibleRows as $er) {
            $sTitle = $er['sample_title'];
            if (!isset($cMap[$sTitle])) {
                continue;
            }
            $rx[] = $er['x'];
            $ry[] = log((float)$cMap[$sTitle] + 1.0, 2);
        }
        $res = ($statMethod === 'spearman') ? stats_spearman($rx, $ry) : stats_pearson($rx, $ry);
        $familyRawResults[$sym] = [
            'code' => $sym,
            'label' => $gMeta['label'],
            'n' => $res['n'] ?? 0,
            'estimate' => $res['estimate'] ?? null,
            'ci_low' => $res['ci_low'] ?? null,
            'ci_high' => $res['ci_high'] ?? null,
            'p_value' => $res['p_value'] ?? null,
        ];
        if (isset($res['p_value']) && $res['p_value'] !== null) {
            $familyPMap[$sym] = (float)$res['p_value'];
        }
    }
}

$adjBh   = stats_adjust_pvalues($familyPMap, 'BH_FDR');
$adjHolm = stats_adjust_pvalues($familyPMap, 'HOLM');
foreach ($familyRawResults as $code => &$fRow) {
    $fRow['p_bh_fdr'] = isset($adjBh[$code]) ? (is_array($adjBh[$code]) ? $adjBh[$code]['p_adjusted'] : (float)$adjBh[$code]) : null;
    $fRow['p_holm']   = isset($adjHolm[$code]) ? (is_array($adjHolm[$code]) ? $adjHolm[$code]['p_adjusted'] : (float)$adjHolm[$code]) : null;
}
unset($fRow);

// Sort family table by raw p-value ascending
uasort($familyRawResults, function (array $a, array $b): int {
    $pa = $a['p_value'] ?? 999.0;
    $pb = $b['p_value'] ?? 999.0;
    return $pa <=> $pb;
});

// ---------------------------------------------------------------------------
// 4. Construct Run Provenance Manifest & Persist to prov_analysis_runs
// ---------------------------------------------------------------------------
$xUnit = ($studyCode === 'BERGEN_BGA')
    ? ($xVar === 'eTIV' ? 'Raw mm³' : ($brainScale === 'DERIVED_RAW_MM3' ? 'Derived Raw Volume (mm³ = Proportion × eTIV)' : 'eTIV-Normalized Proportion (Volume_mm3 / eTIV)'))
    : 'Normalized peak area / biopsy dry weight (GC-MS)';
$yUnit = ($studyCode === 'BERGEN_BGA')
    ? $bgaYMeasures[$yVar]['unit']
    : $marsYMeasures[$yVar]['unit'];

$datasetVersions = ($studyCode === 'BERGEN_BGA')
    ? 'PSN_IBS_MRI_V1 (78 rows) + IBS_DISTRESS_CLIN_V1 (77 rows)'
    : 'GSE146853_COUNTS_V1 (83 biopsies) + MARS_GCMS_BIOPSY_V1 (74 biopsies)';

$uniqueParticipantCount = count(array_unique(array_column($eligibleRows, 'participant_uid')));

$manifestParams = [
    'study_code' => $studyCode,
    'dataset_versions' => $datasetVersions,
    'visit_selection_rule' => $visitRule,
    'cohort_filter' => $cohortFilter,
    'x_variable' => $xVar,
    'x_unit' => $xUnit,
    'y_variable' => $yVar,
    'y_unit' => $yUnit,
    'brain_scale' => $studyCode === 'BERGEN_BGA' ? $brainScale : 'N/A',
    'stat_method' => $statMethod,
    'covariates' => array_keys($activeCovariates),
    'multiple_testing_method' => $mtMethod,
    'n_eligible_observations' => count($eligibleRows),
    'n_unique_participants' => $uniqueParticipantCount,
];
$runHash = hash('sha256', json_encode($manifestParams));
$softwareVersion = 'PHP ' . PHP_VERSION . ' / NeuroGI Exact Stats Engine v' . PLATFORM_VERSION;

$fullManifest = [
    'run_hash' => $runHash,
    'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'software_version' => $softwareVersion,
    'parameters' => $manifestParams,
    'inclusion_exclusion_waterfall' => $waterfall,
    'descriptive_statistics' => ['x' => $descX, 'y' => $descY],
    'bivariate_association' => $bivarResult,
    'covariate_adjusted_ols' => $olsAdjusted,
    'multiple_testing_family_count' => count($familyRawResults),
    'selected_pair_multiple_testing' => ($studyCode === 'BERGEN_BGA')
        ? ($familyRawResults[$xVar] ?? null)
        : ($familyRawResults[$yVar] ?? null),
    'warnings' => $warnings,
    'limitations' => [
        'Observational cross-sectional/longitudinal association only; correlation never implies causation.',
        'Bergen BGA structural MRI regional volumes are pre-normalized by eTIV in the source table.',
        'Unresolved demographic conflicts (BGA_072 sex mismatch, BGA_070 6-yr age discrepancy) are strictly excluded.',
        'Exploratory p-values across 36 brain regions or 15 DE genes require multiple-testing correction (BH-FDR / Holm reported).'
    ]
];

// Log run non-destructively into prov_analysis_runs
try {
    $stLog = $db->prepare("
        INSERT INTO prov_analysis_runs
        (run_hash, study_code, dataset_versions, visit_selection_rule, cohort_filter, exclude_unresolved, exclude_age_offset,
         x_measure_code, y_measure_code, stat_method, covariates_json, multiple_testing_method, n_eligible, n_excluded,
         exclusion_breakdown_json, result_summary_json, software_version)
        VALUES (:rh, :sc, :dv, :vr, :cf, 1, :eao, :xm, :ym, :sm, :cov, :mt, :nel, :nex, :ebj, :rsj, :sv)
        ON DUPLICATE KEY UPDATE created_at = CURRENT_TIMESTAMP
    ");
    $stLog->execute([
        ':rh' => $runHash,
        ':sc' => $studyCode,
        ':dv' => $datasetVersions,
        ':vr' => $visitRule,
        ':cf' => $cohortFilter,
        ':eao' => ($visitRule === 'VERIFIED_STRICT_EXACT_AGE') ? 1 : 0,
        ':xm' => $xVar,
        ':ym' => $yVar,
        ':sm' => $statMethod,
        ':cov' => json_encode(array_keys($activeCovariates)),
        ':mt' => $mtMethod,
        ':nel' => count($eligibleRows),
        ':nex' => count($excludedDetails),
        ':ebj' => json_encode($waterfall),
        ':rsj' => json_encode(['bivariate' => $bivarResult, 'ols' => $olsAdjusted]),
        ':sv' => $softwareVersion
    ]);
} catch (Throwable $e) {
    // Non-blocking if read-only
}

// ---------------------------------------------------------------------------
// 5. Handle Reproducible Exports (?export=csv | json | r | py)
// ---------------------------------------------------------------------------
$exportType = strtolower(trim((string)($_GET['export'] ?? '')));
if (in_array($exportType, ['csv', 'json', 'r', 'py'], true)) {
    $shortHash = substr($runHash, 0, 12);
    if ($exportType === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="neurogi_analysis_manifest_' . $shortHash . '.json"');
        echo json_encode($fullManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($exportType === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="neurogi_matched_data_' . $shortHash . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['# NeuroGI Atlas Reproducible Matched Analysis Export']);
        fputcsv($out, ['# Run SHA-256', $runHash]);
        fputcsv($out, ['# Study', $studyCode, 'Datasets', $datasetVersions]);
        fputcsv($out, ['# Visit Rule', $visitRule, 'Cohort Filter', $cohortFilter]);
        fputcsv($out, ['# X Variable', $xVar . ' (' . $xUnit . ')', 'Y Variable', $yVar . ' (' . $yUnit . ')']);
        fputcsv($out, ['# Method', $statMethod, 'Estimate', (string)($bivarResult['estimate'] ?? 'NA'), 'P-Value', (string)($bivarResult['p_value'] ?? 'NA')]);
        fputcsv($out, ['participant_uid', 'local_id', 'cohort', 'sex', 'age_years', 'education_years', 'eTIV_mm3', 'verification_status', 'x_' . $xVar, 'y_' . $yVar]);
        foreach ($eligibleRows as $r) {
            fputcsv($out, [
                $r['participant_uid'],
                $r['local_id'],
                $r['cohort'],
                $r['sex'],
                $r['age_mri'] ?? '',
                $r['education'] ?? '',
                $r['etiv'] ?? '',
                $r['status'],
                $r['x'],
                $r['y']
            ]);
        }
        fclose($out);
        exit;
    }
    if ($exportType === 'r') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="reproduce_neurogi_' . $shortHash . '.R"');
        $xCsv = implode(', ', array_map(fn($v) => sprintf('%.8g', $v), $xValues));
        $yCsv = implode(', ', array_map(fn($v) => sprintf('%.8g', $v), $yValues));
        $idCsv = implode(', ', array_map(fn($r) => '"' . addslashes($r['local_id']) . '"', $eligibleRows));
        echo "# ============================================================================\n";
        echo "# NeuroGI Atlas — Reproducible R Analysis Script\n";
        echo "# Run Hash: {$runHash}\n";
        echo "# Study: {$studyCode} | Datasets: {$datasetVersions}\n";
        echo "# Visit Rule: {$visitRule} | Cohort: {$cohortFilter} | Eligible N: " . count($eligibleRows) . "\n";
        echo "# X: {$xVar} ({$xUnit})\n";
        echo "# Y: {$yVar} ({$yUnit})\n";
        echo "# ============================================================================\n\n";
        echo "df <- data.frame(\n";
        echo "  participant_id = c({$idCsv}),\n";
        echo "  x = c({$xCsv}),\n";
        echo "  y = c({$yCsv})\n";
        echo ")\n\n";
        echo "cat('Eligible Unique Participants:', nrow(df), '\\n')\n";
        echo "cat('X Mean (SD):', mean(df\$x), sd(df\$x), '\\n')\n";
        echo "cat('Y Mean (SD):', mean(df\$y), sd(df\$y), '\\n\\n')\n";
        echo "res <- cor.test(df\$x, df\$y, method = '{$statMethod}', exact = FALSE)\n";
        echo "print(res)\n\n";
        echo "fit <- lm(y ~ x, data = df)\n";
        echo "print(summary(fit))\n";
        exit;
    }
    if ($exportType === 'py') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="reproduce_neurogi_' . $shortHash . '.py"');
        $xCsv = implode(', ', array_map(fn($v) => sprintf('%.8g', $v), $xValues));
        $yCsv = implode(', ', array_map(fn($v) => sprintf('%.8g', $v), $yValues));
        echo "#!/usr/bin/env python3\n";
        echo "\"\"\"\n";
        echo "NeuroGI Atlas — Reproducible Python Analysis Recipe\n";
        echo "Run Hash: {$runHash}\n";
        echo "Study: {$studyCode} | Datasets: {$datasetVersions}\n";
        echo "Visit Rule: {$visitRule} | Cohort: {$cohortFilter} | Eligible N: " . count($eligibleRows) . "\n";
        echo "X: {$xVar} ({$xUnit})\n";
        echo "Y: {$yVar} ({$yUnit})\n";
        echo "\"\"\"\n";
        echo "import numpy as np\n";
        echo "from scipy import stats\n\n";
        echo "x = np.array([{$xCsv}], dtype=float)\n";
        echo "y = np.array([{$yCsv}], dtype=float)\n\n";
        echo "print(f'Eligible N = {len(x)}')\n";
        echo "print(f'X Mean = {np.mean(x):.6f}, SD = {np.std(x, ddof=1):.6f}')\n";
        echo "print(f'Y Mean = {np.mean(y):.6f}, SD = {np.std(y, ddof=1):.6f}')\n\n";
        if ($statMethod === 'spearman') {
            echo "r, p = stats.spearmanr(x, y)\n";
            echo "print(f'Spearman rho = {r:.6f}, two-tailed p = {p:.6g}')\n";
        } else {
            echo "r, p = stats.pearsonr(x, y)\n";
            echo "print(f'Pearson r = {r:.6f}, two-tailed p = {p:.6g}')\n";
        }
        exit;
    }
}

$pageTitle = 'Reproducible Brain–Symptom & Mucosal Biopsy Analysis | NeuroGI Atlas';
$activeNav = 'analysis';
require_once __DIR__ . '/includes/header.php';

$currentQuery = $_GET;
unset($currentQuery['export']);
$baseExportUrl = 'analysis.php?' . http_build_query($currentQuery);
?>

<section class="page-hero">
  <div class="page-hero-inner">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:1rem;">
      <div>
        <div class="hero-badge" style="margin-bottom:0.6rem;">PHASE 4 &bull; REPRODUCIBLE MATCHED-COHORT STATISTICAL ENGINE</div>
        <h2>Reproducible Exploratory Analysis</h2>
        <p>
          Execute study-scoped bivariate correlations and covariate-adjusted linear models on verified matched participants.
          Every run exposes its full inclusion/exclusion waterfall, brain volume normalization provenance, multiple-testing family adjustments, and downloadable R/Python/JSON/CSV manifests.
        </p>
      </div>
      <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
        <a href="<?= h($baseExportUrl . '&export=csv') ?>" class="btn btn-outline btn-sm">Download Matched CSV</a>
        <a href="<?= h($baseExportUrl . '&export=json') ?>" class="btn btn-outline btn-sm">JSON Manifest</a>
        <a href="<?= h($baseExportUrl . '&export=r') ?>" class="btn btn-outline btn-sm">R Script (.R)</a>
        <a href="<?= h($baseExportUrl . '&export=py') ?>" class="btn btn-outline btn-sm">Python Recipe (.py)</a>
      </div>
    </div>

    <!-- Worked Research Presets -->
    <div style="margin-top:1rem; padding-top:0.85rem; border-top:1px solid var(--border-light); display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center; font-size:0.8rem;">
      <strong style="color:var(--navy-dark);">Worked Research Presets:</strong>
      <a href="analysis.php?study=BERGEN_BGA&amp;x=Left-Thalamus&amp;y=IBS_SSS&amp;visit_rule=VERIFIED_INCL_OFFSET&amp;cohort=ALL&amp;method=pearson&amp;cov_age=1&amp;cov_sex=1" class="badge badge-teal" style="text-decoration:none;">
        1. Bergen: Left Thalamus vs IBS-SSS (All Verified n=59, Age+Sex Adj)
      </a>
      <a href="analysis.php?study=BERGEN_BGA&amp;x=Left-Thalamus&amp;y=IBS_SSS&amp;visit_rule=VERIFIED_STRICT_EXACT_AGE&amp;cohort=ALL&amp;method=pearson&amp;cov_age=1&amp;cov_sex=1" class="badge badge-amber" style="text-decoration:none;">
        2. Sensitivity Check: Exclude +1Yr Visit-Age Offset (Strict n=54)
      </a>
      <a href="analysis.php?study=BERGEN_BGA&amp;x=Left-Hippocampus&amp;y=RBANS_TotalScale&amp;visit_rule=VERIFIED_INCL_OFFSET&amp;cohort=ALL&amp;method=pearson&amp;cov_age=1&amp;cov_educ=1" class="badge badge-blue" style="text-decoration:none;">
        3. Bergen: Left Hippocampus vs RBANS Total (with Education Missingness Audit)
      </a>
      <a href="analysis.php?study=MARS_GSE146853&amp;x=butyrate&amp;y=SLC26A3&amp;visit_rule=VISIT_1_ONLY&amp;cohort=ALL&amp;method=spearman" class="badge badge-green" style="text-decoration:none;">
        4. Mars (GSE146853): Biopsy Butyrate vs SLC26A3 Expression (Visit 1 Paired)
      </a>
    </div>
  </div>
</section>

<div class="container">

  <!-- Study & Variable Configuration Form -->
  <div class="table-card" style="margin-bottom:1.5rem;">
    <div class="table-card-header">
      <h3>1. Configure Study, Linkage Rule, Variables &amp; Model Settings</h3>
      <div style="display:flex; gap:0.5rem;">
        <a href="analysis.php?study=BERGEN_BGA" class="btn <?= $studyCode === 'BERGEN_BGA' ? 'btn-primary' : 'btn-outline' ?> btn-sm">Study A: Bergen Brain–Symptom (BERGEN_BGA)</a>
        <a href="analysis.php?study=MARS_GSE146853" class="btn <?= $studyCode === 'MARS_GSE146853' ? 'btn-primary' : 'btn-outline' ?> btn-sm">Study B: Mars Biopsy Multi-Omics (MARS_GSE146853)</a>
      </div>
    </div>
    <form method="get" action="analysis.php" style="padding:1.25rem;">
      <input type="hidden" name="study" value="<?= h($studyCode) ?>">
      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:1rem; margin-bottom:1rem;">

        <?php if ($studyCode === 'BERGEN_BGA'): ?>
          <div>
            <label class="form-label" for="sel-x">Brain MRI Variable (X)</label>
            <select id="sel-x" name="x" class="form-select">
              <?php foreach ($bgaBrainMeasures as $code => $lbl): ?>
                <option value="<?= h($code) ?>" <?= $xVar === $code ? 'selected' : '' ?>><?= h($lbl) ?> (<?= h($code) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="form-label" for="sel-y">Clinical Symptom or RBANS Measure (Y)</label>
            <select id="sel-y" name="y" class="form-select">
              <?php foreach ($bgaYMeasures as $code => $meta): ?>
                <option value="<?= h($code) ?>" <?= $yVar === $code ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="form-label" for="sel-visit">Visit &amp; Concordance Rule</label>
            <select id="sel-visit" name="visit_rule" class="form-select">
              <option value="VERIFIED_INCL_OFFSET" <?= $visitRule === 'VERIFIED_INCL_OFFSET' ? 'selected' : '' ?>>Verified Matched (Incl. 5 +1yr MRI Visit Offset, Max N=59)</option>
              <option value="VERIFIED_STRICT_EXACT_AGE" <?= $visitRule === 'VERIFIED_STRICT_EXACT_AGE' ? 'selected' : '' ?>>Sensitivity: Strict Exact Age Match Only (Max N=54)</option>
            </select>
          </div>
          <div>
            <label class="form-label" for="sel-cohort">Cohort Subset</label>
            <select id="sel-cohort" name="cohort" class="form-select">
              <option value="ALL" <?= $cohortFilter === 'ALL' ? 'selected' : '' ?>>All Verified Participants (IBS + HC)</option>
              <option value="IBS_ONLY" <?= $cohortFilter === 'IBS_ONLY' ? 'selected' : '' ?>>IBS Patients Only</option>
              <option value="HC_ONLY" <?= $cohortFilter === 'HC_ONLY' ? 'selected' : '' ?>>Healthy Controls Only</option>
            </select>
          </div>
          <div>
            <label class="form-label" for="sel-scale">Brain Volume Scale / Normalization</label>
            <select id="sel-scale" name="brain_scale" class="form-select">
              <option value="ETIV_PROPORTION" <?= $brainScale === 'ETIV_PROPORTION' ? 'selected' : '' ?>>Stored eTIV-Normalized Proportion (Vol / eTIV)</option>
              <option value="DERIVED_RAW_MM3" <?= $brainScale === 'DERIVED_RAW_MM3' ? 'selected' : '' ?>>Derived Raw Volume (mm³ = Proportion × eTIV)</option>
            </select>
          </div>
        <?php else: ?>
          <div>
            <label class="form-label" for="sel-x">Colonic Biopsy GC-MS Metabolite (X)</label>
            <select id="sel-x" name="x" class="form-select">
              <?php foreach ($marsXMeasures as $code => $lbl): ?>
                <option value="<?= h($code) ?>" <?= $xVar === $code ? 'selected' : '' ?>><?= h($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="form-label" for="sel-y">Colonic Biopsy DE Gene Expression (Y)</label>
            <select id="sel-y" name="y" class="form-select">
              <?php foreach ($marsYMeasures as $code => $meta): ?>
                <option value="<?= h($code) ?>" <?= $yVar === $code ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="form-label" for="sel-visit">Longitudinal Biopsy Visit Rule</label>
            <select id="sel-visit" name="visit_rule" class="form-select">
              <option value="VISIT_1_ONLY" <?= $visitRule === 'VISIT_1_ONLY' ? 'selected' : '' ?>>Visit 1 Baseline Biopsies Only (Independent Subjects)</option>
              <option value="VISIT_2_ONLY" <?= $visitRule === 'VISIT_2_ONLY' ? 'selected' : '' ?>>Visit 2 (6-Month) Biopsies Only (Independent Subjects)</option>
              <option value="ALL_MATCHED_BIOPSIES" <?= $visitRule === 'ALL_MATCHED_BIOPSIES' ? 'selected' : '' ?>>All Paired Biopsies (Visit 1 + Visit 2 Pooled)</option>
            </select>
          </div>
          <div>
            <label class="form-label" for="sel-cohort">Subtype Filter</label>
            <select id="sel-cohort" name="cohort" class="form-select">
              <option value="ALL" <?= $cohortFilter === 'ALL' ? 'selected' : '' ?>>All Paired Subjects (IBS-D + IBS-C + HC)</option>
              <option value="IBS_D_ONLY" <?= $cohortFilter === 'IBS_D_ONLY' ? 'selected' : '' ?>>IBS-D Only</option>
              <option value="IBS_C_ONLY" <?= $cohortFilter === 'IBS_C_ONLY' ? 'selected' : '' ?>>IBS-C Only</option>
              <option value="HC_ONLY" <?= $cohortFilter === 'HC_ONLY' ? 'selected' : '' ?>>Healthy Controls Only</option>
            </select>
          </div>
        <?php endif; ?>

        <div>
          <label class="form-label" for="sel-method">Association Method</label>
          <select id="sel-method" name="method" class="form-select">
            <option value="pearson" <?= $statMethod === 'pearson' ? 'selected' : '' ?>>Pearson Linear Correlation (r + Fisher z 95% CI)</option>
            <option value="spearman" <?= $statMethod === 'spearman' ? 'selected' : '' ?>>Spearman Rank Correlation (ρ + Tie-Corrected)</option>
          </select>
        </div>

        <div>
          <label class="form-label" for="sel-mt">Multiple-Testing Family Correction</label>
          <select id="sel-mt" name="mt" class="form-select">
            <option value="BH_FDR" <?= $mtMethod === 'BH_FDR' ? 'selected' : '' ?>>Benjamini–Hochberg FDR (q-value)</option>
            <option value="HOLM" <?= $mtMethod === 'HOLM' ? 'selected' : '' ?>>Holm–Bonferroni Step-Down</option>
          </select>
        </div>
      </div>

      <!-- Optional Covariates Row -->
      <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; padding-top:0.85rem; border-top:1px solid var(--border-light);">
        <div style="display:flex; gap:1.15rem; flex-wrap:wrap; align-items:center; font-size:0.84rem;">
          <strong style="color:var(--navy-dark);">Optional OLS Covariates:</strong>
          <label style="display:inline-flex; align-items:center; gap:0.35rem; cursor:pointer;">
            <input type="checkbox" name="cov_age" value="1" <?= $covAge ? 'checked' : '' ?>> Age (years)
          </label>
          <label style="display:inline-flex; align-items:center; gap:0.35rem; cursor:pointer;">
            <input type="checkbox" name="cov_sex" value="1" <?= $covSex ? 'checked' : '' ?>> Sex (Female=1, Male=0)
          </label>
          <?php if ($studyCode === 'BERGEN_BGA'): ?>
            <label style="display:inline-flex; align-items:center; gap:0.35rem; cursor:pointer;">
              <input type="checkbox" name="cov_educ" value="1" <?= $covEduc ? 'checked' : '' ?>> Education (years — NULL in 9 HC)
            </label>
            <label style="display:inline-flex; align-items:center; gap:0.35rem; cursor:pointer;">
              <input type="checkbox" name="cov_etiv" value="1" <?= $covEtiv ? 'checked' : '' ?>> eTIV (mm³ — only for Raw Volume scale)
            </label>
          <?php endif; ?>
        </div>
        <button type="submit" class="btn btn-primary">Run Reproducible Analysis</button>
      </div>
    </form>
  </div>

  <!-- Methodological Warnings -->
  <?php foreach ($warnings as $w): ?>
    <div class="dq-alert dq-alert-critical">
      <strong>Methodological Safeguard Warning:</strong> <?= h($w) ?>
    </div>
  <?php endforeach; ?>

  <!-- Brain Volume Normalization & ICV Provenance Box (Bergen BGA) -->
  <?php if ($studyCode === 'BERGEN_BGA'): ?>
    <div class="prov-banner">
      <strong>Brain Volume Normalization &amp; Intracranial Volume (ICV) Provenance:</strong>
      In <code>brain_measurements</code> (Bergen Brain-Gut Study, <code>PSN_IBS_MRI_V1</code>), <code>eTIV</code> is stored in raw cubic millimeters ($\text{mm}^3$, range $1.22 \times 10^6$ to $1.74 \times 10^6\text{ mm}^3$), whereas all 35 regional subcortical/ventricular columns (e.g., <code><?= h($xVar) ?></code>) are <strong>already pre-divided by each subject's <code>eTIV</code></strong> ($\text{Proportion} = \text{Volume}_{\text{mm}^3} / \text{eTIV}$).
      Currently active scale: <strong><?= $brainScale === 'DERIVED_RAW_MM3' ? 'Derived Raw Volume (mm³ = Stored Proportion × eTIV)' : 'Stored eTIV-Normalized Proportion (No second division applied)' ?></strong>.
    </div>
  <?php endif; ?>

  <!-- 2. Sample Eligibility & Exclusion Waterfall -->
  <div style="margin-bottom:0.5rem; display:flex; justify-content:space-between; align-items:center;">
    <h3 style="font-size:1.05rem; color:var(--navy-dark);">2. Sample Eligibility &amp; Exclusion Waterfall</h3>
    <span style="font-size:0.8rem; color:var(--navy-subtle);">Unresolved participant links (<code>BGA_072</code>, <code>BGA_070</code>) are excluded by default</span>
  </div>
  <div class="waterfall-grid">
    <?php foreach ($waterfall as $wf): ?>
      <div class="waterfall-step <?= $wf['excluded'] ? 'excluded' : '' ?>">
        <div class="wf-label"><?= h($wf['label']) ?></div>
        <div class="wf-num" style="color:<?= $wf['excluded'] ? '#be123c' : 'var(--navy-dark)' ?>;">
          <?= $wf['count'] >= 0 ? (int)$wf['count'] : (int)$wf['count'] ?>
        </div>
        <div class="wf-desc"><?= h($wf['desc']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- 3. Scatter Plot + Statistical Results Grid -->
  <div style="display:grid; grid-template-columns: 1.35fr 1fr; gap:1.5rem; margin-bottom:1.75rem;">

    <!-- Left: Pure SVG Scatter Plot with OLS Regression Line -->
    <div class="table-card" style="margin-bottom:0;">
      <div class="table-card-header">
        <div>
          <h3>3. Matched Scatter Plot &amp; Linear Trend</h3>
          <div style="font-size:0.78rem; color:var(--navy-subtle);">
            X: <code><?= h($xVar) ?></code> (<?= h($xUnit) ?>) vs Y: <code><?= h($yVar) ?></code> (<?= h($yUnit) ?>)
          </div>
        </div>
        <span class="badge badge-teal">N = <?= count($eligibleRows) ?> (<?= $uniqueParticipantCount ?> Unique Subjects)</span>
      </div>
      <div style="padding:1.25rem;">
        <?php if (count($eligibleRows) >= 3 && $descX['min'] !== null && $descY['min'] !== null): ?>
          <?php
            $svgW = 640;
            $svgH = 400;
            $padL = 68;
            $padR = 28;
            $padT = 24;
            $padB = 52;
            $plotW = $svgW - $padL - $padR;
            $plotH = $svgH - $padT - $padB;

            $xMin = (float)$descX['min'];
            $xMax = (float)$descX['max'];
            $yMin = (float)$descY['min'];
            $yMax = (float)$descY['max'];

            $xSpan = ($xMax - $xMin) > 1e-12 ? ($xMax - $xMin) : 1.0;
            $ySpan = ($yMax - $yMin) > 1e-12 ? ($yMax - $yMin) : 1.0;

            // Add 6% padding
            $xMinPlot = $xMin - 0.06 * $xSpan;
            $xMaxPlot = $xMax + 0.06 * $xSpan;
            $yMinPlot = $yMin - 0.06 * $ySpan;
            $yMaxPlot = $yMax + 0.06 * $ySpan;

            $mapX = fn(float $v): float => $padL + (($v - $xMinPlot) / ($xMaxPlot - $xMinPlot)) * $plotW;
            $mapY = fn(float $v): float => $padT + $plotH - (($v - $yMinPlot) / ($yMaxPlot - $yMinPlot)) * $plotH;

            // Trendline endpoints
            $b0 = $olsSimple['coefficients']['Intercept']['beta'] ?? null;
            $b1 = $olsSimple['coefficients']['X']['beta'] ?? null;
          ?>
          <svg viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" width="100%" height="auto" role="img" aria-label="Scatter plot of <?= h($xVar) ?> versus <?= h($yVar) ?>" style="background:#f8fafc; border:1px solid var(--border-light); border-radius:var(--radius-sm);">
            <!-- Grid lines & Y ticks -->
            <?php for ($i = 0; $i <= 4; $i++): ?>
              <?php
                $yv = $yMinPlot + ($i / 4) * ($yMaxPlot - $yMinPlot);
                $py = $mapY($yv);
              ?>
              <line x1="<?= $padL ?>" y1="<?= round($py, 1) ?>" x2="<?= $svgW - $padR ?>" y2="<?= round($py, 1) ?>" stroke="#e2e8f0" stroke-dasharray="3,3"/>
              <text x="<?= $padL - 8 ?>" y="<?= round($py + 4, 1) ?>" text-anchor="end" font-size="11" fill="#475569"><?= h(sprintf('%.4g', $yv)) ?></text>
            <?php endfor; ?>

            <!-- X ticks -->
            <?php for ($i = 0; $i <= 4; $i++): ?>
              <?php
                $xv = $xMinPlot + ($i / 4) * ($xMaxPlot - $xMinPlot);
                $px = $mapX($xv);
              ?>
              <line x1="<?= round($px, 1) ?>" y1="<?= $padT ?>" x2="<?= round($px, 1) ?>" y2="<?= $padT + $plotH ?>" stroke="#e2e8f0" stroke-dasharray="3,3"/>
              <text x="<?= round($px, 1) ?>" y="<?= $padT + $plotH + 18 ?>" text-anchor="middle" font-size="11" fill="#475569"><?= h(sprintf('%.4g', $xv)) ?></text>
            <?php endfor; ?>

            <!-- Axes -->
            <line x1="<?= $padL ?>" y1="<?= $padT ?>" x2="<?= $padL ?>" y2="<?= $padT + $plotH ?>" stroke="#0f172a" stroke-width="1.5"/>
            <line x1="<?= $padL ?>" y1="<?= $padT + $plotH ?>" x2="<?= $svgW - $padR ?>" y2="<?= $padT + $plotH ?>" stroke="#0f172a" stroke-width="1.5"/>

            <!-- OLS Trendline -->
            <?php if ($b0 !== null && $b1 !== null): ?>
              <?php
                $yLine1 = $b0 + $b1 * $xMin;
                $yLine2 = $b0 + $b1 * $xMax;
              ?>
              <line x1="<?= round($mapX($xMin), 1) ?>" y1="<?= round($mapY($yLine1), 1) ?>"
                    x2="<?= round($mapX($xMax), 1) ?>" y2="<?= round($mapY($yLine2), 1) ?>"
                    stroke="#0f766e" stroke-width="2.2" stroke-dasharray="6,4"/>
            <?php endif; ?>

            <!-- Points -->
            <?php foreach ($eligibleRows as $er): ?>
              <?php
                $cx = round($mapX($er['x']), 1);
                $cy = round($mapY($er['y']), 1);
                $isHc = ($er['cohort'] === 'Healthy Control');
                $isHi = ($highlightId !== '' && $er['local_id'] === $highlightId);
                $fill = $isHi ? '#e11d48' : ($isHc ? '#2563eb' : '#0f766e');
                $rRad = $isHi ? 7.5 : ($er['status'] === 'verified_with_visit_offset' ? 6.0 : 5.2);
                $stroke = ($er['status'] === 'verified_with_visit_offset') ? '#d97706' : '#ffffff';
              ?>
              <a href="participant.php?uid=<?= urlencode($er['participant_uid']) ?>">
                <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $rRad ?>" fill="<?= $fill ?>" fill-opacity="0.85" stroke="<?= $stroke ?>" stroke-width="1.6">
                  <title><?= h($er['local_id'] . ' [' . $er['cohort'] . ', ' . $er['status'] . '] | X=' . sprintf('%.6g', $er['x']) . ', Y=' . sprintf('%.6g', $er['y'])) ?></title>
                </circle>
              </a>
            <?php endforeach; ?>

            <!-- Axis Titles -->
            <text x="<?= $padL + $plotW / 2 ?>" y="<?= $svgH - 10 ?>" text-anchor="middle" font-size="12" font-weight="600" fill="#0f172a">
              <?= h($xVar . ' (' . $xUnit . ')') ?>
            </text>
            <text x="16" y="<?= $padT + $plotH / 2 ?>" text-anchor="middle" font-size="12" font-weight="600" fill="#0f172a" transform="rotate(-90 16 <?= $padT + $plotH / 2 ?>)">
              <?= h($yVar . ' (' . $yUnit . ')') ?>
            </text>
          </svg>

          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem; margin-top:0.65rem; font-size:0.78rem; color:var(--navy-muted);">
            <div style="display:flex; gap:1rem; align-items:center;">
              <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#0f766e;"></span> IBS Patient</span>
              <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#2563eb;"></span> Healthy Control</span>
              <span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#0f766e;border:2px solid #d97706;"></span> +1 Yr Visit Offset (Click any point to inspect participant)</span>
            </div>
            <div>Dashed line: Unadjusted OLS fit</div>
          </div>
        <?php else: ?>
          <div class="dq-alert dq-alert-critical">Insufficient observations ($N < 3$) to render scatter plot.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Right: Bivariate Association, Descriptive Stats & Covariate OLS -->
    <div style="display:flex; flex-direction:column; gap:1.25rem;">

      <!-- Bivariate Association Card -->
      <div class="table-card" style="margin-bottom:0;">
        <div class="table-card-header">
          <h3>Bivariate Association (<?= strtoupper(h($statMethod)) ?>)</h3>
          <?php if (($bivarResult['status'] ?? '') === 'ok'): ?>
            <?php
              $pVal = (float)$bivarResult['p_value'];
              $selFamily = ($studyCode === 'BERGEN_BGA') ? ($familyRawResults[$xVar] ?? null) : ($familyRawResults[$yVar] ?? null);
              $qVal = $selFamily ? ($mtMethod === 'HOLM' ? $selFamily['p_holm'] : $selFamily['p_bh_fdr']) : null;
            ?>
            <span class="badge <?= ($qVal !== null && $qVal < 0.05) ? 'badge-green' : ($pVal < 0.05 ? 'badge-amber' : 'badge-navy') ?>">
              <?= ($qVal !== null && $qVal < 0.05) ? 'Significant after ' . h($mtMethod) : ($pVal < 0.05 ? 'Nominally p < 0.05 (Unadjusted)' : 'Null / Non-Significant (p >= 0.05)') ?>
            </span>
          <?php endif; ?>
        </div>
        <div style="padding:1rem;">
          <?php if (($bivarResult['status'] ?? '') === 'ok'): ?>
            <table class="data-table">
              <tbody>
                <tr>
                  <th>Effect Estimate (<?= $statMethod === 'spearman' ? 'Spearman ρ' : 'Pearson r' ?>)</th>
                  <td><strong style="font-size:1.1rem; color:var(--navy-dark);"><?= number_format((float)$bivarResult['estimate'], 4) ?></strong></td>
                </tr>
                <tr>
                  <th>95% Confidence Interval (Fisher z)</th>
                  <td><code>[<?= number_format((float)$bivarResult['ci_low'], 4) ?>, <?= number_format((float)$bivarResult['ci_high'], 4) ?>]</code></td>
                </tr>
                <tr>
                  <th>Test Statistic ($t$, $\text{df} = <?= (int)$bivarResult['df'] ?>)$</th>
                  <td>$t = <?= number_format((float)$bivarResult['t_stat'], 4) ?>$ ($R^2 = <?= number_format((float)$bivarResult['r_squared'], 4) ?>$)</td>
                </tr>
                <tr>
                  <th>Exact Two-Tailed $P$-Value (Unadjusted)</th>
                  <td><code><?= sprintf('%.5g', (float)$bivarResult['p_value']) ?></code></td>
                </tr>
                <tr>
                  <th>Multiple-Testing Adjusted ($k = <?= count($familyRawResults) ?>$ tests)</th>
                  <td>
                    BH-FDR $q = $ <code><?= $selFamily && $selFamily['p_bh_fdr'] !== null ? sprintf('%.5g', $selFamily['p_bh_fdr']) : 'N/A' ?></code> &bull;
                    Holm $p_{\text{adj}} = $ <code><?= $selFamily && $selFamily['p_holm'] !== null ? sprintf('%.5g', $selFamily['p_holm']) : 'N/A' ?></code>
                  </td>
                </tr>
              </tbody>
            </table>
          <?php else: ?>
            <div class="dq-alert dq-alert-critical"><?= h($bivarResult['warning'] ?? 'Computation unavailable') ?></div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Descriptive Statistics Card -->
      <div class="table-card" style="margin-bottom:0;">
        <div class="table-card-header">
          <h3>Descriptive Statistics ($N = <?= count($eligibleRows) ?>$)</h3>
        </div>
        <div class="table-scroll">
          <table class="data-table">
            <thead>
              <tr>
                <th>Variable</th>
                <th>Mean (SD)</th>
                <th>Median (IQR)</th>
                <th>Min – Max</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><strong>X: <?= h($xVar) ?></strong></td>
                <td><?= fmt_num_or_missing($descX['mean'], 5) ?> (<?= fmt_num_or_missing($descX['sd'], 5) ?>)</td>
                <td><?= fmt_num_or_missing($descX['median'], 5) ?> (<?= fmt_num_or_missing($descX['iqr'], 5) ?>)</td>
                <td><?= fmt_num_or_missing($descX['min'], 5) ?> – <?= fmt_num_or_missing($descX['max'], 5) ?></td>
              </tr>
              <tr>
                <td><strong>Y: <?= h($yVar) ?></strong></td>
                <td><?= fmt_num_or_missing($descY['mean'], 2) ?> (<?= fmt_num_or_missing($descY['sd'], 2) ?>)</td>
                <td><?= fmt_num_or_missing($descY['median'], 2) ?> (<?= fmt_num_or_missing($descY['iqr'], 2) ?>)</td>
                <td><?= fmt_num_or_missing($descY['min'], 2) ?> – <?= fmt_num_or_missing($descY['max'], 2) ?></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Covariate-Adjusted OLS Regression Card -->
      <div class="table-card" style="margin-bottom:0;">
        <div class="table-card-header">
          <h3>Covariate-Adjusted OLS Model</h3>
          <span style="font-size:0.76rem; color:var(--navy-subtle);">
            <?= !empty($activeCovariates) ? 'Adjusted for: ' . h(implode(', ', array_keys($activeCovariates))) : 'No covariates selected (check boxes above)' ?>
          </span>
        </div>
        <div style="padding:1rem;">
          <?php if ($olsAdjusted && ($olsAdjusted['status'] ?? '') === 'ok'): ?>
            <div style="font-size:0.8rem; color:var(--navy-muted); margin-bottom:0.5rem;">
              Model $R^2 = <?= number_format((float)$olsAdjusted['r_squared'], 4) ?>$ (Adj $R^2 = <?= number_format((float)$olsAdjusted['adj_r_squared'], 4) ?>$) &bull;
              Partial $r_{XY \cdot C} = $ <strong><?= number_format((float)$olsAdjusted['partial_r_x'], 4) ?></strong> ($\text{df} = <?= (int)$olsAdjusted['df_residual'] ?>)$
            </div>
            <table class="data-table">
              <thead>
                <tr>
                  <th>Term</th>
                  <th>$\hat{\beta}$ (SE)</th>
                  <th>$t$-stat</th>
                  <th>Two-Tailed $P$</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($olsAdjusted['coefficients'] as $term => $cMeta): ?>
                  <tr style="<?= $term === 'X' ? 'background:#f0fdfa;font-weight:600;' : '' ?>">
                    <td><?= $term === 'X' ? h('X (' . $xVar . ')') : h($term) ?></td>
                    <td><?= sprintf('%.4g', $cMeta['beta']) ?> (<?= sprintf('%.4g', $cMeta['se']) ?>)</td>
                    <td><?= number_format((float)$cMeta['t_stat'], 3) ?></td>
                    <td><code><?= sprintf('%.4g', $cMeta['p_value']) ?></code></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php elseif ($olsAdjusted): ?>
            <div class="dq-alert"><?= h($olsAdjusted['warning'] ?? 'Unable to fit OLS model.') ?></div>
          <?php else: ?>
            <div style="font-size:0.83rem; color:var(--navy-muted);">
              Select one or more covariates (`Age`, `Sex`, `Education`, `eTIV`) in the configuration panel above to compute partial correlation $r_{XY \cdot C}$ and multivariable OLS slopes.
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>

  <!-- 4. Multiple-Testing Family Comparison Table -->
  <div class="table-card" style="margin-bottom:1.75rem;">
    <div class="table-card-header">
      <div>
        <h3>4. Family-Wide Multiple-Testing Comparison (<?= count($familyRawResults) ?> Hypotheses Tested)</h3>
        <div style="font-size:0.78rem; color:var(--navy-subtle);">
          Testing <?= $studyCode === 'BERGEN_BGA' ? 'all 32 FreeSurfer brain measures against Y = ' . h($yVar) : 'all 15 significant DE genes against X = ' . h($xVar) ?> with Benjamini–Hochberg FDR and Holm step-down corrections
        </div>
      </div>
      <span class="badge badge-navy">Sorted by Raw P-Value</span>
    </div>
    <div class="table-scroll" style="max-height:360px; overflow-y:auto;">
      <table class="data-table">
        <thead>
          <tr>
            <th>Rank</th>
            <th>Variable</th>
            <th>N</th>
            <th><?= $statMethod === 'spearman' ? 'Spearman ρ' : 'Pearson r' ?></th>
            <th>95% CI</th>
            <th>Raw $P$-Value</th>
            <th>BH-FDR Adjusted $q$</th>
            <th>Holm–Bonferroni $P_{\text{adj}}$</th>
          </tr>
        </thead>
        <tbody>
          <?php $rk = 1; foreach ($familyRawResults as $code => $fr): ?>
            <?php
              $isSelected = ($studyCode === 'BERGEN_BGA' && $code === $xVar) || ($studyCode === 'MARS_GSE146853' && $code === $yVar);
            ?>
            <tr style="<?= $isSelected ? 'background:#f0fdfa; font-weight:600;' : '' ?>">
              <td>#<?= $rk++ ?></td>
              <td>
                <?= h($fr['label']) ?> (<code><?= h($code) ?></code>)
                <?php if ($isSelected): ?><span class="badge badge-teal">Active Selection</span><?php endif; ?>
              </td>
              <td><?= (int)$fr['n'] ?></td>
              <td><?= $fr['estimate'] !== null ? number_format((float)$fr['estimate'], 4) : '—' ?></td>
              <td><code>[<?= $fr['ci_low'] !== null ? number_format((float)$fr['ci_low'], 3) : '—' ?>, <?= $fr['ci_high'] !== null ? number_format((float)$fr['ci_high'], 3) : '—' ?>]</code></td>
              <td><code><?= $fr['p_value'] !== null ? sprintf('%.4g', $fr['p_value']) : '—' ?></code></td>
              <td><code><?= $fr['p_bh_fdr'] !== null ? sprintf('%.4g', $fr['p_bh_fdr']) : '—' ?></code></td>
              <td><code><?= $fr['p_holm'] !== null ? sprintf('%.4g', $fr['p_holm']) : '—' ?></code></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- 5. Complete Reproducibility Run Manifest -->
  <div class="table-card" style="margin-bottom:2rem;">
    <div class="table-card-header">
      <h3>5. Reproducible Run Manifest &amp; Audit Trail</h3>
      <code style="font-size:0.78rem; color:var(--teal-dark);">SHA-256: <?= h($runHash) ?></code>
    </div>
    <div style="padding:1.25rem; font-size:0.84rem; color:var(--navy-muted);">
      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:1rem; margin-bottom:1rem;">
        <div><strong>Study &amp; Dataset Versions:</strong><br><?= h($studyCode) ?> &bull; <?= h($datasetVersions) ?></div>
        <div><strong>Variables &amp; Units:</strong><br>X: <code><?= h($xVar) ?></code> (<?= h($xUnit) ?>)<br>Y: <code><?= h($yVar) ?></code> (<?= h($yUnit) ?>)</div>
        <div><strong>Inclusion / Visit Rule:</strong><br>Rule: <code><?= h($visitRule) ?></code> &bull; Cohort: <code><?= h($cohortFilter) ?></code> &bull; Unique Participants: <strong><?= $uniqueParticipantCount ?></strong></div>
        <div><strong>Software &amp; Timestamp:</strong><br><?= h($softwareVersion) ?> &bull; <?= h($fullManifest['timestamp_utc']) ?></div>
      </div>
      <div style="padding-top:0.75rem; border-top:1px solid var(--border-light);">
        <strong>Documented Methodological Limitations:</strong>
        <ul style="margin:0.35rem 0 0 1.25rem;">
          <?php foreach ($fullManifest['limitations'] as $lim): ?>
            <li><?= h($lim) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
