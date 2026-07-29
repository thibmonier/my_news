/**
 * Stimulus Controller — Affichage/masquage du mot de passe.
 *
 * Sprint 1 (T-030-08) : bascule l'attribut type du champ mot de passe
 * entre "password" et "text". Aucune requête réseau émise (scénario alternatif 1 US-030).
 *
 * Activation :
 *   data-controller="password-toggle"
 *   data-password-toggle-target="input"    → sur l'input[type=password]
 *   data-password-toggle-target="button"   → sur le bouton toggle
 *   data-action="password-toggle#toggle"   → sur le bouton
 *
 * Note : Ce contrôleur requiert @symfony/stimulus-bridge (Webpack Encore ou AssetMapper).
 * En Sprint 1, la logique inline dans le HTML compense l'absence de bundler.
 * À activer via ImportMap / AssetMapper en Sprint 2.
 *
 * WCAG 2.1 §1.3.5 (Identify Input Purpose) : aria-label mis à jour dynamiquement.
 */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'button'];

    /**
     * Bascule le type du champ mot de passe entre "password" et "text".
     * Met à jour l'aria-label et le texte du bouton pour l'accessibilité.
     * Aucune requête réseau n'est émise lors de cette action.
     */
    toggle() {
        if (!this.hasInputTarget || !this.hasButtonTarget) {
            return;
        }

        const isPassword = this.inputTarget.type === 'password';

        this.inputTarget.type = isPassword ? 'text' : 'password';
        this.buttonTarget.textContent = isPassword ? 'Masquer' : 'Afficher';
        this.buttonTarget.setAttribute(
            'aria-label',
            isPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe',
        );
    }
}
