// ═══════════════════════════════════════════════════════
        //  WEBRTC — Appels vocaux (signalisation par polling)
        // ═══════════════════════════════════════════════════════
        (function () {
            const ME = currentUserId; // défini globalement juste après <body>

            // ── éléments DOM ──
            const overlay = document.getElementById('call-overlay');
            const avatarDisplay = document.getElementById('call-avatar-display');
            const usernameDisp = document.getElementById('call-username-display');
            const statusDisp = document.getElementById('call-status-display');
            const timerDisp = document.getElementById('call-timer');
            const actCalling = document.getElementById('call-actions-calling');
            const actIncoming = document.getElementById('call-actions-incoming');
            const actActive = document.getElementById('call-actions-active');
            const audioLocal = document.getElementById('call-audio-local');
            const audioRemote = document.getElementById('call-audio-remote');
            const videoWrap = document.getElementById('call-video-wrap');
            const videoLocal = document.getElementById('call-video-local');
            const videoRemote = document.getElementById('call-video-remote');
            const btnCamera = document.getElementById('btn-camera-call');
            const btnFlipCamera = document.getElementById('btn-flip-camera');
            // Le retournement caméra (avant/arrière) n'a de sens que sur mobile.
            // Détection : user-agent mobile classique + présence tactile, pour éviter
            // les faux positifs des PC à écran tactile.
            const isMobileDevice = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent)
                && (navigator.maxTouchPoints > 0 || 'ontouchstart' in window);
            const btnCallTab = document.getElementById('btn-call');
            const btnMinimize = document.getElementById('btn-minimize-call');

            // Zoom à la molette sur PC : chaque vidéo (distante et locale) peut être
            // zoomée indépendamment en survolant puis en scrollant dessus. Le zoom
            // est centré sur la position du curseur (comme un zoom Google Maps),
            // pas sur le centre de la vidéo.
            (function enableVideoWheelZoom() {
                const ZOOM_MIN = 1;
                const ZOOM_MAX = 3;

                const clamp = (v) => Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, v));

                // mirrored: true pour videoLocal, qui est affichée en miroir (scaleX(-1))
                function makeZoomState(el, mirrored) {
                    let zoom = 1;
                    el.style.transformOrigin = '50% 50%';

                    function handle(e) {
                        e.preventDefault();

                        // On ne recalcule l'origine du zoom qu'au moment où on
                        // part de zoom=1 : à ce moment-là, getBoundingClientRect()
                        // reflète encore la taille réelle (non transformée) de la
                        // vidéo. Si on la recalculait à chaque cran une fois déjà
                        // zoomé, le rect inclurait le zoom en cours et l'origine
                        // dériverait progressivement vers un bord, bloquant le
                        // dézoom (le zoom diminuait bien en valeur, mais le point
                        // de fuite s'éloignait du curseur au point de donner
                        // l'impression que ça ne dézoomait plus).
                        if (zoom === 1) {
                            const rect = el.getBoundingClientRect();
                            let px = ((e.clientX - rect.left) / rect.width) * 100;
                            let py = ((e.clientY - rect.top) / rect.height) * 100;
                            px = Math.max(0, Math.min(100, px));
                            py = Math.max(0, Math.min(100, py));
                            if (mirrored) px = 100 - px;
                            el.style.transformOrigin = `${px}% ${py}%`;
                        }

                        zoom = clamp(zoom - e.deltaY * 0.0015);
                        el.style.transform = mirrored
                            ? `scaleX(-1) scale(${zoom})`
                            : `scale(${zoom})`;

                        if (zoom === 1) {
                            el.style.transformOrigin = '50% 50%';
                        }
                    }

                    el.addEventListener('wheel', handle, { passive: false });

                    return {
                        handle,
                        reset() {
                            zoom = 1;
                            el.style.transformOrigin = '50% 50%';
                            el.style.transform = '';
                        }
                    };
                }

                const remoteCtl = makeZoomState(videoRemote, false);
                const localCtl = makeZoomState(videoLocal, true);

                // Filet de sécurité : si le curseur est sur une zone sans vidéo
                // (la bande noire visible autour de l'image quand le format ne
                // remplit pas tout le cadre), l'événement 'wheel' n'arrive à
                // aucune des deux vidéos puisqu'aucune ne couvre cette zone. On
                // écoute donc aussi sur le conteneur et on route le zoom vers la
                // vidéo actuellement affichée en grand (la vidéo "swappée" si
                // l'utilisateur a inversé l'affichage, sinon la vidéo distante).
                videoWrap.addEventListener('wheel', (e) => {
                    if (e.target !== videoWrap) return; // déjà géré par la vidéo elle-même
                    const showingLocalBig = videoWrap.classList.contains('call-video-swapped');
                    (showingLocalBig ? localCtl : remoteCtl).handle(e);
                }, { passive: false });

                // Réinitialise le zoom des deux vidéos (appelé à la fin de l'appel).
                window._resetCallVideoZoom = () => {
                    remoteCtl.reset();
                    localCtl.reset();
                };
            })();
            videoLocal.addEventListener('click', () => {
                if (videoLocal._wasDragged) { videoLocal._wasDragged = false; return; }
                videoWrap.classList.toggle('call-video-swapped');
            });
            videoRemote.addEventListener('click', () => {
                if (videoWrap.classList.contains('call-video-swapped')) {
                    videoWrap.classList.remove('call-video-swapped');
                }
            });

            // Déplacement (drag) de la petite vignette vidéo locale n'importe où
            // dans la zone d'appel.
            (function enableLocalVideoDrag() {
                let dragging = false;
                let moved = false;
                let startX = 0, startY = 0;
                let startLeft = 0, startTop = 0;

                videoLocal.addEventListener('pointerdown', (e) => {
                    if (videoWrap.classList.contains('call-video-swapped')) return; // plein écran : pas de drag
                    dragging = true;
                    moved = false;
                    const wrapRect = videoWrap.getBoundingClientRect();
                    const videoRect = videoLocal.getBoundingClientRect();
                    startLeft = videoRect.left - wrapRect.left;
                    startTop = videoRect.top - wrapRect.top;
                    startX = e.clientX;
                    startY = e.clientY;
                    videoLocal.setPointerCapture(e.pointerId);
                });

                videoLocal.addEventListener('pointermove', (e) => {
                    if (!dragging) return;
                    const dx = e.clientX - startX;
                    const dy = e.clientY - startY;
                    if (!moved && Math.hypot(dx, dy) > 6) moved = true;
                    if (!moved) return;

                    const wrapRect = videoWrap.getBoundingClientRect();
                    const videoRect = videoLocal.getBoundingClientRect();
                    let left = startLeft + dx;
                    let top = startTop + dy;
                    left = Math.max(8, Math.min(left, wrapRect.width - videoRect.width - 8));
                    top = Math.max(8, Math.min(top, wrapRect.height - videoRect.height - 8));

                    videoLocal.classList.add('call-video-local-dragged');
                    videoLocal.style.left = left + 'px';
                    videoLocal.style.top = top + 'px';
                    videoLocal.style.right = 'auto';
                    videoLocal.style.bottom = 'auto';
                });

                const endDrag = (e) => {
                    if (!dragging) return;
                    dragging = false;
                    if (moved) {
                        videoLocal._wasDragged = true;
                        try { videoLocal.releasePointerCapture(e.pointerId); } catch (err) {}
                    }
                };
                videoLocal.addEventListener('pointerup', endDrag);
                videoLocal.addEventListener('pointercancel', endDrag);
            })();

            // ── état ──
            let pc = null;   // RTCPeerConnection
            let localStream = null;
            let callState = 'idle'; // idle | calling | incoming | active
            let remoteUser = null;   // { id, username, avatar }
            let pollTimer = null;
            let timerInterval = null;
            let timerSec = 0;
            let isMuted = false;
            let isVideoCall = false;
            let isCameraOff = false;
            let currentFacingMode = 'user'; // 'user' = caméra avant, 'environment' = caméra arrière
            let pendingOffer = null;  // signal reçu en polling
            let pendingIceCandidates = [];  // candidats ICE reçus avant que remoteDescription soit posée

            // ── config STUN/TURN ──
            // TURN via Cloudflare Realtime : les identifiants sont temporaires
            // (TTL 24h) et générés côté serveur (call.php?action=turn_credentials),
            // jamais codés en dur ici. On les met en cache en mémoire pour la durée
            // de la session de page.
            let _rtcConfigCache = null;
            let _rtcConfigCacheAt = 0;
            const RTC_CONFIG_FALLBACK = {
                iceServers: [{ urls: 'stun:stun.l.google.com:19302' }]
            };

            async function getRtcConfig() {
                const CACHE_MS = 12 * 60 * 60 * 1000; // 12h, marge sous le TTL de 24h côté serveur
                if (_rtcConfigCache && (Date.now() - _rtcConfigCacheAt) < CACHE_MS) {
                    return _rtcConfigCache;
                }
                try {
                    const fd = new FormData();
                    fd.append('action', 'turn_credentials');
                    const r = await fetch('features/call.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                    const data = await r.json();
                    if (data.status === 'ok' && Array.isArray(data.iceServers) && data.iceServers.length) {
                        _rtcConfigCache = { iceServers: data.iceServers };
                        _rtcConfigCacheAt = Date.now();
                        console.info('[TURN] Identifiants Cloudflare récupérés :', data.iceServers.length, 'serveurs ICE');
                        return _rtcConfigCache;
                    }
                    // Réponse reçue mais pas au format attendu : très probablement
                    // une erreur serveur (TURN non configuré, curl absent, etc.)
                    console.warn('[TURN] Réponse inattendue de turn_credentials, fallback STUN-only :', data);
                } catch (e) {
                    // On loggue avant de retomber sur STUN-only, sinon l'échec est
                    // invisible et un appel qui ne marche qu'en STUN (impossible en
                    // 4G/NAT restrictif) est très dur à diagnostiquer après coup.
                    console.warn('[TURN] Échec de récupération des identifiants, fallback STUN-only :', e);
                }
                return RTC_CONFIG_FALLBACK;
            }

            // ══════════════════════════════════════════
            //  Signalisation HTTP
            // ══════════════════════════════════════════
            async function signal(action, toUser, payload = '') {
                const fd = new FormData();
                fd.append('action', action);
                fd.append('to_user', toUser);
                if (payload) fd.append('payload', typeof payload === 'string' ? payload : JSON.stringify(payload));
                try {
                    const r = await fetch('features/call.php', { method: 'POST', body: fd });
                    return await r.json();
                } catch (e) { return null; }
            }

            async function poll() {
                try {
                    const r = await fetch('features/call.php?action=poll');
                    const signals = await r.json();
                    for (const s of signals) handleSignal(s);
                } catch (e) { }
            }

            function startPolling() {
                if (pollTimer) return;
                pollTimer = setInterval(poll, 1500);
            }

            function stopPolling() {
                if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
            }

            // ══════════════════════════════════════════
            //  Gestion des signaux reçus
            // ══════════════════════════════════════════
            async function handleSignal(s) {
                const from = parseInt(s.from_user);

                // Signal appartenant à un salon de groupe : délégué au script
                // séparé qui gère le mesh WebRTC de l'appel de groupe.
                if (s.room) {
                    if (window.handleGroupSignal) window.handleGroupSignal(s);
                    return;
                }

                if (s.type === 'offer') {
                    if (callState !== 'idle') {
                        // Si l'offre vient de la personne avec qui on est déjà en
                        // communication, c'est très probablement un doublon (livré deux
                        // fois par le polling) : on l'ignore silencieusement au lieu de
                        // la rejeter, sinon l'appelant se retrouve raccroché à tort.
                        if (remoteUser && from === remoteUser.id) {
                            // Cas particulier : les deux utilisateurs s'appellent en même
                            // temps (chacun a envoyé une offre à l'autre avant de recevoir
                            // la sienne). Sans traitement spécifique, les deux restent
                            // bloqués en "calling" (ça sonne indéfiniment sans jamais se
                            // connecter). On départage de façon déterministe : celui dont
                            // l'ID est le plus élevé abandonne sa propre offre et accepte
                            // automatiquement celle de l'autre à la place.
                            if (callState === 'calling' && currentUserId > from) {
                                endCall(false); // nettoyage local, sans envoyer de hangup
                                pendingOffer = s;
                                try {
                                    isVideoCall = !!JSON.parse(s.payload).video;
                                } catch (e) {
                                    isVideoCall = false;
                                }
                                remoteUser = { id: from, username: s.username, avatar: s.avatar };
                                callState = 'incoming';
                                await acceptCall();
                            }
                            // Sinon (notre ID est plus petit) : on garde notre propre
                            // offre et on ignore la leur — c'est l'autre côté qui va
                            // accepter automatiquement la nôtre grâce à cette même règle.
                            return;
                        }
                        // Sinon, on est réellement occupé avec quelqu'un d'autre — on rejette.
                        await signal('reject', from);
                        return;
                    }
                    pendingOffer = s;
                    try {
                        isVideoCall = !!JSON.parse(s.payload).video;
                    } catch (e) {
                        isVideoCall = false;
                    }
                    remoteUser = { id: from, username: s.username, avatar: s.avatar };
                    showModal('incoming');
                    playRingtone(true);
                    return;
                }

                if (s.type === 'answer' && callState === 'calling' && from === remoteUser?.id) {
                    const answer = JSON.parse(s.payload);
                    await pc.setRemoteDescription(new RTCSessionDescription(answer));
                    await flushPendingIceCandidates();
                    return;
                }

                if (s.type === 'ice' && from === remoteUser?.id) {
                    let candidate;
                    try {
                        candidate = JSON.parse(s.payload);
                    } catch (e) { return; }
                    if (!candidate || !candidate.candidate) return;

                    // Tant que la RTCPeerConnection n'existe pas encore (on est en train de
                    // décrocher) ou que sa remoteDescription n'est pas posée, on ne peut pas
                    // ajouter le candidat immédiatement — on le met en attente au lieu de le
                    // jeter, sinon les candidats reçus pendant la sonnerie sont perdus pour
                    // toujours (source de l'échec de connexion en 4G/5G, où l'établissement
                    // ICE/TURN est plus lent et où chaque candidat compte).
                    if (!pc || !pc.remoteDescription) {
                        pendingIceCandidates.push(candidate);
                        return;
                    }
                    try {
                        await pc.addIceCandidate(new RTCIceCandidate(candidate));
                    } catch (e) { }
                    return;
                }

                if (s.type === 'hangup' && from === remoteUser?.id) {
                    endCall(false);
                    showStatus('Appel terminé');
                    return;
                }

                if (s.type === 'reject' && from === remoteUser?.id) {
                    endCall(false);
                    showStatus('Appel refusé');
                    return;
                }

                if (s.type === 'busy' && from === remoteUser?.id) {
                    endCall(false);
                    showStatus('Occupé');
                    return;
                }
            }

            // ══════════════════════════════════════════
            //  Applique les candidats ICE reçus en avance
            // ══════════════════════════════════════════
            async function flushPendingIceCandidates() {
                if (!pc || !pc.remoteDescription || pendingIceCandidates.length === 0) return;
                const toApply = pendingIceCandidates;
                pendingIceCandidates = [];
                for (const candidate of toApply) {
                    try {
                        await pc.addIceCandidate(new RTCIceCandidate(candidate));
                    } catch (e) { }
                }
            }

            // ══════════════════════════════════════════
            //  Initier un appel
            // ══════════════════════════════════════════
            async function startCall(userId, username, avatar, video = false) {
                if (callState !== 'idle') return;

                isVideoCall = video;
                remoteUser = { id: userId, username, avatar };
                showModal('calling');

                try {
                    localStream = await navigator.mediaDevices.getUserMedia({
                        audio: {
                            echoCancellation: true,
                            noiseSuppression: true,
                            autoGainControl: true,
                            sampleRate: 48000,
                            channelCount: 2
                        },
                        video: isVideoCall ? (window.getVideoConstraints ? window.getVideoConstraints() : { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' }) : false
                    });
                    window._localStream = localStream;
                    window._callAudioElement = audioRemote;
                } catch (e) {
                    alert((isVideoCall ? 'Impossible d\'accéder à la caméra/au microphone : ' : 'Impossible d\'accéder au microphone : ') + e.message);
                    endCall(false);
                    return;
                }

                if (isVideoCall) {
                    localStream = applyVideoEnhancement(localStream, getVideoQualitySettings().framerate);
                    window._localStream = localStream;
                    videoLocal.srcObject = localStream;
                } else {
                    audioLocal.srcObject = localStream;
                    audioLocal.muted = true;
                }
                // Applique le réglage micro par défaut (Paramètres) ou l'état du bouton micro de l'en-tête
                if (localStorage.getItem('micDefaultMuted') === 'true' || (window.upanelMicMuted && window.upanelMicMuted())) {
                    setMicMuted(true);
                }
                pc = await createPeer();
                localStream.getTracks().forEach(t => pc.addTrack(t, localStream));

                const offer = await pc.createOffer();
                offer.sdp = forceStereoOpusSdp(offer.sdp);
                await pc.setLocalDescription(offer);
                await applyVideoBitrate(pc);

                const res = await signal('offer', userId, {
                    type: pc.localDescription.type,
                    sdp: pc.localDescription.sdp,
                    video: isVideoCall
                });
                if (res && res.status === 'busy') {
                    endCall(false);
                    showStatus('Ce contact est déjà en appel');
                }
            }

            // ══════════════════════════════════════════
            //  Décrocher un appel entrant
            // ══════════════════════════════════════════
            async function acceptCall() {
                if (!pendingOffer || callState !== 'incoming') return;
                playRingtone(false);

                const offerData = JSON.parse(pendingOffer.payload);
                isVideoCall = !!offerData.video;

                try {
                    localStream = await navigator.mediaDevices.getUserMedia({
                        audio: {
                            echoCancellation: true,
                            noiseSuppression: true,
                            autoGainControl: true,
                            sampleRate: 48000,
                            channelCount: 2
                        },
                        video: isVideoCall ? (window.getVideoConstraints ? window.getVideoConstraints() : { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' }) : false
                    });
                    window._localStream = localStream;
                    window._callAudioElement = audioRemote;
                } catch (e) {
                    alert((isVideoCall ? 'Impossible d\'accéder à la caméra/au microphone : ' : 'Impossible d\'accéder au microphone : ') + e.message);
                    await signal('reject', remoteUser.id);
                    endCall(false);
                    return;
                }

                if (isVideoCall) {
                    localStream = applyVideoEnhancement(localStream, getVideoQualitySettings().framerate);
                    window._localStream = localStream;
                    videoLocal.srcObject = localStream;
                } else {
                    audioLocal.srcObject = localStream;
                    audioLocal.muted = true;
                }
                // Applique le réglage micro par défaut (Paramètres) ou l'état du bouton micro de l'en-tête
                if (localStorage.getItem('micDefaultMuted') === 'true' || (window.upanelMicMuted && window.upanelMicMuted())) {
                    setMicMuted(true);
                }
                pc = await createPeer();
                localStream.getTracks().forEach(t => pc.addTrack(t, localStream));

                await pc.setRemoteDescription(new RTCSessionDescription({ type: offerData.type, sdp: offerData.sdp }));
                // pc a maintenant une remoteDescription : on applique tout de suite les
                // candidats ICE reçus pendant qu'on décrochait (caméra/micro, etc.) et qui
                // avaient été mis en attente au lieu d'être perdus.
                await flushPendingIceCandidates();

                const answer = await pc.createAnswer();
                answer.sdp = forceStereoOpusSdp(answer.sdp);
                await pc.setLocalDescription(answer);
                await applyVideoBitrate(pc);

                await signal('answer', remoteUser.id, pc.localDescription);

                callState = 'active';
                showModal('active');
            }

            // ══════════════════════════════════════════
            //  RTCPeerConnection
            // ══════════════════════════════════════════
            // Bitrate max (bps), framerate cible et résolution par palier de
            // qualité. Les deux paliers 4K distinguent 30fps et 60fps car le
            // doublement du framerate nécessite un bitrate nettement plus
            // élevé pour garder une qualité comparable.
            const VIDEO_QUALITY_SETTINGS = {
                '720p':  { bitrate: 1_000_000,  framerate: 30, width: 1280, height: 720  },
                '1080p': { bitrate: 2_500_000,  framerate: 30, width: 1920, height: 1080 },
                '4k30':  { bitrate: 10_000_000, framerate: 30, width: 3840, height: 2160 },
                '4k60':  { bitrate: 16_000_000, framerate: 60, width: 3840, height: 2160 }
            };
            // Alias pour compatibilité avec un ancien réglage 'videoQuality' = '4k'
            // stocké côté navigateur avant l'ajout des paliers 30/60fps.
            VIDEO_QUALITY_SETTINGS['4k'] = VIDEO_QUALITY_SETTINGS['4k30'];

            function getVideoQualitySettings() {
                const quality = localStorage.getItem('videoQuality') || '720p';
                return VIDEO_QUALITY_SETTINGS[quality] || VIDEO_QUALITY_SETTINGS['720p'];
            }
            window.getVideoQualitySettings = getVideoQualitySettings;

            // Contraintes getUserMedia dérivées du réglage de qualité choisi
            // dans les paramètres (résolution + framerate ciblés).
            window.getVideoConstraints = function () {
                const s = getVideoQualitySettings();
                return {
                    width: { ideal: s.width },
                    height: { ideal: s.height },
                    // 'ideal' seul (pas de 'max') : certaines webcams ne supportent pas
                    // le 4K à 60fps, seulement 4K30 ou 1080p60. Avec un 'max' strict,
                    // le navigateur peut silencieusement retomber sur une résolution
                    // bien plus basse pour satisfaire le framerate au lieu de garder le 4K.
                    frameRate: { ideal: s.framerate },
                    facingMode: 'user'
                };
            };

            // ── Amélioration vidéo (contraste/saturation + netteté légère) ──
            // Le flux caméra brut manque souvent de punch (contre-jour, webcams
            // bas de gamme). On repasse chaque frame par un <canvas> avec un
            // filtre CSS (peu coûteux, accéléré) + une légère convolution de
            // netteté, puis on capture ce canvas en flux vidéo à envoyer.
            // Activable/désactivable via localStorage 'videoEnhance' (ON par défaut).
            let _enhanceLoopHandle = null;
            let _enhanceVideoEl = null;
            let _enhanceRawVideoTrack = null;

            function isVideoEnhanceEnabled() {
                return localStorage.getItem('videoEnhance') !== 'false';
            }

            function stopVideoEnhancement() {
                if (_enhanceLoopHandle) {
                    cancelAnimationFrame(_enhanceLoopHandle);
                    _enhanceLoopHandle = null;
                }
                if (_enhanceVideoEl) {
                    try { _enhanceVideoEl.srcObject = null; } catch (_) {}
                    _enhanceVideoEl = null;
                }
                // Le track caméra brut n'est plus référencé que par le pipeline canvas ;
                // sans ça la webcam resterait allumée après la fin de l'appel.
                if (_enhanceRawVideoTrack) {
                    try { _enhanceRawVideoTrack.stop(); } catch (_) {}
                    _enhanceRawVideoTrack = null;
                }
            }

            function applyVideoEnhancement(rawStream, targetFramerate) {
                const videoTrack = rawStream.getVideoTracks()[0];
                if (!videoTrack || !isVideoEnhanceEnabled()) return rawStream;

                try {
                    const settings = videoTrack.getSettings();
                    const width = settings.width || 1280;
                    const height = settings.height || 720;

                    const srcVideo = document.createElement('video');
                    srcVideo.muted = true;
                    srcVideo.playsInline = true;
                    srcVideo.srcObject = new MediaStream([videoTrack]);
                    srcVideo.play().catch(() => {});
                    _enhanceVideoEl = srcVideo;
                    _enhanceRawVideoTrack = videoTrack;

                    const canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;
                    const ctx = canvas.getContext('2d', { alpha: false, desynchronized: true });
                    // Contraste/saturation/luminosité légers, accélérés par le
                    // navigateur (filtre CSS natif appliqué au dessin) : corrige le
                    // rendu plat/terne typique des webcams sans dénaturer les couleurs.
                    // NB : une vraie netteté (unsharp mask pixel par pixel) serait trop
                    // coûteuse en JS temps réel à 4K/60fps ; le contraste local suffit
                    // à donner une impression de netteté nettement meilleure.
                    ctx.filter = 'contrast(1.08) saturate(1.06) brightness(1.03)';

                    const drawFrame = () => {
                        if (srcVideo.readyState >= 2) {
                            ctx.drawImage(srcVideo, 0, 0, width, height);
                        }
                        _enhanceLoopHandle = requestAnimationFrame(drawFrame);
                    };
                    drawFrame();

                    const outStream = canvas.captureStream(targetFramerate || 30);
                    const enhancedTrack = outStream.getVideoTracks()[0];

                    const finalStream = new MediaStream();
                    finalStream.addTrack(enhancedTrack);
                    rawStream.getAudioTracks().forEach(t => finalStream.addTrack(t));
                    return finalStream;
                } catch (e) {
                    // En cas d'échec (navigateur trop restrictif, etc.), on retombe
                    // silencieusement sur le flux brut plutôt que de casser l'appel.
                    stopVideoEnhancement();
                    return rawStream;
                }
            }
            window.isVideoEnhanceEnabled = isVideoEnhanceEnabled;
            window.stopVideoEnhancement = stopVideoEnhancement;

            // Opus encode en mono par défaut même avec channelCount:2 côté
            // getUserMedia ; il faut forcer stereo=1/sprop-stereo=1 sur la
            // ligne fmtp du payload Opus dans le SDP local pour avoir un
            // vrai flux 2 canaux.
            function forceStereoOpusSdp(sdp) {
                const lines = sdp.split('\r\n');
                let opusPayload = null;
                for (const line of lines) {
                    const m = line.match(/^a=rtpmap:(\d+) opus\/48000/i);
                    if (m) { opusPayload = m[1]; break; }
                }
                if (!opusPayload) return sdp;

                let found = false;
                const newLines = lines.map(line => {
                    if (line.startsWith(`a=fmtp:${opusPayload} `)) {
                        found = true;
                        if (/stereo=/.test(line)) {
                            return line.replace(/stereo=\d/g, 'stereo=1').replace(/sprop-stereo=\d/g, 'sprop-stereo=1');
                        }
                        return line + ';stereo=1;sprop-stereo=1';
                    }
                    return line;
                });
                if (!found) {
                    // Pas de ligne fmtp existante pour ce payload : on l'ajoute juste après le rtpmap
                    for (let i = 0; i < newLines.length; i++) {
                        if (newLines[i].startsWith(`a=rtpmap:${opusPayload} opus`)) {
                            newLines.splice(i + 1, 0, `a=fmtp:${opusPayload} stereo=1;sprop-stereo=1`);
                            break;
                        }
                    }
                }
                return newLines.join('\r\n');
            }

            async function applyVideoBitrate(peerConnection) {
                if (!isVideoCall) return;
                const sender = peerConnection.getSenders().find(s => s.track && s.track.kind === 'video');
                if (!sender) return;

                const { bitrate: maxBitrate, framerate: maxFramerate } = getVideoQualitySettings();

                try {
                    const params = sender.getParameters();
                    if (!params.encodings || params.encodings.length === 0) {
                        params.encodings = [{}];
                    }
                    params.encodings[0].maxBitrate = maxBitrate;
                    params.encodings[0].maxFramerate = maxFramerate;
                    params.encodings[0].scaleResolutionDownBy = 1; // pas de downscale auto de la résolution
                    // Par défaut le navigateur peut sacrifier la résolution pour tenir le
                    // framerate/bitrate sous contrainte réseau ou CPU. En 4K on préfère
                    // explicitement garder la netteté quitte à perdre des images/sec.
                    params.degradationPreference = 'maintain-resolution';
                    await sender.setParameters(params);
                } catch (e) {
                    // setParameters peut échouer si la connexion n'est pas encore établie ;
                    // on réessaie une fois après 1s quand les tracks sont actifs
                    setTimeout(async () => {
                        try {
                            const params2 = sender.getParameters();
                            if (!params2.encodings || params2.encodings.length === 0) params2.encodings = [{}];
                            params2.encodings[0].maxBitrate = maxBitrate;
                            params2.encodings[0].maxFramerate = maxFramerate;
                            params2.encodings[0].scaleResolutionDownBy = 1;
                            params2.degradationPreference = 'maintain-resolution';
                            await sender.setParameters(params2);
                        } catch (_) {}
                    }, 1000);
                }
            }


            async function createPeer() {
                const p = new RTCPeerConnection(await getRtcConfig());

                p.onicecandidate = async (e) => {
                    if (e.candidate) {
                        await signal('ice', remoteUser.id, e.candidate.toJSON());
                    }
                };

                p.ontrack = (e) => {
                    // e.streams[0] est le stream assemblé par le navigateur — c'est la référence fiable.
                    // On l'assigne directement sans recréer de MediaStream intermédiaire.
                    const stream = e.streams[0];
                    if (!stream) return;

                    if (isVideoCall) {
                        videoRemote.hidden = false;
                        videoRemote.srcObject = stream;
                        // Astuce autoplay : Chrome/Safari bloquent silencieusement l'autoplay
                        // d'une vidéo NON muette hors d'un geste utilisateur direct. On démarre
                        // donc en muet (toujours autorisé), puis on démute une fois la lecture
                        // effectivement lancée pour récupérer le son.
                        videoRemote.muted = true;
                        videoRemote.play()
                            .then(() => {
                                // Petite marge pour laisser le premier frame se dessiner
                                setTimeout(() => { videoRemote.muted = false; }, 150);
                            })
                            .catch(err => {
                                console.error('Lecture vidéo distante bloquée :', err);
                                // Réessaie une fois après une interaction implicite (le clic Décrocher)
                                setTimeout(() => {
                                    videoRemote.play()
                                        .then(() => { videoRemote.muted = false; })
                                        .catch(() => {});
                                }, 300);
                            });
                    } else {
                        audioRemote.srcObject = stream;
                        // Comme pour la vidéo : certains navigateurs bloquent silencieusement
                        // la lecture si le geste utilisateur (clic "Décrocher") est trop
                        // éloigné dans le temps de la négociation ICE. On retente une fois,
                        // puis on force la lecture au prochain clic dans la fenêtre d'appel
                        // (bouton muet, casque, raccrocher…) qui vaut geste utilisateur.
                        const tryPlayAudio = () => audioRemote.play().catch(() => {});
                        tryPlayAudio();
                        setTimeout(tryPlayAudio, 300);
                        const resumeAudioOnInteraction = () => {
                            tryPlayAudio();
                            document.removeEventListener('click', resumeAudioOnInteraction);
                        };
                        document.addEventListener('click', resumeAudioOnInteraction, { once: true });
                    }

                    callState = 'active';
                    showModal('active');
                    if (!timerInterval) startTimer();
                };

                p.onconnectionstatechange = () => {
                    const state = p.connectionState;

                    if (state === 'disconnected') {
                        // État transitoire (coupure réseau brève, ICE qui se réajuste) :
                        // on laisse une marge avant de conclure à un échec définitif.
                        if (p._disconnectTimer) return;
                        p._disconnectTimer = setTimeout(() => {
                            if (p.connectionState === 'disconnected') {
                                // Échec confirmé après le délai de grâce : on informe l'autre
                                // partie via un signal hangup, sinon elle reste bloquée sur
                                // "En communication" sans savoir que l'appel est terminé.
                                endCall(true);
                            }
                            p._disconnectTimer = null;
                        }, 6000);
                        return;
                    }

                    if (p._disconnectTimer) {
                        clearTimeout(p._disconnectTimer);
                        p._disconnectTimer = null;
                    }

                    if (['failed', 'closed'].includes(state)) {
                        // Même logique : on prévient l'autre partie pour éviter un appel
                        // "fantôme" affiché chez elle alors qu'il est déjà terminé ici.
                        endCall(true);
                    }
                };

                return p;
            }

            // ══════════════════════════════════════════
            //  Raccrocher
            // ══════════════════════════════════════════
            async function endCall(sendSignal = true) {
                playRingtone(false);
                stopTimer();

                if (sendSignal && remoteUser) {
                    await signal('hangup', remoteUser.id);
                }

                if (pc) { try { pc.close(); } catch (e) { } pc = null; }
                if (screenStream) { screenStream.getTracks().forEach(t => t.stop()); screenStream = null; }
                isScreenSharing = false;
                if (btnScreenShare) { btnScreenShare.classList.remove('call-btn-muted'); btnScreenShare.title = "Partager l'écran"; }
                if (btnCamera) btnCamera.disabled = false;
                if (btnFlipCamera) btnFlipCamera.disabled = false;
                if (localStream) { localStream.getTracks().forEach(t => t.stop()); localStream = null; }
                stopVideoEnhancement();
                window._localStream = null;
                window._callAudioElement = null;
                audioLocal.srcObject = null;
                audioRemote.srcObject = null;
                videoLocal.srcObject = null;
                videoRemote.srcObject = null;
                videoWrap.classList.remove('call-video-swapped');
                videoLocal.classList.remove('call-video-local-dragged');
                videoLocal.style.left = '';
                videoLocal.style.top = '';
                videoLocal.style.right = '';
                videoLocal.style.bottom = '';
                if (window._resetCallVideoZoom) window._resetCallVideoZoom();

                callState = 'idle';
                pendingOffer = null;
                pendingIceCandidates = [];
                remoteUser = null;
                isMuted = false;
                isVideoCall = false;
                isCameraOff = false;
                currentFacingMode = 'user';
                isSpeakerOn = true;
                setMuteIcon(false);
                setCameraIcon(false);
                setSpeakerIcon(true);
                document.getElementById('btn-mute-call')?.classList.remove('call-btn-muted');
                if (btnSpeaker) btnSpeaker.classList.remove('call-btn-muted');
                if (btnCamera) { btnCamera.classList.remove('call-btn-muted'); }

                // Masque la modale après 1,5s si message de statut affiché
                setTimeout(() => {
                    if (callState === 'idle') {
                        overlay.hidden = true;
                        overlay.classList.remove('call-minimized');
                        overlay.style.left = overlay.style.top = overlay.style.right = overlay.style.bottom = '';
                        chatApp.classList.remove('call-active');
                    }
                }, 1500);
            }

            // ══════════════════════════════════════════
            //  UI modale
            // ══════════════════════════════════════════
            function showModal(state) {
                callState = state;
                overlay.hidden = false;
                chatApp.classList.add('call-active');

                // Bascule avatar (audio) / flux vidéo (appel vidéo)
                avatarDisplay.hidden = isVideoCall;
                videoWrap.hidden = !isVideoCall;

                // Avatar
                if (remoteUser) {
                    if (remoteUser.avatar) {
                        avatarDisplay.innerHTML = `<img src="${remoteUser.avatar}" alt="">`;
                    } else {
                        avatarDisplay.textContent = remoteUser.username.charAt(0).toUpperCase();
                        avatarDisplay.style.background = '#4b6cb7';
                    }
                    usernameDisp.textContent = remoteUser.username;
                }

                actCalling.hidden = state !== 'calling';
                actIncoming.hidden = state !== 'incoming';
                actActive.hidden = state !== 'active';
                timerDisp.hidden = state !== 'active';
                btnCamera.hidden = !(state === 'active' && isVideoCall);
                if (btnFlipCamera) btnFlipCamera.hidden = !(state === 'active' && isVideoCall && isMobileDevice);
                if (btnScreenShare) btnScreenShare.hidden = !(state === 'active' && isVideoCall && !isMobileDevice);

                const labels = {
                    calling: isVideoCall ? 'Appel vidéo en cours…' : 'Appel en cours…',
                    incoming: isVideoCall ? 'Appel vidéo entrant' : 'Appel entrant',
                    active: 'En communication'
                };
                statusDisp.textContent = labels[state] || '';
            }

            // ── Réduire / agrandir l'appel (sans jamais couper le flux) ──
            function setMinimized(min) {
                overlay.classList.toggle('call-minimized', min);
                btnMinimize.title = min ? "Agrandir l'appel" : "Réduire l'appel";
            }
            btnMinimize.addEventListener('click', (e) => {
                e.stopPropagation();
                setMinimized(!overlay.classList.contains('call-minimized'));
            });
            // Cliquer (sans avoir glissé) n'importe où sur la bulle réduite la ragrandit
            overlay.addEventListener('click', () => {
                if (dragMoved) { dragMoved = false; return; }
                if (overlay.classList.contains('call-minimized')) setMinimized(false);
            });

            // ── Glisser la bulle réduite n'importe où sur l'écran ──
            // (souris comme tactile, via la Pointer Events API)
            let dragPointerId = null;
            let dragMoved = false;
            let dragStartX = 0, dragStartY = 0, dragOrigLeft = 0, dragOrigTop = 0;

            overlay.addEventListener('pointerdown', (e) => {
                if (!overlay.classList.contains('call-minimized')) return;
                if (e.target.closest('.call-btn-minimize')) return; // laisse le bouton gérer son propre clic
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

            function endDrag(e) {
                if (dragPointerId === null || (e && e.pointerId !== dragPointerId)) return;
                overlay.classList.remove('dragging');
                try { overlay.releasePointerCapture(dragPointerId); } catch (err) { }
                dragPointerId = null;
            }
            overlay.addEventListener('pointerup', endDrag);
            overlay.addEventListener('pointercancel', endDrag);

            function showStatus(msg) {
                statusDisp.textContent = msg;
                actCalling.hidden = actIncoming.hidden = actActive.hidden = true;
            }

            // ── Timer ──
            function startTimer() {
                if (timerInterval) { clearInterval(timerInterval); }
                timerSec = 0;
                timerDisp.hidden = false;
                timerInterval = setInterval(() => {
                    timerSec++;
                    const m = String(Math.floor(timerSec / 60)).padStart(2, '0');
                    const s = String(timerSec % 60).padStart(2, '0');
                    timerDisp.textContent = m + ':' + s;
                }, 1000);
            }

            function stopTimer() {
                if (timerInterval) { clearInterval(timerInterval); timerInterval = null; }
            }

            // ── Sonnerie ──
            let ringtoneCtx = null;
            let ringtoneNode = null;
            function playRingtone(on) {
                try {
                    if (!on) {
                        if (ringtoneNode) {
                            ringtoneNode.pause();
                            ringtoneNode.currentTime = 0;
                            ringtoneNode = null;
                        }
                        return;
                    }
                    const soundMuted = localStorage.getItem('soundMuted') === 'true';
                    if (soundMuted) return;

                    const file = localStorage.getItem('ringtoneFile') || 'sonnerie-brainrot.mp3';
                    const vol = parseInt(localStorage.getItem('callVolume') ?? '100', 10);

                    ringtoneNode = new Audio('assets/sounds/' + file);
                    ringtoneNode.loop = true;
                    ringtoneNode.volume = vol / 100;
                    ringtoneNode.play().catch(() => { });
                } catch (e) { }
            }

            // ══════════════════════════════════════════
            //  Boutons
            // ══════════════════════════════════════════
            // ── Icônes SVG (micro / caméra / haut-parleur) ──
            const ICONS = {
                micOn: '<path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/>',
                micOff: '<line x1="1" y1="1" x2="23" y2="23"/><path d="M9 9v3a3 3 0 0 0 5.12 2.12M15 9.34V4a3 3 0 0 0-5.94-.6"/><path d="M17 16.95A7 7 0 0 1 5 12v-2m14 0v2a7 7 0 0 1-.11 1.23"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/>',
                camOn: '<path d="M23 7 16 12l7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>',
                camOff: '<line x1="1" y1="1" x2="23" y2="23"/><path d="M16 16v1a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h1"/><path d="M9 5h5a2 2 0 0 1 2 2v5"/><path d="M23 7 16 12"/>',
                speakerOn: '<path d="M3 17v-5a9 9 0 0 1 18 0v5"/><rect x="2" y="15" width="4" height="6" rx="2" fill="#fff"/><rect x="18" y="15" width="4" height="6" rx="2" fill="#fff"/>',
                speakerOff: '<path d="M3 17v-5a9 9 0 0 1 18 0v5"/><rect x="2" y="15" width="4" height="6" rx="2" fill="#fff"/><rect x="18" y="15" width="4" height="6" rx="2" fill="#fff"/><line x1="3" y1="3" x2="21" y2="21"/>'
            };
            function setMuteIcon(muted) {
                const icon = document.getElementById('icon-mute-call');
                if (icon) icon.innerHTML = muted ? ICONS.micOff : ICONS.micOn;
            }
            function setCameraIcon(off) {
                const icon = document.getElementById('icon-camera-call');
                if (icon) icon.innerHTML = off ? ICONS.camOff : ICONS.camOn;
            }
            function setSpeakerIcon(on) {
                const icon = document.getElementById('icon-speaker-call');
                if (icon) icon.innerHTML = on ? ICONS.speakerOn : ICONS.speakerOff;
                if (btnSpeaker) btnSpeaker.title = on ? 'Couper le casque' : 'Réactiver le casque';
            }
            function videoIconSvg(size = 14) {
                return `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;display:inline-block">${ICONS.camOn}</svg>`;
            }

            document.getElementById('btn-cancel-call').onclick = () => endCall(true);
            document.getElementById('btn-reject-call').onclick = async () => {
                playRingtone(false);
                await signal('reject', remoteUser.id);
                endCall(false);
            };
            document.getElementById('btn-accept-call').onclick = () => acceptCall();
            document.getElementById('btn-hangup-call').onclick = () => endCall(true);

            // Centralise l'état "micro coupé" pour qu'il reste synchronisé
            // entre le bouton de la modale d'appel et l'icône du panneau utilisateur.
            function setMicMuted(muted) {
                isMuted = muted;
                if (localStream) localStream.getAudioTracks().forEach(t => t.enabled = !muted);
                setMuteIcon(muted);
                const btnMute = document.getElementById('btn-mute-call');
                if (btnMute) btnMute.classList.toggle('call-btn-muted', muted);
                if (window.upanelSyncMic) window.upanelSyncMic(muted);
            }
            window.setCallMicMuted = setMicMuted;

            document.getElementById('btn-mute-call').onclick = function () {
                if (!localStream) return;
                setMicMuted(!isMuted);
            };

            btnCamera.onclick = function () {
                if (!localStream) return;
                const videoTracks = localStream.getVideoTracks();
                if (!videoTracks.length) return;
                isCameraOff = !isCameraOff;
                videoTracks.forEach(t => t.enabled = !isCameraOff);
                setCameraIcon(isCameraOff);
                this.classList.toggle('call-btn-muted', isCameraOff);
            };

            // ── Retournement de caméra (avant ↔ arrière, mobile uniquement) ──
            if (btnFlipCamera) {
                btnFlipCamera.onclick = async function () {
                    if (!localStream || !pc || !isMobileDevice) return;

                    currentFacingMode = currentFacingMode === 'user' ? 'environment' : 'user';

                    let newStream;
                    try {
                        newStream = await navigator.mediaDevices.getUserMedia({
                            audio: false,
                            video: { facingMode: { exact: currentFacingMode }, width: { ideal: 1280 }, height: { ideal: 720 } }
                        });
                    } catch (e) {
                        // Certains navigateurs n'acceptent pas "exact" — on retente sans contrainte stricte
                        try {
                            newStream = await navigator.mediaDevices.getUserMedia({
                                audio: false,
                                video: { facingMode: currentFacingMode, width: { ideal: 1280 }, height: { ideal: 720 } }
                            });
                        } catch (e2) {
                            console.error('Impossible de retourner la caméra :', e2);
                            currentFacingMode = currentFacingMode === 'user' ? 'environment' : 'user'; // annule
                            return;
                        }
                    }

                    const newVideoTrack = newStream.getVideoTracks()[0];

                    // Remplace la track dans la PeerConnection (sans renegociation SDP)
                    const sender = pc.getSenders().find(s => s.track && s.track.kind === 'video');
                    if (sender) {
                        await sender.replaceTrack(newVideoTrack);
                    }

                    // Remplace la track locale et arrête l'ancienne
                    const oldVideoTrack = localStream.getVideoTracks()[0];
                    if (oldVideoTrack) {
                        localStream.removeTrack(oldVideoTrack);
                        oldVideoTrack.stop();
                    }
                    localStream.addTrack(newVideoTrack);
                    videoLocal.srcObject = localStream;

                    // Réapplique l'état caméra coupée si nécessaire
                    newVideoTrack.enabled = !isCameraOff;

                    // Animation rotation du bouton
                    this.classList.add('flipping');
                    setTimeout(() => this.classList.remove('flipping'), 400);
                };
            }

            // ── Partage d'écran ──────────────────────────────────────────────
            // Remplace la piste vidéo envoyée (caméra) par la piste de l'écran
            // partagé, sans renégociation SDP (replaceTrack), comme pour le flip
            // de caméra. Uniquement disponible en appel vidéo (il faut déjà un
            // "sender" vidéo actif dans la connexion).
            let isScreenSharing = false;
            let screenStream = null;
            const btnScreenShare = document.getElementById('btn-screenshare-call');

            async function stopScreenShare() {
                if (!isScreenSharing) return;
                isScreenSharing = false;

                if (screenStream) {
                    screenStream.getTracks().forEach(t => t.stop());
                    screenStream = null;
                }

                // Revient à la caméra
                const camTrack = localStream ? localStream.getVideoTracks()[0] : null;
                if (pc && camTrack) {
                    const sender = pc.getSenders().find(s => s.track && s.track.kind === 'video');
                    if (sender) { try { await sender.replaceTrack(camTrack); } catch (e) { } }
                }
                if (camTrack) camTrack.enabled = !isCameraOff;
                videoLocal.srcObject = localStream;

                btnScreenShare.classList.remove('call-btn-muted');
                btnScreenShare.title = "Partager l'écran";
                if (btnCamera) btnCamera.disabled = false;
                if (btnFlipCamera) btnFlipCamera.disabled = false;
            }

            if (btnScreenShare) {
                btnScreenShare.onclick = async function () {
                    if (!pc) return;

                    if (isScreenSharing) {
                        await stopScreenShare();
                        return;
                    }

                    if (!isVideoCall) {
                        alert("Le partage d'écran n'est disponible que pour les appels vidéo.");
                        return;
                    }

                    const sender = pc.getSenders().find(s => s.track && s.track.kind === 'video');
                    if (!sender) {
                        alert("Le partage d'écran n'est pas disponible pour cet appel.");
                        return;
                    }

                    try {
                        screenStream = await navigator.mediaDevices.getDisplayMedia({
                            video: { cursor: 'always' },
                            audio: false
                        });
                    } catch (e) {
                        // L'utilisateur a annulé la sélection de fenêtre/écran
                        return;
                    }

                    const screenTrack = screenStream.getVideoTracks()[0];
                    try {
                        await sender.replaceTrack(screenTrack);
                    } catch (e) {
                        screenStream.getTracks().forEach(t => t.stop());
                        screenStream = null;
                        return;
                    }

                    isScreenSharing = true;
                    videoLocal.srcObject = new MediaStream([screenTrack]);
                    btnScreenShare.classList.add('call-btn-muted');
                    btnScreenShare.title = "Arrêter le partage d'écran";
                    // Le bouton caméra/flip n'a plus de sens tant qu'on partage l'écran
                    if (btnCamera) btnCamera.disabled = true;
                    if (btnFlipCamera) btnFlipCamera.disabled = true;

                    // L'utilisateur peut arrêter le partage via l'UI native du navigateur
                    screenTrack.addEventListener('ended', () => { stopScreenShare(); });
                };
            }
            let isSpeakerOn = true;
            const btnSpeaker = document.getElementById('btn-speaker-call');
            async function applySpeakerOutput() {
                // setSinkId n'est disponible que sur certains navigateurs (Chrome desktop/Android).
                // Sur les navigateurs qui ne le supportent pas, seule l'icône change.
                if (typeof audioRemote.setSinkId !== 'function') return;
                try {
                    const devices = await navigator.mediaDevices.enumerateDevices();
                    const outputs = devices.filter(d => d.kind === 'audiooutput');
                    if (!outputs.length) return;
                    const speaker = outputs.find(d => /speaker|haut/i.test(d.label));
                    const earpiece = outputs.find(d => /ear|receiver|écouteur/i.test(d.label));
                    const target = isSpeakerOn ? (speaker || outputs[0]) : (earpiece || outputs[0]);
                    if (target) await audioRemote.setSinkId(target.deviceId);
                } catch (e) { /* pas grave, on garde la sortie par défaut */ }
            }
            if (btnSpeaker) {
                btnSpeaker.onclick = function () {
                    isSpeakerOn = !isSpeakerOn;
                    setSpeakerIcon(isSpeakerOn);
                    this.classList.toggle('call-btn-muted', !isSpeakerOn);
                    // setSinkId ne fonctionne que sur certains appareils (souvent un
                    // seul device audio dispo sur desktop) : on coupe donc aussi le
                    // son directement pour que le bouton ait un effet garanti partout.
                    audioRemote.muted = !isSpeakerOn;
                    applySpeakerOutput();
                    window.upanelSyncHeadphone && window.upanelSyncHeadphone(!isSpeakerOn);
                };
            }
            window.setCallSpeakerState = function (muted) {
                isSpeakerOn = !muted;
                setSpeakerIcon(isSpeakerOn);
                if (btnSpeaker) btnSpeaker.classList.toggle('call-btn-muted', !isSpeakerOn);
                audioRemote.muted = !isSpeakerOn;
                applySpeakerOutput();
            };

            // Bouton 📞 dans la tab bar : ouvre un sélecteur de contact
            if (btnCallTab) {
                btnCallTab.addEventListener('click', () => {
                    if (callState !== 'idle') return;
                    tabBtns.forEach(b => b.classList.remove('active'));
                    btnCallTab.classList.add('active');
                    openCallHistory();
                });
            }

            // ── Historique des appels ──
            function formatCallTime(dateStr) {
                const d = new Date(dateStr.replace(' ', 'T'));
                const now = new Date();
                const sameDay = d.toDateString() === now.toDateString();
                const time = d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
                if (sameDay) return time;
                const sameYear = d.getFullYear() === now.getFullYear();
                const datePart = d.toLocaleDateString('fr-FR', sameYear ? { day: '2-digit', month: '2-digit' } : { day: '2-digit', month: '2-digit', year: 'numeric' });
                return `${datePart} ${time}`;
            }

            function formatCallDuration(sec) {
                sec = parseInt(sec) || 0;
                const m = Math.floor(sec / 60);
                const s = sec % 60;
                return `${m}:${s.toString().padStart(2, '0')}`;
            }

            async function openCallHistory() {
                let panel = document.getElementById('call-history');
                if (panel) {
                    panel.hidden = !panel.hidden;
                    btnCallTab.classList.toggle('active', !panel.hidden);
                    if (!panel.hidden) loadCallHistory(panel);
                    return;
                }

                panel = document.createElement('div');
                panel.id = 'call-history';
                panel.className = 'call-picker';
                // Styles inline de secours : garantit un petit panneau flottant
                // même si styles.css n'est pas (encore) à jour / mis en cache.
                panel.style.cssText = [
                    'position:fixed', 'bottom:76px', 'right:20px', 'z-index:150',
                    'min-width:260px', 'max-width:320px', 'max-height:420px',
                    'display:flex', 'flex-direction:column',
                    'background:var(--bg-secondary, #1a1a1a)',
                    'border:1px solid var(--border-color, #2a2a2a)',
                    'border-radius:12px', 'box-shadow:0 8px 28px rgba(0,0,0,.35)',
                    'overflow:hidden'
                ].join(';');

                const header = document.createElement('div');
                header.className = 'call-history-header';
                header.style.cssText = 'display:flex;align-items:center;justify-content:space-between;flex-shrink:0;';
                const title = document.createElement('div');
                title.className = 'call-picker-title';
                title.textContent = 'Appels';
                header.appendChild(title);

                const btnNew = document.createElement('button');
                btnNew.type = 'button';
                btnNew.className = 'call-history-new-btn';
                btnNew.title = 'Nouvel appel';
                btnNew.setAttribute('aria-label', 'Nouvel appel');
                btnNew.textContent = '+';
                btnNew.onclick = (e) => {
                    e.stopPropagation();
                    panel.hidden = true;
                    openCallPicker();
                };
                header.appendChild(btnNew);

                panel.appendChild(header);

                const list = document.createElement('div');
                list.className = 'call-history-list';
                list.style.cssText = 'overflow-y:auto;min-height:0;';
                panel.appendChild(list);

                document.body.appendChild(panel);
                loadCallHistory(panel);

                setTimeout(() => {
                    document.addEventListener('click', function close(e) {
                        if (!panel.contains(e.target) && e.target !== btnCallTab) {
                            panel.hidden = true;
                            btnCallTab.classList.remove('active');
                            document.removeEventListener('click', close);
                        }
                    });
                }, 100);
            }

            async function loadCallHistory(panel) {
                const list = panel.querySelector('.call-history-list');
                list.innerHTML = '<div class="call-picker-empty">Chargement…</div>';
                try {
                    const r = await fetch('features/call.php?action=history');
                    const calls = await r.json();
                    list.innerHTML = '';

                    if (!Array.isArray(calls) || calls.length === 0) {
                        const empty = document.createElement('div');
                        empty.className = 'call-picker-empty';
                        empty.textContent = 'Aucun appel pour le moment';
                        list.appendChild(empty);
                        return;
                    }

                    calls.forEach(c => {
                        const row = document.createElement('div');
                        row.className = 'call-history-item';

                        const avatarWrap = document.createElement('div');
                        avatarWrap.className = 'call-history-avatar';
                        if (c.avatar) {
                            const img = document.createElement('img');
                            img.src = c.avatar;
                            img.alt = '';
                            avatarWrap.appendChild(img);
                        } else {
                            avatarWrap.textContent = (c.username || '?').charAt(0).toUpperCase();
                        }
                        row.appendChild(avatarWrap);

                        const info = document.createElement('div');
                        info.className = 'call-history-info';

                        const nameEl = document.createElement('div');
                        nameEl.className = 'call-history-name';
                        if (c.status === 'missed' && c.direction === 'incoming') {
                            nameEl.classList.add('call-history-missed');
                        }
                        nameEl.textContent = c.username;
                        info.appendChild(nameEl);

                        const metaEl = document.createElement('div');
                        metaEl.className = 'call-history-meta';
                        const dirIcon = c.direction === 'outgoing' ? '↗' : (c.status === 'missed' || c.status === 'declined' ? '↙' : '↘');
                        let statusLabel = formatCallTime(c.started_at);
                        if (c.status === 'missed') statusLabel = (c.direction === 'incoming' ? 'Manqué · ' : 'Sans réponse · ') + statusLabel;
                        else if (c.status === 'declined') statusLabel = 'Refusé · ' + statusLabel;
                        else if (c.status === 'completed' && c.duration) statusLabel += ` · ${formatCallDuration(c.duration)}`;
                        metaEl.innerHTML = `<span class="call-history-dir">${dirIcon}</span> ${c.video == 1 ? videoIconSvg(12) + ' ' : ''}${statusLabel}`;
                        info.appendChild(metaEl);

                        row.appendChild(info);

                        const btnCall = document.createElement('button');
                        btnCall.type = 'button';
                        btnCall.className = 'call-picker-action call-history-call-btn';
                        btnCall.title = c.video == 1 ? 'Appel vidéo' : 'Appel audio';
                        btnCall.innerHTML = c.video == 1 ? videoIconSvg(18) : '📞';
                        btnCall.onclick = (e) => {
                            e.stopPropagation();
                            panel.hidden = true;
                            btnCallTab.classList.remove('active');
                            const presenceBtn = document.querySelector(`#presence-list .friend-item[data-id="${c.other_id}"]`);
                            const avatar = presenceBtn?.querySelector('img')?.src || c.avatar || null;
                            startCall(parseInt(c.other_id), c.username, avatar, c.video == 1);
                        };
                        row.appendChild(btnCall);

                        list.appendChild(row);
                    });
                } catch (e) {
                    list.innerHTML = '<div class="call-picker-empty">Impossible de charger l\'historique</div>';
                }
            }

            // ── Sélecteur de contact pour lancer un appel ──
            function openCallPicker() {
                let picker = document.getElementById('call-picker');
                if (picker) {
                    picker.hidden = !picker.hidden;
                    btnCallTab.classList.toggle('active', !picker.hidden);
                    return;
                }

                picker = document.createElement('div');
                picker.id = 'call-picker';
                picker.className = 'call-picker';

                const title = document.createElement('div');
                title.className = 'call-picker-title';
                title.textContent = 'Appeler…';
                picker.appendChild(title);

                // Récupère la liste des contacts depuis les options du select MP
                const mpSelect = document.getElementById('mp-contact');
                if (mpSelect) {
                    Array.from(mpSelect.options).forEach(opt => {
                        if (!opt.value) return;

                        const row = document.createElement('div');
                        row.className = 'call-picker-item';

                        const name = document.createElement('span');
                        name.className = 'call-picker-name';
                        name.textContent = opt.textContent.trim();
                        row.appendChild(name);

                        const getAvatar = () => {
                            const presenceBtn = document.querySelector(`#presence-list .friend-item[data-id="${opt.value}"]`);
                            return presenceBtn?.querySelector('img')?.src || null;
                        };

                        const btnAudioCall = document.createElement('button');
                        btnAudioCall.type = 'button';
                        btnAudioCall.className = 'call-picker-action';
                        btnAudioCall.title = 'Appel audio';
                        btnAudioCall.textContent = '📞';
                        btnAudioCall.onclick = (e) => {
                            e.stopPropagation();
                            picker.hidden = true;
                            startCall(parseInt(opt.value), opt.textContent.trim(), getAvatar(), false);
                        };
                        row.appendChild(btnAudioCall);

                        const btnVideoCall = document.createElement('button');
                        btnVideoCall.type = 'button';
                        btnVideoCall.className = 'call-picker-action';
                        btnVideoCall.title = 'Appel vidéo';
                        btnVideoCall.innerHTML = videoIconSvg(18);
                        btnVideoCall.onclick = (e) => {
                            e.stopPropagation();
                            picker.hidden = true;
                            startCall(parseInt(opt.value), opt.textContent.trim(), getAvatar(), true);
                        };
                        row.appendChild(btnVideoCall);

                        picker.appendChild(row);
                    });
                }

                if (picker.children.length <= 1) {
                    const empty = document.createElement('div');
                    empty.className = 'call-picker-empty';
                    empty.textContent = 'Aucun contact disponible';
                    picker.appendChild(empty);
                }

                document.body.appendChild(picker);

                // Ferme en cliquant ailleurs
                setTimeout(() => {
                    document.addEventListener('click', function close(e) {
                        if (!picker.contains(e.target) && e.target !== btnCallTab) {
                            picker.hidden = true;
                            btnCallTab.classList.remove('active');
                            document.removeEventListener('click', close);
                        }
                    });
                }, 100);
            }

            // ── Expose startCall pour pouvoir l'appeler depuis d'autres endroits ──
            window.startVoiceCall = startCall;

            // Démarre le polling
            startPolling();

        })();