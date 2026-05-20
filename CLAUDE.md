# CLAUDE.md — EventHub Pro · Examen PHP Avancé
# ENSA Marrakech · Université Cadi Ayyad · 2025-2026

## 🎯 Contexte de cet examen

Projet : **EventHub Pro** — Plateforme de gestion d'événements
Durée : 3h | Barème : 20 pts + 5 pts bonus MVC
Un MVP est fourni. Ta mission : compléter les fichiers marqués 🔴 ou ⚠️.
Un agent réviseur relira ton code — sois rigoureux et précis à 100%.

---

## ⚠️ RÈGLES ABSOLUES (violations = pénalités)

- **PHP 8+ natif uniquement** — aucun framework (Laravel, Symfony → note 0)
- **PDO uniquement** pour la BDD — jamais mysqli, jamais `$pdo->query()` avec concaténation
- **Requêtes préparées** partout — jamais d'interpolation SQL directe
- **SQL uniquement dans les Modèles** (ou fonctions dédiées) — jamais dans les contrôleurs/vues
- `password_hash($pass, PASSWORD_BCRYPT)` + `password_verify()` pour les mots de passe
- `htmlspecialchars()` dans toutes les vues
- CSRF token sur tous les formulaires POST
- JSON lu via `file_get_contents('php://input')` — jamais `$_POST` pour AJAX
- Headers `Content-Type: application/json` sur toutes les APIs

---

## 📁 Structure du projet fourni

```
eventhub_mvp/
├── index.html                  ✅ Fourni (ne pas modifier)
├── config/
│   ├── db.php                  ✅ Fourni — getDB() retourne PDO singleton
│   └── mailer.php              ⚠️ Compléter SMTP_HOST, SMTP_USER, SMTP_PASS
├── events/
│   ├── create.php              🔴 Corriger les bugs PDO (Partie 1.2)
│   └── register.php            🔴 Implémenter inscription + emails (Parties 2.1 + 2.2)
├── api/
│   ├── events.php              ⚠️ Compléter searchEvents() (Partie 1.3)
│   └── stats.php               🔴 Créer entièrement (Partie 4.2)
├── pdf/
│   ├── ticket.php              🔴 Créer ticket PDF (Partie 3.1)
│   └── report.php              🔴 Créer rapport PDF 3 pages (Partie 3.2)
├── mail/
│   ├── SendConfirmation.php    🔴 Implémenter envoi email (Partie 2.1)
│   ├── AlertMailer.php         🔴 Implémenter alerte 80% (Partie 2.2)
│   └── templates/              ✅ Fourni — utiliser str_replace() pour les placeholders
├── assets/js/app.js            ⚠️ Décommenter et compléter les TODO (Partie 4.1)
├── database/schema.sql         ⚠️ Ajouter table registrations + index (Partie 1.1)
└── lib/
    ├── tcpdf/tcpdf.php         (à télécharger — voir section installations)
    ├── phpqrcode/qrlib.php     (à télécharger)
    └── PHPMailer/src/          (à télécharger)
```

---

## 🗄️ Base de données : eventhub_db

### Tables FOURNIES (ne pas recréer)
- `users` — id, name, email, password (bcrypt), role ENUM('organizer','participant')
- `categories` — id, slug, label, color_primary, color_light
- `events` — id, title, description, event_date, location, capacity, category, organizer_email, organizer_id
- `mail_logs` — id, type, recipient, event_id, error_message, created_at

### Table À CRÉER : registrations (Partie 1.1)
```sql
DROP TABLE IF EXISTS registrations;
CREATE TABLE registrations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id      INT UNSIGNED NOT NULL,
    name          VARCHAR(150) NOT NULL,
    email         VARCHAR(255) NOT NULL,
    token         VARCHAR(64)  NOT NULL UNIQUE,     -- pour désinscription
    registered_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    UNIQUE KEY uq_event_email (event_id, email)     -- empêcher double inscription
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Colonne À AJOUTER dans events (Partie 2.2)
```sql
ALTER TABLE events ADD COLUMN alert_sent TINYINT(1) NOT NULL DEFAULT 0;
```

### Index de performance À CRÉER (Partie 1.1)
```sql
-- Optimise searchEvents() qui filtre par date ET catégorie
CREATE INDEX idx_events_date_category ON events (event_date, category);
-- Justification : la requête searchEvents() filtre souvent par ces deux colonnes combinées
```

### Données de test pour registrations
```sql
INSERT INTO registrations (event_id, name, email, token) VALUES
(1, 'Yassine El Fassi',  'yassine@example.ma', SHA2(CONCAT('1-yassine-', NOW()), 256)),
(1, 'Salma Benali',      'salma@example.ma',   SHA2(CONCAT('1-salma-',   NOW()), 256)),
(1, 'Mehdi Khalil',      'mehdi@example.ma',   SHA2(CONCAT('1-mehdi-',   NOW()), 256)),
(2, 'Zineb Moussaoui',   'zineb@example.ma',   SHA2(CONCAT('2-zineb-',   NOW()), 256)),
(3, 'Yassine El Fassi',  'yassine@example.ma', SHA2(CONCAT('3-yassine-', NOW()), 256));
```

---

## 🔧 PARTIE 1 — PDO & Base de données

### 1.1 — La fonction getDB() (déjà fournie, à utiliser partout)
```php
// Dans config/db.php — déjà implémenté, ne pas modifier
// Utilisation dans tous les fichiers :
require_once __DIR__ . '/../config/db.php';
$pdo = getDB(); // retourne un singleton PDO configuré
```

### 1.2 — Corriger createEvent() (3 bugs intentionnels)
```php
// ✅ VERSION CORRIGÉE de createEvent() dans events/create.php
function createEvent(PDO $pdo, array $data): int
{
    // CORRECTION BUG 1 : utiliser des placeholders nommés au lieu de concaténation
    // → Empêche l'injection SQL
    $sql = "INSERT INTO events 
                (title, description, event_date, location, capacity, category, organizer_email, created_at)
            VALUES 
                (:title, :description, :event_date, :location, :capacity, :category, :organizer_email, NOW())";

    // CORRECTION BUG 2 : utiliser prepare() + execute() au lieu de query()
    // → query() n'échappe pas les données ; prepare() crée une vraie requête préparée
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

    // CORRECTION BUG 3 : retourner lastInsertId() pour connaître l'ID créé
    // → "return true" masquait les erreurs et ne donnait aucune info utile
    return (int)$pdo->lastInsertId();
}
```

### 1.3 — searchEvents() avec filtres dynamiques
```php
// Stratégie : tableau $conditions[] + tableau $bindings[]
function searchEvents(PDO $pdo, string $keyword='', string $category='',
                      string $dateFrom='', string $dateTo='', bool $hasPlaces=false,
                      int $page=1, int $perPage=6): array
{
    $conditions = [];
    $bindings   = [];

    // Filtre mot-clé
    if ($keyword !== '') {
        $conditions[] = '(e.title LIKE :keyword OR e.description LIKE :keyword)';
        $bindings[':keyword'] = '%' . $keyword . '%';
    }
    // Filtre catégorie
    if ($category !== '') {
        $conditions[] = 'e.category = :category';
        $bindings[':category'] = $category;
    }
    // Filtre date début
    if ($dateFrom !== '') {
        $conditions[] = 'e.event_date >= :date_from';
        $bindings[':date_from'] = $dateFrom;
    }
    // Filtre date fin
    if ($dateTo !== '') {
        $conditions[] = 'e.event_date <= :date_to';
        $bindings[':date_to'] = $dateTo;
    }

    $baseSelect = "SELECT e.id, e.title, e.description, e.event_date, e.location,
                          e.capacity, e.category, e.organizer_email,
                          COUNT(r.id)                             AS registered_count,
                          (e.capacity - COUNT(r.id))              AS available_places,
                          ROUND(COUNT(r.id) / e.capacity * 100)   AS fill_percentage
                   FROM   events e
                   LEFT JOIN registrations r ON r.event_id = e.id";

    $sql = $baseSelect;
    if (!empty($conditions)) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' GROUP BY e.id';

    // Filtre places disponibles (HAVING car basé sur COUNT)
    if ($hasPlaces) {
        $sql .= ' HAVING available_places > 0';
    }
    $sql .= ' ORDER BY e.event_date ASC';

    // Pagination
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

    // Requête COUNT pour pagination
    $countSql = "SELECT COUNT(DISTINCT e.id) FROM events e LEFT JOIN registrations r ON r.event_id = e.id";
    if (!empty($conditions)) $countSql .= ' WHERE ' . implode(' AND ', $conditions);
    $countStmt = $pdo->prepare($countSql);
    foreach ($bindings as $key => $val) {
        $countStmt->bindValue($key, $val);
    }
    $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    return ['events' => $events, 'total' => $total];
}
```

---

## 📧 PARTIE 2 — Emails PHPMailer

### 2.1 — SendConfirmation::send()
```php
public static function send(PDO $pdo, array $event, string $name, string $email, string $token): bool
{
    // Charger le template HTML
    $html = file_get_contents(__DIR__ . '/templates/confirmation.html');

    // Formater la date en français
    $dateFormatted = (new DateTime($event['event_date']))->format('d/m/Y à H\hi');

    // Construire le lien de désinscription
    $unsubLink = 'http://localhost/eventhub_mvp/events/unsubscribe.php?token=' . urlencode($token);
    $ticketLink = 'http://localhost/eventhub_mvp/pdf/ticket.php?token=' . urlencode($token);

    // Remplacer les placeholders
    $html = str_replace('{{PARTICIPANT_NAME}}', htmlspecialchars($name),             $html);
    $html = str_replace('{{EVENT_TITLE}}',      htmlspecialchars($event['title']),   $html);
    $html = str_replace('{{EVENT_DATE}}',        $dateFormatted,                     $html);
    $html = str_replace('{{EVENT_LOCATION}}',   htmlspecialchars($event['location']),$html);
    $html = str_replace('{{TICKET_LINK}}',       $ticketLink,                        $html);
    $html = str_replace('{{UNSUBSCRIBE_LINK}}',  $unsubLink,                         $html);
    $html = str_replace('{{YEAR}}',              date('Y'),                          $html);

    try {
        $mail = createMailer();
        $mail->addAddress($email, $name);
        $mail->Subject = 'Votre inscription — ' . $event['title'];
        $mail->Body    = $html;
        $mail->AltBody = strip_tags($html);
        $mail->send();
        return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        logMailError($pdo, 'confirmation', $email, $e->getMessage());
        return false;
    }
}
```

### 2.2 — AlertMailer::sendCapacityAlert() — Éviter les doublons
```php
// DÉCISION DE CONCEPTION CHOISIE : Colonne alert_sent dans la table events
// Justification : solution atomique en BDD, pas de race condition,
// consultable directement dans le dashboard, aucun fichier temporaire à gérer.

public static function sendCapacityAlert(PDO $pdo, array $event): bool
{
    // Vérifier si l'alerte a déjà été envoyée
    if ((int)$event['alert_sent'] === 1) {
        return false; // Ne pas renvoyer
    }

    // Générer le PDF rapport en fichier temporaire
    $tempPdf = sys_get_temp_dir() . '/report_event_' . $event['id'] . '.pdf';
    generateReportPDF($pdo, $event['id'], 'F', $tempPdf);

    // Charger et personnaliser le template
    $html = file_get_contents(__DIR__ . '/templates/alert.html');
    $fillPct   = round($event['registered_count'] / $event['capacity'] * 100);
    $available = $event['capacity'] - $event['registered_count'];

    $html = str_replace('{{ORGANIZER_NAME}}', htmlspecialchars($event['organizer_email']), $html);
    $html = str_replace('{{EVENT_TITLE}}',    htmlspecialchars($event['title']),           $html);
    $html = str_replace('{{FILL_PCT}}',        $fillPct,                                   $html);
    $html = str_replace('{{REGISTERED}}',      $event['registered_count'],                 $html);
    $html = str_replace('{{CAPACITY}}',        $event['capacity'],                         $html);
    $html = str_replace('{{AVAILABLE}}',       $available,                                 $html);
    $html = str_replace('{{DASHBOARD_LINK}}', 'http://localhost/eventhub_mvp/#dashboard',  $html);
    $html = str_replace('{{YEAR}}',            date('Y'),                                  $html);

    try {
        $mail = createMailer();
        $mail->addAddress($event['organizer_email']);
        $mail->Subject = '⚠️ Alerte capacité — ' . $event['title'];
        $mail->Body    = $html;
        $mail->AltBody = strip_tags($html);
        if (file_exists($tempPdf)) {
            $mail->addAttachment($tempPdf, 'rapport_' . $event['id'] . '.pdf');
        }
        $mail->send();

        // Marquer comme envoyé en BDD (évite les doublons)
        $stmt = $pdo->prepare('UPDATE events SET alert_sent = 1 WHERE id = :id');
        $stmt->execute([':id' => $event['id']]);

        @unlink($tempPdf); // Nettoyer le fichier temporaire
        return true;

    } catch (\PHPMailer\PHPMailer\Exception $e) {
        logMailError($pdo, 'capacity_alert', $event['organizer_email'], $e->getMessage());
        return false;
    }
}
```

### Détection du seuil 80% dans register.php
```php
// Dans events/register.php, après l'insertion :
$newCount  = $event['registered_count'] + 1;
$capacityPct = ($newCount / $event['capacity']) * 100;
$alertSent = false;

if ($capacityPct >= 80 && (int)$event['alert_sent'] === 0) {
    require_once __DIR__ . '/../mail/AlertMailer.php';
    require_once __DIR__ . '/../pdf/report.php';
    $event['registered_count'] = $newCount; // mettre à jour avant envoi
    $alertSent = AlertMailer::sendCapacityAlert($pdo, $event);
}
```

---

## 📄 PARTIE 3 — Génération PDF

### Choix de bibliothèque : TCPDF
**Justification** : TCPDF supporte nativement les primitives de dessin (Rect, Line, Cell, SetFillColor) nécessaires pour le graphique en barres PHP pur demandé à la Partie 3.2. Dompdf génère du HTML/CSS mais les graphiques nécessiteraient des images externes.

### 3.1 — Structure du ticket PDF (TCPDF)
```php
// Démarrage TCPDF — ticket A5 paysage
require_once __DIR__ . '/../lib/tcpdf/tcpdf.php';
require_once __DIR__ . '/../lib/phpqrcode/qrlib.php';

$pdf = new TCPDF('L', 'mm', 'A5', true, 'UTF-8');
$pdf->SetCreator('EventHub Pro');
$pdf->SetTitle('Ticket — ' . $data['title']);
$pdf->SetMargins(10, 10, 10);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();

// Bandeau coloré selon catégorie
list($r, $g, $b) = sscanf($colors['primary'], '#%02x%02x%02x');
$pdf->SetFillColor($r, $g, $b);
$pdf->Rect(0, 0, 210, 12, 'F'); // bandeau en haut

// Numéro de ticket
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetXY(130, 2);
$pdf->Cell(70, 8, 'TICKET N° ' . str_pad($data['id'], 5, '0', STR_PAD_LEFT), 0, 0, 'R');

// Infos événement
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', 'B', 14);
$pdf->SetXY(10, 18);
$pdf->MultiCell(120, 8, $data['title'], 0, 'L');

$pdf->SetFont('helvetica', '', 10);
$pdf->SetXY(10, 34);
$pdf->Cell(120, 6, '📅 ' . date('d/m/Y à H\hi', strtotime($data['event_date'])), 0, 1);
$pdf->SetXY(10, 40);
$pdf->Cell(120, 6, '📍 ' . $data['location'], 0, 1);

// QR Code
$qrTempFile = sys_get_temp_dir() . '/qr_' . $registrationId . '.png';
$qrData = $data['id'] . '|' . $registrationId . '|' . $token;
QRcode::png($qrData, $qrTempFile, QR_ECLEVEL_M, 4);
$pdf->Image($qrTempFile, 150, 18, 45, 45);

// Infos participant
$pdf->SetFillColor(248, 250, 252);
$pdf->Rect(10, 65, 185, 22, 'F');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetXY(12, 67);
$pdf->Cell(90, 5, 'Participant : ' . $data['name'], 0, 1);
$pdf->SetXY(12, 73);
$pdf->Cell(90, 5, 'Email       : ' . $data['email'], 0, 1);
$pdf->SetXY(12, 79);
$pdf->Cell(90, 5, 'Inscrit le  : ' . date('d/m/Y à H:i', strtotime($data['registered_at'])), 0, 1);

// Lien désinscription
$pdf->SetFont('helvetica', 'I', 7);
$pdf->SetTextColor(148, 163, 184);
$pdf->SetXY(10, 92);
$pdf->Cell(185, 5, 'Désinscription : http://localhost/eventhub_mvp/events/unsubscribe.php?token=' . $token, 0, 0, 'C');

// Output
if ($output === 'F') {
    $pdf->Output($filePath, 'F');
    @unlink($qrTempFile);
    return $filePath;
} else {
    @unlink($qrTempFile);
    $pdf->Output('ticket_' . $registrationId . '.pdf', 'D');
}
```

### 3.2 — Graphique en barres TCPDF (DÉFI TECHNIQUE)
```php
// PAGE 3 — Graphique en barres des inscriptions par jour
$pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 10, 'Inscriptions par jour — 7 derniers jours', 0, 1, 'C');

if (empty($statsByDay)) {
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->Cell(0, 10, 'Aucune inscription récente.', 0, 1, 'C');
} else {
    $maxCount = max(array_column($statsByDay, 'count'));
    $barWidth  = 20;   // largeur de chaque barre en mm
    $chartH    = 80;   // hauteur maximale du graphique
    $originX   = 25;   // départ X
    $originY   = 180;  // position Y du bas du graphique (ligne de base)

    // Axe Y (vertical)
    $pdf->SetDrawColor(200, 200, 200);
    $pdf->Line($originX - 2, $originY - $chartH - 5, $originX - 2, $originY);

    // Axe X (horizontal)
    $pdf->Line($originX - 2, $originY, $originX + count($statsByDay) * ($barWidth + 5) + 5, $originY);

    foreach ($statsByDay as $i => $row) {
        $barH = ($maxCount > 0) ? ($row['count'] / $maxCount) * $chartH : 0;
        $x    = $originX + $i * ($barWidth + 5);
        $y    = $originY - $barH;

        // Barre bleue
        $pdf->SetFillColor(37, 99, 235);
        $pdf->Rect($x, $y, $barWidth, $barH, 'F');

        // Valeur au-dessus de la barre
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetTextColor(37, 99, 235);
        $pdf->SetXY($x, $y - 7);
        $pdf->Cell($barWidth, 5, $row['count'], 0, 0, 'C');

        // Label date en dessous
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetXY($x, $originY + 2);
        $pdf->Cell($barWidth, 5, date('d/m', strtotime($row['day'])), 0, 0, 'C');
    }
}
```

---

## 🌐 PARTIE 4 — AJAX / Fetch API

### 4.1 — Décommenter et compléter app.js
Les fonctions sont déjà écrites dans les commentaires du fichier `app.js` fourni.
Il suffit de **décommenter les blocs** et supprimer les `console.warn(...)`.

```javascript
// loadEvents() — décommenter le bloc try/catch complet
// registerToEvent() — décommenter le bloc try/catch complet
// debounceSearch() — 3 lignes :
function debounceSearch() {
    clearTimeout(STATE.debounceTimer);
    STATE.debounceTimer = setTimeout(loadEvents, 400);
}
```

### 4.2 — api/stats.php (à créer entièrement)
```php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';
session_start();

// Vérifier rôle organisateur
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'organizer') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accès refusé.']);
    exit;
}

try {
    $pdo = getDB();

    // Résumé global
    $summary = $pdo->query("
        SELECT COUNT(DISTINCT e.id) AS total_events,
               COUNT(r.id)          AS total_registered,
               SUM(r.registered_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS new_last_24h,
               ROUND(AVG(COUNT(r.id) / e.capacity * 100)) AS avg_fill_pct,
               SUM(COUNT(r.id) / e.capacity >= 0.8)       AS alert_count
        FROM events e LEFT JOIN registrations r ON r.event_id = e.id
        GROUP BY e.id
    ")->fetch();

    // Top 3
    $top3 = $pdo->query("
        SELECT e.id, e.title, COUNT(r.id) AS reg,
               ROUND(COUNT(r.id) / e.capacity * 100) AS fill_pct
        FROM events e LEFT JOIN registrations r ON r.event_id = e.id
        GROUP BY e.id ORDER BY fill_pct DESC LIMIT 3
    ")->fetchAll();

    // Par événement
    $perEvent = $pdo->query("
        SELECT e.id, e.title, e.capacity, COUNT(r.id) AS registered,
               ROUND(COUNT(r.id)/e.capacity*100) AS fill_pct,
               (COUNT(r.id) >= e.capacity) AS is_full
        FROM events e LEFT JOIN registrations r ON r.event_id = e.id
        GROUP BY e.id ORDER BY e.event_date ASC
    ")->fetchAll();

    // Par jour (7 derniers jours)
    $byDay = $pdo->query("
        SELECT DATE(registered_at) AS day, COUNT(*) AS count
        FROM registrations
        WHERE registered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(registered_at) ORDER BY day ASC
    ")->fetchAll();

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
```

---

## 📋 PARTIE 5 — SCENARIO.md (à rédiger)

```markdown
# SCENARIO.md — Tests bout-en-bout EventHub Pro

## Étape 1 — Création de l'événement
- Action : POST vers events/create.php avec title="DevFest Marrakech 2026", capacity=5
- Résultat : Inséré en BD, JSON {"success":true, "event_id":4}

## Étape 2 — 4 inscriptions
- Action : POST vers events/register.php (4 fois avec emails différents)
- Résultat : 4 lignes dans registrations, 4 emails de confirmation envoyés

## Étape 3 — Seuil 80% (4ème inscrit)
- Action : 4ème inscription → registered_count=4, capacity=5 → 80%
- Résultat : alert_sent=1 dans events, email alerte avec PDF en pièce jointe

## Étape 4 — Événement complet (5ème inscrit)
- Action : POST register.php → registered_count=5
- Résultat : JSON {"success":true, "is_full":true}, bouton désactivé côté JS

## Étape 5 — Rapport PDF
- Action : GET pdf/report.php?event_id=4
- Résultat : PDF 3 pages téléchargé (résumé, liste inscrits, graphique barres)

## Étape 6 — Désinscription
- Action : GET events/unsubscribe.php?token=<token>
- Résultat : Ligne supprimée dans registrations, nb_exemplaires mis à jour
```

---

## ⭐ BONUS MVC (+5 pts)

Commencer par les parties 1-5. Si le temps reste, refactoriser dans cet ordre :

1. **Models/** en premier (mécanique) — déplacer les requêtes PDO
2. **Controllers/** ensuite — déplacer la logique métier
3. **core/Database.php** — Singleton PDO
4. **core/Router.php** — dispatcher les URL vers Controller@action
5. **public/index.php** — Front Controller unique

```
app/
├── Models/
│   ├── EventModel.php        ← requêtes PDO events
│   ├── RegistrationModel.php ← requêtes PDO registrations
│   └── UserModel.php
├── Views/
│   ├── events/index.php
│   ├── events/create.php
│   ├── dashboard/index.php
│   └── layouts/header.php
└── Controllers/
    ├── EventController.php
    ├── MailController.php
    ├── PdfController.php
    └── ApiController.php
core/
├── Router.php
├── Controller.php   (abstraite)
└── Database.php     (Singleton)
public/
└── index.php        (Front Controller)
```

---

## 📦 À TÉLÉCHARGER avant l'examen

| Bibliothèque | Usage | Source |
|---|---|---|
| XAMPP | PHP 8+, MySQL, Apache | apachefriends.org |
| TCPDF | Génération PDF (Parties 3.1 + 3.2) | tcpdf.org → déposer dans lib/tcpdf/ |
| phpqrcode | QR Code sur les tickets | sourceforge.net/projects/phpqrcode/ → lib/phpqrcode/ |
| PHPMailer | Envoi emails (Parties 2.1 + 2.2) | github.com/PHPMailer/PHPMailer → lib/PHPMailer/src/ |
| Mailtrap | Tester les emails sans les envoyer | mailtrap.io (compte gratuit) |

---

## ✅ Checklist avant de rendre

- [ ] database/schema.sql : table registrations + alert_sent + index + données test
- [ ] events/create.php : 3 bugs corrigés avec commentaires justificatifs
- [ ] api/events.php : searchEvents() complété (filtres + pagination)
- [ ] events/register.php : insertion + token + email confirmation + détection 80%
- [ ] mail/SendConfirmation.php : template chargé + placeholders + PHPMailer send()
- [ ] mail/AlertMailer.php : vérif alert_sent + PDF joint + marquer envoyé
- [ ] pdf/ticket.php : TCPDF ou Dompdf + QR code + couleur dynamique
- [ ] pdf/report.php : 3 pages + graphique barres PHP pur
- [ ] api/stats.php : 4 requêtes JSON + vérif session organisateur
- [ ] assets/js/app.js : 4 fonctions décommentées (loadEvents, register, debounce, dashboard)
- [ ] SCENARIO.md rédigé avec les 6 étapes
- [ ] CHOIX_TECHNIQUES.md : justifier TCPDF vs Dompdf + stratégie anti-doublon email
- [ ] pdf/samples/ : ticket_example.pdf + report_example.pdf générés

