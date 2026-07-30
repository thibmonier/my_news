/**
 * Stimulus Controller — Indicateur de progression de lecture (US-007).
 *
 * Affiche une ligne émeraude (#10B981) fixée en haut du viewport dont la largeur
 * évolue proportionnellement à la position de défilement de la page.
 *
 * Activation HTML :
 *   data-controller="progress-bar"     → sur le <div class="progress-bar">
 *
 * Comportement :
 * - connect() : écoute l'événement scroll (passive) + calcul initial
 * - disconnect() : retire l'écouteur scroll (anti-fuite mémoire Turbo Drive)
 * - Throttle via requestAnimationFrame (~50ms, une frame à la fois)
 * - Cas division par zéro (page non-scrollable) : width = 100%
 * - Reset width à 0% sur événement turbo:load (SPA-like navigation)
 *
 * Accessibilité :
 * - aria-valuenow mis à jour dynamiquement (WCAG 2.1 AA)
 * - aria-valuemin="0" et aria-valuemax="100" statiques dans le HTML source
 *
 * Compatible Symfony AssetMapper (auto-découvert dans assets/controllers/).
 * À activer via importmap une fois AssetMapper configuré en Sprint 2+.
 *
 * @see https://stimulus.hotwired.dev
 */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    /** Identifiant de la frame requestAnimationFrame en cours (null si aucune). */
    #rafId = null;

    /** Horodatage du dernier appel traité (throttle 50ms). */
    #lastRun = 0;

    /** Délai minimal entre deux mises à jour (ms). */
    static #THROTTLE_MS = 50;

    /** Référence stable à onScroll (nécessaire pour removeEventListener). */
    #boundScroll = null;

    /** Référence stable au handler turbo:load. */
    #boundTurboLoad = null;

    /**
     * Appelé automatiquement par Stimulus lors du montage du controller.
     * - Enregistre l'écouteur scroll (passive → pas de blocage rendu)
     * - Enregistre le handler turbo:load (reset lors de navigation Turbo Drive)
     * - Calcule la progression initiale
     */
    connect() {
        this.#boundScroll = this.#handleScroll.bind(this);
        this.#boundTurboLoad = this.#onTurboLoad.bind(this);

        document.addEventListener('scroll', this.#boundScroll, { passive: true });
        document.addEventListener('turbo:load', this.#boundTurboLoad);

        // Calcul initial : page non-scrollable → 100%, sinon 0%
        this.#update();
    }

    /**
     * Appelé automatiquement par Stimulus lors du démontage (navigation Turbo Drive).
     * Retire l'écouteur scroll pour éviter les fuites mémoire (listener orphelin).
     */
    disconnect() {
        if (this.#boundScroll) {
            document.removeEventListener('scroll', this.#boundScroll);
            this.#boundScroll = null;
        }

        if (this.#boundTurboLoad) {
            document.removeEventListener('turbo:load', this.#boundTurboLoad);
            this.#boundTurboLoad = null;
        }

        if (this.#rafId !== null) {
            cancelAnimationFrame(this.#rafId);
            this.#rafId = null;
        }
    }

    /**
     * Réinitialise la barre à 0% lors d'une navigation Turbo Drive (turbo:load).
     * Permet la réutilisation du controller entre pages sans rechargement complet.
     */
    #onTurboLoad() {
        this.element.style.width = '0%';
        this.element.setAttribute('aria-valuenow', '0');
    }

    /**
     * Handler scroll avec throttle via requestAnimationFrame.
     *
     * N'exécute qu'une mise à jour maximum par frame (rAF) ET respecte
     * un délai minimal de 50ms entre appels pour limiter la charge CPU.
     * Une seule frame à la fois (#rafId guard) évite les empilements.
     */
    #handleScroll() {
        const now = Date.now();

        if (now - this.#lastRun < ProgressBarController.#THROTTLE_MS) {
            return;
        }

        if (this.#rafId !== null) {
            return;
        }

        this.#rafId = requestAnimationFrame(() => {
            this.#rafId = null;
            this.#lastRun = Date.now();
            this.#update();
        });
    }

    /**
     * Calcule et applique la largeur proportionnelle à la progression de scroll.
     *
     * Cas division par zéro :
     * Si scrollHeight - innerHeight <= 0, la page tient dans le viewport.
     * La progression est alors fixée à 100% (page entièrement "lue").
     * Aucune erreur NaN/Infinity n'est produite.
     */
    #update() {
        const scrollTop = window.scrollY ?? document.documentElement.scrollTop;
        const maxScroll = document.documentElement.scrollHeight - window.innerHeight;

        let pct;

        if (maxScroll <= 0) {
            // Page non-scrollable : contenu tenant dans le viewport → 100%
            pct = 100;
        } else {
            pct = Math.round(Math.min(100, Math.max(0, (scrollTop / maxScroll) * 100)));
        }

        this.element.style.width = pct + '%';
        this.element.setAttribute('aria-valuenow', String(pct));
    }
}
