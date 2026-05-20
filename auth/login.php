<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

$data  = json_decode(file_get_contents('php://input'), true);
$email = isset($data['email'])    ? trim($data['email'])    : '';
$pass  = isset($data['password']) ? trim($data['password']) : '';

if (!$email || !$pass || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email ou mot de passe manquant.']);
    exit;
}

try {
    $pdo  = getDB();
    $stmt = $pdo->prepare('SELECT id, name, email, password, role FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($pass, $user['password'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Identifiants invalides.']);
        exit;
    }

    session_start();
    session_regenerate_id(true);
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role']  = $user['role'];

    echo json_encode([
        'success' => true,
        'user'    => [
            'name'  => $user['name'],
            'email' => $user['email'],
            'role'  => $user['role'],
        ],
    ]);

} catch (PDOException $e) {
    error_log('[EventHub] auth/login.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
}
