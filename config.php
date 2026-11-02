<?php
declare(strict_types=1);

// Environment-aware database settings for XAMPP (with safe local defaults)
define('DB_HOST', getenv('NEUROGI_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', (int)(getenv('NEUROGI_DB_PORT') ?: 3306));
define('DB_USER', getenv('NEUROGI_DB_USER') ?: 'root');
define('DB_PASS', getenv('NEUROGI_DB_PASS') !== false ? (string)getenv('NEUROGI_DB_PASS') : '');
define('DB_NAME', getenv('NEUROGI_DB_NAME') ?: 'neurogi_atlas');

// Platform version and scientific governance constants
const PLATFORM_VERSION = '2.0.0-provenance';
const PLATFORM_DESIGNATION = 'Multimodal Gut–Brain Evidence Platform';

// Tables that should NOT appear as raw generic tables in legacy api.php
const HIDDEN_TABLES = [
    'hyperkvasir_import_staging',
    'sample_metadata_import',
    'gene_results_import',
    'atlas_dataset_link',
    'schema_migrations',
    'prov_studies',
    'prov_datasets',
    'prov_dataset_versions',
    'prov_participants',
    'prov_visits',
    'prov_measurement_defs',
    'prov_source_records',
    'prov_measurements',
    'prov_participant_links',
    'prov_data_quality_issues',
    'prov_evidence_edges',
    'prov_analysis_runs'
];
