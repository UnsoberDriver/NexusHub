/*
 * cache.js — Cache local des messages (IndexedDB + mémoire) pour NexusHub.
 *
 * Deux niveaux :
 *  - IndexedDB : persiste entre les rechargements de page.
 *  - Cache mémoire (in-memory mirror) : préchargé depuis IndexedDB dès le
 *    lancement du script, permet un rendu 100% synchrone (donc perçu comme
 *    instantané) au clic sur un contact/groupe, sans attendre la moindre
 *    transaction IndexedDB.
 *
 * Ce fichier ne modifie PAS app.js : il se greffe dessus (monkey-patch) sur
 * renderMessages / renderGroupMessages / openGroup, et écoute le "change"
 * de mpSelect. Doit être chargé APRÈS app.js.
 */
(function () {
    'use strict';

    // Le nom de la base IndexedDB inclut l'ID de l'utilisateur courant.
    // Sans ça, sur un navigateur/ordinateur partagé entre plusieurs comptes,
    // le cache d'un compte (messages généraux ET privés) restait accessible
    // et s'affichait brièvement au chargement de la page pour le compte
    // suivant qui se connectait dans ce même navigateur (preloadAllIntoMemory()
    // + rendu instantané au DOMContentLoaded, avant que le fetch réseau du
    // nouveau compte ne vienne écraser l'affichage).
    var DB_NAME = 'nexushub_cache_u' + (typeof currentUserId !== 'undefined' && currentUserId ? currentUserId : 'anon');
    var DB_VERSION = 1;
    var STORE = 'messages';
    var MAX_PER_CONV = 300;

    // Nettoyage des anciennes bases IndexedDB laissées par d'autres comptes
    // s'étant connectés sur ce même navigateur (ex: 'nexushub_cache' sans
    // suffixe, provenant d'une version antérieure de ce fichier, ou
    // 'nexushub_cache_u<autre_id>'). On ne les supprime pas activement (une
    // suppression peut échouer si un onglet de l'autre compte est encore
    // ouvert ailleurs), mais on ne les ouvre jamais : DB_NAME ci-dessus
    // suffit à garantir qu'on ne lira/écrira plus jamais dedans.

    var dbPromise = null;
    // Miroir mémoire : { convKey: [messages triés par id] }
    var memCache = {};

    function openDB() {
        if (dbPromise) return dbPromise;
        if (!('indexedDB' in window)) {
            dbPromise = Promise.reject(new Error('IndexedDB indisponible'));
            return dbPromise;
        }
        dbPromise = new Promise(function (resolve, reject) {
            var req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = function () {
                var db = req.result;
                if (!db.objectStoreNames.contains(STORE)) {
                    var store = db.createObjectStore(STORE, { keyPath: 'ckey' });
                    store.createIndex('byConv', 'convKey', { unique: false });
                }
            };
            req.onsuccess = function () { resolve(req.result); };
            req.onerror = function () { reject(req.error); };
        });
        return dbPromise;
    }

    function tx(mode) {
        return openDB().then(function (db) {
            return db.transaction(STORE, mode).objectStore(STORE);
        });
    }

    function txDone(store) {
        return new Promise(function (resolve, reject) {
            var t = store.transaction;
            t.oncomplete = function () { resolve(); };
            t.onerror = function () { reject(t.error); };
            t.onabort = function () { reject(t.error); };
        });
    }

    function setMem(convKey, messages) {
        var sorted = messages.slice().sort(function (a, b) { return a.id - b.id; });
        memCache[convKey] = sorted.slice(-MAX_PER_CONV);
    }

    function mergeMem(convKey, messages) {
        var byId = {};
        (memCache[convKey] || []).forEach(function (m) { byId[m.id] = m; });
        messages.forEach(function (m) { byId[m.id] = m; });
        var merged = Object.keys(byId).map(function (k) { return byId[k]; });
        setMem(convKey, merged);
    }

    // Remplace intégralement le cache d'une conversation (sync complète :
    // reflète aussi les suppressions/éditions distantes).
    function replaceConversation(convKey, messages) {
        setMem(convKey, messages);
        return tx('readwrite').then(function (store) {
            var idx = store.index('byConv');
            var range = IDBKeyRange.only(convKey);
            return new Promise(function (resolve, reject) {
                var req = idx.openCursor(range);
                req.onsuccess = function (e) {
                    var cursor = e.target.result;
                    if (cursor) {
                        cursor.delete();
                        cursor.continue();
                    } else {
                        memCache[convKey].forEach(function (m) {
                            store.put({ ckey: convKey + ':' + m.id, convKey: convKey, id: m.id, data: m });
                        });
                        resolve();
                    }
                };
                req.onerror = function () { reject(req.error); };
            }).then(function () { return txDone(store); });
        }).catch(function (e) { console.error('Erreur cache (replace) :', e); });
    }

    // Fusion incrémentale (fetchs "since_id").
    function mergeConversation(convKey, messages) {
        if (!messages || !messages.length) return Promise.resolve();
        mergeMem(convKey, messages);
        return tx('readwrite').then(function (store) {
            messages.forEach(function (m) {
                store.put({ ckey: convKey + ':' + m.id, convKey: convKey, id: m.id, data: m });
            });
            return txDone(store);
        }).then(function () {
            return trimConversation(convKey);
        }).catch(function (e) { console.error('Erreur cache (merge) :', e); });
    }

    function trimConversation(convKey) {
        return tx('readwrite').then(function (store) {
            var idx = store.index('byConv');
            return new Promise(function (resolve, reject) {
                var items = [];
                var req = idx.openCursor(IDBKeyRange.only(convKey));
                req.onsuccess = function (e) {
                    var cursor = e.target.result;
                    if (cursor) {
                        items.push(cursor.value);
                        cursor.continue();
                    } else {
                        resolve(items);
                    }
                };
                req.onerror = function () { reject(req.error); };
            }).then(function (items) {
                if (items.length > MAX_PER_CONV) {
                    items.sort(function (a, b) { return a.id - b.id; });
                    var toDrop = items.slice(0, items.length - MAX_PER_CONV);
                    toDrop.forEach(function (item) { store.delete(item.ckey); });
                }
                return txDone(store);
            });
        }).catch(function (e) { console.error('Erreur cache (trim) :', e); });
    }

    // Lecture IndexedDB brute (filet de secours uniquement).
    function getConversationFromDB(convKey) {
        return tx('readonly').then(function (store) {
            var idx = store.index('byConv');
            return new Promise(function (resolve, reject) {
                var out = [];
                var req = idx.openCursor(IDBKeyRange.only(convKey));
                req.onsuccess = function (e) {
                    var cursor = e.target.result;
                    if (cursor) {
                        out.push(cursor.value.data);
                        cursor.continue();
                    } else {
                        resolve(out);
                    }
                };
                req.onerror = function () { reject(req.error); };
            });
        }).then(function (items) {
            items.sort(function (a, b) { return a.id - b.id; });
            return items;
        }).catch(function (e) {
            console.error('Erreur cache (get) :', e);
            return [];
        });
    }

    // Précharge TOUTES les conversations en mémoire dès le lancement du
    // script, pour que le premier clic de l'utilisateur (qui arrive
    // forcément après un minimum d'interaction, donc après ce préchargement)
    // trouve déjà tout en mémoire et se rende de façon 100% synchrone.
    function preloadAllIntoMemory() {
        return tx('readonly').then(function (store) {
            return new Promise(function (resolve, reject) {
                var req = store.openCursor();
                req.onsuccess = function (e) {
                    var cursor = e.target.result;
                    if (cursor) {
                        var row = cursor.value;
                        if (!memCache[row.convKey]) memCache[row.convKey] = [];
                        memCache[row.convKey].push(row.data);
                        cursor.continue();
                    } else {
                        Object.keys(memCache).forEach(function (k) {
                            memCache[k].sort(function (a, b) { return a.id - b.id; });
                        });
                        resolve();
                    }
                };
                req.onerror = function () { reject(req.error); };
            });
        }).catch(function (e) { console.error('Erreur cache (preload) :', e); });
    }

    function getConversation(convKey) {
        if (memCache[convKey]) return Promise.resolve(memCache[convKey]);
        return getConversationFromDB(convKey).then(function (items) {
            if (items.length) memCache[convKey] = items;
            return items;
        });
    }

    window.NexusCache = {
        getConversation: getConversation,
        replaceConversation: replaceConversation,
        mergeConversation: mergeConversation
    };

    // Chauffe la connexion IndexedDB + précharge le miroir mémoire dès que
    // possible, sans attendre d'événement utilisateur.
    var preloadPromise = preloadAllIntoMemory();

    // ------------------------------------------------------------------
    // Rendu instantané depuis le cache
    // ------------------------------------------------------------------

    function renderMessagesSync(container, messages, msgType) {
        var prevUsername = null;
        var frag = document.createDocumentFragment();
        messages.forEach(function (m) {
            var div = buildMessageDiv(m, msgType, prevUsername);
            div.classList.add('from-cache');
            frag.appendChild(div);
            prevUsername = m.username;
        });
        container.appendChild(frag);
        container.scrollTop = container.scrollHeight;
    }

    function renderFromCacheInto(containerId, convKey, msgType) {
        var container = document.getElementById(containerId);
        if (!container) return;
        if (container.querySelector('.message[data-id]')) return;

        // Chemin rapide : déjà en mémoire -> rendu synchrone immédiat, 0 attente.
        if (memCache[convKey] && memCache[convKey].length) {
            renderMessagesSync(container, memCache[convKey], msgType);
            return;
        }

        // Chemin de secours : rien en mémoire pour l'instant (ex: tout
        // premier appel avant la fin du préchargement, quelques ms après
        // le chargement de la page).
        preloadPromise.then(function () {
            if (container.querySelector('.message[data-id]')) return;
            var messages = memCache[convKey];
            if (messages && messages.length) {
                renderMessagesSync(container, messages, msgType);
            }
        });
    }

    // --- Général ---
    if (typeof renderMessages === 'function') {
        var _origRenderMessages = renderMessages;
        window.renderMessages = function (containerId, messages, fullSync) {
            _origRenderMessages(containerId, messages, fullSync);
            if (!Array.isArray(messages)) return;
            var isMp = containerId === 'mp-messages';
            if (isMp && (typeof mpSelect === 'undefined' || !mpSelect || !mpSelect.value)) return;
            var convKey = isMp ? ('mp:' + mpSelect.value) : 'general';
            var doFullSync = fullSync === undefined ? true : !!fullSync;
            if (doFullSync) {
                if (messages.length) replaceConversation(convKey, messages);
            } else if (messages.length) {
                mergeConversation(convKey, messages);
            }
        };
    }

    // --- Groupes : fetch_group renvoie toujours la liste complète -> replace
    if (typeof renderGroupMessages === 'function') {
        var _origRenderGroupMessages = renderGroupMessages;
        window.renderGroupMessages = function (messages) {
            _origRenderGroupMessages(messages);
            if (!Array.isArray(messages) || typeof currentGroupId === 'undefined' || !currentGroupId) return;
            if (messages.length) replaceConversation('group:' + currentGroupId, messages);
        };
    }

    // --- Ouverture d'un groupe : cache affiché tout de suite après le
    // "innerHTML = ''" fait par openGroup(), pendant le fetch réseau.
    if (typeof openGroup === 'function') {
        var _origOpenGroup = openGroup;
        window.openGroup = function (id, name) {
            _origOpenGroup(id, name);
            renderFromCacheInto('groupe-messages', 'group:' + id, 'group');
        };
    }

    // --- Changement de contact MP : même principe, juste après le clear
    // fait par le listener "change" natif de app.js.
    if (typeof mpSelect !== 'undefined' && mpSelect) {
        mpSelect.addEventListener('change', function () {
            if (!mpSelect.value) return;
            renderFromCacheInto('mp-messages', 'mp:' + mpSelect.value, 'private');
        });
    }

    // --- Filet de sécurité pour le général si jamais le conteneur arrive
    // vide au chargement (ex: erreur serveur ponctuelle).
    document.addEventListener('DOMContentLoaded', function () {
        renderFromCacheInto('general-messages', 'general', 'general');
    });

    // NOTE : un préchauffage en arrière-plan (qui allait chercher tous les
    // contacts/groupes automatiquement après le chargement de la page) a
    // été retiré : il envoyait trop de requêtes d'un coup, avec le risque
    // de déclencher une protection anti-bot/anti-abus côté serveur — et
    // avait en plus l'effet de bord de marquer des conversations comme
    // lues sans que l'utilisateur les ait ouvertes. Le cache reste
    // uniquement alimenté par les vraies actions de l'utilisateur
    // (clic sur un contact/groupe, polling normal), ce qui est plus lent
    // au tout premier clic sur chaque conversation, mais fiable.
})();