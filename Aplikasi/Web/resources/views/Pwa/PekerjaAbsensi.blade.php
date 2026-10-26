@verbatim
// F-18 bagian 4 (D-37): service worker PWA absensi, cakupan hanya halaman absen karyawan ini.
// - Model wajah (/model-wajah/*) disimpan di cache: ±10 MB hanya diunduh sekali.
// - Halaman absen network-first; tanpa koneksi tampil pesan offline (absen wajib online karena server yang menilai
//   lokasi & wajah). Kiriman absen (POST) tidak pernah disimpan atau diulang di sini.
const CACHE_MODEL = 'payoung-model-wajah-v1';

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (peristiwa) => {
    peristiwa.waitUntil(
        caches
            .keys()
            .then((kunci) => Promise.all(kunci.filter((k) => k.startsWith('payoung-model-wajah-') && k !== CACHE_MODEL).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (peristiwa) => {
    const permintaan = peristiwa.request;

    if (permintaan.method !== 'GET') {
        return;
    }

    const alamat = new URL(permintaan.url);

    if (alamat.origin === self.location.origin && alamat.pathname.startsWith('/model-wajah/')) {
        peristiwa.respondWith(
            caches.open(CACHE_MODEL).then((cache) =>
                cache.match(permintaan).then(
                    (tersimpan) =>
                        tersimpan ||
                        fetch(permintaan).then((jawaban) => {
                            if (jawaban.ok) {
                                cache.put(permintaan, jawaban.clone());
                            }

                            return jawaban;
                        }),
                ),
            ),
        );

        return;
    }

    if (permintaan.mode === 'navigate') {
        peristiwa.respondWith(
            fetch(permintaan).catch(
                () =>
                    new Response(
                        '<!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tidak ada koneksi</title><body style="font-family:sans-serif;padding:24px;line-height:1.5"><h1 style="font-size:20px">Tidak ada koneksi internet</h1><p>Absen butuh internet karena lokasi dan wajah diperiksa server. Nyalakan data seluler atau Wi-Fi, lalu buka lagi.</p></body></html>',
                        { headers: { 'Content-Type': 'text/html; charset=utf-8' } },
                    ),
            ),
        );
    }
});
@endverbatim
