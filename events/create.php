<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  EventHub Pro — events/create.php                           ║
 * ║  Création d'un événement                                    ║
 * ║  ENSA Marrakech — Examen PHP Avancé                         ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * STATUT : 🔴 À compléter — Partie 1.2
 *
 * Ce fichier reçoit les données du formulaire (POST JSON)
 * et les insère en base de données.
 *
 * BUGS INTENTIONNELS À CORRIGER (Partie 1.2) :
 *   ❌  Injection SQL directe dans createEvent()
 *   ❌  Aucune validation des données entrantes
 *   ❌  Retour toujours true même en cas d'échec
 *   ❌  Aucune gestion d'exception PDO
 *
 * À IMPLÉMENTER :
 *   ✅  Corriger createEvent() avec requêtes préparées
 *   ✅  Valider et assainir les données reçues
 *   ✅  Retourner une vraie réponse JSON success/error
 *   ✅  Brancher l'appel fetch() depuis assets/js/app.js
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once __DIR__ . '/../config/db.php';

// ── Point d'entrée ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

// Lecture du body JSON
$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Données JSON invalides.']);
    exit;
}

$required = ['title', 'description', 'date', 'location', 'capacity', 'category', 'organizer_email'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Champ obligatoire manquant : $field"]);
        exit;
    }
}

try {
    $pdo    = getDB();
    $result = createEvent($pdo, $data);

    echo json_encode([
        'success'  => true,
        'event_id' => $result,
        'message'  => 'Événement créé avec succès.'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

// ═════════════════════════════════════════════════════════════════════════
// FONCTION PRINCIPALE — BUGS INTENTIONNELS À CORRIGER (Partie 1.2)
// ═════════════════════════════════════════════════════════════════════════

/**
 * Insère un nouvel événement en base de données.
 *
 * ⚠️  ATTENTION : Cette fonction contient des erreurs volontaires.
 *     Identifiez-les, corrigez-les et justifiez chaque correction
 *     dans un commentaire inline.
 *
 * @param  PDO   $pdo
 * @param  array $data  Données issues du formulaire
 * @return bool         (toujours vrai — c'est un bug !)
 */
function createEvent(PDO $pdo, array $data): int
{
    // CORRECTION BUG 1 : placeholders nommés (:title, :description…) au lieu de concaténation
    // → les valeurs ne sont jamais interprétées comme SQL, injection impossible
    $sql = "INSERT INTO events (title, description, event_date, location, capacity, category, organizer_email, created_at)
            VALUES (:title, :description, :event_date, :location, :capacity, :category, :organizer_email, NOW())";

    // CORRECTION BUG 2 : prepare() + execute() au lieu de query()
    // → query() exécute la chaîne brute sans échappement ; prepare() envoie requête et données séparément
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':title'           => htmlspecialchars(trim($data['title'])),
        ':description'     => htmlspecialchars(trim($data['description'])),
        ':event_date'      => $data['date'],
        ':location'        => htmlspecialchars(trim($data['location'])),
        ':capacity'        => (int)$data['capacity'],
        ':category'        => $data['category'],
        ':organizer_email' => filter_var($data['organizer_email'], FILTER_VALIDATE_EMAIL),
    ]);

    // CORRECTION BUG 3 : lastInsertId() au lieu de return true
    // → retourne l'ID réel de la ligne créée ; une erreur PDO lèverait une exception plutôt que renvoyer 0
    return (int)$pdo->lastInsertId();
}
