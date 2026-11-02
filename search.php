<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$pageTitle = 'Cross-Domain Provenance Search | NeuroGI Atlas';
$activeNav = 'search';

$db = pdo();

// Read and sanitize query inputs
$q         = trim((string)($_GET['q'] ?? ''));
$domain    = trim((string)($_GET['domain'] ?? 'all'));
$cohort    = trim((string)($_GET['cohort'] ?? 'all'));
$timepoint = trim((string)($_GET['timepoint'] ?? 'all'));
$view      = trim((string)($_GET['view'] ?? 'table'));

$allowedDomains = ['all', 'genes', 'samples', 'metabolomics', 'brain', 'clinical', 'literature', 'trials'];
if (!in_array($domain, $allowedDomains, true)) {
    $domain = 'all';
}
$allowedCohorts = ['all', 'IBS_GENERIC', 'IBS-D', 'IBS-C', 'Healthy', 'Diarrhea', 'Constipation'];
if (!in_array($cohort, $allowedCohorts, true)) {
    $cohort = 'all';
}
// Normalize legacy URL params ('Diarrhea' -> 'IBS-D', 'Constipation' -> 'IBS-C')
if ($cohort === 'Diarrhea') {
    $cohort = 'IBS-D';
}
if ($cohort === 'Constipation') {
    $cohort = 'IBS-C';
}

$allowedTimepoints = ['all', 'V1_BASELINE', 'V2_FOLLOWUP', 'T1', 'T2'];
if (!in_array($timepoint, $allowedTimepoints, true)) {
    $timepoint = 'all';
}
if ($timepoint === 'T1') {
    $timepoint = 'V1_BASELINE';
}
if ($timepoint === 'T2') {
    $timepoint = 'V2_FOLLOWUP';
}

$isSearching = ($q !== '' || $cohort !== 'all' || $timepoint !== 'all' || $domain !== 'all');
$searchWildcard = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

// Preload participant verification status map for BERGEN_BGA and MARS_GSE146853
$partStatusMap = [];
foreach ($db->query("SELECT participant_uid, study_code, local_participant_id, identity_verification_status, verification_notes FROM prov_participants")->fetchAll() as $pr) {
    $partStatusMap[$pr['study_code'] . ':' . $pr['local_participant_id']] = $pr;
}

// Track domain states ('matched', 'no_match', 'not_applicable') and distinct metrics
$domainSections = [];
$metrics = [
    'total_records' => 0,
    'unique_participants' => [],
    'verified_matched_participants' => [],
    'unresolved_conflict_participants' => [],
    'studies' => [],
    'publications' => 0,
    'trials' => 0,
];

if ($isSearching) {
    // -----------------------------------------------------------------------
    // DOMAIN 1: GSE146853 Significant Genes (Transcriptomics)
    // -----------------------------------------------------------------------
    if ($domain === 'all' || $domain === 'genes') {
        if ($timepoint !== 'all') {
            $domainSections['genes'] = [
                'status' => 'not_applicable',
                'title' => 'GSE146853 Differential Gene Expression (significant_genes & gene_results)',
                'study' => 'MARS_GSE146853 (Mars et al., Cell 2020)',
                'version' => 'GSE146853_DE_V1 (2020-09)',
                'reason' => 'Not Applicable: Participant visit timepoint filter (' . $timepoint . ') does not apply to contrast-level DESeq2 gene summary statistics (IBS-D vs HC).'
            ];
        } elseif ($cohort === 'IBS-C') {
            $domainSections['genes'] = [
                'status' => 'not_applicable',
                'title' => 'GSE146853 Differential Gene Expression (significant_genes)',
                'study' => 'MARS_GSE146853 (Mars et al., Cell 2020)',
                'version' => 'GSE146853_DE_V1 (2020-09)',
                'reason' => 'Not Applicable: The 15 FDR-significant genes in significant_genes were computed specifically for the IBS-D vs Healthy Control contrast, not IBS-C. To inspect IBS-C raw sample counts for any gene, use the Sample Metadata or Gene Count Inspector.'
            ];
        } else {
            $geneWheres = [];
            $geneParams = [];
            if ($q !== '') {
                if (stripos('IBS-D diarrhea colonic mucosa GSE146853', $q) !== false || strtoupper($q) === 'IBS') {
                    // Keyword matches contrast/disease context of GSE146853 DE genes
                } else {
                    $geneWheres[] = "(CAST(gene_id AS CHAR) LIKE ? OR gene_symbol LIKE ? OR gene_description LIKE ?)";
                    $geneParams = [$searchWildcard, $searchWildcard, $searchWildcard];
                }
            }
            $gWhereSql = $geneWheres ? ('WHERE ' . implode(' AND ', $geneWheres)) : '';
            $stG = $db->prepare("SELECT * FROM significant_genes $gWhereSql ORDER BY padj ASC LIMIT 25");
            $stG->execute($geneParams);
            $gRows = $stG->fetchAll();

            if ($gRows) {
                $metrics['total_records'] += count($gRows);
                $metrics['studies']['MARS_GSE146853'] = true;
                $formatted = [];
                foreach ($gRows as $r) {
                    $sym = (string)($r['gene_symbol'] ?: $r['gene_id']);
                    $why = ($q !== '')
                        ? (stripos($sym, $q) !== false ? "Matched gene_symbol '$sym'" : (stripos((string)$r['gene_description'], $q) !== false ? "Matched gene_description" : "Matched contrast context (IBS-D vs HC)"))
                        : "Matched cohort/domain filter (IBS-D vs HC DE)";
                    $formatted[] = [
                        'Identifier' => $sym . ' (' . $r['gene_id'] . ')',
                        'Gene Description' => (string)$r['gene_description'],
                        'Contrast & Tissue' => ($r['contrast_definition'] ?? 'IBS-D vs HC') . ' • ' . ($r['tissue'] ?? 'Sigmoid Colon Biopsy'),
                        'log2FC (±SE)' => sprintf('%+.3f (±%.3f)', (float)$r['log2FoldChange'], (float)($r['lfcSE'] ?? 0)),
                        'BH padj' => sprintf('%.2e', (float)$r['padj']),
                        'Why It Matched' => $why,
                        'Source & Version' => 'MARS_GSE146853 • GSE146853_DE_V1 (2020-09)',
                        '_actions' => [
                            ['label' => 'Plot Counts', 'url' => 'genes.php?inspect=' . urlencode($sym)],
                            ['label' => 'Biopsy SCFA Corr', 'url' => 'analysis.php?study=MARS_GSE146853&x=butyrate&y=' . urlencode($sym)]
                        ]
                    ];
                }
                $domainSections['genes'] = [
                    'status' => 'matched',
                    'title' => 'GSE146853 Colonic Mucosal Differential Expression (significant_genes)',
                    'badge' => 'Transcriptomics (DE)',
                    'study' => 'MARS_GSE146853 (Mars et al., Cell 2020, PMID: 32916129)',
                    'version' => 'GSE146853_DE_V1 (Retrieved 2020-09)',
                    'columns' => ['Identifier', 'Gene Description', 'Contrast & Tissue', 'log2FC (±SE)', 'BH padj', 'Why It Matched', 'Source & Version'],
                    'rows' => $formatted
                ];
            } else {
                $domainSections['genes'] = [
                    'status' => 'no_match',
                    'title' => 'GSE146853 Colonic Mucosal Differential Expression (significant_genes)',
                    'study' => 'MARS_GSE146853',
                    'version' => 'GSE146853_DE_V1',
                    'reason' => 'No Matching Results: Filter applies to this domain, but 0 genes matched "' . $q . '".'
                ];
            }
        }
    }

    // -----------------------------------------------------------------------
    // DOMAIN 2: Mars et al. Longitudinal Sample Metadata (sample_metadata)
    // -----------------------------------------------------------------------
    if ($domain === 'all' || $domain === 'samples') {
        $sWheres = ["geo_accession != 'geo_accession'"];
        $sParams = [];
        if ($q !== '') {
            if (stripos('IBS diarrhea constipation colonic biopsy GSE146853', $q) === false) {
                $sWheres[] = "(sample_title LIKE ? OR geo_accession LIKE ? OR CAST(patientid AS CHAR) LIKE ? OR cohort LIKE ?)";
                $sParams = array_merge($sParams, [$searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard]);
            }
        }
        if ($cohort === 'Healthy') {
            $sWheres[] = "cohort = 'Healthy'";
        } elseif ($cohort === 'IBS-D') {
            $sWheres[] = "(cohort = 'Diarrhea' OR cohort = 'Diarrheaiarrhea')";
        } elseif ($cohort === 'IBS-C') {
            $sWheres[] = "cohort = 'Constipation'";
        } elseif ($cohort === 'IBS_GENERIC') {
            $sWheres[] = "cohort IN ('Diarrhea', 'Diarrheaiarrhea', 'Constipation')";
        }

        if ($timepoint === 'V1_BASELINE') {
            $sWheres[] = "(timepoint = 'T1' OR timepoint = '1' OR sample_title LIKE '%-1')";
        } elseif ($timepoint === 'V2_FOLLOWUP') {
            $sWheres[] = "(timepoint = 'T2' OR timepoint = '2' OR sample_title LIKE '%-2')";
        }

        $stS = $db->prepare("SELECT * FROM sample_metadata WHERE " . implode(' AND ', $sWheres) . " ORDER BY sample_title ASC LIMIT 50");
        $stS->execute($sParams);
        $sRows = $stS->fetchAll();

        if ($sRows) {
            $metrics['total_records'] += count($sRows);
            $metrics['studies']['MARS_GSE146853'] = true;
            $formatted = [];
            foreach ($sRows as $r) {
                $pid = trim((string)($r['patientid'] ?? ''));
                $tp  = 1;
                if (preg_match('/^(\d+)-(\d+)$/', (string)$r['sample_title'], $m)) {
                    if ($pid === '' || $pid === '0') $pid = $m[1];
                    $tp = (int)$m[2];
                }
                $pUid = 'MARS_GSE146853:' . $pid;
                $metrics['unique_participants'][$pUid] = true;
                if (isset($partStatusMap[$pUid]) && $partStatusMap[$pUid]['identity_verification_status'] === 'verified') {
                    $metrics['verified_matched_participants'][$pUid] = true;
                }

                $whyParts = [];
                if ($q !== '') {
                    $whyParts[] = "Keyword '$q' in sample/cohort";
                }
                if ($cohort !== 'all') {
                    $whyParts[] = "Subtype filter '$cohort'";
                }
                if ($timepoint !== 'all') {
                    $whyParts[] = "Timepoint '$timepoint' (Visit $tp)";
                }

                $ageDisp = ((float)$r['age'] > 0) ? (string)$r['age'] : 'Missing (0.00 in GEO)';
                $bmiDisp = ((float)$r['bmi'] > 0) ? (string)$r['bmi'] : 'Missing (0.00 in GEO)';

                $formatted[] = [
                    'Sample / GEO ID' => $r['sample_title'] . ' (' . $r['geo_accession'] . ')',
                    'Scoped Participant' => $pUid,
                    'Visit' => 'Visit ' . $tp,
                    'Condition (Raw)' => (string)$r['cohort'],
                    'Demographics' => 'Sex: ' . ($r['gender'] ?: 'Missing') . ' • Age: ' . $ageDisp . ' • BMI: ' . $bmiDisp,
                    'Why It Matched' => implode(' + ', $whyParts) ?: 'Matched active filters',
                    'Source & Version' => 'MARS_GSE146853 • GSE146853_META_V1 (2020-09)',
                    '_actions' => [
                        ['label' => 'Inspect Participant', 'url' => 'participant.php?uid=' . urlencode($pUid)],
                        ['label' => 'Biopsy Analysis', 'url' => 'analysis.php?study=MARS_GSE146853']
                    ]
                ];
            }
            $domainSections['samples'] = [
                'status' => 'matched',
                'title' => 'GSE146853 Colonic Biopsy RNA-seq Sample Metadata (sample_metadata)',
                'badge' => 'Biopsy Samples',
                'study' => 'MARS_GSE146853 (Mayo Clinic Longitudinal Cohort)',
                'version' => 'GSE146853_META_V1 (83 biopsies across 42 subjects)',
                'columns' => ['Sample / GEO ID', 'Scoped Participant', 'Visit', 'Condition (Raw)', 'Demographics', 'Why It Matched', 'Source & Version'],
                'rows' => $formatted
            ];
        } else {
            $domainSections['samples'] = [
                'status' => 'no_match',
                'title' => 'GSE146853 Colonic Biopsy RNA-seq Sample Metadata',
                'study' => 'MARS_GSE146853',
                'version' => 'GSE146853_META_V1',
                'reason' => 'No Matching Results: Filter applies to this domain, but 0 biopsy samples matched your criteria.'
            ];
        }
    }

    // -----------------------------------------------------------------------
    // DOMAIN 3: Mars et al. Colonic Biopsy GC-MS Metabolomics
    // -----------------------------------------------------------------------
    if ($domain === 'all' || $domain === 'metabolomics') {
        $mWheres = ["1=1"];
        $mParams = [];
        if ($q !== '') {
            if (stripos('IBS SCFA butyrate propionate acetate metabolomics biopsy', $q) === false) {
                $mWheres[] = "(full_sample_title LIKE ? OR patient_id LIKE ? OR raw_cohort LIKE ? OR matched_rnaseq_geo LIKE ?)";
                $mParams = [$searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard];
            }
        }
        if ($cohort === 'Healthy') {
            $mWheres[] = "raw_cohort = 'Healthy'";
        } elseif ($cohort === 'IBS-D') {
            $mWheres[] = "raw_cohort = 'IBS-D'";
        } elseif ($cohort === 'IBS-C') {
            $mWheres[] = "raw_cohort = 'IBS-C'";
        } elseif ($cohort === 'IBS_GENERIC') {
            $mWheres[] = "raw_cohort IN ('IBS-D', 'IBS-C')";
        }

        if ($timepoint === 'V1_BASELINE') {
            $mWheres[] = "visit_number = 1";
        } elseif ($timepoint === 'V2_FOLLOWUP') {
            $mWheres[] = "visit_number = 2";
        }

        $stM = $db->prepare("SELECT * FROM mars_gcms_biopsy_metabolites WHERE " . implode(' AND ', $mWheres) . " ORDER BY excel_row_num ASC LIMIT 40");
        $stM->execute($mParams);
        $mRows = $stM->fetchAll();

        if ($mRows) {
            $metrics['total_records'] += count($mRows);
            $metrics['studies']['MARS_GSE146853'] = true;
            $formatted = [];
            foreach ($mRows as $r) {
                $pUid = (string)$r['participant_uid'];
                $metrics['unique_participants'][$pUid] = true;
                if (!empty($r['matched_rnaseq_geo'])) {
                    $metrics['verified_matched_participants'][$pUid] = true;
                }
                $formatted[] = [
                    'Biopsy Sample ID' => $r['full_sample_title'] . ' (Row #' . $r['excel_row_num'] . ')',
                    'Scoped Participant' => $pUid,
                    'Paired RNA-seq' => $r['matched_rnaseq_geo'] ?: 'GC-MS Only',
                    'Subtype & Visit' => $r['raw_cohort'] . ' • Visit ' . $r['visit_number'],
                    'SCFAs (Acet / Prop / Butyr)' => sprintf('%.1f / %.1f / %.1f', (float)$r['acetate'], (float)$r['propionate'], (float)$r['butyrate']),
                    'Why It Matched' => ($q !== '' ? "Matched '$q'" : 'Matched filter') . ' in Supplementary Data III GC-MS',
                    'Source & Version' => 'MARS_GSE146853 • MARS_GCMS_BIOPSY_V1 (Cell 2020)',
                    '_actions' => [
                        ['label' => 'Inspect Participant', 'url' => 'participant.php?uid=' . urlencode($pUid)],
                        ['label' => 'Run SCFA Analysis', 'url' => 'analysis.php?study=MARS_GSE146853']
                    ]
                ];
            }
            $domainSections['metabolomics'] = [
                'status' => 'matched',
                'title' => 'Colonic Biopsy GC-MS Metabolomics (mars_gcms_biopsy_metabolites)',
                'badge' => 'Biopsy GC-MS SCFAs',
                'study' => 'MARS_GSE146853 (Mars et al., Cell 2020 Supplementary Data III)',
                'version' => 'MARS_GCMS_BIOPSY_V1 (74 biopsies, 66 paired with RNA-seq)',
                'columns' => ['Biopsy Sample ID', 'Scoped Participant', 'Paired RNA-seq', 'Subtype & Visit', 'SCFAs (Acet / Prop / Butyr)', 'Why It Matched', 'Source & Version'],
                'rows' => $formatted
            ];
        } else {
            $domainSections['metabolomics'] = [
                'status' => 'no_match',
                'title' => 'Colonic Biopsy GC-MS Metabolomics (mars_gcms_biopsy_metabolites)',
                'study' => 'MARS_GSE146853',
                'version' => 'MARS_GCMS_BIOPSY_V1',
                'reason' => 'No Matching Results: 0 biopsy GC-MS records matched your query.'
            ];
        }
    }

    // -----------------------------------------------------------------------
    // DOMAIN 4: Brain MRI & RBANS Cognition (BERGEN_BGA: brain_measurements)
    // -----------------------------------------------------------------------
    if ($domain === 'all' || $domain === 'brain') {
        if (in_array($cohort, ['IBS-D', 'IBS-C'], true)) {
            $domainSections['brain'] = [
                'status' => 'not_applicable',
                'title' => 'Bergen Structural Brain MRI & RBANS Cognition (brain_measurements)',
                'study' => 'BERGEN_BGA (University of Bergen Brain-Gut Study)',
                'version' => 'PSN_IBS_MRI_V1 (78 subjects)',
                'reason' => 'Not Applicable (Subtype Granularity Boundary): You filtered for specific Rome subtype "' . $cohort . '", whereas the Bergen Brain-Gut Study (brain_measurements) records only generic "IBS" vs "HC" without subtype classification. In accordance with Phase 7 scientific rules, generic IBS is never silently equated with IBS-D or IBS-C. Select "IBS (Generic)" or "All Cohorts" to view Bergen MRI records.'
            ];
        } elseif ($timepoint === 'V2_FOLLOWUP') {
            $domainSections['brain'] = [
                'status' => 'not_applicable',
                'title' => 'Bergen Structural Brain MRI & RBANS Cognition (brain_measurements)',
                'study' => 'BERGEN_BGA',
                'version' => 'PSN_IBS_MRI_V1',
                'reason' => 'Not Applicable: Bergen Brain MRI (`brain_measurements`) is a single cross-sectional MRI acquisition (`T_MRI_ACQ`), not a 6-month Visit 2 longitudinal biopsy.'
            ];
        } else {
            $bWheres = ["1=1"];
            $bParams = [];
            $matchedRoiSchema = (stripos('thalamus hippocampus amygdala caudate putamen pallidum brain mri volume rbans cognition etiv', $q) !== false && $q !== '');
            if ($q !== '' && !$matchedRoiSchema) {
                $bWheres[] = "(`Subject` LIKE ? OR `Group` LIKE ? OR `Gender` LIKE ?)";
                $bParams = [$searchWildcard, $searchWildcard, $searchWildcard];
            }
            if ($cohort === 'Healthy') {
                $bWheres[] = "`Group` = 'HC'";
            } elseif ($cohort === 'IBS_GENERIC') {
                $bWheres[] = "`Group` = 'IBS'";
            }

            $stB = $db->prepare("SELECT * FROM brain_measurements WHERE " . implode(' AND ', $bWheres) . " ORDER BY `Subject` ASC LIMIT 50");
            $stB->execute($bParams);
            $bRows = $stB->fetchAll();

            if ($bRows) {
                $metrics['total_records'] += count($bRows);
                $metrics['studies']['BERGEN_BGA'] = true;
                $formatted = [];
                foreach ($bRows as $r) {
                    $sid = (string)$r['Subject'];
                    $pUid = 'BERGEN_BGA:' . $sid;
                    $metrics['unique_participants'][$pUid] = true;
                    $vStat = $partStatusMap[$pUid]['identity_verification_status'] ?? 'single_table_only';
                    if (in_array($vStat, ['verified', 'verified_with_visit_offset'], true)) {
                        $metrics['verified_matched_participants'][$pUid] = true;
                    } elseif ($vStat === 'unresolved_conflict') {
                        $metrics['unresolved_conflict_participants'][$pUid] = true;
                    }

                    $why = $matchedRoiSchema
                        ? "Matched neuroimaging/cognitive measure '$q'"
                        : ($q !== '' ? "Matched '$q' on Subject/Group" : "Matched cohort filter");

                    $formatted[] = [
                        'Scoped Participant' => $pUid . ' [' . $vStat . ']',
                        'Cohort & Sex' => $r['Group'] . ' • ' . $r['Gender'] . ' • Age ' . $r['Age'],
                        'L/R Thalamus (eTIV Prop)' => number_format((float)$r['Left-Thalamus'], 5) . ' / ' . number_format((float)$r['Right-Thalamus'], 5),
                        'L/R Hippocampus (eTIV Prop)' => number_format((float)$r['Left-Hippocampus'], 5) . ' / ' . number_format((float)$r['Right-Hippocampus'], 5),
                        'RBANS Total & IBS-SSS' => 'RBANS: ' . fmt_num_or_missing($r['Fullscale_RBANS'], 1) . ' • IBS-SSS: ' . fmt_num_or_missing($r['IBS_SSS'], 0),
                        'Why It Matched' => $why,
                        'Source & Version' => 'BERGEN_BGA • PSN_IBS_MRI_V1 (T_MRI_ACQ)',
                        '_actions' => [
                            ['label' => 'Inspect Participant', 'url' => 'participant.php?uid=' . urlencode($pUid)],
                            ['label' => 'Highlight in Analysis', 'url' => 'analysis.php?study=BERGEN_BGA&highlight=' . urlencode($sid)]
                        ]
                    ];
                }
                $domainSections['brain'] = [
                    'status' => 'matched',
                    'title' => 'Bergen Structural Brain MRI & RBANS Cognition (brain_measurements)',
                    'badge' => 'Neuroimaging & RBANS',
                    'study' => 'BERGEN_BGA (Lundervold et al., University of Bergen)',
                    'version' => 'PSN_IBS_MRI_V1 (78 MRI participants; regional volumes eTIV-normalized)',
                    'columns' => ['Scoped Participant', 'Cohort & Sex', 'L/R Thalamus (eTIV Prop)', 'L/R Hippocampus (eTIV Prop)', 'RBANS Total & IBS-SSS', 'Why It Matched', 'Source & Version'],
                    'rows' => $formatted
                ];
            } else {
                $domainSections['brain'] = [
                    'status' => 'no_match',
                    'title' => 'Bergen Structural Brain MRI & RBANS Cognition (brain_measurements)',
                    'study' => 'BERGEN_BGA',
                    'version' => 'PSN_IBS_MRI_V1',
                    'reason' => 'No Matching Results: Filter applies to this domain, but 0 MRI records matched "' . $q . '".'
                ];
            }
        }
    }

    // -----------------------------------------------------------------------
    // DOMAIN 5: Clinical Symptom Distress (BERGEN_BGA: clinical_symptom_data)
    // -----------------------------------------------------------------------
    if ($domain === 'all' || $domain === 'clinical') {
        if (in_array($cohort, ['IBS-D', 'IBS-C'], true)) {
            $domainSections['clinical'] = [
                'status' => 'not_applicable',
                'title' => 'Bergen Clinical Symptom Distress & IBS-SSS (clinical_symptom_data)',
                'study' => 'BERGEN_BGA (University of Bergen Brain-Gut Study)',
                'version' => 'IBS_DISTRESS_CLIN_V1 (77 subjects)',
                'reason' => 'Not Applicable (Subtype Granularity Boundary): clinical_symptom_data records generic "IBS" vs "HC" without Rome IBS-D/IBS-C subtype classification. Generic IBS is never equated with ' . $cohort . '.'
            ];
        } elseif ($timepoint === 'V2_FOLLOWUP') {
            $domainSections['clinical'] = [
                'status' => 'not_applicable',
                'title' => 'Bergen Clinical Symptom Distress & IBS-SSS (clinical_symptom_data)',
                'study' => 'BERGEN_BGA',
                'version' => 'IBS_DISTRESS_CLIN_V1',
                'reason' => 'Not Applicable: clinical_symptom_data represents T0_BASELINE clinical intake, not Visit 2 follow-up.'
            ];
        } else {
            $cWheres = ["1=1"];
            $cParams = [];
            $matchedClinSchema = (stripos('ibs-sss pain distension bowel satisfaction life interference symptom distress', $q) !== false && $q !== '');
            if ($q !== '' && !$matchedClinSchema) {
                $cWheres[] = "(participant_id LIKE ? OR category LIKE ? OR sex LIKE ?)";
                $cParams = [$searchWildcard, $searchWildcard, $searchWildcard];
            }
            if ($cohort === 'Healthy') {
                $cWheres[] = "category = 'HC'";
            } elseif ($cohort === 'IBS_GENERIC') {
                $cWheres[] = "category = 'IBS'";
            }

            $stC = $db->prepare("SELECT * FROM clinical_symptom_data WHERE " . implode(' AND ', $cWheres) . " ORDER BY participant_id ASC LIMIT 50");
            $stC->execute($cParams);
            $cRows = $stC->fetchAll();

            if ($cRows) {
                $metrics['total_records'] += count($cRows);
                $metrics['studies']['BERGEN_BGA'] = true;
                $formatted = [];
                foreach ($cRows as $r) {
                    $sid = (string)$r['participant_id'];
                    $pUid = 'BERGEN_BGA:' . $sid;
                    $metrics['unique_participants'][$pUid] = true;
                    $vStat = $partStatusMap[$pUid]['identity_verification_status'] ?? 'single_table_only';
                    if (in_array($vStat, ['verified', 'verified_with_visit_offset'], true)) {
                        $metrics['verified_matched_participants'][$pUid] = true;
                    } elseif ($vStat === 'unresolved_conflict') {
                        $metrics['unresolved_conflict_participants'][$pUid] = true;
                    }

                    $why = $matchedClinSchema
                        ? "Matched clinical symptom measure '$q'"
                        : ($q !== '' ? "Matched '$q' on participant_id/category" : "Matched cohort filter");

                    $formatted[] = [
                        'Scoped Participant' => $pUid . ' [' . $vStat . ']',
                        'Phenotype & Demographics' => $r['category'] . ' • ' . $r['sex'] . ' • Age ' . $r['age'],
                        'IBS-SSS Total' => fmt_num_or_missing($r['ibs_sss'], 0) . ((int)$r['ibs_sss'] === 0 ? ' (True 0)' : ''),
                        'Pain & Bloating (B1 / H1 / H2)' => 'B1: ' . fmt_num_or_missing($r['B1'], 0) . ' • H1: ' . fmt_num_or_missing($r['H1'], 0) . ' • H2: ' . fmt_num_or_missing($r['H2'], 0),
                        'Distension (B2) & Life (F2 / R1)' => 'B2: ' . fmt_num_or_missing($r['B2'], 0) . ' • F2: ' . fmt_num_or_missing($r['F2'], 0) . ' • R1: ' . fmt_num_or_missing($r['R1'], 0),
                        'Why It Matched' => $why,
                        'Source & Version' => 'BERGEN_BGA • IBS_DISTRESS_CLIN_V1 (T0_BASELINE)',
                        '_actions' => [
                            ['label' => 'Inspect Participant', 'url' => 'participant.php?uid=' . urlencode($pUid)],
                            ['label' => 'Run Analysis', 'url' => 'analysis.php?study=BERGEN_BGA&highlight=' . urlencode($sid)]
                        ]
                    ];
                }
                $domainSections['clinical'] = [
                    'status' => 'matched',
                    'title' => 'Bergen Clinical Symptom Distress & IBS-SSS (clinical_symptom_data)',
                    'badge' => 'Clinical Symptoms',
                    'study' => 'BERGEN_BGA (University of Bergen Brain-Gut Study)',
                    'version' => 'IBS_DISTRESS_CLIN_V1 (77 participants; T0_BASELINE)',
                    'columns' => ['Scoped Participant', 'Phenotype & Demographics', 'IBS-SSS Total', 'Pain & Bloating (B1 / H1 / H2)', 'Distension (B2) & Life (F2 / R1)', 'Why It Matched', 'Source & Version'],
                    'rows' => $formatted
                ];
            } else {
                $domainSections['clinical'] = [
                    'status' => 'no_match',
                    'title' => 'Bergen Clinical Symptom Distress & IBS-SSS (clinical_symptom_data)',
                    'study' => 'BERGEN_BGA',
                    'version' => 'IBS_DISTRESS_CLIN_V1',
                    'reason' => 'No Matching Results: Filter applies to this domain, but 0 clinical records matched "' . $q . '".'
                ];
            }
        }
    }

    // -----------------------------------------------------------------------
    // DOMAIN 6: PubMed Literature (pubmed_articles)
    // -----------------------------------------------------------------------
    if ($domain === 'all' || $domain === 'literature') {
        if ($timepoint !== 'all') {
            $domainSections['literature'] = [
                'status' => 'not_applicable',
                'title' => 'PubMed Curated Literature (pubmed_articles)',
                'study' => 'PUBMED_LITERATURE',
                'version' => 'PUBMED_EXTRACT_V1 (2025-02 Snapshot)',
                'reason' => 'Not Applicable: Participant visit timepoint filter (' . $timepoint . ') does not apply to bibliographic PubMed articles.'
            ];
        } else {
            $lWheres = ["1=1"];
            $lParams = [];
            if ($q !== '') {
                $lWheres[] = "(title LIKE ? OR abstract LIKE ? OR pmid LIKE ?)";
                $lParams = [$searchWildcard, $searchWildcard, $searchWildcard];
            }
            if ($cohort === 'IBS-D') {
                $lWheres[] = "(title LIKE '%IBS-D%' OR abstract LIKE '%IBS-D%' OR abstract LIKE '%diarrhea%')";
            } elseif ($cohort === 'IBS-C') {
                $lWheres[] = "(title LIKE '%IBS-C%' OR abstract LIKE '%IBS-C%' OR abstract LIKE '%constipation%')";
            }

            $stL = $db->prepare("SELECT pmid, title, year, abstract, source FROM pubmed_articles WHERE " . implode(' AND ', $lWheres) . " ORDER BY year DESC, id ASC LIMIT 25");
            $stL->execute($lParams);
            $lRows = $stL->fetchAll();

            if ($lRows) {
                $metrics['total_records'] += count($lRows);
                $metrics['publications'] += count($lRows);
                $formatted = [];
                foreach ($lRows as $r) {
                    $why = ($q !== '')
                        ? (stripos((string)$r['title'], $q) !== false ? "Keyword '$q' in article title" : "Keyword '$q' in abstract/PMID")
                        : "Matched text filter ($cohort)";
                    $formatted[] = [
                        'PMID' => (string)$r['pmid'],
                        'Year' => (string)$r['year'],
                        'Title' => (string)$r['title'],
                        'Why It Matched' => $why . ' (Keyword match — not a participant link)',
                        'Source & Version' => 'NCBI PubMed • PUBMED_EXTRACT_V1 (2025-02)',
                        '_actions' => [
                            ['label' => 'PubMed ↗', 'url' => 'https://pubmed.ncbi.nlm.nih.gov/' . urlencode((string)$r['pmid']) . '/', 'external' => true],
                            ['label' => 'Evidence Graph', 'url' => 'evidence.php']
                        ]
                    ];
                }
                $domainSections['literature'] = [
                    'status' => 'matched',
                    'title' => 'PubMed Peer-Reviewed Literature (pubmed_articles)',
                    'badge' => 'Literature (PMID)',
                    'study' => 'NCBI PubMed Repository Extract',
                    'version' => 'PUBMED_EXTRACT_V1 (Snapshot retrieved 2025-02)',
                    'columns' => ['PMID', 'Year', 'Title', 'Why It Matched', 'Source & Version'],
                    'rows' => $formatted
                ];
            } else {
                $domainSections['literature'] = [
                    'status' => 'no_match',
                    'title' => 'PubMed Peer-Reviewed Literature (pubmed_articles)',
                    'study' => 'PUBMED_LITERATURE',
                    'version' => 'PUBMED_EXTRACT_V1',
                    'reason' => 'No Matching Results: 0 PubMed articles matched "' . $q . '".'
                ];
            }
        }
    }

    // -----------------------------------------------------------------------
    // DOMAIN 7: ClinicalTrials.gov Registry (clinical_trials)
    // -----------------------------------------------------------------------
    if ($domain === 'all' || $domain === 'trials') {
        if ($timepoint !== 'all') {
            $domainSections['trials'] = [
                'status' => 'not_applicable',
                'title' => 'ClinicalTrials.gov Registered Studies (clinical_trials)',
                'study' => 'CLINICALTRIALS_GOV',
                'version' => 'CT_GOV_EXTRACT_V1 (2025-02 Snapshot)',
                'reason' => 'Not Applicable: Participant visit timepoint filter (' . $timepoint . ') does not apply to ClinicalTrials.gov registry records.'
            ];
        } else {
            $tWheres = ["1=1"];
            $tParams = [];
            if ($q !== '') {
                $tWheres[] = "(title LIKE ? OR summary LIKE ? OR conditions LIKE ? OR nct_id LIKE ?)";
                $tParams = [$searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard];
            }
            if ($cohort === 'IBS-D') {
                $tWheres[] = "(conditions LIKE '%IBS-D%' OR conditions LIKE '%Diarrhea%' OR summary LIKE '%IBS-D%')";
            } elseif ($cohort === 'IBS-C') {
                $tWheres[] = "(conditions LIKE '%IBS-C%' OR conditions LIKE '%Constipation%' OR summary LIKE '%IBS-C%')";
            }

            $stT = $db->prepare("SELECT nct_id, title, status, phase, conditions, sponsor FROM clinical_trials WHERE " . implode(' AND ', $tWheres) . " ORDER BY id ASC LIMIT 25");
            $stT->execute($tParams);
            $tRows = $stT->fetchAll();

            if ($tRows) {
                $metrics['total_records'] += count($tRows);
                $metrics['trials'] += count($tRows);
                $formatted = [];
                foreach ($tRows as $r) {
                    $why = ($q !== '')
                        ? (stripos((string)$r['conditions'], $q) !== false ? "Keyword '$q' in Conditions" : "Keyword '$q' in Title/Summary/NCT")
                        : "Matched condition filter ($cohort)";
                    $formatted[] = [
                        'NCT ID' => (string)$r['nct_id'],
                        'Snapshot Status' => (string)$r['status'] . ' (as of 2025-02)',
                        'Phase' => (string)($r['phase'] ?: 'Not Specified'),
                        'Title & Conditions' => (string)$r['title'] . ' [' . (string)$r['conditions'] . ']',
                        'Why It Matched' => $why,
                        'Source & Version' => 'ClinicalTrials.gov • CT_GOV_EXTRACT_V1 (2025-02 Snapshot)',
                        '_actions' => [
                            ['label' => 'Verify Live Status ↗', 'url' => 'https://clinicaltrials.gov/study/' . urlencode((string)$r['nct_id']), 'external' => true]
                        ]
                    ];
                }
                $domainSections['trials'] = [
                    'status' => 'matched',
                    'title' => 'ClinicalTrials.gov Registered Studies (clinical_trials)',
                    'badge' => 'Clinical Trials (NCT)',
                    'study' => 'ClinicalTrials.gov API v2 Snapshot',
                    'version' => 'CT_GOV_EXTRACT_V1 (Retrieved 2025-02 — verify live trial recruitment status on ClinicalTrials.gov)',
                    'columns' => ['NCT ID', 'Snapshot Status', 'Phase', 'Title & Conditions', 'Why It Matched', 'Source & Version'],
                    'rows' => $formatted
                ];
            } else {
                $domainSections['trials'] = [
                    'status' => 'no_match',
                    'title' => 'ClinicalTrials.gov Registered Studies (clinical_trials)',
                    'study' => 'CLINICALTRIALS_GOV',
                    'version' => 'CT_GOV_EXTRACT_V1',
                    'reason' => 'No Matching Results: 0 clinical trials matched "' . $q . '".'
                ];
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="page-hero-inner">
    <div class="hero-badge" style="margin-bottom:0.6rem;">PHASE 7 &bull; PROVENANCE-AWARE CROSS-DOMAIN SEARCH &amp; FILTER SEMANTICS</div>
    <h2>Cross-Domain Provenance Search</h2>
    <p>
      Query across study-scoped participants, colonic biopsy transcriptomics &amp; GC-MS metabolomics, brain MRI volumetrics, clinical symptoms, PubMed literature, and ClinicalTrials.gov.
      Every match explains <strong>why it matched</strong>, its <strong>source study &amp; version</strong>, and distinguishes <strong>Not Applicable</strong> filters from <strong>0 Matching Results</strong>.
    </p>
  </div>
</section>

<div class="container">

  <!-- Search Form with Semantic Filter Explanations -->
  <div class="table-card" style="padding:1.25rem; margin-bottom:1.5rem;">
    <form method="GET" action="search.php">
      <div style="display:flex; gap:0.6rem; margin-bottom:1rem; flex-wrap:wrap;">
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search by participant (BGA_046, BGA_072, 10007541), gene (TIGIT, SLC26A3), brain region (Thalamus), or disease (IBS)..." style="flex:1; min-width:260px; padding:0.65rem 1rem; border:1px solid var(--border-light); border-radius:var(--radius-sm); font-size:0.92rem;">
        <button type="submit" class="btn btn-primary">Search Atlas</button>
        <?php if ($isSearching): ?>
          <a href="search.php" class="btn btn-outline">Reset</a>
        <?php endif; ?>
      </div>

      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap:1rem; align-items:end;">
        <div>
          <label class="form-label" for="s-dom">Domain Scope</label>
          <select id="s-dom" name="domain" class="form-select">
            <option value="all" <?= $domain === 'all' ? 'selected' : '' ?>>All Research Domains (7)</option>
            <option value="genes" <?= $domain === 'genes' ? 'selected' : '' ?>>1. GSE146853 DE Genes (Transcriptomics)</option>
            <option value="samples" <?= $domain === 'samples' ? 'selected' : '' ?>>2. GSE146853 Biopsy Sample Metadata</option>
            <option value="metabolomics" <?= $domain === 'metabolomics' ? 'selected' : '' ?>>3. Mars Colonic Biopsy GC-MS Metabolomics</option>
            <option value="brain" <?= $domain === 'brain' ? 'selected' : '' ?>>4. Bergen Brain MRI &amp; RBANS Cognition</option>
            <option value="clinical" <?= $domain === 'clinical' ? 'selected' : '' ?>>5. Bergen Clinical Symptoms (IBS-SSS)</option>
            <option value="literature" <?= $domain === 'literature' ? 'selected' : '' ?>>6. PubMed Literature (Snapshot)</option>
            <option value="trials" <?= $domain === 'trials' ? 'selected' : '' ?>>7. ClinicalTrials.gov (Snapshot)</option>
          </select>
        </div>

        <div>
          <label class="form-label" for="s-coh">Cohort / Subtype (Strict Mapping)</label>
          <select id="s-coh" name="cohort" class="form-select">
            <option value="all" <?= $cohort === 'all' ? 'selected' : '' ?>>All Cohorts &amp; Subtypes</option>
            <option value="IBS_GENERIC" <?= $cohort === 'IBS_GENERIC' ? 'selected' : '' ?>>IBS (Generic / Any IBS Cohort)</option>
            <option value="IBS-D" <?= $cohort === 'IBS-D' ? 'selected' : '' ?>>IBS-D (Diarrhea-Predominant — Mars/Lit Only)</option>
            <option value="IBS-C" <?= $cohort === 'IBS-C' ? 'selected' : '' ?>>IBS-C (Constipation-Predominant — Mars/Lit Only)</option>
            <option value="Healthy" <?= $cohort === 'Healthy' ? 'selected' : '' ?>>Healthy Control (HC)</option>
          </select>
        </div>

        <div>
          <label class="form-label" for="s-tp">Participant Visit / Timepoint</label>
          <select id="s-tp" name="timepoint" class="form-select">
            <option value="all" <?= $timepoint === 'all' ? 'selected' : '' ?>>All Timepoints (No Visit Restriction)</option>
            <option value="V1_BASELINE" <?= $timepoint === 'V1_BASELINE' ? 'selected' : '' ?>>Visit 1 / Baseline Intake Only</option>
            <option value="V2_FOLLOWUP" <?= $timepoint === 'V2_FOLLOWUP' ? 'selected' : '' ?>>Visit 2 (6-Month Follow-up — Mars Only)</option>
          </select>
        </div>

        <div style="display:flex; gap:0.4rem; justify-content:flex-end;">
          <button type="submit" name="view" value="table" class="btn btn-sm <?= $view === 'table' ? 'btn-primary' : 'btn-outline' ?>">Table View</button>
          <button type="submit" name="view" value="cards" class="btn btn-sm <?= $view === 'cards' ? 'btn-primary' : 'btn-outline' ?>">Card View</button>
        </div>
      </div>
    </form>
  </div>

  <?php if (!$isSearching): ?>
    <!-- Guided Evaluator Search Presets -->
    <div class="table-card" style="padding:2rem; text-align:center;">
      <h3 style="font-size:1.2rem; color:var(--navy-dark); margin-bottom:0.5rem;">Try a Provenance-Aware Search Preset</h3>
      <p style="color:var(--navy-muted); max-width:640px; margin:0 auto 1.25rem; font-size:0.9rem;">
        Test how NeuroGI Atlas distinguishes study-scoped participants, subtype specificity (`IBS` vs `IBS-D` vs `IBS-C`), and domain filter applicability:
      </p>
      <div style="display:flex; gap:0.5rem; justify-content:center; flex-wrap:wrap;">
        <a href="search.php?q=IBS" class="badge badge-teal" style="text-decoration:none; padding:0.45rem 0.85rem;">Disease: "IBS" (All Domains + Separate Counts)</a>
        <a href="search.php?cohort=IBS-D" class="badge badge-amber" style="text-decoration:none; padding:0.45rem 0.85rem;">Subtype Filter: IBS-D (Shows Bergen MRI as Not Applicable)</a>
        <a href="search.php?q=IBS&amp;timepoint=V2_FOLLOWUP" class="badge badge-blue" style="text-decoration:none; padding:0.45rem 0.85rem;">Timepoint: Visit 2 (Shows Articles/Trials as Not Applicable)</a>
        <a href="search.php?q=BGA_072" class="badge badge-rose" style="text-decoration:none; padding:0.45rem 0.85rem;">Participant: BGA_072 (Unresolved Sex Conflict)</a>
        <a href="search.php?q=BGA_097" class="badge badge-amber" style="text-decoration:none; padding:0.45rem 0.85rem;">Participant: BGA_097 (+1 Yr Visit Offset)</a>
        <a href="search.php?q=SLC26A3" class="badge badge-green" style="text-decoration:none; padding:0.45rem 0.85rem;">Gene: SLC26A3 (Downregulated Chloride Transporter)</a>
      </div>
    </div>

  <?php else: ?>

    <!-- De-duplicated Multi-Metric Breakdown Banner -->
    <div class="waterfall-grid" style="margin-bottom:1.5rem;">
      <div class="waterfall-step">
        <div class="wf-label">Matched Table Rows</div>
        <div class="wf-num"><?= number_format($metrics['total_records']) ?></div>
        <div class="wf-desc">Raw records across active domains</div>
      </div>
      <div class="waterfall-step">
        <div class="wf-label">Unique Study Participants</div>
        <div class="wf-num"><?= count($metrics['unique_participants']) ?></div>
        <div class="wf-desc">De-duplicated by <code>study_code:ID</code></div>
      </div>
      <div class="waterfall-step">
        <div class="wf-label">Verified Multi-Modal IDs</div>
        <div class="wf-num" style="color:#059669;"><?= count($metrics['verified_matched_participants']) ?></div>
        <div class="wf-desc">Eligible for matched analysis</div>
      </div>
      <div class="waterfall-step <?= count($metrics['unresolved_conflict_participants']) > 0 ? 'excluded' : '' ?>">
        <div class="wf-label">Unresolved Conflicts</div>
        <div class="wf-num" style="color:#be123c;"><?= count($metrics['unresolved_conflict_participants']) ?></div>
        <div class="wf-desc">Excluded from matched joins</div>
      </div>
      <div class="waterfall-step">
        <div class="wf-label">Distinct Studies</div>
        <div class="wf-num"><?= count($metrics['studies']) ?></div>
        <div class="wf-desc"><?= h(implode(', ', array_keys($metrics['studies'])) ?: 'None') ?></div>
      </div>
      <div class="waterfall-step">
        <div class="wf-label">Articles &amp; Trials</div>
        <div class="wf-num"><?= $metrics['publications'] + $metrics['trials'] ?></div>
        <div class="wf-desc"><?= $metrics['publications'] ?> PMID &bull; <?= $metrics['trials'] ?> NCT (2025-02 Snapshot)</div>
      </div>
    </div>

    <!-- Render Each Domain Section (Matched, Not Applicable, or No Matching Results) -->
    <?php foreach ($domainSections as $domKey => $sec): ?>
      <?php if ($sec['status'] === 'not_applicable'): ?>
        <div class="prov-banner" style="background:#f8fafc; border-left-color:#64748b; margin-bottom:1.25rem;">
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem; margin-bottom:0.25rem;">
            <strong><?= h($sec['title']) ?></strong>
            <span class="badge badge-navy">Filter Not Applicable to Domain</span>
          </div>
          <div style="font-size:0.84rem; color:var(--navy-muted);"><?= h($sec['reason']) ?></div>
        </div>

      <?php elseif ($sec['status'] === 'no_match'): ?>
        <div class="table-card" style="margin-bottom:1.25rem; padding:0.9rem 1.25rem; background:#ffffff;">
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
            <div>
              <strong style="color:var(--navy-dark);"><?= h($sec['title']) ?></strong>
              <span style="font-size:0.82rem; color:var(--navy-subtle); margin-left:0.5rem;"><?= h($sec['reason']) ?></span>
            </div>
            <span class="badge badge-amber">0 Matching Results (Filter Applicable)</span>
          </div>
        </div>

      <?php else: ?>
        <section class="table-card" style="margin-bottom:1.75rem;">
          <div class="table-card-header" style="flex-wrap:wrap; gap:0.75rem;">
            <div>
              <h3>
                <span class="badge badge-teal"><?= h($sec['badge']) ?></span>
                <?= h($sec['title']) ?>
                <span style="font-size:0.82rem; font-weight:500; color:var(--navy-subtle);"> (<?= count($sec['rows']) ?> records)</span>
              </h3>
              <div style="font-size:0.78rem; color:var(--navy-subtle); margin-top:0.2rem;">
                <strong>Source Study / Repository:</strong> <?= h($sec['study']) ?> &bull;
                <strong>Version / Retrieval Date:</strong> <code><?= h($sec['version']) ?></code>
              </div>
            </div>
          </div>

          <?php if ($view === 'table'): ?>
            <div class="table-scroll">
              <table class="data-table">
                <thead>
                  <tr>
                    <?php foreach ($sec['columns'] as $col): ?>
                      <th><?= h($col) ?></th>
                    <?php endforeach; ?>
                    <th>Available Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($sec['rows'] as $row): ?>
                    <tr>
                      <?php foreach ($sec['columns'] as $col): ?>
                        <td style="font-size:0.82rem;">
                          <?php if ($col === 'Why It Matched'): ?>
                            <span style="color:var(--teal-dark); font-weight:600;"><?= h((string)$row[$col]) ?></span>
                          <?php elseif ($col === 'Source & Version'): ?>
                            <code style="font-size:0.74rem;"><?= h((string)$row[$col]) ?></code>
                          <?php else: ?>
                            <?= h((string)$row[$col]) ?>
                          <?php endif; ?>
                        </td>
                      <?php endforeach; ?>
                      <td>
                        <div style="display:flex; gap:0.35rem; flex-wrap:wrap;">
                          <?php foreach ($row['_actions'] as $act): ?>
                            <a href="<?= h($act['url']) ?>" <?= !empty($act['external']) ? 'target="_blank" rel="noopener"' : '' ?> class="btn btn-outline btn-sm" style="padding:0.2rem 0.5rem; font-size:0.74rem;">
                              <?= h($act['label']) ?>
                            </a>
                          <?php endforeach; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div style="padding:1.25rem;" class="cards-grid">
              <?php foreach ($sec['rows'] as $row): ?>
                <div class="card">
                  <div class="card-title"><?= h((string)reset($row)) ?></div>
                  <div class="card-body">
                    <?php foreach ($sec['columns'] as $col): ?>
                      <div style="display:flex; justify-content:space-between; gap:0.5rem; margin-bottom:0.35rem; font-size:0.82rem; border-bottom:1px dotted var(--border-light); padding-bottom:0.2rem;">
                        <span style="color:var(--navy-subtle);"><?= h($col) ?>:</span>
                        <span style="font-weight:600; color:var(--navy-dark); text-align:right;"><?= h((string)($row[$col] ?? '')) ?></span>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <div class="card-footer">
                    <?php foreach ($row['_actions'] as $act): ?>
                      <a href="<?= h($act['url']) ?>" <?= !empty($act['external']) ? 'target="_blank" rel="noopener"' : '' ?> class="btn btn-outline btn-sm" style="font-size:0.75rem;">
                        <?= h($act['label']) ?>
                      </a>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    <?php endforeach; ?>

  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
