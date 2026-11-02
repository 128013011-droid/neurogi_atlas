<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';

$geneQuery = trim($_GET['gene'] ?? '');
if ($geneQuery === '') {
    echo json_encode(['error' => 'Please provide a gene name or Ensembl ID (e.g., GDNF or ENSG00000168621).']);
    exit;
}

$countsFile = __DIR__ . '/../data/GSE146853_filtered_counts.csv';
if (!file_exists($countsFile)) {
    echo json_encode(['error' => 'Filtered count file not found on server.']);
    exit;
}

// 1. Fetch sample metadata from database to get cohorts for each sample
$sampleCohorts = [];
try {
    $db = pdo();
    $stmt = $db->query("SELECT sample_title, cohort, gender, age, timepoint 
                        FROM sample_metadata 
                        WHERE geo_accession != 'geo_accession' AND cohort IS NOT NULL");
    while ($r = $stmt->fetch()) {
        $sampleCohorts[$r['sample_title']] = [
            'cohort'    => $r['cohort'],
            'gender'    => $r['gender'],
            'age'       => $r['age'],
            'timepoint' => $r['timepoint']
        ];
    }
} catch (Exception $e) {
    // Fallback: continue without metadata
}

// 2. Open filtered count CSV and stream line by line
$handle = fopen($countsFile, 'r');
if (!$handle) {
    echo json_encode(['error' => 'Could not read count file.']);
    exit;
}

$header = fgetcsv($handle);
if (!$header) {
    fclose($handle);
    echo json_encode(['error' => 'Corrupt count header.']);
    exit;
}

// Map sample column index to sample title
$sampleColMap = [];
foreach ($header as $idx => $colName) {
    if (str_ends_with($colName, '_GeneCount')) {
        $sampleTitle = preg_replace('/^s_|_GeneCount$/', '', $colName);
        $sampleColMap[$idx] = [
            'raw_col'      => $colName,
            'sample_title' => $sampleTitle,
            'cohort'       => $sampleCohorts[$sampleTitle]['cohort'] ?? 'Unknown',
            'gender'       => $sampleCohorts[$sampleTitle]['gender'] ?? '',
            'age'          => $sampleCohorts[$sampleTitle]['age'] ?? null,
            'timepoint'    => $sampleCohorts[$sampleTitle]['timepoint'] ?? ''
        ];
    }
}

// Search for the requested gene
$foundGene = null;
$cleanQuery = strtoupper($geneQuery);

while (($row = fgetcsv($handle)) !== false) {
    $chr       = $row[0] ?? '';
    $geneId    = $row[1] ?? '';
    $geneName  = $row[2] ?? '';
    $start     = $row[3] ?? '';
    $stop      = $row[4] ?? '';
    $codingLen = $row[5] ?? '';

    if (strtoupper($geneId) === $cleanQuery || strtoupper($geneName) === $cleanQuery) {
        $samplesData = [];
        $cohortCounts = ['Healthy' => [], 'Diarrhea' => [], 'Constipation' => [], 'Unknown' => []];

        foreach ($sampleColMap as $idx => $info) {
            $rawVal = isset($row[$idx]) ? (float)$row[$idx] : 0.0;
            $sampleEntry = [
                'sample'    => $info['sample_title'],
                'cohort'    => $info['cohort'],
                'count'     => $rawVal,
                'gender'    => $info['gender'],
                'age'       => $info['age'],
                'timepoint' => $info['timepoint']
            ];
            $samplesData[] = $sampleEntry;
            $cKey = in_array($info['cohort'], ['Healthy', 'Diarrhea', 'Constipation'], true) ? $info['cohort'] : 'Unknown';
            $cohortCounts[$cKey][] = $rawVal;
        }

        // Compute summary statistics per cohort (mean, median, min, max)
        $summary = [];
        foreach ($cohortCounts as $cName => $vals) {
            if (empty($vals)) continue;
            sort($vals);
            $n = count($vals);
            $mean = array_sum($vals) / $n;
            $median = ($n % 2 === 0) ? ($vals[$n / 2 - 1] + $vals[$n / 2]) / 2 : $vals[floor($n / 2)];
            $summary[$cName] = [
                'n'      => $n,
                'mean'   => round($mean, 2),
                'median' => round($median, 2),
                'min'    => min($vals),
                'max'    => max($vals),
                'q1'     => $vals[floor($n * 0.25)],
                'q3'     => $vals[floor($n * 0.75)],
            ];
        }

        $foundGene = [
            'gene_id'       => $geneId,
            'gene_name'     => $geneName,
            'chromosome'    => $chr,
            'start'         => $start,
            'stop'          => $stop,
            'coding_length' => $codingLen,
            'sample_count'  => count($samplesData),
            'cohort_summary'=> $summary,
            'samples'       => $samplesData,
        ];
        break;
    }
}

fclose($handle);

if (!$foundGene) {
    echo json_encode([
        'found' => false,
        'query' => $geneQuery,
        'message' => "Gene '$geneQuery' not found in GSE146853 filtered dataset (count >= 10 in >= 3 samples)."
    ]);
    exit;
}

// Check if this gene is in the DESeq2 significant results
$deInfo = null;
try {
    $db = pdo();
    $st = $db->prepare("SELECT * FROM significant_genes WHERE gene_id = ? OR gene_symbol = ? LIMIT 1");
    $st->execute([$foundGene['gene_id'], $foundGene['gene_name']]);
    $deInfo = $st->fetch() ?: null;
} catch (Exception $e) {
    // Continue
}

echo json_encode([
    'found'         => true,
    'gene'          => $foundGene,
    'deseq2'        => $deInfo,
    'note'          => 'Counts shown are unnormalized sequencing read counts from GSE146853. Normalization and differential expression testing are modeled via DESeq2 negative binomial dispersion.'
], JSON_PRETTY_PRINT);
