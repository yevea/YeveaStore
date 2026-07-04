/**
 * The core PanelController template auto-focuses the first editable input on
 * load, which makes the settings page scroll down to a random field (e.g.
 * "days until expiration" on the control panel). Undo it: blur and go back
 * to the top. The timeout runs after every document-ready handler, including
 * the core one that sets the focus.
 */
$(document).ready(function () {
    setTimeout(function () {
        if (document.activeElement && document.activeElement !== document.body) {
            document.activeElement.blur();
        }
        window.scrollTo(0, 0);
    }, 100);
});
