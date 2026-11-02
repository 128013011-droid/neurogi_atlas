<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$pageTitle = 'Dashboard | NeuroGI Atlas — Multimodal Gut–Brain Evidence Platform';
$activeNav = 'home';

$db = pdo();
$stats = [
    'bga_verified'    => (int)$db->query("SELECT COUNT(*) FROM prov_participants WHERE study_code = 'BERGEN_BGA' AND identity_verification_status IN ('verified', 'verified_with_visit_offset')")->fetchColumn(),
    'bga_total'       => (int)$db->query("SELECT COUNT(*) FROM prov_participants WHERE study_code = 'BERGEN_BGA'")->fetchColumn(),
    'mars_paired'     => (int)$db->query("SELECT COUNT(*) FROM mars_gcms_biopsy_metabolites WHERE matched_rnaseq_geo IS NOT NULL AND matched_rnaseq_geo != ''")->fetchColumn(),
    'rna_samples'     => (int)$db->query("SELECT COUNT(*) FROM sample_metadata WHERE geo_accession != 'geo_accession'")->fetchColumn(),
    'sig_genes'       => (int)$db->query("SELECT COUNT(*) FROM significant_genes")->fetchColumn(),
    'nmr_stool'       => (int)$db->query("SELECT COUNT(*) FROM mars_nmr_stool_metabolites")->fetchColumn(),
    'evidence_edges'  => (int)$db->query("SELECT COUNT(*) FROM prov_evidence_edges")->fetchColumn(),
    'dq_flags'        => (int)$db->query("SELECT COUNT(*) FROM prov_data_quality_issues")->fetchColumn(),
];

$sigGenes = $db->query("SELECT * FROM significant_genes ORDER BY padj ASC LIMIT 8")->fetchAll();
$recentPubs = $db->query("SELECT * FROM pubmed_articles ORDER BY id ASC LIMIT 3")->fetchAll();
$recentTrials = $db->query("SELECT * FROM clinical_trials ORDER BY id ASC LIMIT 3")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="page-hero-inner">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 2rem; flex-wrap: wrap;">
      <div style="max-width: 800px;">
        <div class="hero-badge" style="margin-bottom: 0.75rem;">
          PROVENANCE-AUDITED &bull; MULTIMODAL GUT–BRAIN EVIDENCE PLATFORM
        </div>
        <h2>Scientifically Transparent Gut–Brain Axis Research</h2>
        <p>
          NeuroGI Atlas integrates <strong>study-scoped participant measurements</strong> across structural brain MRI &amp; clinical symptoms (Bergen Brain-Gut Study, <code>BERGEN_BGA</code>) and longitudinal colonic mucosal transcriptomics &amp; GC-MS metabolomics (Mars et al., <em>Cell</em> 2020, <code>GSE146853</code>), backed by a source-traceable evidence graph and reproducible statistical engine.
        </p>
      </div>
      <div style="display: flex; gap: 0.6rem; flex-wrap: wrap; align-self: center;">
        <a href="participant.php" class="btn btn-primary">Participant Explorer</a>
        <a href="analysis.php" class="btn btn-outline">Reproducible Analysis</a>
        <a href="evidence.php" class="btn btn-outline">Evidence Graph</a>
        <a href="validation.php" class="btn btn-outline">Worked Example &amp; Limits</a>
      </div>
    </div>
  </div>
</section>

<div class="container">

  <!-- Three Core Scientific Contributions Banner -->
  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(310px, 1fr)); gap: 1.25rem; margin-bottom: 1.75rem;">
    <div class="card" style="border-top: 4px solid var(--teal-primary);">
      <div class="card-title">Contribution 1: Verified Participant-Level Integration</div>
      <div class="card-body">
        Study-scoped participant identifiers (<code>BERGEN_BGA:BGA_xxx</code> and <code>MARS_GSE146853:10007xxx</code>) with explicit demographic concordance checks.
        In <code>BERGEN_BGA</code>, <strong>54 exact + 5 visit-offset participants</strong> are verified across Brain MRI and IBS-SSS, while <strong>2 demographic conflicts</strong> (<code>BGA_072</code> sex mismatch, <code>BGA_070</code> 6-yr age gap) are quarantined. In <code>MARS_GSE146853</code>, <strong>66 colonic biopsies</strong> are paired across RNA-seq and GC-MS SCFAs.
      </div>
      <div class="card-footer">
        <span class="badge badge-green">Zero Cross-Cohort Fabrication</span>
        <a href="participant.php" style="font-weight:600; font-size:0.84rem;">Open Participant Explorer &rarr;</a>
      </div>
    </div>

    <div class="card" style="border-top: 4px solid #2563eb;">
      <div class="card-title">Contribution 2: Reproducible Exploratory Analysis</div>
      <div class="card-body">
        Interactive statistical engine computing exact Pearson $r$, Spearman $\rho$, Fisher-$z$ 95% CIs, multivariable OLS regression (`Age`, `Sex`, `Education`, `eTIV`), and Benjamini–Hochberg / Holm multiple-testing corrections across 32 brain regions or 15 DE genes, with live CSV, JSON manifest, R, and Python exports.
      </div>
      <div class="card-footer">
        <span class="badge badge-blue">Exact Stats + R/Python Exports</span>
        <a href="analysis.php" style="font-weight:600; font-size:0.84rem;">Run Matched Analysis &rarr;</a>
      </div>
    </div>

    <div class="card" style="border-top: 4px solid #d97706;">
      <div class="card-title">Contribution 3: Source-Traceable Evidence Exploration</div>
      <div class="card-body">
        Curated gut–brain relationships classified into <strong>5 transparent epistemological categories</strong> (same-cohort measured, cross-study literature, curated contextual, computational hypothesis, and contradictory/null findings), each storing verbatim supporting passages and limitations without invented numeric scores.
      </div>
      <div class="card-footer">
        <span class="badge badge-amber"><?= $stats['evidence_edges'] ?> Verified Edges</span>
        <a href="evidence.php" style="font-weight:600; font-size:0.84rem;">Open Evidence Explorer &rarr;</a>
      </div>
    </div>
  </div>

  <!-- Live Provenance-Scoped Metrics Grid -->
  <section class="waterfall-grid" style="margin-bottom: 2rem;">
    <div class="waterfall-step">
      <div class="wf-label">Bergen Verified Matched</div>
      <div class="wf-num"><?= $stats['bga_verified'] ?> <span style="font-size:0.85rem;font-weight:500;color:var(--navy-subtle);">/ <?= $stats['bga_total'] ?></span></div>
      <div class="wf-desc">54 exact + 5 (+1yr MRI offset); 2 conflicts excluded</div>
    </div>
    <div class="waterfall-step">
      <div class="wf-label">Mars Paired Biopsies</div>
      <div class="wf-num"><?= $stats['mars_paired'] ?> <span style="font-size:0.85rem;font-weight:500;color:var(--navy-subtle);">/ <?= $stats['rna_samples'] ?></span></div>
      <div class="wf-desc">Same-visit RNA-seq + GC-MS SCFA biopsies</div>
    </div>
    <div class="waterfall-step">
      <div class="wf-label">GSE146853 DE Genes</div>
      <div class="wf-num"><?= $stats['sig_genes'] ?> <span style="font-size:0.85rem;font-weight:500;color:var(--navy-subtle);">/ 29,767</span></div>
      <div class="wf-desc">BH FDR <code>padj &lt; 0.05</code> (IBS-D vs HC)</div>
    </div>
    <div class="waterfall-step">
      <div class="wf-label">Stool NMR Profiles</div>
      <div class="wf-num"><?= number_format($stats['nmr_stool']) ?></div>
      <div class="wf-desc">19 metabolites (Anonymized tube IDs)</div>
    </div>
    <div class="waterfall-step">
      <div class="wf-label">Audited DQ Flags</div>
      <div class="wf-num"><?= $stats['dq_flags'] ?></div>
      <div class="wf-desc">Preserved raw values + documented policies</div>
    </div>
  </section>

  <!-- Global Search Box -->
  <div class="table-card" style="padding: 1.25rem; margin-bottom: 2rem;">
    <form action="search.php" method="GET">
      <div style="display: flex; gap: 0.6rem; flex-wrap: wrap;">
        <input type="search" name="q" placeholder="Search across study-scoped participants (BGA_046, BGA_072), mucosal genes (SLC26A3, TIGIT), brain regions (Thalamus), or trials..." style="flex: 1; min-width: 260px; padding: 0.65rem 1rem; border: 1px solid var(--border-light); border-radius: var(--radius-sm); font-size: 0.92rem;">
        <button type="submit" class="btn btn-primary">Search Atlas</button>
      </div>
      <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.85rem; font-size: 0.8rem; color: var(--navy-subtle); flex-wrap: wrap;">
        <strong style="color:var(--navy-dark);">Quick Audit Queries:</strong>
        <a href="search.php?q=IBS" class="badge badge-teal" style="text-decoration:none;">Disease: IBS</a>
        <a href="search.php?cohort=IBS-D" class="badge badge-amber" style="text-decoration:none;">Subtype: IBS-D (Strict Mapping)</a>
        <a href="participant.php?uid=BERGEN_BGA:BGA_072" class="badge badge-rose" style="text-decoration:none;">BGA_072 (Sex Conflict)</a>
        <a href="participant.php?uid=BERGEN_BGA:BGA_097" class="badge badge-amber" style="text-decoration:none;">BGA_097 (+1Yr Age Offset)</a>
        <a href="genes.php?inspect=SLC26A3" class="badge badge-green" style="text-decoration:none;">Gene: SLC26A3</a>
        <a href="genes.php?inspect=TIGIT" class="badge badge-green" style="text-decoration:none;">Gene: TIGIT</a>
        <a href="validation.php" class="badge badge-blue" style="text-decoration:none;">Worked Research Example</a>
      </div>
    </form>
  </div>

  <!-- Significant Differentially Expressed Genes Preview -->
  <section class="table-card" style="margin-bottom: 2rem;">
    <div class="table-card-header">
      <div>
        <h3>GSE146853 Statistically Significant Differentially Expressed Genes (Top 8 of 15, BH FDR <code>padj &lt; 0.05</code>)</h3>
        <div style="font-size:0.78rem; color:var(--navy-subtle);">
          Primary Study: Mars et al., <em>Cell</em> 2020 (DOI: <code>10.1016/j.cell.2020.08.007</code>) &bull; Contrast: <strong>IBS-D vs Healthy Control</strong> &bull; Tissue: <strong>Sigmoid Colon Mucosal Biopsy</strong>
        </div>
      </div>
      <a href="genes.php" class="btn btn-outline btn-sm">View All 15 Genes &amp; GO Provenance &rarr;</a>
    </div>
    <div class="table-scroll">
      <table class="data-table">
        <thead>
          <tr>
            <th>Gene Symbol</th>
            <th>NCBI Entrez ID</th>
            <th>Gene Name</th>
            <th>Base Mean</th>
            <th>log2FC (&plusmn;lfcSE)</th>
            <th>BH Adj. <em>padj</em></th>
            <th>Direction (IBS-D vs HC)</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sigGenes as $g): ?>
            <?php
              $sym = (string)($g['gene_symbol'] ?: $g['gene_id']);
              $lfc = (float)$g['log2FoldChange'];
            ?>
            <tr>
              <td><strong><?= h($sym) ?></strong></td>
              <td><code><?= h((string)$g['gene_id']) ?></code></td>
              <td style="max-width: 260px; font-size:0.82rem;"><?= h((string)($g['gene_description'] ?? '')) ?></td>
              <td><?= number_format((float)$g['baseMean'], 2) ?></td>
              <td style="font-weight: 600; color: <?= $lfc > 0 ? '#15803d' : '#be123c' ?>;">
                <?= sprintf('%+.3f', $lfc) ?>
                <?php if (isset($g['lfcSE']) && $g['lfcSE'] !== null): ?>
                  <span style="font-weight:400; color:var(--navy-subtle); font-size:0.78rem;">(&plusmn;<?= number_format((float)$g['lfcSE'], 3) ?>)</span>
                <?php endif; ?>
              </td>
              <td><code><?= sprintf('%.2e', (float)$g['padj']) ?></code></td>
              <td>
                <span class="badge <?= $lfc > 0 ? 'badge-green' : 'badge-rose' ?>">
                  <?= $lfc > 0 ? 'Up in IBS-D' : 'Down in IBS-D' ?>
                </span>
              </td>
              <td>
                <a href="genes.php?inspect=<?= urlencode($sym) ?>" class="btn btn-outline btn-sm" style="padding:0.2rem 0.55rem; font-size:0.76rem;">Plot Counts</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- Literature & Trials Dual Section -->
  <section style="display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
    <div class="table-card" style="margin-bottom:0;">
      <div class="table-card-header">
        <h3>Curated PubMed Literature (2025-02 Snapshot)</h3>
        <a href="research.php" class="btn btn-outline btn-sm">View 100 Articles &rarr;</a>
      </div>
      <div style="padding: 1rem 1.25rem;">
        <?php foreach ($recentPubs as $p): ?>
          <div style="margin-bottom: 0.9rem; padding-bottom: 0.9rem; border-bottom: 1px solid var(--border-light);">
            <div style="display:flex; justify-content: space-between; align-items: flex-start; gap: 0.5rem; margin-bottom: 0.3rem;">
              <h4 style="font-size: 0.9rem; color: var(--navy-dark); font-weight: 600; line-height: 1.35;">
                <a href="https://pubmed.ncbi.nlm.nih.gov/<?= urlencode((string)$p['pmid']) ?>/" target="_blank" rel="noopener">
                  <?= h((string)$p['title']) ?> &#8599;
                </a>
              </h4>
              <span class="badge badge-teal"><?= h((string)$p['year']) ?></span>
            </div>
            <div style="font-size: 0.76rem; color: var(--navy-subtle);">
              PMID: <code><?= h((string)$p['pmid']) ?></code> &bull; Bibliographic Context (Not Participant-Linked)
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="table-card" style="margin-bottom:0;">
      <div class="table-card-header">
        <h3>ClinicalTrials.gov Registry (2025-02 Snapshot)</h3>
        <a href="research.php#trials" class="btn btn-outline btn-sm">View 100 Trials &rarr;</a>
      </div>
      <div style="padding: 1rem 1.25rem;">
        <?php foreach ($recentTrials as $t): ?>
          <div style="margin-bottom: 0.9rem; padding-bottom: 0.9rem; border-bottom: 1px solid var(--border-light);">
            <div style="display:flex; justify-content: space-between; align-items: flex-start; gap: 0.5rem; margin-bottom: 0.3rem;">
              <h4 style="font-size: 0.9rem; color: var(--navy-dark); font-weight: 600; line-height: 1.35;">
                <a href="https://clinicaltrials.gov/study/<?= urlencode((string)$t['nct_id']) ?>" target="_blank" rel="noopener">
                  <?= h((string)$t['title']) ?> &#8599;
                </a>
              </h4>
              <span class="badge badge-navy"><?= h((string)$t['status']) ?> (Snapshot)</span>
            </div>
            <div style="font-size: 0.76rem; color: var(--navy-subtle);">
              NCT ID: <code><?= h((string)$t['nct_id']) ?></code> &bull; Sponsor: <?= h((string)($t['sponsor'] ?: 'Not Specified')) ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
