import { Controller } from '@hotwired/stimulus';

/**
 * Hides the shipping surcharge fields while "free shipping" is ticked: the
 * surcharge does not apply then. The fields are hidden, never disabled, so the
 * form still submits (and validates) them and the stored amount is kept.
 */
export default class extends Controller {
    static targets = ['trigger', 'field'];

    connect() {
        this.update();
    }

    update() {
        const free = this.hasTriggerTarget && this.triggerTarget.checked;
        this.fieldTargets.forEach((el) => el.classList.toggle('d-none', free));
    }
}
