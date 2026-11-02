<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

$dbOnline = false;
$dbTableCount = 0;
try {
    $db = pdo();
    $dbOnline = true;
    $res = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . DB_NAME . "'");
    $dbTableCount = (int)$res->fetchColumn();
} catch (Exception $e) {
    $dbOnline = false;
}
?>
</main>

<footer class="site-footer">
  <div class="footer-inner">
    <div class="footer-col">
      <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.75rem;">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="var(--teal-primary)" stroke-width="2.2" stroke-linecap="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        <h4 style="margin:0; font-size:1.1rem; color:var(--navy-dark);">NeuroGI Research Atlas <span style="font-size:0.75rem; color:var(--teal-primary); font-weight:700;">v<?= defined('PLATFORM_VERSION') ? h(PLATFORM_VERSION) : '2.0.0' ?></span></h4>
      </div>
      <p style="font-size:0.84rem; line-height:1.6; color:var(--navy-muted); margin-bottom:0.75rem;">
        A scientifically transparent, reproducible <strong>Multimodal Gut–Brain Evidence Platform</strong> integrating study-scoped participant measurements, colonic mucosal transcriptomics &amp; metabolomics, neuroimaging, and source-traceable literature.
      </p>
      <p style="font-size:0.75rem; line-height:1.45; color:var(--navy-subtle); margin-bottom:0.85rem;">
        <strong>Research Disclaimer:</strong> For academic exploratory research only. Never interpret correlation as causation or use platform outputs for clinical diagnosis or treatment decisions.
      </p>
      <div style="display:inline-flex; align-items:center; gap:0.5rem; font-size:0.75rem; padding:0.3rem 0.65rem; border-radius:999px; background:<?= $dbOnline ? 'var(--mint-bg)' : '#fee2e2' ?>; color:<?= $dbOnline ? 'var(--teal-dark)' : '#b91c1c' ?>; font-weight:600;">
        <span style="width:8px; height:8px; border-radius:50%; background:<?= $dbOnline ? '#10b981' : '#ef4444' ?>; display:inline-block;"></span>
        <?= $dbOnline ? 'MySQL Provenance Store (' . $dbTableCount . ' Tables)' : 'Database Offline' ?>
      </div>
    </div>

    <div class="footer-col">
      <h4>Core Contributions</h4>
      <ul>
        <li><a href="participant.php">Verified Participant Explorer</a></li>
        <li><a href="analysis.php">Reproducible Brain–Symptom Analysis</a></li>
        <li><a href="evidence.php">Source-Traceable Evidence Graph</a></li>
        <li><a href="genes.php">Transcriptomics &amp; Biopsy SCFAs</a></li>
        <li><a href="validation.php">Validation, Benchmarks &amp; Limits</a></li>
      </ul>
    </div>

    <div class="footer-col">
      <h4>Multimodal Domains</h4>
      <ul>
        <li><a href="datasets.php#mri">Bergen BGA: Brain MRI &amp; RBANS</a></li>
        <li><a href="datasets.php#clinical">Bergen BGA: IBS-SSS &amp; Symptoms</a></li>
        <li><a href="genes.php">Mars et al. (GSE146853): RNA-seq</a></li>
        <li><a href="datasets.php#metabolomics">Mars et al.: GC-MS &amp; NMR Metabolites</a></li>
        <li><a href="gut_images.php">Simula: Kvasir-SEG &amp; HyperKvasir</a></li>
      </ul>
    </div>

    <div class="footer-col">
      <h4>Provenance &amp; Governance</h4>
      <ul>
        <li><a href="about.php#provenance">Study-Scoped Data Provenance</a></li>
        <li><a href="validation.php#limitations">Scientific Limitations &amp; Audit</a></li>
        <li><a href="validation.php#demo-guide">Evaluator Demonstration Guide</a></li>
        <li><a href="research.php">PubMed &amp; ClinicalTrials.gov</a></li>
        <li><a href="about.php#ethics">Ethics &amp; Open Licences</a></li>
      </ul>
    </div>
  </div>

  <div class="footer-bottom">
    <div>
      &copy; <?= date('Y') ?> NeuroGI Atlas Research Platform. All raw imported measurements preserved alongside provenance audit trails.
    </div>
    <div style="display:flex; gap:1.25rem;">
      <a href="validation.php">Validation &amp; Novelty</a>
      <a href="participant.php">Participants</a>
      <a href="analysis.php">Reproducible Analysis</a>
      <a href="about.php">Provenance</a>
    </div>
  </div>
</footer>

<script src="assets/js/main.js"></script>
</body>
</html>
