import { Controller } from '@hotwired/stimulus';

/*
 * Moves the focus to the search field of the top bar when "/" is pressed, from any screen.
 * The key is left alone while the administrator types in a field, an editor or a dialog
 * input, and when a modifier is held, so it never takes a character away from them.
 */
export default class extends Controller {
    static targets = ['field'];

    connect() {
        this.onKeydown = this.onKeydown.bind(this);
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
    }

    onKeydown(event) {
        if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey || event.defaultPrevented) {
            return;
        }

        if (this.isTyping(event.target) || !this.hasFieldTarget || this.fieldTarget.offsetParent === null) {
            return;
        }

        event.preventDefault();
        this.fieldTarget.focus();
        this.fieldTarget.select();
    }

    isTyping(element) {
        if (!(element instanceof HTMLElement)) {
            return false;
        }

        return element.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(element.tagName) || element.closest('[contenteditable="true"], .ace_editor') !== null;
    }
}
