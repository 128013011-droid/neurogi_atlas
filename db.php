<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const IMAGE_TABLE = 'hyperkvasir_import_staging';

/**
 * Returns a configured PDO database connection instance.
 */
function pdo(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        throw new PDOException('Database connection failed: ' . $e->getMessage(), (int)$e->getCode());
    }

    return $pdo;
}

/**
 * Normalizes an image file path to a safe web-accessible relative URL or returns null.
 */
function web_path(?string $path): ?string {
    if (!$path) {
        return null;
    }

    $path = trim($path);
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    // Convert backslashes
    $clean = str_replace('\\', '/', $path);

    // Prevent path traversal
    if (str_contains($clean, '..')) {
        return null;
    }

    // If it's a relative path starting inside the project
    if (file_exists(__DIR__ . '/' . ltrim($clean, '/'))) {
        return ltrim($clean, '/');
    }

    // If it points to an existing file in images/masks
    if (file_exists(__DIR__ . '/images/masks/' . basename($clean))) {
        return 'images/masks/' . rawurlencode(basename($clean));
    }

    // If it points to an existing file in medical images
    if (file_exists(__DIR__ . '/medical images/' . basename($clean))) {
        return 'medical images/' . rawurlencode(basename($clean));
    }

    return null;
}

/**
 * Escapes HTML output safely.
 */
function esc_html(mixed $str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

/**
 * Short alias for esc_html().
 */
function h(mixed $str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

/**
 * Formats a numeric value while strictly preserving missing (NULL/empty) vs true zero (0).
 * Never replaces NULL with 0, and never renders 0 as 'Missing'.
 */
function fmt_num_or_missing(mixed $val, int $decimals = 2, string $suffix = '', string $missingLabel = 'Missing (NULL)'): string {
    if ($val === null || $val === '') {
        return '<span class="badge badge-amber" title="Value is missing in source record; preserved as NULL">' . esc_html($missingLabel) . '</span>';
    }
    if (!is_numeric($val)) {
        return esc_html((string)$val);
    }
    return number_format((float)$val, $decimals) . ($suffix !== '' ? esc_html($suffix) : '');
}

/**
 * Generates or retrieves a session CSRF token for state-changing requests.
 */
function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }
    if (empty($_SESSION['neurogi_csrf_token'])) {
        $_SESSION['neurogi_csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['neurogi_csrf_token'];
}

/**
 * Verifies a submitted CSRF token against the session token.
 */
function verify_csrf_token(?string $token): bool {
    if (!$token) {
        return false;
    }
    $expected = csrf_token();
    return hash_equals($expected, $token);
}

/**
 * Renders a standardized HTML badge for participant linkage verification status.
 */
function render_verification_badge(string $status): string {
    return match ($status) {
        'verified' => '<span class="badge badge-green" title="Study-scoped identity and demographics verified across datasets">✓ Verified Match</span>',
        'verified_with_visit_offset' => '<span class="badge badge-teal" title="Same-study participant verified (Group, Sex, IBS-SSS match); +1 yr age difference reflects clinical baseline vs MRI visit">✓ Verified (Visit Age Offset)</span>',
        'unresolved', 'unresolved_conflict' => '<span class="badge badge-rose" title="Conflicting demographic records across tables; excluded from matched analysis by default">⚠ Unresolved Conflict (Excluded)</span>',
        'rejected' => '<span class="badge badge-rose" title="Cross-study linkage rejected: separate cohorts cannot be joined">✗ Rejected (Separate Cohort)</span>',
        'single_table_only', 'not_applicable' => '<span class="badge badge-navy" title="Present in a single modality table within its source study">Single-Modality Record</span>',
        'unlinked_anonymized_id' => '<span class="badge badge-amber" title="Anonymized tube/sample ID without public participant mapping key">Unlinked Anonymized ID</span>',
        default => '<span class="badge badge-navy">' . esc_html($status) . '</span>',
    };
}
