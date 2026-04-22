<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

function status_id_from_label(string $label): ?int {
  $stmt = db()->prepare("SELECT id FROM project_statuses WHERE label = ? LIMIT 1");
  $stmt->bind_param('s', $label);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $row ? (int)$row['id'] : null;
}

function save_uploaded_files(): array {
  if (empty($_FILES['photos'])) return [];
  $uploads = $_FILES['photos'];
  $saved = [];
  $baseDir = dirname(__DIR__) . '/uploads';
  if (!is_dir($baseDir)) mkdir($baseDir, 0775, true);
  $subDir = $baseDir . '/' . date('Y') . '/' . date('m');
  if (!is_dir($subDir)) mkdir($subDir, 0775, true);

  $count = is_array($uploads['name']) ? count($uploads['name']) : 0;
  for ($i = 0; $i < $count; $i++) {
    if ((int)$uploads['error'][$i] !== UPLOAD_ERR_OK) continue;
    $tmp = $uploads['tmp_name'][$i];
    $orig = (string)$uploads['name'][$i];
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) continue;
    $file = uniqid('p_', true) . '.' . $ext;
    $target = $subDir . '/' . $file;
    if (move_uploaded_file($tmp, $target)) {
      $saved[] = 'uploads/' . date('Y') . '/' . date('m') . '/' . $file;
    }
  }
  return $saved;
}

function insert_project_photos(int $projectId, array $photos): void {
  $stmt = db()->prepare("INSERT INTO project_photos (project_id, photo_url, sort_order, is_cover) VALUES (?, ?, ?, ?)");
  foreach (array_values($photos) as $i => $url) {
    $u = trim((string)$url);
    if ($u === '') continue;
    $order = $i;
    $isCover = $i === 0 ? 1 : 0;
    $stmt->bind_param('isii', $projectId, $u, $order, $isCover);
    $stmt->execute();
  }
  $stmt->close();
}

function normalize_photo_url(string $url): string {
  $u = trim($url);
  if ($u === '') return '';
  if (preg_match('/^data:/i', $u)) return '';
  if (preg_match('/[\x00-\x1F\x7F]/', $u)) return '';
  if (preg_match('/\s/', $u)) return '';
  if (preg_match('/^https?:\/\/[^\s]+$/i', $u)) return $u;
  if (preg_match('/^\/?[A-Za-z0-9._\-\/]+$/', $u)) return ltrim($u, '/');
  return '';
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST' && (($_POST['_method'] ?? '') === 'PUT')) {
  $method = 'PUT';
}
if ($method === 'POST' && (($_POST['_method'] ?? '') === 'DELETE')) {
  $method = 'DELETE';
}

if ($method === 'GET') {
  $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
  $where = $id > 0 ? 'WHERE p.id = ?' : 'WHERE p.is_published = 1';
  if ($id > 0) {
    $stmt = db()->prepare(
      "SELECT p.id, p.name, p.description, ps.label AS status_label
       FROM projects p
       JOIN project_statuses ps ON ps.id = p.status_id
       {$where}
       LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $pr = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$pr) respond(['ok' => true, 'project' => null]);
    $pid = (int)$pr['id'];
    $photosRes = db()->query("SELECT photo_url FROM project_photos WHERE project_id = {$pid} ORDER BY sort_order ASC, id ASC");
    $photos = [];
    while ($p = $photosRes->fetch_assoc()) {
      $safeUrl = normalize_photo_url((string)$p['photo_url']);
      if ($safeUrl !== '') $photos[] = $safeUrl;
    }
    respond(['ok' => true, 'project' => [
      'id' => $pid,
      'name' => $pr['name'],
      'description' => $pr['description'],
      'statusLabel' => $pr['status_label'],
      'photos' => $photos,
    ]]);
  }

  $res = db()->query(
    "SELECT p.id, p.name, p.description, ps.label AS status_label
     FROM projects p
     JOIN project_statuses ps ON ps.id = p.status_id
     WHERE p.is_published = 1
     ORDER BY p.id DESC"
  );
  $projects = [];
  while ($r = $res->fetch_assoc()) {
    $projects[(int)$r['id']] = [
      'id' => (int)$r['id'],
      'name' => $r['name'],
      'description' => $r['description'],
      'statusLabel' => $r['status_label'],
      'photos' => [],
    ];
  }
  if (!$projects) respond(['ok' => true, 'projects' => []]);
  $ids = implode(',', array_keys($projects));
  $photosRes = db()->query("SELECT project_id, photo_url FROM project_photos WHERE project_id IN ({$ids}) ORDER BY sort_order ASC, id ASC");
  while ($p = $photosRes->fetch_assoc()) {
    $safeUrl = normalize_photo_url((string)$p['photo_url']);
    if ($safeUrl !== '') $projects[(int)$p['project_id']]['photos'][] = $safeUrl;
  }
  respond(['ok' => true, 'projects' => array_values($projects)]);
}

if ($method === 'POST') {
  $u = require_auth();
  $name = trim((string)($_POST['name'] ?? ''));
  $description = trim((string)($_POST['description'] ?? ''));
  $statusLabel = trim((string)($_POST['statusLabel'] ?? ''));
  $existingUrls = json_decode((string)($_POST['existing_urls'] ?? '[]'), true);
  if (!is_array($existingUrls)) $existingUrls = [];
  $existingUrls = array_values(array_filter(array_map(
    fn($u) => normalize_photo_url((string)$u),
    $existingUrls
  )));
  $newUploads = save_uploaded_files();
  $photos = array_values(array_filter(array_merge($existingUrls, $newUploads)));
  if ($name === '' || $description === '' || $statusLabel === '' || !$photos) {
    respond(['ok' => false, 'message' => 'Dados obrigatorios faltando.'], 422);
  }
  $statusId = status_id_from_label($statusLabel);
  if (!$statusId) respond(['ok' => false, 'message' => 'Status invalido.'], 422);

  $createdBy = (int)$u['id'];
  $stmt = db()->prepare(
    "INSERT INTO projects (name, description, status_id, is_published, created_by) VALUES (?, ?, ?, 1, ?)"
  );
  $stmt->bind_param('ssii', $name, $description, $statusId, $createdBy);
  $stmt->execute();
  $projectId = (int)db()->insert_id;
  $stmt->close();
  insert_project_photos($projectId, $photos);
  respond(['ok' => true, 'projectId' => $projectId]);
}

if ($method === 'PUT') {
  require_auth();
  $projectId = (int)($_POST['projectId'] ?? 0);
  if ($projectId <= 0) respond(['ok' => false, 'message' => 'projectId invalido.'], 422);
  $name = trim((string)($_POST['name'] ?? ''));
  $description = trim((string)($_POST['description'] ?? ''));
  $statusLabel = trim((string)($_POST['statusLabel'] ?? ''));
  $existingUrls = json_decode((string)($_POST['existing_urls'] ?? '[]'), true);
  if (!is_array($existingUrls)) $existingUrls = [];
  $existingUrls = array_values(array_filter(array_map(
    fn($u) => normalize_photo_url((string)$u),
    $existingUrls
  )));
  $newUploads = save_uploaded_files();
  $photos = array_values(array_filter(array_merge($existingUrls, $newUploads)));
  if ($name === '' || $description === '' || $statusLabel === '' || !$photos) {
    respond(['ok' => false, 'message' => 'Dados obrigatorios faltando.'], 422);
  }
  $statusId = status_id_from_label($statusLabel);
  if (!$statusId) respond(['ok' => false, 'message' => 'Status invalido.'], 422);

  $stmt = db()->prepare("UPDATE projects SET name=?, description=?, status_id=? WHERE id=?");
  $stmt->bind_param('ssii', $name, $description, $statusId, $projectId);
  $stmt->execute();
  $stmt->close();
  db()->query("DELETE FROM project_photos WHERE project_id = {$projectId}");
  insert_project_photos($projectId, $photos);
  respond(['ok' => true]);
}

if ($method === 'DELETE') {
  require_auth();
  $projectId = (int)($_POST['projectId'] ?? 0);
  if ($projectId <= 0) respond(['ok' => false, 'message' => 'projectId invalido.'], 422);
  db()->query("DELETE FROM projects WHERE id = {$projectId}");
  respond(['ok' => true]);
}

respond(['ok' => false, 'message' => 'Metodo nao suportado.'], 405);

