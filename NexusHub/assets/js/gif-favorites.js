(function () {
    const FAV_KEY = "gifFavoritesV1";
    const MAX_FAVORITES = 60;

    function getFavorites() {
        try {
            const list = JSON.parse(localStorage.getItem(FAV_KEY) || "[]");
            return Array.isArray(list) ? list : [];
        } catch (e) {
            return [];
        }
    }

    function saveFavorites(list) {
        localStorage.setItem(FAV_KEY, JSON.stringify(list));
    }

    function isFavorite(src) {
        return getFavorites().some((f) => f.src === src);
    }

    function toggleFavorite(src, alt) {
        let list = getFavorites();
        const idx = list.findIndex((f) => f.src === src);
        let nowFavorite;
        if (idx >= 0) {
            list.splice(idx, 1);
            nowFavorite = false;
        } else {
            list.unshift({ src: src, alt: alt || "" });
            if (list.length > MAX_FAVORITES) list = list.slice(0, MAX_FAVORITES);
            nowFavorite = true;
        }
        saveFavorites(list);
        return nowFavorite;
    }

    function cssEscape(value) {
        if (window.CSS && CSS.escape) return CSS.escape(value);
        return String(value).replace(/["\\]/g, "\\$&");
    }

    function starSvg(filled) {
        return filled
            ? '<svg viewBox="0 0 24 24" width="15" height="15" fill="#ffd23f" stroke="#ffd23f" stroke-width="1.4" xmlns="http://www.w3.org/2000/svg"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26"/></svg>'
            : '<svg viewBox="0 0 24 24" width="15" height="15" fill="rgba(0,0,0,0.35)" stroke="#fff" stroke-width="1.4" xmlns="http://www.w3.org/2000/svg"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26"/></svg>';
    }

    function refreshAllStarsFor(src) {
        const fav = isFavorite(src);
        document
            .querySelectorAll('.gif-item-wrap[data-gif-src="' + cssEscape(src) + '"] .gif-fav-star')
            .forEach((btn) => {
                btn.dataset.favActive = fav ? "1" : "0";
                btn.innerHTML = starSvg(fav);
                btn.setAttribute("aria-label", fav ? "Retirer des favoris" : "Ajouter aux favoris");
            });
    }

    function makeStarButton(src, alt, onToggle) {
        const btn = document.createElement("div");
        btn.role = "button";
        btn.tabIndex = 0;
        btn.className = "gif-fav-star";
        const fav = isFavorite(src);
        btn.dataset.favActive = fav ? "1" : "0";
        btn.innerHTML = starSvg(fav);
        btn.setAttribute("aria-label", fav ? "Retirer des favoris" : "Ajouter aux favoris");
        btn.addEventListener("click", (e) => {
            e.preventDefault();
            e.stopPropagation();
            toggleFavorite(src, alt);
            refreshAllStarsFor(src);
            document.querySelectorAll(".gif-picker[data-fav-ready]").forEach((picker) => {
                if (typeof picker._renderGifFavorites === "function") picker._renderGifFavorites();
            });
            if (typeof onToggle === "function") onToggle();
        });
        return btn;
    }

    function decorateResultsImg(img) {
        if (img.dataset.favDecorated || !img.parentNode) return;
        img.dataset.favDecorated = "1";
        const src = img.src;
        const wrap = document.createElement("div");
        wrap.className = "gif-item-wrap";
        wrap.dataset.gifSrc = src;
        img.parentNode.insertBefore(wrap, img);
        wrap.appendChild(img);
        wrap.appendChild(makeStarButton(src, img.alt));
    }

    async function sendGifFromForm(form, src) {
        const fd = new FormData();
        const typeInput = form.querySelector('input[name="type"]');
        const type = typeInput ? typeInput.value : "general";
        const isGroup = type === "group";
        fd.append("action", isGroup ? "send_group" : "send");
        fd.append("ajax", "1");
        if (isGroup) {
            const groupIdInput = form.querySelector('input[name="group_id"]');
            fd.append("group_id", groupIdInput ? groupIdInput.value : "");
        } else {
            fd.append("type", type);
            const toInput = form.querySelector('input[name="to"]');
            if (toInput) fd.append("to", toInput.value);
        }
        const replyInput = form.querySelector('input[name="reply_to"]');
        if (replyInput) fd.append("reply_to", replyInput.value);
        fd.append("message", "IMAGE:" + src);
        try {
            const res = await fetch("index.php", { method: "POST", body: fd });
            if (!res.ok) {
                console.error("Erreur envoi GIF favori, statut HTTP :", res.status);
                alert("Erreur lors de l'envoi du GIF (statut " + res.status + ")");
                return;
            }
            const picker = form.querySelector(".gif-picker");
            if (picker) picker.hidden = true;
            if (typeof clearReplyTarget === "function") clearReplyTarget(form.id);
            if (isGroup) {
                if (typeof refreshGroupMessages === "function") await refreshGroupMessages();
            } else if (typeof refreshMessages === "function") {
                await refreshMessages();
            }
        } catch (err) {
            console.error("Erreur envoi GIF favori :", err);
            alert("Erreur lors de l'envoi du GIF : " + err.message);
        }
    }

    function setupGifFavorites(formId) {
        const form = document.getElementById(formId);
        if (!form) return;
        const wrapper = form.querySelector(".gif-wrapper");
        if (!wrapper) return;
        const picker = wrapper.querySelector(".gif-picker");
        const searchInput = wrapper.querySelector(".gif-search");
        const resultsEl = wrapper.querySelector(".gif-results");
        const gifBtn = wrapper.querySelector(".btn-gif");
        if (!picker || !searchInput || !resultsEl || !gifBtn) return;

        picker.dataset.favReady = "1";

        const favSection = document.createElement("div");
        favSection.className = "gif-favorites gif-favorites-inline";
        favSection.style.cssText = "text-align:center;width:100%;grid-column:1 / -1;";

        // Keeps the favorites block as the very first cell of the results
        // grid, re-inserting it whenever the grid content gets replaced
        // (e.g. after a new search or a new page of GIFs loads).
        function ensureFavSectionInResults() {
            const hasFavs = getFavorites().length > 0;
            if (!hasFavs) {
                if (favSection.parentNode) favSection.parentNode.removeChild(favSection);
                return;
            }
            if (resultsEl.firstChild !== favSection) {
                resultsEl.insertBefore(favSection, resultsEl.firstChild);
            }
        }

        // Renders the "Favoris" label (always shown, and always kept
        // intact) plus, only when the favorites filter is toggled on, the
        // favorite thumbnails themselves. On a plain open of the picker,
        // the filter is off, so no favorite GIFs are displayed.
        function renderFavorites() {
            const favs = getFavorites();
            favSection.innerHTML = "";
            if (!favs.length) {
                favSection.hidden = true;
                ensureFavSectionInResults();
                return;
            }
            favSection.hidden = false;
            const label = document.createElement("div");
            label.className = "gif-fav-label";
            label.textContent = "Favoris";
            label.style.cursor = "pointer";
            label.title = "Afficher / masquer les favoris";
            label.addEventListener("click", (e) => {
                e.preventDefault();
                e.stopPropagation();
                toggleFavoritesFilter();
            });
            favSection.appendChild(label);

            const filterActive = wrapper.dataset.favFilterActive === "1";
            if (filterActive) {
                const favGrid = document.createElement("div");
                favGrid.style.cssText = "display:grid;grid-template-columns:repeat(2,1fr);gap:8px;";
                favSection.appendChild(favGrid);
                favs.forEach((f) => {
                    const item = document.createElement("div");
                    item.className = "gif-item-wrap gif-fav-item";
                    item.dataset.gifSrc = f.src;

                    const img = document.createElement("img");
                    img.src = f.src;
                    img.alt = f.alt || "";
                    img.loading = "lazy";
                    img.addEventListener("click", (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        sendGifFromForm(form, f.src);
                    });

                    const star = makeStarButton(f.src, f.alt, renderFavorites);
                    item.append(img, star);
                    favGrid.appendChild(item);
                });
            }

            ensureFavSectionInResults();
        }

        // When the favorites filter is active, every non-favorite item in
        // the results grid is hidden and only the favorites cell (with its
        // label, always kept intact) remains visible.
        function applyFavoritesFilter() {
            const active = wrapper.dataset.favFilterActive === "1";
            resultsEl.querySelectorAll(".gif-item-wrap").forEach((item) => {
                if (item === favSection || item.classList.contains("gif-fav-item")) return;
                item.style.display = active ? "none" : "";
            });
        }

        function toggleFavoritesFilter() {
            const active = wrapper.dataset.favFilterActive === "1";
            wrapper.dataset.favFilterActive = active ? "0" : "1";
            renderFavorites();
            applyFavoritesFilter();
        }

        picker._renderGifFavorites = () => {
            renderFavorites();
            applyFavoritesFilter();
        };

        const resultsObserver = new MutationObserver((mutations) => {
            mutations.forEach((m) => {
                m.addedNodes.forEach((node) => {
                    if (node.nodeType === 1 && node.tagName === "IMG") {
                        decorateResultsImg(node);
                    }
                });
            });
            // The grid may have been repopulated (e.g. innerHTML reset by
            // the search logic), which would drop the favorites cell —
            // put it back as the first cell if needed.
            ensureFavSectionInResults();
            applyFavoritesFilter();
        });
        resultsObserver.observe(resultsEl, { childList: true });

        gifBtn.addEventListener("click", () => {
            wrapper.dataset.favFilterActive = "0";
            renderFavorites();
            applyFavoritesFilter();
        });

        wrapper.dataset.favFilterActive = "0";
        renderFavorites();
        applyFavoritesFilter();
    }

    function init() {
        setupGifFavorites("form-general");
        setupGifFavorites("form-mp");
        setupGifFavorites("form-groupe");
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();