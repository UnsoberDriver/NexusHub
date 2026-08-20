// ============================================
        // Contacts
        // ============================================
        (function () {
            const contactsList = document.getElementById('mp-contacts-list');

            // Tout le monde peut discuter avec tout le monde : plus besoin
            // d'ajouter quelqu'un, cette liste affiche directement tous les
            // utilisateurs renvoyés par contacts.php?action=list.
            function renderContactsList(contacts) {
                if (!contactsList) return;

                if (!contacts.length) {
                    contactsList.innerHTML = '<div class="insta-empty">Aucun autre utilisateur pour l\'instant.</div>';
                    return;
                }

                const isAdmin = !!window.currentUserIsAdmin;

                contactsList.innerHTML = contacts.map(c => {
                    const label = c.display_name || c.username;
                    const initial = escapeHtml(label.charAt(0).toUpperCase());
                    const avatarInner = c.avatar
                        ? `<img src="${escapeHtml(c.avatar)}" alt="" onerror="this.remove();this.parentElement.textContent='${initial}';">`
                        : initial;
                    const deleteBtn = isAdmin
                        ? `<button type="button" class="contact-delete-btn" data-id="${c.id}" data-name="${escapeHtml(label)}" title="Supprimer cet utilisateur" aria-label="Supprimer ${escapeHtml(label)}" style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:16px;opacity:0.6;padding:4px 8px;">🗑️</button>`
                        : '';
                    return `
                <div class="contact-card" data-id="${c.id}">
                    <span class="contact-avatar">${avatarInner}</span>
                    <span class="contact-name">${escapeHtml(label)}</span>
                    ${deleteBtn}
                </div>`;
                }).join('');
            }

            async function loadContacts() {
                if (!contactsList) return;
                try {
                    const res = await fetch('features/contacts.php?action=list');
                    const data = await res.json();
                    if (Array.isArray(data)) {
                        renderContactsList(data);
                    }
                } catch (e) {
                    contactsList.innerHTML = '<div class="insta-empty">Erreur de chargement.</div>';
                }
            }

            // Suppression d'un utilisateur par un admin (délégation d'événement
            // car les boutons sont recréés à chaque rendu de la liste).
            if (contactsList) {
                contactsList.addEventListener('click', async function (e) {
                    const btn = e.target.closest('.contact-delete-btn');
                    if (!btn) return;

                    const userId = btn.dataset.id;
                    const userName = btn.dataset.name;

                    if (!confirm(`Supprimer définitivement l'utilisateur "${userName}" ? Cette action est irréversible : ses messages et son historique seront supprimés.`)) {
                        return;
                    }

                    btn.disabled = true;
                    try {
                        const res = await fetch('index.php?action=delete_user', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'action=delete_user&user_id=' + encodeURIComponent(userId)
                        });
                        const data = await res.json();
                        if (data.status === 'ok') {
                            loadContacts();
                        } else {
                            alert(data.message || 'Erreur lors de la suppression.');
                            btn.disabled = false;
                        }
                    } catch (e) {
                        alert('Erreur réseau lors de la suppression.');
                        btn.disabled = false;
                    }
                });
            }

            window.loadContacts = loadContacts;
        })();