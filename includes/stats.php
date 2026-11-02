<?php
declare(strict_types=1);

/**
 * NeuroGI Atlas — Reproducible Statistical Computation Engine
 * Implements verified mathematical routines for:
 *  - Descriptive statistics (N, missing, mean, SD, median, Q1, Q3, IQR, min, max)
 *  - Pearson linear correlation (r, Fisher z 95% CI, exact Student-t p-value, OLS slope)
 *  - Spearman rank correlation (rho with average-rank tie handling, 95% CI, p-value)
 *  - Multivariable OLS linear regression for covariate adjustment & partial correlation
 *  - Benjamini-Hochberg FDR and Holm-Bonferroni multiple-testing corrections
 */

/**
 * Log-Gamma function via Lanczos approximation (g=7, n=9), accurate to ~1e-15.
 */
function stats_lgamma(float $z): float {
    $coeffs = [
        0.99999999999980993,
        676.5203681218851,
        -1259.1392167224028,
        771.32342877765313,
        -176.61502916214059,
        12.507343278686905,
        -0.13857109526572012,
        9.9843695780195716e-6,
        1.5056327351493116e-7
    ];
    if ($z < 0.5) {
        return log(M_PI / sin(M_PI * $z)) - stats_lgamma(1.0 - $z);
    }
    $z -= 1.0;
    $x = $coeffs[0];
    for ($i = 1; $i < 9; $i++) {
        $x += $coeffs[$i] / ($z + $i);
    }
    $t = $z + 7.5;
    return 0.5 * log(2.0 * M_PI) + ($z + 0.5) * log($t) - $t + log($x);
}

/**
 * Continued fraction for regularized incomplete beta function I_x(a, b) via Modified Lentz's method.
 */
function stats_betacf(float $a, float $b, float $x): float {
    $maxIter = 200;
    $eps = 3.0e-14;
    $fpmin = 1.0e-30;

    $qab = $a + $b;
    $qap = $a + 1.0;
    $qam = $a - 1.0;
    $c = 1.0;
    $d = 1.0 - $qab * $x / $qap;
    if (abs($d) < $fpmin) $d = $fpmin;
    $d = 1.0 / $d;
    $h = $d;

    for ($m = 1; $m <= $maxIter; $m++) {
        $m2 = 2 * $m;
        $aa = $m * ($b - $m) * $x / (($qam + $m2) * ($a + $m2));
        $d = 1.0 + $aa * $d;
        if (abs($d) < $fpmin) $d = $fpmin;
        $c = 1.0 + $aa / $c;
        if (abs($c) < $fpmin) $c = $fpmin;
        $d = 1.0 / $d;
        $h *= $d * $c;

        $aa = -($a + $m) * ($qab + $m) * $x / (($a + $m2) * ($qap + $m2));
        $d = 1.0 + $aa * $d;
        if (abs($d) < $fpmin) $d = $fpmin;
        $c = 1.0 + $aa / $c;
        if (abs($c) < $fpmin) $c = $fpmin;
        $d = 1.0 / $d;
        $del = $d * $c;
        $h *= $del;
        if (abs($del - 1.0) < $eps) break;
    }
    return $h;
}

/**
 * Regularized incomplete beta function I_x(a, b).
 */
function stats_betai(float $a, float $b, float $x): float {
    if ($x <= 0.0) return 0.0;
    if ($x >= 1.0) return 1.0;
    $bt = exp(
        stats_lgamma($a + $b) - stats_lgamma($a) - stats_lgamma($b)
        + $a * log($x) + $b * log(1.0 - $x)
    );
    if ($x < ($a + 1.0) / ($a + $b + 2.0)) {
        return $bt * stats_betacf($a, $b, $x) / $a;
    }
    return 1.0 - $bt * stats_betacf($b, $a, 1.0 - $x) / $b;
}

/**
 * Exact two-tailed p-value for a Student-t statistic with $df degrees of freedom.
 */
function stats_student_t_pvalue(float $t, float $df): float {
    if ($df <= 0.0) return 1.0;
    if (!is_finite($t)) return 0.0;
    $x = $df / ($df + $t * $t);
    return max(0.0, min(1.0, stats_betai(0.5 * $df, 0.5, $x)));
}

/**
 * Critical value t_{1 - alpha/2, df} via Newton/bisection inversion of stats_student_t_pvalue.
 */
function stats_student_t_crit(float $df, float $alpha = 0.05): float {
    if ($df <= 0) return 1.95996398454;
    $low = 0.0;
    $high = 50.0;
    for ($i = 0; $i < 60; $i++) {
        $mid = 0.5 * ($low + $high);
        $p = stats_student_t_pvalue($mid, $df);
        if ($p > $alpha) {
            $low = $mid;
        } else {
            $high = $mid;
        }
    }
    return 0.5 * ($low + $high);
}

/**
 * Linear interpolation quantile (R type 7 / SciPy default).
 */
function stats_quantile(array $sortedVals, float $q): float {
    $n = count($sortedVals);
    if ($n === 0) return NAN;
    if ($n === 1) return (float)$sortedVals[0];
    $pos = ($n - 1) * $q;
    $base = (int)floor($pos);
    $rest = $pos - $base;
    if (isset($sortedVals[$base + 1])) {
        return (float)$sortedVals[$base] + $rest * ((float)$sortedVals[$base + 1] - (float)$sortedVals[$base]);
    }
    return (float)$sortedVals[$base];
}

/**
 * Computes comprehensive descriptive statistics for a numeric array.
 */
function stats_descriptive(array $values, int $missingCount = 0): array {
    $clean = [];
    foreach ($values as $v) {
        if ($v === null || $v === '' || !is_numeric($v) || is_nan((float)$v)) {
            $missingCount++;
        } else {
            $clean[] = (float)$v;
        }
    }
    $n = count($clean);
    if ($n === 0) {
        return [
            'n' => 0, 'missing' => $missingCount,
            'mean' => null, 'sd' => null, 'median' => null,
            'q1' => null, 'q3' => null, 'iqr' => null,
            'min' => null, 'max' => null, 'is_constant' => true
        ];
    }
    sort($clean, SORT_NUMERIC);
    $mean = array_sum($clean) / $n;
    $ss = 0.0;
    foreach ($clean as $v) {
        $d = $v - $mean;
        $ss += $d * $d;
    }
    $sd = ($n > 1) ? sqrt($ss / ($n - 1)) : 0.0;
    $median = stats_quantile($clean, 0.5);
    $q1 = stats_quantile($clean, 0.25);
    $q3 = stats_quantile($clean, 0.75);
    $min = $clean[0];
    $max = $clean[$n - 1];

    return [
        'n' => $n,
        'missing' => $missingCount,
        'mean' => $mean,
        'sd' => $sd,
        'median' => $median,
        'q1' => $q1,
        'q3' => $q3,
        'iqr' => $q3 - $q1,
        'min' => $min,
        'max' => $max,
        'is_constant' => ($sd < 1.0e-12 || $n < 2)
    ];
}

/**
 * Assigns average ranks for ties (1-based) to a numeric array.
 */
function stats_rank_average(array $vals): array {
    $n = count($vals);
    $indexed = [];
    foreach ($vals as $idx => $v) {
        $indexed[] = ['idx' => $idx, 'val' => (float)$v];
    }
    usort($indexed, fn($a, $b) => $a['val'] <=> $b['val']);

    $ranks = array_fill(0, $n, 0.0);
    $i = 0;
    while ($i < $n) {
        $j = $i;
        while ($j + 1 < $n && abs($indexed[$j + 1]['val'] - $indexed[$i]['val']) <= 1.0e-12) {
            $j++;
        }
        $avgRank = 0.5 * ($i + $j) + 1.0;
        for ($k = $i; $k <= $j; $k++) {
            $ranks[$indexed[$k]['idx']] = $avgRank;
        }
        $i = $j + 1;
    }
    return $ranks;
}

/**
 * Computes exact Pearson linear correlation r, Fisher z 95% CI, Student-t p-value, and OLS line.
 */
function stats_pearson(array $x, array $y): array {
    $n = min(count($x), count($y));
    $warnings = [];
    if ($n < 3) {
        $msg = 'Insufficient paired observations (n < 3) to compute correlation.';
        return [
            'valid' => false,
            'status' => 'error_insufficient_n',
            'method' => 'Pearson product-moment correlation (r)',
            'n' => $n,
            'estimate' => null,
            'ci_low' => null,
            'ci_high' => null,
            'ci_method' => 'Fisher z-transformation (atanh/tanh, SE = 1/sqrt(n-3))',
            't_stat' => null,
            'df' => $n - 2,
            'p_value' => null,
            'slope' => null,
            'intercept' => null,
            'r_squared' => null,
            'warning' => $msg,
            'warnings' => [$msg]
        ];
    }
    if ($n < 10) {
        $warnings[] = "Small sample size (n = $n < 10): confidence intervals are wide and normality assumptions are sensitive to outliers.";
    }

    $mx = array_sum($x) / $n;
    $my = array_sum($y) / $n;
    $sxx = 0.0;
    $syy = 0.0;
    $sxy = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $dx = (float)$x[$i] - $mx;
        $dy = (float)$y[$i] - $my;
        $sxx += $dx * $dx;
        $syy += $dy * $dy;
        $sxy += $dx * $dy;
    }

    if ($sxx <= 1.0e-18 || $syy <= 1.0e-18) {
        $msg = 'One or both variables have zero variance (constant values); correlation is undefined.';
        return [
            'valid' => false,
            'status' => 'error_constant_variable',
            'method' => 'Pearson product-moment correlation (r)',
            'n' => $n,
            'estimate' => null,
            'ci_low' => null,
            'ci_high' => null,
            'ci_method' => 'Fisher z-transformation',
            't_stat' => null,
            'df' => $n - 2,
            'p_value' => null,
            'slope' => 0.0,
            'intercept' => $my,
            'r_squared' => 0.0,
            'warning' => $msg,
            'warnings' => [$msg]
        ];
    }

    $r = max(-1.0, min(1.0, $sxy / sqrt($sxx * $syy)));
    $slope = $sxy / $sxx;
    $intercept = $my - $slope * $mx;
    $df = $n - 2;

    if (abs($r) >= 0.999999999999) {
        $tStat = ($r > 0 ? 1.0e12 : -1.0e12);
        $pVal = 0.0;
        $ciLow = $r;
        $ciHigh = $r;
    } else {
        $tStat = $r * sqrt($df / (1.0 - $r * $r));
        $pVal = stats_student_t_pvalue($tStat, (float)$df);
        if ($n > 3) {
            $z = 0.5 * log((1.0 + $r) / (1.0 - $r));
            $seZ = 1.0 / sqrt($n - 3.0);
            $zLow = $z - 1.959963984540054 * $seZ;
            $zHigh = $z + 1.959963984540054 * $seZ;
            $ciLow = tanh($zLow);
            $ciHigh = tanh($zHigh);
        } else {
            $ciLow = null;
            $ciHigh = null;
            $warnings[] = 'Confidence interval requires n >= 4 for Fisher z transformation.';
        }
    }

    return [
        'valid' => true,
        'status' => 'ok',
        'method' => 'Pearson product-moment correlation (r)',
        'n' => $n,
        'estimate' => $r,
        'ci_low' => $ciLow,
        'ci_high' => $ciHigh,
        'ci_method' => 'Fisher z-transformation (z = atanh(r), SE = 1/sqrt(n-3), 95% normal limits)',
        't_stat' => $tStat,
        'df' => $df,
        'p_value' => $pVal,
        'slope' => $slope,
        'intercept' => $intercept,
        'r_squared' => $r * $r,
        'warning' => implode(' ', $warnings),
        'warnings' => $warnings
    ];
}

/**
 * Computes Spearman rank correlation rho (with average-rank tie handling), 95% CI, and p-value.
 */
function stats_spearman(array $x, array $y): array {
    $n = min(count($x), count($y));
    if ($n < 3) {
        $msg = 'Insufficient paired observations (n < 3) to compute Spearman rank correlation.';
        return [
            'valid' => false,
            'status' => 'error_insufficient_n',
            'method' => 'Spearman rank-order correlation (rho)',
            'n' => $n,
            'estimate' => null,
            'ci_low' => null,
            'ci_high' => null,
            'ci_method' => 'Fisher z-transformation on rank correlation',
            't_stat' => null,
            'df' => $n - 2,
            'p_value' => null,
            'slope' => null,
            'intercept' => null,
            'r_squared' => null,
            'warning' => $msg,
            'warnings' => [$msg]
        ];
    }

    $rx = stats_rank_average(array_values($x));
    $ry = stats_rank_average(array_values($y));
    $res = stats_pearson($rx, $ry);

    // Also compute raw-scale OLS line for plotting reference
    $rawPearson = stats_pearson(array_values($x), array_values($y));
    $res['method'] = 'Spearman rank-order correlation (rho, tie-adjusted average ranks)';
    $res['ci_method'] = 'Fisher z-transformation on rank correlation (z = atanh(rho), SE = 1/sqrt(n-3))';
    $res['slope'] = $rawPearson['slope'];
    $res['intercept'] = $rawPearson['intercept'];
    return $res;
}

/**
 * Solves A * x = b using Gaussian elimination with partial pivoting.
 * Returns null if matrix is singular or nearly singular.
 */
function stats_solve_linear_system(array $A, array $b): ?array {
    $m = count($A);
    for ($i = 0; $i < $m; $i++) {
        $A[$i][] = $b[$i];
    }
    for ($col = 0; $col < $m; $col++) {
        $pivotRow = $col;
        $maxVal = abs($A[$col][$col]);
        for ($row = $col + 1; $row < $m; $row++) {
            if (abs($A[$row][$col]) > $maxVal) {
                $maxVal = abs($A[$row][$col]);
                $pivotRow = $row;
            }
        }
        if ($maxVal < 1.0e-12) {
            return null;
        }
        if ($pivotRow !== $col) {
            $tmp = $A[$col];
            $A[$col] = $A[$pivotRow];
            $A[$pivotRow] = $tmp;
        }
        for ($row = $col + 1; $row < $m; $row++) {
            $factor = $A[$row][$col] / $A[$col][$col];
            for ($k = $col; $k <= $m; $k++) {
                $A[$row][$k] -= $factor * $A[$col][$k];
            }
        }
    }
    $x = array_fill(0, $m, 0.0);
    for ($row = $m - 1; $row >= 0; $row--) {
        $sum = $A[$row][$m];
        for ($col = $row + 1; $col < $m; $col++) {
            $sum -= $A[$row][$col] * $x[$col];
        }
        $x[$row] = $sum / $A[$row][$row];
    }
    return $x;
}

/**
 * Inverts a symmetric positive-definite matrix of size m x m.
 */
function stats_invert_matrix(array $M): ?array {
    $m = count($M);
    $inv = [];
    for ($j = 0; $j < $m; $j++) {
        $e = array_fill(0, $m, 0.0);
        $e[$j] = 1.0;
        $col = stats_solve_linear_system($M, $e);
        if ($col === null) return null;
        for ($i = 0; $i < $m; $i++) {
            $inv[$i][$j] = $col[$i];
        }
    }
    return $inv;
}

/**
 * Fits a multivariable OLS regression model: Y = beta_0 + beta_X * X + sum(gamma_k * Cov_k)
 * and computes the covariate-adjusted partial correlation r_{YX.C}, adjusted slope, SE, 95% CI, and p-value.
 *
 * @param float[] $y Dependent variable vector (length n)
 * @param float[] $x Primary predictor vector (length n)
 * @param array<string, float[]> $covariates Associative array of covariate name => numeric vector (length n)
 */
function stats_ols_covariate_adjusted(array $y, array $x, array $covariates): array {
    $n = count($y);
    $covNames = array_keys($covariates);
    $p = 1 + count($covNames); // number of predictors excluding intercept
    $cols = $p + 1;            // including intercept
    $df = $n - $cols;

    if ($df < 3) {
        $msg = "Insufficient degrees of freedom (n = $n, parameters = $cols, df = $df < 3) for covariate-adjusted model.";
        return [
            'valid' => false,
            'status' => 'error_insufficient_df',
            'reason' => $msg,
            'warning' => $msg
        ];
    }

    // Build design matrix Z: [1, x, cov_1, ..., cov_k]
    $Z = [];
    for ($i = 0; $i < $n; $i++) {
        $row = [1.0, (float)$x[$i]];
        foreach ($covNames as $cn) {
            $row[] = (float)$covariates[$cn][$i];
        }
        $Z[] = $row;
    }

    // Compute Z^T * Z and Z^T * y
    $ZtZ = array_fill(0, $cols, array_fill(0, $cols, 0.0));
    $Zty = array_fill(0, $cols, 0.0);
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $cols; $j++) {
            $Zty[$j] += $Z[$i][$j] * (float)$y[$i];
            for ($k = $j; $k < $cols; $k++) {
                $ZtZ[$j][$k] += $Z[$i][$j] * $Z[$i][$k];
            }
        }
    }
    for ($j = 0; $j < $cols; $j++) {
        for ($k = 0; $k < $j; $k++) {
            $ZtZ[$j][$k] = $ZtZ[$k][$j];
        }
    }

    $invZtZ = stats_invert_matrix($ZtZ);
    $beta = stats_solve_linear_system($ZtZ, $Zty);
    if ($invZtZ === null || $beta === null) {
        $msg = 'Design matrix is singular or collinear (e.g., a covariate has zero variance in the selected subgroup).';
        return [
            'valid' => false,
            'status' => 'error_singular_matrix',
            'reason' => $msg,
            'warning' => $msg
        ];
    }

    // Compute residuals and R^2
    $meanY = array_sum($y) / $n;
    $tss = 0.0;
    $rss = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $yHat = 0.0;
        for ($j = 0; $j < $cols; $j++) {
            $yHat += $Z[$i][$j] * $beta[$j];
        }
        $res = (float)$y[$i] - $yHat;
        $rss += $res * $res;
        $dy = (float)$y[$i] - $meanY;
        $tss += $dy * $dy;
    }

    $sigma2 = $rss / $df;
    $r2 = ($tss > 1.0e-18) ? max(0.0, 1.0 - ($rss / $tss)) : 0.0;
    $adjR2 = 1.0 - (1.0 - $r2) * (($n - 1.0) / $df);

    $tCrit = stats_student_t_crit((float)$df, 0.05);
    $paramNames = array_merge(['Intercept', 'Primary_X'], $covNames);
    $terms = [];
    foreach ($paramNames as $idx => $name) {
        $se = sqrt(max(0.0, $sigma2 * $invZtZ[$idx][$idx]));
        $tVal = ($se > 1.0e-18) ? ($beta[$idx] / $se) : 0.0;
        $pVal = ($se > 1.0e-18) ? stats_student_t_pvalue($tVal, (float)$df) : 1.0;
        $terms[$name] = [
            'beta' => $beta[$idx],
            'se' => $se,
            't_stat' => $tVal,
            'p_value' => $pVal,
            'ci_low' => $beta[$idx] - $tCrit * $se,
            'ci_high' => $beta[$idx] + $tCrit * $se,
        ];
    }

    // Partial correlation of X and Y controlling for covariates: r_partial = t_X / sqrt(t_X^2 + df)
    $tX = $terms['Primary_X']['t_stat'];
    $partialR = $tX / sqrt($tX * $tX + $df);
    $coefficients = $terms;
    $coefficients['X'] = $terms['Primary_X'];
    unset($coefficients['Primary_X']);

    return [
        'valid' => true,
        'status' => 'ok',
        'n' => $n,
        'df' => $df,
        'df_residual' => $df,
        'r_squared' => $r2,
        'adj_r_squared' => $adjR2,
        'partial_r' => $partialR,
        'partial_r_x' => $partialR,
        'primary_x' => $terms['Primary_X'],
        'terms' => $terms,
        'coefficients' => $coefficients,
        'covariates' => $covNames
    ];
}

/**
 * Applies multiple-testing correction across a family of p-values.
 * Supports 'BH_FDR' (Benjamini-Hochberg False Discovery Rate) and 'HOLM' (Holm-Bonferroni).
 *
 * @param array<string|int, float> $pValues Associative map of test_id => raw p-value
 * @param string $method 'BH_FDR' or 'HOLM'
 * @return array<string|int, float> Map of test_id => adjusted p/q-value in original key order
 */
function stats_adjust_pvalues(array $pValues, string $method = 'BH_FDR'): array {
    $m = count($pValues);
    if ($m === 0) return [];

    $items = [];
    foreach ($pValues as $k => $p) {
        $items[] = ['key' => $k, 'p' => max(0.0, min(1.0, (float)$p))];
    }
    usort($items, fn($a, $b) => $a['p'] <=> $b['p']);

    $adj = array_fill(0, $m, 1.0);
    if (strtoupper($method) === 'HOLM') {
        // Step-down Holm-Bonferroni: p_adj(i) = max_{j <= i} min(1, (m - j) * p_(j))
        $runningMax = 0.0;
        for ($i = 0; $i < $m; $i++) {
            $val = min(1.0, ($m - $i) * $items[$i]['p']);
            $runningMax = max($runningMax, $val);
            $adj[$i] = $runningMax;
        }
    } else {
        // Step-up Benjamini-Hochberg FDR: q_(i) = min_{j >= i} min(1, (m / rank_j) * p_(j))
        $runningMin = 1.0;
        for ($i = $m - 1; $i >= 0; $i--) {
            $rank = $i + 1;
            $val = min(1.0, ($m / $rank) * $items[$i]['p']);
            $runningMin = min($runningMin, $val);
            $adj[$i] = $runningMin;
        }
    }

    $byKey = [];
    for ($i = 0; $i < $m; $i++) {
        $byKey[$items[$i]['key']] = $adj[$i];
    }

    $out = [];
    foreach (array_keys($pValues) as $k) {
        $out[$k] = $byKey[$k];
    }
    return $out;
}

/**
 * Loads raw read counts from data/GSE146853_filtered_counts.csv for a list of gene symbols or Ensembl IDs.
 * Returns array: [gene_symbol_upper => [sample_title => float_count]]
 */
function stats_load_mars_gene_counts(array $targetGenes): array {
    $countsFile = dirname(__DIR__) . '/data/GSE146853_filtered_counts.csv';
    if (!file_exists($countsFile) || empty($targetGenes)) {
        return [];
    }
    $wanted = [];
    foreach ($targetGenes as $g) {
        $wanted[strtoupper(trim((string)$g))] = true;
    }

    $handle = fopen($countsFile, 'r');
    if (!$handle) {
        return [];
    }
    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return [];
    }

    $sampleCols = [];
    foreach ($header as $idx => $colName) {
        if (str_ends_with((string)$colName, '_GeneCount')) {
            $sampleTitle = preg_replace('/^s_|_GeneCount$/', '', (string)$colName);
            $sampleCols[$idx] = $sampleTitle;
        }
    }

    $result = [];
    while (($row = fgetcsv($handle)) !== false) {
        $geneId   = strtoupper(trim((string)($row[1] ?? '')));
        $geneName = strtoupper(trim((string)($row[2] ?? '')));
        if (isset($wanted[$geneId]) || isset($wanted[$geneName])) {
            $sampleMap = [];
            foreach ($sampleCols as $colIdx => $sTitle) {
                $sampleMap[$sTitle] = isset($row[$colIdx]) ? (float)$row[$colIdx] : 0.0;
            }
            if ($geneName !== '') {
                $result[$geneName] = $sampleMap;
            }
            if ($geneId !== '') {
                $result[$geneId] = $sampleMap;
            }
            if (count($result) >= count($wanted) * 2) {
                break;
            }
        }
    }
    fclose($handle);
    return $result;
}
