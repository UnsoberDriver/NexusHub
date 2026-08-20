// ════════════════════════════════════════════════════════
        //  Appel de groupe (mesh WebRTC) — onglet "Chat général"
        //  Chaque participant ouvre une RTCPeerConnection directe
        //  avec chaque autre participant. Réutilise la même table
        //  de signalisation que les appels 1-to-1 (call.php), mais
        //  taguée avec room='general' pour ne pas interférer.
        // ════════════════════════════════════════════════════════
        (function () {
            let ROOM = 'general';
            // TURN via Cloudflare Realtime : identifiants temporaires (TTL 24h),
            // récupérés depuis call.php?action=turn_credentials. Si calls.js est
            // chargé sur la même page, on réutilise son cache pour éviter un
            // double appel réseau.
            let _rtcConfigCache = null;
            let _rtcConfigCacheAt = 0;
            const RTC_CONFIG_FALLBACK = { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] };

            async function getRtcConfig() {
                const CACHE_MS = 12 * 60 * 60 * 1000;
                if (_rtcConfigCache && (Date.now() - _rtcConfigCacheAt) < CACHE_MS) {
                    return _rtcConfigCache;
                }
                try {
                    const fd = new FormData();
                    fd.append('action', 'turn_credentials');
                    const r = await fetch('call.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                    const data = await r.json();
                    if (data.status === 'ok' && Array.isArray(data.iceServers) && data.iceServers.length) {
                        _rtcConfigCache = { iceServers: data.iceServers };
                        _rtcConfigCacheAt = Date.now();
                        console.info('[TURN] Identifiants Cloudflare récupérés :', data.iceServers.length, 'serveurs ICE');
                        return _rtcConfigCache;
                    }
                    console.warn('[TURN] Réponse inattendue de turn_credentials, fallback STUN-only :', data);
                } catch (e) {
                    console.warn('[TURN] Échec de récupération des identifiants, fallback STUN-only :', e);
                }
                return RTC_CONFIG_FALLBACK;
            }

            const overlay = document.getElementById('group-call-overlay');
            const grid = document.getElementById('group-call-grid');
            const statusEl = document.getElementById('group-call-status');
            const timerEl = document.getElementById('group-call-timer');
            const audioSinks = document.getElementById('group-call-audio-sinks');
            const localVideo = document.getElementById('group-call-video-local');
            const localAvatar = document.getElementById('group-call-avatar-local');
            const btnCall = document.getElementById('general-header-call');
            const btnVideoCall = document.getElementById('general-header-videocall');
            const btnGroupCall = document.getElementById('groupe-header-call');
            const btnGroupVideoCall = document.getElementById('groupe-header-videocall');
            const btnLeave = document.getElementById('btn-leave-group-call');
            const btnMute = document.getElementById('btn-mute-group-call');
            const btnCamera = document.getElementById('btn-camera-group-call');
            const btnMinimize = document.getElementById('btn-minimize-group-call');

            let active = false;
            let isVideoCall = false;
            let isMuted = false;
            let isCameraOff = false;
            let localStream = null;
            let heartbeatTimer = null;
            let timerInterval = null;
            let timerSec = 0;
            const peers = new Map();   // userId -> { pc, tile, video, username, avatar }
            const pendingIce = new Map(); // userId -> [candidates] reçus avant remoteDescription

            function currentUserId() {
                return parseInt(document.body.dataset.userId || window.CURRENT_USER_ID || 0);
            }

            async function rtcSignal(action, toUser, payload = '') {
                const fd = new FormData();
                fd.append('action', action);
                fd.append('to_user', toUser);
                fd.append('room', ROOM);
                if (payload) fd.append('payload', payload);
                try {
                    const r = await fetch('call.php', { method: 'POST', body: fd });
                    return await r.json();
                } catch (e) { return null; }
            }

            function initialOf(name) {
                return (name || '?').trim().charAt(0).toUpperCase();
            }

            // Zoom à la molette sur PC : chaque vidéo de la grille (locale et
            // distantes) peut être zoomée indépendamment en survolant puis en
            // scrollant dessus. Le zoom est centré sur la position du curseur
            // (comme un zoom Google Maps), pas sur le centre de la vidéo.
            const ZOOM_MIN = 1;
            const ZOOM_MAX = 3;
            const zoomClamp = (v) => Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, v));
            const zoomControllers = new Set();

            function enableTileWheelZoom(el, mirrored) {
                if (el._zoomBound) return el._zoomCtl;
                el._zoomBound = true;

                let zoom = 1;
                el.style.transformOrigin = '50% 50%';

                function handle(e) {
                    e.preventDefault();

                    // On ne recalcule l'origine du zoom qu'au moment où on part
                    // de zoom=1 : getBoundingClientRect() reflète alors encore la
                    // taille réelle (non transformée) de la vidéo. La recalculer
                    // à chaque cran une fois déjà zoomé fausse le résultat (le
                    // rect inclut le zoom en cours) et fait dériver l'origine
                    // vers un bord, bloquant le dézoom en pratique.
                    if (zoom === 1) {
                        const rect = el.getBoundingClientRect();
                        let px = ((e.clientX - rect.left) / rect.width) * 100;
                        let py = ((e.clientY - rect.top) / rect.height) * 100;
                        px = Math.max(0, Math.min(100, px));
                        py = Math.max(0, Math.min(100, py));
                        if (mirrored) px = 100 - px;
                        el.style.transformOrigin = `${px}% ${py}%`;
                    }

                    zoom = zoomClamp(zoom - e.deltaY * 0.0015);
                    el.style.transform = mirrored
                        ? `scaleX(-1) scale(${zoom})`
                        : `scale(${zoom})`;

                    if (zoom === 1) {
                        el.style.transformOrigin = '50% 50%';
                    }
                }

                el.addEventListener('wheel', handle, { passive: false });

                const ctl = {
                    handle,
                    reset() {
                        zoom = 1;
                        el.style.transformOrigin = '50% 50%';
                        el.style.transform = '';
                    }
                };
                el._zoomCtl = ctl;
                zoomControllers.add(ctl);
                return ctl;
            }

            // Filet de sécurité : si le curseur est sur une zone de la tuile qui
            // n'est pas couverte par l'élément <video> (bande noire visible en
            // arrière-plan de la tuile, par ex. tant que le flux n'est pas encore
            // affiché), l'événement 'wheel' n'atteint aucune vidéo. On écoute
            // donc aussi sur le conteneur de la tuile et on route le zoom vers
            // la vidéo qu'elle contient.
            function enableTileContainerFallback(tile, videoEl, mirrored) {
                tile.addEventListener('wheel', (e) => {
                    if (e.target !== tile) return; // déjà géré par la vidéo elle-même
                    enableTileWheelZoom(videoEl, mirrored).handle(e);
                }, { passive: false });
            }

            function resetAllTileZoom() {
                zoomControllers.forEach((ctl) => ctl.reset());
            }
            enableTileWheelZoom(localVideo, false);
            enableTileContainerFallback(document.getElementById('group-call-tile-local'), localVideo, false);

            function ensureTile(userId, username, avatar) {
                const existing = document.getElementById('group-call-tile-' + userId);
                if (existing) return { tile: existing, video: existing.querySelector('video') };

                const tile = document.createElement('div');
                tile.className = 'group-call-tile';
                tile.id = 'group-call-tile-' + userId;

                const video = document.createElement('video');
                video.autoplay = true;
                video.playsInline = true;
                tile.appendChild(video);
                enableTileWheelZoom(video, false);
                enableTileContainerFallback(tile, video, false);

                const avatarDiv = document.createElement('div');
                avatarDiv.className = 'group-call-tile-avatar';
                if (avatar) {
                    const img = document.createElement('img');
                    img.src = avatar;
                    img.style.width = '100%';
                    img.style.height = '100%';
                    img.style.objectFit = 'cover';
                    avatarDiv.appendChild(img);
                } else {
                    avatarDiv.textContent = initialOf(username);
                }
                tile.appendChild(avatarDiv);

                const nameDiv = document.createElement('div');
                nameDiv.className = 'group-call-tile-name';
                nameDiv.textContent = username || ('Utilisateur ' + userId);
                tile.appendChild(nameDiv);

                grid.appendChild(tile);
                return { tile, video };
            }

            function removeTile(userId) {
                const el = document.getElementById('group-call-tile-' + userId);
                if (el) el.remove();
                const audioEl = document.getElementById('group-call-audio-' + userId);
                if (audioEl) audioEl.remove();
            }

            async function createPeerConnection(userId, username, avatar) {
                const pc = new RTCPeerConnection(await getRtcConfig());
                const { tile, video } = ensureTile(userId, username, avatar);

                if (localStream) {
                    localStream.getTracks().forEach(t => pc.addTrack(t, localStream));
                }

                pc.onicecandidate = (e) => {
                    if (e.candidate) rtcSignal('ice', userId, JSON.stringify(e.candidate));
                };

                pc.ontrack = (e) => {
                    const stream = e.streams[0];
                    const hasVideo = stream.getVideoTracks().length > 0;
                    if (hasVideo && isVideoCall) {
                        video.srcObject = stream;
                        tile.classList.add('has-video');
                    } else {
                        // Flux audio seul : lu via un <audio> caché plutôt que
                        // le <video> de la tuile, qui reste sur l'avatar.
                        let audioEl = document.getElementById('group-call-audio-' + userId);
                        if (!audioEl) {
                            audioEl = document.createElement('audio');
                            audioEl.id = 'group-call-audio-' + userId;
                            audioEl.autoplay = true;
                            audioSinks.appendChild(audioEl);
                        }
                        audioEl.srcObject = stream;
                        // Filet de sécurité : l'attribut autoplay seul peut être bloqué
                        // silencieusement par le navigateur. On force la lecture et on
                        // retente au prochain clic dans la fenêtre si besoin.
                        const tryPlayGroupAudio = () => audioEl.play().catch(() => {});
                        tryPlayGroupAudio();
                        setTimeout(tryPlayGroupAudio, 300);
                        const resumeGroupAudioOnInteraction = () => {
                            tryPlayGroupAudio();
                            document.removeEventListener('click', resumeGroupAudioOnInteraction);
                        };
                        document.addEventListener('click', resumeGroupAudioOnInteraction, { once: true });
                    }
                };

                pc.oniceconnectionstatechange = () => {
                    if (['failed', 'disconnected', 'closed'].includes(pc.iceConnectionState)) {
                        // Laisse une chance de reconnexion ICE avant de nettoyer
                        setTimeout(() => {
                            if (pc.iceConnectionState === 'failed') removePeer(userId, false);
                        }, 4000);
                    }
                };

                peers.set(userId, { pc, tile, video, username, avatar });
                return pc;
            }

            async function flushPendingIce(userId) {
                const peer = peers.get(userId);
                const queue = pendingIce.get(userId);
                if (!peer || !queue || !peer.pc.remoteDescription) return;
                pendingIce.delete(userId);
                for (const c of queue) {
                    try { await peer.pc.addIceCandidate(new RTCIceCandidate(c)); } catch (e) { }
                }
            }

            async function initiateOfferTo(userId, username, avatar) {
                const pc = await createPeerConnection(userId, username, avatar);
                const offer = await pc.createOffer();
                await pc.setLocalDescription(offer);
                await rtcSignal('offer', userId, JSON.stringify({ sdp: offer.sdp, type: offer.type, video: isVideoCall }));
            }

            function removePeer(userId, notify = true) {
                const peer = peers.get(userId);
                if (peer) {
                    try { peer.pc.close(); } catch (e) { }
                }
                peers.delete(userId);
                pendingIce.delete(userId);
                if (notify) rtcSignal('hangup', userId, '');
                removeTile(userId);
            }

            // ── Signaux entrants pour cette room (appelé depuis le script 1-to-1) ──
            window.handleGroupSignal = async function (s) {
                if (s.room !== ROOM) return; // autre salon (extensible plus tard)
                const from = parseInt(s.from_user);

                if (s.type === 'offer') {
                    if (!active) { rtcSignal('hangup', from, ''); return; }
                    let peer = peers.get(from);
                    let payload;
                    try { payload = JSON.parse(s.payload); } catch (e) { return; }
                    if (!peer) {
                        await createPeerConnection(from, s.username, s.avatar);
                        peer = peers.get(from);
                    }
                    await peer.pc.setRemoteDescription(new RTCSessionDescription({ type: 'offer', sdp: payload.sdp }));
                    await flushPendingIce(from);
                    const answer = await peer.pc.createAnswer();
                    await peer.pc.setLocalDescription(answer);
                    await rtcSignal('answer', from, JSON.stringify({ sdp: answer.sdp, type: answer.type }));
                    updateStatus();
                    return;
                }

                if (s.type === 'answer') {
                    const peer = peers.get(from);
                    if (!peer) return;
                    let payload;
                    try { payload = JSON.parse(s.payload); } catch (e) { return; }
                    await peer.pc.setRemoteDescription(new RTCSessionDescription({ type: 'answer', sdp: payload.sdp }));
                    await flushPendingIce(from);
                    updateStatus();
                    return;
                }

                if (s.type === 'ice') {
                    let candidate;
                    try { candidate = JSON.parse(s.payload); } catch (e) { return; }
                    if (!candidate || !candidate.candidate) return;
                    const peer = peers.get(from);
                    if (!peer || !peer.pc.remoteDescription) {
                        if (!pendingIce.has(from)) pendingIce.set(from, []);
                        pendingIce.get(from).push(candidate);
                        return;
                    }
                    try { await peer.pc.addIceCandidate(new RTCIceCandidate(candidate)); } catch (e) { }
                    return;
                }

                if (s.type === 'hangup' || s.type === 'reject') {
                    removePeer(from, false);
                    updateStatus();
                    return;
                }
            };

            function updateStatus() {
                const n = peers.size;
                statusEl.textContent = n === 0
                    ? "En attente d'autres participants…"
                    : (n === 1 ? '1 autre participant' : n + ' autres participants');
            }

            function formatTimer(sec) {
                const m = Math.floor(sec / 60).toString().padStart(2, '0');
                const s = (sec % 60).toString().padStart(2, '0');
                return m + ':' + s;
            }

            function startTimer() {
                timerSec = 0;
                timerEl.hidden = false;
                timerEl.textContent = '00:00';
                timerInterval = setInterval(() => {
                    timerSec++;
                    timerEl.textContent = formatTimer(timerSec);
                }, 1000);
            }

            function stopTimer() {
                if (timerInterval) clearInterval(timerInterval);
                timerInterval = null;
                timerEl.hidden = true;
            }

            function currentGroupRoom() {
                const el = document.getElementById('groupe-id-input');
                const id = el && el.value;
                return id ? 'group-' + id : null;
            }

            async function joinGroupCall(video, room) {
                if (active) return;
                ROOM = room || 'general';

                try {
                    localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: video ? true : false });
                } catch (e) {
                    alert("Impossible d'accéder au micro" + (video ? '/à la caméra' : '') + '.');
                    return;
                }

                active = true;
                isVideoCall = video;
                isMuted = false;
                isCameraOff = false;
                overlay.hidden = false;
                statusEl.textContent = 'Connexion…';
                btnCamera.hidden = !video;
                grid.querySelectorAll('.group-call-tile:not(#group-call-tile-local)').forEach(t => t.remove());

                if (video) {
                    localVideo.srcObject = localStream;
                    localVideo.hidden = false;
                    document.getElementById('group-call-tile-local').classList.add('has-video');
                } else {
                    localVideo.hidden = true;
                    document.getElementById('group-call-tile-local').classList.remove('has-video');
                }
                localAvatar.textContent = initialOf(document.body.dataset.username || 'Vous');

                // Rejoint le salon : récupère les membres déjà présents et leur envoie une offre
                const fd = new FormData();
                fd.append('action', 'room_join');
                fd.append('room', ROOM);
                fd.append('video', video ? '1' : '0');
                let members = [];
                try {
                    const r = await fetch('call.php', { method: 'POST', body: fd });
                    const data = await r.json();
                    members = data.members || [];
                } catch (e) { }

                for (const m of members) {
                    await initiateOfferTo(m.user_id, m.username, m.avatar);
                }

                updateStatus();
                startTimer();

                // Heartbeat : garde la présence active + filet de sécurité pour
                // détecter les départs silencieux (onglet fermé sans hangup).
                heartbeatTimer = setInterval(async () => {
                    const hfd = new FormData();
                    hfd.append('action', 'room_heartbeat');
                    hfd.append('room', ROOM);
                    try {
                        const r = await fetch('call.php', { method: 'POST', body: hfd });
                        const data = await r.json();
                        const liveIds = new Set((data.members || []).map(m => parseInt(m.user_id)));
                        // Nettoie les pairs qui ne sont plus dans le salon côté serveur
                        for (const id of Array.from(peers.keys())) {
                            if (!liveIds.has(id)) removePeer(id, false);
                        }
                        // Filet de sécurité anti-course : si un membre est présent côté
                        // serveur mais qu'on n'a encore aucune connexion avec lui, on
                        // initie nous-même (seulement si notre id est le plus petit,
                        // pour éviter que les deux côtés initient en même temps).
                        for (const m of (data.members || [])) {
                            const mid = parseInt(m.user_id);
                            if (!peers.has(mid) && currentUserId() && currentUserId() < mid) {
                                initiateOfferTo(mid, m.username, m.avatar);
                            }
                        }
                        updateStatus();
                    } catch (e) { }
                }, 6000);

                if (ROOM === 'general') {
                    btnCall.classList.add('active');
                    btnVideoCall.classList.add('active');
                } else {
                    if (btnGroupCall) btnGroupCall.classList.add('active');
                    if (btnGroupVideoCall) btnGroupVideoCall.classList.add('active');
                }
            }

            async function leaveGroupCall() {
                if (!active) return;
                active = false;

                for (const id of Array.from(peers.keys())) {
                    removePeer(id, true);
                }

                const fd = new FormData();
                fd.append('action', 'room_leave');
                fd.append('room', ROOM);
                try { await fetch('call.php', { method: 'POST', body: fd }); } catch (e) { }

                if (localStream) {
                    localStream.getTracks().forEach(t => t.stop());
                    localStream = null;
                }
                if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null; }
                stopTimer();
                resetAllTileZoom();
                overlay.hidden = true;
                overlay.classList.remove('call-minimized');
                overlay.style.left = overlay.style.top = overlay.style.right = overlay.style.bottom = '';
                btnCall.classList.remove('active');
                btnVideoCall.classList.remove('active');
                if (btnGroupCall) btnGroupCall.classList.remove('active');
                if (btnGroupVideoCall) btnGroupVideoCall.classList.remove('active');
            }

            // ── Réduire / agrandir l'appel de groupe (sans jamais couper le flux) ──
            function setMinimized(min) {
                overlay.classList.toggle('call-minimized', min);
                if (btnMinimize) btnMinimize.title = min ? "Agrandir l'appel" : "Réduire l'appel";
            }
            if (btnMinimize) {
                btnMinimize.addEventListener('click', (e) => {
                    e.stopPropagation();
                    setMinimized(!overlay.classList.contains('call-minimized'));
                });
            }
            // Cliquer (sans avoir glissé) n'importe où sur la bulle réduite la ragrandit
            overlay.addEventListener('click', () => {
                if (dragMoved) { dragMoved = false; return; }
                if (overlay.classList.contains('call-minimized')) setMinimized(false);
            });

            // ── Glisser la bulle réduite n'importe où sur l'écran ──
            let dragPointerId = null;
            let dragMoved = false;
            let dragStartX = 0, dragStartY = 0, dragOrigLeft = 0, dragOrigTop = 0;

            overlay.addEventListener('pointerdown', (e) => {
                if (!overlay.classList.contains('call-minimized')) return;
                if (e.target.closest('.call-btn-minimize')) return;
                dragPointerId = e.pointerId;
                dragMoved = false;
                const rect = overlay.getBoundingClientRect();
                dragOrigLeft = rect.left;
                dragOrigTop = rect.top;
                dragStartX = e.clientX;
                dragStartY = e.clientY;
                overlay.setPointerCapture(dragPointerId);
                overlay.classList.add('dragging');
            });

            overlay.addEventListener('pointermove', (e) => {
                if (dragPointerId === null || e.pointerId !== dragPointerId) return;
                const dx = e.clientX - dragStartX;
                const dy = e.clientY - dragStartY;
                if (!dragMoved && Math.hypot(dx, dy) > 6) dragMoved = true;
                if (!dragMoved) return;

                const rect = overlay.getBoundingClientRect();
                const maxLeft = window.innerWidth - rect.width - 4;
                const maxTop = window.innerHeight - rect.height - 4;
                const newLeft = Math.min(Math.max(dragOrigLeft + dx, 4), Math.max(maxLeft, 4));
                const newTop = Math.min(Math.max(dragOrigTop + dy, 4), Math.max(maxTop, 4));

                overlay.style.left = newLeft + 'px';
                overlay.style.top = newTop + 'px';
                overlay.style.right = 'auto';
                overlay.style.bottom = 'auto';
            });

            function endGroupDrag(e) {
                if (dragPointerId === null || (e && e.pointerId !== dragPointerId)) return;
                overlay.classList.remove('dragging');
                try { overlay.releasePointerCapture(dragPointerId); } catch (err) { }
                dragPointerId = null;
            }
            overlay.addEventListener('pointerup', endGroupDrag);
            overlay.addEventListener('pointercancel', endGroupDrag);

            if (btnCall) btnCall.addEventListener('click', () => { active ? leaveGroupCall() : joinGroupCall(false); });
            if (btnVideoCall) btnVideoCall.addEventListener('click', () => { active ? leaveGroupCall() : joinGroupCall(true); });
            if (btnGroupCall) btnGroupCall.addEventListener('click', () => { active ? leaveGroupCall() : joinGroupCall(false, currentGroupRoom()); });
            if (btnGroupVideoCall) btnGroupVideoCall.addEventListener('click', () => { active ? leaveGroupCall() : joinGroupCall(true, currentGroupRoom()); });
            if (btnLeave) btnLeave.addEventListener('click', leaveGroupCall);

            if (btnMute) {
                btnMute.addEventListener('click', () => {
                    if (!localStream) return;
                    isMuted = !isMuted;
                    localStream.getAudioTracks().forEach(t => t.enabled = !isMuted);
                    btnMute.classList.toggle('call-btn-muted', isMuted);
                });
            }

            if (btnCamera) {
                btnCamera.addEventListener('click', () => {
                    if (!localStream) return;
                    isCameraOff = !isCameraOff;
                    localStream.getVideoTracks().forEach(t => t.enabled = !isCameraOff);
                    btnCamera.classList.toggle('call-btn-muted', isCameraOff);
                });
            }

            // Quitte proprement le salon si l'onglet/la page se ferme pendant l'appel
            window.addEventListener('pagehide', () => {
                if (!active) return;
                const fd = new FormData();
                fd.append('action', 'room_leave');
                fd.append('room', ROOM);
                try { navigator.sendBeacon('call.php', fd); } catch (e) { }
            });
        })();