import { Controller } from '@hotwired/stimulus';
import { loadHtmlFragment } from '../lib/html-fragment.js';

export default class extends Controller {
    connect() {
        this.boundOnShown = this.onShown.bind(this);
        this.element.addEventListener('shown.bs.tab', this.boundOnShown);
    }

    disconnect() {
        this.element.removeEventListener('shown.bs.tab', this.boundOnShown);
    }

    async onShown(event) {
        const button = event.target;
        const href = button?.getAttribute('data-href');
        if (!href || button.dataset.loaded === '1') {
            return;
        }

        const targetSelector = button.getAttribute('data-bs-target');
        const pane = targetSelector ? this.element.querySelector(targetSelector) : null;
        if (!pane) {
            return;
        }

        button.dataset.loaded = '1';
        const loaded = await loadHtmlFragment(href, pane, button.dataset.errorMessage || 'Unable to load this tab.');
        if (!loaded) {
            button.dataset.loaded = '';
        }
    }
}
