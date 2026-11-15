/**
 * Adem Ayem - Payment & Receipt Module
 * Modal pembayaran, kalkulasi kembalian, dan struk.
 */

// Ubah sesuai identitas usaha (tampil di struk)
const STORE_NAME = 'ADEM AYEM';
const STORE_ADDRESS = 'Jl. Contoh No. 1, Kotamu';

let selectedPay = 'tunai';
let payingOrder = null;
let lastTrx = null;

const PAY_LABELS = {
  tunai: 'Tunai',
  qris: 'QRIS',
  transfer: 'Transfer Bank'
};

const PAY_ENUM = {
  tunai: 'cash',
  qris: 'qris',
  transfer: 'transfer'
};

function closePaymentModal() {
  const modal = document.getElementById('paymentModal');
  if (modal) modal.style.display = 'none';
}

function selectPayModal(m) {
  selectedPay = m;
  document.querySelectorAll('.paymethod-card').forEach(c => c.classList.toggle('sel', c.dataset.m === m));
  const cashSection = document.getElementById('cashSection');
  if (cashSection) cashSection.style.display = m === 'tunai' ? 'block' : 'none';
  updateChange();
}

function currentPayTotal() {
  if (payingOrder) return payingOrder.total;
  return typeof computeTotals === 'function' ? computeTotals().total : 0;
}

function setQuickAmt(v) {
  const total = currentPayTotal();
  document.getElementById('cashReceived').value = v === 'exact' ? total : v;
  updateChange();
}

function updateChange() {
  const total = currentPayTotal();
  const received = Number(document.getElementById('cashReceived')?.value) || 0;
  const change = received - total;
  const changeEl = document.getElementById('changeAmt');
  if (changeEl) changeEl.textContent = rupiah(change > 0 ? change : 0);
}

function closeReceiptModal() {
  const receiptModal = document.getElementById('receiptModal');
  if (receiptModal) receiptModal.style.display = 'none';
}

/* ------------------------------------------------------------ STRUK */

function receiptRowsHTML(t) {
  const itemsHtml = (t.items || []).map(i =>
    `<div class="rline"><span>${escapeMenuHTML(i.name)}<br>&nbsp;&nbsp;${i.qty} x ${rupiah(i.price)}</span><span>${rupiah(i.price * i.qty)}</span></div>`
  ).join('');

  const d = new Date(t.date);

  return `
    <div class="rline"><span>No. ${escapeMenuHTML(t.no)}</span><span>${d.toLocaleDateString('id-ID')} ${d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}</span></div>
    <div class="rline"><span>Kasir: ${escapeMenuHTML(t.kasir || '-')}</span><span>${t.orderType === 'takeaway' ? 'Bawa pulang' : 'Makan di tempat'}</span></div>
    <div class="rline"><span>Pelanggan</span><span>${escapeMenuHTML(t.customer || '-')}</span></div>
    <hr>${itemsHtml}<hr>
    <div class="rline"><span>Subtotal</span><span>${rupiah(t.subtotal)}</span></div>
    ${t.discount > 0 ? `<div class="rline"><span>Diskon</span><span>- ${rupiah(t.discount)}</span></div>` : ''}
    <div class="rline"><b>TOTAL</b><b>${rupiah(t.total)}</b></div>
    <div class="rline"><span>${PAY_LABELS[t.payment] || t.payment}</span><span>${t.payment === 'tunai' ? rupiah(t.received) : '-'}</span></div>
    ${t.payment === 'tunai' ? `<div class="rline"><span>Kembali</span><span>${rupiah(t.change)}</span></div>` : ''}`;
}

// Isi struk lengkap (dipakai di layar dan saat dicetak)
function receiptDocHTML(t) {
  return `
    <div class="center" style="text-align:center"><b>${escapeMenuHTML(STORE_NAME)}</b><br><span style="font-size:11px">${escapeMenuHTML(STORE_ADDRESS)}</span></div>
    <hr>${receiptRowsHTML(t)}<hr>
    <div class="center" style="text-align:center;font-size:11.5px">Terima kasih atas kunjungan Anda<br>Simpan struk ini sebagai bukti</div>`;
}

function openReceiptModal(t) {
  const bodyEl = document.getElementById('receiptModalBody');
  if (bodyEl) bodyEl.innerHTML = receiptDocHTML(t);

  const receiptModal = document.getElementById('receiptModal');
  if (receiptModal) receiptModal.style.display = 'flex';
}

// Cetak lewat iframe tersembunyi supaya hanya struk yang tercetak
function printLastReceipt() {
  if (!lastTrx) return;

  const old = document.getElementById('printFrame');
  if (old) old.remove();

  const frame = document.createElement('iframe');
  frame.id = 'printFrame';
  frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
  document.body.appendChild(frame);

  const doc = frame.contentWindow.document;
  doc.open();
  doc.write(`<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Struk Pembayaran</title>
<style>
  @page { size: 80mm auto; margin: 0; }
  * { box-sizing: border-box; }
  body { margin:0; padding:4mm; width:80mm; font-family:'Courier New',monospace; font-size:12px; color:#000; }
  .rline { display:flex; justify-content:space-between; padding:1px 0; }
  hr { border:none; border-top:1px dashed #000; margin:6px 0; }
  .center { text-align:center; }
</style>
</head>
<body>
  ${receiptDocHTML(lastTrx)}
</body>
</html>`);
  doc.close();

  setTimeout(() => {
    frame.contentWindow.focus();
    frame.contentWindow.print();
  }, 300);
}

window.addEventListener('afterprint', () => document.body.classList.remove('print-receipt-mode'));