<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$db = pdo();

// 1. Fetch the 15 verified statistically significant genes (including lfcSE, stat, contrast_definition, tissue)
$sigGenes = $db->query("SELECT * FROM significant_genes ORDER BY padj ASC")->fetchAll();

// 2. Fetch the corrected GO biological process enrichment terms (6 terms) and full 63-term table if requested
$showAllGo = !empty($_GET['all_go']);
$goTerms = $db->query("SELECT * FROM go_enrichment ORDER BY p_adjust ASC")->fetchAll();
$goSigAll = [];
if ($showAllGo) {
    try {
        $goSigAll = $db->query("SELECT * FROM go_enrichment_sig ORDER BY p_adjust ASC")->fetchAll();
    } catch (Throwable $e) {
        $goSigAll = [];
    }
}

// Handle CSV Downloads (?export=de_csv or ?export=go_csv)
$export = strtolower(trim((string)($_GET['export'] ?? '')));
if ($export === 'de_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gse146853_significant_de_genes_with_provenance.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['# NeuroGI Atlas — GSE146853 Significant Differentially Expressed Genes']);
    fputcsv($out, ['# Primary Study: Mars et al., Cell 2020 (DOI: 10.1016/j.cell.2020.08.007, PMID: 32916129)']);
    fputcsv($out, ['# Contrast: IBS-D vs Healthy Control | Tissue: Sigmoid Colon Mucosal Biopsy | Method: DESeq2 Negative Binomial Wald Test (BH FDR < 0.05)']);
    fputcsv($out, ['ncbi_gene_id', 'gene_symbol', 'gene_name', 'contrast_definition', 'tissue', 'baseMean', 'log2FoldChange', 'lfcSE', 'wald_stat', 'pvalue', 'padj_bh_fdr', 'expression_change']);
    foreach ($sigGenes as $g) {
        fputcsv($out, [
            $g['gene_id'],
            $g['gene_symbol'],
            $g['gene_description'] ?? '',
            $g['contrast_definition'] ?? 'IBS-D vs Healthy Control',
            $g['tissue'] ?? 'Sigmoid Colon Mucosal Biopsy',
            $g['baseMean'],
            $g['log2FoldChange'],
            $g['lfcSE'],
            $g['stat'],
            $g['pvalue'],
            $g['padj'],
            $g['expression_change']
        ]);
    }
    fclose($out);
    exit;
}
if ($export === 'go_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gse146853_go_enrichment_provenance.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['# NeuroGI Atlas — GSE146853 Gene Ontology (GO:BP) Over-Representation Analysis']);
    fputcsv($out, ['# Method: clusterProfiler::enrichGO (Hypergeometric test) | Gene Set DB: GO.db / org.Hs.eg.db (Homo sapiens)']);
    fputcsv($out, ['# Identifier Mapping: NCBI Entrez Gene ID <-> HGNC Symbol | Background Universe: N = 14,893 expressed colonic mucosal genes']);
    fputcsv($out, ['go_id', 'description', 'gene_ratio', 'bg_ratio', 'rich_factor', 'fold_enrichment', 'z_score', 'pvalue', 'p_adjust_bh', 'qvalue', 'gene_symbols', 'gene_count']);
    foreach ($goTerms as $go) {
        fputcsv($out, [
            $go['go_id'],
            $go['description'],
            $go['gene_ratio'],
            $go['bg_ratio'],
            $go['rich_factor'],
            $go['fold_enrichment'],
            $go['z_score'],
            $go['pvalue'],
            $go['p_adjust'],
            $go['qvalue'],
            $go['gene_ids'],
            $go['gene_count']
        ]);
    }
    fclose($out);
    exit;
}

// 3. Optional inspect query parameter
$inspectGene = trim((string)($_GET['inspect'] ?? 'TIGIT'));

// 4. Search across all 29,767 genes
$geneSearch = trim((string)($_GET['gene_q'] ?? ''));
$geneSearchResults = [];
if ($geneSearch !== '') {
    $gParam = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $geneSearch) . '%';
    $st = $db->prepare("SELECT r.gene_id, r.gene_symbol, r.baseMean, r.log2FoldChange, r.pvalue, r.padj, r.SYMBOL, r.GENENAME,
                               s.lfcSE, s.stat, s.contrast_definition, s.tissue
                        FROM gene_results r
                        LEFT JOIN significant_genes s ON CAST(s.gene_id AS CHAR) = r.gene_id
                        WHERE r.gene_id != 'GeneID' AND (r.gene_id LIKE ? OR r.gene_symbol LIKE ? OR r.SYMBOL LIKE ? OR r.GENENAME LIKE ?)
                        ORDER BY r.padj ASC LIMIT 35");
    $st->execute([$gParam, $gParam, $gParam, $gParam]);
    $geneSearchResults = $st->fetchAll();
}

$pageTitle = 'Transcriptomics & Biopsy Multi-Omics (GSE146853) | NeuroGI Atlas';
$activeNav = 'genes';
require_once __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="page-hero-inner">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1.5rem; flex-wrap: wrap;">
      <div style="max-width: 820px;">
        <div class="hero-badge" style="margin-bottom: 0.65rem;">
          PHASE 6 &bull; GSE146853 TRANSCRIPTOMICS TRANSPARENCY &amp; BIOPSY PROVENANCE
        </div>
        <h2>Colonic Mucosal Transcriptomics (GSE146853)</h2>
        <p>
          Explore host sigmoid colon mucosal differential gene expression from <strong>Mars et al. (<em>Cell</em> 2020, DOI: <code>10.1016/j.cell.2020.08.007</code>, PMID: <code>32916129</code>)</strong>.
          Inspect contrast definitions, standard errors (<code>lfcSE</code>), Wald statistics, sample inclusion rules, GO over-representation provenance, and paired biopsy GC-MS short-chain fatty acids.
        </p>
      </div>
      <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
        <a href="genes.php?export=de_csv" class="btn btn-outline btn-sm">Download Significant DE CSV</a>
        <a href="genes.php?export=go_csv" class="btn btn-outline btn-sm">Download GO Enrichment CSV</a>
        <a href="analysis.php?study=MARS_GSE146853" class="btn btn-primary btn-sm">Biopsy Gene–SCFA Analysis &rarr;</a>
      </div>
    </div>
  </div>
</section>

<div class="container">

  <!-- Provenance, Sample Inclusion & Cross-Cohort Boundary Banner -->
  <div class="prov-banner">
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:1rem; margin-bottom:0.85rem;">
      <div>
        <strong>Primary Study &amp; Tissue:</strong><br>
        Mars et al., <em>Cell</em> 2020 (<code>GSE146853</code>, Mayo Clinic).<br>
        <strong>Tissue:</strong> Sigmoid Colon Mucosal Biopsy.<br>
        <strong>Identifier Type:</strong> NCBI Entrez Gene ID + HGNC Symbol.
      </div>
      <div>
        <strong>Contrast &amp; Statistical Pipeline:</strong><br>
        <strong>Primary DE Contrast:</strong> <code>IBS-D vs Healthy Control</code>.<br>
        <strong>Model:</strong> DESeq2 negative binomial GLM with Wald test and Benjamini–Hochberg FDR (<code>padj &lt; 0.05</code>).<br>
        <strong>Universe:</strong> 29,767 profiled transcripts; 14,893 expressed background genes; 15 FDR-significant genes.
      </div>
      <div>
        <strong>Sample Inclusion Rules Across Tables:</strong><br>
        &bull; <code>sample_metadata</code>: 83 longitudinal colonic biopsy RNA-seq samples across 42 unique subjects (Visit 1 &amp; Visit 2).<br>
        &bull; <code>GSE146853_filtered_counts.csv</code>: 68 quality-filtered biopsies (22 Healthy, 29 IBS-D, 17 IBS-C) streamed in the Count Inspector.<br>
        &bull; <code>mars_gcms_biopsy_metabolites</code>: 66 biopsies with exact same-visit paired GC-MS SCFAs.
      </div>
    </div>
    <div style="padding-top:0.75rem; border-top:1px dashed var(--teal-border); font-size:0.82rem; color:var(--navy-dark);">
      <strong>Cross-Cohort Integrity Rule:</strong> Never correlate <code>GSE146853</code> colonic gene expression directly with <code>BERGEN_BGA</code> brain MRI volumes or IBS-SSS scores, as those come from an independent Norwegian cohort with zero shared participants.
      For matched molecular analysis within <code>GSE146853</code>, use <a href="analysis.php?study=MARS_GSE146853">Paired Biopsy RNA-seq + GC-MS SCFA Analysis</a>; for literature-supported gut–brain context, use the <a href="evidence.php">Source-Traceable Evidence Explorer</a>.
    </div>
  </div>

  <!-- Interactive On-Demand Sample Count Inspector -->
  <section class="plot-box" id="count-inspector">
    <div class="plot-header" style="flex-wrap:wrap; gap:1rem;">
      <div>
        <h3 style="font-size: 1.2rem; color: var(--navy-dark); margin-bottom: 0.25rem;">
          Interactive Sample-Level Count Inspector (<code>GSE146853_filtered_counts.csv</code>)
        </h3>
        <p style="font-size: 0.84rem; color: var(--navy-muted);">
          Streams raw sequencing read counts across 68 quality-filtered sigmoid colon mucosal biopsies (22 Healthy, 29 IBS-D, 17 IBS-C).
          Note: Raw read counts are shown for sample distribution transparency; DESeq2 fold changes use size-factor normalized counts.
        </p>
      </div>
      <div style="display: flex; gap: 0.5rem; align-items: center;">
        <input type="text" id="geneSearchInput" value="<?= h($inspectGene) ?>" placeholder="e.g. TIGIT, SLC26A3, AQP8, HMGCS2" style="padding: 0.45rem 0.75rem; border: 1px solid var(--border-light); border-radius: var(--radius-sm); font-size: 0.88rem; width: 210px;">
        <button type="button" class="btn btn-primary btn-sm" id="btnLoadGene">Load Counts</button>
      </div>
    </div>

    <div id="inspectorLoading" style="display: none; padding: 2rem; text-align: center; color: var(--navy-subtle);">
      Streaming biopsy read counts from <code>GSE146853_filtered_counts.csv</code>...
    </div>

    <div id="inspectorResult" style="display: grid; grid-template-columns: 1fr 1.2fr; gap: 1.5rem; align-items: start;">
      <!-- Left Column: Summary & DESeq2 stats -->
      <div style="background: #f8fafc; border: 1px solid var(--border-light); border-radius: var(--radius-md); padding: 1.25rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
          <div>
            <h4 id="resGeneTitle" style="font-size: 1.35rem; color: var(--navy-dark); margin: 0;"><?= h($inspectGene) ?></h4>
            <span style="font-size: 0.78rem; color: var(--navy-subtle);">NCBI Entrez Gene ID: <code id="resEnsemblId">—</code></span>
          </div>
          <span id="resStatusBadge" class="badge badge-teal">Loading...</span>
        </div>

        <div style="font-size: 0.85rem; color: var(--navy-muted); margin-bottom: 1rem;" id="resGeneDesc">
          Select a gene to view colonic mucosal biopsy read count summaries.
        </div>

        <h5 style="font-size: 0.82rem; font-weight: 700; color: var(--navy-dark); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem;">
          Biopsy Raw Read Count Summary ($N = 68$ Quality-Filtered Samples)
        </h5>
        <table style="width: 100%; border-collapse: collapse; font-size: 0.82rem; margin-bottom: 1rem;" id="cohortSummaryTable">
          <thead>
            <tr style="border-bottom: 1px solid var(--border-light); text-align: left; color: var(--navy-subtle);">
              <th style="padding: 0.35rem 0;">Cohort / Subtype</th>
              <th>N Biopsies</th>
              <th>Median</th>
              <th>Mean</th>
              <th>Min – Max</th>
            </tr>
          </thead>
          <tbody id="cohortSummaryBody">
            <!-- Populated by JS -->
          </tbody>
        </table>

        <div style="border-top: 1px solid var(--border-light); padding-top: 0.75rem; font-size: 0.8rem; color: var(--navy-subtle);">
          <strong>Tissue:</strong> Sigmoid Colon Mucosal Biopsy &bull; <strong>Source:</strong> <code>GSE146853</code>
        </div>
      </div>

      <!-- Right Column: Pure SVG Distribution Chart -->
      <div style="background: white; border: 1px solid var(--border-light); border-radius: var(--radius-md); padding: 1.25rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
          <span style="font-size: 0.85rem; font-weight: 700; color: var(--navy-dark);">Raw Read Count Distribution by Subtype</span>
          <span style="font-size: 0.75rem; color: var(--navy-subtle);">Unnormalized read counts (GSE146853, N=68)</span>
        </div>
        <div id="chartContainer" style="width: 100%; height: 260px;">
          <svg id="countChartSvg" width="100%" height="100%" viewBox="0 0 500 240" style="overflow: visible;"></svg>
        </div>
        <div style="display: flex; justify-content: center; gap: 1.5rem; margin-top: 0.75rem; font-size: 0.78rem;">
          <span style="display: inline-flex; align-items: center; gap: 0.35rem;">
            <span style="width: 10px; height: 10px; border-radius: 50%; background: #10b981;"></span> Healthy Control (n=22)
          </span>
          <span style="display: inline-flex; align-items: center; gap: 0.35rem;">
            <span style="width: 10px; height: 10px; border-radius: 50%; background: #ef4444;"></span> IBS-D / Diarrhea (n=29)
          </span>
          <span style="display: inline-flex; align-items: center; gap: 0.35rem;">
            <span style="width: 10px; height: 10px; border-radius: 50%; background: #f59e0b;"></span> IBS-C / Constipation (n=17)
          </span>
        </div>
      </div>
    </div>
  </section>

  <!-- Table 1: Top 15 Significant DE Genes with Full DESeq2 Provenance -->
  <section class="table-card" style="margin-bottom:1.75rem;">
    <div class="table-card-header" style="flex-wrap:wrap; gap:0.75rem;">
      <div>
        <h3>1. The 15 Statistically Significant Differentially Expressed Genes (BH FDR <code>padj &lt; 0.05</code>)</h3>
        <div style="font-size:0.78rem; color:var(--navy-subtle);">
          Source: <code>data/NeuroGI_Atlas_annotated_genes.csv</code> &bull; Contrast: <strong>IBS-D vs Healthy Control</strong> &bull; Tissue: <strong>Sigmoid Colon Mucosal Biopsy</strong>
        </div>
      </div>
      <div style="display:flex; gap:0.5rem;">
        <span class="badge badge-teal">DESeq2 Wald Test (lfcSE &amp; stat Preserved)</span>
        <a href="genes.php?export=de_csv" class="btn btn-outline btn-sm">Export CSV</a>
      </div>
    </div>
    <div class="table-scroll">
      <table class="data-table">
        <thead>
          <tr>
            <th>Gene Symbol</th>
            <th>NCBI Entrez ID</th>
            <th>Gene Name</th>
            <th>Contrast &amp; Tissue</th>
            <th>Base Mean</th>
            <th>log2FC</th>
            <th>Std Error (<code>lfcSE</code>)</th>
            <th>Wald <code>stat</code></th>
            <th>Raw <em>p</em>-value</th>
            <th>BH Adj. <em>padj</em></th>
            <th>Direction</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sigGenes as $g): ?>
            <?php
              $sym = (string)($g['gene_symbol'] ?: $g['gene_id']);
              $lfc = (float)$g['log2FoldChange'];
              $lfcSe = isset($g['lfcSE']) && $g['lfcSE'] !== null ? (float)$g['lfcSE'] : null;
              $wStat = isset($g['stat']) && $g['stat'] !== null ? (float)$g['stat'] : null;
            ?>
            <tr>
              <td><strong><?= h($sym) ?></strong></td>
              <td><code><?= h((string)$g['gene_id']) ?></code></td>
              <td style="max-width: 220px; font-size:0.82rem;"><?= h((string)($g['gene_description'] ?? '')) ?></td>
              <td style="font-size:0.76rem; color:var(--navy-muted);">
                <strong><?= h((string)($g['contrast_definition'] ?? 'IBS-D vs Healthy Control')) ?></strong><br>
                <?= h((string)($g['tissue'] ?? 'Sigmoid Colon Biopsy')) ?>
              </td>
              <td><?= number_format((float)$g['baseMean'], 2) ?></td>
              <td style="font-weight: 700; color: <?= $lfc > 0 ? '#15803d' : '#be123c' ?>;">
                <?= $lfc > 0 ? '+' : '' ?><?= number_format($lfc, 3) ?>
              </td>
              <td><code><?= $lfcSe !== null ? number_format($lfcSe, 3) : '—' ?></code></td>
              <td><code><?= $wStat !== null ? number_format($wStat, 3) : '—' ?></code></td>
              <td><code><?= sprintf('%.2e', (float)$g['pvalue']) ?></code></td>
              <td><code style="font-weight: 700; color: var(--teal-dark);"><?= sprintf('%.2e', (float)$g['padj']) ?></code></td>
              <td>
                <span class="badge <?= str_contains((string)$g['expression_change'], 'Up') || str_contains((string)$g['expression_change'], 'Higher') || $lfc > 0 ? 'badge-green' : 'badge-rose' ?>">
                  <?= $lfc > 0 ? 'Up in IBS-D' : 'Down in IBS-D' ?>
                </span>
              </td>
              <td>
                <div style="display:flex; gap:0.35rem;">
                  <button type="button" class="btn btn-outline btn-sm" style="padding:0.2rem 0.5rem; font-size:0.75rem;" onclick="loadGene('<?= h($sym) ?>')">
                    Plot Counts
                  </button>
                  <a href="analysis.php?study=MARS_GSE146853&amp;x=butyrate&amp;y=<?= urlencode($sym) ?>" class="btn btn-outline btn-sm" style="padding:0.2rem 0.5rem; font-size:0.75rem;" title="Correlate with paired biopsy GC-MS Butyrate">
                    SCFA Corr
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- Table 2: Pathway Enrichment with Full Provenance & Pleiotropic Annotation Warning -->
  <section class="table-card" style="margin-bottom:1.75rem;">
    <div class="table-card-header" style="flex-wrap:wrap; gap:0.75rem;">
      <div>
        <h3>2. Gene Ontology (GO:BP) Over-Representation Analysis (<code>clusterProfiler</code>)</h3>
        <div style="font-size:0.78rem; color:var(--navy-subtle);">
          <strong>Database:</strong> GO Biological Process (<code>org.Hs.eg.db</code>) &bull;
          <strong>ID Mapping:</strong> NCBI Entrez Gene ID &harr; HGNC Symbol &bull;
          <strong>Background Universe:</strong> $N = 14,893$ expressed mucosal genes (<code>BgRatio = k/14893</code>) &bull;
          <strong>Method:</strong> One-sided hypergeometric test with BH FDR
        </div>
      </div>
      <div style="display:flex; gap:0.5rem;">
        <?php if ($showAllGo): ?>
          <a href="genes.php" class="btn btn-outline btn-sm">Show Curated Top 6 Terms</a>
        <?php else: ?>
          <a href="genes.php?all_go=1" class="btn btn-outline btn-sm">Show All 63 Source GO Terms</a>
        <?php endif; ?>
        <a href="genes.php?export=go_csv" class="btn btn-outline btn-sm">Download GO CSV</a>
      </div>
    </div>

    <div class="dq-alert" style="margin:1rem 1.25rem 0;">
      <strong>Methodological Note on Pleiotropic Gene Ontology Terms:</strong>
      Because the input query set is small ($n = 15$ FDR-significant genes) and includes epithelial sodium/magnesium channels and desmosomal proteins (<code>SCNN1B</code>, <code>PKP2</code>, <code>TRPM6</code>) that also carry cardiac conduction annotations in <code>org.Hs.eg.db</code>, standard genome-wide GO over-representation surfaces pleiotropic terms such as <code>GO:0086002 (cardiac muscle cell action potential)</code> alongside mucosal ion transport and T-cell regulation. In colonic biopsy tissue, these genes reflect <strong>epithelial electrolyte/water transport and barrier integrity</strong>, not cardiac physiology.
    </div>

    <div class="table-scroll" style="padding-top:0.5rem;">
      <table class="data-table">
        <thead>
          <tr>
            <th>GO Term ID</th>
            <th>Biological Process Description</th>
            <th>Gene Ratio</th>
            <th>Background Ratio</th>
            <th>Fold Enrichment</th>
            <th><em>z</em>-Score</th>
            <th>Raw <em>p</em>-value</th>
            <th>BH Adj. <em>p</em></th>
            <th>Storey <em>q</em>-value</th>
            <th>Mapped Symbols / IDs</th>
          </tr>
        </thead>
        <tbody>
          <?php $activeGoList = ($showAllGo && !empty($goSigAll)) ? $goSigAll : $goTerms; ?>
          <?php foreach ($activeGoList as $go): ?>
            <tr>
              <td><code><?= h((string)$go['go_id']) ?></code></td>
              <td><strong><?= h((string)$go['description']) ?></strong></td>
              <td><code><?= h((string)$go['gene_ratio']) ?></code></td>
              <td><code><?= h((string)($go['bg_ratio'] ?? '—/14893')) ?></code></td>
              <td><strong><?= number_format((float)$go['fold_enrichment'], 2) ?>&times;</strong></td>
              <td><?= isset($go['z_score']) ? number_format((float)$go['z_score'], 2) : '—' ?></td>
              <td><code><?= sprintf('%.2e', (float)$go['pvalue']) ?></code></td>
              <td><code style="font-weight:700; color:var(--teal-dark);"><?= sprintf('%.2e', (float)$go['p_adjust']) ?></code></td>
              <td><code><?= isset($go['qvalue']) ? sprintf('%.2e', (float)$go['qvalue']) : '—' ?></code></td>
              <td><span class="badge badge-teal"><?= h((string)$go['gene_ids']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- Section 3: Search Across All 29,767 Genes -->
  <section class="table-card" style="margin-bottom:2rem;">
    <div class="table-card-header">
      <div>
        <h3>3. Query Full DESeq2 Summary Catalog (29,767 Profiled Genes in <code>gene_results</code>)</h3>
        <div style="font-size:0.78rem; color:var(--navy-subtle);">
          Note: The full 29,767-row repository extract (<code>GSE146853_all_genes_results_symbol.csv</code>) stores <code>baseMean</code>, <code>log2FoldChange</code>, <code>pvalue</code>, and <code>padj</code>; <code>lfcSE</code> and Wald <code>stat</code> are available for the 15 annotated FDR-significant genes.
        </div>
      </div>
    </div>
    <div style="padding: 1.25rem;">
      <form method="GET" action="genes.php" style="display: flex; gap: 0.5rem; margin-bottom: 1.25rem;">
        <input type="search" name="gene_q" value="<?= h($geneSearch) ?>" placeholder="Search by HGNC symbol (e.g. SLC26A3, AQP8, TIGIT, TPH1, SERT) or NCBI Entrez Gene ID..." style="flex: 1; padding: 0.65rem 1rem; border: 1px solid var(--border-light); border-radius: var(--radius-sm); font-size: 0.92rem;">
        <button type="submit" class="btn btn-primary">Search 29,767 Genes</button>
        <?php if ($geneSearch !== ''): ?>
          <a href="genes.php" class="btn btn-outline">Clear</a>
        <?php endif; ?>
      </form>

      <?php if ($geneSearch !== ''): ?>
        <?php if (empty($geneSearchResults)): ?>
          <p style="color: var(--navy-subtle); font-style: italic;">No genes matched "<?= h($geneSearch) ?>".</p>
        <?php else: ?>
          <div class="table-scroll">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Symbol</th>
                  <th>NCBI Entrez ID</th>
                  <th>Gene Name</th>
                  <th>Contrast</th>
                  <th>baseMean</th>
                  <th>log2FC</th>
                  <th>lfcSE</th>
                  <th>p-value</th>
                  <th>BH padj</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($geneSearchResults as $gr): ?>
                  <?php $sym = (string)($gr['SYMBOL'] ?: ($gr['gene_symbol'] ?: $gr['gene_id'])); ?>
                  <tr>
                    <td><strong><?= h($sym) ?></strong></td>
                    <td><code><?= h((string)$gr['gene_id']) ?></code></td>
                    <td><?= h((string)$gr['GENENAME']) ?></td>
                    <td style="font-size:0.76rem;"><?= h((string)($gr['contrast_definition'] ?? 'IBS-D vs HC')) ?></td>
                    <td><?= number_format((float)$gr['baseMean'], 2) ?></td>
                    <td style="font-weight:600; color: <?= (float)$gr['log2FoldChange'] > 0 ? '#15803d' : '#be123c' ?>;">
                      <?= (float)$gr['log2FoldChange'] > 0 ? '+' : '' ?><?= number_format((float)$gr['log2FoldChange'], 3) ?>
                    </td>
                    <td><code><?= $gr['lfcSE'] !== null ? number_format((float)$gr['lfcSE'], 3) : 'Not in 29k extract' ?></code></td>
                    <td><code><?= sprintf('%.2e', (float)$gr['pvalue']) ?></code></td>
                    <td><code><?= $gr['padj'] !== null ? sprintf('%.2e', (float)$gr['padj']) : 'NA' ?></code></td>
                    <td>
                      <button type="button" class="btn btn-outline btn-sm" onclick="loadGene('<?= h($sym) ?>')">
                        Plot Counts
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>

</div>

<script>
function loadGene(symbol) {
  document.getElementById('geneSearchInput').value = symbol;
  document.getElementById('btnLoadGene').click();
  document.getElementById('count-inspector').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

document.addEventListener('DOMContentLoaded', () => {
  const btn = document.getElementById('btnLoadGene');
  const input = document.getElementById('geneSearchInput');
  const loading = document.getElementById('inspectorLoading');
  const result = document.getElementById('inspectorResult');

  btn.addEventListener('click', async () => {
    const gene = input.value.trim();
    if (!gene) return;

    loading.style.display = 'block';
    result.style.opacity = '0.4';

    try {
      const res = await fetch('api/gene_counts.php?gene=' + encodeURIComponent(gene));
      const data = await res.json();

      if (!res.ok || !data.found) {
        alert(data.message || data.error || 'Gene not found in filtered count matrix.');
        return;
      }

      renderInspector(data);
    } catch (err) {
      alert('Error streaming gene counts: ' + err.message);
    } finally {
      loading.style.display = 'none';
      result.style.opacity = '1';
    }
  });

  function renderInspector(data) {
    const g = data.gene;
    document.getElementById('resGeneTitle').textContent = g.gene_name || g.gene_id;
    document.getElementById('resEnsemblId').textContent = g.gene_id;

    const de = data.deseq2;
    const badge = document.getElementById('resStatusBadge');
    const desc = document.getElementById('resGeneDesc');
    if (de) {
      badge.textContent = de.expression_change || (de.log2FoldChange > 0 ? 'Up in IBS-D (FDR < 0.05)' : 'Down in IBS-D (FDR < 0.05)');
      badge.className = 'badge ' + (de.log2FoldChange > 0 ? 'badge-green' : 'badge-rose');
      desc.textContent = (de.gene_description || 'Differentially expressed gene') + ' | log2FC = ' + Number(de.log2FoldChange).toFixed(3) + ', padj = ' + Number(de.padj).toExponential(2);
    } else {
      badge.textContent = 'FDR padj >= 0.05 in IBS-D vs HC';
      badge.className = 'badge badge-navy';
      desc.textContent = 'Sample count distribution across 68 quality-filtered colonic mucosal biopsies (GSE146853).';
    }

    const tbody = document.getElementById('cohortSummaryBody');
    tbody.innerHTML = '';
    const cohorts = ['Healthy', 'Diarrhea', 'Constipation'];
    cohorts.forEach(c => {
      const s = g.cohort_summary[c];
      if (!s) return;
      const tr = document.createElement('tr');
      tr.style.borderBottom = '1px dotted var(--border-light)';
      tr.innerHTML = `
        <td style="padding:0.4rem 0; font-weight:600;">${c}</td>
        <td>${s.n}</td>
        <td>${s.median}</td>
        <td>${s.mean}</td>
        <td>${s.min} – ${s.max}</td>
      `;
      tbody.appendChild(tr);
    });

    renderSvgDistribution(g.samples);
  }

  function renderSvgDistribution(samples) {
    const svg = document.getElementById('countChartSvg');
    svg.innerHTML = '';

    const width = 500, height = 240;
    const padLeft = 55, padRight = 30, padTop = 20, padBottom = 35;
    const chartW = width - padLeft - padRight;
    const chartH = height - padTop - padBottom;

    const maxVal = Math.max(...samples.map(s => s.count), 10);
    const yMax = Math.ceil(maxVal * 1.15);

    const yTicks = 4;
    for (let i = 0; i <= yTicks; i++) {
      const val = Math.round((yMax / yTicks) * i);
      const y = padTop + chartH - (i / yTicks) * chartH;

      const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
      line.setAttribute('x1', padLeft);
      line.setAttribute('x2', width - padRight);
      line.setAttribute('y1', y);
      line.setAttribute('y2', y);
      line.setAttribute('stroke', '#e2e8f0');
      line.setAttribute('stroke-dasharray', i === 0 ? 'none' : '3,3');
      svg.appendChild(line);

      const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
      text.setAttribute('x', padLeft - 8);
      text.setAttribute('y', y + 4);
      text.setAttribute('font-size', '10');
      text.setAttribute('text-anchor', 'end');
      text.setAttribute('fill', '#64748b');
      text.textContent = val.toLocaleString();
      svg.appendChild(text);
    }

    const cohortX = {
      'Healthy': padLeft + chartW * 0.2,
      'Diarrhea': padLeft + chartW * 0.5,
      'Constipation': padLeft + chartW * 0.8
    };

    const cohortColors = {
      'Healthy': '#10b981',
      'Diarrhea': '#ef4444',
      'Constipation': '#f59e0b'
    };

    Object.entries(cohortX).forEach(([cName, cx]) => {
      const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
      text.setAttribute('x', cx);
      text.setAttribute('y', height - 10);
      text.setAttribute('font-size', '12');
      text.setAttribute('font-weight', '600');
      text.setAttribute('text-anchor', 'middle');
      text.setAttribute('fill', '#1e293b');
      text.textContent = cName;
      svg.appendChild(text);
    });

    samples.forEach((s, idx) => {
      const baseX = cohortX[s.cohort];
      if (!baseX) return;
      // Deterministic jitter based on index so chart is reproducible across reloads
      const jitter = (((idx * 37) % 100) / 100 - 0.5) * 42;
      const x = baseX + jitter;
      const y = padTop + chartH - (s.count / yMax) * chartH;

      const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
      circle.setAttribute('cx', x);
      circle.setAttribute('cy', y);
      circle.setAttribute('r', '4.5');
      circle.setAttribute('fill', cohortColors[s.cohort] || '#64748b');
      circle.setAttribute('opacity', '0.8');
      circle.setAttribute('stroke', '#ffffff');
      circle.setAttribute('stroke-width', '1');

      const title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
      title.textContent = `${s.sample} (${s.cohort}): ${s.count} raw counts`;
      circle.appendChild(title);

      svg.appendChild(circle);
    });
  }

  btn.click();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
