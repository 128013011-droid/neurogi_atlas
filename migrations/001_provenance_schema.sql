-- ============================================================================
-- Migration 001: Provenance-Aware Multimodal Gut-Brain Data Model
-- Non-destructive extension of the neurogi_atlas database.
-- All original tables and imported records are preserved untouched.
-- ============================================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(50) PRIMARY KEY,
    description VARCHAR(255) NOT NULL,
    checksum VARCHAR(64) NOT NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1. Studies Registry
CREATE TABLE IF NOT EXISTS prov_studies (
    study_code VARCHAR(60) PRIMARY KEY,
    title VARCHAR(500) NOT NULL,
    short_name VARCHAR(120) NOT NULL,
    institution VARCHAR(255) DEFAULT NULL,
    country VARCHAR(100) DEFAULT NULL,
    primary_accession VARCHAR(100) DEFAULT NULL,
    pmid VARCHAR(50) DEFAULT NULL,
    doi VARCHAR(150) DEFAULT NULL,
    study_design VARCHAR(150) DEFAULT NULL,
    population_description TEXT,
    cohort_scope VARCHAR(150) DEFAULT NULL,
    is_ibs_cohort TINYINT(1) NOT NULL DEFAULT 0,
    license VARCHAR(150) DEFAULT NULL,
    access_restrictions TEXT,
    source_url TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 2. Datasets Registry
CREATE TABLE IF NOT EXISTS prov_datasets (
    dataset_code VARCHAR(80) PRIMARY KEY,
    study_code VARCHAR(60) NOT NULL,
    dataset_name VARCHAR(255) NOT NULL,
    domain VARCHAR(80) NOT NULL,
    measurement_layer VARCHAR(120) DEFAULT NULL,
    source_table_or_file VARCHAR(255) NOT NULL,
    record_authenticity ENUM(
        'real_source_data',
        'curated_repository_extract',
        'reference_benchmark_non_ibs',
        'synthetic_or_composite_legacy'
    ) NOT NULL DEFAULT 'real_source_data',
    sample_count INT NOT NULL DEFAULT 0,
    participant_count INT NOT NULL DEFAULT 0,
    available_timepoints VARCHAR(150) DEFAULT NULL,
    unit_and_normalization_note TEXT,
    license VARCHAR(150) DEFAULT NULL,
    access_restrictions VARCHAR(255) DEFAULT NULL,
    retrieval_or_version_date VARCHAR(80) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_prov_ds_study (study_code)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3. Dataset Versions & File Checksums
CREATE TABLE IF NOT EXISTS prov_dataset_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dataset_code VARCHAR(80) NOT NULL,
    version_tag VARCHAR(60) NOT NULL,
    retrieval_date DATE DEFAULT NULL,
    file_path VARCHAR(255) DEFAULT NULL,
    file_sha256 VARCHAR(64) DEFAULT NULL,
    row_count INT NOT NULL DEFAULT 0,
    import_script VARCHAR(255) DEFAULT NULL,
    transformation_log TEXT,
    is_current TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dataset_version (dataset_code, version_tag)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 4. Study-Scoped Participants
CREATE TABLE IF NOT EXISTS prov_participants (
    participant_uid VARCHAR(120) PRIMARY KEY,
    study_code VARCHAR(60) NOT NULL,
    local_participant_id VARCHAR(80) NOT NULL,
    raw_cohort_label VARCHAR(80) DEFAULT NULL,
    harmonized_cohort VARCHAR(60) DEFAULT NULL,
    subtype_specificity ENUM(
        'subtype_specific',
        'generic_ibs',
        'healthy_control',
        'unknown_or_missing'
    ) NOT NULL DEFAULT 'unknown_or_missing',
    raw_sex VARCHAR(40) DEFAULT NULL,
    harmonized_sex ENUM('F', 'M', 'Conflict', 'Missing') NOT NULL DEFAULT 'Missing',
    raw_age_summary VARCHAR(80) DEFAULT NULL,
    baseline_age DOUBLE DEFAULT NULL,
    bmi DOUBLE DEFAULT NULL,
    education_years DOUBLE DEFAULT NULL,
    identity_verification_status ENUM(
        'verified',
        'verified_with_visit_offset',
        'unresolved_conflict',
        'single_table_only',
        'unlinked_anonymized_id'
    ) NOT NULL DEFAULT 'single_table_only',
    verification_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_study_local_participant (study_code, local_participant_id),
    INDEX idx_part_study (study_code),
    INDEX idx_part_cohort (harmonized_cohort),
    INDEX idx_part_status (identity_verification_status)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 5. Explicit Visits / Timepoints
CREATE TABLE IF NOT EXISTS prov_visits (
    visit_uid VARCHAR(150) PRIMARY KEY,
    participant_uid VARCHAR(120) NOT NULL,
    study_code VARCHAR(60) NOT NULL,
    visit_code VARCHAR(50) NOT NULL,
    visit_label VARCHAR(120) NOT NULL,
    timepoint_order INT NOT NULL DEFAULT 1,
    age_at_visit DOUBLE DEFAULT NULL,
    raw_age_value VARCHAR(50) DEFAULT NULL,
    visit_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_visit_part (participant_uid),
    INDEX idx_visit_study_code (study_code, visit_code)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 6. Measurement Definitions (Units, Scale, Normalization, ICV Handling)
CREATE TABLE IF NOT EXISTS prov_measurement_defs (
    measure_code VARCHAR(80) PRIMARY KEY,
    domain VARCHAR(60) NOT NULL,
    measure_name VARCHAR(150) NOT NULL,
    source_column VARCHAR(100) DEFAULT NULL,
    unit VARCHAR(60) NOT NULL,
    scale_range VARCHAR(80) DEFAULT NULL,
    normalization_method VARCHAR(150) NOT NULL,
    icv_handling VARCHAR(255) DEFAULT NULL,
    description TEXT
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 7. Source Records (Preserving Original Row ID & Raw JSON Payload)
CREATE TABLE IF NOT EXISTS prov_source_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dataset_code VARCHAR(80) NOT NULL,
    source_file_or_table VARCHAR(255) NOT NULL,
    original_row_id VARCHAR(120) NOT NULL,
    participant_uid VARCHAR(120) DEFAULT NULL,
    visit_uid VARCHAR(150) DEFAULT NULL,
    raw_payload_json LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dataset_orig_row (dataset_code, original_row_id),
    INDEX idx_sr_participant (participant_uid)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 8. Participant Measurements (Raw & Harmonized Preserved Separately)
CREATE TABLE IF NOT EXISTS prov_measurements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    participant_uid VARCHAR(120) NOT NULL,
    visit_uid VARCHAR(150) NOT NULL,
    dataset_code VARCHAR(80) NOT NULL,
    measure_code VARCHAR(80) NOT NULL,
    raw_value_text VARCHAR(100) DEFAULT NULL,
    raw_value_num DOUBLE DEFAULT NULL,
    harmonized_value_num DOUBLE DEFAULT NULL,
    derived_raw_mm3 DOUBLE DEFAULT NULL,
    unit VARCHAR(60) DEFAULT NULL,
    is_missing TINYINT(1) NOT NULL DEFAULT 0,
    quality_flag VARCHAR(100) DEFAULT NULL,
    source_record_id INT DEFAULT NULL,
    UNIQUE KEY uq_visit_ds_measure (visit_uid, dataset_code, measure_code),
    INDEX idx_pm_part_measure (participant_uid, measure_code),
    INDEX idx_pm_measure (measure_code)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 9. Participant Linkage Decisions
CREATE TABLE IF NOT EXISTS prov_participant_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_participant_uid VARCHAR(120) NOT NULL,
    source_dataset_code VARCHAR(80) NOT NULL,
    source_local_id VARCHAR(80) NOT NULL,
    target_participant_uid VARCHAR(120) DEFAULT NULL,
    target_dataset_code VARCHAR(80) NOT NULL,
    target_local_id VARCHAR(80) NOT NULL,
    same_study TINYINT(1) NOT NULL DEFAULT 0,
    link_status ENUM(
        'verified',
        'verified_with_visit_offset',
        'unresolved',
        'rejected',
        'not_applicable'
    ) NOT NULL,
    exclude_from_matched_analysis TINYINT(1) NOT NULL DEFAULT 1,
    demographic_concordance VARCHAR(255) DEFAULT NULL,
    justification TEXT NOT NULL,
    reviewed_by VARCHAR(100) DEFAULT 'NeuroGI Provenance Audit Engine v2.0',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_participant_link (source_dataset_code, source_local_id, target_dataset_code, target_local_id),
    INDEX idx_pl_source_uid (source_participant_uid),
    INDEX idx_pl_status (link_status)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 10. Data-Quality Issues Registry
CREATE TABLE IF NOT EXISTS prov_data_quality_issues (
    issue_code VARCHAR(50) PRIMARY KEY,
    study_code VARCHAR(60) NOT NULL,
    dataset_code VARCHAR(80) NOT NULL,
    participant_uid VARCHAR(120) DEFAULT NULL,
    record_identifier VARCHAR(120) DEFAULT NULL,
    severity ENUM('critical', 'warning', 'info') NOT NULL,
    category VARCHAR(80) NOT NULL,
    field_name VARCHAR(80) DEFAULT NULL,
    observed_values TEXT NOT NULL,
    root_cause TEXT NOT NULL,
    resolution_policy TEXT NOT NULL,
    original_preserved TINYINT(1) NOT NULL DEFAULT 1,
    excluded_by_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dqi_participant (participant_uid),
    INDEX idx_dqi_study (study_code)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 11. Source-Traceable Evidence Relationships (Phase 5)
CREATE TABLE IF NOT EXISTS prov_evidence_edges (
    edge_code VARCHAR(60) PRIMARY KEY,
    source_entity_type VARCHAR(50) NOT NULL,
    source_entity_id VARCHAR(100) NOT NULL,
    source_entity_label VARCHAR(255) NOT NULL,
    target_entity_type VARCHAR(50) NOT NULL,
    target_entity_id VARCHAR(100) NOT NULL,
    target_entity_label VARCHAR(255) NOT NULL,
    relationship_type VARCHAR(100) NOT NULL,
    evidence_category ENUM(
        'same_cohort_measured',
        'cross_study_literature',
        'curated_contextual',
        'computational_hypothesis',
        'contradictory_or_inconclusive'
    ) NOT NULL,
    evidence_badge_label VARCHAR(100) NOT NULL,
    source_study_code VARCHAR(60) NOT NULL,
    original_source_id VARCHAR(150) NOT NULL,
    supporting_passage_or_field TEXT NOT NULL,
    study_population VARCHAR(255) DEFAULT NULL,
    tissue_or_modality VARCHAR(150) DEFAULT NULL,
    effect_direction VARCHAR(80) DEFAULT NULL,
    effect_estimate VARCHAR(255) DEFAULT NULL,
    curation_method VARCHAR(150) NOT NULL,
    review_status ENUM(
        'verified_curated',
        'computed_from_matched_data',
        'flagged_limitation'
    ) NOT NULL DEFAULT 'verified_curated',
    limitations TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 12. Reproducible Analysis Runs Registry (Phase 4)
CREATE TABLE IF NOT EXISTS prov_analysis_runs (
    run_hash VARCHAR(64) PRIMARY KEY,
    study_code VARCHAR(60) NOT NULL,
    dataset_versions VARCHAR(255) NOT NULL,
    visit_selection_rule VARCHAR(150) NOT NULL,
    cohort_filter VARCHAR(60) NOT NULL,
    exclude_unresolved TINYINT(1) NOT NULL DEFAULT 1,
    exclude_age_offset TINYINT(1) NOT NULL DEFAULT 0,
    x_measure_code VARCHAR(80) NOT NULL,
    y_measure_code VARCHAR(80) NOT NULL,
    stat_method ENUM('pearson', 'spearman') NOT NULL,
    covariates_json VARCHAR(255) DEFAULT '[]',
    multiple_testing_method VARCHAR(80) DEFAULT 'BH_FDR',
    n_eligible INT NOT NULL,
    n_excluded INT NOT NULL,
    exclusion_breakdown_json TEXT NOT NULL,
    result_summary_json LONGTEXT NOT NULL,
    software_version VARCHAR(120) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 13. Mars et al. GC-MS Mucosal Biopsy Metabolomics (Real Data from Supplementary Data III)
CREATE TABLE IF NOT EXISTS mars_gcms_biopsy_metabolites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    excel_row_num INT NOT NULL UNIQUE,
    raw_cohort VARCHAR(40) NOT NULL,
    patient_id VARCHAR(40) NOT NULL,
    visit_number INT NOT NULL,
    short_sample_id VARCHAR(40) NOT NULL,
    full_sample_title VARCHAR(60) NOT NULL,
    participant_uid VARCHAR(120) NOT NULL,
    visit_uid VARCHAR(150) NOT NULL,
    matched_rnaseq_geo VARCHAR(40) DEFAULT NULL,
    acetate DOUBLE DEFAULT NULL,
    propionate DOUBLE DEFAULT NULL,
    iso_butyrate DOUBLE DEFAULT NULL,
    butyrate DOUBLE DEFAULT NULL,
    two_methyl_butyrate DOUBLE DEFAULT NULL,
    iso_valerate DOUBLE DEFAULT NULL,
    valerate DOUBLE DEFAULT NULL,
    caproate DOUBLE DEFAULT NULL,
    lactate DOUBLE DEFAULT NULL,
    two_oh_butyrate DOUBLE DEFAULT NULL,
    unit VARCHAR(120) DEFAULT 'Normalized peak area / biopsy dry weight (standardized source units)',
    source_file VARCHAR(255) DEFAULT 'data/Mars et al, IBS multi-omics/Supplementary data III source data GC-MS biopsy.xlsx',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_gcms_part (participant_uid),
    INDEX idx_gcms_sample (full_sample_title)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 14. Mars et al. NMR Stool Metabolomics (Real Data from Supplementary Data II)
CREATE TABLE IF NOT EXISTS mars_nmr_stool_metabolites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    matlab_no INT NOT NULL UNIQUE,
    id_on_tube VARCHAR(40) NOT NULL,
    time_point INT NOT NULL,
    gender VARCHAR(10) DEFAULT NULL,
    gender_code INT DEFAULT NULL,
    diagnosis VARCHAR(40) NOT NULL,
    group_code INT DEFAULT NULL,
    participant_uid VARCHAR(120) NOT NULL,
    two_methylbutyrate DOUBLE DEFAULT NULL,
    lactate DOUBLE DEFAULT NULL,
    alanine DOUBLE DEFAULT NULL,
    tyrosine DOUBLE DEFAULT NULL,
    isoleucine DOUBLE DEFAULT NULL,
    leucine DOUBLE DEFAULT NULL,
    valine DOUBLE DEFAULT NULL,
    lysine DOUBLE DEFAULT NULL,
    succinate DOUBLE DEFAULT NULL,
    glycine DOUBLE DEFAULT NULL,
    b_arabinose DOUBLE DEFAULT NULL,
    b_xylose DOUBLE DEFAULT NULL,
    acetate DOUBLE DEFAULT NULL,
    propionate DOUBLE DEFAULT NULL,
    butyrate DOUBLE DEFAULT NULL,
    glucose DOUBLE DEFAULT NULL,
    isovalerate DOUBLE DEFAULT NULL,
    uracil DOUBLE DEFAULT NULL,
    hypoxanthine DOUBLE DEFAULT NULL,
    linkage_status VARCHAR(60) DEFAULT 'unlinked_anonymized_tube_id',
    source_file VARCHAR(255) DEFAULT 'data/Mars et al, IBS multi-omics/Supplementary data II source data NMR metabolomics.xlsx',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_nmr_tube (id_on_tube),
    INDEX idx_nmr_diag (diagnosis)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 15. Local Kvasir-SEG Reference Assets (1,000 Verified Local Masks & BBoxes)
CREATE TABLE IF NOT EXISTS kvasir_seg_local_assets (
    image_id VARCHAR(100) PRIMARY KEY,
    study_code VARCHAR(60) NOT NULL DEFAULT 'SIMULA_KVASIR_SEG',
    dataset_code VARCHAR(80) NOT NULL DEFAULT 'KVASIR_SEG_LOCAL_1000',
    modality VARCHAR(50) NOT NULL DEFAULT 'Endoscopy (Polyp Segmentation Reference)',
    body_region VARCHAR(100) NOT NULL DEFAULT 'Colorectal / Lower GI Tract',
    original_label VARCHAR(100) NOT NULL DEFAULT 'polyp',
    image_width INT NOT NULL,
    image_height INT NOT NULL,
    bbox_count INT NOT NULL DEFAULT 1,
    bbox_json TEXT NOT NULL,
    local_mask_path VARCHAR(255) DEFAULT NULL,
    has_local_mask TINYINT(1) NOT NULL DEFAULT 0,
    has_local_raw_frame TINYINT(1) NOT NULL DEFAULT 0,
    is_ibs_cohort TINYINT(1) NOT NULL DEFAULT 0,
    license VARCHAR(100) NOT NULL DEFAULT 'CC-BY 4.0 (Simula Research Laboratory)',
    source_citation VARCHAR(255) NOT NULL DEFAULT 'Jha et al. 2020 (Kvasir-SEG) / Borgli et al. 2020 (HyperKvasir)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
