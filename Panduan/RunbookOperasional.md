# Runbook operasional Payoung

Dokumen kerangka untuk Teknis. Salin ke wiki/dokumen internal Anda, isi langkah yang sesuai server Anda, lalu atur
alamatnya di konsol: **Operasional › Runbook alert** (`https://…`). Setiap alert di dasbor menautkan
`{alamat}#{jangkar}`; jangkar di bawah ini sama persis dengan yang dipakai dasbor, jadi judul bagian cukup memakai
tulisan jangkar (mis. `## penjadwal-berhenti`) atau menambahkan `<a id="penjadwal-berhenti"></a>`.

Prinsip: **diagnosis dulu, tindakan sesudahnya, catat di insiden bila pengguna terdampak** (Operasional › Insiden).
Jangan menjalankan `migrate:fresh`, `db:wipe`, atau mencabut perangkat sebelum outbox-nya terkirim.

## penjadwal-berhenti

Gejala: detak scheduler lebih dari 3 menit tidak tercatat (Kritis).

1. Periksa cron server: `* * * * * php artisan schedule:run` masih terpasang dan berjalan sebagai pengguna aplikasi.
2. Jalankan `php artisan pengelola:detak` manual. Bila sukses, masalahnya di cron/hosting; bila gagal, baca `storage/logs`.
3. Pastikan disk tidak penuh dan database dapat dihubungi.
4. Setelah pulih, alert menutup sendiri pada pemeriksaan berikutnya.

## antrean-tertunda

Gejala: job tertua melewati 5 menit (Kritis).

1. Lihat **Job gagal** di dasbor: ada galat yang berulang?
2. Pastikan worker berjalan (`queue:work --stop-when-empty` dari scheduler) dan tidak terkunci proses lama.
3. Coba ulang job gagal yang aman diulang; buang yang sudah tidak relevan (wajib alasan, tercatat di audit).

## backup-terlambat

Gejala: backup berhasil terakhir lebih dari 26 jam (Peringatan).

1. Periksa skrip backup dan ruang disk tujuan.
2. Jalankan backup manual lalu catat hasilnya di dasbor (**Backup & uji restore**).
3. Lakukan uji restore bulanan dan catat hasilnya.

## error-rate-tinggi

Gejala: galat server (5xx) di atas ambang dalam 5 menit (Kritis).

1. Buka **Rute API paling lambat** dan **Job gagal**; cari rute/galat yang dominan.
2. Baca `storage/logs` pada menit kejadian; cocokkan dengan rilis terakhir (`rilis:pasang`).
3. Bila setelah rilis: pertimbangkan **kill switch** fitur (Rilis & flag) sebelum mengembalikan versi.
4. Buka insiden dan umumkan bila pengguna terdampak.

## latensi-pos-tinggi

Gejala: p95 API POS lebih dari 400 ms (Peringatan).

1. Lihat **Rute API paling lambat**: apakah satu rute mendominasi (mis. `pos.sinkron.kirim`)?
2. Periksa kueri lambat database dan deadlock (`tests/Konkurensi` menjelaskan pola yang sudah diketahui).
3. Periksa beban server (CPU, memori, koneksi database) dan antrean.

## outbox-macet

Gejala: perangkat dengan outbox tidak kosong lebih dari 2 jam (Peringatan).

1. Buka daftar **Perangkat dengan outbox macet** (tercatat di audit).
2. Hubungi tenant: pastikan kasir tersambung internet dan aplikasi versi terbaru.
3. Gunakan alat bantu dukungan (status perangkat & outbox) lewat akses dukungan berizin. **Jangan mencabut perangkat**
   sebelum outbox terkirim.
4. Lihat **Penolakan sinkron terbanyak**: bila satu kode galat dominan, item itu ditolak server dan perlu keputusan tenant.
