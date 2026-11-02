<?php
declare(strict_types=1);

/**
 * Versioned, Non-Destructive Migration & Provenance Ingestion Runner
 * Stages A & B (Phases 1 & 2): Provenance-Aware Data Model & Verified Participant Linkage
 */

require_once __DIR__ . '/../db.php';

function parseXlsxSheet(string $path): array {
    if (!file_exists($path)) return [];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [];

    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $ss = new SimpleXMLElement($ssXml);
        foreach ($ss->si as $si) {
            if (isset($si->t)) {
                $shared[] = (string)$si->t;
            } else {
                $txt = '';
                foreach ($si->r as $r) $txt .= (string)$r->t;
                $shared[] = $txt;
            }
        }
    }

    $shXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!$shXml) {
        $zip->close();
        return [];
    }
    $sh = new SimpleXMLElement($shXml);
    $table = [];
    foreach ($sh->sheetData->row as $row) {
        $rData = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $colLetters = preg_replace('/[0-9]+/', '', $ref);
            $colIdx = 0;
            for ($i = 0; $i < strlen($colLetters); $i++) {
                $colIdx = $colIdx * 26 + (ord($colLetters[$i]) - ord('A') + 1);
            }
            $colIdx--;
            $type = (string)$c['t'];
            $val  = isset($c->v) ? (string)$c->v : '';
            if ($type === 's') {
                $val = $shared[(int)$val] ?? $val;
            }
            $rData[$colIdx] = $val;
        }
        if ($rData) {
            $maxCol = max(array_keys($rData));
            $fullRow = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $fullRow[$i] = $rData[$i] ?? '';
            }
            $table[] = $fullRow;
        }
    }
    $zip->close();
    return $table;
}

echo "=== Running NeuroGI Atlas Versioned Migrations & Provenance Engine ===\n";

$db = pdo();
$baseDir = dirname(__DIR__);

// 1. Execute 001_provenance_schema.sql
$sqlFile = __DIR__ . '/001_provenance_schema.sql';
$sqlContent = file_get_contents($sqlFile);
$checksum = hash('sha256', (string)$sqlContent);

// Strip single-line SQL comments before splitting on semicolons
$cleanSql = preg_replace('/^\s*--.*$/m', '', (string)$sqlContent);
$statements = array_filter(array_map('trim', explode(';', (string)$cleanSql)));
foreach ($statements as $stmtSql) {
    if ($stmtSql !== '') {
        $db->exec($stmtSql);
    }
}

// Non-destructive column additions on significant_genes (for lfcSE, stat, contrast, tissue)
$existingCols = $db->query("SHOW COLUMNS FROM significant_genes")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('lfcSE', $existingCols, true)) {
    $db->exec("ALTER TABLE significant_genes ADD COLUMN lfcSE DOUBLE DEFAULT NULL AFTER log2FoldChange");
}
if (!in_array('stat', $existingCols, true)) {
    $db->exec("ALTER TABLE significant_genes ADD COLUMN stat DOUBLE DEFAULT NULL AFTER lfcSE");
}
if (!in_array('contrast_definition', $existingCols, true)) {
    $db->exec("ALTER TABLE significant_genes ADD COLUMN contrast_definition VARCHAR(120) DEFAULT 'IBS-D (Diarrhea) vs Healthy Control (HC)' AFTER expression_change");
}
if (!in_array('tissue', $existingCols, true)) {
    $db->exec("ALTER TABLE significant_genes ADD COLUMN tissue VARCHAR(100) DEFAULT 'Sigmoid Colon Mucosal Biopsy' AFTER contrast_definition");
}

// Enrich significant_genes with lfcSE and stat from NeuroGI_Atlas_annotated_genes.csv
$annotFile = $baseDir . '/data/NeuroGI_Atlas_annotated_genes.csv';
if (file_exists($annotFile)) {
    $h = fopen($annotFile, 'r');
    $hdr = fgetcsv($h);
    $upStmt = $db->prepare("UPDATE significant_genes SET lfcSE = ?, stat = ?, contrast_definition = 'IBS-D (Diarrhea, n=25) vs Healthy Control (HC, n=21)', tissue = 'Colonic Mucosal Biopsy (Sigmoid)' WHERE gene_id = ?");
    while (($r = fgetcsv($h)) !== false) {
        if (count($r) >= 7) {
            $upStmt->execute([(float)$r[2], (float)$r[3], trim($r[6])]);
        }
    }
    fclose($h);
}

$migStmt = $db->prepare("INSERT INTO schema_migrations (version, description, checksum) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE checksum = VALUES(checksum)");
$migStmt->execute(['001_provenance_schema', 'Provenance-aware multimodal data model, participant linkage, DQ registry, and metabolomics tables', $checksum]);
echo "[OK] Applied migration 001_provenance_schema (SHA-256: " . substr($checksum, 0, 12) . "...)\n";

// 2. Populate prov_studies
$studiesData = [
    [
        'MARS_GSE146853',
        'Longitudinal Multi-Omics Reveals Subset-Specific Mechanisms Underlying Irritable Bowel Syndrome',
        'Mars et al. (2020) Cell / GSE146853',
        'Mayo Clinic & University of Minnesota',
        'USA',
        'GSE146853',
        '32916129',
        '10.1016/j.cell.2020.08.007',
        'Longitudinal Observational Cohort (Timepoints T1 & T2, ~6 months apart)',
        'Adults with Rome III IBS-D (Diarrhea-predominant), IBS-C (Constipation-predominant), and Healthy Controls undergoing flexible sigmoidoscopy mucosal biopsies and longitudinal stool sampling.',
        'IBS-D, IBS-C, and Healthy Controls (Subtype-Specific)',
        1,
        'NCBI GEO Public Domain / CC-BY 4.0 Supplementary Data',
        'Publicly accessible de-identified research metadata and count matrices; NMR stool tube IDs (1-77) are not publicly mapped to biopsy patient IDs (10007xxx).',
        'https://www.ncbi.nlm.nih.gov/geo/query/acc.cgi?acc=GSE146853',
        'Primary study for colonic mucosal RNA-seq (68 biopsies across 42 patients), GC-MS mucosal biopsy SCFAs (74 biopsies across 42 patients; 54 exact sample-visit matches to RNA-seq), and NMR stool metabolomics (464 samples across 76 tube IDs).'
    ],
    [
        'BERGEN_BGA',
        'Bergen Brain-Gut Study: Subcortical Brain Volumetrics, RBANS Cognition, and IBS Symptom Distress',
        'Bergen Brain-Gut Cohort (PSN-IBS / ibs-distress)',
        'Haukeland University Hospital & University of Bergen',
        'Norway',
        'PSN-IBS / ibs-distress',
        '31932487',
        '10.3389/fnins.2020.00618',
        'Cross-Sectional Case-Control Multimodal Cohort (Clinical Intake & Brain MRI)',
        '94 unique BGA study participants (BGA_046 to BGA_172): 78 with 3T FreeSurfer brain volumetrics and RBANS cognition, 77 with detailed IBS-SSS and symptom distress scores; 61 overlapping IDs.',
        'Generic IBS (Rome III, subtype not stratified in public table) and Healthy Controls (HC)',
        1,
        'CC-BY 4.0 (GitHub arvidl/psn-ibs & arvidl/ibs-distress)',
        'Open de-identified tabular derivatives; original raw CSV files were imported directly into MySQL prior to repository archival.',
        'https://github.com/arvidl/psn-ibs',
        'Independent Norwegian cohort. Never joined with Mars et al. (GSE146853) or Simula endoscopy datasets.'
    ],
    [
        'SIMULA_KVASIR_SEG',
        'HyperKvasir & Kvasir-SEG Gastrointestinal Endoscopy Polyp Segmentation Benchmark',
        'Simula HyperKvasir / Kvasir-SEG',
        'Simula Research Laboratory & Bærum Hospital',
        'Norway',
        'HyperKvasir / Kvasir-SEG',
        '32868775',
        '10.1038/s41597-020-00622-y',
        'Endoscopic Image Benchmark Archive (Non-IBS Reference)',
        'General clinical gastroenterology endoscopy frames and 1,000 annotated colorectal polyp images/masks.',
        'General GI Endoscopy / Colorectal Polyps (NOT an IBS cohort)',
        0,
        'CC-BY 4.0 (Research & Educational Use)',
        'Reference anatomical/polyp imagery only. Raw frames in hyperkvasir_import_staging point to Colab paths; 1,000 Kvasir-SEG binary masks and bounding boxes are stored locally.',
        'https://datasets.simula.no/kvasir-seg/',
        'Included strictly as mucosal/endoscopic reference imagery. Zero participant overlap with BGA or GSE146853.'
    ],
    [
        'OPENNEURO_DS005747',
        'OpenNeuro 7T Structural & Resting-State Brain MRI Reference Dataset',
        'OpenNeuro ds005747 (7T MRI Reference)',
        'OpenNeuro Repository',
        'International',
        'ds005747',
        null,
        '10.18112/openneuro.ds005747.v1.2.1',
        'Healthy Adult Neuroimaging Reference (7-Tesla)',
        'Healthy adult volunteers (not an IBS cohort); single anatomical NIfTI volume (sub-011_T1w.nii.gz) stored locally.',
        'Healthy Reference (Non-IBS)',
        0,
        'CC0 1.0 Public Domain',
        'Public domain structural reference volume.',
        'https://openneuro.org/datasets/ds005747',
        'Anatomical reference volume only; not linked to BGA or GSE146853 participants.'
    ],
    [
        'CURATED_REPOSITORIES',
        'Curated External Literature, Clinical Trials, and NCBI GEO Screening Repositories',
        'PubMed / ClinicalTrials.gov / GEO Catalogs',
        'NCBI & NLM Public Registries',
        'International',
        'PubMed / NCT / GEO',
        null,
        null,
        'Literature & Registry Curation',
        '100 PubMed articles, 100 ClinicalTrials.gov registered IBS studies, and 22 human gut-brain candidate GEO series.',
        'Multi-study literature and trial metadata',
        1,
        'NLM / NCBI Public Domain',
        'Cached snapshot retrieved 2026-10-31; trial status reflects status at last retrieval.',
        'https://pubmed.ncbi.nlm.nih.gov/',
        'Provides cross-study literature context and trial metadata.'
    ]
];

$stStudy = $db->prepare("INSERT INTO prov_studies (
    study_code, title, short_name, institution, country, primary_accession, pmid, doi,
    study_design, population_description, cohort_scope, is_ibs_cohort, license,
    access_restrictions, source_url, notes
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
    title=VALUES(title), short_name=VALUES(short_name), pmid=VALUES(pmid),
    doi=VALUES(doi), population_description=VALUES(population_description), notes=VALUES(notes)");

foreach ($studiesData as $sd) {
    $stStudy->execute($sd);
}
echo "[OK] Populated prov_studies (" . count($studiesData) . " studies)\n";

// 3. Populate prov_datasets & prov_dataset_versions
$datasetsData = [
    ['GSE146853_RNASEQ_META', 'MARS_GSE146853', 'GSE146853 Colonic Biopsy Sample Metadata', 'transcriptomics', 'Sample Metadata & Demographics', 'sample_metadata (data/GSE146853_sample_metadata.csv)', 'real_source_data', 68, 42, 'T1 (Visit 1, n=42), T2 (Visit 2, n=26)', 'Age (years), BMI (kg/m²); 46 rows required timepoint/patientid parsing from sample_title; GSM4914057 missing demographics.', 'Public Domain (NCBI GEO)', 'Public', '2026-10-31', 'data/GSE146853_sample_metadata.csv'],
    ['GSE146853_RNASEQ_COUNTS', 'MARS_GSE146853', 'GSE146853 Filtered Gene Read Count Matrix', 'transcriptomics', 'Raw RNA-seq Read Counts', 'data/GSE146853_filtered_counts.csv', 'real_source_data', 68, 42, 'T1 (n=42), T2 (n=26)', 'Unnormalized integer sequencing read counts; filtered to 29,767 genes with >=10 counts in >=3 samples.', 'Public Domain (NCBI GEO)', 'Public', '2026-10-31', 'data/GSE146853_filtered_counts.csv'],
    ['GSE146853_DESEQ2_SIG15', 'MARS_GSE146853', 'GSE146853 Curated FDR-Controlled DE Genes (n=15)', 'transcriptomics', 'Differential Gene Expression (DESeq2)', 'significant_genes (data/NeuroGI_Atlas_annotated_genes.csv)', 'real_source_data', 68, 42, 'Combined Biopsy Cohort (IBS-D vs HC contrast)', 'DESeq2 median-of-ratios normalized baseMean, log2FoldChange (IBS-D vs HC), lfcSE, Wald stat, pvalue, Benjamini-Hochberg padj < 0.05.', 'CC-BY 4.0 / Public Domain', 'Public', '2026-10-31', 'data/NeuroGI_Atlas_annotated_genes.csv'],
    ['GSE146853_DESEQ2_ALL', 'MARS_GSE146853', 'GSE146853 Full Transcriptome DE Table (29,767 Genes)', 'transcriptomics', 'Full Transcriptome DESeq2 Output', 'gene_results (NeuroGI_Atlas_All_DE_Results.csv)', 'real_source_data', 68, 42, 'Combined Biopsy Cohort', 'Contains 29,767 tested genes plus 1 imported header row artifact (id=32); includes unshrunken and shrunken log2FC from initial pipeline run.', 'CC-BY 4.0 / Public Domain', 'Public', '2026-10-31', null],
    ['GSE146853_GO_ORA', 'MARS_GSE146853', 'GSE146853 GO Biological Process Enrichment (6 Terms)', 'transcriptomics', 'Pathway Over-Representation Analysis', 'go_enrichment (data/NeuroGI_Atlas_corrected_GO_enrichment.csv)', 'real_source_data', 6, 0, 'Not Applicable (Gene-Set Level)', 'clusterProfiler hypergeometric ORA: 11 mapped significant genes vs 14,893 expressed background universe; BH p.adjust & qvalue.', 'CC-BY 4.0', 'Public', '2026-10-31', 'data/NeuroGI_Atlas_corrected_GO_enrichment.csv'],
    ['MARS_GCMS_BIOPSY', 'MARS_GSE146853', 'Mars et al. GC-MS Colonic Biopsy SCFA & Metabolomics', 'metabolomics_gcms', 'Targeted Mucosal Biopsy GC-MS Metabolomics', 'mars_gcms_biopsy_metabolites (Supplementary data III source data GC-MS biopsy.xlsx)', 'real_source_data', 74, 42, 'Visit 1 (T1, n=41), Visit 2 (T2, n=33)', '10 metabolites (acetate, propionate, isobutyrate, butyrate, 2-methylbutyrate, isovalerate, valerate, caproate, lactate, 2-OH-butyrate); 54 exact sample-visit matches to GSE146853 RNA-seq.', 'CC-BY 4.0 (Mars et al. 2020 Supplementary Data III)', 'Public', '2020-09-17', 'data/Mars et al, IBS multi-omics/Supplementary data III source data GC-MS biopsy.xlsx'],
    ['MARS_NMR_STOOL', 'MARS_GSE146853', 'Mars et al. Longitudinal Stool 1H-NMR Metabolomics', 'metabolomics_nmr', 'Untargeted/Targeted Stool NMR Metabolomics', 'mars_nmr_stool_metabolites (Supplementary data II source data NMR metabolomics.xlsx)', 'real_source_data', 464, 76, 'Timepoints 0, 1, 2, 3, 4, 5, 6, 7 (Longitudinal Stool)', '19 fecal metabolites across 464 samples from 76 anonymized tube IDs (ID_on_tube). Unlinked to 10007xxx biopsy IDs due to unblinded key unavailability.', 'CC-BY 4.0 (Mars et al. 2020 Supplementary Data II)', 'Public', '2020-09-17', 'data/Mars et al, IBS multi-omics/Supplementary data II source data NMR metabolomics.xlsx'],
    ['BGA_PSN_IBS_MRI', 'BERGEN_BGA', 'Bergen PSN-IBS Brain MRI Volumetrics & RBANS Cognition', 'neuroimaging', 'FreeSurfer Subcortical/Cortical Volumes & RBANS', 'brain_measurements', 'real_source_data', 78, 78, 'V1_MRI (Single Cross-Sectional MRI Assessment)', 'eTIV is in raw mm³ (~1.2M–1.8M mm³). All 35 regional brain volumes are stored as eTIV-normalized proportions (Region_mm³ / eTIV). RBANS indices are standardized scores (mean ~100).', 'CC-BY 4.0 (github.com/arvidl/psn-ibs)', 'Public DB Table (Original CSV not in data/)', '2026-10-31', null],
    ['BGA_IBS_DISTRESS_CLIN', 'BERGEN_BGA', 'Bergen BGA Clinical Symptom Distress & IBS-SSS Profiles', 'clinical_symptoms', 'IBS-SSS & Birgitta/Psychological Distress Items', 'clinical_symptom_data', 'real_source_data', 77, 77, 'V1_CLIN (Single Cross-Sectional Clinical Assessment)', 'IBS-SSS total score (0–500 scale; 0 = asymptomatic) and 8 symptom/distress subdomain items (B1, B2, H1, H2, F1, F2, R1, R2).', 'CC-BY 4.0 (github.com/arvidl/ibs-distress)', 'Public DB Table (Original CSV not in data/)', '2026-10-31', null],
    ['KVASIR_STAGING_1001', 'SIMULA_KVASIR_SEG', 'HyperKvasir Staged Endoscopy Metadata (Colab Paths)', 'endoscopy_reference', 'Endoscopic Image Metadata & BBox Flags', 'hyperkvasir_import_staging', 'reference_benchmark_non_ibs', 1000, 0, 'Not Applicable (Cross-Sectional Reference Frames)', '1,000 valid metadata rows + 1 header artifact row (image_id); image_path points to Google Colab (/content/hyperkvasir_gut_images/...).', 'CC-BY 4.0 (Simula Research Laboratory)', 'Metadata local; raw JPEGs external', '2026-10-31', null],
    ['KVASIR_SEG_LOCAL_1000', 'SIMULA_KVASIR_SEG', 'Kvasir-SEG Local Segmentation Masks & Bounding Boxes', 'endoscopy_reference', 'Polyp Segmentation Masks & BBox Coordinates', 'kvasir_seg_local_assets (images/masks/ + kavsir_bboxes.json)', 'reference_benchmark_non_ibs', 1000, 0, 'Not Applicable (Reference Polyp Masks)', '1,000 local binary segmentation masks (.jpg) in images/masks/ with pixel dimensions and bounding box coordinates from kavsir_bboxes.json.', 'CC-BY 4.0 (Simula Research Laboratory)', 'Local Masks & JSON', '2026-10-31', 'data/kvasir-temp/Kvasir-SEG/kavsir_bboxes.json'],
    ['OPENNEURO_7T_MRI', 'OPENNEURO_DS005747', 'OpenNeuro ds005747 7T Structural Brain NIfTI Volume', 'anatomical_mri_reference', '7T T1-weighted Structural MRI', 'image_records (medical images/brain/sub-011_T1w.nii.gz)', 'reference_benchmark_non_ibs', 1, 1, 'Single Reference Scan', 'Compressed NIfTI volume (10.9 MB) for healthy subject sub-011.', 'CC0 1.0 Public Domain', 'Public Local File', '2026-10-31', 'medical images/brain/sub-011_T1w.nii.gz'],
    ['PUBMED_GUTBRAIN_100', 'CURATED_REPOSITORIES', 'Curated PubMed Gut-Brain Axis Literature (100 Articles)', 'literature', 'Peer-Reviewed Biomedical Abstracts', 'pubmed_articles (data/pubmed_articles_clean.csv)', 'curated_repository_extract', 100, 0, 'Not Applicable (Publication Years 2013–2026)', 'PMID, title, publication year, abstract, source.', 'NLM Public Domain', 'Public', '2026-10-31', 'data/pubmed_articles_clean.csv'],
    ['CLINICALTRIALS_IBS_100', 'CURATED_REPOSITORIES', 'ClinicalTrials.gov Registered IBS Studies (100 Trials)', 'clinical_trials', 'Clinical Trial Registry Records', 'clinical_trials (data/clinical_trials_clean.csv)', 'curated_repository_extract', 100, 0, 'Not Applicable (Trial Start/Completion Dates)', 'NCT ID, status at last retrieval (2026-10-31), study type, phase, conditions, enrollment, sponsor, summary.', 'ClinicalTrials.gov Public Domain', 'Public', '2026-10-31', 'data/clinical_trials_clean.csv'],
    ['GEO_CANDIDATES_22', 'CURATED_REPOSITORIES', 'Screened Human Gut-Brain NCBI GEO Series (22 Studies)', 'geo_studies', 'External Transcriptomic Study Metadata', 'geo_candidates (data/geo_gut_brain_candidates.csv)', 'curated_repository_extract', 22, 0, 'Not Applicable (Submission Dates 2005–2026)', 'GEO accession, title, summary, organism, study type, sample count.', 'NCBI GEO Public Domain', 'Public', '2026-10-31', 'data/geo_gut_brain_candidates.csv'],
    ['LEGACY_STUDIES_CSV', 'CURATED_REPOSITORIES', 'Legacy Composite Studies Table (data/studies.csv)', 'synthetic_legacy', 'Legacy Demo Summary Rows', 'data/studies.csv (used only by search_legacy.php)', 'synthetic_or_composite_legacy', 3, 0, 'Not Applicable', '3 composite summary rows containing mismatched modality labels (flagged in DQ audit and isolated from research search).', 'Internal Legacy File', ' Quarantined from primary analysis', '2026-10-31', 'data/studies.csv'],
];

$stDs = $db->prepare("INSERT INTO prov_datasets (
    dataset_code, study_code, dataset_name, domain, measurement_layer,
    source_table_or_file, record_authenticity, sample_count, participant_count,
    available_timepoints, unit_and_normalization_note, license, access_restrictions,
    retrieval_or_version_date
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
    dataset_name=VALUES(dataset_name), sample_count=VALUES(sample_count),
    participant_count=VALUES(participant_count), available_timepoints=VALUES(available_timepoints),
    unit_and_normalization_note=VALUES(unit_and_normalization_note)");

$stVer = $db->prepare("INSERT INTO prov_dataset_versions (
    dataset_code, version_tag, retrieval_date, file_path, file_sha256,
    row_count, import_script, transformation_log, is_current
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
ON DUPLICATE KEY UPDATE
    file_sha256=VALUES(file_sha256), row_count=VALUES(row_count), transformation_log=VALUES(transformation_log)");

foreach ($datasetsData as $d) {
    $fileRel = array_pop($d);
    $stDs->execute($d);
    $dsCode = $d[0];
    $sampleCount = $d[7];
    $retDate = $d[13];
    $sha = null;
    if ($fileRel && file_exists($baseDir . '/' . $fileRel)) {
        $sha = hash_file('sha256', $baseDir . '/' . $fileRel);
    }
    $stVer->execute([
        $dsCode,
        'v1.0-audited',
        $retDate,
        $fileRel ?: 'MySQL Table: ' . $d[5],
        $sha,
        $sampleCount,
        'migrations/run_migrations.php',
        $d[10]
    ]);
}
echo "[OK] Populated prov_datasets & prov_dataset_versions (" . count($datasetsData) . " datasets)\n";

// 4. Populate prov_measurement_defs
$measureDefs = [
    // Brain MRI (PSN-IBS)
    ['eTIV', 'neuroimaging', 'Estimated Total Intracranial Volume (eTIV)', 'eTIV', 'mm³', '1,000,000 – 2,000,000 mm³', 'Raw FreeSurfer aseg volume (mm³)', 'Reference intracranial volume used to normalize regional volumes.', 'FreeSurfer estimated Total Intracranial Volume in cubic millimeters.'],
    ['Left-Thalamus', 'neuroimaging', 'Left Thalamus Volume', 'Left-Thalamus', 'Proportion of eTIV', '0.0035 – 0.0075', 'eTIV-Normalized Proportion (Raw_mm³ / eTIV)', 'Already divided by eTIV in source table. Derived raw mm³ = value * eTIV. Do NOT divide by eTIV a second time.', 'FreeSurfer Left-Thalamus-Proper volume normalized by eTIV.'],
    ['Right-Thalamus', 'neuroimaging', 'Right Thalamus Volume', 'Right-Thalamus', 'Proportion of eTIV', '0.0035 – 0.0075', 'eTIV-Normalized Proportion (Raw_mm³ / eTIV)', 'Already divided by eTIV in source table. Derived raw mm³ = value * eTIV.', 'FreeSurfer Right-Thalamus-Proper volume normalized by eTIV.'],
    ['Left-Hippocampus', 'neuroimaging', 'Left Hippocampus Volume', 'Left-Hippocampus', 'Proportion of eTIV', '0.0020 – 0.0045', 'eTIV-Normalized Proportion (Raw_mm³ / eTIV)', 'Already divided by eTIV in source table. Derived raw mm³ = value * eTIV.', 'FreeSurfer Left-Hippocampus volume normalized by eTIV.'],
    ['Right-Hippocampus', 'neuroimaging', 'Right Hippocampus Volume', 'Right-Hippocampus', 'Proportion of eTIV', '0.0020 – 0.0045', 'eTIV-Normalized Proportion (Raw_mm³ / eTIV)', 'Already divided by eTIV in source table. Derived raw mm³ = value * eTIV.', 'FreeSurfer Right-Hippocampus volume normalized by eTIV.'],
    ['Left-Amygdala', 'neuroimaging', 'Left Amygdala Volume', 'Left-Amygdala', 'Proportion of eTIV', '0.0008 – 0.0020', 'eTIV-Normalized Proportion (Raw_mm³ / eTIV)', 'Already divided by eTIV in source table. Derived raw mm³ = value * eTIV.', 'FreeSurfer Left-Amygdala volume normalized by eTIV.'],
    ['Right-Amygdala', 'neuroimaging', 'Right Amygdala Volume', 'Right-Amygdala', 'Proportion of eTIV', '0.0008 – 0.0020', 'eTIV-Normalized Proportion (Raw_mm³ / eTIV)', 'Already divided by eTIV in source table. Derived raw mm³ = value * eTIV.', 'FreeSurfer Right-Amygdala volume normalized by eTIV.'],
    ['Left-Caudate', 'neuroimaging', 'Left Caudate Volume', 'Left-Caudate', 'Proportion of eTIV', '0.0018 – 0.0038', 'eTIV-Normalized Proportion (Raw_mm³ / eTIV)', 'Already divided by eTIV in source table. Derived raw mm³ = value * eTIV.', 'FreeSurfer Left-Caudate volume normalized by eTIV.'],
    ['Right-Caudate', 'neuroimaging', 'Right Caudate Volume', 'Right-Caudate', 'Proportion of eTIV', '0.0018 – 0.0038', 'eTIV-Normalized Proportion (Raw_mm³ / eTIV)', 'Already divided by eTIV in source table. Derived raw mm³ = value * eTIV.', 'FreeSurfer Right-Caudate volume normalized by eTIV.'],
    ['Left-Putamen', 'neuroimaging', 'Left Putamen Volume', 'Left-Putamen', 'Proportion of eTIV', '0.0025 – 0.0050', 'eTIV-Normalized Proportion (Raw_mm³ / eTIV)', 'Already divided by eTIV in source table. Derived raw mm³ = value * eTIV.', 'FreeSurfer Left-Putamen volume normalized by eTIV.'],
    ['Right-Putamen', 'neuroimaging', 'Right Putamen Volume', 'Right-Putamen', 'Proportion of eTIV', '0.0025 – 0.0050', 'eTIV-Normalized Proportion (Raw_mm³ / eTIV)', 'Already divided by eTIV in source table. Derived raw mm³ = value * eTIV.', 'FreeSurfer Right-Putamen volume normalized by eTIV.'],
    ['Left-Pallidum', 'neuroimaging', 'Left Pallidum Volume', 'Left-Pallidum', 'Proportion of eTIV', '0.0009 – 0.0020', 'eTIV-Normalized Proportion (Raw_mm³ / eTIV)', 'Already divided by eTIV in source table. Derived raw mm³ = value * eTIV.', 'FreeSurfer Left-Pallidum volume normalized by eTIV.'],
    ['Right-Pallidum', 'neuroimaging', 'Right Pallidum Volume', 'Right-Pallidum', 'Proportion of eTIV', '0.0009 – 0.0020', 'eTIV-Normalized Proportion (Raw_mm³ / eTIV)', 'Already divided by eTIV in source table. Derived raw mm³ = value * eTIV.', 'FreeSurfer Right-Pallidum volume normalized by eTIV.'],
    ['Left-Accumbens-area', 'neuroimaging', 'Left Accumbens Area Volume', 'Left-Accumbens-area', 'Proportion of eTIV', '0.0002 – 0.0008', 'eTIV-Normalized Proportion (Raw_mm³ / eTIV)', 'Already divided by eTIV in source table. Derived raw mm³ = value * eTIV.', 'FreeSurfer Left-Accumbens-area volume normalized by eTIV.'],
    ['Right-Accumbens-area', 'neuroimaging', 'Right Accumbens Area Volume', 'Right-Accumbens-area', 'Proportion of eTIV', '0.0002 – 0.0008', 'eTIV-Normalized Proportion (Raw_mm³ / eTIV)', 'Already divided by eTIV in source table. Derived raw mm³ = value * eTIV.', 'FreeSurfer Right-Accumbens-area volume normalized by eTIV.'],
    ['Left-Cerebellum-Cortex', 'neuroimaging', 'Left Cerebellum Cortex Volume', 'Left-Cerebellum-Cortex', 'Proportion of eTIV', '0.025 – 0.055', 'eTIV-Normalized Proportion (Raw_mm³ / eTIV)', 'Already divided by eTIV in source table. Derived raw mm³ = value * eTIV.', 'FreeSurfer Left-Cerebellum-Cortex volume normalized by eTIV.'],
    ['Right-Cerebellum-Cortex', 'neuroimaging', 'Right Cerebellum Cortex Volume', 'Right-Cerebellum-Cortex', 'Proportion of eTIV', '0.025 – 0.055', 'eTIV-Normalized Proportion (Raw_mm³ / eTIV)', 'Already divided by eTIV in source table. Derived raw mm³ = value * eTIV.', 'FreeSurfer Right-Cerebellum-Cortex volume normalized by eTIV.'],
    ['CortexVol', 'neuroimaging', 'Total Cerebral Cortex Volume', 'CortexVol', 'Proportion of eTIV', '0.28 – 0.42', 'eTIV-Normalized Proportion (Raw_mm³ / eTIV)', 'Already divided by eTIV in source table. Derived raw mm³ = value * eTIV.', 'FreeSurfer total cortical gray matter volume normalized by eTIV.'],
    ['SubCortGrayVol', 'neuroimaging', 'Subcortical Gray Matter Volume', 'SubCortGrayVol', 'Proportion of eTIV', '0.035 – 0.055', 'eTIV-Normalized Proportion (Raw_mm³ / eTIV)', 'Already divided by eTIV in source table. Derived raw mm³ = value * eTIV.', 'FreeSurfer total subcortical gray matter volume normalized by eTIV.'],
    ['TotalGrayVol', 'neuroimaging', 'Total Gray Matter Volume', 'TotalGrayVol', 'Proportion of eTIV', '0.40 – 0.56', 'eTIV-Normalized Proportion (Raw_mm³ / eTIV)', 'Already divided by eTIV in source table. Derived raw mm³ = value * eTIV.', 'FreeSurfer total gray matter volume normalized by eTIV.'],
    ['CerebralWhiteMatterVol', 'neuroimaging', 'Total Cerebral White Matter Volume', 'CerebralWhiteMatterVol', 'Proportion of eTIV', '0.25 – 0.38', 'eTIV-Normalized Proportion (Raw_mm³ / eTIV)', 'Already divided by eTIV in source table. Derived raw mm³ = value * eTIV.', 'FreeSurfer total cerebral white matter volume normalized by eTIV.'],
    ['BrainSegVol', 'neuroimaging', 'Brain Segmentation Volume', 'BrainSegVol', 'Proportion of eTIV', '0.70 – 0.95', 'eTIV-Normalized Proportion (Raw_mm³ / eTIV)', 'Already divided by eTIV in source table. Derived raw mm³ = value * eTIV.', 'FreeSurfer total segmented brain volume normalized by eTIV.'],
    // Cognition (RBANS)
    ['Fullscale_RBANS', 'cognition', 'RBANS Full-Scale Total Index', 'Fullscale_RBANS', 'Index Score', '40 – 160 (Normative Mean=100, SD=15)', 'Age-normed standard score', 'Not applicable', 'Repeatable Battery for the Assessment of Neuropsychological Status (RBANS) total scale score.'],
    ['Memory_Index', 'cognition', 'RBANS Immediate Memory Index', 'Memory_Index', 'Index Score', '40 – 160 (Mean=100, SD=15)', 'Age-normed standard score', 'Not applicable', 'RBANS Immediate Memory domain index score.'],
    ['Visuospatial_Index', 'cognition', 'RBANS Visuospatial/Constructional Index', 'Visuospatial_Index', 'Index Score', '40 – 160 (Mean=100, SD=15)', 'Age-normed standard score', 'Not applicable', 'RBANS Visuospatial/Constructional domain index score.'],
    ['Verbalskills_Index', 'cognition', 'RBANS Language / Verbal Skills Index', 'Verbalskills_Index', 'Index Score', '40 – 160 (Mean=100, SD=15)', 'Age-normed standard score', 'Not applicable', 'RBANS Language / Verbal skills domain index score.'],
    ['Attention_Index', 'cognition', 'RBANS Attention Index', 'Attention_Index', 'Index Score', '40 – 160 (Mean=100, SD=15)', 'Age-normed standard score', 'Not applicable', 'RBANS Attention domain index score.'],
    ['Recall_Index', 'cognition', 'RBANS Delayed Memory / Recall Index', 'Recall_Index', 'Index Score', '40 – 160 (Mean=100, SD=15)', 'Age-normed standard score', 'Not applicable', 'RBANS Delayed Memory / Recall domain index score.'],
    // Clinical Symptoms (IBS-SSS & Distress)
    ['IBS_SSS', 'clinical_symptoms', 'IBS Severity Scoring System (IBS-SSS)', 'ibs_sss / IBS_SSS', 'Points (0–500)', '0–500 (<75 remission/HC, 75–175 mild, 175–300 moderate, >300 severe)', 'Validated composite clinical scale', 'Not applicable', 'Francis et al. IBS Severity Scoring System combining pain severity, pain frequency, bloating, bowel dissatisfaction, and life interference.'],
    ['B1', 'clinical_symptoms', 'B1 Symptom Subscore (Bloating / Bowel Distress 1)', 'B1', 'Scale Points', '0 – 25', 'Raw questionnaire subscore', 'Not applicable', 'Bergen BGA clinical distress item B1.'],
    ['B2', 'clinical_symptoms', 'B2 Symptom Subscore (Distension / Bowel Distress 2)', 'B2', 'Scale Points', '0 – 25', 'Raw questionnaire subscore', 'Not applicable', 'Bergen BGA clinical distress item B2.'],
    ['H1', 'clinical_symptoms', 'H1 Symptom Subscore (Anxiety / HADS-A / Pain Freq)', 'H1', 'Scale Points', '0 – 21', 'Raw questionnaire subscore', 'Not applicable', 'Bergen BGA clinical distress item H1.'],
    ['H2', 'clinical_symptoms', 'H2 Symptom Subscore (Depression / HADS-D / Pain Sev)', 'H2', 'Scale Points', '0 – 21', 'Raw questionnaire subscore', 'Not applicable', 'Bergen BGA clinical distress item H2.'],
    ['F1', 'clinical_symptoms', 'F1 Symptom Subscore (Somatic / Physical Fatigue)', 'F1', 'Scale Points', '0 – 25', 'Raw questionnaire subscore', 'Not applicable', 'Bergen BGA clinical distress item F1.'],
    ['F2', 'clinical_symptoms', 'F2 Symptom Subscore (Mental Fatigue)', 'F2', 'Scale Points', '0 – 15', 'Raw questionnaire subscore', 'Not applicable', 'Bergen BGA clinical distress item F2.'],
    ['R1', 'clinical_symptoms', 'R1 Symptom Subscore (Emotional Distress / Resilience 1)', 'R1', 'Scale Points', '15 – 45', 'Raw questionnaire subscore', 'Not applicable', 'Bergen BGA clinical distress item R1.'],
    ['R2', 'clinical_symptoms', 'R2 Symptom Subscore (Psychosocial Burden / Resilience 2)', 'R2', 'Scale Points', '40 – 85', 'Raw questionnaire subscore', 'Not applicable', 'Bergen BGA clinical distress item R2.'],
    ['Education', 'demographics', 'Formal Education Duration', 'Education', 'Years', '8 – 25 years', 'Raw years of schooling', 'Not applicable', 'Years of formal education recorded at MRI assessment (missing for 9 HC subjects).'],
    // GC-MS Biopsy Metabolites
    ['gcms_acetate', 'metabolomics_gcms', 'Mucosal Biopsy Acetate (GC-MS)', 'acetate', 'Normalized Peak Area', 'Continuous', 'Source-standardized biopsy GC-MS quantification', 'Not applicable', 'Mucosal biopsy short-chain fatty acid acetate measured by GC-MS (Mars et al. Supp Data III).'],
    ['gcms_propionate', 'metabolomics_gcms', 'Mucosal Biopsy Propionate (GC-MS)', 'propionate', 'Normalized Peak Area', 'Continuous', 'Source-standardized biopsy GC-MS quantification', 'Not applicable', 'Mucosal biopsy short-chain fatty acid propionate measured by GC-MS (Mars et al. Supp Data III).'],
    ['gcms_butyrate', 'metabolomics_gcms', 'Mucosal Biopsy Butyrate (GC-MS)', 'butyrate', 'Normalized Peak Area', 'Continuous', 'Source-standardized biopsy GC-MS quantification', 'Not applicable', 'Mucosal biopsy short-chain fatty acid butyrate measured by GC-MS (Mars et al. Supp Data III).'],
    ['gcms_lactate', 'metabolomics_gcms', 'Mucosal Biopsy Lactate (GC-MS)', 'lactate', 'Normalized Peak Area', 'Continuous', 'Source-standardized biopsy GC-MS quantification', 'Not applicable', 'Mucosal biopsy lactate measured by GC-MS (Mars et al. Supp Data III).'],
];

$stMDef = $db->prepare("INSERT INTO prov_measurement_defs (
    measure_code, domain, measure_name, source_column, unit, scale_range,
    normalization_method, icv_handling, description
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
    measure_name=VALUES(measure_name), unit=VALUES(unit),
    normalization_method=VALUES(normalization_method), icv_handling=VALUES(icv_handling)");
foreach ($measureDefs as $md) {
    $stMDef->execute($md);
}
echo "[OK] Populated prov_measurement_defs (" . count($measureDefs) . " definitions)\n";

// 5. Populate BERGEN_BGA Participants, Visits, Source Records, Measurements, and Linkage Decisions
$brainRows = $db->query("SELECT * FROM brain_measurements ORDER BY `Subject` ASC")->fetchAll(PDO::FETCH_ASSOC);
$clinRows  = $db->query("SELECT * FROM clinical_symptom_data ORDER BY participant_id ASC")->fetchAll(PDO::FETCH_ASSOC);

$brainMap = [];
foreach ($brainRows as $r) $brainMap[trim($r['Subject'])] = $r;
$clinMap = [];
foreach ($clinRows as $r) $clinMap[trim($r['participant_id'])] = $r;

$allBgaIds = array_unique(array_merge(array_keys($brainMap), array_keys($clinMap)));
sort($allBgaIds);

$stPart = $db->prepare("INSERT INTO prov_participants (
    participant_uid, study_code, local_participant_id, raw_cohort_label, harmonized_cohort,
    subtype_specificity, raw_sex, harmonized_sex, raw_age_summary, baseline_age, bmi,
    education_years, identity_verification_status, verification_notes
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
    raw_cohort_label=VALUES(raw_cohort_label), harmonized_cohort=VALUES(harmonized_cohort),
    raw_sex=VALUES(raw_sex), harmonized_sex=VALUES(harmonized_sex),
    raw_age_summary=VALUES(raw_age_summary), baseline_age=VALUES(baseline_age),
    education_years=VALUES(education_years),
    identity_verification_status=VALUES(identity_verification_status),
    verification_notes=VALUES(verification_notes)");

$stVisit = $db->prepare("INSERT INTO prov_visits (
    visit_uid, participant_uid, study_code, visit_code, visit_label,
    timepoint_order, age_at_visit, raw_age_value, visit_notes
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
    age_at_visit=VALUES(age_at_visit), raw_age_value=VALUES(raw_age_value), visit_notes=VALUES(visit_notes)");

$stSrcRec = $db->prepare("INSERT INTO prov_source_records (
    dataset_code, source_file_or_table, original_row_id, participant_uid, visit_uid, raw_payload_json
) VALUES (?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
    participant_uid=VALUES(participant_uid), visit_uid=VALUES(visit_uid), raw_payload_json=VALUES(raw_payload_json)");

$stMeas = $db->prepare("INSERT INTO prov_measurements (
    participant_uid, visit_uid, dataset_code, measure_code, raw_value_text,
    raw_value_num, harmonized_value_num, derived_raw_mm3, unit, is_missing, quality_flag, source_record_id
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
    raw_value_text=VALUES(raw_value_text), raw_value_num=VALUES(raw_value_num),
    harmonized_value_num=VALUES(harmonized_value_num), derived_raw_mm3=VALUES(derived_raw_mm3),
    is_missing=VALUES(is_missing), quality_flag=VALUES(quality_flag)");

$stLink = $db->prepare("INSERT INTO prov_participant_links (
    source_participant_uid, source_dataset_code, source_local_id,
    target_participant_uid, target_dataset_code, target_local_id,
    same_study, link_status, exclude_from_matched_analysis,
    demographic_concordance, justification
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
    link_status=VALUES(link_status),
    exclude_from_matched_analysis=VALUES(exclude_from_matched_analysis),
    demographic_concordance=VALUES(demographic_concordance),
    justification=VALUES(justification)");

$brainMeasureCols = [
    'eTIV', 'Left-Cerebellum-White-Matter', 'Left-Cerebellum-Cortex', 'Left-Thalamus',
    'Left-Caudate', 'Left-Putamen', 'Left-Pallidum', 'Left-Hippocampus', 'Left-Amygdala',
    'Left-Accumbens-area', 'CSF', 'Right-Cerebellum-White-Matter', 'Right-Cerebellum-Cortex',
    'Right-Thalamus', 'Right-Caudate', 'Right-Putamen', 'Right-Pallidum', 'Right-Hippocampus',
    'Right-Amygdala', 'Right-Accumbens-area', 'WM-hypointensities', 'CC_Posterior',
    'CC_Mid_Posterior', 'CC_Central', 'CC_Mid_Anterior', 'CC_Anterior', 'BrainSegVol',
    'BrainSegVolNotVent', 'lhCortexVol', 'rhCortexVol', 'CortexVol',
    'lhCerebralWhiteMatterVol', 'rhCerebralWhiteMatterVol', 'CerebralWhiteMatterVol',
    'SubCortGrayVol', 'TotalGrayVol'
];
$rbansCols = [
    'Fullscale_RBANS', 'Memory_Index', 'Visuospatial_Index',
    'Verbalskills_Index', 'Attention_Index', 'Recall_Index'
];
$clinCols = ['ibs_sss' => 'IBS_SSS', 'B1' => 'B1', 'B2' => 'B2', 'H1' => 'H1', 'H2' => 'H2', 'F1' => 'F1', 'F2' => 'F2', 'R1' => 'R1', 'R2' => 'R2'];

$bgaCounts = ['verified' => 0, 'offset' => 0, 'unresolved' => 0, 'single' => 0];

foreach ($allBgaIds as $localId) {
    $pUid = "BERGEN_BGA:$localId";
    $b = $brainMap[$localId] ?? null;
    $c = $clinMap[$localId] ?? null;

    if ($b && $c) {
        $bSex = trim((string)$b['Gender']);
        $cSex = trim((string)$c['sex']);
        $bAge = (float)$b['Age'];
        $cAge = (float)$c['age'];
        $bGrp = trim((string)$b['Group']);
        $cGrp = trim((string)$c['category']);
        $bSss = (float)$b['IBS_SSS'];
        $cSss = (float)$c['ibs_sss'];

        $ageDiff = round(abs($bAge - $cAge), 2);
        $sexMatch = ($bSex === $cSex);
        $grpMatch = ($bGrp === $cGrp);
        $sssMatch = (abs($bSss - $cSss) < 0.01);

        if (!$sexMatch) {
            // BGA_072: M vs F
            $verStatus = 'unresolved_conflict';
            $linkStatus = 'unresolved';
            $exclude = 1;
            $harmSex = 'Conflict';
            $rawSexStr = "MRI:$bSex / Clin:$cSex";
            $rawAgeStr = "MRI:{$b['Age']} / Clin:{$c['age']}";
            $concordance = "Group=$bGrp/$cGrp (OK), Age=$bAge/$cAge (OK), IBS_SSS=$bSss/$cSss (OK), Sex=$bSex vs $cSex (CONFLICT)";
            $just = "Excluded from matched analysis: Sex conflict between brain_measurements (Gender='$bSex') and clinical_symptom_data (sex='$cSex'). Raw values preserved separately without silent overwrite.";
            $bgaCounts['unresolved']++;
        } elseif ($ageDiff > 1.5) {
            // BGA_070: 28 vs 22.00 (6-year discrepancy)
            $verStatus = 'unresolved_conflict';
            $linkStatus = 'unresolved';
            $exclude = 1;
            $harmSex = ($bSex === 'M' ? 'M' : 'F');
            $rawSexStr = $bSex;
            $rawAgeStr = "MRI:{$b['Age']} vs Clin:{$c['age']} (6-yr conflict)";
            $concordance = "Group=$bGrp/$cGrp (OK), Sex=$bSex (OK), IBS_SSS=$bSss/$cSss (OK), Age=$bAge vs $cAge (6-YEAR CONFLICT)";
            $just = "Excluded from matched analysis: 6-year age discrepancy between brain_measurements (Age=$bAge) and clinical_symptom_data (age={$c['age']}) exceeds plausible inter-visit interval.";
            $bgaCounts['unresolved']++;
        } elseif ($ageDiff > 0.0) {
            // BGA_083 (0.01 yr rounding) or BGA_097, BGA_128, BGA_152, BGA_156 (+1 yr MRI vs Clinical intake offset)
            $verStatus = 'verified_with_visit_offset';
            $linkStatus = 'verified_with_visit_offset';
            $exclude = 0;
            $harmSex = ($bSex === 'M' ? 'M' : 'F');
            $rawSexStr = $bSex;
            $rawAgeStr = "Clin:{$c['age']} -> MRI:{$b['Age']} (Δ=" . ($bAge - $cAge) . " yr)";
            $concordance = "Group=$bGrp (OK), Sex=$bSex (OK), IBS_SSS=$bSss (OK), Age Clin={$c['age']} vs MRI={$b['Age']} (Δ={$ageDiff} yr)";
            $just = ($ageDiff <= 0.05)
                ? "Verified same-study participant ($localId): Group, Sex, and IBS_SSS match identically; Age differs by 0.01 yr due to decimal vs integer rounding (30.01 vs 30)."
                : "Verified same-study participant ($localId): Group ($bGrp), Sex ($bSex), and IBS_SSS ($bSss) match identically across PSN-IBS and ibs-distress; MRI Age ($bAge) is +1 year relative to Clinical intake age ({$c['age']}), consistent with inter-visit interval or birthday between baseline questionnaire and MRI scan. Included in primary matched analysis with sensitivity toggle.";
            $bgaCounts['offset']++;
        } else {
            $verStatus = 'verified';
            $linkStatus = 'verified';
            $exclude = 0;
            $harmSex = ($bSex === 'M' ? 'M' : 'F');
            $rawSexStr = $bSex;
            $rawAgeStr = (string)$b['Age'];
            $concordance = "100% Exact Concordance: Group=$bGrp, Sex=$bSex, Age=$bAge, IBS_SSS=$bSss";
            $just = "Verified same-study participant ($localId) in Bergen Brain-Gut cohort: exact match on Study ID, Group ($bGrp), Sex ($bSex), Age ($bAge), and IBS-SSS ($bSss)."
                . ($localId === 'BGA_057' ? " Note: Historical audit flag investigated for BGA_057; both live DB tables currently store Female ('F'), Age=30, IBS_SSS=264." : "");
            $bgaCounts['verified']++;
        }

        $harmCohort = ($bGrp === 'HC') ? 'HC' : 'IBS';
        $subtypeSpec = ($bGrp === 'HC') ? 'healthy_control' : 'generic_ibs';
        $eduVal = ($b['Education'] !== null && $b['Education'] !== '') ? (float)$b['Education'] : null;

        $stPart->execute([
            $pUid, 'BERGEN_BGA', $localId, $bGrp, $harmCohort, $subtypeSpec,
            $rawSexStr, $harmSex, $rawAgeStr, $cAge, null, $eduVal, $verStatus, $just
        ]);

        $stLink->execute([
            $pUid, 'BGA_PSN_IBS_MRI', $localId,
            $pUid, 'BGA_IBS_DISTRESS_CLIN', $localId,
            1, $linkStatus, $exclude, $concordance, $just
        ]);
    } elseif ($b) {
        $bGrp = trim((string)$b['Group']);
        $bSex = trim((string)$b['Gender']);
        $bAge = (float)$b['Age'];
        $harmCohort = ($bGrp === 'HC') ? 'HC' : 'IBS';
        $subtypeSpec = ($bGrp === 'HC') ? 'healthy_control' : 'generic_ibs';
        $eduVal = ($b['Education'] !== null && $b['Education'] !== '') ? (float)$b['Education'] : null;
        $just = "Participant $localId is present in PSN-IBS brain_measurements (MRI + RBANS + summary IBS_SSS) but absent from clinical_symptom_data (B1-R2 subdomain table).";

        $stPart->execute([
            $pUid, 'BERGEN_BGA', $localId, $bGrp, $harmCohort, $subtypeSpec,
            $bSex, ($bSex === 'M' ? 'M' : 'F'), (string)$b['Age'], $bAge, null, $eduVal, 'single_table_only', $just
        ]);
        $stLink->execute([
            $pUid, 'BGA_PSN_IBS_MRI', $localId,
            null, 'BGA_IBS_DISTRESS_CLIN', 'NOT_PRESENT',
            1, 'not_applicable', 1, 'Present in Brain MRI table only', $just
        ]);
        $bgaCounts['single']++;
    } elseif ($c) {
        $cGrp = trim((string)$c['category']);
        $cSex = trim((string)$c['sex']);
        $cAge = (float)$c['age'];
        $harmCohort = ($cGrp === 'HC') ? 'HC' : 'IBS';
        $subtypeSpec = ($cGrp === 'HC') ? 'healthy_control' : 'generic_ibs';
        $just = "Participant $localId is present in clinical_symptom_data (IBS-SSS + B1-R2 distress scores) but did not undergo or pass QC for PSN-IBS brain_measurements.";

        $stPart->execute([
            $pUid, 'BERGEN_BGA', $localId, $cGrp, $harmCohort, $subtypeSpec,
            $cSex, ($cSex === 'M' ? 'M' : 'F'), (string)$c['age'], $cAge, null, null, 'single_table_only', $just
        ]);
        $stLink->execute([
            $pUid, 'BGA_IBS_DISTRESS_CLIN', $localId,
            null, 'BGA_PSN_IBS_MRI', 'NOT_PRESENT',
            1, 'not_applicable', 1, 'Present in Clinical Symptom table only', $just
        ]);
        $bgaCounts['single']++;
    }

    // Create explicit visits & measurements for Brain MRI
    if ($b) {
        $vMriUid = "$pUid:V1_MRI";
        $stVisit->execute([
            $vMriUid, $pUid, 'BERGEN_BGA', 'V1_MRI',
            'Neuroimaging & RBANS Assessment Visit', 1,
            (float)$b['Age'], (string)$b['Age'],
            '3T MRI structural acquisition (FreeSurfer) and RBANS cognitive battery.'
        ]);
        $stSrcRec->execute([
            'BGA_PSN_IBS_MRI', 'brain_measurements', $localId, $pUid, $vMriUid, json_encode($b, JSON_UNESCAPED_SLASHES)
        ]);
        $eTivVal = (float)$b['eTIV'];

        foreach ($brainMeasureCols as $bmCol) {
            $rawVal = $b[$bmCol];
            $isMiss = ($rawVal === null || $rawVal === '') ? 1 : 0;
            $numVal = $isMiss ? null : (float)$rawVal;
            $derivedMm3 = (!$isMiss && $bmCol !== 'eTIV' && $eTivVal > 0) ? round($numVal * $eTivVal, 2) : ($bmCol === 'eTIV' ? $numVal : null);
            $unit = ($bmCol === 'eTIV') ? 'mm³' : 'Proportion of eTIV';
            $stMeas->execute([
                $pUid, $vMriUid, 'BGA_PSN_IBS_MRI', $bmCol,
                $isMiss ? null : (string)$rawVal, $numVal, $numVal, $derivedMm3, $unit, $isMiss, null, null
            ]);
        }
        foreach ($rbansCols as $rbCol) {
            $rawVal = $b[$rbCol];
            $isMiss = ($rawVal === null || $rawVal === '') ? 1 : 0;
            $numVal = $isMiss ? null : (float)$rawVal;
            $stMeas->execute([
                $pUid, $vMriUid, 'BGA_PSN_IBS_MRI', $rbCol,
                $isMiss ? null : (string)$rawVal, $numVal, $numVal, null, 'Index Score', $isMiss, null, null
            ]);
        }
        // Education & MRI-table IBS_SSS
        $eduRaw = $b['Education'];
        $eduMiss = ($eduRaw === null || $eduRaw === '') ? 1 : 0;
        $stMeas->execute([
            $pUid, $vMriUid, 'BGA_PSN_IBS_MRI', 'Education',
            $eduMiss ? null : (string)$eduRaw, $eduMiss ? null : (float)$eduRaw, $eduMiss ? null : (float)$eduRaw,
            null, 'Years', $eduMiss, $eduMiss ? 'MISSING_IN_SOURCE' : null, null
        ]);
        $sssRaw = $b['IBS_SSS'];
        $sssMiss = ($sssRaw === null || $sssRaw === '') ? 1 : 0;
        $stMeas->execute([
            $pUid, $vMriUid, 'BGA_PSN_IBS_MRI', 'IBS_SSS',
            $sssMiss ? null : (string)$sssRaw, $sssMiss ? null : (float)$sssRaw, $sssMiss ? null : (float)$sssRaw,
            null, 'Points (0-500)', $sssMiss, ((float)$sssRaw === 0.0 ? 'TRUE_ZERO_HC_SCORE' : null), null
        ]);
    }

    // Create explicit visits & measurements for Clinical Symptom table
    if ($c) {
        $vClinUid = "$pUid:V1_CLIN";
        $stVisit->execute([
            $vClinUid, $pUid, 'BERGEN_BGA', 'V1_CLIN',
            'Clinical Baseline Symptom & Distress Intake', 1,
            (float)$c['age'], (string)$c['age'],
            'IBS-SSS and B1-B2, H1-H2, F1-F2, R1-R2 symptom distress questionnaire assessment.'
        ]);
        $stSrcRec->execute([
            'BGA_IBS_DISTRESS_CLIN', 'clinical_symptom_data', (string)$c['id'] . ':' . $localId, $pUid, $vClinUid, json_encode($c, JSON_UNESCAPED_SLASHES)
        ]);
        foreach ($clinCols as $dbCol => $mCode) {
            $rawVal = $c[$dbCol];
            $isMiss = ($rawVal === null || $rawVal === '') ? 1 : 0;
            $numVal = $isMiss ? null : (float)$rawVal;
            $unit = ($mCode === 'IBS_SSS') ? 'Points (0-500)' : 'Scale Points';
            $qFlag = ($mCode === 'IBS_SSS' && $numVal === 0.0) ? 'TRUE_ZERO_HC_SCORE' : null;
            $stMeas->execute([
                $pUid, $vClinUid, 'BGA_IBS_DISTRESS_CLIN', $mCode,
                $isMiss ? null : (string)$rawVal, $numVal, $numVal, null, $unit, $isMiss, $qFlag, null
            ]);
        }
    }
}
echo "[OK] Populated BERGEN_BGA participants (" . count($allBgaIds) . "): verified={$bgaCounts['verified']}, visit_offset={$bgaCounts['offset']}, unresolved={$bgaCounts['unresolved']}, single_modality={$bgaCounts['single']}\n";

// 6. Populate MARS_GSE146853 RNA-seq Metadata & GC-MS Biopsy Metabolomics & NMR Stool Metabolomics
$gcmsFile = $baseDir . '/data/Mars et al, IBS multi-omics/Supplementary data III source data GC-MS biopsy.xlsx';
$gcmsTable = parseXlsxSheet($gcmsFile);
$gcmsBySampleTitle = [];
$gcmsByPatient = [];

if (count($gcmsTable) > 1) {
    for ($i = 1; $i < count($gcmsTable); $i++) {
        $r = $gcmsTable[$i];
        $rawCohort = trim($r[0] ?? '');
        $rawPid = trim($r[1] ?? '');
        $visitNum = (int)trim($r[2] ?? '1');
        $shortSamp = trim($r[3] ?? '');
        // Strip trailing -1 or -2 from Patient ID column (e.g. '10007541-2' -> '10007541')
        $basePid = preg_replace('/-[12]$/', '', $rawPid);
        if ($basePid === '' && $shortSamp !== '') {
            $parts = explode('-', $shortSamp);
            $basePid = '10007' . preg_replace('/[^0-9]/', '', $parts[0]);
        }
        // Check if short_sample_id conflicts with Patient ID (e.g. Row 12: PID=10007589, Sample=554_unknown)
        $isAmbiguousSample = str_contains(strtolower($shortSamp), 'unknown');
        $fullSampleTitle = $isAmbiguousSample ? ($basePid . '-' . $visitNum . '-UNK') : ($basePid . '-' . $visitNum);
        $entry = [
            'excel_row_num' => $i + 1,
            'raw_cohort' => $rawCohort,
            'raw_patient_id_col' => $rawPid,
            'patient_id' => $basePid,
            'visit_number' => $visitNum,
            'short_sample_id' => $shortSamp,
            'full_sample_title' => $fullSampleTitle,
            'is_ambiguous' => $isAmbiguousSample,
            'acetate' => is_numeric($r[4] ?? null) ? (float)$r[4] : null,
            'propionate' => is_numeric($r[5] ?? null) ? (float)$r[5] : null,
            'iso_butyrate' => is_numeric($r[6] ?? null) ? (float)$r[6] : null,
            'butyrate' => is_numeric($r[7] ?? null) ? (float)$r[7] : null,
            'two_methyl_butyrate' => is_numeric($r[8] ?? null) ? (float)$r[8] : null,
            'iso_valerate' => is_numeric($r[9] ?? null) ? (float)$r[9] : null,
            'valerate' => is_numeric($r[10] ?? null) ? (float)$r[10] : null,
            'caproate' => is_numeric($r[11] ?? null) ? (float)$r[11] : null,
            'lactate' => is_numeric($r[12] ?? null) ? (float)$r[12] : null,
            'two_oh_butyrate' => is_numeric($r[13] ?? null) ? (float)$r[13] : null,
        ];
        $gcmsBySampleTitle[$fullSampleTitle] = $entry;
        if (!$isAmbiguousSample) {
            $gcmsByPatient[$basePid][$visitNum] = $entry;
        } else {
            $gcmsByPatient[$basePid][99] = $entry; // Keep ambiguous row separate from verified visit 1
        }
    }
}

// Read GSE146853_sample_metadata.csv directly so we see true empty strings vs MySQL 0.00
$rnaCsvPath = $baseDir . '/data/GSE146853_sample_metadata.csv';
$rnaRows = [];
$rnaBySampleTitle = [];
$rnaByPatient = [];
if (file_exists($rnaCsvPath)) {
    $h = fopen($rnaCsvPath, 'r');
    $hdr = fgetcsv($h);
    $lineNum = 1;
    while (($r = fgetcsv($h)) !== false) {
        $lineNum++;
        if (count($r) < 6 || $r[0] === 'sample_title') continue;
        $sTitle = trim($r[0]);
        $geoAcc = trim($r[1]);
        $rawAge = trim($r[2]);
        $rawBmi = trim($r[3]);
        $rawGen = trim($r[4]);
        $rawCoh = trim($r[5]);
        $rawPid = trim($r[6] ?? '');
        $rawTp  = trim($r[7] ?? '');

        $parts = explode('-', $sTitle);
        $derivedPid = $rawPid !== '' ? $rawPid : $parts[0];
        $visitNum = isset($parts[1]) ? (int)$parts[1] : 1;
        $derivedTp = $rawTp !== '' ? $rawTp : ('T' . $visitNum);

        $item = [
            'csv_line' => $lineNum,
            'sample_title' => $sTitle,
            'geo_accession' => $geoAcc,
            'raw_age' => $rawAge,
            'age' => ($rawAge !== '' && is_numeric($rawAge)) ? (float)$rawAge : null,
            'raw_bmi' => $rawBmi,
            'bmi' => ($rawBmi !== '' && is_numeric($rawBmi)) ? (float)$rawBmi : null,
            'raw_gender' => $rawGen,
            'gender' => in_array($rawGen, ['F', 'M'], true) ? $rawGen : 'Missing',
            'raw_cohort' => $rawCoh,
            'raw_patientid' => $rawPid,
            'patient_id' => $derivedPid,
            'raw_timepoint' => $rawTp,
            'timepoint' => $derivedTp,
            'visit_number' => $visitNum
        ];
        $rnaBySampleTitle[$sTitle] = $item;
        $rnaByPatient[$derivedPid][$visitNum] = $item;
    }
    fclose($h);
}

$allMarsPids = array_unique(array_merge(array_keys($rnaByPatient), array_keys($gcmsByPatient)));
sort($allMarsPids);

// Clean any stale suffixed MARS participant rows from earlier runs
$db->exec("DELETE FROM prov_participants WHERE study_code = 'MARS_GSE146853' AND local_participant_id LIKE '%-2'");
$db->exec("DELETE FROM prov_visits WHERE study_code = 'MARS_GSE146853' AND participant_uid LIKE '%-2'");
$db->exec("DELETE FROM prov_measurements WHERE dataset_code = 'MARS_GCMS_BIOPSY'");
$db->exec("DELETE FROM prov_participant_links WHERE source_dataset_code = 'GSE146853_RNASEQ_META' AND target_dataset_code = 'MARS_GCMS_BIOPSY'");

$stGcms = $db->prepare("INSERT INTO mars_gcms_biopsy_metabolites (
    excel_row_num, raw_cohort, patient_id, visit_number, short_sample_id, full_sample_title,
    participant_uid, visit_uid, matched_rnaseq_geo, acetate, propionate, iso_butyrate,
    butyrate, two_methyl_butyrate, iso_valerate, valerate, caproate, lactate, two_oh_butyrate
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
    matched_rnaseq_geo=VALUES(matched_rnaseq_geo), acetate=VALUES(acetate),
    propionate=VALUES(propionate), butyrate=VALUES(butyrate)");

$marsMatchedBiopsies = 0;

foreach ($allMarsPids as $pid) {
    $pUid = "MARS_GSE146853:$pid";
    $rVisits = $rnaByPatient[$pid] ?? [];
    $gVisits = $gcmsByPatient[$pid] ?? [];

    $firstR = reset($rVisits) ?: null;
    $firstG = reset($gVisits) ?: null;

    $rawCoh = $firstR ? $firstR['raw_cohort'] : ($firstG ? $firstG['raw_cohort'] : '');
    if ($rawCoh === 'Diarrheaiarrhea') {
        $harmCoh = 'IBS-D';
        $subSpec = 'subtype_specific';
    } elseif ($rawCoh === 'Diarrhea' || $rawCoh === 'IBS-D') {
        $harmCoh = 'IBS-D';
        $subSpec = 'subtype_specific';
    } elseif ($rawCoh === 'Constipation' || $rawCoh === 'IBS-C') {
        $harmCoh = 'IBS-C';
        $subSpec = 'subtype_specific';
    } elseif ($rawCoh === 'Healthy') {
        $harmCoh = 'HC';
        $subSpec = 'healthy_control';
    } else {
        // Check if GC-MS provides the cohort when RNA-seq metadata is blank (e.g., 10007600-1)
        $gCoh = $firstG['raw_cohort'] ?? '';
        if ($gCoh === 'IBS-C') {
            $harmCoh = 'IBS-C';
            $subSpec = 'subtype_specific';
        } elseif ($gCoh === 'IBS-D') {
            $harmCoh = 'IBS-D';
            $subSpec = 'subtype_specific';
        } elseif ($gCoh === 'Healthy') {
            $harmCoh = 'HC';
            $subSpec = 'healthy_control';
        } else {
            $harmCoh = 'Unknown';
            $subSpec = 'unknown_or_missing';
        }
    }

    $rawSex = $firstR ? $firstR['raw_gender'] : '';
    $harmSex = in_array($rawSex, ['F', 'M'], true) ? $rawSex : 'Missing';
    $ageVal = $firstR ? $firstR['age'] : null;
    $bmiVal = $firstR ? $firstR['bmi'] : null;

    $verStatus = ($rVisits && $gVisits) ? 'verified' : 'single_table_only';
    $notes = [];
    if ($rVisits) $notes[] = "RNA-seq biopsies: " . implode(', ', array_map(fn($x) => $x['sample_title'] . " ({$x['geo_accession']})", $rVisits));
    if ($gVisits) $notes[] = "GC-MS biopsies: " . implode(', ', array_map(fn($x) => $x['full_sample_title'], $gVisits));
    if ($rawCoh === 'Diarrheaiarrhea') {
        $notes[] = "DQ Flag: Raw GEO metadata contains typo 'Diarrheaiarrhea'; harmonized to 'IBS-D' while preserving original value.";
    }
    if ($pid === '10007600') {
        $notes[] = "DQ Flag: GSM4914057 (10007600-1) has missing cohort/age/bmi/gender in GEO metadata (which MySQL decimal import turned into 0.00); Mars et al. Supplementary Data III GC-MS table confirms cohort is IBS-C.";
    }

    $stPart->execute([
        $pUid, 'MARS_GSE146853', $pid,
        $rawCoh ?: ($firstG['raw_cohort'] ?? 'Missing'),
        $harmCoh, $subSpec, $rawSex ?: 'Missing', $harmSex,
        $ageVal !== null ? (string)$ageVal : 'Missing',
        $ageVal, $bmiVal, null, $verStatus, implode(' | ', $notes)
    ]);

    $visitNums = array_unique(array_merge(array_keys($rVisits), array_keys($gVisits)));
    sort($visitNums);

    foreach ($visitNums as $vNum) {
        $tpCode = 'T' . $vNum;
        $vUid = "$pUid:$tpCode";
        $rv = $rVisits[$vNum] ?? null;
        $gv = $gVisits[$vNum] ?? null;

        $stVisit->execute([
            $vUid, $pUid, 'MARS_GSE146853', $tpCode,
            "Colonic Mucosal Biopsy Visit $vNum ($tpCode)", $vNum,
            $rv ? $rv['age'] : $ageVal,
            $rv ? $rv['raw_age'] : null,
            ($rv && $gv) ? "Matched same-biopsy RNA-seq ({$rv['geo_accession']}) and GC-MS SCFA metabolomics ({$gv['short_sample_id']})."
                         : ($rv ? "RNA-seq mucosal biopsy ({$rv['geo_accession']})." : "GC-MS mucosal biopsy ({$gv['short_sample_id']}).")
        ]);

        if ($rv) {
            $stSrcRec->execute([
                'GSE146853_RNASEQ_META', 'data/GSE146853_sample_metadata.csv',
                $rv['sample_title'] . ':' . $rv['geo_accession'], $pUid, $vUid, json_encode($rv, JSON_UNESCAPED_SLASHES)
            ]);
        }

        if ($gv) {
            $matchedGeo = $rv ? $rv['geo_accession'] : null;
            $stGcms->execute([
                $gv['excel_row_num'], $gv['raw_cohort'], $gv['patient_id'], $gv['visit_number'],
                $gv['short_sample_id'], $gv['full_sample_title'], $pUid, $vUid, $matchedGeo,
                $gv['acetate'], $gv['propionate'], $gv['iso_butyrate'], $gv['butyrate'],
                $gv['two_methyl_butyrate'], $gv['iso_valerate'], $gv['valerate'],
                $gv['caproate'], $gv['lactate'], $gv['two_oh_butyrate']
            ]);
            $stSrcRec->execute([
                'MARS_GCMS_BIOPSY', 'Supplementary data III source data GC-MS biopsy.xlsx',
                'Row_' . $gv['excel_row_num'] . ':' . $gv['full_sample_title'], $pUid, $vUid, json_encode($gv, JSON_UNESCAPED_SLASHES)
            ]);

            foreach (['acetate' => 'gcms_acetate', 'propionate' => 'gcms_propionate', 'butyrate' => 'gcms_butyrate', 'lactate' => 'gcms_lactate'] as $gKey => $mCode) {
                $val = $gv[$gKey];
                $stMeas->execute([
                    $pUid, $vUid, 'MARS_GCMS_BIOPSY', $mCode,
                    $val !== null ? (string)$val : null, $val, $val, null,
                    'Normalized Peak Area', $val === null ? 1 : 0, null, null
                ]);
            }
        }

        if ($rv && $gv) {
            $marsMatchedBiopsies++;
            $stLink->execute([
                $pUid, 'GSE146853_RNASEQ_META', $rv['sample_title'],
                $pUid, 'MARS_GCMS_BIOPSY', $gv['full_sample_title'],
                1, 'verified', 0,
                "Exact Patient ID ($pid), Visit ($tpCode), and Sample Title ({$rv['sample_title']})",
                "Verified same-study, same-visit colonic mucosal biopsy in Mars et al. (GSE146853): RNA-seq accession {$rv['geo_accession']} and GC-MS biopsy sample {$gv['short_sample_id']}."
            ]);
        }
    }
}
echo "[OK] Populated MARS_GSE146853 participants (" . count($allMarsPids) . ") and GC-MS biopsy metabolomics (" . count($gcmsBySampleTitle) . " samples, $marsMatchedBiopsies exact RNA-seq visit matches)\n";

// 7. Populate Mars et al. NMR Stool Metabolomics (464 rows across 76 tube IDs)
$nmrFile = $baseDir . '/data/Mars et al, IBS multi-omics/Supplementary data II source data NMR metabolomics.xlsx';
$nmrTable = parseXlsxSheet($nmrFile);
$stNmr = $db->prepare("INSERT INTO mars_nmr_stool_metabolites (
    matlab_no, id_on_tube, time_point, gender, gender_code, diagnosis, group_code, participant_uid,
    two_methylbutyrate, lactate, alanine, tyrosine, isoleucine, leucine, valine, lysine,
    succinate, glycine, b_arabinose, b_xylose, acetate, propionate, butyrate, glucose,
    isovalerate, uracil, hypoxanthine
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
    diagnosis=VALUES(diagnosis), propionate=VALUES(propionate), butyrate=VALUES(butyrate)");

$nmrRowCount = 0;
if (count($nmrTable) > 1) {
    for ($i = 1; $i < count($nmrTable); $i++) {
        $r = $nmrTable[$i];
        $matlabNo = (int)($r[0] ?? $i);
        $tubeId = trim((string)($r[1] ?? ''));
        $tp = (int)($r[2] ?? 0);
        $gen = trim((string)($r[3] ?? ''));
        $genCode = (int)($r[4] ?? 0);
        $diag = trim((string)($r[5] ?? ''));
        $grpCode = (int)($r[6] ?? 0);
        $pUid = "MARS_NMR_TUBE:Tube_$tubeId";

        $vals = [];
        for ($c = 7; $c <= 25; $c++) {
            $vals[] = isset($r[$c]) && is_numeric($r[$c]) ? (float)$r[$c] : null;
        }
        $stNmr->execute(array_merge([
            $matlabNo, $tubeId, $tp, $gen, $genCode, $diag, $grpCode, $pUid
        ], $vals));
        $nmrRowCount++;
    }
}
echo "[OK] Populated mars_nmr_stool_metabolites ($nmrRowCount longitudinal stool NMR samples)\n";

// Record explicit cross-study REJECTED linkage rules so cross-cohort joins are structurally documented as forbidden
$stLink->execute([
    'BERGEN_BGA:ALL_PARTICIPANTS', 'BGA_PSN_IBS_MRI', 'BGA_*',
    'MARS_GSE146853:ALL_PARTICIPANTS', 'GSE146853_RNASEQ_META', '10007*',
    0, 'rejected', 1,
    'Incompatible Studies & Institutions (Norway Bergen BGA vs USA Mayo Clinic GSE146853)',
    'NON-NEGOTIABLE SCIENTIFIC RULE: Bergen Brain-Gut (BGA_*) and Mars et al. (GSE146853, 10007*) are completely independent cohorts from different studies and countries. Cross-cohort participant correlation is scientifically invalid and strictly blocked.'
]);
$stLink->execute([
    'MARS_NMR_TUBE:ALL_TUBES', 'MARS_NMR_STOOL', 'ID_on_tube (1..77)',
    'MARS_GSE146853:ALL_PARTICIPANTS', 'GSE146853_RNASEQ_META', '10007*',
    1, 'unresolved', 1,
    'Same study (Mars et al. 2020) but unmapped anonymized ID_on_tube vs 10007xxx patient_id',
    'Mars et al. Supplementary Data II (NMR stool) uses anonymized tube numbers (1–77) without a published lookup table to the 10007xxx biopsy patient IDs. Excluded from participant-level matched analysis.'
]);
$stLink->execute([
    'SIMULA_KVASIR_SEG:ALL_IMAGES', 'KVASIR_SEG_LOCAL_1000', 'cju* / UUID',
    'BERGEN_BGA:ALL_PARTICIPANTS', 'BGA_PSN_IBS_MRI', 'BGA_*',
    0, 'rejected', 1,
    'Incompatible Benchmark Reference Dataset vs Clinical Cohort',
    'HyperKvasir / Kvasir-SEG is a general gastrointestinal endoscopy / colorectal polyp dataset (Simula Research Lab), NOT an IBS cohort and NOT linked to any study participant.'
]);

// 8. Populate Local Kvasir-SEG 1,000 Reference Assets from kavsir_bboxes.json + images/masks/
$bboxPath = $baseDir . '/data/kvasir-temp/Kvasir-SEG/kavsir_bboxes.json';
if (file_exists($bboxPath)) {
    $bboxes = json_decode((string)file_get_contents($bboxPath), true);
    if (is_array($bboxes)) {
        $stKv = $db->prepare("INSERT INTO kvasir_seg_local_assets (
            image_id, image_width, image_height, bbox_count, bbox_json, local_mask_path, has_local_mask
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            image_width=VALUES(image_width), image_height=VALUES(image_height),
            bbox_count=VALUES(bbox_count), local_mask_path=VALUES(local_mask_path), has_local_mask=VALUES(has_local_mask)");
        $kvCount = 0;
        foreach ($bboxes as $imgId => $meta) {
            $w = (int)($meta['width'] ?? 0);
            $h = (int)($meta['height'] ?? 0);
            $boxArr = $meta['bbox'] ?? [];
            $maskRel = "images/masks/{$imgId}.jpg";
            $hasMask = file_exists($baseDir . '/' . $maskRel) ? 1 : 0;
            $stKv->execute([
                (string)$imgId, $w, $h, count($boxArr),
                json_encode($boxArr, JSON_UNESCAPED_SLASHES),
                $hasMask ? $maskRel : null, $hasMask
            ]);
            $kvCount++;
        }
        echo "[OK] Populated kvasir_seg_local_assets ($kvCount local segmentation masks & bounding box records)\n";
    }
}

// 9. Populate prov_data_quality_issues (16 Audited Issues)
$dqIssues = [
    [
        'DQ-BGA-SEX-072', 'BERGEN_BGA', 'BGA_PSN_IBS_MRI / BGA_IBS_DISTRESS_CLIN', 'BERGEN_BGA:BGA_072', 'BGA_072',
        'critical', 'conflicting_demographics', 'Gender / sex',
        "brain_measurements.Gender = 'M' vs clinical_symptom_data.sex = 'F' (Age=42, Group=IBS, IBS_SSS=270 in both)",
        'Conflicting sex code across the two Bergen BGA tables for participant BGA_072.',
        'Preserve both original values untouched. Mark participant linkage as unresolved and exclude BGA_072 by default from matched Brain-Symptom analyses. Display records separately in Participant Explorer.',
        1, 1
    ],
    [
        'DQ-BGA-SEX-057-AUDIT', 'BERGEN_BGA', 'BGA_PSN_IBS_MRI / BGA_IBS_DISTRESS_CLIN', 'BERGEN_BGA:BGA_057', 'BGA_057',
        'info', 'conflicting_demographics', 'Gender / sex (Historical Audit Check)',
        "brain_measurements.Gender = 'F' and clinical_symptom_data.sex = 'F' in current MySQL tables (Age=30, Group=IBS, IBS_SSS=264)",
        'Reported in specification as a known sex discrepancy candidate; inspection of current MySQL tables shows both store F (whereas BGA_072 has M vs F). Original raw CSV files for BGA are absent from data/, so prior in-place reconciliation of BGA_057 cannot be ruled out.',
        'Document audit finding transparently on BGA_057 and in the Data Quality Registry; flag missing raw BGA CSV source files.',
        1, 0
    ],
    [
        'DQ-BGA-AGE-070', 'BERGEN_BGA', 'BGA_PSN_IBS_MRI / BGA_IBS_DISTRESS_CLIN', 'BERGEN_BGA:BGA_070', 'BGA_070',
        'critical', 'conflicting_demographics', 'Age / age',
        "brain_measurements.Age = 28 vs clinical_symptom_data.age = 22.00 (Group=HC, Sex=F, IBS_SSS=58 in both)",
        '6-year age discrepancy between MRI table and Clinical table exceeds plausible inter-visit interval and indicates a source transcription error or mismatched subject row.',
        'Preserve both original values. Mark BGA_070 linkage as unresolved and exclude by default from matched analyses.',
        1, 1
    ],
    [
        'DQ-BGA-AGE-097', 'BERGEN_BGA', 'BGA_PSN_IBS_MRI / BGA_IBS_DISTRESS_CLIN', 'BERGEN_BGA:BGA_097', 'BGA_097',
        'warning', 'conflicting_demographics', 'Age / age',
        "brain_measurements.Age = 31 vs clinical_symptom_data.age = 30.00 (Group=IBS, Sex=F, IBS_SSS=305 in both)",
        '1-year age difference between clinical intake questionnaire (30.00) and MRI scan acquisition (31), consistent with birthday passage between baseline clinical enrollment and MRI visit.',
        'Preserve both visit-specific ages (V1_CLIN=30.00, V1_MRI=31). Mark as verified_with_visit_offset and provide sensitivity analysis toggle in Reproducible Analysis.',
        1, 0
    ],
    [
        'DQ-BGA-AGE-128', 'BERGEN_BGA', 'BGA_PSN_IBS_MRI / BGA_IBS_DISTRESS_CLIN', 'BERGEN_BGA:BGA_128', 'BGA_128',
        'warning', 'conflicting_demographics', 'Age / age',
        "brain_measurements.Age = 28 vs clinical_symptom_data.age = 27.00 (Group=IBS, Sex=F, IBS_SSS=231 in both)",
        '1-year age difference between clinical intake (27.00) and MRI scan (28), consistent with inter-visit interval.',
        'Preserve both visit-specific ages (V1_CLIN=27.00, V1_MRI=28). Mark as verified_with_visit_offset and support sensitivity exclusion.',
        1, 0
    ],
    [
        'DQ-BGA-AGE-152', 'BERGEN_BGA', 'BGA_PSN_IBS_MRI / BGA_IBS_DISTRESS_CLIN', 'BERGEN_BGA:BGA_152', 'BGA_152',
        'warning', 'conflicting_demographics', 'Age / age',
        "brain_measurements.Age = 33 vs clinical_symptom_data.age = 32.00 (Group=IBS, Sex=M, IBS_SSS=181 in both)",
        '1-year age difference between clinical intake (32.00) and MRI scan (33), consistent with inter-visit interval.',
        'Preserve both visit-specific ages (V1_CLIN=32.00, V1_MRI=33). Mark as verified_with_visit_offset and support sensitivity exclusion.',
        1, 0
    ],
    [
        'DQ-BGA-AGE-156', 'BERGEN_BGA', 'BGA_PSN_IBS_MRI / BGA_IBS_DISTRESS_CLIN', 'BERGEN_BGA:BGA_156', 'BGA_156',
        'warning', 'conflicting_demographics', 'Age / age',
        "brain_measurements.Age = 29 vs clinical_symptom_data.age = 28.00 (Group=IBS, Sex=F, IBS_SSS=248 in both)",
        '1-year age difference between clinical intake (28.00) and MRI scan (29), consistent with inter-visit interval.',
        'Preserve both visit-specific ages (V1_CLIN=28.00, V1_MRI=29). Mark as verified_with_visit_offset and support sensitivity exclusion.',
        1, 0
    ],
    [
        'DQ-BGA-AGE-083', 'BERGEN_BGA', 'BGA_PSN_IBS_MRI / BGA_IBS_DISTRESS_CLIN', 'BERGEN_BGA:BGA_083', 'BGA_083',
        'info', 'conflicting_demographics', 'Age / age',
        "brain_measurements.Age = 30 vs clinical_symptom_data.age = 30.01 (Group=IBS, Sex=F, IBS_SSS=405 in both)",
        '0.01-year precision difference due to integer truncation in brain_measurements vs decimal age in clinical_symptom_data.',
        'Preserve both raw values and document rounding precision difference.',
        1, 0
    ],
    [
        'DQ-BGA-EDU-NULL', 'BERGEN_BGA', 'BGA_PSN_IBS_MRI', null, '9 HC Subjects (BGA_069..BGA_172)',
        'warning', 'missing_values', 'Education',
        'Education is NULL for 9 Healthy Control subjects (BGA_069, BGA_070, BGA_164, BGA_165, BGA_166, BGA_167, BGA_168, BGA_171, BGA_172)',
        'Missing education metadata in PSN-IBS Healthy Control subset.',
        'Keep NULL as missing (never replace with 0). Automatically exclude missing rows when Education is selected as a covariate and report exact exclusion count.',
        1, 0
    ],
    [
        'DQ-BGA-SSS-ZERO', 'BERGEN_BGA', 'BGA_PSN_IBS_MRI / BGA_IBS_DISTRESS_CLIN', null, 'BGA_076, BGA_090, BGA_125, BGA_130',
        'warning', 'zero_vs_missing_semantics', 'IBS_SSS / ibs_sss',
        'IBS_SSS = 0 for 4 Healthy Controls; previously rendered as N/A in datasets.php due to PHP ?: truthiness operator.',
        'True score of 0 on IBS-SSS (0–500 scale) in asymptomatic Healthy Controls was conflated with NULL/empty string in PHP template.',
        'Fix display logic to check !== null && !== "" so true 0 scores are displayed and analyzed as 0 while NULL remains N/A.',
        1, 0
    ],
    [
        'DQ-GSE-TYPO-559', 'MARS_GSE146853', 'GSE146853_RNASEQ_META', 'MARS_GSE146853:10007559', 'GSM4407876 (10007559-1) & GSM4872062 (10007559-2)',
        'warning', 'cohort_label_typo', 'cohort',
        "cohort = 'Diarrheaiarrhea' for both T1 and T2 biopsies of patient 10007559",
        'String concatenation typo in GSE146853_sample_metadata.csv caused these 2 IBS-D samples to be omitted from cohort=Diarrhea searches.',
        'Preserve raw_cohort_label = "Diarrheaiarrhea" in sample_metadata and prov_source_records, map harmonized_cohort = "IBS-D" in prov_participants, and match both in search filters.',
        1, 0
    ],
    [
        'DQ-GSE-ZERO-600', 'MARS_GSE146853', 'GSE146853_RNASEQ_META', 'MARS_GSE146853:10007600', 'GSM4914057 (10007600-1)',
        'critical', 'missing_values_zero_replaced', 'age, bmi, gender, cohort',
        'CSV has empty fields (,,,,) for GSM4914057, but MySQL sample_metadata stored age=0.00 and bmi=0.00; Mars et al. GC-MS table records Cohort=IBS-C.',
        'Non-strict MySQL DECIMAL import converted empty strings to 0.00 for age and bmi.',
        'Keep original sample_metadata row untouched for auditability; store NULL for age and bmi in prov_participants/prov_visits (never treat 0.00 as a real age/BMI) and document IBS-C cohort from matched GC-MS biopsy 600-1.',
        1, 0
    ],
    [
        'DQ-GSE-MISSING-TP', 'MARS_GSE146853', 'GSE146853_RNASEQ_META', null, 'Rows id=2..47 (46 biopsies)',
        'warning', 'missing_visit_metadata', 'patientid, timepoint',
        '46 of 68 rows in sample_metadata have empty patientid="" and timepoint="" even though sample_title encodes {patientid}-{1|2}.',
        'Incomplete metadata extraction when GSE146853 series matrix was parsed into GSE146853_sample_metadata.csv.',
        'Derive explicit patient_id and T1/T2 visit codes in prov_participants and prov_visits from sample_title suffix (-1 -> T1, -2 -> T2) while preserving raw empty fields in prov_source_records.',
        1, 0
    ],
    [
        'DQ-HEADER-ROWS', 'MARS_GSE146853', 'GSE146853_RNASEQ_META / GSE146853_DESEQ2_ALL / KVASIR_STAGING_1001', null, 'sample_metadata.id=1, gene_results.id=32, hyperkvasir_import_staging.image_id="image_id"',
        'warning', 'header_row_imported', 'All columns',
        'Literal CSV header rows were imported as data rows in sample_metadata (id=1), gene_results (id=32), and hyperkvasir_import_staging (image_id="image_id").',
        'CSV import without IGNORE 1 LINES during initial table creation.',
        'Filter out literal header rows in all queries and count metrics while documenting their presence in the Data Quality Registry.',
        1, 1
    ],
    [
        'DQ-MARS-DOI-CITE', 'MARS_GSE146853', 'GSE146853_RNASEQ_META', null, 'evidence_records.id=1 & about.php',
        'warning', 'citation_discrepancy', 'doi / journal',
        'Existing about.php and evidence_records cited Mars et al. 2021 Nature Communications (10.1038/s41467-021-23720-6) for GSE146853.',
        'Citation mix-up in initial portal text: GSE146853 and Supplementary Data II/III were published by Mars et al. in Cell (2020), 182(6):1460-1473, DOI: 10.1016/j.cell.2020.08.007, PMID: 32916129.',
        'Record accurate Cell (2020) citation and DOI (10.1016/j.cell.2020.08.007) in prov_studies and UI while noting the legacy citation discrepancy.',
        1, 0
    ],
    [
        'DQ-LEGACY-STUDIES-CSV', 'CURATED_REPOSITORIES', 'LEGACY_STUDIES_CSV', null, 'data/studies.csv (3 rows)',
        'critical', 'synthetic_legacy_table', 'neuroimaging, endoscopy',
        'data/studies.csv (used only by search_legacy.php) attributes 7T Structural MRI to PMID 34017001 and HyperKvasir Colonoscopy to PMID 31932487.',
        'Synthetic/composite demonstration CSV created during early prototyping that conflated modalities across unrelated studies.',
        'Quarantine data/studies.csv as synthetic_or_composite_legacy in prov_datasets; exclude from search.php and evidence explorer.',
        1, 1
    ],
    [
        'DQ-GCMS-ROW12-554UNK', 'MARS_GSE146853', 'MARS_GCMS_BIOPSY', 'MARS_GSE146853:10007589', 'Excel Row 12 (PID=10007589, Sample=554_unknown)',
        'warning', 'conflicting_sample_label', 'Patient ID vs Sample',
        "Supplementary data III GC-MS Row 12 has Patient ID='10007589', Visit='1', Sample='554_unknown' (while Row 38 has PID='10007589', Visit='1', Sample='589-1 R'). Also Visit 2 rows append '-2' to Patient ID column (e.g. '10007541-2'), and Row 63 has PID='10007616-2' for Visit 1 ('616-1').",
        'Source spreadsheet labeling inconsistency in Mars et al. Supplementary Data III.',
        'Isolate Row 12 (554_unknown) as an unresolved visit (T99) so it does not overwrite verified biopsy 589-1 (Row 38); strip trailing -[12] from Patient ID column to recover canonical 8-digit patient ID (10007xxx) while preserving raw Patient ID column in source_records.',
        1, 1
    ]
];

$stDq = $db->prepare("INSERT INTO prov_data_quality_issues (
    issue_code, study_code, dataset_code, participant_uid, record_identifier,
    severity, category, field_name, observed_values, root_cause, resolution_policy,
    original_preserved, excluded_by_default
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
    observed_values=VALUES(observed_values), root_cause=VALUES(root_cause),
    resolution_policy=VALUES(resolution_policy)");

foreach ($dqIssues as $dqi) {
    $stDq->execute($dqi);
}
echo "[OK] Populated prov_data_quality_issues (" . count($dqIssues) . " documented issues)\n";

// 10. Populate prov_evidence_edges (Phase 5 — Curated, Source-Traceable Relationships across all 5 categories)
$evidenceEdges = [
    [
        'EV-01-GDNF-IBSD',
        'Subtype', 'IBS-D', 'IBS - Diarrhea Predominant (IBS-D)',
        'Gene', 'ENSG00000168621', 'GDNF (Glial Cell Derived Neurotrophic Factor)',
        'downregulated_in_mucosa',
        'same_cohort_measured', 'Same-cohort measured association',
        'MARS_GSE146853', 'GSE146853 / DESeq2 (PMID:32916129)',
        'NeuroGI_Atlas_annotated_genes.csv: ENSG00000168621 (GDNF), baseMean=156.17, log2FoldChange=-0.9222, lfcSE=0.2195, Wald stat=-4.2009, pvalue=2.66e-5, BH padj=0.0451 (Raw mean counts: Healthy=178.1 vs IBS-D=119.7 across 68 biopsies).',
        'Adults with Rome III IBS-D (n=25 biopsies) vs Healthy Controls (n=21 biopsies)',
        'Sigmoid colon mucosal biopsy (RNA-seq)',
        'Negative (Lower in IBS-D)',
        'log2FC = -0.922 ± 0.220 (FDR padj = 0.0451)',
        'Direct DESeq2 Wald test on GSE146853 filtered count matrix',
        'computed_from_matched_data',
        'Bulk mucosal biopsy RNA-seq reflects mixed epithelial, stromal, and enteric glial cell populations; longitudinal biopsies (T1/T2) from 42 subjects were modeled at sample level without random subject intercept in the provided DESeq2 table.'
    ],
    [
        'EV-02-OTOP2-IBSD',
        'Subtype', 'IBS-D', 'IBS - Diarrhea Predominant (IBS-D)',
        'Gene', 'ENSG00000183034', 'OTOP2 (Otopetrin 2 Proton Channel)',
        'upregulated_in_mucosa',
        'same_cohort_measured', 'Same-cohort measured association',
        'MARS_GSE146853', 'GSE146853 / DESeq2 (PMID:32916129)',
        'NeuroGI_Atlas_annotated_genes.csv: ENSG00000183034 (OTOP2), baseMean=2629.41, log2FoldChange=+0.8346, lfcSE=0.1673, Wald stat=+4.9873, pvalue=6.12e-7, BH padj=0.00772.',
        'Adults with Rome III IBS-D (n=25 biopsies) vs Healthy Controls (n=21 biopsies)',
        'Sigmoid colon mucosal biopsy (RNA-seq)',
        'Positive (Higher in IBS-D)',
        'log2FC = +0.835 ± 0.167 (FDR padj = 0.0077)',
        'Direct DESeq2 Wald test on GSE146853 filtered count matrix',
        'computed_from_matched_data',
        'Unnormalized raw count means show smaller separation than DESeq2 size-factor-normalized fold change due to library size differences across biopsies.'
    ],
    [
        'EV-03-MST1L-IBSD',
        'Subtype', 'IBS-D', 'IBS - Diarrhea Predominant (IBS-D)',
        'Gene', 'ENSG00000186715', 'MST1L (Macrophage Stimulating 1 Like)',
        'downregulated_in_mucosa',
        'same_cohort_measured', 'Same-cohort measured association',
        'MARS_GSE146853', 'GSE146853 / DESeq2 (PMID:32916129)',
        'NeuroGI_Atlas_annotated_genes.csv: ENSG00000186715 (MST1L), baseMean=2895.87, log2FoldChange=-1.7049, lfcSE=0.2990, Wald stat=-5.7016, pvalue=1.19e-8, BH padj=0.000299 (Raw mean counts: Healthy=5142.9 vs IBS-D=1464.3).',
        'Adults with Rome III IBS-D (n=25 biopsies) vs Healthy Controls (n=21 biopsies)',
        'Sigmoid colon mucosal biopsy (RNA-seq)',
        'Negative (Lower in IBS-D)',
        'log2FC = -1.705 ± 0.299 (FDR padj = 2.99e-4)',
        'Direct DESeq2 Wald test on GSE146853 filtered count matrix',
        'computed_from_matched_data',
        'MST1L is an annotated pseudogene with sequence homology to MST1 (which is also downregulated, log2FC = -1.069, padj = 0.0442); short-read multi-mapping between MST1 and MST1L can affect quantification.'
    ],
    [
        'EV-04-GCMS-PROPIONATE-RNA',
        'Subtype', 'IBS-C', 'IBS - Constipation Predominant (IBS-C)',
        'Metabolite', 'gcms_propionate', 'Mucosal Biopsy Propionate & Butyrate (GC-MS)',
        'measured_in_matched_biopsies',
        'same_cohort_measured', 'Same-cohort measured association',
        'MARS_GSE146853', 'Mars et al. 2020 Supp Data III (PMID:32916129)',
        'Supplementary data III source data GC-MS biopsy.xlsx: 74 mucosal biopsies quantified for 10 SCFAs/organic acids (acetate, propionate, butyrate, etc.), with 54 biopsies matched 1:1 by patient_id (10007xxx) and visit (T1/T2) to GSE146853 RNA-seq.',
        'Mars et al. longitudinal cohort (n=74 biopsies across 42 subjects; 54 paired with RNA-seq)',
        'Colonic mucosal biopsy (GC-MS)',
        'Altered SCFA profile across IBS subtypes',
        'n = 54 verified same-visit biopsy pairs (IBS-C mean propionate = 20.3 vs IBS-D = 30.8 vs HC = 25.1)',
        '1:1 study-scoped participant and visit matching (MARS_GSE146853)',
        'computed_from_matched_data',
        'Only 54 of the 68 RNA-seq biopsies have a corresponding row in the GC-MS biopsy dataset; NMR stool samples (ID_on_tube 1–77) cannot be linked at the participant level.'
    ],
    [
        'EV-05-BGA-THALAMUS-IBSSSS',
        'BrainRegion', 'Left-Thalamus', 'Left Thalamus Volume (eTIV-Normalized)',
        'Symptom', 'IBS_SSS', 'IBS Severity Scoring System (IBS-SSS)',
        'evaluated_for_association',
        'contradictory_or_inconclusive', 'Contradictory or inconclusive evidence',
        'BERGEN_BGA', 'Bergen BGA Verified Matched Analysis (n=59)',
        'Direct computation on n=59 verified matched Bergen BGA participants (excluding unresolved BGA_070 and BGA_072): Left-Thalamus (eTIV proportion) vs IBS-SSS yields weak, statistically non-significant linear correlation (r = -0.057, p = 0.669 across all n=59; r = -0.118, p = 0.457 within IBS n=42).',
        'Bergen Brain-Gut Cohort (n=59 verified matched: 42 IBS, 17 HC)',
        '3T Brain MRI (FreeSurfer) & Clinical Questionnaire',
        'Null / Inconclusive in cross-sectional linear test',
        'Pearson r = -0.057 (95% CI [-0.309, 0.202], p = 0.669, n = 59)',
        'Reproducible statistical computation on verified BGA participants',
        'computed_from_matched_data',
        'Cross-sectional volumetric proportions alone do not capture functional thalamocortical hyperconnectivity; illustrates why empirical null results must be reported transparently rather than overstated.'
    ],
    [
        'EV-06-BGA-RBANS-DISTRESS',
        'CognitiveMeasure', 'Fullscale_RBANS', 'RBANS Full-Scale Cognitive Index',
        'Symptom', 'IBS_SSS', 'IBS Symptom Severity (IBS-SSS)',
        'inverse_cohort_trend',
        'same_cohort_measured', 'Same-cohort measured association',
        'BERGEN_BGA', 'Bergen BGA Verified Matched Analysis (n=59)',
        'In the verified Bergen BGA cohort (n=59 matched participants; n=78 total MRI), IBS patients show lower mean Fullscale RBANS score (mean = 92.0 in IBS vs 102.9 in HC) and a modest negative association between IBS-SSS and Fullscale RBANS across the combined cohort (r = -0.354, p = 0.0059, n = 59), though attenuated within the IBS-only subgroup (r = -0.068, p = 0.669, n = 42).',
        'Bergen Brain-Gut Cohort (n=59 verified matched: 42 IBS, 17 HC)',
        'RBANS Neuropsychological Battery & IBS-SSS',
        'Negative across combined cohort (driven by HC vs IBS group difference)',
        'Combined r = -0.354 (95% CI [-0.559, -0.109], p = 0.0059); IBS-only r = -0.068 (p = 0.669)',
        'Reproducible statistical computation with subgroup sensitivity check',
        'computed_from_matched_data',
        'Association across combined HC+IBS is primarily driven by between-group shift (Simpson/group-confounding effect) and missing Education values in 9 HC subjects; within-IBS correlation is non-significant.'
    ],
    [
        'EV-07-GO-STEMCELL-GDNF',
        'Gene', 'ENSG00000168621', 'GDNF / PTN / RBM24',
        'Pathway', 'GO:2000736', 'GO:2000736 Regulation of Stem Cell Differentiation',
        'over_represented_in_go_ora',
        'computational_hypothesis', 'Computational hypothesis',
        'MARS_GSE146853', 'NeuroGI_Atlas_corrected_GO_enrichment.csv',
        'clusterProfiler Over-Representation Analysis: GO:2000736 (regulation of stem cell differentiation), GeneRatio=3/11 (GDNF/PTN/RBM24), BgRatio=63/14893, FoldEnrichment=64.47, pvalue=1.16e-5, BH p.adjust=0.00532.',
        'GSE146853 significant DE gene set (11 mapped symbols out of 15 FDR-significant genes)',
        'Computational Gene Ontology ORA (Background N=14,893 expressed colonic genes)',
        'Depleted in IBS-D (all 3 leading genes downregulated)',
        'Fold Enrichment = 64.5x (BH p.adjust = 0.0053, q = 0.0025)',
        'clusterProfiler hypergeometric test over expressed gene universe',
        'flagged_limitation',
        'Driven by only 3 genes out of 11 mapped foreground genes. Companion enriched terms (GO:0044849 estrous cycle, GO:0042698 ovulation cycle, driven by HAS2/PTN) reflect pleiotropic GO annotations rather than colonic processes.'
    ],
    [
        'EV-08-LIT-SEROTONIN-MUCOSA',
        'Metabolite', 'SEROTONIN_5HT', 'Enteric Serotonin (5-HT) Signaling',
        'Disease', 'IBS', 'Intestinal Mucosal Inflammation & Gut-Brain Signaling',
        'modulates_mucosal_chemokines',
        'cross_study_literature', 'Cross-study literature-supported relationship',
        'CURATED_REPOSITORIES', 'GEO:GSE269175 / PubMed:28164854',
        'GSE269175 summary: "Using a human 3D in vitro model, we investigated the effect of serotonin, a neuromodulator produced in the gut, on immune cell and intestinal tissue interactions. Our findings revealed that serotonin attenuates the tumor-necrosis-factor-alpha-induced pro-inflammatory response, mostly by affecting the expression of chemokines."',
        'Human 3D intestinal mucosal tissue model (GSE269175, n=14 samples) & clinical review (PMID:28164854)',
        'Human intestinal mucosal model (RNA-seq)',
        'Attenuates TNF-alpha-induced chemokine response',
        'Qualitative literature/GEO evidence (GSE269175)',
        'Manual curation from NCBI GEO candidate record GSE269175 and PubMed record 28164854',
        'verified_curated',
        'Ex vivo 3D tissue model (GSE269175) rather than in vivo IBS patient biopsies; provides mechanistic context rather than matched participant evidence.'
    ],
    [
        'EV-09-TRIAL-ELUXADOLINE-IBSD',
        'Intervention', 'ELUXADOLINE', 'Eluxadoline (Mixed Opioid Receptor Modulator)',
        'Subtype', 'IBS-D', 'IBS - Diarrhea Predominant (IBS-D)',
        'evaluated_in_phase3_rct',
        'curated_contextual', 'Curated contextual link',
        'CURATED_REPOSITORIES', 'NCT01553591 (ClinicalTrials.gov)',
        'clinical_trials.csv (NCT01553591): "Efficacy, Safety, and Tolerability of Eluxadoline in the Treatment of Participants With Diarrhea-Predominant Irritable Bowel Syndrome (IBS-d)", Phase 3 Interventional Study, Enrollment=1282, Status at retrieval=COMPLETED.',
        '1,282 adults with Rome III IBS-D (Furiex Pharmaceuticals Phase 3 trial)',
        'Interventional Clinical Trial (Registry Record)',
        'Interventional trial completed',
        'Enrollment N = 1,282 (Phase 3 RCT)',
        'Curated from ClinicalTrials.gov registry record NCT01553591',
        'verified_curated',
        'Registry status COMPLETED indicates trial completion at last retrieval, not patient-level efficacy data within this database.'
    ],
    [
        'EV-10-LIT-MICROBIOME-DIVERSITY',
        'Disease', 'IBS', 'Gut-Brain Axis Disorders & Comorbid Mood Distress',
        'Pathway', 'MICROBIAL_ALPHA_BETA_DIVERSITY', 'Gut Microbial Alpha/Beta Diversity & SCFA Taxa',
        'heterogeneous_across_cohorts',
        'contradictory_or_inconclusive', 'Contradictory or inconclusive evidence',
        'CURATED_REPOSITORIES', 'PMID:33271426 & PMID:40312666',
        'PubMed PMID 33271426 & PMID 40312666: "Our results showed that the findings of the alpha- and beta-diversity assessments were inconsistent... there were conflicting reports on the abundance of bacteria due to confounders such as diet and psychotropic medications."',
        'Systematic reviews of 26 and 24 human case-control studies',
        'Stool 16S rRNA / Metagenomic Systematic Reviews',
        'Inconsistent alpha/beta diversity; lower SCFA-producers (Faecalibacterium) reported with dietary confounding',
        'Systematic review synthesis (24–26 studies)',
        'Manual curation of verbatim systematic review conclusions in pubmed_articles',
        'verified_curated',
        'High inter-study heterogeneity and unmeasured confounding by diet and medication prevent causal inference from cross-sectional microbiome associations.'
    ]
];

$stEdge = $db->prepare("INSERT INTO prov_evidence_edges (
    edge_code, source_entity_type, source_entity_id, source_entity_label,
    target_entity_type, target_entity_id, target_entity_label,
    relationship_type, evidence_category, evidence_badge_label,
    source_study_code, original_source_id, supporting_passage_or_field,
    study_population, tissue_or_modality, effect_direction, effect_estimate,
    curation_method, review_status, limitations
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
    supporting_passage_or_field=VALUES(supporting_passage_or_field),
    effect_estimate=VALUES(effect_estimate),
    limitations=VALUES(limitations)");

foreach ($evidenceEdges as $ee) {
    $stEdge->execute($ee);
}
echo "[OK] Populated prov_evidence_edges (" . count($evidenceEdges) . " source-traceable evidence relationships)\n";

echo "\n=== Provenance Migration & Data Ingestion Completed Successfully! ===\n";
