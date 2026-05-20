<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  EventHub Pro — pdf/report.php                              ║
 * ║  Rapport de gestion multi-pages pour l'organisateur         ║
 * ║  ENSA Marrakech — Examen PHP Avancé                         ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * STATUT : ✅ Complété — Partie 3.2
 *
 * Choix bibliothèque : TCPDF (cohérence avec ticket.php)
 * Justification : TCPDF fournit des primitives de dessin natives
 * (Rect, Line, Cell, SetFillColor) indispensables pour le graphique
 * en barres PHP pur (Page 3). Dompdf aurait nécessité des images
 * SVG externes ou du CSS trop complexe pour les graphiques.
 *
 * USAGE :
 *   GET  /pdf/report.php?event_id=3          → téléchargement navigateur
 *   $path = generateReportPDF($pdo, 3, 'F', $path)  → pour email (Partie 2.2)
 */

require_once __DIR__ . '/../config/db.php';

// ── Inclusion TCPDF avec fallback multi-chemins ────────────────────────────
$tcpdfPath = __DIR__ . '/../lib/tcpdf/tcpdf.php';
if (!file_exists($tcpdfPath)) {
    $altPaths = [
        __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php',
        __DIR__ . '/../tcpdf/tcpdf.php',
        'tcpdf/tcpdf.php'
    ];
    foreach ($altPaths as $path) {
        if (file_exists($path)) {
            $tcpdfPath = $path;
            break;
        }
    }
}
if (!file_exists($tcpdfPath)) {
    die('Erreur : TCPDF non trouvé. Chemin testé : ' . $tcpdfPath);
}
require_once $tcpdfPath;

// ═════════════════════════════════════════════════════════════════════════
// CLASSE TCPDF PERSONNALISÉE — En-tête et pied de page automatiques
// ═════════════════════════════════════════════════════════════════════════

class EventHubReport extends TCPDF
{
    private array $event;

    public function __construct(array $event)
    {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8');
        $this->event = $event;
    }

    public function Header(): void
    {
        $this->SetFillColor(15, 31, 61);
        $this->Rect(0, 0, 210, 16, 'F');

        $this->SetFont('helvetica', 'B', 10);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(10, 4);
        $this->Cell(100, 8, 'EventHub Pro', 0, 0, 'L');

        $this->SetFont('helvetica', '', 8);
        $title = mb_strlen($this->event['title']) > 40
            ? mb_substr($this->event['title'], 0, 37) . '...'
            : $this->event['title'];
        $this->SetXY(100, 4);
        $this->Cell(100, 8, $title, 0, 0, 'R');

        $this->SetDrawColor(37, 99, 235);
        $this->SetLineWidth(0.5);
        $this->Line(0, 16, 210, 16);
    }

    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetDrawColor(203, 213, 225);
        $this->SetLineWidth(0.3);
        $this->Line(10, $this->GetY(), 200, $this->GetY());

        $this->SetFont('helvetica', 'I', 7);
        $this->SetTextColor(148, 163, 184);
        $this->SetXY(10, $this->GetY() + 2);
        $this->Cell(95, 4, 'Généré le ' . date('d/m/Y à H:i'), 0, 0, 'L');
        $this->Cell(95, 4, 'Page ' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}

// ═════════════════════════════════════════════════════════════════════════
// FONCTION PRINCIPALE
// ═════════════════════════════════════════════════════════════════════════

/**
 * Génère le rapport PDF complet pour un événement.
 *
 * @param  PDO    $pdo
 * @param  int    $eventId
 * @param  string $output    'D'=download | 'F'=save file | 'I'=inline | 'S'=string
 * @param  string $filePath  Chemin si output = 'F'
 * @return string|void       Chemin du fichier si output='F', rien sinon
 * @throws Exception         Si événement introuvable ou erreur TCPDF
 */
function generateReportPDF(PDO $pdo, int $eventId, string $output = 'D', string $filePath = ''): ?string
{
    // ── 1. Récupérer les données de l'événement ─────────────────────────
    $stmt = $pdo->prepare(
        'SELECT e.*,
                u.name                                   AS organizer_name,
                COUNT(r.id)                              AS registered_count,
                (e.capacity - COUNT(r.id))               AS available_places,
                ROUND(COUNT(r.id) / e.capacity * 100, 1) AS fill_pct
         FROM   events e
         LEFT JOIN users u ON u.id = e.organizer_id
         LEFT JOIN registrations r ON r.event_id = e.id
         WHERE  e.id = :id
         GROUP  BY e.id'
    );
    $stmt->execute([':id' => $eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    // CORRECTION CRITIQUE : Ne pas faire die() ici — ça tue le processus email
    // Retourner une exception que l'appelant peut catcher
    if (!$event) {
        throw new Exception('Événement introuvable (ID: ' . $eventId . ')');
    }

    // ── 2. Liste des inscrits ───────────────────────────────────────────
    $stmt = $pdo->prepare(
        'SELECT id, name, email, registered_at
         FROM   registrations
         WHERE  event_id = :id
         ORDER  BY name ASC'
    );
    $stmt->execute([':id' => $eventId]);
    $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── 3. Statistiques — 7 derniers jours ────────────────────────────
    $stmt = $pdo->prepare(
        'SELECT DATE(registered_at) AS day, COUNT(*) AS count
         FROM   registrations
         WHERE  event_id = :id
           AND  registered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP  BY DATE(registered_at)
         ORDER  BY day ASC'
    );
    $stmt->execute([':id' => $eventId]);
    $statsByDay = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── 4. Initialisation TCPDF ───────────────────────────────────────
    $pdf = new EventHubReport($event);
    $pdf->SetCreator('EventHub Pro');
    $pdf->SetAuthor('ENSA Marrakech');
    $pdf->SetTitle('Rapport — ' . $event['title']);
    $pdf->SetMargins(15, 22, 15);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetHeaderMargin(0);
    $pdf->SetFooterMargin(12);

    // ═══════════════════════════════════════════════════════════════════
    // PAGE 1 — Résumé exécutif
    // ═══════════════════════════════════════════════════════════════════
    $pdf->AddPage();
    _sectionTitle($pdf, 'Résumé exécutif');

    // Carte info événement
    $pdf->SetFillColor(241, 245, 249);
    $pdf->Rect(15, 38, 180, 50, 'F');

    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetTextColor(15, 31, 61);
    $pdf->SetXY(20, 42);
    $pdf->MultiCell(170, 7, $event['title'], 0, 'L');

    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(71, 85, 105);

    $rows = [
        ['Date',         date('d/m/Y à H:i', strtotime($event['event_date']))],
        ['Lieu',         $event['location']],
        ['Organisateur', $event['organizer_name'] ?? 'N/A'],
        ['Catégorie',    ucfirst($event['category'] ?? 'N/A')],
    ];
    $y = 55;
    foreach ($rows as [$label, $value]) {
        $pdf->SetXY(20, $y);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(40, 5, $label . ' :', 0, 0);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(130, 5, $value, 0, 1);
        $y += 7;
    }

    // Métriques (3 tuiles)
    _metricCard($pdf,  15,  96, 55, 'Capacité totale', (string)$event['capacity'],       [15, 31, 61]);
    _metricCard($pdf,  80,  96, 55, 'Inscrits',         (string)$event['registered_count'], [37, 99, 235]);
    _metricCard($pdf, 145,  96, 50, 'Places libres',    (string)$event['available_places'], [22, 163, 74]);

    // Jauge de remplissage
    $pdf->SetY(140);
    _fillBar($pdf, (float)$event['fill_pct']);

    // Date génération
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(148, 163, 184);
    $pdf->SetXY(15, 168);
    $pdf->Cell(180, 5, 'Rapport généré le ' . date('d/m/Y à H:i:s'), 0, 0, 'C');

    // ═══════════════════════════════════════════════════════════════════
    // PAGE 2 — Liste des inscrits
    // ═══════════════════════════════════════════════════════════════════
    $pdf->AddPage();
    _sectionTitle($pdf, 'Liste des inscrits (' . count($registrations) . ')');

    if (empty($registrations)) {
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->SetTextColor(148, 163, 184);
        $pdf->SetXY(15, 50);
        $pdf->Cell(180, 10, 'Aucun inscrit pour cet événement.', 0, 0, 'C');
    } else {
        $pdf->SetY(38);
        _tableHeader($pdf, ['#', 'Nom', 'Email', "Date d'inscription"], [12, 55, 80, 38]);

        $pdf->SetFont('helvetica', '', 8);
        $row = 0;
        foreach ($registrations as $reg) {
            $pdf->SetFillColor($row % 2 === 0 ? 248 : 255, $row % 2 === 0 ? 250 : 255, $row % 2 === 0 ? 252 : 255);
            $pdf->SetTextColor(30, 41, 59);
            $pdf->SetX(15);
            $pdf->Cell(12,  7, (string)($row + 1),                               'B', 0, 'C', true);
            $pdf->Cell(55,  7, mb_substr($reg['name'], 0, 28),                   'B', 0, 'L', true);
            $pdf->Cell(80,  7, mb_substr($reg['email'], 0, 38),                  'B', 0, 'L', true);
            $pdf->Cell(38,  7, date('d/m/Y H:i', strtotime($reg['registered_at'])), 'B', 1, 'C', true);
            $row++;
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // PAGE 3 — Graphique en barres PHP pur (DÉFI TECHNIQUE)
    // ═══════════════════════════════════════════════════════════════════
    $pdf->AddPage();
    _sectionTitle($pdf, 'Inscriptions par jour — 7 derniers jours');

    if (empty($statsByDay)) {
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->SetTextColor(148, 163, 184);
        $pdf->SetXY(15, 50);
        $pdf->Cell(180, 10, 'Aucune inscription dans les 7 derniers jours.', 0, 0, 'C');
    } else {
        $maxCount = max(array_column($statsByDay, 'count'));
        $maxCount = max($maxCount, 1);

        $chartX   = 30;
        $chartY   = 42;
        $chartH   = 100;
        $chartW   = 160;
        $originY  = $chartY + $chartH;
        $n        = count($statsByDay);
        $barW     = min(20, (int)(($chartW - 10) / $n) - 4);
        $gap      = (int)(($chartW - $n * $barW) / ($n + 1));

        // Grille horizontale
        $pdf->SetFont('helvetica', '', 6.5);
        $pdf->SetTextColor(148, 163, 184);
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->SetLineWidth(0.2);

        $gridLines = 5;
        for ($i = 0; $i <= $gridLines; $i++) {
            $yLine = $originY - ($i / $gridLines) * $chartH;
            $val   = (int)round(($i / $gridLines) * $maxCount);
            $pdf->Line($chartX, $yLine, $chartX + $chartW, $yLine);
            $pdf->SetXY($chartX - 14, $yLine - 2.5);
            $pdf->Cell(12, 5, (string)$val, 0, 0, 'R');
        }

        // Axes
        $pdf->SetDrawColor(30, 41, 59);
        $pdf->SetLineWidth(0.5);
        $pdf->Line($chartX, $chartY, $chartX, $originY);
        $pdf->Line($chartX, $originY, $chartX + $chartW, $originY);

        // Barres
        foreach ($statsByDay as $i => $row) {
            $barH = ($row['count'] / $maxCount) * $chartH;
            $x    = $chartX + $gap + $i * ($barW + $gap);
            $y    = $originY - $barH;

            $pdf->SetFillColor(37, 99, 235);
            $pdf->Rect($x, $y, $barW, $barH, 'F');
            $pdf->SetFillColor(96, 165, 250);
            $pdf->Rect($x, $y, $barW * 0.25, $barH, 'F');

            $pdf->SetFont('helvetica', 'B', 7);
            $pdf->SetTextColor(15, 31, 61);
            $pdf->SetXY($x - 1, $y - 6);
            $pdf->Cell($barW + 2, 5, (string)$row['count'], 0, 0, 'C');

            $pdf->SetFont('helvetica', '', 6.5);
            $pdf->SetTextColor(71, 85, 105);
            $pdf->SetXY($x - 1, $originY + 2);
            $pdf->Cell($barW + 2, 5, date('d/m', strtotime($row['day'])), 0, 0, 'C');
        }

        // Titres axes
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->StartTransform();
        $pdf->Rotate(90, $chartX - 20, $chartY + $chartH / 2);
        $pdf->SetXY($chartX - 20 - 10, $chartY + $chartH / 2 - 2.5);
        $pdf->Cell(20, 5, 'Inscrits', 0, 0, 'C');
        $pdf->StopTransform();

        $pdf->SetXY($chartX + $chartW / 2 - 10, $originY + 10);
        $pdf->Cell(20, 5, 'Date', 0, 0, 'C');

        $pdf->SetFont('helvetica', 'I', 7.5);
        $pdf->SetTextColor(148, 163, 184);
        $pdf->SetXY(15, $originY + 20);
        $pdf->Cell(180, 5, 'Données des 7 derniers jours à compter du ' . date('d/m/Y'), 0, 0, 'C');
    }

    // ═══════════════════════════════════════════════════════════════════
    // OUTPUT
    // ═══════════════════════════════════════════════════════════════════
    if ($output === 'F' && $filePath !== '') {
        $pdf->Output($filePath, 'F');
        return $filePath;
    }

    if ($output === 'S') {
        return $pdf->Output('', 'S');
    }

    $pdf->Output('rapport_evenement_' . $eventId . '.pdf', $output);
    return null;
}

// ═════════════════════════════════════════════════════════════════════════
// HELPERS
// ═════════════════════════════════════════════════════════════════════════

function _sectionTitle(TCPDF $pdf, string $title): void
{
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetTextColor(15, 31, 61);
    $pdf->SetXY(15, 24);
    $pdf->Cell(180, 8, $title, 0, 1, 'L');
    $pdf->SetDrawColor(37, 99, 235);
    $pdf->SetLineWidth(0.6);
    $pdf->Line(15, 33, 195, 33);
}

function _metricCard(TCPDF $pdf, float $x, float $y, float $w, string $label, string $value, array $color): void
{
    $pdf->SetFillColor(241, 245, 249);
    $pdf->Rect($x, $y, $w, 30, 'F');
    $pdf->SetFillColor(...$color);
    $pdf->Rect($x, $y, $w, 2.5, 'F');
    $pdf->SetFont('helvetica', '', 7.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY($x, $y + 6);
    $pdf->Cell($w, 5, strtoupper($label), 0, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->SetTextColor(...$color);
    $pdf->SetXY($x, $y + 13);
    $pdf->Cell($w, 10, $value, 0, 0, 'C');
}

function _fillBar(TCPDF $pdf, float $pct): void
{
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(15, 31, 61);
    $pdf->SetXY(15, 140);
    $pdf->Cell(180, 6, 'Taux de remplissage : ' . $pct . ' %', 0, 1);
    $pdf->SetFillColor(226, 232, 240);
    $pdf->Rect(15, 148, 180, 7, 'F');

    if ($pct >= 80) {
        $pdf->SetFillColor(220, 38, 38);
    } elseif ($pct >= 60) {
        $pdf->SetFillColor(234, 88, 12);
    } else {
        $pdf->SetFillColor(22, 163, 74);
    }

    $filled = max(1, (int)round($pct * 1.8));
    $pdf->Rect(15, 148, $filled, 7, 'F');

    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY(15, 149);
    $pdf->Cell($filled, 5, $pct . ' %', 0, 0, 'C');
}

function _tableHeader(TCPDF $pdf, array $headers, array $widths): void
{
    $pdf->SetFillColor(15, 31, 61);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetX(15);
    foreach ($headers as $i => $h) {
        $pdf->Cell($widths[$i], 8, $h, 0, 0, 'C', true);
    }
    $pdf->Ln();
}

// ── Point d'entrée GET ────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli' && isset($_GET['event_id'])) {
    try {
        $pdo = getDB();
        generateReportPDF($pdo, (int)$_GET['event_id'], 'D');
    } catch (Exception $e) {
        http_response_code(404);
        die($e->getMessage());
    }
}