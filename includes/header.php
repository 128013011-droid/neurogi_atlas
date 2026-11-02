<?php
declare(strict_types=1);

if (!isset($pageTitle)) {
    $pageTitle = 'NeuroGI Atlas | Multimodal Gut–Brain Evidence Platform';
}
if (!isset($activeNav)) {
    $activeNav = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,600;0,700;1,600&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/main.css">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><circle cx='50' cy='50' r='45' fill='%234f46e5'/><path d='M30 50 Q50 20 70 50 T90 50' stroke='white' stroke-width='8' fill='none'/></svg>">
</head>
<body>
<a href="#main-content" class="skip-link">Skip to main content</a>

<header class="site-header">
  <div class="header-inner">
    <a href="index.php" class="brand-wrap">
      <div class="brand-icon">
        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
          <circle cx="12" cy="4" r="2"/>
          <circle cx="12" cy="20" r="2"/>
        </svg>
      </div>
      <div class="brand-text">
        <h1>NeuroGI <span>Atlas</span></h1>
        <div class="brand-tagline">Multimodal Gut–Brain Evidence Platform</div>
      </div>
    </a>

    <nav aria-label="Main Navigation">
      <ul class="nav-links">
        <li>
          <a href="index.php" class="<?= $activeNav === 'home' ? 'active' : '' ?>">
            Dashboard
          </a>
        </li>
        <li>
          <a href="search.php" class="<?= $activeNav === 'search' ? 'active' : '' ?>">
            Search
          </a>
        </li>
        <li>
          <a href="participant.php" class="<?= $activeNav === 'participant' ? 'active' : '' ?>">
            Participants <span class="nav-badge">Verified</span>
          </a>
        </li>
        <li>
          <a href="analysis.php" class="<?= $activeNav === 'analysis' ? 'active' : '' ?>">
            Analysis
          </a>
        </li>
        <li>
          <a href="evidence.php" class="<?= $activeNav === 'evidence' ? 'active' : '' ?>">
            Evidence
          </a>
        </li>
        <li>
          <a href="genes.php" class="<?= $activeNav === 'genes' ? 'active' : '' ?>">
            Transcriptomics <span class="nav-badge">GSE146853</span>
          </a>
        </li>
        <li>
          <a href="datasets.php" class="<?= $activeNav === 'datasets' ? 'active' : '' ?>">
            Datasets
          </a>
        </li>
        <li>
          <a href="gut_images.php" class="<?= $activeNav === 'gut_images' ? 'active' : '' ?>">
            Endoscopy
          </a>
        </li>
        <li>
          <a href="research.php" class="<?= $activeNav === 'research' ? 'active' : '' ?>">
            Literature
          </a>
        </li>
        <li>
          <a href="validation.php" class="<?= $activeNav === 'validation' ? 'active' : '' ?>">
            Validation
          </a>
        </li>
        <li>
          <a href="about.php" class="<?= $activeNav === 'about' ? 'active' : '' ?>">
            About
          </a>
        </li>
      </ul>
    </nav>

    <div class="header-actions">
      <a href="search.php" class="btn-header-search" title="Quick Search">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        <span>Search</span>
        <kbd class="kbd-shortcut">/</kbd>
      </a>
    </div>
  </div>
</header>

<main id="main-content" class="site-main">
