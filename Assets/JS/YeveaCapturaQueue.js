/**
 * YeveaCaptura offline queue: captures (fields + photo blobs) stored in
 * IndexedDB and replayed against /capturar when connectivity returns.
 *
 * Loaded BOTH by the app page (script tag) and by the service worker
 * (importScripts) so Background Sync can flush with the app closed. The
 * server dedupes by capture_id, so page and SW flushing concurrently can
 * never create duplicate products.
 */
var YCQ = (function () {
    'use strict';

    var DB_NAME = 'yevea-captura';
    var STORE = 'queue';

    function openDb() {
        return new Promise(function (resolve, reject) {
            var req = indexedDB.open(DB_NAME, 1);
            req.onupgradeneeded = function () {
                req.result.createObjectStore(STORE, { keyPath: 'id' });
            };
            req.onsuccess = function () { resolve(req.result); };
            req.onerror = function () { reject(req.error); };
        });
    }

    function tx(mode, fn) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var t = db.transaction(STORE, mode);
                var result = fn(t.objectStore(STORE));
                t.oncomplete = function () {
                    db.close();
                    resolve(result && 'result' in result ? result.result : undefined);
                };
                t.onerror = function () {
                    db.close();
                    reject(t.error);
                };
            });
        });
    }

    /** capture = {id, created, fields: {…}, photos: [{blob, name}]} */
    function add(capture) {
        return tx('readwrite', function (store) { store.put(capture); });
    }

    function remove(id) {
        return tx('readwrite', function (store) { store.delete(id); });
    }

    function all() {
        return tx('readonly', function (store) { return store.getAll(); });
    }

    function count() {
        return tx('readonly', function (store) { return store.count(); });
    }

    /** Builds the multipart body for one capture and POSTs it. */
    function postCapture(endpoint, capture) {
        var data = new FormData();
        data.append('action', 'save');
        data.append('capture_id', capture.id);
        Object.keys(capture.fields).forEach(function (key) {
            data.append(key, capture.fields[key]);
        });
        (capture.photos || []).forEach(function (photo) {
            data.append('photos[]', photo.blob, photo.name || 'foto.jpg');
        });

        return fetch(endpoint, {
            method: 'POST',
            body: data,
            credentials: 'same-origin'
        }).then(function (resp) { return resp.json(); });
    }

    /**
     * Replays every queued capture, oldest first. Removes entries the server
     * accepted (ok:true, including duplicates already saved by a concurrent
     * flush) or rejected as invalid (they would fail forever). Keeps entries
     * on network failure or expired session (401) for a later retry.
     *
     * @returns Promise<{sent: number, kept: number}>
     */
    function flush(endpoint) {
        return all().then(function (items) {
            items.sort(function (a, b) { return a.created - b.created; });
            var sent = 0;
            var kept = 0;

            function next(index) {
                if (index >= items.length) {
                    return Promise.resolve({ sent: sent, kept: kept });
                }
                var capture = items[index];
                return postCapture(endpoint, capture)
                    .then(function (result) {
                        if (result.ok || result.error !== 'admin-login-required') {
                            sent++;
                            return remove(capture.id);
                        }
                        kept++;
                        return undefined;
                    })
                    .catch(function () {
                        // network error: keep queued, stop (the rest will fail too)
                        kept += items.length - index;
                        index = items.length;
                        return undefined;
                    })
                    .then(function () { return next(index + 1); });
            }

            return next(0);
        });
    }

    return { add: add, remove: remove, all: all, count: count, postCapture: postCapture, flush: flush };
})();
