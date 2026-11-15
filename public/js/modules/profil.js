/**
 * Adem Ayem - Profil Module
 * Modal profil pengguna, perubahan nama/username/password, dan visibilitas password.
 */

// Data user aktif: pakai currentUser() kalau ada, kalau tidak pakai SERVER_USER dari layout
function profilUser() {
  if (typeof currentUser === 'function') {
    const u = currentUser();
    if (u) return u;
  }
  return typeof SERVER_USER !== 'undefined' ? SERVER_USER : null;
}

function editProfile(on) {
  const modal = document.getElementById('profileModal');
  if (!modal) return;

  if (on) {
    const u = profilUser();
    if (u) {
      const nameInput = document.getElementById('myName');
      if (nameInput) nameInput.value = u.name;
      const userInput = document.getElementById('myUser');
      if (userInput) userInput.value = u.username;
    }
    ['myOldPass', 'myPass', 'myPassConfirm'].forEach(id => {
      const i = document.getElementById(id);
      if (i) { i.value = ''; i.type = 'password'; }
    });
    document.querySelectorAll('#profileModal .eye-btn').forEach(b => b.classList.remove('shown'));
    const modalMsg = document.getElementById('modalMsg');
    if (modalMsg) modalMsg.textContent = '';
    const profileMsg = document.getElementById('profileMsg');
    if (profileMsg) profileMsg.textContent = '';
  }
  modal.style.display = on ? 'flex' : 'none';
}

function renderProfil() {
  const u = profilUser();
  if (!u) return;

  const heroName = document.getElementById('heroName');
  if (heroName) heroName.textContent = u.name;

  editProfile(false);
}

async function saveProfile() {
  const u = profilUser();
  if (!u) return;

  const msg = document.getElementById('modalMsg');
  const setMsg = text => { if (msg) msg.textContent = text; };
  const saveBtn = document.querySelector('#profileModal .btn-primary');

  const name = document.getElementById('myName')?.value.trim() || '';
  const username = document.getElementById('myUser')?.value.trim() || '';
  const password = document.getElementById('myPass')?.value || '';
  const current_password = document.getElementById('myOldPass')?.value || '';
  const password_confirmation = document.getElementById('myPassConfirm')?.value || '';

  // Validasi sisi klien (server tetap memvalidasi ulang)
  if (!name || !username) return setMsg('Nama dan username wajib diisi.');
  if (name.length > 45) return setMsg('Nama maksimal 45 karakter.');
  if (username.length > 30) return setMsg('Username maksimal 30 karakter.');
  if (password && password.length < 6) return setMsg('Password baru minimal 6 karakter.');
  if (password && !current_password) return setMsg('Isi password lama untuk mengganti password.');
  if (password !== password_confirmation) return setMsg('Konfirmasi password tidak sama.');

  setMsg('');
  if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Menyimpan...'; }

  try {
    const res = await fetch(ROUTE_URLS.profil, {
      method: 'PUT',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': CSRF_TOKEN
      },
      body: JSON.stringify({ name, username, password, current_password, password_confirmation })
    });

    // Balasan bisa bukan JSON (mis. halaman error), jadi jangan langsung dipercaya
    let data = null;
    try { data = await res.json(); } catch (_) { /* biarkan null */ }

    if (res.status === 419 || res.status === 401) {
      return setMsg('Sesi habis. Muat ulang halaman lalu login kembali.');
    }

    if (!res.ok || !data) {
      const first = data && data.errors ? Object.values(data.errors)[0][0] : null;
      return setMsg(first || (data && data.message) || 'Gagal menyimpan (kode ' + res.status + ').');
    }

    // Perbarui data di memori (kedua sumber, supaya konsisten)
    [u, typeof SERVER_USER !== 'undefined' ? SERVER_USER : null].forEach(target => {
      if (target) { target.name = data.name; target.username = data.username; }
    });

    // Chip di navbar: ganti isi tebalnya saja, jangan timpa teks "Masuk sebagai"
    const userChip = document.getElementById('userChip');
    if (userChip) {
      const target = userChip.querySelector('b') || userChip;
      target.textContent = userChipText(u);
    }

    const heroName = document.getElementById('heroName');
    if (heroName) heroName.textContent = u.name;

    editProfile(false);

    const profileMsg = document.getElementById('profileMsg');
    if (profileMsg) profileMsg.textContent = 'Profil berhasil diperbarui.';
  } catch (e) {
    setMsg('Terjadi kesalahan jaringan.');
  } finally {
    if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Simpan'; }
  }
}

function togglePw(id, btn) {
  const input = document.getElementById(id);
  if (!input) return;
  const show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  if (btn) btn.classList.toggle('shown', show);
}