<?php
require_once __DIR__ . '/config/db.php';
$pdo = getDB();

$email = 'orga@ensa.ma';
$pass  = 'password';

// 1. Récupérer l'utilisateur
$stmt = $pdo->prepare('SELECT id, name, email, password, role FROM users WHERE email = :email');
$stmt->execute([':email' => $email]);
$user = $stmt->fetch();

echo "<pre>";
echo "=== DIAGNOSTIC AUTH ===\n\n";

if (!$user) {
    echo "❌ Utilisateur '$email' INTROUVABLE en base.\n";
} else {
    echo "✅ Utilisateur trouvé : " . $user['name'] . " (" . $user['role'] . ")\n";
    echo "   Hash stocké : " . $user['password'] . "\n";
    echo "   Longueur du hash : " . strlen($user['password']) . " chars\n\n";

    $ok = password_verify($pass, $user['password']);
    echo "password_verify('$pass', hash) → " . ($ok ? "✅ TRUE" : "❌ FALSE") . "\n\n";

    if (!$ok) {
        echo "→ Génération d'un nouveau hash et mise à jour...\n";
        $newHash = password_hash($pass, PASSWORD_BCRYPT);
        $upd = $pdo->prepare('UPDATE users SET password = :h WHERE email = :e');
        $upd->execute([':h' => $newHash, ':e' => $email]);
        echo "✅ Hash mis à jour. Nouveau hash : $newHash\n";
        echo "\nRe-test : ";
        $ok2 = password_verify($pass, $newHash);
        echo $ok2 ? "✅ OK — Connecte-toi maintenant." : "❌ Toujours KO (problème PHP ?)";
    }
}

echo "\n\nPHP version : " . PHP_VERSION;
echo "\n</pre>";
