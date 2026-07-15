/**
 * YeveaCaptura PWA front-end: native camera capture with multi-photo preview
 * gallery, dynamic form metadata (families / warehouses / next SKU) and
 * save via fetch. All URLs are relative to /capturar so the app works from
 * any FS_ROUTE without configuration.
 */
(function () {
    'use strict';

    var i18n = window.YC_I18N || {};
    var photos = []; // {file: File, url: objectURL}

    var form = document.getElementById('yc-form');
    var successPanel = document.getElementById('yc-success');
    var skuOutput = document.getElementById('yc-sku');
    var thumbs = document.getElementById('yc-thumbs');
    var errorBox = document.getElementById('yc-error');
    var saveBtn = document.getElementById('yc-save');

    // ---- PWA: service worker + install prompt -----------------------------

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('capturar?file=sw').catch(function () {
            // offline shell unavailable; the app still works online
        });
    }

    var deferredPrompt = null;
    var installBtn = document.getElementById('yc-install');
    var autoInstall = new URLSearchParams(location.search).get('install') === '1';

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        deferredPrompt = event;
        installBtn.hidden = false;
        if (autoInstall) {
            autoInstall = false;
            triggerInstall();
        }
    });

    installBtn.addEventListener('click', triggerInstall);

    function triggerInstall() {
        if (!deferredPrompt) {
            return;
        }
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function () {
            deferredPrompt = null;
            installBtn.hidden = true;
        });
    }

    window.addEventListener('appinstalled', function () {
        installBtn.hidden = true;
    });

    // ---- Form metadata -----------------------------------------------------

    function loadMeta() {
        fetch('capturar?api=meta', { credentials: 'same-origin' })
            .then(function (resp) { return resp.json(); })
            .then(function (meta) {
                if (!meta.ok) {
                    return;
                }
                fillSelect('yc-familia', meta.families, i18n.noFamily || '—');
                fillSelect('yc-almacen', meta.warehouses, null);
                skuOutput.textContent = meta.nextSku;
            })
            .catch(function () {
                showError(i18n.error);
            });
    }

    function fillSelect(id, items, emptyLabel) {
        var select = document.getElementById(id);
        select.innerHTML = '';
        if (emptyLabel !== null) {
            var blank = document.createElement('option');
            blank.value = '';
            blank.textContent = emptyLabel;
            select.appendChild(blank);
        }
        items.forEach(function (item) {
            var option = document.createElement('option');
            option.value = item.cod;
            option.textContent = item.name;
            select.appendChild(option);
        });
    }

    // ---- Photos: camera, gallery, preview with delete ----------------------

    var cameraInput = document.getElementById('yc-camera');
    var galleryInput = document.getElementById('yc-gallery');

    document.getElementById('yc-camera-btn').addEventListener('click', function () {
        cameraInput.click();
    });
    document.getElementById('yc-gallery-btn').addEventListener('click', function () {
        galleryInput.click();
    });

    cameraInput.addEventListener('change', addPhotos);
    galleryInput.addEventListener('change', addPhotos);

    function addPhotos(event) {
        Array.prototype.forEach.call(event.target.files, function (file) {
            if (file.type.indexOf('image/') !== 0) {
                return;
            }
            photos.push({ file: file, url: URL.createObjectURL(file) });
        });
        event.target.value = '';
        renderThumbs();
    }

    function renderThumbs() {
        thumbs.innerHTML = '';
        photos.forEach(function (photo, index) {
            var cell = document.createElement('div');
            cell.className = 'yc-thumb';

            var img = document.createElement('img');
            img.src = photo.url;
            img.alt = (i18n.photos || 'Foto') + ' ' + (index + 1);
            cell.appendChild(img);

            var del = document.createElement('button');
            del.type = 'button';
            del.className = 'yc-thumb-del';
            del.textContent = '✕';
            del.addEventListener('click', function () {
                URL.revokeObjectURL(photo.url);
                photos.splice(index, 1);
                renderThumbs();
            });
            cell.appendChild(del);

            thumbs.appendChild(cell);
        });
    }

    // ---- Save ---------------------------------------------------------------

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        hideError();

        if (form.nombre.value.trim() === '') {
            showError(i18n.nameRequired);
            form.nombre.focus();
            return;
        }
        if (form.almacen.value === '') {
            showError(i18n.warehouseRequired);
            form.almacen.focus();
            return;
        }

        var data = new FormData(form);
        photos.forEach(function (photo) {
            data.append('photos[]', photo.file, photo.file.name || 'foto.jpg');
        });

        saveBtn.disabled = true;
        saveBtn.textContent = i18n.saving || '…';

        fetch('capturar', { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (resp) { return resp.json(); })
            .then(function (result) {
                if (!result.ok) {
                    throw new Error(result.error || 'error');
                }
                showSuccess(result);
            })
            .catch(function () {
                showError(i18n.error);
            })
            .finally(function () {
                saveBtn.disabled = false;
                saveBtn.textContent = i18n.save;
            });
    });

    function showSuccess(result) {
        document.getElementById('yc-success-sku').textContent = result.sku;
        document.getElementById('yc-success-photos').textContent =
            result.photos + ' 📷';
        document.getElementById('yc-view-product').href =
            new URL(result.url, location.href).href;
        if (result.nextSku) {
            skuOutput.textContent = result.nextSku;
        }
        form.hidden = true;
        successPanel.hidden = false;
        window.scrollTo(0, 0);
    }

    document.getElementById('yc-again').addEventListener('click', function () {
        photos.forEach(function (photo) { URL.revokeObjectURL(photo.url); });
        photos = [];
        renderThumbs();
        form.reset();
        form.hidden = false;
        successPanel.hidden = true;
        loadMeta();
        window.scrollTo(0, 0);
    });

    function showError(message) {
        errorBox.textContent = message || 'Error';
        errorBox.hidden = false;
    }

    function hideError() {
        errorBox.hidden = true;
    }

    loadMeta();
})();
