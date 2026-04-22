<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
  require_auth();
  $rows = [];
  $res = db()->query(
    "SELECT iu.id, iu.username, iu.is_active, ap.code AS profile
     FROM internal_users iu
     JOIN access_profiles ap ON ap.id = iu.profile_id
     ORDER BY iu.id DESC"
  );
  while ($r = $res->fetch_assoc()) {
    $rows[] = [
      'id' => (int)$r['id'],
      'username' => $r['username'],
      'profile' => $r['profile'],
      'active' => (int)$r['is_active'] === 1,
    ];
  }
  respond(['ok' => true, 'users' => $rows]);
}

if ($method === 'POST') {
  $actor = require_root();
  $body = read_json_body();
  $username = trim((string)($body['username'] ?? ''));
  $profile = strtoupper(trim((string)($body['profile'] ?? 'ADMIN')));
  $password = (string)($body['password'] ?? '');
  if ($username === '' || $password === '' || !in_array($profile, ['ROOT', 'ADMIN'], true)) {
    respond(['ok' => false, 'message' => 'Dados invalidos.'], 422);
  }

  $stmtP = db()->prepare("SELECT id FROM access_profiles WHERE code = ? LIMIT 1");
  $stmtP->bind_param('s', $profile);
  $stmtP->execute();
  $prof = $stmtP->get_result()->fetch_assoc();
  $stmtP->close();
  if (!$prof) respond(['ok' => false, 'message' => 'Perfil invalido.'], 422);

  $hash = password_hash($password, PASSWORD_BCRYPT);
  $createdBy = (int)$actor['id'];
  $profileId = (int)$prof['id'];
  $stmt = db()->prepare(
    "INSERT INTO internal_users (profile_id, username, password_hash, is_active, created_by, password_changed_at)
     VALUES (?, ?, ?, 1, ?, NOW())"
  );
  $stmt->bind_param('issi', $profileId, $username, $hash, $createdBy);
  if (!$stmt->execute()) {
    $stmt->close();
    respond(['ok' => false, 'message' => 'Falha ao criar usuario (talvez usuario ja exista).'], 409);
  }
  $stmt->close();
  respond(['ok' => true]);
}

if ($method === 'PATCH') {
  require_root();
  $body = read_json_body();
  $userId = (int)($body['userId'] ?? 0);
  if ($userId <= 0) respond(['ok' => false, 'message' => 'userId invalido.'], 422);

  if (array_key_exists('active', $body)) {
    $active = !empty($body['active']) ? 1 : 0;
    $stmt = db()->prepare("UPDATE internal_users SET is_active = ? WHERE id = ?");
    $stmt->bind_param('ii', $active, $userId);
    $stmt->execute();
    $stmt->close();
  }

  if (!empty($body['newPassword'])) {
    $hash = password_hash((string)$body['newPassword'], PASSWORD_BCRYPT);
    $stmt = db()->prepare("UPDATE internal_users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?");
    $stmt->bind_param('si', $hash, $userId);
    $stmt->execute();
    $stmt->close();
  }

  respond(['ok' => true]);
}

respond(['ok' => false, 'message' => 'Metodo nao suportado.'], 405);

