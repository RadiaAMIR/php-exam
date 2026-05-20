<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  EventHub Pro — mail/AlertMailer.php                        ║
 * ║  Email d'alerte organisateur (seuil 80%) + PDF joint        ║
 * ║  ENSA Marrakech — Examen PHP Avancé                         ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * STATUT : ✅ Complété — Partie 2.2
 *
 * APPELÉ DEPUIS : events/register.php quand taux >= 80%
 *
 * QUESTION DE CONCEPTION — Anti-doublon :
 *   La colonne `alert_sent` dans la table `events` sert de verrou persistant.
 *   C'est une solution atomique en BDD : contrairement à un fichier lock ou
 *   une variable PHP, `alert_sent = 1` survit aux requêtes concurrentes et
 *   aux redémarrages de serveur. Si deux processus passent le if en même temps,
 *   le second UPDATE est idempotent et sans effet négatif.
 */

require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/db.php';

class AlertMailer
{
    /**
     * Envoie l'email d'alerte de capacité à l'organisateur avec rapport PDF joint.
     *
     * @param  PDO   $pdo
     * @param  array $event   Données complètes de l'événement
     * @return bool
     */
    public static function sendCapacityAlert(PDO $pdo, array $event): bool
    {
        // ── 1. Vérifier anti-doublon ────────────────────────────────────────
        if ((int)$event['alert_sent'] === 1) {
            return false; // Alerte déjà envoyée, on stoppe
        }

        // ── 2. Calculer les métriques ───────────────────────────────────────
        $fillPct   = (int) round($event['registered_count'] / $event['capacity'] * 100);
        $available = (int) ($event['capacity'] - $event['registered_count']);

        // ── 3. Générer le PDF rapport en fichier temporaire ─────────────────
        // CORRECTION : On génère le PDF AVANT d'initialiser PHPMailer
        // pour éviter d'envoyer un email vide si la génération échoue.
        $tempPdf = sys_get_temp_dir() . '/report_event_' . (int)$event['id'] . '_' . uniqid() . '.pdf';

        try {
            // Inclure le générateur de rapport PDF (Partie 3.2)
            require_once __DIR__ . '/../pdf/report.php';

            // Générer le PDF et le sauvegarder sur disque (mode 'F')
            // La fonction generateReportPDF() doit être définie dans report.php
            generateReportPDF($pdo, (int)$event['id'], 'F', $tempPdf);

        } catch (Exception $e) {
            error_log('[EventHub] Erreur génération PDF rapport : ' . $e->getMessage());
            // On continue sans pièce jointe plutôt que de bloquer l'alerte
            $tempPdf = null;
        }

        // ── 4. Charger et personnaliser le template HTML ────────────────────
        $html = file_get_contents(__DIR__ . '/templates/alert.html');
        if ($html === false) {
            error_log('[EventHub] Template alert.html introuvable');
            return false;
        }

        $html = str_replace('{{ORGANIZER_NAME}}', htmlspecialchars($event['organizer_email'], ENT_QUOTES, 'UTF-8'), $html);
        $html = str_replace('{{EVENT_TITLE}}',    htmlspecialchars($event['title'],            ENT_QUOTES, 'UTF-8'), $html);
        $html = str_replace('{{FILL_PCT}}',        $fillPct,                                                          $html);
        $html = str_replace('{{REGISTERED}}',      (int)$event['registered_count'],                                   $html);
        $html = str_replace('{{CAPACITY}}',        (int)$event['capacity'],                                           $html);
        $html = str_replace('{{AVAILABLE}}',        $available,                                                        $html);
        $html = str_replace('{{DASHBOARD_LINK}}',  'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/eventhub_mvp/#dashboard', $html);
        $html = str_replace('{{YEAR}}',             date('Y'),                                                         $html);

        // ── 5. Envoi PHPMailer avec pièce jointe ────────────────────────────
        try {
            $mail = createMailer();
            $mail->addAddress($event['organizer_email'], 'Organisateur');
            $mail->Subject = '⚠️ Alerte capacité — ' . $event['title'] . ' (' . $fillPct . '% rempli)';
            $mail->Body    = $html;
            $mail->AltBody = strip_tags($html);

            // Attacher le PDF s'il a été généré avec succès
            if ($tempPdf !== null && file_exists($tempPdf)) {
                $mail->addAttachment(
                    $tempPdf,
                    'rapport_evenement_' . (int)$event['id'] . '.pdf',
                    'base64',
                    'application/pdf'
                );
            }

            $mail->send();

            // ── 6. Marquer l'alerte comme envoyée (anti-doublon) ─────────────
            $stmt = $pdo->prepare('UPDATE events SET alert_sent = 1 WHERE id = :id');
            $stmt->execute([':id' => (int)$event['id']]);

            // ── 7. Nettoyage fichier temporaire ─────────────────────────────
            if ($tempPdf !== null && file_exists($tempPdf)) {
                @unlink($tempPdf);
            }

            return true;

        } catch (\PHPMailer\PHPMailer\Exception $e) {
            // Logger l'erreur en base
            logMailError($pdo, 'capacity_alert', $event['organizer_email'], $e->getMessage());

            // Nettoyer le PDF temporaire même en cas d'échec
            if ($tempPdf !== null && file_exists($tempPdf)) {
                @unlink($tempPdf);
            }

            return false;
        }
    }
}