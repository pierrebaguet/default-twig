import { Controller } from '@hotwired/stimulus';

/**
 * The publication block of a content or folder sheet. Shows a date field only while the
 * chosen gesture reads it (each field wrapper names its gestures in
 * data-publication-actions), and refuses a withdrawal date that does not come after the
 * publication date when both are shown.
 *
 * Hidden fields are hidden, never disabled: the server ignores the dates a gesture does
 * not take, and the core checks the dates again whatever the browser let through.
 */
export default class extends Controller {
    static targets = ['action', 'field', 'publishAt', 'unpublishAt', 'message'];

    connect() {
        this.update();
    }

    update() {
        const chosen = this.actionTargets.find((radio) => radio.checked)?.value ?? null;

        this.fieldTargets.forEach((field) => {
            const actions = (field.dataset.publicationActions || '').split(' ');
            field.classList.toggle('d-none', !actions.includes(chosen));
        });

        this.validate();
    }

    validate() {
        if (!this.hasUnpublishAtTarget) {
            return;
        }

        const start = this.hasPublishAtTarget && this.isShown(this.publishAtTarget) ? this.parse(this.publishAtTarget.value) : null;
        const end = this.isShown(this.unpublishAtTarget) ? this.parse(this.unpublishAtTarget.value) : null;
        const invalid = start !== null && end !== null && end <= start;

        this.unpublishAtTarget.classList.toggle('is-invalid', invalid);
        this.unpublishAtTarget.setCustomValidity(invalid ? this.invalidMessage : '');
        if (this.hasMessageTarget) {
            this.messageTarget.hidden = !invalid;
        }
    }

    get invalidMessage() {
        return this.hasMessageTarget ? this.messageTarget.textContent.trim() : 'The withdrawal date must come after the publication date.';
    }

    isShown(input) {
        return !input.closest('.d-none');
    }

    /**
     * A date typed as YYYY-MM-DD HH:MM (seconds optional) into a sortable number, or null
     * when the value is empty or reads otherwise: the server decides then.
     */
    parse(value) {
        const match = /^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?$/.exec(value.trim());
        if (match === null) {
            return null;
        }

        const [, year, month, day, hour = '0', minute = '0', second = '0'] = match;

        return ((((Number(year) * 100 + Number(month)) * 100 + Number(day)) * 100 + Number(hour)) * 100 + Number(minute)) * 100 + Number(second);
    }
}
