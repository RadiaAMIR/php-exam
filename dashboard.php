<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  EventHub Pro — dashboard.php                               ║
 * ║  Tableau de bord organisateur — temps réel                  ║
 * ║  ENSA Marrakech — Examen PHP Avancé                         ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * STATUT : ✅ Complété — Partie 4.2
 *
 * ACCÈS : Réservé aux organisateurs (vérification session)
 * MISE À JOUR : Automatique toutes les 30s via fetch() (app.js)
 */

session_start();

// ════════════════════════════════════════════════════════════════════════
// SÉCURITÉ : ACCÈS RÉSERVÉ AUX ORGANISATEURS
// ════════════════════════════════════════════════════════════════════════
// CORRECTION CRITIQUE : Vérifier que l'utilisateur est connecté ET
// qu'il a le rôle 'organizer'. Sinon, rediriger vers la page de
// connexion avec un message d'erreur.
// ════════════════════════════════════════════════════════════════════════

$isLoggedIn = isset($_SESSION['user_id']);
$isOrganizer = ($_SESSION['user_role'] ?? '') === 'organizer';

if (!$isLoggedIn || !$isOrganizer) {
    // Redirection vers la page de connexion avec message
    $_SESSION['redirect_after_login'] = 'dashboard.php';
    header('Location: login.php?error=access_denied&role=organizer_required');
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — EventHub Pro</title>
    <style>
        /* ══ RESET & BASE ════════════════════════════════════════════════ */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: #F8FAFC;
            color: #334155;
            line-height: 1.6;
        }

        /* ══ HEADER ════════════════════════════════════════════════════════ */
        .header {
            background: #0F1F3D;
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 {
            font-size: 1.25rem;
            font-weight: 700;
            letter-spacing: -0.02em;
        }
        .header .badge-role {
            background: #2563EB;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .header nav a {
            color: #94A3B8;
            text-decoration: none;
            margin-left: 1.5rem;
            font-size: 0.875rem;
            transition: color 0.2s;
        }
        .header nav a:hover { color: white; }
        .header .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .header .user-name {
            font-size: 0.875rem;
            color: #CBD5E1;
        }

        /* ══ CONTAINER ═══════════════════════════════════════════════════ */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* ══ KPI CARDS ═════════════════════════════════════════════════════ */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        .kpi-card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            border: 1px solid #E2E8F0;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .kpi-card .value {
            font-size: 2.25rem;
            font-weight: 800;
            color: #0F1F3D;
            line-height: 1;
            margin-bottom: 0.5rem;
            transition: color 0.3s ease;
        }
        .kpi-card .label {
            font-size: 0.875rem;
            color: #64748B;
            font-weight: 500;
        }
        .kpi-card .kpi-icon {
            font-size: 1.5rem;
            margin-bottom: 0.75rem;
        }

        /* Couleurs spécifiques par KPI */
        .kpi-total    .kpi-icon { color: #2563EB; }
        .kpi-new      .kpi-icon { color: #16A34A; }
        .kpi-alert    .kpi-icon { color: #F59E0B; }
        .kpi-taux     .kpi-icon { color: #7C3AED; }

        /* ══ SECTIONS ══════════════════════════════════════════════════════ */
        .section {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            border: 1px solid #E2E8F0;
        }
        .section-title {
            font-size: 1.125rem;
            font-weight: 700;
            color: #0F1F3D;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #2563EB;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* ══ TOP 3 ═════════════════════════════════════════════════════════ */
        #top3-container {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .top3-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem;
            border-radius: 0.75rem;
            transition: all 0.3s ease;
        }
        .top3-item:hover { transform: translateX(4px); }
        .top3-rank {
            font-size: 1.5rem;
            width: 2.5rem;
            text-align: center;
        }
        .top3-info { flex: 1; margin-left: 1rem; }
        .top3-title {
            font-weight: 700;
            color: #1E293B;
            font-size: 0.9375rem;
        }
        .top3-meta {
            font-size: 0.8125rem;
            color: #64748B;
            margin-top: 0.125rem;
        }
        .top3-pct {
            font-size: 1.5rem;
            font-weight: 800;
        }

        /* ══ TABLEAU ═══════════════════════════════════════════════════════ */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        .data-table th {
            text-align: left;
            padding: 0.875rem 1rem;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748B;
            background: #F8FAFC;
            border-bottom: 2px solid #E2E8F0;
        }
        .data-table td {
            padding: 0.875rem 1rem;
            border-bottom: 1px solid #F1F5F9;
            font-size: 0.875rem;
        }
        .data-table tr:hover td { background: #F8FAFC; }
        .data-table tr { transition: background 0.2s; }

        /* Barre de progression dans le tableau */
        .table-bar {
            width: 100px;
            height: 6px;
            background: #E2E8F0;
            border-radius: 9999px;
            overflow: hidden;
        }
        .table-bar-fill {
            height: 100%;
            border-radius: 9999px;
            transition: width 0.5s ease;
        }

        /* Badges statut */
        .badge {
            display: inline-block;
            padding: 0.25rem 0.625rem;
            border-radius: 9999px;
            font-size: 0.6875rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .badge-full {
            background: #FEE2E2;
            color: #DC2626;
        }
        .badge-open {
            background: #DCFCE7;
            color: #16A34A;
        }

        /* ══ MINI CHART ════════════════════════════════════════════════════ */
        #mini-chart-container {
            display: flex;
            justify-content: center;
            padding: 1rem 0;
        }
        #mini-chart-container svg {
            max-width: 100%;
        }

        /* ══ FOOTER / STATUS ═══════════════════════════════════════════════ */
        .status-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 2rem;
            background: white;
            border-top: 1px solid #E2E8F0;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
        }
        .status-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: #64748B;
        }
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #16A34A;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        #last-update {
            font-size: 0.8125rem;
            color: #94A3B8;
            font-style: italic;
            transition: color 0.3s;
        }

        /* ══ TOAST CONTAINER ═══════════════════════════════════════════════ */
        #toast-container {
            position: fixed;
            top: 1.5rem;
            right: 1.5rem;
            z-index: 50;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .toast {
            padding: 1rem 1.25rem;
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            max-width: 320px;
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { transform: translateX(120%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .toast.success { background: #16A34A; }
        .toast.error   { background: #DC2626; }
        .toast.info    { background: #2563EB; }

        /* ══ RESPONSIVE ════════════════════════════════════════════════════ */
        @media (max-width: 768px) {
            .container { padding: 1rem; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .header { flex-direction: column; gap: 1rem; text-align: center; }
            .status-bar { flex-direction: column; gap: 0.5rem; }
        }
    </style>
</head>
<body>

    <!-- ════════════════════════════════════════════════════════════════════
         HEADER
         ════════════════════════════════════════════════════════════════════ -->
    <header class="header">
        <div style="display:flex; align-items:center; gap:1rem;">
            <h1>📊 EventHub Pro — Dashboard</h1>
            <span class="badge-role">ORGANISATEUR</span>
        </div>
        <div class="user-info">
            <span class="user-name">
                <?= htmlspecialchars($_SESSION['user_name'] ?? 'Organisateur') ?>
            </span>
            <nav>
                <a href="index.php">← Retour aux événements</a>
                <a href="events/create.php">+ Créer un événement</a>
                <a href="logout.php" style="color:#F87171;">Déconnexion</a>
            </nav>
        </div>
    </header>

    <!-- ════════════════════════════════════════════════════════════════════
         CONTENU PRINCIPAL
         ════════════════════════════════════════════════════════════════════ -->
    <main class="container" id="dashboard-container">

        <!-- KPIs -->
        <div class="kpi-grid">
            <div class="kpi-card kpi-total">
                <div class="kpi-icon">👥</div>
                <div class="value" id="kpi-total">0</div>
                <div class="label">Total inscrits</div>
            </div>
            <div class="kpi-card kpi-new">
                <div class="kpi-icon">🆕</div>
                <div class="value" id="kpi-new-24h">0</div>
                <div class="label">Nouveaux (24h)</div>
            </div>
            <div class="kpi-card kpi-alert">
                <div class="kpi-icon">⚠️</div>
                <div class="value" id="kpi-alertes">0</div>
                <div class="label">Alertes 80%</div>
            </div>
            <div class="kpi-card kpi-taux">
                <div class="kpi-icon">📈</div>
                <div class="value" id="kpi-taux">0%</div>
                <div class="label">Taux moyen</div>
            </div>
        </div>

        <!-- Top 3 -->
        <section class="section">
            <h2 class="section-title">🏆 Top 3 — Événements les plus remplis</h2>
            <div id="top3-container">
                <p style="color:#94A3B8; text-align:center; padding:2rem;">Chargement…</p>
            </div>
        </section>

        <!-- Tableau détaillé -->
        <section class="section">
            <h2 class="section-title">📋 Tous les événements</h2>
            <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Événement</th>
                            <th>Inscrits</th>
                            <th>Progression</th>
                            <th>Taux</th>
                            <th>Statut</th>
                        </tr>
                    </thead>
                    <tbody id="dashboard-table-body">
                        <tr><td colspan="5" style="text-align:center; color:#94A3B8; padding:2rem;">Chargement…</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Mini graphique -->
        <section class="section">
            <h2 class="section-title">📊 Inscriptions par jour (7 derniers jours)</h2>
            <div id="mini-chart-container">
                <p style="color:#94A3B8; text-align:center; padding:2rem;">Chargement…</p>
            </div>
        </section>

    </main>

    <!-- ════════════════════════════════════════════════════════════════════
         BARRE DE STATUS (fixe en bas)
         ════════════════════════════════════════════════════════════════════ -->
    <div class="status-bar">
        <div class="status-indicator">
            <span class="status-dot"></span>
            <span>Live — Mise à jour automatique</span>
        </div>
        <span id="last-update">Initialisation…</span>
    </div>

    <!-- Toast container -->
    <div id="toast-container"></div>

    <!-- ════════════════════════════════════════════════════════════════════
         SCRIPTS
         ════════════════════════════════════════════════════════════════════ -->
    <script src="assets/js/app.js"></script>

</body>
</html>w