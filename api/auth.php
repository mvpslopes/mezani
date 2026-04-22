<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'me') {
  $u = get_current_user_row();
  respond(['ok' => true, 'user' => $u ? [
    'id' => (int)$u['id'],
    'username' => $u['username'],
    'profile' => $u['profile'],
  ] : null]);
}

if ($method === 'POST' && $action === 'logout') {
  $_SESSION = [];
  session_destroy();
  respond(['ok' => true]);
}

if ($method === 'POST' && $action === 'login') {
  $body = read_json_body();
  $username = trim((string)($body['username'] ?? ''));
  $password = (string)($body['password'] ?? '');
  if ($username === '' || $password === '') {
    respond(['ok' => false, 'message' => 'Usuario e senha sao obrigatorios.'], 422);
  }

  $stmt = db()->prepare(
    "SELECT iu.id, iu.username, iu.password_hash, iu.is_active, ap.code AS profile
     FROM internal_users iu
     JOIN access_profiles ap ON ap.id = iu.profile_id
     WHERE iu.username = ? LIMIT 1"
  );
  $stmt->bind_param('s', $username);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$row || (int)$row['is_active'] !== 1 || !password_verify($password, (string)$row['password_hash'])) {
    respond(['ok' => false, 'message' => 'Usuario/senha invalidos ou usuario inativo.'], 401);
  }

  $_SESSION['user_id'] = (int)$row['id'];
  respond(['ok' => true, 'user' => [
    'id' => (int)$row['id'],
    'username' => $row['username'],
    'profile' => $row['profile'],
  ]]);
}

respond(['ok' => false, 'message' => 'Rota invalida.'], 404);

