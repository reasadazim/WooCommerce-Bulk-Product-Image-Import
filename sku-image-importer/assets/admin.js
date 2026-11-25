(function () {
    'use strict';

    const app = document.getElementById('sii-app');
    if (!app) return;
    const element = (id) => document.getElementById('sii-' + id);
    const rows = new Map();
    let token = '';
    let running = false;
    let stop = false;

    function status(message, error = false) {
        element('status').textContent = message;
        element('status').classList.toggle('sii-error', error);
    }

    async function request(action, values) {
        const response = await fetch(siiAdmin.url, {
            method: 'POST',
            credentials: 'same-origin',
            body: new URLSearchParams({ action, nonce: siiAdmin.nonce, ...values })
        });
        let result;
        try {
            result = await response.json();
        } catch (error) {
            throw new Error('The server did not return an import result. Reload, scan and retry. Completed images will be reused.');
        }
        if (!result.success) {
            throw new Error(result.data && result.data.message ? result.data.message : 'The request failed. Reload and scan again.');
        }
        return result.data;
    }

    function selected() {
        return Array.from(rows.values()).filter((row) => row.checkbox && row.checkbox.checked);
    }

    function updateSelection() {
        const ready = Array.from(rows.values()).filter((row) => row.checkbox);
        const count = selected().length;
        element('import').disabled = running || !token || count === 0;
        element('import').textContent = 'Import selected SKUs (' + count + ')';
        element('select-all').checked = ready.length > 0 && count === ready.length;
        element('select-all').indeterminate = count > 0 && count < ready.length;
    }

    function busy(value) {
        running = value;
        ['scan', 'select-all'].forEach((id) => { element(id).disabled = value; });
        rows.forEach((row) => { if (row.checkbox) row.checkbox.disabled = value; });
        updateSelection();
    }

    function render(data) {
        rows.clear();
        element('rows').replaceChildren();
        data.rows.forEach((item) => {
            const tr = document.createElement('tr');
            const check = document.createElement('td');
            check.className = 'check-column';
            let checkbox = null;
            if (item.ready) {
                checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.checked = true;
                checkbox.setAttribute('aria-label', 'Import ' + item.sku);
                checkbox.addEventListener('change', updateSelection);
                check.append(checkbox);
            }
            const sku = document.createElement('td');
            sku.textContent = item.sku;
            const files = document.createElement('td');
            files.textContent = (item.files.f || 'Front missing') + '\n' + (item.files.b || 'Back missing');
            const targets = document.createElement('td');
            targets.textContent = item.targets.map((target) => '#' + target.id).join(', ') || (item.ready ? 'Product image and gallery only' : '—');
            const result = document.createElement('td');
            result.textContent = item.message;
            if (!item.ready) result.className = 'sii-error';
            tr.append(check, sku, files, targets, result);
            element('rows').append(tr);
            rows.set(item.key, { ...item, checkbox, result });
        });
        element('summary').textContent = data.rows.length + ' SKUs found. ' + data.ready + ' ready, ' + (data.rows.length - data.ready) + ' skipped. ' + data.ignored.length + ' filenames ignored.';
        element('ignored').hidden = data.ignored.length === 0;
        element('ignored-names').textContent = data.ignored.join(', ');
        element('preview').hidden = false;
        element('progress').hidden = true;
        updateSelection();
    }

    element('scan').addEventListener('click', async () => {
        busy(true);
        token = '';
        element('preview').hidden = true;
        status('Scanning files and matching existing variations…');
        try {
            const data = await request('sii_scan', {});
            token = data.token;
            render(data);
            status(data.ready ? 'Preview ready. Review the matched variations, then import the checked SKUs.' : 'No SKUs are ready. Review the skipped rows and filenames.');
        } catch (error) {
            status(error.message, true);
        } finally {
            busy(false);
        }
    });

    element('select-all').addEventListener('change', () => {
        rows.forEach((row) => { if (row.checkbox) row.checkbox.checked = element('select-all').checked; });
        updateSelection();
    });

    element('stop').addEventListener('click', () => {
        stop = true;
        element('stop').disabled = true;
        status('Stopping after the current SKU finishes…');
    });

    element('import').addEventListener('click', async () => {
        const queue = selected();
        if (!queue.length || !token || running) return;
        busy(true);
        stop = false;
        element('stop').hidden = false;
        element('stop').disabled = false;
        const progress = element('progress');
        progress.hidden = false;
        progress.max = queue.length;
        progress.value = 0;
        let completed = 0;
        let failed = 0;
        let updated = 0;
        for (const row of queue) {
            if (stop) break;
            status('Importing ' + row.sku + ' (' + (completed + 1) + '/' + queue.length + ')… Keep this page open.');
            row.result.textContent = 'Importing…';
            try {
                const data = await request('sii_import', { token, key: row.key });
                updated += data.updated;
                row.result.textContent = 'Done. ' + data.updated + ' variation images updated' + (data.skipped ? ', ' + data.skipped + ' variations without images left unchanged' : '') + '. Product image #' + data.front_id + ', gallery replaced with back #' + data.back_id + '.';
                if (data.variations && data.variations.length) {
                    const details = document.createElement('details');
                    const summary = document.createElement('summary');
                    summary.textContent = 'Verified variation image replacements';
                    const report = document.createElement('p');
                    report.textContent = data.variations.map((variation) => '#' + variation.id + ': image #' + variation.previous_image_id + ' → #' + variation.image_id + (variation.image_ids ? ' (gallery: ' + variation.image_ids.map((id) => '#' + id).join(', ') + ')' : '') + (variation.status === 'already_set' ? ' (already assigned)' : '')).join('\n');
                    details.append(summary, report);
                    row.result.append(details);
                }
                row.result.className = 'sii-success';
                row.checkbox.checked = false;
            } catch (error) {
                failed++;
                row.result.textContent = error.message;
                row.result.className = 'sii-error';
            }
            completed++;
            progress.value = completed;
        }
        element('stop').hidden = true;
        busy(false);
        status((stop ? 'Stopped. ' : 'Finished. ') + (completed - failed) + ' SKUs imported, ' + failed + ' failed. ' + updated + ' variation images updated.' + (failed ? ' Review failed rows; scan and retry after correcting them.' : '') + ' Reload the product editor to see the saved images before editing or saving that product.', failed > 0);
    });

    window.addEventListener('beforeunload', (event) => {
        if (!running) return;
        event.preventDefault();
        event.returnValue = '';
    });
}());
