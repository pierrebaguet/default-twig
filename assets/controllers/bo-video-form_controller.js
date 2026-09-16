import { Controller } from '@hotwired/stimulus';

/**
 * Adds a video to a product without leaving the tab. The server answers either
 * a JSON acknowledgement, or the add form re-rendered with its errors — which is
 * how a refused address states which platforms the shop accepts.
 */
export default class extends Controller {
    static targets = ['form', 'list'];

    static values = {
        listUrl: String,
    };

    async submit(event) {
        event.preventDefault();

        const form = event.currentTarget;
        const response = await fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form),
        });

        if (response.status === 422) {
            this.formTarget.innerHTML = await response.text();
            return;
        }

        if (!response.ok) {
            return;
        }

        form.reset();
        this.refresh();
    }

    async refresh() {
        if (!this.listUrlValue || !this.hasListTarget) {
            return;
        }

        try {
            const response = await fetch(this.listUrlValue, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
            });
            if (response.ok) {
                this.listTarget.innerHTML = await response.text();
            }
        } catch (e) {
            // silent: the tab still shows the list it was rendered with
        }
    }
}
