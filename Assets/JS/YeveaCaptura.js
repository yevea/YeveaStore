/**
 * YeveaCaptura PWA front-end: native camera capture with multi-photo preview
 * gallery, dynamic form metadata (families / warehouses / next SKU) and
 * save via fetch. All URLs are relative to /capturar so the app works from
 * any FS_ROUTE without configuration.
 *
 * Offline-first: metadata is cached in localStorage, and saves made without
 * connection are queued in IndexedDB (YeveaCapturaQueue.js) and replayed
 * automatically — from the page when it reopens or comes back online, and
 * from the service worker via Background Sync even with the app closed.
 */
(function () {
    'use strict';

    var ENDPOINT = 'capturar';
    var META_CACHE_KEY = 'yc-meta';

    var i18n = window.YC_I18N || {};
    var photos = []; // {file: File, url: objectURL}

    var form = document.getElementById('yc-form');
    var successPanel = document.getElementById('yc-success');
    var queuedPanel = document.getElementById('yc-queued');
    var skuOutput = document.getElementById('yc-sku');
    var thumbs = document.getElementById('yc-thumbs');
    var errorBox = document.getElementById('yc-error');
    var saveBtn = document.getElementById('yc-save');
    var pendingBadge = document.getElementById('yc-pending');

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

    // ---- Form metadata (network first, localStorage when offline) ----------

    function loadMeta() {
        fetch(ENDPOINT + '?api=meta', { credentials: 'same-origin' })
            .then(function (resp) { return resp.json(); })
            .then(function (meta) {
                if (!meta.ok) {
                    throw new Error(meta.error || 'meta');
                }
                try {
                    localStorage.setItem(META_CACHE_KEY, JSON.stringify(meta));
                } catch (e) { /* storage full/blocked: cache is best-effort */ }
                applyMeta(meta);
            })
            .catch(function () {
                var cached = null;
                try {
                    cached = JSON.parse(localStorage.getItem(META_CACHE_KEY));
                } catch (e) { /* corrupt cache */ }
                if (cached) {
                    applyMeta(cached);
                    skuOutput.textContent = '—';
                } else {
                    showError(i18n.offlineNoMeta || i18n.error);
                }
            });
    }

    function applyMeta(meta) {
        fillSelect('yc-familia', meta.families, i18n.noFamily || '—');
        fillSelect('yc-almacen', meta.warehouses, null);
        skuOutput.textContent = meta.nextSku;
    }

    function fillSelect(id, items, emptyLabel) {
        var select = document.getElementById(id);
        var previous = select.value;
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
        if (previous) {
            select.value = previous;
        }
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

    // ---- Save (online direct, offline queued) -------------------------------

    function newCaptureId() {
        if (window.crypto && crypto.randomUUID) {
            return crypto.randomUUID();
        }
        return 'yc-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
    }

    function buildCapture() {
        return {
            id: newCaptureId(),
            created: Date.now(),
            fields: {
                familia: form.familia.value,
                nombre: form.nombre.value.trim(),
                almacen: form.almacen.value,
                pila: form.pila.value.trim(),
                peso: form.peso.value,
                largo: form.largo.value,
                ancho: form.ancho.value,
                grueso: form.grueso.value,
                comentario: form.comentario.value.trim()
            },
            photos: photos.map(function (photo, index) {
                return { blob: photo.file, name: photo.file.name || 'foto-' + (index + 1) + '.jpg' };
            })
        };
    }

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

        var capture = buildCapture();

        saveBtn.disabled = true;
        saveBtn.textContent = i18n.saving || '…';

        if (!navigator.onLine) {
            enqueue(capture);
            return;
        }

        YCQ.postCapture(ENDPOINT, capture)
            .then(function (result) {
                if (!result.ok) {
                    // server-side validation error: show it, don't queue
                    restoreSaveBtn();
                    showError(i18n[result.error] || i18n.error);
                    return;
                }
                restoreSaveBtn();
                showSuccess(result);
            })
            .catch(function () {
                // network failure mid-flight: queue it (server dedupes by id)
                enqueue(capture);
            });
    });

    function restoreSaveBtn() {
        saveBtn.disabled = false;
        saveBtn.textContent = i18n.save;
    }

    function enqueue(capture) {
        YCQ.add(capture)
            .then(function () {
                restoreSaveBtn();
                registerSync();
                updatePendingBadge();
                showPanel(queuedPanel);
            })
            .catch(function () {
                restoreSaveBtn();
                showError(i18n.error);
            });
    }

    function registerSync() {
        if (!('serviceWorker' in navigator)) {
            return;
        }
        navigator.serviceWorker.ready.then(function (reg) {
            if (reg.sync) {
                return reg.sync.register('yc-flush');
            }
            return undefined;
        }).catch(function () {
            // Background Sync unsupported (iOS): the page flushes on reopen
        });
    }

    // ---- Queue flushing from the page ---------------------------------------

    function flushQueue() {
        YCQ.count().then(function (pending) {
            if (pending === 0 || !navigator.onLine) {
                updatePendingBadge();
                return;
            }
            YCQ.flush(ENDPOINT).then(function (outcome) {
                updatePendingBadge();
                if (outcome.sent > 0) {
                    loadMeta(); // refresh the next-SKU preview
                }
            });
        });
    }

    function updatePendingBadge() {
        YCQ.count().then(function (pending) {
            pendingBadge.hidden = pending === 0;
            pendingBadge.textContent = '📥 ' + pending;
        });
    }

    window.addEventListener('online', flushQueue);

    // ---- Panels --------------------------------------------------------------

    function showSuccess(result) {
        document.getElementById('yc-success-sku').textContent = result.sku;
        var priceBox = document.getElementById('yc-success-price');
        priceBox.hidden = !(result.price > 0);
        if (result.price > 0) {
            priceBox.textContent = result.price.toFixed(2).replace('.', ',') + ' €';
        }
        document.getElementById('yc-success-photos').textContent =
            result.photos + ' 📷';
        document.getElementById('yc-view-product').href =
            new URL(result.url, location.href).href;
        if (result.nextSku) {
            skuOutput.textContent = result.nextSku;
        }
        showPanel(successPanel);
    }

    function showPanel(panel) {
        form.hidden = true;
        successPanel.hidden = panel !== successPanel;
        queuedPanel.hidden = panel !== queuedPanel;
        window.scrollTo(0, 0);
    }

    function resetForNext() {
        photos.forEach(function (photo) { URL.revokeObjectURL(photo.url); });
        photos = [];
        renderThumbs();
        form.reset();
        form.hidden = false;
        successPanel.hidden = true;
        queuedPanel.hidden = true;
        loadMeta();
        window.scrollTo(0, 0);
    }

    document.getElementById('yc-again').addEventListener('click', resetForNext);
    document.getElementById('yc-again-queued').addEventListener('click', resetForNext);

    function showError(message) {
        errorBox.textContent = message || 'Error';
        errorBox.hidden = false;
    }

    function hideError() {
        errorBox.hidden = true;
    }

    loadMeta();
    flushQueue();
})();
