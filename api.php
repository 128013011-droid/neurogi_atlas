<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if ($db->connect_errno) {
    http_response_code(500);
    echo json_encode(['error' => 'Cannot connect to database: ' . $db->connect_error]);
    exit;
}
$db->set_charset('utf8mb4');

function ident(string $s): string { return '`' . str_replace('`', '``', $s) . '`'; }
function like_escape(string $s): string { return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s); }
function fail(string $msg): void {
    http_response_code(500);
    echo json_encode(['error' => $msg]);
    exit;
}

/* ---- Read the table/column structure automatically ---- */
$schema = [];
$sql = "SELECT c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE
        FROM information_schema.COLUMNS c
        JOIN information_schema.TABLES t
          ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
        WHERE c.TABLE_SCHEMA = ? AND t.TABLE_TYPE = 'BASE TABLE'
        ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION";
$stmt = $db->prepare($sql);
$dbname = DB_NAME;
$stmt->bind_param('s', $dbname);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    if (in_array($r['TABLE_NAME'], HIDDEN_TABLES, true)) continue;
    $schema[$r['TABLE_NAME']][] = ['name' => $r['COLUMN_NAME'], 'type' => strtolower($r['DATA_TYPE'])];
}
$stmt->close();

/* ---- Read the request ---- */
$q     = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$words = array_slice(array_values(array_filter(preg_split('/\s+/u', $q))), 0, 6);
$only  = (string)($_GET['table'] ?? '');
if ($q === '' && $only === '') {   // nothing typed: return no data
    echo json_encode(['query' => '', 'words' => [], 'single' => false, 'page' => 1, 'limit' => 5, 'results' => []]);
    exit;
}
$page  = max(1, (int)($_GET['page'] ?? 1));

if ($only !== '' && isset($schema[$only])) {   // one table, paged
    $tables = [$only];
    $limit  = 25;
    $offset = ($page - 1) * $limit;
    $single = true;
} else {                                       // all tables, short preview
    $tables = array_keys($schema);
    $limit  = 5;
    $offset = 0;
    $single = false;
}

$skipTypes = ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'geometry'];
$results = [];
$skipped = [];

foreach ($tables as $table) {
    $cols = $schema[$table];

    // one "CAST(column AS CHAR)" per searchable column
    $exprs = [];
    foreach ($cols as $c) {
        if (!in_array($c['type'], $skipTypes, true)) $exprs[] = 'CAST(' . ident($c['name']) . ' AS CHAR)';
    }

    // every typed word must appear in at least one column of the row
    $where = '';
    $types = '';
    $params = [];
    if ($words && $exprs) {
        $parts = [];
        foreach ($words as $w) {
            $like = '%' . like_escape($w) . '%';
            $ors = [];
            foreach ($exprs as $e) { $ors[] = "$e LIKE ?"; $params[] = $like; $types .= 's'; }
            $parts[] = '(' . implode(' OR ', $ors) . ')';
        }
        $where = 'WHERE ' . implode(' AND ', $parts);
    }

    // total matches
    $stmt = $db->prepare('SELECT COUNT(*) FROM ' . ident($table) . " $where");
    if (!$stmt) { $skipped[] = $table . ': ' . $db->error; continue; }
    if ($params) $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) { $skipped[] = $table . ': ' . $stmt->error; $stmt->close(); continue; }
    $total = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    if ($total === 0) continue;

    // rows
    $stmt = $db->prepare('SELECT * FROM ' . ident($table) . " $where LIMIT ? OFFSET ?");
    if (!$stmt) { $skipped[] = $table . ': ' . $db->error; continue; }
    $p2 = array_merge($params, [$limit, $offset]);
    $stmt->bind_param($types . 'ii', ...$p2);
    if (!$stmt->execute()) { $skipped[] = $table . ': ' . $stmt->error; $stmt->close(); continue; }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $results[] = [
        'table'   => $table,
        'total'   => $total,
        'columns' => array_column($cols, 'name'),
        'rows'    => $rows,
    ];
}

echo json_encode([
    'query'   => $q,
    'words'   => $words,
    'single'  => $single,
    'page'    => $page,
    'limit'   => $limit,
    'tables_checked' => count($tables),
    'skipped' => $skipped,
    'results' => $results,
], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
