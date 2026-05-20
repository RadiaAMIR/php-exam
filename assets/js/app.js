/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  EventHub Pro — assets/js/app.js                            ║
 * ║  JavaScript principal — Fetch API & interactions            ║
 * ║  ENSA Marrakech — Examen PHP Avancé                         ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * STATUT : ⚠️ Partiel — Fonctions incomplètes (marquées TODO)
 *
 * CE QUI EST FOURNI :
 *   ✅  renderEventCards()     — rendu HTML des cartes
 *   ✅  showToast()            — notifications toast
 *   ✅  showSkeletons()        — squelettes de chargement
 *   ✅  setButtonLoading()     — état de chargement sur les boutons
 *   ✅  animateCounter()       — animation de compteurs
 *   ✅  formatDate()           — formatage de date en français
 *
 * À COMPLÉTER (Parties 4.1 et 4.2) :
 *   🔴  loadEvents()          — chargement via fetch + filtres
 *   🔴  registerToEvent()     — inscription via POST fetch
 *   🔴  debounceSearch()      — recherche live avec délai 400ms
 *   🔴  startDashboard()      — polling toutes les 30s
 *   🔴  fetchDashboardStats() — appel api/stats.php
 *
 * CONTRAINTES :
 *   → JavaScript vanilla uniquement (pas de jQuery, pas d'Axios)
 *   → Tous les fetch() doivent gérer les erreurs réseau (try/catch)
 *   → L'interface ne doit jamais "casser" en cas d'erreur API
 */

'use strict';

// ══════════════════════════════════════════════════════════════════════════
// ÉTAT GLOBAL
// ══════════════════════════════════════════════════════════════════════════
const STATE = {
    currentTab:    'all',       // Onglet actif : 'all' | 'upcoming' | 'full'
    dashInterval:  null,        // Référence setInterval du dashboard
    debounceTimer: null,        // Référence setTimeout du debounce
    selectedEvent: null,        // Événement sélectionné pour inscription
};

const CATEGORY_COLORS = {
    tech:     { bg: '#DBEAFE', text: '#1D4ED8', primary: '#2563EB' },
    design:   { bg: '#EDE9FE', text: '#6D28D9', primary: '#7C3AED' },
    business: { bg: '#FEF3C7', text: '#B45309', primary: '#EA580C' },
    science:  { bg: '#DCFCE7', text: '#15803D', primary: '#16A34A' },
};


// ══════════════════════════════════════════════════════════════════════════
// TODO 4.1 — CHARGEMENT DES ÉVÉNEMENTS
// ══════════════════════════════════════════════════════════════════════════

/**
 * Charge les événements depuis api/events.php et les affiche.
 *
 * PARAMÈTRES À ENVOYER EN POST (JSON) :
 *   keyword, category, has_places, tab (STATE.currentTab), page
 *
 * EN CAS DE SUCCÈS :
 *   → Appeler renderEventCards(data.data)
 *   → Mettre à jour la pagination si data.meta.pages > 1
 *
 * EN CAS D'ERREUR RÉSEAU :
 *   → showToast('Impossible de charger les événements.', 'error')
 *   → Afficher un message d'erreur dans la grille (pas de page blanche)
 *
 * LOADING STATE :
 *   → Appeler showSkeletons() avant le fetch
 *   → Les remplacer par les vraies cartes après réception
 *
 * @returns {Promise<void>}
 */
async function loadEvents() {
    const keyword   = document.getElementById('search-input')?.value ?? '';
    const category  = document.getElementById('filter-category')?.value ?? '';
    const hasPlaces = document.getElementById('filter-places')?.value === '1';

    showSkeletons();

    try {
        const response = await fetch('api/events.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                keyword,
                category,
                has_places: hasPlaces,
                tab:        STATE.currentTab,
            })
        });

        if (!response.ok) throw new Error('HTTP ' + response.status);

        const data = await response.json();

        if (data.success) {
            renderEventCards(data.data);
        } else {
            showGridError(data.error ?? 'Erreur inconnue.');
        }

    } catch (err) {
        console.error('[loadEvents]', err);
        showToast('Impossible de charger les événements.', 'error');
        showGridError('Erreur de connexion au serveur.');
    }
}


// ══════════════════════════════════════════════════════════════════════════
// TODO 4.1 — INSCRIPTION EN TEMPS RÉEL
// ══════════════════════════════════════════════════════════════════════════

/**
 * Soumet l'inscription d'un participant à un événement.
 *
 * DONNÉES À ENVOYER (POST JSON) :
 *   { event_id, name, email }
 *
 * EN CAS DE SUCCÈS (data.success === true) :
 *   → Fermer la modale d'inscription
 *   → showToast('Inscription réussie ! Ticket envoyé par email.', 'success')
 *   → Mettre à jour la barre de capacité de la carte SANS rechargement :
 *       document.getElementById('bar-' + eventId).style.width = data.capacity_pct + '%'
 *       document.getElementById('places-' + eventId).textContent = ...
 *   → Si data.is_full === true : désactiver le bouton d'inscription
 *   → Si data.alert_sent === true : showToast('Alerte 80% envoyée à l\'organisateur', 'info')
 *
 * EN CAS D'ERREUR :
 *   → showToast(data.error, 'error')
 *   → Ne pas fermer la modale
 *
 * @param {number} eventId
 * @param {string} name
 * @param {string} email
 * @returns {Promise<void>}
 */
async function registerToEvent(eventId, name, email) {
    setButtonLoading('btn-reg', true, 'Inscription en cours…');

    try {
        const response = await fetch('events/register.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ event_id: eventId, name, email })
        });

        const data = await response.json();

        if (data.success) {
            closeReg();
            showToast('Inscription réussie ! Email de confirmation envoyé.', 'success');

            // Mise à jour temps réel de la carte sans rechargement
            const barEl    = document.getElementById('bar-'    + eventId);
            const placesEl = document.getElementById('places-' + eventId);
            const btnEl    = document.getElementById('btn-'    + eventId);

            if (barEl) {
                barEl.style.width = data.capacity_pct + '%';
                if (data.is_full)                  barEl.style.background = '#DC2626';
                else if (data.capacity_pct >= 80)  barEl.style.background = '#F59E0B';
            }

            if (placesEl) {
                // Recalcule le nombre d'inscrits depuis le texte "X / Y" de la carte
                const parts = placesEl.textContent.trim().split('/');
                if (parts.length === 2) {
                    const capacity   = parseInt(parts[1].trim());
                    const registered = Math.round(capacity * data.capacity_pct / 100);
                    placesEl.textContent = registered + ' / ' + capacity;
                }
                if (data.is_full) placesEl.style.color = '#DC2626';
            }

            if (data.is_full && btnEl) {
                btnEl.disabled = true;
                btnEl.textContent = 'Complet';
                btnEl.style.background = '#94A3B8';
                btnEl.classList.add('opacity-40', 'cursor-not-allowed');
            }

            if (data.alert_sent) {
                showToast('Alerte 80% envoyée à l\'organisateur', 'info');
            }

        } else {
            showToast(data.error ?? 'Erreur lors de l\'inscription.', 'error');
        }

    } catch (err) {
        console.error('[registerToEvent]', err);
        showToast('Erreur réseau. Veuillez réessayer.', 'error');
    } finally {
        setButtonLoading('btn-reg', false, "S'inscrire & recevoir le ticket PDF");
    }
}


// ══════════════════════════════════════════════════════════════════════════
// TODO 4.1 — RECHERCHE AVEC DEBOUNCE
// ══════════════════════════════════════════════════════════════════════════

/**
 * Déclenche loadEvents() après un délai de 400ms sans frappe.
 * Annule le timer précédent si l'utilisateur tape encore.
 *
 * APPELÉ PAR : oninput sur #search-input
 *
 * EXEMPLE D'IMPLÉMENTATION ATTENDUE :
 *   clearTimeout(STATE.debounceTimer);
 *   STATE.debounceTimer = setTimeout(loadEvents, 400);
 */
function debounceSearch() {
    clearTimeout(STATE.debounceTimer);
    STATE.debounceTimer = setTimeout(loadEvents, 400);
}


// ══════════════════════════════════════════════════════════════════════════
// TODO 4.2 — DASHBOARD TEMPS RÉEL
// ══════════════════════════════════════════════════════════════════════════

/**
 * Démarre le polling automatique du dashboard (toutes les 30s).
 * Appelle fetchDashboardStats() immédiatement puis toutes les 30 secondes.
 * Arrête le polling précédent si la fonction est rappelée.
 */
function startDashboard() {
    if (STATE.dashInterval) clearInterval(STATE.dashInterval);
    fetchDashboardStats();
    STATE.dashInterval = setInterval(fetchDashboardStats, 30000);
}

/**
 * Récupère les statistiques depuis api/stats.php et met à jour le dashboard.
 *
 * EN CAS DE SUCCÈS :
 *   → Mettre à jour les KPI (animateCounter pour les nombres)
 *   → Mettre à jour le Top 3
 *   → Mettre à jour l'heure de dernière mise à jour
 *   → Si un événement vient de passer à 100% → showToast(..., 'info')
 *
 * EN CAS D'ERREUR :
 *   → Afficher un message discret (ne pas casser l'interface)
 *   → Réessayer automatiquement dans 10 secondes (clearInterval + setTimeout)
 *
 * @returns {Promise<void>}
 */
async function fetchDashboardStats() {
    try {
        const response = await fetch('api/stats.php');

        if (response.status === 403) {
            // Non connecté ou pas organisateur → afficher message d'accès refusé
            clearInterval(STATE.dashInterval);
            STATE.dashInterval = null;
            showDashboardAccessDenied();
            return;
        }

        if (!response.ok) throw new Error('HTTP ' + response.status);

        const data = await response.json();
        if (!data.success) throw new Error(data.error);

        // Mise à jour KPI
        animateCounter('d-total', parseInt(data.summary.total_registered) || 0);
        animateCounter('d-new',   parseInt(data.summary.new_last_24h)    || 0);
        animateCounter('d-alert', parseInt(data.summary.alert_count)     || 0);
        const tauxEl = document.getElementById('d-taux');
        if (tauxEl) tauxEl.textContent = (data.summary.avg_fill_pct || 0) + '%';

        // Mise à jour stats hero
        animateCounter('h-inscrits', parseInt(data.summary.total_registered) || 0);
        const complets = (data.per_event || []).filter(e => e.is_full).length;
        animateCounter('h-complets', complets);
        animateCounter('h-total', (data.per_event || []).length);
        animateCounter('h-new24', parseInt(data.summary.new_last_24h) || 0);

        // Notification si un événement vient de passer à 100%
        (data.per_event || []).forEach(e => {
            if (parseInt(e.fill_pct) >= 100) {
                showToast('🎉 ' + e.title + ' est maintenant complet !', 'info');
            }
        });

        // Top 3
        renderTop3(data.top3 || []);

        // Horodatage
        const lastUpdateEl = document.getElementById('last-update');
        if (lastUpdateEl) lastUpdateEl.textContent = 'Mis à jour à ' + new Date().toLocaleTimeString('fr-FR');

    } catch (err) {
        console.error('[fetchDashboardStats]', err);
        clearInterval(STATE.dashInterval);
        STATE.dashInterval = null;
        showToast('Erreur de chargement du dashboard. Nouvelle tentative dans 10s.', 'error');
        setTimeout(() => startDashboard(), 10000);
    }
}

function showDashboardAccessDenied() {
    const sec = document.getElementById('sec-dashboard');
    if (!sec) return;
    sec.innerHTML = `
        <div class="flex flex-col items-center justify-center py-20 text-center">
            <div class="text-5xl mb-4">🔒</div>
            <h2 class="font-display font-bold text-2xl mb-2" style="color:#e2e8f0">Accès réservé</h2>
            <p class="text-sm mb-6" style="color:#94a3b8">Le dashboard est accessible aux organisateurs uniquement.</p>
            <button onclick="openLogin()"
                class="px-6 py-3 rounded-xl font-display font-bold text-sm text-white"
                style="background:linear-gradient(135deg,#1d4ed8,#3b82f6);box-shadow:0 0 20px rgba(59,130,246,.4)">
                Se connecter en tant qu'organisateur
            </button>
        </div>`;
}

function renderTop3(top3) {
    const el = document.getElementById('top-list');
    if (!el) return;
    if (!top3 || top3.length === 0) {
        el.innerHTML = '<p class="text-slate-400 text-sm text-center py-4">Aucun événement.</p>';
        return;
    }
    const medals = ['🥇', '🥈', '🥉'];
    el.innerHTML = top3.map((e, i) => {
        const pct = parseInt(e.fill_pct) || 0;
        const color = pct >= 100 ? '#DC2626' : pct >= 80 ? '#F59E0B' : '#2563EB';
        return `
        <div class="flex items-center gap-4">
            <span class="text-2xl">${medals[i] || ''}</span>
            <div class="flex-1 min-w-0">
                <p class="font-display font-bold text-sm text-slate-900 truncate">${e.title}</p>
                <div class="cap-bar mt-1">
                    <div class="cap-bar-fill" style="width:${pct}%;background:${color}"></div>
                </div>
            </div>
            <span class="font-display font-bold text-sm" style="color:${color};min-width:40px;text-align:right">${pct}%</span>
        </div>`;
    }).join('');
}


// ══════════════════════════════════════════════════════════════════════════
// FOURNI — RENDU DES CARTES D'ÉVÉNEMENTS
// ══════════════════════════════════════════════════════════════════════════

/**
 * Génère et injecte les cartes d'événements dans #events-grid.
 *
 * @param {Array} events  Tableau d'objets événement (depuis api/events.php)
 */
function renderEventCards(events) {
    const grid = document.getElementById('events-grid');

    if (!events || events.length === 0) {
        grid.innerHTML = `
            <div class="col-span-3 text-center py-16">
                <div class="text-5xl mb-4">🔍</div>
                <p class="font-display font-bold text-lg" style="color:#94a3b8">Aucun événement trouvé</p>
                <p class="text-sm mt-2" style="color:#64748b">Modifiez vos critères de recherche</p>
            </div>`;
        return;
    }

    // Versions sombres des couleurs de catégorie
    const DARK_COLORS = {
        tech:     { bg: 'rgba(37,99,235,.15)',  text: '#93c5fd', primary: '#3b82f6' },
        design:   { bg: 'rgba(124,58,237,.15)', text: '#c4b5fd', primary: '#7c3aed' },
        business: { bg: 'rgba(234,88,12,.15)',  text: '#fdba74', primary: '#ea580c' },
        science:  { bg: 'rgba(22,163,74,.15)',  text: '#86efac', primary: '#16a34a' },
    };

    grid.innerHTML = events.map(e => {
        const pct      = parseInt(e.fill_percentage) || 0;
        const isFull   = e.available_places <= 0;
        const isWarn   = pct >= 80 && !isFull;
        const colors   = DARK_COLORS[e.category] || { bg: 'rgba(100,116,139,.15)', text: '#94a3b8', primary: '#64748b' };
        const barColor = isFull ? '#ef4444' : isWarn ? '#f59e0b' : colors.primary;

        return `
        <div class="event-card rounded-2xl overflow-hidden flex flex-col" data-event-id="${e.id}">
            <div class="h-1" style="background:linear-gradient(90deg,${colors.primary},${colors.primary}88)"></div>
            <div class="p-5 flex flex-col flex-1">
                <div class="flex items-start gap-2 mb-3 flex-wrap">
                    <span class="badge" style="background:${colors.bg};color:${colors.text}">${e.category}</span>
                    ${isFull ? '<span class="badge" style="background:rgba(239,68,68,.15);color:#ef4444">Complet</span>' : ''}
                    ${isWarn ? '<span class="badge" style="background:rgba(245,158,11,.15);color:#f59e0b">🔥 Quasi plein</span>' : ''}
                </div>
                <h3 class="font-display font-bold text-base mb-1 leading-snug" style="color:#e2e8f0">${e.title}</h3>
                <p class="text-xs mb-1" style="color:#64748b">📅 ${formatDate(e.event_date)}</p>
                <p class="text-xs mb-3" style="color:#64748b">📍 ${e.location}</p>
                <p class="text-xs leading-relaxed flex-1" style="color:#94a3b8">${e.description}</p>
                <div class="mt-4">
                    <div class="flex justify-between text-xs font-display font-bold mb-1">
                        <span style="color:#475569">Capacité</span>
                        <span style="color:${barColor}" id="places-${e.id}">
                            ${e.registered_count} / ${e.capacity}
                        </span>
                    </div>
                    <div class="cap-bar">
                        <div class="cap-bar-fill" id="bar-${e.id}"
                             style="width:${pct}%;background:${barColor}"></div>
                    </div>
                    ${!isFull ? `<p class="text-xs mt-1" style="color:#475569">${e.available_places} place(s) restante(s)</p>` : ''}
                </div>
                <button
                    id="btn-${e.id}"
                    ${isFull ? 'disabled' : `onclick="openRegisterModal(${e.id})"`}
                    class="mt-4 w-full py-2.5 rounded-xl font-display font-bold text-xs text-white tracking-wide transition"
                    style="background:${isFull ? '#1e293b' : `linear-gradient(135deg,${colors.primary},${colors.primary}cc)`};
                           color:${isFull ? '#475569' : '#fff'};
                           ${isFull ? '' : `box-shadow:0 0 14px ${colors.primary}40`};
                           cursor:${isFull ? 'not-allowed' : 'pointer'}">
                    ${isFull ? 'Complet' : "S'inscrire →"}
                </button>
            </div>
        </div>`;
    }).join('');
}


// ══════════════════════════════════════════════════════════════════════════
// FOURNI — UTILITAIRES
// ══════════════════════════════════════════════════════════════════════════

/** Affiche les squelettes de chargement dans la grille. */
function showSkeletons(count = 3) {
    const grid = document.getElementById('events-grid');
    grid.innerHTML = Array.from({ length: count }, () => `
        <div class="rounded-2xl p-5" style="background:#131d2e;border:1px solid #1f3057">
            <div class="skeleton h-1 w-full mb-4 -mx-5 -mt-5" style="width:calc(100% + 40px); border-radius:0"></div>
            <div class="skeleton h-5 w-3/4 mb-2 mt-2"></div>
            <div class="skeleton h-3 w-1/2 mb-1"></div>
            <div class="skeleton h-3 w-2/3 mb-4"></div>
            <div class="skeleton h-2 w-full mb-4"></div>
            <div class="skeleton h-9 w-28 rounded-xl"></div>
        </div>`).join('');
}

/** Affiche un message d'erreur dans la grille. */
function showGridError(message) {
    document.getElementById('events-grid').innerHTML = `
        <div class="col-span-3 text-center py-16">
            <div class="text-5xl mb-4">⚠️</div>
            <p class="font-display font-bold" style="color:#ef4444">${message}</p>
            <button onclick="loadEvents()"
                    class="mt-4 px-6 py-2 rounded-lg text-sm font-display font-bold text-white"
                    style="background:linear-gradient(135deg,#1d4ed8,#3b82f6)">Réessayer</button>
        </div>`;
}

/**
 * Affiche un toast de notification.
 * @param {string} message
 * @param {'success'|'error'|'info'} type
 */
function showToast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    const toast     = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.cssText = 'opacity:0; transform:translateX(120%); transition:all .3s ease;';
        setTimeout(() => toast.remove(), 300);
    }, 3500);
}

/**
 * Met un bouton en état de chargement (spinner).
 * @param {string} buttonId
 * @param {boolean} loading
 * @param {string} loadingText
 */
function setButtonLoading(buttonId, loading, loadingText = 'Chargement…') {
    const btn = document.getElementById(buttonId);
    if (!btn) return;
    btn.disabled = loading;
    if (loading) {
        btn.dataset.originalText = btn.textContent;
        btn.innerHTML = `<span class="spinner"></span> ${loadingText}`;
    } else {
        btn.innerHTML = btn.dataset.originalText || loadingText;
    }
}

/**
 * Anime un compteur de 0 à target.
 * @param {string} elementId
 * @param {number} target
 */
function animateCounter(elementId, target) {
    const el = document.getElementById(elementId);
    if (!el) return;
    const start = parseInt(el.textContent) || 0;
    const diff  = target - start;
    const steps = 24;
    let   step  = 0;
    const timer = setInterval(() => {
        step++;
        el.textContent = Math.round(start + diff * (step / steps));
        if (step >= steps) { el.textContent = target; clearInterval(timer); }
    }, 20);
}

/**
 * Formate une date ISO en français lisible.
 * @param {string} dateStr  Format: '2025-09-20T09:00:00'
 * @returns {string}        Format: 'sam. 20 sept. 2025 à 09h00'
 */
function formatDate(dateStr) {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleDateString('fr-FR', {
        weekday: 'short', day: 'numeric', month: 'short',
        year: 'numeric', hour: '2-digit', minute: '2-digit'
    }).replace(':', 'h');
}


// ══════════════════════════════════════════════════════════════════════════
// INITIALISATION
// ══════════════════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
    loadEvents();
});
