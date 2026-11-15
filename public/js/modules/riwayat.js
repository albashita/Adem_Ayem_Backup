/**
 * Adem Ayem - Riwayat Transaksi & Pembayaran Module
 * Filter transaksi, pembayaran (stok dikurangi otomatis di server),
 * ubah pesanan yang belum dibayar, pembatalan pesanan yang sudah dibayar, dan struk.
 */

let histOrders = [];
let histFilter = 'semua';
let cancelingOrder = null;

// payingOrder, selectedPay, dan lastTrx dideklarasikan di payment.js

const PAY_FROM_ENUM = {
  cash: 'tunai',
  qris: 'qris',
  transfer: 'transfer'
};

const PAY_METHOD_NAMES = {
  cash: 'Tunai',
  qris: 'QRIS',
  transfer: 'Transfer Bank',
  tunai: 'Tunai'
};

// Balasan server bisa bukan JSON (mis. halaman error), jadi jangan langsung dipercaya
async function histJson(res) {
  try { return await res.json(); } catch (_) { return {}; }
}

function histErrorMessage(res, data, fallback) {
  if (data && data.errors) return Object.values(data.errors).flat().join('\n');
  if (data && data.message) return data.message;
  if (res.status === 419) return 'Sesi habis. Muat ulang halaman lalu login kembali.';
  return fallback + ' (kode ' + res.status + ')';
}

// Helper modal alert
function triggerHistAlert(message, title = 'Perhatian') {
  const alertModal = document.getElementById('alertModal');
  const msgEl = document.getElementById('alertModalMsg');
  const titleEl = document.getElementById('alertModalTitle');
  if (alertModal && msgEl) {
    msgEl.textContent = message;
    if (titleEl) titleEl.textContent = title;
    alertModal.style.display = 'flex';
  } else {
    alert(message);
  }
}

if (typeof window.closeAlertModal !== 'function') {
  window.closeAlertModal = function () {
    const alertModal = document.getElementById('alertModal');
    if (alertModal) alertModal.style.display = 'none';
  };
}

async function loadHistory() {
  const page = document.getElementById('riwayatPage');
  if (!page) return;

  const body = document.getElementById('histBody');
  if (body) {
    body.innerHTML = '<tr><td colspan="8" class="empty">Memuat data transaksi...</td></tr>';
  }

  const url = page.dataset.listUrl || '/riwayat/data';

  try {
    const res = await fetch(url, {
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    });

    const contentType = res.headers.get('content-type') || '';
    if (!contentType.includes('application/json')) {
      throw new Error('Sesi telah berakhir atau server mengembalikan format tidak valid. Silakan muat ulang halaman.');
    }

    const data = await res.json();
    if (!res.ok) {
      throw new Error(data.message || 'Gagal memuat data transaksi (kode ' + res.status + ').');
    }

    histOrders = Array.isArray(data) ? data : (data.data || []);
    renderHistory();
  } catch (e) {
    console.error('[AdemAyem] Gagal memuat data transaksi:', e);
    if (body) {
      body.innerHTML = `<tr><td colspan="8" class="empty" style="color:var(--danger);padding:24px 12px;">${escapeMenuHTML(e.message || 'Terjadi kesalahan saat memuat data transaksi.')}</td></tr>`;
    }
  }
}

function setHistFilter(f) {
  histFilter = f;
  renderHistory();
}

function onDateFilterChange() {
  const fromEl = document.getElementById('fFrom');
  const toEl = document.getElementById('fTo');
  const fromVal = fromEl?.value || '';
  const toVal = toEl?.value || '';

  if (fromVal && toVal && fromVal > toVal) {
    triggerHistAlert('Tanggal awal tidak boleh melebihi tanggal akhir.', 'Filter Tanggal');
    toEl.value = fromVal;
  }

  renderHistory();
}

function resetHistFilter() {
  ['fSearch', 'fTipe', 'fMetode', 'fFrom', 'fTo'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  setHistFilter('semua');
}

// yyyy-mm-dd waktu lokal
function histLocalDate(d) {
  if (!d) return '';
  const x = new Date(d);
  if (isNaN(x.getTime())) return '';
  const m = String(x.getMonth() + 1).padStart(2, '0');
  const day = String(x.getDate()).padStart(2, '0');
  return x.getFullYear() + '-' + m + '-' + day;
}

function getFilteredHistory() {
  const q = (document.getElementById('fSearch')?.value || '').trim().toLowerCase();
  const tipe = document.getElementById('fTipe')?.value || '';
  const metode = document.getElementById('fMetode')?.value || '';
  const from = document.getElementById('fFrom')?.value || '';
  const to = document.getElementById('fTo')?.value || '';

  return histOrders.filter(o => {
    if (histFilter === 'belum' && o.status !== 'pending') return false;
    if (histFilter === 'sudah' && o.status !== 'completed') return false;
    if (histFilter === 'batal' && o.status !== 'cancelled') return false;

    if (tipe && o.service_type !== tipe) return false;

    if (metode && (o.payment_method || '').toLowerCase() !== metode.toLowerCase()) {
      return false;
    }

    if (q) {
      const hay = ((o.no || '') + ' ' + (o.customer || '')).toLowerCase();
      if (!hay.includes(q)) return false;
    }

    if (from || to) {
      const d = histLocalDate(o.date);
      if (from && d < from) return false;
      if (to && d > to) return false;
    }

    return true;
  });
}

function renderHistory() {
  document.querySelectorAll('#histTabs .cat-tab').forEach(b =>
    b.classList.toggle('active', b.dataset.f === histFilter)
  );

  const histBody = document.getElementById('histBody');
  if (!histBody) return;

  const list = getFilteredHistory();

  if (!list.length) {
    const emptyMsg = histOrders.length
      ? 'Tidak ada transaksi yang sesuai dengan filter yang dipilih.'
      : 'Belum ada transaksi yang tercatat.';
    histBody.innerHTML = `<tr><td colspan="8" class="empty">${emptyMsg}</td></tr>`;
    return;
  }

  histBody.innerHTML = list.map(o => {
    const isTakeaway = o.service_type === 'takeaway';
    const isCompleted = o.status === 'completed';
    const isCancelled = o.status === 'cancelled';

    const serviceBadge = `<span class="badge ${isTakeaway ? 'badge-take' : 'badge-dine'}">${isTakeaway ? 'Bawa Pulang' : 'Makan di Tempat'}</span>`;

    const statusBadge = isCancelled
      ? `<span class="badge badge-unpaid" style="background:#fee2e2;color:#dc2626;">Dibatalkan</span>`
      : isCompleted
        ? `<span class="badge badge-paid">Sudah Bayar</span>`
        : `<span class="badge badge-unpaid">Menunggu Pembayaran</span>`;

    const methodLabel = (isCompleted || isCancelled) && o.payment_method
      ? `<span class="pay-method-badge">${PAY_METHOD_NAMES[o.payment_method] || escapeMenuHTML(o.payment_method)}</span>`
      : `<span style="color:var(--muted);">-</span>`;

    // Teks pembatalan: "Alasan: catatan | Refund: ... | Stok: ..." (bagian pertama ditampilkan, sisanya di tooltip)
    const cancelText = o.cancel_text || '';
    const cancelShort = cancelText.split(' | ')[0] || '-';

    const actionBtn = isCancelled
      ? `<span style="color:var(--muted);font-size:12px;" title="${escapeMenuHTML(cancelText)}">${escapeMenuHTML(cancelShort)}</span>`
      : isCompleted
        ? `<div style="display:flex;gap:6px;justify-content:center;">
             <button class="btn btn-outline btn-compact" onclick="showOrderReceipt(${o.id})" title="Lihat struk pembayaran">Struk</button>
             <button class="btn btn-compact" style="background:#fff5f5;border:1px solid #fca5a5;color:#dc2626;" onclick="openCancelOrder(${o.id})" title="Batalkan pesanan yang tidak jadi">Batalkan</button>
           </div>`
        : `<div style="display:flex;gap:6px;justify-content:center;">
             <button class="btn btn-primary btn-compact" onclick="openPayOrder(${o.id})" title="Proses pembayaran pesanan ini">Bayar</button>
             <button class="btn btn-outline btn-compact" onclick="openEditOrder(${o.id})" title="Ubah isi pesanan">Edit</button>
           </div>`;

    const dateFormatted = new Date(o.date).toLocaleDateString('id-ID', {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    });

    return `
      <tr>
        <td style="white-space:nowrap;font-size:12.5px;">${dateFormatted}</td>
        <td style="font-weight:600;font-family:monospace;letter-spacing:0.5px;">${escapeMenuHTML(o.no)}</td>
        <td>${escapeMenuHTML(o.customer || 'Umum')}</td>
        <td>${serviceBadge}</td>
        <td style="font-weight:700;color:var(--brand);font-variant-numeric:tabular-nums;${isCancelled ? 'text-decoration:line-through;opacity:.6;' : ''}">${rupiah(o.total)}</td>
        <td>${methodLabel}</td>
        <td>${statusBadge}</td>
        <td class="row-actions" style="text-align:center;">${actionBtn}</td>
      </tr>
    `;
  }).join('');
}

// Data riwayat -> bentuk yang dipakai struk
function histToTrx(o) {
  return {
    id: o.id,
    no: o.no,
    date: o.date,
    items: o.items || [],
    subtotal: o.subtotal || 0,
    discount: o.discount || 0,
    tax: o.tax || 0,
    total: o.total || 0,
    payment: PAY_FROM_ENUM[o.payment_method] || o.payment_method || 'tunai',
    received: o.received ?? o.total,
    change: o.change ?? 0,
    kasir: (o.user && o.user.name) || '-',
    customer: o.customer || 'Umum',
    orderType: o.service_type || 'dine-in'
  };
}

function showOrderReceipt(id) {
  const o = histOrders.find(x => x.id === id);
  if (!o) return;
  lastTrx = histToTrx(o);
  openReceiptModal(lastTrx);
}

function openPayOrder(id) {
  payingOrder = histOrders.find(o => o.id === id);
  if (!payingOrder) return;

  const invEl = document.getElementById('payBillInvoice');
  if (invEl) invEl.textContent = payingOrder.no;

  const custEl = document.getElementById('payBillCustomer');
  if (custEl) custEl.textContent = payingOrder.customer || 'Umum';

  const typeEl = document.getElementById('payBillType');
  if (typeEl) {
    typeEl.textContent = payingOrder.service_type === 'takeaway' ? 'Bawa Pulang' : 'Makan di Tempat';
  }

  const subEl = document.getElementById('payBillSubtotal');
  if (subEl) subEl.textContent = rupiah(payingOrder.subtotal || payingOrder.total);

  const discRow = document.getElementById('payBillDiscRow');
  const discEl = document.getElementById('payBillDiscount');
  if (discRow && discEl) {
    if (payingOrder.discount > 0) {
      discRow.style.display = 'flex';
      discEl.textContent = '- ' + rupiah(payingOrder.discount);
    } else {
      discRow.style.display = 'none';
    }
  }

  const totalEl = document.getElementById('payModalTotal');
  if (totalEl) totalEl.textContent = rupiah(payingOrder.total);

  const cashRec = document.getElementById('cashReceived');
  if (cashRec) cashRec.value = '';

  selectPayModal('tunai');
  document.getElementById('paymentModal').style.display = 'flex';
}

async function confirmPayOrder() {
  if (!payingOrder) return;
  const total = payingOrder.total;
  let received = total;
  let change = 0;

  if (selectedPay === 'tunai') {
    received = Number(document.getElementById('cashReceived')?.value) || 0;
    if (received < total) {
      triggerHistAlert('Jumlah uang yang diterima kurang dari total tagihan (' + rupiah(total) + ').', 'Nominal Kurang');
      return;
    }
    change = received - total;
  }

  const btn = document.getElementById('payConfirmBtn');
  if (btn) {
    btn.disabled = true;
    btn.textContent = 'Memproses Pembayaran...';
  }

  try {
    const page = document.getElementById('riwayatPage');
    const base = page?.dataset.payUrl || '/riwayat';
    const res = await fetch(base + '/' + payingOrder.id + '/pay', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': CSRF_TOKEN,
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({
        payment_method: PAY_ENUM[selectedPay] || 'cash',
        received: received
      })
    });

    const data = await histJson(res);
    if (!res.ok) {
      triggerHistAlert(histErrorMessage(res, data, 'Gagal memproses pembayaran'), 'Gagal');
      return;
    }

    lastTrx = {
      ...histToTrx(payingOrder),
      payment: selectedPay,
      received: received,
      change: change
    };

    closePaymentModal();
    openReceiptModal(lastTrx);

    await loadHistory();

  } catch (e) {
    console.error(e);
    triggerHistAlert('Terjadi kesalahan jaringan: ' + e.message, 'Gagal');
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.textContent = 'Konfirmasi & Cetak Struk';
    }
  }
}

// ─── Ubah pesanan yang belum dibayar ─────────────────────────────────────────
let editingOrder = null;
let editLines = [];        // [{ menu_id, name, price, qty }]
let editType = 'dine-in';
let editMenus = [];        // daftar menu tersedia dari server

async function loadEditMenus() {
  if (editMenus.length) return;

  const page = document.getElementById('riwayatPage');
  const url = page?.dataset.menuUrl || '/kasir/menu-data';

  try {
    const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
    const data = await histJson(res);
    editMenus = Array.isArray(data)
      ? data.map(m => ({ id: Number(m.id), name: m.name, price: Number(m.price) }))
      : [];
  } catch (e) {
    editMenus = [];
  }
}

function fillEditMenuSelect() {
  const sel = document.getElementById('editAddMenu');
  if (!sel) return;

  sel.innerHTML = '<option value="">-- Pilih menu --</option>' + editMenus.map(m =>
    `<option value="${m.id}">${escapeMenuHTML(m.name)} (${rupiah(m.price)})</option>`
  ).join('');
}

async function openEditOrder(id) {
  const o = histOrders.find(x => x.id === id);
  if (!o || o.status !== 'pending') return;

  editingOrder = o;
  editType = o.service_type || 'dine-in';
  editLines = (o.items || []).map(i => ({
    menu_id: Number(i.menu_id),
    name: i.name,
    price: Number(i.price),
    qty: Number(i.qty)
  }));

  document.getElementById('editOrderInfo').textContent = `${o.no} · belum dibayar`;
  document.getElementById('editCust').value = (o.customer && o.customer !== 'Umum') ? o.customer : '';
  document.getElementById('editDiscount').value = o.discount > 0 ? o.discount : '';
  document.getElementById('editOrderError').textContent = '';
  document.getElementById('editOrderModal').style.display = 'flex';

  await loadEditMenus();
  fillEditMenuSelect();

  // Harga mengikuti daftar menu terbaru (server juga memakai harga terbaru)
  editLines.forEach(l => {
    const m = editMenus.find(x => x.id === l.menu_id);
    if (m) l.price = m.price;
  });

  renderEditOrder();
}

function closeEditOrder() {
  const modal = document.getElementById('editOrderModal');
  if (modal) modal.style.display = 'none';
  editingOrder = null;
  editLines = [];
}

function setEditType(t) {
  editType = t;
  renderEditOrder();
}

function renderEditOrder() {
  document.querySelectorAll('#editTypeToggle button')
    .forEach(b => b.classList.toggle('sel', b.dataset.t === editType));

  const list = document.getElementById('editItems');
  if (list) {
    list.innerHTML = editLines.length
      ? editLines.map(l => `
          <div class="cart-item">
            <div>
              ${escapeMenuHTML(l.name)}<br>
              <span style="color:var(--muted);font-size:12px">${rupiah(l.price)} x ${l.qty} = ${rupiah(l.price * l.qty)}</span>
            </div>
            <div class="qty-ctrl">
              <button type="button" onclick="editChangeQty(${l.menu_id},-1)" title="Kurangi">−</button>
              <span style="margin:0 6px;min-width:20px;text-align:center;font-weight:600;">${l.qty}</span>
              <button type="button" onclick="editChangeQty(${l.menu_id},1)" title="Tambah">+</button>
              <button type="button" onclick="editRemoveLine(${l.menu_id})" style="margin-left:6px;color:var(--danger);border:none;background:none;cursor:pointer" title="Hapus item">✕</button>
            </div>
          </div>
        `).join('')
      : '<div class="empty">Belum ada item. Tambahkan menu di bawah.</div>';
  }

  renderEditTotals();
}

function editSubtotal() {
  return editLines.reduce((s, l) => s + l.price * l.qty, 0);
}

function editDiscountValue() {
  const sub = editSubtotal();
  const v = Number(document.getElementById('editDiscount')?.value) || 0;
  return Math.min(Math.max(v, 0), sub);
}

function renderEditTotals() {
  const sub = editSubtotal();
  const disc = editDiscountValue();

  const subEl = document.getElementById('editSubtotal');
  if (subEl) subEl.textContent = rupiah(sub);

  const totalEl = document.getElementById('editTotal');
  if (totalEl) totalEl.textContent = rupiah(sub - disc);
}

function editChangeQty(menuId, delta) {
  const line = editLines.find(l => l.menu_id === menuId);
  if (!line) return;

  line.qty = Math.min(99, line.qty + delta);
  if (line.qty <= 0) editLines = editLines.filter(l => l.menu_id !== menuId);

  renderEditOrder();
}

function editRemoveLine(menuId) {
  editLines = editLines.filter(l => l.menu_id !== menuId);
  renderEditOrder();
}

function addEditLine() {
  const sel = document.getElementById('editAddMenu');
  const menuId = Number(sel?.value);
  if (!menuId) return;

  const menu = editMenus.find(m => m.id === menuId);
  if (!menu) return;

  const line = editLines.find(l => l.menu_id === menuId);
  if (line) {
    line.qty = Math.min(99, line.qty + 1);
  } else {
    editLines.push({ menu_id: menu.id, name: menu.name, price: menu.price, qty: 1 });
  }

  sel.value = '';
  document.getElementById('editOrderError').textContent = '';
  renderEditOrder();
}

async function saveEditOrder() {
  if (!editingOrder) return;

  const errEl = document.getElementById('editOrderError');
  const btn = document.getElementById('editSaveBtn');
  errEl.textContent = '';

  if (!editLines.length) {
    errEl.textContent = 'Pesanan harus memiliki minimal 1 menu.';
    return;
  }

  const custName = document.getElementById('editCust').value.trim();

  btn.disabled = true;
  btn.textContent = 'Menyimpan...';

  try {
    const page = document.getElementById('riwayatPage');
    const base = page?.dataset.payUrl || '/riwayat';
    const res = await fetch(base + '/' + editingOrder.id, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': CSRF_TOKEN,
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({
        customer_name: custName || null,
        service_type: editType,
        discount: editDiscountValue(),
        items: editLines.map(l => ({ menu_id: l.menu_id, qty: l.qty }))
      })
    });

    const data = await histJson(res);
    if (!res.ok) {
      errEl.textContent = histErrorMessage(res, data, 'Gagal mengubah pesanan');
      return;
    }

    closeEditOrder();
    await loadHistory();
    triggerHistAlert(data.message || 'Pesanan berhasil diubah.', 'Berhasil');
  } catch (e) {
    errEl.textContent = 'Terjadi kesalahan jaringan: ' + e.message;
  } finally {
    btn.disabled = false;
    btn.textContent = 'Simpan Perubahan';
  }
}

// ─── Batalkan pesanan yang sudah dibayar ─────────────────────────────────────
function openCancelOrder(id) {
  cancelingOrder = histOrders.find(o => o.id === id);
  if (!cancelingOrder) return;

  document.getElementById('cancelOrderInfo').textContent =
    `${cancelingOrder.no} · ${cancelingOrder.customer || 'Umum'} · ${rupiah(cancelingOrder.total)}`;
  document.getElementById('cancelReason').value = 'customer_cancel';
  document.getElementById('cancelNote').value = '';
  document.getElementById('cancelRefund').value =
    cancelingOrder.payment_method === 'cash' ? 'cash' : 'transfer';
  document.getElementById('cancelRestoreStock').value = '1';
  document.getElementById('cancelOrderError').textContent = '';
  document.getElementById('cancelOrderModal').style.display = 'flex';
}

function closeCancelOrder() {
  document.getElementById('cancelOrderModal').style.display = 'none';
  cancelingOrder = null;
}

async function confirmCancelOrder() {
  if (!cancelingOrder) return;

  const reason  = document.getElementById('cancelReason').value;
  const note    = document.getElementById('cancelNote').value.trim();
  const refund  = document.getElementById('cancelRefund').value;
  const restore = document.getElementById('cancelRestoreStock').value === '1';
  const errEl   = document.getElementById('cancelOrderError');
  const btn     = document.getElementById('cancelConfirmBtn');

  errEl.textContent = '';
  if (reason === 'other' && !note) {
    errEl.textContent = 'Keterangan wajib diisi untuk alasan "Lainnya".';
    return;
  }

  btn.disabled = true;
  try {
    const page = document.getElementById('riwayatPage');
    const base = page?.dataset.payUrl || '/riwayat';
    const res = await fetch(base + '/' + cancelingOrder.id + '/cancel', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': CSRF_TOKEN,
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({
        reason,
        note: note || null,
        refund_method: refund,
        restore_stock: restore
      })
    });

    const data = await histJson(res);
    if (!res.ok) {
      errEl.textContent = histErrorMessage(res, data, 'Gagal membatalkan pesanan');
      return;
    }

    closeCancelOrder();
    await loadHistory();
    triggerHistAlert(data.message || 'Pesanan berhasil dibatalkan.', 'Berhasil');
  } catch (e) {
    errEl.textContent = 'Terjadi kesalahan jaringan: ' + e.message;
  } finally {
    btn.disabled = false;
  }
}

// Ekspor ke window agar dapat dipanggil dari atribut onclick di HTML
window.loadHistory = loadHistory;
window.renderHistory = renderHistory;
window.setHistFilter = setHistFilter;
window.resetHistFilter = resetHistFilter;
window.onDateFilterChange = onDateFilterChange;
window.openPayOrder = openPayOrder;
window.confirmPayOrder = confirmPayOrder;
window.showOrderReceipt = showOrderReceipt;
window.openEditOrder = openEditOrder;
window.closeEditOrder = closeEditOrder;
window.setEditType = setEditType;
window.renderEditTotals = renderEditTotals;
window.editChangeQty = editChangeQty;
window.editRemoveLine = editRemoveLine;
window.addEditLine = addEditLine;
window.saveEditOrder = saveEditOrder;
window.openCancelOrder = openCancelOrder;
window.closeCancelOrder = closeCancelOrder;
window.confirmCancelOrder = confirmCancelOrder;