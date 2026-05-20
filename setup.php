<?php
/**
 * EventHub Pro — Script de configuration du compte organisateur
 * Accès unique : http://localhost/eventhub_mvp/setup.php
 * SUPPRIMER ce fichier après utilisation en production.
 */
require_once __DIR__ . '/config/db.php';

$message = '';
$type    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name']  ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass  = trim($_POST['pass']  ?? '');

    if (!$name || !$email || !$pass) {
        $message = 'Tous les champs sont obligatoires.';
        $type    = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Email invalide.';
        $type    = 'error';
    } elseif (strlen($pass) < 6) {
        $message = 'Le mot de passe doit faire au moins 6 caractères.';
        $type    = 'error';
    } else {
        try {
            $pdo  = getDB();
            $hash = password_hash($pass, PASSWORD_BCRYPT);

            // Upsert : met à jour si l'email existe déjà
            $stmt = $pdo->prepare(
                'INSERT INTO users (name, email, password, role)
                 VALUES (:name, :email, :password, "organizer")
                 ON DUPLICATE KEY UPDATE
                     name     = VALUES(name),
                     password = VALUES(password),
                     role     = "organizer"'
            );
            $stmt->execute([':name' => $name, ':email' => $email, ':password' => $hash]);

            $message = "Compte organisateur créé / mis à jour avec succès.\n"
                     . "Email : $email\n"
                     . "Connectez-vous sur : http://localhost/eventhub_mvp/";
            $type = 'success';

        } catch (PDOException $e) {
            $message = 'Erreur BDD : ' . $e->getMessage();
            $type    = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8"/>
  <title>Setup — EventHub Pro</title>
  <style>
    body { font-family: 'Segoe UI', sans-serif; background:#0b1120; color:#e2e8f0; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
    .card { background:#131d2e; border:1px solid #1f3057; border-radius:16px; padding:40px; width:400px; }
    h1 { font-size:1.3rem; font-weight:800; color:#f59e0b; margin:0 0 6px; }
    p.sub { color:#64748b; font-size:.85rem; margin:0 0 28px; }
    label { display:block; font-size:.75rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:#64748b; margin-bottom:5px; }
    input { width:100%; padding:10px 14px; background:#1a2744; border:1.5px solid #2a3f6a; border-radius:8px; color:#e2e8f0; font-size:.95rem; outline:none; margin-bottom:16px; box-sizing:border-box; }
    input:focus { border-color:#3b82f6; }
    button { width:100%; padding:12px; background:linear-gradient(135deg,#1d4ed8,#3b82f6); color:#fff; border:none; border-radius:8px; font-size:.95rem; font-weight:700; cursor:pointer; }
    .msg { padding:12px 16px; border-radius:8px; font-size:.85rem; white-space:pre-line; margin-bottom:20px; }
    .msg.success { background:rgba(22,163,74,.15); border:1px solid rgba(22,163,74,.3); color:#86efac; }
    .msg.error   { background:rgba(239,68,68,.15);  border:1px solid rgba(239,68,68,.3);  color:#fca5a5; }
    .warn { background:rgba(245,158,11,.1); border:1px solid rgba(245,158,11,.2); border-radius:8px; padding:10px 14px; font-size:.8rem; color:#fbbf24; margin-top:20px; }
  </style>
</head>
<body>
<div class="card">
  <h1>⚙️ Setup Organisateur</h1>
  <p class="sub">Crée ou met à jour le compte organisateur EventHub Pro.</p>

  <?php if ($message): ?>
    <div class="msg <?= $type ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <form method="POST">
    <label>Nom</label>
    <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? 'Admin ENSA') ?>" required />

    <label>Email</label>
    <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? 'admin@ensa.ma') ?>" required />

    <label>Mot de passe (min. 6 caractères)</label>
    <input type="password" name="pass" placeholder="Choisissez un mot de passe" required />

    <button type="submit">Créer le compte organisateur</button>
  </form>

  <div class="warn">⚠️ Supprimez ce fichier après utilisation.</div>
</div>
</body>
</html>
