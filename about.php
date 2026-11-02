<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$pageTitle = 'Provenance, Pipeline, Ethics & Licensing | NeuroGI Atlas';
$activeNav = 'about';

$db = pdo();
$studies = $db->query("SELECT * FROM prov_studies ORDER BY study_code ASC")->fetchAll();
$migrations = $db->query("SELECT * FROM schema_migrations ORDER BY version ASC")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="page-hero-inner">
    <div class="hero-badge" style="margin-bottom:0.6rem;">PROVENANCE, PIPELINE, ETHICS &amp; LICENSING</div>
    <h2>About NeuroGI Atlas &amp; Provenance Governance</h2>
    <p>
      NeuroGI Atlas is a scientifically transparent, reproducible <strong>Multimodal Gut–Brain Evidence Platform</strong> investigating study-scoped neuroimaging, clinical symptom distress, colonic mucosal transcriptomics, and metabolomics in Disorders of Gut–Brain Interaction (DGBI / IBS).
    </p>
  </div>
</section>

<div class="container">
  <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem; align-items: start;">
    <div>

      <!-- Scientific Governance Rules -->
      <section class="table-card" style="padding: 1.5rem; margin-bottom: 1.75rem;">
        <h3 style="font-size: 1.25rem; color: var(--navy-dark); margin-bottom: 0.65rem;">
          Non-Negotiable Scientific Governance Rules
        </h3>
        <p style="color: var(--navy-muted); margin-bottom: 0.85rem; font-size:0.88rem; line-height: 1.65;">
          To prevent common bioinformatics portal anti-patterns—such as joining unrelated cohorts on string IDs, conflating generic IBS with subtype-specific IBS-D/IBS-C, or replacing missing values with zero—NeuroGI Atlas enforces strict architectural guardrails:
        </p>
        <ul style="margin-left: 1.35rem; color: var(--navy-muted); font-size:0.86rem; line-height: 1.75;">
          <li><strong>Study-Scoped Participant Identity:</strong> Every participant is scoped to their originating study (<code>BERGEN_BGA:BGA_xxx</code> vs. <code>MARS_GSE146853:10007xxx</code>). Separate cohorts are never treated as matched participants.</li>
          <li><strong>Demographic Concordance Verification:</strong> Overlapping IDs across tables within the same study are verified for Sex, Age, and Group concordance before matched analysis. Conflicting records (<code>BGA_072</code> sex conflict, <code>BGA_070</code> 6-yr age discrepancy) are quarantined and excluded by default.</li>
          <li><strong>Preservation of Raw &amp; Missing Values:</strong> Original imported rows are preserved untouched alongside JSON source payloads in <code>prov_source_records</code>. Missing values (<code>NULL</code> in Bergen `Education`, `0.00` placeholder in `GSM4914057` age/BMI) are never conflated with true zero scores (`IBS_SSS = 0` in asymptomatic controls).</li>
          <li><strong>Corrected Primary Source Attribution:</strong> Colonic biopsy RNA-seq (`GSE146853`), GC-MS biopsy metabolites (`Supplementary Data III`), and stool NMR metabolites (`Supplementary Data II`) are properly attributed to <strong>Mars et al., <em>Cell</em> 2020 (DOI: <code>10.1016/j.cell.2020.08.007</code>, PMID: <code>32916129</code>)</strong>, correcting the legacy v1.0 mis-citation.</li>
        </ul>
      </section>

      <!-- Registered Studies Table -->
      <section class="table-card" id="provenance" style="margin-bottom: 1.75rem;">
        <div class="table-card-header">
          <h3>Registered Studies &amp; Primary Citations (<code>prov_studies</code>)</h3>
          <span class="badge badge-teal"><?= count($studies) ?> Registered Studies</span>
        </div>
        <div class="table-scroll">
          <table class="data-table">
            <thead>
              <tr>
                <th>Study Code</th>
                <th>Title &amp; Originating Institution</th>
                <th>Accession / DOI / PMID</th>
                <th>Design &amp; Cohort Scope</th>
                <th>Licence</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($studies as $st): ?>
                <tr>
                  <td><code><?= h($st['study_code']) ?></code></td>
                  <td style="font-size:0.82rem;">
                    <strong><?= h($st['short_name']) ?></strong><br>
                    <span style="color:var(--navy-muted);"><?= h($st['title']) ?></span><br>
                    <span style="color:var(--navy-subtle); font-size:0.76rem;"><?= h($st['institution'] ?? '') ?> (<?= h($st['country'] ?? '') ?>)</span>
                  </td>
                  <td style="font-size:0.78rem;">
                    <?php if (!empty($st['primary_accession'])): ?>
                      Acc: <code><?= h($st['primary_accession']) ?></code><br>
                    <?php endif; ?>
                    <?php if (!empty($st['doi'])): ?>
                      DOI: <a href="https://doi.org/<?= h($st['doi']) ?>" target="_blank" rel="noopener"><?= h($st['doi']) ?> &#8599;</a><br>
                    <?php endif; ?>
                    <?php if (!empty($st['pmid'])): ?>
                      PMID: <a href="https://pubmed.ncbi.nlm.nih.gov/<?= h($st['pmid']) ?>/" target="_blank" rel="noopener"><?= h($st['pmid']) ?> &#8599;</a>
                    <?php endif; ?>
                  </td>
                  <td style="font-size:0.78rem; color:var(--navy-muted);">
                    <strong><?= h($st['study_design'] ?? '') ?></strong><br>
                    <?= h($st['cohort_scope'] ?? '') ?>
                  </td>
                  <td style="font-size:0.78rem;"><?= h($st['license'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Bioinformatics & Statistical Pipeline -->
      <section class="table-card" id="pipeline" style="padding: 1.5rem; margin-bottom: 1.75rem;">
        <h3 style="font-size: 1.2rem; color: var(--navy-dark); margin-bottom: 0.75rem;">
          Bioinformatics, Statistical &amp; Migration Pipeline
        </h3>
        <div style="display: flex; flex-direction: column; gap: 0.75rem; font-size:0.85rem; color:var(--navy-muted);">
          <div style="background: #f8fafc; border: 1px solid var(--border-light); border-radius: var(--radius-sm); padding: 0.85rem;">
            <strong style="color: var(--navy-dark);">1. Versioned Non-Destructive Schema Migration (`migrations/001_provenance_schema.sql` &amp; `run_migrations.php`):</strong>
            Extends the original 16 MySQL tables with 15 provenance and real-data tables (`prov_studies`, `prov_datasets`, `prov_dataset_versions`, `prov_participants`, `prov_visits`, `prov_measurement_defs`, `prov_source_records`, `prov_measurements`, `prov_participant_links`, `prov_data_quality_issues`, `prov_evidence_edges`, `prov_analysis_runs`, `mars_gcms_biopsy_metabolites`, `mars_nmr_stool_metabolites`, `kvasir_seg_local_assets`) without deleting a single original row.
          </div>
          <div style="background: #f8fafc; border: 1px solid var(--border-light); border-radius: var(--radius-sm); padding: 0.85rem;">
            <strong style="color: var(--navy-dark);">2. GSE146853 DESeq2 &amp; GO Enrichment Provenance:</strong>
            Raw read counts across 68 quality-filtered biopsies were screened for $\ge 10$ counts in $\ge 3$ samples ($29,767$ transcripts; $14,893$ expressed mucosal background universe) and modeled via DESeq2 negative binomial Wald tests (`IBS-D vs Healthy Control`), identifying 15 FDR-significant genes (`padj < 0.05`) with `lfcSE` and `stat` preserved.
          </div>
          <div style="background: #f8fafc; border: 1px solid var(--border-light); border-radius: var(--radius-sm); padding: 0.85rem;">
            <strong style="color: var(--navy-dark);">3. Exact Statistical Engine (`includes/stats.php`):</strong>
            Implements exact regularized incomplete beta ($I_x(a,b)$) Student-$t$ two-tailed $p$-values, Fisher-$z$ 95% confidence intervals, tie-corrected Spearman rank correlation, Gaussian-elimination OLS linear regression with partial correlation $r_{XY \cdot C}$, and Benjamini–Hochberg FDR / Holm–Bonferroni multiple-testing corrections.
          </div>
        </div>
      </section>

      <!-- Ethics & Research Disclaimer -->
      <section class="table-card" id="ethics" style="padding: 1.5rem; margin-bottom: 2rem;">
        <h3 style="font-size: 1.2rem; color: var(--navy-dark); margin-bottom: 0.65rem;">
          Ethics, Data Protection &amp; Non-Clinical Disclaimer
        </h3>
        <p style="font-size: 0.86rem; color: var(--navy-muted); line-height: 1.65; margin-bottom: 0.75rem;">
          All datasets integrated in NeuroGI Atlas derive from publicly released, de-identified open-science repositories (`NCBI GEO`, `GitHub/Zenodo CC-BY 4.0`, `Simula Research Laboratory CC-BY 4.0`, `OpenNeuro CC0 1.0`) approved by their respective institutional review boards (Mayo Clinic IRB for `GSE146853`; Regional Committee for Medical and Health Research Ethics West-Norway for `BERGEN_BGA`).
        </p>
        <div class="dq-alert" style="margin-bottom:0;">
          <strong>Non-Clinical Research Disclaimer:</strong> NeuroGI Atlas is an academic bioinformatics and exploratory data analysis platform. Outputs, correlations, and evidence graphs must never be interpreted as causal clinical proof, diagnostic criteria, or medical treatment advice.
        </div>
      </section>

    </div>

    <!-- Sidebar -->
    <div>
      <div class="table-card" id="citation" style="padding: 1.5rem; margin-bottom: 1.5rem;">
        <h4 style="font-size: 1.05rem; color: var(--navy-dark); margin-bottom: 0.5rem;">
          Citing NeuroGI Atlas &amp; Primary Cohorts
        </h4>
        <div style="background: #f8fafc; border: 1px solid var(--border-light); border-radius: var(--radius-sm); padding: 0.75rem; font-size: 0.76rem; font-family: var(--font-mono); color: var(--navy-dark); line-height: 1.5; margin-bottom: 0.75rem;">
          1. Mars RAT, Yang Y, Ward T, et al. Longitudinal Multi-omics Reveals Subset-Specific Mechanisms Underlying Irritable Bowel Syndrome. Cell. 2020;182(6):1460-1473.e17. doi:10.1016/j.cell.2020.08.007 (GSE146853)<br><br>
          2. Lundervold AJ, et al. Bergen Brain-Gut-Microbiota Axis Study (PSN-IBS &amp; ibs-distress). University of Bergen; CC-BY 4.0.<br><br>
          3. Jha D, Smedsrud PH, Riegler MA, et al. Kvasir-SEG: A Segmented Polyp Dataset. MMM 2020.
        </div>
      </div>

      <div class="table-card" style="padding: 1.5rem; margin-bottom: 1.5rem;">
        <h4 style="font-size: 1.05rem; color: var(--navy-dark); margin-bottom: 0.5rem;">
          Applied Schema Migrations
        </h4>
        <?php foreach ($migrations as $m): ?>
          <div style="font-size:0.8rem; color:var(--navy-muted); border-bottom:1px solid var(--border-light); padding-bottom:0.5rem; margin-bottom:0.5rem;">
            <strong>Version <code><?= h($m['version']) ?></code></strong><br>
            <?= h($m['description']) ?><br>
            <span style="font-size:0.72rem; color:var(--navy-subtle);">Applied: <?= h((string)$m['applied_at']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="table-card" style="padding: 1.5rem;">
        <h4 style="font-size: 1.05rem; color: var(--navy-dark); margin-bottom: 0.5rem;">
          Platform Technical Stack
        </h4>
        <ul style="font-size: 0.83rem; color: var(--navy-muted); line-height: 1.75; list-style: none;">
          <li>&bull; <strong>Runtime:</strong> PHP <?= h(PHP_VERSION) ?> (Strict Types)</li>
          <li>&bull; <strong>Database:</strong> MySQL / MariaDB (31 Tables in <code>neurogi_atlas</code>)</li>
          <li>&bull; <strong>Security:</strong> PDO Prepared Statements, `h()` Output Escaping, Path-Traversal Guards</li>
          <li>&bull; <strong>Statistics:</strong> Pure PHP Exact Incomplete-Beta &amp; OLS Engine + R/Python Script Generators</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
