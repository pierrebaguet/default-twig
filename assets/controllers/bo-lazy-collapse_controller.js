import { Controller } from '@hotwired/stimulus';

// Loads the body of a collapsible section the first time it opens, so a heavy section
// costs nothing to a page that never shows it. The URL answers a plain GET with an HTML
// fragment and applies the same access check as the page it belongs to.
//
//   <section data-controller="bo-lazy-collapse"
//            data-bo-lazy-collapse-url-value="/admin/customer/carts?customer_id=1"
//            data-bo-lazy-collapse-error-message-value="Unable to load this section.">
//     <button data-bs-toggle="collapse" data-bs-target="#body">…</button>
//     <div id="body" class="collapse"><div data-bo-lazy-collapse-target="body">…</div></div>
//   </section>
export default class extends Controller {
    static targets = ['body'];

    static values = {
        url: String,
        errorMessage: { type: String, default: 'Unable to load this section.' },
    };

    connect() {
        this.loaded = false;
        this.boundOnShow = this.load.bind(this);
        this.element.addEventListener('show.bs.collapse', this.boundOnShow);

        // A section rendered open loads straight away.
        if (this.element.querySelector('.collapse.show')) {
            this.load();
        }
    }

    disconnect() {
        this.element.removeEventListener('show.bs.collapse', this.boundOnShow);
    }

    async load() {
        if (this.loaded || !this.urlValue || !this.hasBodyTarget) {
            return;
        }

        this.loaded = true;
        try {
            const response = await fetch(this.urlValue, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            this.bodyTarget.innerHTML = await response.text();
        } catch (error) {
            // Let the next opening try again.
            this.loaded = false;
            const alert = document.createElement('div');
            alert.className = 'alert alert-danger mb-0';
            alert.setAttribute('role', 'alert');
            alert.textContent = this.errorMessageValue;
            this.bodyTarget.replaceChildren(alert);
        }
    }
}
