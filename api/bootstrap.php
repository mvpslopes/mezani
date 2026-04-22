<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');

function env_or_default(string $key, string $default): string {
  $value = getenv($key);
  return $value === false || $value === '' ? $default : $value;
}

function db(): mysqli {
  static $conn = null;
  if ($conn instanceof mysqli) {
    return $conn;
  }

  $host = env_or_default('MEZANI_DB_HOST', 'localhost');
  $name = env_or_default('MEZANI_DB_NAME', 'u179630068_mezani');
  $user = env_or_default('MEZANI_DB_USER', 'u179630068_mezani_root');
  $pass = env_or_default('MEZANI_DB_PASS', '6#z3UDsxB!c');

  $conn = new mysqli($host, $user, $pass, $name);
  if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Falha de conexao com o banco.']);
    exit;
  }
  $conn->set_charset('utf8mb4');
  return $conn;
}

function read_json_body(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function respond(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload);
  exit;
}

function get_current_user_row(): ?array {
  if (!isset($_SESSION['user_id'])) return null;
  $userId = (int) $_SESSION['user_id'];
  $stmt = db()->prepare(
    "SELECT iu.id, iu.username, iu.is_active, ap.code AS profile
     FROM internal_users iu
     JOIN access_profiles ap ON ap.id = iu.profile_id
     WHERE iu.id = ? LIMIT 1"
  );
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $res = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$res || (int)$res['is_active'] !== 1) return null;
  return $res;
}

function require_auth(): array {
  $u = get_current_user_row();
  if (!$u) respond(['ok' => false, 'message' => 'Nao autenticado.'], 401);
  return $u;
}

function require_root(): array {
  $u = require_auth();
  if (($u['profile'] ?? '') !== 'ROOT') {
    respond(['ok' => false, 'message' => 'Apenas ROOT pode executar esta acao.'], 403);
  }
  return $u;
}

