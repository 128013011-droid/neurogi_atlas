<?php
declare(strict_types=1);

$urls = [
    'Home Dashboard'                        => 'http://localhost/NeuroGI_Atlas/index.php',
    'Root URL'                              => 'http://localhost/NeuroGI_Atlas/',
    'Search Page (Blank)'                   => 'http://localhost/NeuroGI_Atlas/search.php',
    'Search Keyword: IBS'                   => 'http://localhost/NeuroGI_Atlas/search.php?q=IBS',
    'Search Subtype: IBS-D (Strict)'        => 'http://localhost/NeuroGI_Atlas/search.php?cohort=IBS-D',
    'Search Timepoint: Visit 2'             => 'http://localhost/NeuroGI_Atlas/search.php?q=IBS&timepoint=V2_FOLLOWUP',
    'Search Domain: Brain'                  => 'http://localhost/NeuroGI_Atlas/search.php?domain=brain',
    'Participant Registry'                  => 'http://localhost/NeuroGI_Atlas/participant.php',
    'Participant Verified (BGA_046)'        => 'http://localhost/NeuroGI_Atlas/participant.php?uid=BERGEN_BGA:BGA_046',
    'Participant Sex Conflict (BGA_072)'    => 'http://localhost/NeuroGI_Atlas/participant.php?uid=BERGEN_BGA:BGA_072',
    'Participant Age Conflict (BGA_070)'    => 'http://localhost/NeuroGI_Atlas/participant.php?uid=BERGEN_BGA:BGA_070',
    'Participant Visit Offset (BGA_097)'    => 'http://localhost/NeuroGI_Atlas/participant.php?uid=BERGEN_BGA:BGA_097',
    'Participant Mars Biopsy (10007541)'    => 'http://localhost/NeuroGI_Atlas/participant.php?uid=MARS_GSE146853:10007541',
    'Analysis Engine (Bergen Default)'      => 'http://localhost/NeuroGI_Atlas/analysis.php?study=BERGEN_BGA&x=Left-Thalamus&y=IBS_SSS&cov_age=1&cov_sex=1',
    'Analysis Engine (Mars Biopsy)'         => 'http://localhost/NeuroGI_Atlas/analysis.php?study=MARS_GSE146853&x=butyrate&y=SLC26A3',
    'Analysis Export: CSV'                  => 'http://localhost/NeuroGI_Atlas/analysis.php?study=BERGEN_BGA&export=csv',
    'Analysis Export: JSON Manifest'        => 'http://localhost/NeuroGI_Atlas/analysis.php?study=BERGEN_BGA&export=json',
    'Analysis Export: R Script'             => 'http://localhost/NeuroGI_Atlas/analysis.php?study=BERGEN_BGA&export=r',
    'Analysis Export: Python Script'        => 'http://localhost/NeuroGI_Atlas/analysis.php?study=BERGEN_BGA&export=py',
    'Evidence Explorer (All)'               => 'http://localhost/NeuroGI_Atlas/evidence.php',
    'Evidence Explorer (Same-Cohort)'       => 'http://localhost/NeuroGI_Atlas/evidence.php?category=same_cohort_measured',
    'Dataset Catalogue'                     => 'http://localhost/NeuroGI_Atlas/datasets.php',
    'Gene Expression Analysis'              => 'http://localhost/NeuroGI_Atlas/genes.php',
    'Gene Export: DE CSV'                   => 'http://localhost/NeuroGI_Atlas/genes.php?export=de_csv',
    'Gene Export: GO CSV'                   => 'http://localhost/NeuroGI_Atlas/genes.php?export=go_csv',
    'Gene Counts API (TIGIT)'               => 'http://localhost/NeuroGI_Atlas/api/gene_counts.php?gene=TIGIT',
    'Gene Counts API (SLC26A3)'             => 'http://localhost/NeuroGI_Atlas/api/gene_counts.php?gene=SLC26A3',
    'Endoscopy Local Masks (1000)'          => 'http://localhost/NeuroGI_Atlas/gut_images.php?mode=local_masks',
    'Endoscopy Staging Table'               => 'http://localhost/NeuroGI_Atlas/gut_images.php?mode=staging',
    'Research & References'                 => 'http://localhost/NeuroGI_Atlas/research.php',
    'Validation, Worked Example & Limits'   => 'http://localhost/NeuroGI_Atlas/validation.php',
    'About & Provenance'                    => 'http://localhost/NeuroGI_Atlas/about.php',
];

echo "=== Running NeuroGI Atlas v2.0 Automated Web Endpoint Tests ===\n\n";

$passed = 0;
$failed = 0;

foreach ($urls as $name => $url) {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 8,
            'ignore_errors' => true
        ]
    ]);

    $start = microtime(true);
    $content = @file_get_contents($url, false, $ctx);
    $duration = round((microtime(true) - $start) * 1000, 1);

    $statusLine = $http_response_header[0] ?? 'HTTP/1.1 500 Error';
    preg_match('{HTTP\/\S*\s(\d{3})}', $statusLine, $match);
    $status = (int)($match[1] ?? 500);

    $hasFatal = ($content !== false) && (
        stripos($content, 'Fatal error') !== false ||
        stripos($content, 'Parse error') !== false ||
        stripos($content, 'Uncaught PDOException') !== false ||
        stripos($content, 'Warning:') !== false
    );

    if ($status === 200 && !$hasFatal && strlen((string)$content) > 50) {
        echo " [PASS] $name ($status, {$duration}ms, " . strlen((string)$content) . " bytes)\n";
        $passed++;
    } else {
        echo " [FAIL] $name ($status, {$duration}ms)\n";
        if ($hasFatal) {
            echo "        Error detected in body: " . substr(strip_tags((string)$content), 0, 200) . "...\n";
        }
        $failed++;
    }
}

echo "\nSummary: $passed Passed, $failed Failed\n";
exit($failed > 0 ? 1 : 0);
