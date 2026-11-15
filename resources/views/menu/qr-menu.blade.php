<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#1f5d3a">
    <title>Daftar Menu - Depot Yu Sri Adem Ayem</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Kaushan+Script&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/qr-menu.css') }}">
    <style>
        /* Pastikan atribut hidden selalu menyembunyikan elemen */
        [hidden] { display: none !important; }
        .menu-retry {
            margin-top: 12px;
            padding: 8px 18px;
            border: 0;
            border-radius: 8px;
            background: #2f7d4f;
            color: #fff;
            font: 600 13px 'Poppins', sans-serif;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <header class="menu-header">
        <p class="depot">Depot Yu Sri</p>
        <p class="brand">Adem Ayem</p>
        <h1>Daftar Menu</h1>
    </header>

    <main class="menu-container">
        <div class="menu-filters">
            <input
                type="search"
                id="menuSearch"
                placeholder="Cari nama menu..."
                aria-label="Cari menu"
            >
            <div class="category-chips" id="categoryChips" role="group" aria-label="Pilih kategori"></div>
        </div>

        <div id="menuStatusBox" style="text-align:center;">
            <p id="menuStatus" class="menu-status">Memuat menu...</p>
            <button type="button" id="menuRetry" class="menu-retry" hidden>Muat ulang</button>
        </div>
        <section id="menuList" class="menu-grid"></section>
    </main>

    <footer class="menu-footer">
        Terima kasih telah berkunjung ke Depot Yu Sri Adem Ayem.
    </footer>

    <noscript>
        <p style="text-align:center;padding:20px;">Aktifkan JavaScript untuk melihat daftar menu.</p>
    </noscript>

    <script>
        const MENU_DATA_URL = @json(route('qr-menu.data'));

        const menuList = document.getElementById('menuList');
        const menuStatus = document.getElementById('menuStatus');
        const menuRetry = document.getElementById('menuRetry');
        const menuSearch = document.getElementById('menuSearch');
        const categoryChips = document.getElementById('categoryChips');

        const rupiahFormat = new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            maximumFractionDigits: 0
        });

        let menus = [];
        let selectedCategory = '';

        function escapeHTML(value) {
            return String(value ?? '').replace(/[&<>"']/g, char => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            })[char]);
        }

        // Dipanggil saat file foto gagal dimuat
        function photoFailed(img) {
            const box = img.parentElement;
            if (box) box.innerHTML = '<div class="no-photo">Foto belum tersedia</div>';
        }

        function showStatus(text, withRetry = false) {
            menuStatus.hidden = false;
            menuStatus.textContent = text;
            menuRetry.hidden = !withRetry;
        }

        function hideStatus() {
            menuStatus.hidden = true;
            menuRetry.hidden = true;
        }

        function renderChips(categories) {
            const items = [{ value: '', label: 'Semua' },
                ...categories.map(name => ({ value: name, label: name }))];

            categoryChips.innerHTML = items.map(item => `
                <button type="button"
                        class="chip ${item.value === selectedCategory ? 'active' : ''}"
                        data-value="${escapeHTML(item.value)}"
                        aria-pressed="${item.value === selectedCategory}">
                    ${escapeHTML(item.label)}
                </button>
            `).join('');
        }

        function renderMenus() {
            const keyword = menuSearch.value.trim().toLowerCase();

            const filtered = menus.filter(menu => {
                const name = String(menu.name || '').toLowerCase();
                const description = String(menu.description || '').toLowerCase();
                const category = String(menu.category?.name || '');

                const matchesKeyword = !keyword
                    || name.includes(keyword)
                    || description.includes(keyword);

                return matchesKeyword && (!selectedCategory || category === selectedCategory);
            });

            if (!filtered.length) {
                menuList.innerHTML = '';
                showStatus(menus.length
                    ? 'Menu yang dicari tidak ditemukan.'
                    : 'Belum ada menu yang tersedia.');
                return;
            }

            hideStatus();

            menuList.innerHTML = filtered.map(menu => {
                const photo = menu.image_url
                    ? `<img src="${escapeHTML(menu.image_url)}"
                            alt="${escapeHTML(menu.name)}"
                            loading="lazy"
                            onerror="photoFailed(this)">`
                    : '<div class="no-photo">Foto belum tersedia</div>';

                return `
                    <article class="menu-card">
                        <div class="menu-photo">${photo}</div>
                        <div class="menu-info">
                            <h2>${escapeHTML(menu.name)}</h2>
                            <p>${escapeHTML(menu.description || 'Menu pilihan Adem Ayem.')}</p>
                            <strong>${rupiahFormat.format(Number(menu.price) || 0)}</strong>
                        </div>
                    </article>
                `;
            }).join('');
        }

        async function loadMenus() {
            showStatus('Memuat menu...');

            try {
                const response = await fetch(MENU_DATA_URL, {
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store'
                });

                if (!response.ok) {
                    throw new Error('Gagal mengambil data menu (kode ' + response.status + ').');
                }

                menus = await response.json();

                const categories = [
                    ...new Set(menus.map(menu => menu.category?.name).filter(Boolean))
                ];

                renderChips(categories);
                renderMenus();
            } catch (error) {
                showStatus('Menu gagal dimuat. Periksa koneksi lalu coba lagi.', true);
                console.error(error);
            }
        }

        categoryChips.addEventListener('click', event => {
            const chip = event.target.closest('.chip');
            if (!chip) return;

            selectedCategory = chip.dataset.value;

            categoryChips.querySelectorAll('.chip').forEach(button => {
                const active = button.dataset.value === selectedCategory;
                button.classList.toggle('active', active);
                button.setAttribute('aria-pressed', String(active));
            });

            renderMenus();
        });

        menuSearch.addEventListener('input', renderMenus);
        menuRetry.addEventListener('click', loadMenus);

        loadMenus();
    </script>
</body>
</html>