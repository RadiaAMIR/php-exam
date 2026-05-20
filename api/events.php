<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  EventHub Pro — api/events.php                              ║
 * ║  Endpoint AJAX — Liste et recherche des événements          ║
 * ║  ENSA Marrakech — Examen PHP Avancé                         ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * STATUT : ⚠️ Partiel — Partie 1.3 + Partie 4.1
 *
 * FOURNI :
 *   ✅  Structure de réponse JSON
 *   ✅  Lecture des paramètres GET/POST
 *   ✅  Requête de base sans filtre
 *
 * À COMPLÉTER :
 *   🔴  searchEvents() — filtres dynamiques combinables (Partie 1.3)
 *   🔴  Pagination des résultats
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/db.php';

// ── Lecture des paramètres (GET ou POST JSON) ─────────────────────────────
$params = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = file_get_contents('php://input');
    $params = json_decode($body, true) ?? [];
} else {
    $params = $_GET;
}

$keyword  = isset($params['keyword'])  ? trim($params['keyword'])   : '';
$category = isset($params['category']) ? trim($params['category'])  : '';
$dateFrom = isset($params['date_from'])? trim($params['date_from']) : '';
$dateTo   = isset($params['date_to'])  ? trim($params['date_to'])   : '';
$hasPlaces= isset($params['has_places'])? (bool)$params['has_places']: false;
$page     = isset($params['page'])     ? max(1, (int)$params['page']): 1;
$perPage  = 6;

try {
    $pdo    = getDB();
    $result = searchEvents($pdo, $keyword, $category, $dateFrom, $dateTo, $hasPlaces, $page, $perPage);

    echo json_encode([
        'success' => true,
        'data'    => $result['events'],
        'meta'    => [
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => ceil($result['total'] / $perPage),
        ]
    ]);

} catch (Exception $e) {
    error_log('[EventHub] api/events.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
}


// ═════════════════════════════════════════════════════════════════════════
// DÉCISION DE CONCEPTION — searchEvents()
// ═════════════════════════════════════════════════════════════════════════
//
// STRATÉGIE : tableau $conditions[] + tableau $bindings[]
// ─────────────────────────────────────────────────────────────────────────
// Chaque filtre actif ajoute une chaîne SQL dans $conditions[] et sa valeur
// dans $bindings[]. La clause WHERE est assemblée à la fin avec implode().
// Avantage : aucune concaténation de valeur dans la chaîne SQL — les données
// ne transitent jamais comme du code SQL, ce qui élimine structurellement
// l'injection SQL. PDO traite la requête et les paramètres séparément :
// le moteur MySQL reçoit un plan d'exécution figé, puis substitue les valeurs
// dans un contexte purement données, jamais dans un contexte syntaxique.
//
// POURQUOI HAVING POUR $hasPlaces ET PAS WHERE ?
// ─────────────────────────────────────────────────────────────────────────
// `available_places` est une colonne calculée (e.capacity - COUNT(r.id)).
// En SQL, WHERE est évalué AVANT GROUP BY : à ce stade, COUNT(r.id) n'a pas
// encore été calculé, donc la colonne n'existe pas. HAVING est évalué APRÈS
// GROUP BY, quand chaque agrégat est disponible. Utiliser WHERE ici
// provoquerait une erreur "Unknown column 'available_places'".
//
// POURQUOI bindValue() AVEC PDO::PARAM_INT POUR LIMIT ET OFFSET ?
// ─────────────────────────────────────────────────────────────────────────
// PDO en mode émulation (PDO::ATTR_EMULATE_PREPARES = true par défaut)
// entoure chaque paramètre de guillemets lors de la substitution.
// LIMIT '6' OFFSET '0' est du SQL invalide : MySQL exige des entiers nus.
// bindValue() avec PDO::PARAM_INT force PDO à transmettre la valeur sans
// guillemets, garantissant une pagination syntaxiquement correcte quelle que
// soit la configuration PDO du serveur.
//
// ═════════════════════════════════════════════════════════════════════════

/**
 * Recherche des événements avec filtres combinables.
 *
 * CONTRAINTES OBLIGATOIRES :
 *   → Tous les filtres sont OPTIONNELS (aucun n'est requis)
 *   → Les filtres actifs se COMBINENT (AND)
 *   → Aucune concaténation SQL directe → requêtes préparées uniquement
 *   → La requête doit être construite dynamiquement selon les filtres reçus
 *
 * STRATÉGIE SUGGÉRÉE (plusieurs approches valides) :
 *   Construire un tableau $conditions[] et un tableau $bindings[]
 *   Ajouter une condition à chaque filtre actif
 *   Assembler : WHERE implode(' AND ', $conditions)
 *   Binder : execute($bindings)
 *
 * CHAMPS RETOURNÉS PAR ÉVÉNEMENT :
 *   id, title, description, event_date, location,
 *   capacity, category, organizer_email,
 *   registered_count   (COUNT via JOIN)
 *   available_places   (capacity - registered_count)
 *   fill_percentage    (arrondi à l'entier)
 *
 * @param PDO    $pdo
 * @param string $keyword     Recherche dans title et description
 * @param string $category    Filtre par catégorie exacte
 * @param string $dateFrom    Date minimum (format Y-m-d)
 * @param string $dateTo      Date maximum (format Y-m-d)
 * @param bool   $hasPlaces   Si true : exclure les événements complets
 * @param int    $page        Numéro de page (pagination)
 * @param int    $perPage     Résultats par page
 * @return array              ['events' => [...], 'total' => int]
 */
function searchEvents(
    PDO    $pdo,
    string $keyword   = '',
    string $category  = '',
    string $dateFrom  = '',
    string $dateTo    = '',
    bool   $hasPlaces = false,
    int    $page      = 1,
    int    $perPage   = 6
): array {

    // ── Requête de base (fournie) ─────────────────────────────────────
    $baseSelect = "SELECT e.id,
                          e.title,
                          e.description,
                          e.event_date,
                          e.location,
                          e.capacity,
                          e.category,
                          e.organizer_email,
                          COUNT(r.id)                                  AS registered_count,
                          (e.capacity - COUNT(r.id))                   AS available_places,
                          ROUND(COUNT(r.id) / e.capacity * 100)        AS fill_percentage
                   FROM   events e
                   LEFT JOIN registrations r ON r.event_id = e.id";

    $conditions = [];
    $bindings   = [];

    if ($keyword !== '') {
        $conditions[] = '(e.title LIKE :keyword OR e.description LIKE :keyword)';
        $bindings[':keyword'] = '%' . $keyword . '%';
    }

    if ($category !== '') {
        $conditions[] = 'e.category = :category';
        $bindings[':category'] = $category;
    }

    if ($dateFrom !== '') {
        $conditions[] = 'e.event_date >= :date_from';
        $bindings[':date_from'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $conditions[] = 'e.event_date <= :date_to';
        $bindings[':date_to'] = $dateTo;
    }

    // ── Assemblage de la requête ──────────────────────────────────────
    $sql = $baseSelect;

    if (!empty($conditions)) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= ' GROUP BY e.id';

    // $hasPlaces se base sur la colonne calculée available_places → HAVING obligatoire (pas WHERE)
    if ($hasPlaces) {
        $sql .= ' HAVING available_places > 0';
    }

    $sql .= ' ORDER BY e.event_date ASC';

    // Pagination : bindValue() pour LIMIT/OFFSET afin de forcer PDO::PARAM_INT
    $offset = ($page - 1) * $perPage;
    $sql .= ' LIMIT :limit OFFSET :offset';

    $stmt = $pdo->prepare($sql);
    foreach ($bindings as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $events = $stmt->fetchAll();

    // Requête COUNT séparée : rejoue les mêmes conditions sans LIMIT ni ORDER BY
    $countSql = "SELECT COUNT(DISTINCT e.id)
                 FROM   events e
                 LEFT JOIN registrations r ON r.event_id = e.id";
    if (!empty($conditions)) {
        $countSql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $countStmt = $pdo->prepare($countSql);
    foreach ($bindings as $key => $val) {
        $countStmt->bindValue($key, $val);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    return ['events' => $events, 'total' => $total];
}
