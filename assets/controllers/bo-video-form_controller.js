import { Controller } from '@hotwired/stimulus';

/**
 * Adds a video to a product without leaving the tab. The server answers either
 * a JSON acknowledgement, or the add form re-rendered with its errors — which is
 * how a refused address states which platforms the shop accepts.
 *
 * Whatever comes back, the answer is spoken and the keyboard lands somewhere
 * useful: on the address field when it was refused, on the emptied field when
 * the video went through.
 */
export default class extends Controller {
    static targets = ['form', 'list', 'status'];

    static values = {
        listUrl: String,
        addedMessage: String,
        failedMessage: String,
    };

    async submit(event) {
        event.preventDefault();

        const form = event.currentTarget;
        let response;
        try {
            response = await fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(form),
            });
        } catch (e) {
            this.announce(this.failedMessageValue, 'alert alert-danger small');
            return;
        }

        if (response.status === 422) {
            this.formTarget.innerHTML = await response.text();
            this.focusUrl();
            return;
        }

        if (!response.ok) {
            // Never swallow it: an admin who sees nothing happen retries forever.
            this.announce(this.failedMessageValue, 'alert alert-danger small');
            return;
        }

        form.reset();
        await this.refresh();
        this.announce(this.addedMessageValue, 'alert alert-success small');
        this.focusUrl();
    }

    focusUrl() {
        const url = this.element.querySelector('[data-testid="bo-video-url"]');
        url?.focus();
    }

    announce(message, className) {
        if (!this.hasStatusTarget || !message) {
            return;
        }
        this.statusTarget.className = className;
        this.statusTarget.textContent = message;
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
            this.announce(this.failedMessageValue, 'alert alert-danger small');
        }
    }
}
