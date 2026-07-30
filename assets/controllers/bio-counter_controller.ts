/**
 * Stimulus Controller — Compteur de caractères pour la bio professionnelle (US-032).
 *
 * Targets  : counter  — span affichant "<len> / <max>" en temps réel
 * Values   : max (Number, default: 280) — limite injectée via data-bio-counter-max-value
 *
 * Comportement :
 *  - Affiche le nombre de caractères courant / limite maximale
 *  - Rouge (var(--color-error, #DC2626)) si len > max
 *  - Couleur par défaut sinon (var(--color-on-surface-variant, #6b7280))
 *
 * Aucune valeur n'est codée en dur dans le controller — la limite `max` est
 * injectée via l'attribut HTML `data-bio-counter-max-value` (YAGNI + réutilisabilité).
 */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller<HTMLElement> {
    static targets = ['counter'];
    static values = { max: { type: Number, default: 280 } };

    declare readonly counterTarget: HTMLElement;
    declare readonly maxValue: number;

    private textarea: HTMLTextAreaElement | null = null;

    connect(): void {
        this.textarea = this.element.querySelector('textarea');
        if (this.textarea) {
            this.textarea.addEventListener('input', this.update.bind(this));
            this.update();
        }
    }

    disconnect(): void {
        if (this.textarea) {
            this.textarea.removeEventListener('input', this.update.bind(this));
        }
    }

    private update(): void {
        const len = this.textarea?.value.length ?? 0;
        this.counterTarget.textContent = `${len} / ${this.maxValue}`;

        if (len > this.maxValue) {
            this.counterTarget.style.color = 'var(--color-error, #DC2626)';
            this.counterTarget.setAttribute('aria-live', 'assertive');
        } else {
            this.counterTarget.style.color = 'var(--color-on-surface-variant, #6b7280)';
            this.counterTarget.setAttribute('aria-live', 'polite');
        }
    }
}
