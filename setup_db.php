<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

echo "=== Initializing NeuroGI Atlas Database Tables ===\n";

try {
    $db = pdo();

    // 1. Run link_table.sql
    $linkSql = file_get_contents(__DIR__ . '/link_table.sql');
    if ($linkSql) {
        $db->exec($linkSql);
        echo "[OK] Executed link_table.sql (atlas_dataset_link)\n";
    }

    // 2. pubmed_articles
    $db->exec("CREATE TABLE IF NOT EXISTS pubmed_articles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pmid VARCHAR(50) NOT NULL UNIQUE,
        title VARCHAR(600) NOT NULL,
        year VARCHAR(20) DEFAULT '',
        abstract LONGTEXT,
        source VARCHAR(50) DEFAULT 'PubMed',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pubCsv = __DIR__ . '/data/pubmed_articles_clean.csv';
    if (file_exists($pubCsv)) {
        $handle = fopen($pubCsv, 'r');
        $header = fgetcsv($handle);
        $stmt = $db->prepare("INSERT INTO pubmed_articles (pmid, title, year, abstract, source)
                              VALUES (?, ?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE title=VALUES(title), year=VALUES(year), abstract=VALUES(abstract)");
        $count = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) >= 5 && !empty(trim($row[0]))) {
                $stmt->execute([trim($row[0]), trim($row[1]), trim($row[2]), trim($row[3]), trim($row[4])]);
                $count++;
            }
        }
        fclose($handle);
        echo "[OK] pubmed_articles: populated $count rows\n";
    }

    // 3. clinical_trials
    $db->exec("CREATE TABLE IF NOT EXISTS clinical_trials (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nct_id VARCHAR(50) NOT NULL UNIQUE,
        title VARCHAR(600) NOT NULL,
        status VARCHAR(100) DEFAULT '',
        study_type VARCHAR(100) DEFAULT '',
        phase VARCHAR(100) DEFAULT '',
        conditions TEXT,
        start_date VARCHAR(50) DEFAULT '',
        completion_date VARCHAR(50) DEFAULT '',
        enrollment VARCHAR(50) DEFAULT '',
        sponsor VARCHAR(255) DEFAULT '',
        summary LONGTEXT,
        source VARCHAR(50) DEFAULT 'ClinicalTrials.gov',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $trialCsv = __DIR__ . '/data/clinical_trials_clean.csv';
    if (file_exists($trialCsv)) {
        $handle = fopen($trialCsv, 'r');
        $header = fgetcsv($handle);
        $stmt = $db->prepare("INSERT INTO clinical_trials (nct_id, title, status, study_type, phase, conditions, start_date, completion_date, enrollment, sponsor, summary, source)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE title=VALUES(title), status=VALUES(status), phase=VALUES(phase)");
        $count = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) >= 12 && !empty(trim($row[0]))) {
                $stmt->execute([
                    trim($row[0]), trim($row[1]), trim($row[2]), trim($row[3]),
                    trim($row[4]), trim($row[5]), trim($row[6]), trim($row[7]),
                    trim($row[8]), trim($row[9]), trim($row[10]), trim($row[11])
                ]);
                $count++;
            }
        }
        fclose($handle);
        echo "[OK] clinical_trials: populated $count rows\n";
    }

    // 4. geo_candidates
    $db->exec("CREATE TABLE IF NOT EXISTS geo_candidates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        geo_uid VARCHAR(50) NOT NULL UNIQUE,
        accession VARCHAR(50) NOT NULL,
        title VARCHAR(600) NOT NULL,
        summary LONGTEXT,
        organism VARCHAR(100) DEFAULT 'Homo sapiens',
        study_type VARCHAR(100) DEFAULT '',
        submission_date VARCHAR(50) DEFAULT '',
        samples VARCHAR(50) DEFAULT '',
        source VARCHAR(50) DEFAULT 'NCBI GEO',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $geoCsv = __DIR__ . '/data/geo_gut_brain_candidates.csv';
    if (file_exists($geoCsv)) {
        $handle = fopen($geoCsv, 'r');
        $header = fgetcsv($handle);
        $stmt = $db->prepare("INSERT INTO geo_candidates (geo_uid, accession, title, summary, organism, study_type, submission_date, samples, source)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE title=VALUES(title), summary=VALUES(summary)");
        $count = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) >= 9 && !empty(trim($row[0]))) {
                $stmt->execute([
                    trim($row[0]), trim($row[1]), trim($row[2]), trim($row[3]),
                    trim($row[4]), trim($row[5]), trim($row[6]), trim($row[7]),
                    trim($row[8])
                ]);
                $count++;
            }
        }
        fclose($handle);
        echo "[OK] geo_candidates: populated $count rows\n";
    }

    // 5. significant_genes
    $db->exec("CREATE TABLE IF NOT EXISTS significant_genes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        gene_id VARCHAR(50) NOT NULL UNIQUE,
        gene_symbol VARCHAR(50) DEFAULT NULL,
        gene_description VARCHAR(255) DEFAULT NULL,
        baseMean DOUBLE DEFAULT 0,
        log2FoldChange DOUBLE DEFAULT 0,
        pvalue DOUBLE DEFAULT 1,
        padj DOUBLE DEFAULT 1,
        expression_change VARCHAR(100) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $sigCsv = __DIR__ . '/data/NeuroGI_Atlas_final_gene_table.csv';
    if (file_exists($sigCsv)) {
        $handle = fopen($sigCsv, 'r');
        $header = fgetcsv($handle);
        $stmt = $db->prepare("INSERT INTO significant_genes (gene_id, gene_symbol, gene_description, baseMean, log2FoldChange, pvalue, padj, expression_change)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE log2FoldChange=VALUES(log2FoldChange), padj=VALUES(padj), expression_change=VALUES(expression_change)");
        $count = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) >= 8 && !empty(trim($row[0]))) {
                $sym = trim($row[1]);
                if ($sym === 'NA' || $sym === '') $sym = null;
                $desc = trim($row[2]);
                if ($desc === 'NA' || $desc === '') $desc = null;

                $stmt->execute([
                    trim($row[0]),
                    $sym,
                    $desc,
                    (float)$row[3],
                    (float)$row[4],
                    (float)$row[5],
                    (float)$row[6],
                    trim($row[7])
                ]);
                $count++;
            }
        }
        fclose($handle);
        echo "[OK] significant_genes: populated $count rows\n";
    }

    // 6. go_enrichment
    $db->exec("CREATE TABLE IF NOT EXISTS go_enrichment (
        id INT AUTO_INCREMENT PRIMARY KEY,
        go_id VARCHAR(50) NOT NULL UNIQUE,
        description VARCHAR(255) NOT NULL,
        gene_ratio VARCHAR(50) DEFAULT '',
        bg_ratio VARCHAR(50) DEFAULT '',
        rich_factor DOUBLE DEFAULT 0,
        fold_enrichment DOUBLE DEFAULT 0,
        odds_ratio DOUBLE DEFAULT 0,
        z_score DOUBLE DEFAULT 0,
        pvalue DOUBLE DEFAULT 1,
        p_adjust DOUBLE DEFAULT 1,
        qvalue DOUBLE DEFAULT 1,
        gene_ids VARCHAR(255) DEFAULT '',
        gene_count INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $goCsv = __DIR__ . '/data/NeuroGI_Atlas_corrected_GO_enrichment.csv';
    if (file_exists($goCsv)) {
        $handle = fopen($goCsv, 'r');
        $header = fgetcsv($handle);
        $stmt = $db->prepare("INSERT INTO go_enrichment (go_id, description, gene_ratio, bg_ratio, rich_factor, fold_enrichment, odds_ratio, z_score, pvalue, p_adjust, qvalue, gene_ids, gene_count)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE description=VALUES(description), p_adjust=VALUES(p_adjust)");
        $count = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) >= 13 && !empty(trim($row[0]))) {
                $stmt->execute([
                    trim($row[0]), trim($row[1]), trim($row[2]), trim($row[3]),
                    (float)$row[4], (float)$row[5], (float)$row[6], (float)$row[7],
                    (float)$row[8], (float)$row[9], (float)$row[10], trim($row[11]),
                    (int)$row[12]
                ]);
                $count++;
            }
        }
        fclose($handle);
        echo "[OK] go_enrichment: populated $count rows\n";
    }

    echo "\n=== Database Setup Complete Successfully! ===\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
