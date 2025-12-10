<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/fungsi.php';

// Hanya kasir dan di atasnya yang bisa mengakses
require_role(ROLE_KASIR);

header('Content-Type: application/json');

// Ambil query pencarian, pastikan minimal 1 karakter
$term = $_GET['q'] ?? '';
if (strlen($term) < 1) {
    echo json_encode([]);
    exit;
}

$pdo = get_db_connection();

$searchTerm = '%' . $term . '%';

// Cari member berdasarkan nama atau kode member
$stmt = $pdo->prepare("
    SELECT id, member_code, name, points_balance
    FROM members
    WHERE (name LIKE :term OR member_code LIKE :term) AND status = 'active'
    ORDER BY name ASC
    LIMIT 15
");

$stmt->execute([':term' => $searchTerm]);
$members = $stmt->fetchAll();

// Format hasil untuk ditampilkan di pencarian
$results = array_map(function($member) {
    return [
        'id' => $member['id'],
        'text' => $member['member_code'] . ' - ' . $member['name'],
        'points' => (int)$member['points_balance']
    ];
}, $members);

// Kembalikan sebagai JSON
echo json_encode($results);
