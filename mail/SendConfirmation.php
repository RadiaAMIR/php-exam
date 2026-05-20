<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  EventHub Pro — mail/SendConfirmation.php                   ║
 * ║  Email de confirmation d'inscription                        ║
 * ║  ENSA Marrakech — Examen PHP Avancé                         ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * STATUT : 🔴 À compléter — Partie 2.1
 *
 * APPELÉ DEPUIS : events/register.php après inscription réussie
 *
 * À IMPLÉMENTER :
 *   🔴  Charger le template HTML (mail/templates/confirmation.html)
 *   🔴  Remplacer les placeholders par les vraies données
 *   🔴  Envoyer l'email avec PHPMailer
 *   🔴  Retourner true/false selon le résultat
 *   🔴  Logger les erreurs avec logMailError() si l'envoi échoue
 */

require_once __DIR__ . '/../config/mailer.php';
require_once __DIR__ . '/../config/db.php';

class SendConfirmation
{
    /**
     * Envoie l'email de confirmation d'inscription.
     *
     * @param  PDO    $pdo
     * @param  array  $event   Données de l'événement (depuis la BD)
     * @param  string $name    Nom du participant
     * @param  string $email   Email du participant
     * @param  string $token   Token unique de désinscription
     * @return bool            true si envoi réussi, false sinon
     */
    public static function send(PDO $pdo, array $event, string $name, string $email, string $token, int $registrationId = 0): bool
    {
        // Charger le template HTML depuis le disque
        $html = file_get_contents(__DIR__ . '/templates/confirmation.html');

        // Formater la date en français : "20/09/2026 à 09h00"
        $dateFormatted = (new DateTime($event['event_date']))->format('d/m/Y à H\hi');

        // Construire les liens avec le token encodé (caractères spéciaux sécurisés)
        $unsubLink  = 'http://localhost/eventhub_mvp/events/unsubscribe.php?token=' . urlencode($token);
        $ticketLink = 'http://localhost/eventhub_mvp/pdf/ticket.php?token='         . urlencode($token);

        // Injecter les données — htmlspecialchars() sur toutes les valeurs utilisateur
        $html = str_replace('{{PARTICIPANT_NAME}}', htmlspecialchars($name,              ENT_QUOTES, 'UTF-8'), $html);
        $html = str_replace('{{EVENT_TITLE}}',      htmlspecialchars($event['title'],    ENT_QUOTES, 'UTF-8'), $html);
        $html = str_replace('{{EVENT_DATE}}',        $dateFormatted,                                           $html);
        $html = str_replace('{{EVENT_LOCATION}}',   htmlspecialchars($event['location'], ENT_QUOTES, 'UTF-8'), $html);
        $html = str_replace('{{TICKET_LINK}}',       $ticketLink,                                              $html);
        $html = str_replace('{{UNSUBSCRIBE_LINK}}',  $unsubLink,                                               $html);
        $html = str_replace('{{YEAR}}',              date('Y'),                                                $html);

        // Générer le ticket PDF en mémoire pour le joindre à l'email
        $pdfContent = null;
        if ($registrationId > 0) {
            try {
                require_once __DIR__ . '/../pdf/ticket.php';
                $pdfContent = generateTicketPDF($pdo, $registrationId, $token, 'S');
            } catch (\Exception $e) {
                error_log('[EventHub] Erreur génération ticket PDF pour mail : ' . $e->getMessage());
            }
        }

        try {
            $mail = createMailer();
            $mail->addAddress($email, $name);
            $mail->Subject = 'Votre inscription — ' . $event['title'];
            $mail->Body    = $html;
            $mail->AltBody = strip_tags($html);

            // Joindre le ticket PDF si généré avec succès
            if ($pdfContent !== null && $pdfContent !== '') {
                $mail->addStringAttachment(
                    $pdfContent,
                    'ticket_eventhub_' . $registrationId . '.pdf',
                    'base64',
                    'application/pdf'
                );
            }

            $mail->send();
            return true;

        } catch (\PHPMailer\PHPMailer\Exception $e) {
            // Logger l'erreur sans relancer l'exception : l'inscription reste valide
            logMailError($pdo, 'confirmation', $email, $e->getMessage());
            return false;
        }
    }
}
