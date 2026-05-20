<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/tcpdf/tcpdf.php';

function generateTicketPDF(
    PDO $pdo,
    int $registrationId,
    string $token,
    string $output = 'D',
    string $filePath = ''
) {
    $stmt = $pdo->prepare(
        'SELECT r.id AS registration_id,
                r.name,
                r.email,
                r.registered_at,
                e.id AS event_id,
                e.title,
                e.event_date,
                e.location,
                e.category,
                e.capacity,
                COUNT(reg2.id) AS registered_count
         FROM registrations r
         JOIN events e ON e.id = r.event_id
         LEFT JOIN registrations reg2 ON reg2.event_id = e.id
         WHERE r.id = :rid AND r.token = :token
         GROUP BY r.id'
    );
    $stmt->execute([':rid' => $registrationId, ':token' => $token]);
    $data = $stmt->fetch();

    if (!$data) {
        http_response_code(404);
        die('Inscription introuvable ou token invalide.');
    }

    $categoryColors = [
        'tech' => ['primary' => '#2563EB', 'light' => '#DBEAFE'],
        'design' => ['primary' => '#7C3AED', 'light' => '#EDE9FE'],
        'business' => ['primary' => '#EA580C', 'light' => '#FEF3C7'],
        'science' => ['primary' => '#16A34A', 'light' => '#DCFCE7'],
    ];
    $colors = $categoryColors[$data['category']] ?? ['primary' => '#0F1F3D', 'light' => '#F8FAFC'];

    $hex = ltrim($colors['primary'], '#');
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    $pdf = new TCPDF('L', 'mm', 'A5', true, 'UTF-8', false);
    $pdf->SetCreator('EventHub Pro');
    $pdf->SetAuthor('ENSA Marrakech');
    $pdf->SetTitle('Ticket - ' . $data['title']);
    $pdf->SetPrintHeader(false);
    $pdf->SetPrintFooter(false);
    $pdf->SetMargins(0, 0, 0);
    $pdf->AddPage();

    $pdf->SetFillColor($r, $g, $b);
    $pdf->Rect(0, 0, 210, 12, 'F');

    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY(0, 1);
    $pdf->Cell(210, 10, "TICKET D'ENTREE - EventHub Pro", 0, 0, 'C');

    $logoPath = __DIR__ . '/../assets/img/logo.png';
    if (file_exists($logoPath)) {
        $pdf->Image($logoPath, 8, 14, 30, 0, 'PNG');
    }

    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->SetXY(120, 14);
    $pdf->Cell(80, 8, 'No ' . str_pad((string)$registrationId, 5, '0', STR_PAD_LEFT), 0, 0, 'R');

    $pdf->SetFont('helvetica', 'B', 15);
    $pdf->SetTextColor($r, $g, $b);
    $pdf->SetXY(8, 26);
    $pdf->MultiCell(128, 8, $data['title'], 0, 'L');

    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(60, 60, 60);
    $pdf->SetXY(8, 44);
    $pdf->Cell(130, 6, 'Date : ' . date('d/m/Y a H\hi', strtotime($data['event_date'])), 0, 1);
    $pdf->SetX(8);
    $pdf->Cell(130, 6, 'Lieu : ' . $data['location'], 0, 1);

    $pdf->SetDrawColor($r, $g, $b);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(8, 60, 140, 60);

    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetXY(8, 63);
    $pdf->Cell(120, 5, 'PARTICIPANT', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetXY(8, 68);
    $pdf->Cell(120, 5, $data['name'], 0, 1);
    $pdf->SetXY(8, 73);
    $pdf->Cell(120, 5, $data['email'], 0, 1);
    $pdf->SetXY(8, 78);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(150, 150, 150);
    $pdf->Cell(120, 5, 'Inscrit le ' . date('d/m/Y a H:i', strtotime($data['registered_at'])), 0, 1);

    $qrData = $data['event_id'] . '|' . $registrationId . '|' . $token;
    $style = [
        'border' => 0,
        'vpadding' => 'auto',
        'hpadding' => 'auto',
        'fgcolor' => [15, 31, 61],
        'bgcolor' => false,
        'module_width' => 1,
        'module_height' => 1,
    ];
    $pdf->write2DBarcode($qrData, 'QRCODE,M', 150, 20, 50, 50, $style, 'N');
    $pdf->SetFont('helvetica', '', 7);
    $pdf->SetTextColor(150, 150, 150);
    $pdf->SetXY(148, 72);
    $pdf->Cell(54, 5, 'Scanner pour valider', 0, 0, 'C');

    $baseHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/eventhub_mvp/pdf/ticket.php'));
    $appPath = rtrim(dirname($scriptDir), '/');
    if ($appPath === '' || $appPath === '.') {
        $appPath = '/eventhub_mvp';
    }
    $pdf->SetFont('helvetica', 'I', 7);
    $pdf->SetTextColor(180, 180, 180);
    $pdf->SetXY(0, 130);
    $pdf->Cell(210, 5, 'Desinscription : ' . $scheme . '://' . $baseHost . $appPath . '/events/unsubscribe.php?token=' . $token, 0, 0, 'C');

    $pdf->SetFillColor($r, $g, $b);
    $pdf->Rect(0, 136, 210, 6, 'F');

    if ($output === 'F' && $filePath !== '') {
        $pdf->Output($filePath, 'F');
        return $filePath;
    }

    if ($output === 'S') {
        return $pdf->Output('ticket_' . $registrationId . '.pdf', 'S');
    }

    $pdf->Output('ticket_' . $registrationId . '.pdf', 'D');
}

if (php_sapi_name() !== 'cli') {
    $pdo = getDB();

    if (isset($_GET['token']) && !isset($_GET['registration_id'])) {
        // Accès par token seul (lien dans l'email de confirmation)
        $stmt = $pdo->prepare('SELECT id FROM registrations WHERE token = :token');
        $stmt->execute([':token' => $_GET['token']]);
        $row = $stmt->fetch();
        if (!$row) { http_response_code(404); die('Token invalide ou inscription introuvable.'); }
        generateTicketPDF($pdo, (int)$row['id'], (string)$_GET['token'], 'D');
    } elseif (isset($_GET['registration_id'], $_GET['token'])) {
        generateTicketPDF($pdo, (int)$_GET['registration_id'], (string)$_GET['token'], 'D');
    }
}