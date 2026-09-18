// Copy-to-clipboard for the Devices table's "Copy Overland link" button
// (devices.yaml's table_view.buttons `type: custom`, handler
// devices_copy_url() in web/index.php). The button is still a real
// <a href="..."> pointing at the actual per-device Receiver URL -- this
// only intercepts the click so it copies instead of navigating; a
// visitor with JS disabled, or an old browser with neither the Clipboard
// API nor execCommand('copy'), still gets a real link (falls through to
// /api/overland's own 405/404 on a bare GET, not a broken button).
document.addEventListener('DOMContentLoaded', function () {
    var links = document.querySelectorAll('a.icon-btn[href*="/api/overland?"]');

    links.forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            var url = link.getAttribute('href');

            copyText(url).then(function () {
                showCopied(link);
            }).catch(function () {
                // Clipboard API and the execCommand('copy') fallback both
                // failed (or aren't available at all) -- still hand the
                // visitor the real URL rather than failing silently.
                window.prompt('Copy this Overland Receiver URL:', url);
            });
        });
    });

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        // Legacy fallback (http:// origin, or an older browser with no
        // Clipboard API): a temporary, invisible, selected textarea plus
        // execCommand('copy') is the standard workaround for this case.
        return new Promise(function (resolve, reject) {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.top = '0';
            textarea.style.left = '0';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();

            var copied = false;
            try {
                copied = document.execCommand('copy');
            } catch (err) {
                copied = false;
            }
            document.body.removeChild(textarea);

            if (copied) resolve();
            else reject();
        });
    }

    function showCopied(link) {
        var icon = link.querySelector('i');
        var originalIconClass = icon ? icon.className : null;
        var originalTitle = link.getAttribute('title');

        if (icon) icon.className = 'bx bx-check';
        link.setAttribute('title', 'Copied!');

        setTimeout(function () {
            if (icon && originalIconClass) icon.className = originalIconClass;
            link.setAttribute('title', originalTitle);
        }, 1500);
    }
});
