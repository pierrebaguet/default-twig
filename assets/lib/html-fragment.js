/**
 * Loads an HTML fragment of the back-office into an element: a plain GET marked as
 * an XHR, the answer written in place. An answer that is not a success, or a request
 * that fails, leaves an alert with the given message instead, and resolves to false
 * so the caller can let the next attempt try again.
 *
 * @param {string} url
 * @param {Element} target
 * @param {string} errorMessage
 * @returns {Promise<boolean>}
 */
export async function loadHtmlFragment(url, target, errorMessage) {
    try {
        const response = await fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        target.innerHTML = await response.text();

        return true;
    } catch (error) {
        const alert = document.createElement('div');
        alert.className = 'alert alert-danger mb-0';
        alert.setAttribute('role', 'alert');
        alert.textContent = errorMessage;
        target.replaceChildren(alert);

        return false;
    }
}
