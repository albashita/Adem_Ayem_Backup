(function () {
  // DIUBAH: users.js dihapus dari daftar
  const MODULES = [
    'core.js',
    'qr-code.js',
    'dashboard.js',
    'menu.js',
    'stok.js',
    'pengeluaran.js',
    'kasir.js',
    'payment.js',
    'riwayat.js',
    'laporan.js',
    'profil.js'
  ];

  function initApp() {
    if (typeof seedIfEmpty === 'function') seedIfEmpty();
    if (typeof ensureMenuCodes === 'function') ensureMenuCodes();

    // DIUBAH: cabang loginScreen/doLogin dihapus (login sekarang halaman Blade + server)
    if (typeof PAGE_KEY !== 'undefined' && typeof bootstrapPage === 'function') {
      bootstrapPage();
    }
  }

  function start() {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initApp);
    } else {
      initApp();
    }
  }

  // Kasus 1: Modul sudah dimuat melalui script tags di Blade
  if (typeof seedIfEmpty === 'function') {
    start();
    return;
  }

  // Kasus 2: Fallback loader jika app.js di-load secara mandiri
  const currentScript = document.currentScript;
  const basePath = currentScript
    ? currentScript.src.replace(/app\.js(\?.*)?$/, 'modules/')
    : '/js/modules/';

  let loadedCount = 0;
  MODULES.forEach(mod => {
    const s = document.createElement('script');
    s.src = basePath + mod;
    s.async = false; // Memastikan urutan dependensi terjaga
    s.onload = () => {
      loadedCount++;
      if (loadedCount === MODULES.length) start();
    };
    s.onerror = (e) => {
      console.error('[AdemAyem] Gagal memuat modul:', mod, e);
    };
    document.head.appendChild(s);
  });
})();