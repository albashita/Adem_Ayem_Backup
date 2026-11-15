/**
 * Adem Ayem - Menu Management Module
 * CRUD menu (tabel ms_menu), unggah foto, dan pengaturan resep (ms_resep).
 * Semua data diambil dari server, tidak memakai localStorage.
 */

let editingMenuId = null;
let dbMenus = [];

// Kategori (nilai enum ms_menu.kategori) yang punya tombol "Atur Resep".
// Mau resep juga untuk minuman/camilan? Tambahkan di sini: ['makanan', 'minuman']
const RECIPE_CATEGORIES = ['makanan'];

/* ------------------------------------------------------------ HELPER */

function csrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.content
    || (typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '');
}

function menuHeaders() {
  return {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': csrfToken()
  };
}

// Balasan server bisa bukan JSON (mis. halaman error), jadi jangan langsung dipercaya
async function readJson(response) {
  try { return await response.json(); } catch (e) { return {}; }
}

function errorMessage(result, fallback) {
  if (result && result.errors) return Object.values(result.errors).flat().join('\n');
  return (result && result.message) || fallback;
}

function showMenuMessage(type, text) {
  const el = document.getElementById('menuMessage');
  if (!el) return;
  el.textContent = text;
  el.style.color = type === 'error' ? '#dc2626' : '#166534';
  el.style.fontWeight = '600';
  clearTimeout(showMenuMessage.timer);
  showMenuMessage.timer = setTimeout(() => { el.textContent = ''; }, 6000);
}

function menuUrl(action, id = null) {
  const page = document.getElementById('menuPage');
  if (!page) {
    throw new Error('Elemen menuPage tidak ditemukan di Blade.');
  }

  if (action === 'list') return page.dataset.listUrl;
  if (action === 'store') return page.dataset.storeUrl;
  if (action === 'recipe') return `${page.dataset.recipeUrl || '/menu'}/${id}/recipe`;

  const base = action === 'update'
    ? page.dataset.updateUrl
    : page.dataset.deleteUrl;

  return `${base}/${id}`;
}

/* ------------------------------------------------------- MODAL MENU */

function previewMenuImage(input, existingUrl = null) {
  const img = document.getElementById('mImagePreview');
  if (!img) return;

  const file = input && input.files ? input.files[0] : null;
  if (file) {
    img.src = URL.createObjectURL(file);
    img.style.display = 'block';
  } else if (existingUrl) {
    img.src = existingUrl;
    img.style.display = 'block';
  } else {
    img.removeAttribute('src');
    img.style.display = 'none';
  }
}

function openMenuModal(id = null) {
  const form = document.getElementById('menuForm');
  if (!form) {
    alert('Form menu tidak ditemukan.');
    return;
  }

  const isEdit = Boolean(id);
  const menu = isEdit ? dbMenus.find(item => Number(item.id_menu) === Number(id)) : null;

  if (isEdit && !menu) {
    alert('Data menu tidak ditemukan.');
    return;
  }

  editingMenuId = isEdit ? id : null;
  form.reset();
  previewMenuImage(null, menu ? menu.image_url : null);

  document.getElementById('menuModalTitle').textContent = isEdit ? 'Ubah Menu' : 'Tambah Menu';
  document.getElementById('mSaveBtn').textContent = isEdit ? 'Update' : 'Simpan';
  document.getElementById('mStatusField').style.display = isEdit ? '' : 'none';
  document.getElementById('mPackaging').value = 0;

  if (menu) {
    document.getElementById('mCode').value = menu.kode_menu || '';
    document.getElementById('mName').value = menu.name || '';
    document.getElementById('mCat').value = menu.kategori || '';
    document.getElementById('mPrice').value = menu.price ?? '';
    document.getElementById('mPackaging').value = menu.biaya_kemasan ?? 0;
    document.getElementById('mDescription').value = menu.description || '';
    document.getElementById('mStatus').value = menu.status || 'tersedia';
  }

  document.getElementById('menuModal').style.display = 'flex';
  document.getElementById('mName').focus();
}

function closeMenuModal() {
  const modal = document.getElementById('menuModal');
  if (modal) modal.style.display = 'none';
  editingMenuId = null;
}

async function saveMenu(event) {
  if (event) event.preventDefault();

  const code = document.getElementById('mCode').value.trim();
  const name = document.getElementById('mName').value.trim();
  const category = document.getElementById('mCat').value;
  const price = Number(document.getElementById('mPrice').value);
  const packaging = Number(document.getElementById('mPackaging').value || 0);
  const description = document.getElementById('mDescription').value.trim();
  const status = document.getElementById('mStatus').value;

  const imageFile = document.getElementById('mImage')?.files?.[0] || null;
  const isEditing = Boolean(editingMenuId);

  if (!name || !category || !Number.isInteger(price) || price < 1) {
    alert('Nama menu, kategori, dan harga wajib diisi dengan benar.');
    return;
  }

  if (!Number.isInteger(packaging) || packaging < 0) {
    alert('Biaya kemasan harus berupa angka 0 atau lebih.');
    return;
  }

  if (code.length > 10) {
    alert('Kode menu maksimal 10 karakter.');
    return;
  }

  if (imageFile && !['image/jpeg', 'image/png', 'image/webp'].includes(imageFile.type)) {
    alert('Foto harus berformat JPG, PNG, atau WEBP.');
    return;
  }

  if (imageFile && imageFile.size > 2 * 1024 * 1024) {
    alert('Ukuran foto maksimal 2 MB.');
    return;
  }

  const button = document.getElementById('mSaveBtn');
  button.disabled = true;

  const formData = new FormData();
  // Kode kosong = server membuat kode otomatis (menu baru) atau tetap memakai kode lama (ubah)
  if (code) formData.append('kode_menu', code);
  formData.append('name', name);
  formData.append('description', description);
  formData.append('price', String(price));
  formData.append('biaya_kemasan', String(packaging));
  formData.append('categories_id_categorie', category);

  if (imageFile) formData.append('image', imageFile);

  if (isEditing) {
    formData.append('status', status);
    formData.append('_method', 'PUT');
  }

  try {
    const response = await fetch(
      isEditing ? menuUrl('update', editingMenuId) : menuUrl('store'),
      {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken()
        },
        body: formData
      }
    );

    const result = await readJson(response);
    if (!response.ok) {
      throw new Error(errorMessage(result, 'Gagal menyimpan menu (kode ' + response.status + ').'));
    }

    closeMenuModal();
    showMenuMessage('success', result.message || 'Menu berhasil disimpan.');
    await renderMenuAdmin();
  } catch (error) {
    alert(error.message || 'Terjadi kesalahan saat menyimpan menu.');
  } finally {
    button.disabled = false;
  }
}

/* ------------------------------------------------------ DAFTAR MENU */

function filterMenuByCategory() {
  renderFilteredMenu();
}

function renderFilteredMenu() {
  const body = document.getElementById('menuBody');
  if (!body) return;

  const isOwner = (typeof SERVER_USER !== 'undefined') && SERVER_USER.role === 'superadmin';

  const selectedCat = (document.getElementById('filterCategory')?.value || '').trim();
  const filtered = dbMenus.filter(menu => !selectedCat || menu.kategori === selectedCat);

  if (!filtered.length) {
    const emptyMessage = selectedCat ? 'Tidak ada menu pada kategori tersebut.' : 'Belum ada menu.';
    body.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:24px; color:#64748b;">${emptyMessage}</td></tr>`;
    return;
  }

  body.innerHTML = filtered.map(menu => {
    const imageHTML = menu.image_url
      ? `<img
          src="${escapeMenuHTML(menu.image_url)}"
          alt="${escapeMenuHTML(menu.name)}"
          onerror="this.style.display='none';this.nextElementSibling.style.display='inline-block';"
        >
        <span style="display:none;font-size:22px;">🍽️</span>`
      : '<span style="font-size:22px;">🍽️</span>';

    const canSetRecipe = isOwner && RECIPE_CATEGORIES.includes(menu.kategori);
    const kode = menu.kode_menu || menu.code || ('MENU-' + String(menu.id_menu).padStart(3, '0'));
    const nonaktif = menu.status === 'kosong'
      ? '<span class="tag tag-nonaktif">Nonaktif</span>'
      : '';

    return `
      <tr>
        <td class="col-foto">${imageHTML}</td>
        <td class="col-kode">${escapeMenuHTML(kode)}</td>
        <td class="col-nama">${escapeMenuHTML(menu.name)}${nonaktif}</td>
        <td class="col-kategori">
          <span class="tag tag-menu">${escapeMenuHTML(menu.category?.name || '-')}</span>
        </td>
        <td class="col-harga">${rupiah(menu.price)}</td>
        <td class="col-aksi">
          <div class="row-actions">
            ${canSetRecipe ? `
              <button type="button" class="btn-recipe"
                      onclick="openRecipeModal(${Number(menu.id_menu)})"
                      title="Atur resep bahan baku untuk menu ini">Atur Resep</button>
            ` : ''}
            <button type="button" onclick="openMenuModal(${Number(menu.id_menu)})">Ubah</button>
            <button type="button" class="del" onclick="deleteMenu(${Number(menu.id_menu)})">Hapus</button>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

async function renderMenuAdmin() {
  const body = document.getElementById('menuBody');
  if (!body || !document.getElementById('menuPage')) return;

  body.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:20px; color:#64748b;">Memuat data menu...</td></tr>';

  try {
    const response = await fetch(menuUrl('list'), {
      headers: { 'Accept': 'application/json' }
    });

    const result = await readJson(response);
    if (!response.ok) {
      throw new Error(errorMessage(result, 'Gagal memuat menu (kode ' + response.status + ').'));
    }

    dbMenus = Array.isArray(result) ? result : (result.menus || []);
    renderFilteredMenu();
  } catch (error) {
    body.innerHTML = `
      <tr>
        <td colspan="6" style="text-align:center; padding:20px; color:#dc2626;">
          Gagal memuat menu: ${escapeMenuHTML(error.message)}
        </td>
      </tr>
    `;
  }
}

/* ------------------------------------------------------- HAPUS MENU */

async function deleteMenu(id) {
  const menu = dbMenus.find(item => Number(item.id_menu) === Number(id));
  if (!menu) {
    alert('Data menu tidak ditemukan.');
    return;
  }

  const modal = document.getElementById('deleteMenuModal');
  const nameElement = document.getElementById('deleteMenuName');
  const confirmButton = document.getElementById('confirmDeleteMenu');

  if (!modal || !nameElement || !confirmButton) {
    alert('Modal konfirmasi hapus belum tersedia.');
    return;
  }

  nameElement.textContent = menu.name;
  modal.style.display = 'flex';

  confirmButton.onclick = async function () {
    confirmButton.disabled = true;
    try {
      const response = await fetch(menuUrl('delete', id), {
        method: 'DELETE',
        headers: menuHeaders()
      });

      const result = await readJson(response);
      if (!response.ok) {
        throw new Error(errorMessage(result, 'Gagal menghapus menu (kode ' + response.status + ').'));
      }

      modal.style.display = 'none';
      showMenuMessage('success', result.message || 'Menu berhasil dihapus.');
      await renderMenuAdmin();
    } catch (error) {
      alert(error.message || 'Terjadi kesalahan saat menghapus menu.');
    } finally {
      confirmButton.disabled = false;
    }
  };
}

function closeDeleteMenuModal() {
  const modal = document.getElementById('deleteMenuModal');
  if (modal) modal.style.display = 'none';
}

/*
|--------------------------------------------------------------------------
| Pengaturan Resep Menu (Owner / Superadmin)
|--------------------------------------------------------------------------
*/
let currentRecipeMenuId = null;
let currentRecipeMenu = null;
let recipeIngredients = [];
let availableStockItems = [];
let initialRecipeSnapshot = null;

function getRecipeSnapshot(ingredients) {
  return (ingredients || [])
    .map(item => ({
      id_stock_item: Number(item.id_stock_item),
      quantity_required: parseFloat(item.quantity_required) || 0
    }))
    .filter(item => item.id_stock_item > 0)
    .sort((a, b) => a.id_stock_item - b.id_stock_item);
}

function isRecipeChanged() {
  if (initialRecipeSnapshot === null) return false;
  const current = getRecipeSnapshot(recipeIngredients);
  if (current.length !== initialRecipeSnapshot.length) return true;
  for (let i = 0; i < current.length; i++) {
    if (current[i].id_stock_item !== initialRecipeSnapshot[i].id_stock_item) return true;
    if (Math.abs(current[i].quantity_required - initialRecipeSnapshot[i].quantity_required) > 0.0001) return true;
  }
  return false;
}

function updateSaveRecipeButtonState() {
  const saveBtn = document.getElementById('btnSaveRecipe');
  if (saveBtn) saveBtn.disabled = !isRecipeChanged();
}

function showRecipeAlert(type, message) {
  const alertEl = document.getElementById('recipeAlert');
  if (!alertEl) return;
  alertEl.style.display = 'block';
  if (type === 'error') {
    alertEl.style.background = '#fef2f2';
    alertEl.style.color = '#991b1b';
    alertEl.style.border = '1px solid #fecaca';
  } else if (type === 'success') {
    alertEl.style.background = '#f0fdf4';
    alertEl.style.color = '#166534';
    alertEl.style.border = '1px solid #bbf7d0';
  } else {
    alertEl.style.background = '#eff6ff';
    alertEl.style.color = '#1e40af';
    alertEl.style.border = '1px solid #bfdbfe';
  }
  alertEl.textContent = message;
}

function clearRecipeAlert() {
  const alertEl = document.getElementById('recipeAlert');
  if (alertEl) {
    alertEl.style.display = 'none';
    alertEl.textContent = '';
  }
}

async function openRecipeModal(menuId) {
  currentRecipeMenuId = menuId;
  currentRecipeMenu = null;
  recipeIngredients = [];
  availableStockItems = [];
  initialRecipeSnapshot = null;

  clearRecipeAlert();

  const modal = document.getElementById('recipeModal');
  if (!modal) return;
  modal.style.display = 'flex';

  const nameEl = document.getElementById('recipeMenuName');
  const codeEl = document.getElementById('recipeMenuCode');
  const tbody = document.getElementById('recipeTableBody');
  const stockSelect = document.getElementById('recipeStockSelect');
  const qtyInput = document.getElementById('recipeQtyInput');
  const unitDisplay = document.getElementById('recipeUnitDisplay');
  const saveBtn = document.getElementById('btnSaveRecipe');

  if (saveBtn) {
    saveBtn.disabled = true;
    saveBtn.textContent = 'Simpan Resep';
  }

  if (nameEl) nameEl.textContent = 'Memuat...';
  if (codeEl) codeEl.textContent = '...';
  if (stockSelect) stockSelect.innerHTML = '<option value="">-- Memuat Bahan Baku --</option>';
  if (qtyInput) qtyInput.value = '';
  if (unitDisplay) unitDisplay.textContent = '-';
  if (tbody) {
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:16px; color:#64748b;">Memuat data resep...</td></tr>';
  }

  try {
    const response = await fetch(menuUrl('recipe', menuId), { headers: menuHeaders() });

    const result = await readJson(response);
    if (!response.ok) {
      throw new Error(errorMessage(result, 'Gagal memuat data resep (kode ' + response.status + ').'));
    }

    currentRecipeMenu = result.menu || {};
    if (nameEl) nameEl.textContent = currentRecipeMenu.name || '-';
    if (codeEl) codeEl.textContent = currentRecipeMenu.kode_menu || currentRecipeMenu.code || `MENU-${String(menuId).padStart(3, '0')}`;

    availableStockItems = Array.isArray(result.available_stock_items) ? result.available_stock_items : [];
    recipeIngredients = (result.ingredients || []).map(item => ({
      id_menu_ingredient: item.id_menu_ingredient || null,
      id_stock_item: Number(item.id_stock_item),
      name: item.name,
      unit: item.unit,
      quantity_required: Number(item.quantity_required)
    }));

    initialRecipeSnapshot = getRecipeSnapshot(recipeIngredients);

    populateRecipeStockSelect();
    renderRecipeTable();
    updateSaveRecipeButtonState();

    if (!availableStockItems.length && !recipeIngredients.length) {
      showRecipeAlert('info', 'Belum ada bahan baku. Tambahkan dulu data bahan di halaman Stok Barang.');
    }
  } catch (error) {
    showRecipeAlert('error', error.message || 'Terjadi kesalahan saat memuat resep.');
    if (tbody) {
      tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:16px; color:#dc2626;">${escapeMenuHTML(error.message)}</td></tr>`;
    }
    if (saveBtn) saveBtn.disabled = true;
  }
}

function closeRecipeModal() {
  const modal = document.getElementById('recipeModal');
  if (modal) modal.style.display = 'none';

  currentRecipeMenuId = null;
  currentRecipeMenu = null;
  recipeIngredients = [];
  availableStockItems = [];
  initialRecipeSnapshot = null;
  clearRecipeAlert();

  const saveBtn = document.getElementById('btnSaveRecipe');
  if (saveBtn) saveBtn.disabled = true;
}

function populateRecipeStockSelect() {
  const select = document.getElementById('recipeStockSelect');
  if (!select) return;

  const usedIds = new Set(recipeIngredients.map(i => Number(i.id_stock_item)));

  const options = ['<option value="">-- Pilih Bahan Baku --</option>'];
  availableStockItems.forEach(item => {
    const used = usedIds.has(Number(item.id_stock_item));
    options.push(`
      <option value="${item.id_stock_item}" ${used ? 'disabled' : ''}>
        ${escapeMenuHTML(item.name)} (${escapeMenuHTML(item.unit)})${used ? ' - Sudah dalam resep' : ''}
      </option>
    `);
  });

  select.innerHTML = options.join('');
  onRecipeStockSelectChange();
}

function onRecipeStockSelectChange() {
  const select = document.getElementById('recipeStockSelect');
  const unitDisplay = document.getElementById('recipeUnitDisplay');
  if (!select || !unitDisplay) return;

  const found = availableStockItems.find(i => Number(i.id_stock_item) === Number(select.value));
  unitDisplay.textContent = found ? found.unit : '-';
}

function addRecipeIngredient() {
  clearRecipeAlert();

  const select = document.getElementById('recipeStockSelect');
  const qtyInput = document.getElementById('recipeQtyInput');
  if (!select || !qtyInput) return;

  const stockId = Number(select.value);
  if (!stockId) {
    showRecipeAlert('error', 'Pilih bahan baku dari data Stok Barang terlebih dahulu.');
    select.focus();
    return;
  }

  const stockItem = availableStockItems.find(i => Number(i.id_stock_item) === stockId);
  if (!stockItem) {
    showRecipeAlert('error', 'Data bahan baku tidak valid.');
    return;
  }

  if (recipeIngredients.some(i => Number(i.id_stock_item) === stockId)) {
    showRecipeAlert('error', `Bahan baku "${stockItem.name}" sudah ada dalam resep menu ini.`);
    return;
  }

  const qty = parseFloat(qtyInput.value);
  if (isNaN(qty) || qty <= 0) {
    showRecipeAlert('error', 'Jumlah bahan baku harus berupa angka lebih besar dari nol.');
    qtyInput.focus();
    return;
  }

  recipeIngredients.push({
    id_stock_item: stockItem.id_stock_item,
    name: stockItem.name,
    unit: stockItem.unit,
    quantity_required: qty
  });

  select.value = '';
  qtyInput.value = '';

  populateRecipeStockSelect();
  renderRecipeTable();
  updateSaveRecipeButtonState();
  showRecipeAlert('success', `Bahan "${stockItem.name}" berhasil ditambahkan ke daftar.`);
}

function updateRecipeQuantity(stockItemId, newQty) {
  clearRecipeAlert();
  const parsed = parseFloat(newQty);
  const item = recipeIngredients.find(i => Number(i.id_stock_item) === Number(stockItemId));
  if (item) {
    item.quantity_required = isNaN(parsed) ? 0 : parsed;
  }
  updateSaveRecipeButtonState();
}

function removeRecipeIngredient(stockItemId) {
  clearRecipeAlert();
  const found = recipeIngredients.find(i => Number(i.id_stock_item) === Number(stockItemId));
  const itemName = found ? found.name : 'Bahan';

  recipeIngredients = recipeIngredients.filter(i => Number(i.id_stock_item) !== Number(stockItemId));

  populateRecipeStockSelect();
  renderRecipeTable();
  updateSaveRecipeButtonState();
  showRecipeAlert('info', `Bahan "${itemName}" dihapus dari daftar resep.`);
}

function renderRecipeTable() {
  const tbody = document.getElementById('recipeTableBody');
  if (!tbody) return;

  if (!recipeIngredients.length) {
    tbody.innerHTML = `
      <tr>
        <td colspan="5" style="text-align:center; padding:18px; color:#64748b;">
          Belum ada bahan baku yang diatur untuk resep menu ini.<br>
          <small style="color:#94a3b8;">Pilih bahan baku dari form di atas untuk menambahkannya ke resep.</small>
        </td>
      </tr>
    `;
    return;
  }

  tbody.innerHTML = recipeIngredients.map((item, index) => `
    <tr>
      <td style="text-align:center; font-weight:500; color:#64748b;">${index + 1}</td>
      <td style="font-weight:600; color:#1e293b;">${escapeMenuHTML(item.name)}</td>
      <td style="text-align:center;">
        <input
          type="number"
          step="any"
          min="0.001"
          value="${item.quantity_required}"
          oninput="updateRecipeQuantity(${Number(item.id_stock_item)}, this.value)"
          onchange="updateRecipeQuantity(${Number(item.id_stock_item)}, this.value)"
          style="width:90px; padding:6px 8px; border:1px solid #cbd5e1; border-radius:6px; text-align:right; font-size:13px;"
          title="Ubah jumlah bahan baku"
        >
      </td>
      <td style="text-align:center;">
        <span class="tag" style="background:#f1f5f9; color:#475569; font-size:12px;">
          ${escapeMenuHTML(item.unit)}
        </span>
      </td>
      <td style="text-align:center;">
        <button
          type="button"
          class="del"
          onclick="removeRecipeIngredient(${Number(item.id_stock_item)})"
          style="padding:5px 10px; font-size:12px; border-radius:4px;"
          title="Hapus dari resep"
        >
          Hapus
        </button>
      </td>
    </tr>
  `).join('');
}

async function saveRecipe() {
  clearRecipeAlert();

  if (!currentRecipeMenuId) {
    showRecipeAlert('error', 'Menu tidak dipilih.');
    return;
  }

  if (!isRecipeChanged()) {
    showRecipeAlert('info', 'Tidak ada perubahan pada resep.');
    return;
  }

  for (const item of recipeIngredients) {
    const qty = parseFloat(item.quantity_required);
    if (isNaN(qty) || qty <= 0) {
      showRecipeAlert('error', `Jumlah bahan "${item.name}" harus lebih besar dari nol.`);
      return;
    }
  }

  const stockIds = recipeIngredients.map(i => Number(i.id_stock_item));
  if (new Set(stockIds).size !== stockIds.length) {
    showRecipeAlert('error', 'Terdapat bahan baku duplikat dalam resep.');
    return;
  }

  const saveBtn = document.getElementById('btnSaveRecipe');
  if (saveBtn) {
    saveBtn.disabled = true;
    saveBtn.textContent = 'Menyimpan...';
  }

  try {
    const payload = {
      ingredients: recipeIngredients.map(i => ({
        id_stock_item: Number(i.id_stock_item),
        quantity_required: Number(i.quantity_required)
      }))
    };

    const response = await fetch(menuUrl('recipe', currentRecipeMenuId), {
      method: 'POST',
      headers: menuHeaders(),
      body: JSON.stringify(payload)
    });

    const result = await readJson(response);
    if (!response.ok) {
      throw new Error(errorMessage(result, 'Gagal menyimpan resep menu (kode ' + response.status + ').'));
    }

    // Modal ditutup, jadi pesan sukses ditampilkan di halaman utama
    closeRecipeModal();
    showMenuMessage('success', result.message || 'Resep menu berhasil disimpan.');
  } catch (error) {
    showRecipeAlert('error', error.message || 'Terjadi kesalahan saat menyimpan resep.');
    updateSaveRecipeButtonState();
  } finally {
    if (saveBtn) saveBtn.textContent = 'Simpan Resep';
  }
}

/* ------------------------------------------------------------ UMUM */

document.addEventListener('keydown', function (e) {
  if (e.key !== 'Escape') return;
  closeMenuModal();
  closeDeleteMenuModal();
  closeRecipeModal();
});

// Global binding untuk inline handler HTML
window.filterMenuByCategory = filterMenuByCategory;
window.renderFilteredMenu = renderFilteredMenu;
window.renderMenuAdmin = renderMenuAdmin;