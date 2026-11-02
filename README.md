# NeuroGI Atlas v2.0 — Multimodal Gut–Brain Evidence Platform

A scientifically transparent, reproducible PHP/MySQL research platform integrating study-scoped participant measurements, colonic mucosal transcriptomics and GC-MS metabolomics, structural brain MRI volumetrics, RBANS cognition, clinical symptom distress (IBS-SSS), and source-traceable literature/clinical trial evidence.

---

## Core Scientific Contributions

1. **Verified Participant-Level Integration (`participant.php`)**:
   - Study-scoped participant identifiers (`BERGEN_BGA:BGA_xxx` and `MARS_GSE146853:10007xxx`).
   - Automated demographic concordance verification across overlapping tables (`brain_measurements` and `clinical_symptom_data`):
     - **54 exact concordant matches** (`verified`).
     - **5 verified +1-year MRI visit-offset matches** (`BGA_083, BGA_097, BGA_128, BGA_152, BGA_156`, marked `verified_with_visit_offset`).
     - **2 unresolved demographic conflicts quarantined** (`BGA_072` sex mismatch `M` vs `F`; `BGA_070` 6-year age discrepancy `28` vs `22.00`, displayed side-by-side unmerged and excluded from matched analysis by default).
     - **33 single-modality Bergen participants** (`17` MRI-only, `16` Clinical-only).
   - **66 paired colonic mucosal biopsy visits** in `MARS_GSE146853` linking `GSE146853` RNA-seq with `Supplementary Data III` GC-MS short-chain fatty acids (`mars_gcms_biopsy_metabolites`).

2. **Reproducible Exploratory Statistical Engine (`analysis.php` & `includes/stats.php`)**:
   - Interactive study-scoped bivariate correlation (`Pearson r` with Fisher-$z$ 95% CI, `Spearman ρ` with average-rank tie handling) and multivariable Ordinary Least Squares (`OLS`) covariate adjustment (`Age`, `Sex`, `Education`, `eTIV`) with partial correlation $r_{XY \cdot C}$.
   - Transparent 7-step sample eligibility & exclusion waterfall.
   - Explicit FreeSurfer `eTIV` normalization provenance (preventing silent double-normalization of pre-normalized regional volume proportions).
   - Benjamini–Hochberg FDR (`BH_FDR`) and Holm–Bonferroni (`HOLM`) step-down multiple-testing correction across all 32 brain MRI measures or 15 DE genes.
   - Live downloadable **CSV matched data**, **JSON analysis manifest** (with SHA-256 run hash), **standalone R script (`.R`)**, and **standalone Python script (`.py`)**.

3. **Source-Traceable Cross-Study Evidence Explorer (`evidence.php`)**:
   - Interactive SVG Network Graph and WCAG-accessible Evidence Table classifying relationships into **5 explicit epistemological categories**:
     1. `Same-cohort measured association`
     2. `Cross-study literature-supported relationship`
     3. `Curated contextual link`
     4. `Computational hypothesis`
     5. `Contradictory or inconclusive evidence`
   - Stores verbatim supporting passages, study population, tissue/modality, effect estimates, and limitations without fabricated numerical confidence scores.

---

## Architecture & Page Inventory

- `index.php` — Platform Dashboard & Core Contributions Overview
- `search.php` — Cross-Domain Provenance Search (with "Why It Matched", strict `IBS` vs `IBS-D`/`IBS-C` subtype boundaries, filter applicability warnings, and de-duplicated participant counters)
- `participant.php` — Verified Participant Explorer & Visit Coverage Matrix
- `analysis.php` — Reproducible Brain–Symptom & Mucosal Biopsy Analysis Engine (+ CSV/JSON/R/Python Exports)
- `evidence.php` — Source-Traceable Evidence Explorer (SVG Graph + Accessible Table + Verbatim Passage Inspector)
- `genes.php` — `GSE146853` Transcriptomics Explorer (with `lfcSE`, Wald `stat`, contrast/tissue provenance, GO background universe $N=14,893$, pleiotropic term note, and CSV exports)
- `datasets.php` — Master Dataset Inventory, SHA-256 Checksums, Real GC-MS Biopsy (`74` rows) & Stool NMR (`464` rows) Metabolomics, and 17-item Data-Quality Audit Registry
- `gut_images.php` — Endoscopy Reference Benchmark (`1,000` local Kvasir-SEG masks + SVG bounding boxes in `kvasir_seg_local_assets` and `1,000` staged HyperKvasir records, explicitly labeled as non-IBS reference data)
- `research.php` — Curated PubMed Literature (`100` articles), ClinicalTrials.gov (`100` trials with snapshot date caveats), and GEO Candidates (`22` studies)
- `validation.php` — End-to-End Worked Research Example, Competitor Comparison Matrix, Scientific Limitations, Academic Contribution Statement, and Evaluator Demo Guide
- `about.php` — Provenance Governance, Primary Citations (Mars et al., *Cell* 2020, DOI `10.1016/j.cell.2020.08.007`), Pipeline, and Ethics

---

## Setup, Configuration & Versioned Migrations

### 1. Environment Variables (`config.php`)
Database credentials default to standard local XAMPP settings (`127.0.0.1:3306`, `neurogi_atlas`, user `root`, empty password) and can be overridden via environment variables:
- `NEUROGI_DB_HOST`
- `NEUROGI_DB_PORT`
- `NEUROGI_DB_NAME`
- `NEUROGI_DB_USER`
- `NEUROGI_DB_PASS`

### 2. Running the Provenance Migration
To apply the non-destructive schema migration (`migrations/001_provenance_schema.sql`) and populate all 15 provenance and real-data tables (`prov_*`, `mars_gcms_biopsy_metabolites`, `mars_nmr_stool_metabolites`, `kvasir_seg_local_assets`):
```powershell
C:\xampp\php\php.exe migrations/run_migrations.php
```

### 3. Running Automated Verification & Scientific Integrity Tests
```powershell
C:\xampp\php\php.exe tests/test_scientific_integrity.php
C:\xampp\php\php.exe tests/test_endpoints.php
```
