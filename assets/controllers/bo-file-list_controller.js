import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['grid', 'item'];

    static values = {
        toggleUrlTemplate: String,
        deleteUrlTemplate: String,
        positionUrl: String,
        token: String,
        sortable: { type: Boolean, default: false },
    };

    connect() {
        if (!this.sortableValue || !this.hasGridTarget) {
            return;
        }
        this.dragged = null;
        this.placeholder = null;
        this.itemTargets.forEach((item) => this.makeDraggable(item));
        this.boundDragStart = this.onDragStart.bind(this);
        this.boundDragOver = this.onDragOver.bind(this);
        this.boundDrop = this.onDrop.bind(this);
        this.boundDragEnd = this.onDragEnd.bind(this);
        this.gridTarget.addEventListener('dragstart', this.boundDragStart);
        this.gridTarget.addEventListener('dragover', this.boundDragOver);
        this.gridTarget.addEventListener('drop', this.boundDrop);
        this.gridTarget.addEventListener('dragend', this.boundDragEnd);
    }

    disconnect() {
        if (!this.hasGridTarget || !this.boundDragStart) {
            return;
        }
        this.gridTarget.removeEventListener('dragstart', this.boundDragStart);
        this.gridTarget.removeEventListener('dragover', this.boundDragOver);
        this.gridTarget.removeEventListener('drop', this.boundDrop);
        this.gridTarget.removeEventListener('dragend', this.boundDragEnd);
    }

    makeDraggable(item) {
        item.setAttribute('draggable', 'true');
        item.querySelectorAll('a').forEach((a) => a.setAttribute('draggable', 'false'));
    }

    createPlaceholder(reference) {
        const ph = document.createElement('li');
        ph.className = reference.className;
        ph.classList.add('bo-file-list-placeholder');
        ph.dataset.placeholder = '';
        const card = document.createElement('div');
        card.className = 'card h-100';
        ph.appendChild(card);
        return ph;
    }

    onDragStart(event) {
        if (event.target.closest('button, input, textarea, select')) {
            event.preventDefault();
            return;
        }
        const item = event.target.closest('[data-bo-file-list-target="item"]');
        if (!item) {
            return;
        }
        this.dragged = item;
        item.classList.add('opacity-50');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', item.dataset.fileId || '');
        this.placeholder = this.createPlaceholder(item);
        item.after(this.placeholder);
    }

    onDragOver(event) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        const item = event.target.closest('[data-bo-file-list-target="item"]');
        if (!item || item === this.dragged || !this.placeholder) {
            return;
        }
        const pos = this.placeholder.compareDocumentPosition(item);
        if (pos & Node.DOCUMENT_POSITION_FOLLOWING) {
            item.after(this.placeholder);
        } else if (pos & Node.DOCUMENT_POSITION_PRECEDING) {
            item.before(this.placeholder);
        }
    }

    onDrop(event) {
        if (!this.dragged || !this.placeholder) {
            return;
        }
        event.preventDefault();
        this.placeholder.replaceWith(this.dragged);
        this.placeholder = null;
        const newPosition = this.itemTargets.indexOf(this.dragged) + 1;
        this.refreshPositionLabels();
        this.persist(this.dragged.dataset.fileId, newPosition);
    }

    onDragEnd() {
        if (this.dragged) {
            this.dragged.classList.remove('opacity-50');
            this.dragged = null;
        }
        if (this.placeholder) {
            this.placeholder.remove();
            this.placeholder = null;
        }
    }

    refreshPositionLabels() {
        this.itemTargets.forEach((item, index) => {
            const label = item.querySelector('[data-position-label]');
            if (label) {
                label.textContent = `#${index + 1}`;
            }
        });
    }

    persist(fileId, position) {
        const body = new URLSearchParams();
        body.set('file_id', String(fileId));
        body.set('position', String(position));
        body.set('_token', this.tokenValue);

        fetch(this.positionUrlValue, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: body.toString(),
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
            })
            .catch(() => {
                window.location.reload();
            });
    }

    tokenBody() {
        const body = new URLSearchParams();
        body.set('_token', this.tokenValue);

        return body;
    }

    // A decorative image is published with an empty alt attribute, so its alt field
    // has nothing left to say: it is disabled, and the missing-text warning goes away.
    toggleDecorative(event) {
        const form = event.currentTarget.closest('form');
        const alt = form?.querySelector('input[name="alt"]');
        if (alt) {
            alt.disabled = event.currentTarget.checked;
        }
        this.updateAltWarning(form);
    }

    refreshAltWarning(event) {
        this.updateAltWarning(event.currentTarget.closest('form'));
    }

    updateAltWarning(form) {
        const warning = form?.querySelector('[data-alt-warning]');
        if (!warning) {
            return;
        }
        const alt = form.querySelector('input[name="alt"]');
        const decorative = form.querySelector('input[name="decorative"]');
        const described = Boolean(alt && alt.value.trim() !== '');
        warning.classList.toggle('d-none', described || Boolean(decorative && decorative.checked));
    }

    withToken(url) {
        return `${url}${url.includes('?') ? '&' : '?'}_token=${encodeURIComponent(this.tokenValue)}`;
    }

    async toggle(event) {
        const button = event.currentTarget;
        const icon = button.querySelector('i');

        icon?.classList.toggle('bi-eye');
        icon?.classList.toggle('bi-eye-slash');
        const isNowVisible = icon?.classList.contains('bi-eye');
        button.title = isNowVisible
            ? (button.dataset.labelHide || '')
            : (button.dataset.labelShow || '');

        const id = String(event.params.id);
        const url = (this.toggleUrlTemplateValue || '').replace(/\/0(?=\/toggle|$)/, `/${id}`);
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: this.tokenBody().toString(),
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
        } catch (e) {
            icon?.classList.toggle('bi-eye');
            icon?.classList.toggle('bi-eye-slash');
            button.title = isNowVisible
                ? (button.dataset.labelShow || '')
                : (button.dataset.labelHide || '');
        }
    }

    async delete(event) {
        const button = event.currentTarget;
        if (button.disabled) {
            return;
        }
        button.disabled = true;

        const id = String(event.params.id);
        if (!confirm('Are you sure?')) {
            button.disabled = false;
            return;
        }

        const item = event.currentTarget.closest('[data-bo-file-list-target="item"]');
        if (item) {
            item.style.opacity = '0.3';
            item.style.pointerEvents = 'none';
        }

        const url = (this.deleteUrlTemplateValue || '').replace(/\/0$/, `/${id}`).replace(/\/0(?=\/)/, `/${id}`);
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: this.tokenBody().toString(),
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            item?.remove();
        } catch (e) {
            if (item) {
                item.style.opacity = '';
                item.style.pointerEvents = '';
            }
            button.disabled = false;
        }
    }
}
