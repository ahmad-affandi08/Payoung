import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import TataLetakSitus from '@/TataLetak/TataLetakSitus';
import type { PropsHalamanPengembang } from '@/Tipe/Situs';

const contohVerifikasiPhp = `$badan = file_get_contents('php://input');
$waktu = $_SERVER['HTTP_X_WAKTU_KIRIM'] ?? '';
$tanda = $_SERVER['HTTP_X_TANDA_TANGAN'] ?? '';
$hitung = 'sha256=' . hash_hmac('sha256', $waktu . '.' . $badan, getenv('RAHASIA_WEBHOOK_Payoung'));

if (! hash_equals($hitung, $tanda) || abs(time() - (int) $waktu) > 300) {
    http_response_code(401);
    exit;
}
// Simpan X-Id-Peristiwa. Abaikan bila sudah pernah diproses.`;

const contohVerifikasiNode = `import crypto from 'node:crypto';

function CekTandaTangan(badanMentah, header, rahasia) {
    const hitung = 'sha256=' + crypto
        .createHmac('sha256', rahasia)
        .update(header['x-waktu-kirim'] + '.' + badanMentah)
        .digest('hex');
    const a = Buffer.from(hitung);
    const b = Buffer.from(header['x-tanda-tangan'] ?? '');
    return a.length === b.length && crypto.timingSafeEqual(a, b);
}`;

function Bagian({ id, judul, children }: { id: string; judul: string; children: ReactNode }) {
    return (
        <section id={id} aria-labelledby={`${id}-judul`} className="flex flex-col gap-3">
            <h2 id={`${id}-judul`} className="text-judul font-semibold text-teks-utama">
                {judul}
            </h2>
            {children}
        </section>
    );
}

function BlokKode({ label, kode }: { label: string; kode: string }) {
    return (
        <figure className="flex min-w-0 flex-col gap-1">
            <figcaption className="text-label font-semibold text-teks-sekunder">{label}</figcaption>
            <pre className="overflow-x-auto rounded-kontrol border border-garis bg-permukaan-sorot p-3 font-mono text-label text-teks-utama">
                <code>{kode}</code>
            </pre>
        </figure>
    );
}

/**
 * X7 bagian 3: portal dokumentasi pengembang. Isi endpoint & webhook berasal dari spesifikasi OpenAPI yang juga bisa
 * diunduh, jadi halaman ini tidak perlu disunting tiap kali API bertambah.
 */
export default function Pengembang() {
    const { AlamatApi, Versi, Endpoint, Webhook, UnduhSpesifikasi } = usePage<PropsHalamanPengembang>().props;

    return (
        <TataLetakSitus judul="Dokumentasi API untuk pengembang">
            <main className="mx-auto flex max-w-4xl flex-col gap-10 px-4 py-10 sm:py-14">
                <header className="flex flex-col gap-3">
                    <JudulHalaman skala="situs">API Payoung untuk pengembang</JudulHalaman>
                    <p className="max-w-2xl text-pengantar text-teks-sekunder">
                        Sambungkan aplikasi akuntansi, marketplace, atau dasbor BI ke data usaha di Payoung. Versi{' '}
                        {Versi}, baca saja, ditambah webhook untuk peristiwa penjualan.
                    </p>
                    <p className="text-isi text-teks-utama">
                        Spesifikasi lengkap (OpenAPI 3.1):{' '}
                        <a className="font-semibold text-brand underline" href={UnduhSpesifikasi}>
                            openapi-v1.json
                        </a>
                    </p>
                </header>

                <Bagian id="mulai" judul="Mulai">
                    <ol className="flex list-decimal flex-col gap-2 pl-5 text-isi text-teks-utama">
                        <li>
                            Pemilik usaha membuat token di <strong>Pengaturan › Token API</strong> (paket Bisnis atau
                            Enterprise) dan memilih akses yang diberikan.
                        </li>
                        <li>
                            Kirim token di header <span className="font-mono">Authorization: Bearer &lt;token&gt;</span>{' '}
                            ke <span className="font-mono break-all">{AlamatApi}</span>.
                        </li>
                        <li>
                            Ambil halaman berikutnya dengan <span className="font-mono">?kursor=</span> berisi{' '}
                            <span className="font-mono">Kursor.Berikutnya</span> sampai nilainya{' '}
                            <span className="font-mono">null</span>. Ukuran halaman{' '}
                            <span className="font-mono">?per=</span> 1–200 (bawaan 50).
                        </li>
                    </ol>
                    <BlokKode
                        label="Contoh permintaan"
                        kode={`curl -H "Authorization: Bearer payoung_123_xxxxxxxx" \\\n  "${AlamatApi}/penjualan?dari=2026-10-01&sampai=2026-10-31&per=100"`}
                    />
                </Bagian>

                <Bagian id="aturan" judul="Aturan data">
                    <ul className="flex list-disc flex-col gap-2 pl-5 text-isi text-teks-utama">
                        <li>
                            Nama field PascalCase Bahasa Indonesia, sama dengan nama kolom (misal{' '}
                            <span className="font-mono">TotalAkhir</span>).
                        </li>
                        <li>
                            Uang dan jumlah dikirim sebagai string desimal (
                            <span className="font-mono">"15000.00"</span>) agar tidak kehilangan presisi. Waktu ISO-8601
                            UTC.
                        </li>
                        <li>
                            Pengenal selalu <span className="font-mono">Uuid</span>. HPP, nilai persediaan, NPWP/NIK,
                            alamat, dan limit kredit pelanggan tidak pernah dikirim (UU Pelindungan Data Pribadi).
                        </li>
                        <li>Batas 120 permintaan per menit per token. Lewat batas dijawab 429 dengan Retry-After.</li>
                        <li>
                            Masalah berbentuk{' '}
                            <span className="font-mono">{'{"Galat": {"Kode": "...", "Pesan": "..."}}'}</span>: 401 token
                            tidak valid, 403 fitur/akses kurang, 404 tidak ditemukan, 422 parameter salah.
                        </li>
                    </ul>
                </Bagian>

                <Bagian id="endpoint" judul="Endpoint">
                    <ul className="flex flex-col divide-y divide-garis rounded-panel border border-garis">
                        {Endpoint.map((e) => (
                            <li key={`${e.Metode} ${e.Jalur}`} className="flex flex-col gap-1 p-4">
                                <p className="flex flex-wrap items-baseline gap-2">
                                    <span className="font-mono text-label font-semibold text-brand">{e.Metode}</span>
                                    <span className="font-mono text-isi break-all text-teks-utama">{e.Jalur}</span>
                                </p>
                                <p className="text-isi text-teks-sekunder">{e.Ringkasan}</p>
                                <p className="text-keterangan text-teks-sekunder">
                                    Akses: <span className="font-mono">{e.Cakupan}</span>
                                    {e.Parameter.length > 0 ? (
                                        <>
                                            {' | '}Parameter:{' '}
                                            <span className="font-mono">
                                                {e.Parameter.map((p) => (p.Wajib ? `${p.Nama} (wajib)` : p.Nama)).join(
                                                    ', ',
                                                )}
                                            </span>
                                        </>
                                    ) : null}
                                </p>
                            </li>
                        ))}
                    </ul>
                </Bagian>

                <Bagian id="webhook" judul="Webhook">
                    <p className="text-isi text-teks-utama">
                        Daftarkan alamat HTTPS publik di <strong>Pengaturan › Webhook</strong>. Payoung mengirim POST
                        JSON <span className="font-mono">{'{IdPeristiwa, Peristiwa, TerjadiPada, Data}'}</span>. Balas
                        2xx dalam 10 detik. Gagal dicoba lagi 1 menit, 5 menit, 30 menit, 2 jam, lalu 12 jam kemudian.
                        Pakai <span className="font-mono">X-Id-Peristiwa</span> untuk mengabaikan kiriman ganda.
                    </p>
                    <ul className="flex flex-col divide-y divide-garis rounded-panel border border-garis">
                        {Webhook.map((w) => (
                            <li key={w.Peristiwa} className="flex flex-col gap-1 p-4">
                                <span className="font-mono text-isi font-semibold text-teks-utama">{w.Peristiwa}</span>
                                <span className="text-isi text-teks-sekunder">{w.Ringkasan}</span>
                            </li>
                        ))}
                    </ul>
                    <p className="text-isi text-teks-utama">
                        Periksa tanda tangan sebelum memproses: HMAC-SHA256 memakai rahasia webhook atas{' '}
                        <span className="font-mono">X-Waktu-Kirim</span>, titik, lalu badan mentah, dibandingkan dengan{' '}
                        <span className="font-mono">X-Tanda-Tangan</span>. Tolak kiriman yang waktunya lebih dari 5
                        menit.
                    </p>
                    <BlokKode label="PHP" kode={contohVerifikasiPhp} />
                    <BlokKode label="Node.js" kode={contohVerifikasiNode} />
                </Bagian>

                <Bagian id="versi" judul="Versi & perubahan">
                    <p className="text-isi text-teks-utama">
                        Field baru bisa bertambah kapan saja. Abaikan field yang tidak dikenal. Perubahan yang tidak
                        kompatibel terbit sebagai versi baru di alamat baru, dan versi lama tetap didukung paling
                        sedikit 6 bulan setelah diumumkan.
                    </p>
                </Bagian>
            </main>
        </TataLetakSitus>
    );
}
