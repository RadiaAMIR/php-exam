<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';
session_start();

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'organizer') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accès refusé.']);
    exit;
}

try {
    $pdo = getDB();

    // ── Résumé global ──────────────────────────────────────────────────────
    // Sous-requête obligatoire : MySQL interdit AVG(COUNT(...)) et SUM(COUNT(...))
    // car les fonctions d'agrégat ne peuvent pas s'imbriquer directement.
    $summary = $pdo->query("
        SELECT
            COUNT(*)                    AS total_events,
            SUM(sub.registered)         AS total_registered,
            SUM(sub.new_24h)            AS new_last_24h,
            ROUND(AVG(sub.fill_pct))    AS avg_fill_pct,
            SUM(sub.fill_pct >= 80)     AS alert_count
        FROM (
            SELECT
                e.id,
                COUNT(r.id)                                                        AS registered,
                SUM(r.registered_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR))          AS new_24h,
                ROUND(COUNT(r.id) / e.capacity * 100)                              AS fill_pct
            FROM events e
            LEFT JOIN registrations r ON r.event_id = e.id
            GROUP BY e.id, e.capacity
        ) AS sub
    ")->fetch(PDO::FETCH_ASSOC);

    // Garantir des entiers (NULL si aucune inscription)
    $summary['total_registered'] = (int)($summary['total_registered'] ?? 0);
    $summary['new_last_24h']     = (int)($summary['new_last_24h']     ?? 0);
    $summary['avg_fill_pct']     = (int)($summary['avg_fill_pct']     ?? 0);
    $summary['alert_count']      = (int)($summary['alert_count']      ?? 0);
    $summary['total_events']     = (int)($summary['total_events']     ?? 0);

    // ── Top 3 événements les plus remplis ──────────────────────────────────
    $top3 = $pdo->query("
        SELECT e.id, e.title,
               COUNT(r.id)                          AS reg,
               ROUND(COUNT(r.id) / e.capacity * 100) AS fill_pct
        FROM events e
        LEFT JOIN registrations r ON r.event_id = e.id
        GROUP BY e.id, e.title, e.capacity
        ORDER BY fill_pct DESC
        LIMIT 3
    ")->fetchAll(PDO::FETCH_ASSOC);

    // ── Détail par événement ───────────────────────────────────────────────
    $perEvent = $pdo->query("
        SELECT e.id, e.title, e.capacity,
               COUNT(r.id)                              AS registered,
               ROUND(COUNT(r.id) / e.capacity * 100)    AS fill_pct,
               (COUNT(r.id) >= e.capacity)              AS is_full
        FROM events e
        LEFT JOIN registrations r ON r.event_id = e.id
        GROUP BY e.id, e.title, e.capacity, e.event_date
        ORDER BY e.event_date ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // ── Inscriptions par jour — 7 derniers jours ───────────────────────────
    $byDay = $pdo->query("
        SELECT DATE(registered_at) AS day, COUNT(*) AS count
        FROM registrations
        WHERE registered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(registered_at)
        ORDER BY day ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'              => true,
        'generated_at'         => date('Y-m-d H:i:s'),
        'summary'              => $summary,
        'top3'                 => $top3,
        'per_event'            => $perEvent,
        'registrations_by_day' => $byDay,
    ]);

} catch (Exception $e) {
    error_log('[EventHub] api/stats.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur serveur.']);
}
