// Copy-to-clipboard helpers for the Devices page: the static Receiver
// URL box at the top (a plain button with its value in data-copy-value),
// plus a small inline copy button this script injects next to each row's
// Guid/Device_key cell -- those are plain <td>text</td> cells from the
// generic webform table renderer (core/lib/FormElement.php), with no
// hook for a button of their own, so this adds one client-side rather
// than needing a core/yaml change for something this app-specific.
document.addEventListener('DOMContentLoaded', function () {
    wireStaticCopyButtons();
    injectTableCellCopyButtons();

    function wireStaticCopyButtons() {
        document.querySelectorAll('.copy-value-btn[data-copy-value]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                copyValue(btn.getAttribute('data-copy-value'), btn);
            });
        });
    }

    // Finds the Guid/Device_key columns by their header text (robust to
    // devices.yaml's table_view column order changing) and adds a copy
    // button right after each matching cell's own text in every data row.
    function injectTableCellCopyButtons() {
        var table = document.querySelector('.settings-table-scroll table');
        if (!table) return;

        var headerCells = table.querySelectorAll('thead th');
        var targetColumns = [];
        headerCells.forEach(function (th, index) {
            var text = th.textContent.trim().toLowerCase();
            if (text === 'guid' || text === 'device_key') {
                targetColumns.push(index);
            }
        });
        if (!targetColumns.length) return;

        table.querySelectorAll('tbody tr').forEach(function (row) {
            var cells = row.querySelectorAll('td');
            targetColumns.forEach(function (index) {
                var cell = cells[index];
                if (!cell) return;

                var value = cell.textContent.trim();
                if (!value) return;

                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'icon-btn copy-value-btn cell-copy-btn';
                btn.title = 'Copy value';
                btn.innerHTML = '<i class="bx bx-copy"></i>';
                btn.addEventListener('click', function () {
                    copyValue(value, btn);
                });
                cell.appendChild(document.createTextNode(' '));
                cell.appendChild(btn);
            });
        });
    }

    function copyValue(value, btn) {
        copyText(value).then(function () {
            showCopied(btn);
        }).catch(function () {
            // Clipboard API and the execCommand('copy') fallback both
            // failed (or aren't available at all) -- still hand the
            // visitor the real value rather than failing silently.
            window.prompt('Copy this value:', value);
        });
    }

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

    function showCopied(btn) {
        var icon = btn.querySelector('i');
        var originalIconClass = icon ? icon.className : null;
        var originalTitle = btn.getAttribute('title');

        if (icon) icon.className = 'bx bx-check';
        btn.setAttribute('title', 'Copied!');

        setTimeout(function () {
            if (icon && originalIconClass) icon.className = originalIconClass;
            btn.setAttribute('title', originalTitle);
        }, 1500);
    }
});
