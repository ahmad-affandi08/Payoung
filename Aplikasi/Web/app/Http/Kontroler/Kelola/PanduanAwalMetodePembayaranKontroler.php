<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola;

use App\Domain\Integrasi\Layanan\InfoGerbangPembayaran;
use App\Domain\Penjualan\Aksi\AturTeksQrisMetode;
use App\Domain\Penjualan\Aksi\SimpanMetodePembayaran;
use App\Domain\Penjualan\Aksi\UbahBatasHariMenungguMetode;
use App\Domain\Penjualan\Aksi\UbahStatusMetodePembayaran;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Enum\KanalPenjualan;
use App\Domain\Penjualan\Kueri\DaftarMetodePembayaran;
use App\Domain\Penjualan\Kueri\PembayaranBelumDicairkan;
use App\Domain\Penjualan\Layanan\PenyimpanGambarQris;
use App\Domain\Penjualan\Model\MetodePembayaran;
use App\Domain\Referensi\Kueri\ReferensiBankAktif;
use App\Http\Permintaan\Kelola\PanduanAwal\SimpanMetodePembayaranPermintaan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * F-01 langkah 5: metode pembayaran (Tunai selalu ada; tambah QRIS statis, QRIS dinamis F-08, EDC, transfer;
 * aktif/nonaktif). Metode dicari lewat Uuid di scope tenant aktif, jadi milik tenant lain = 404. Gambar QRIS hanya
 * lewat rute unduh ini.
 */
final class PanduanAwalMetodePembayaranKontroler extends DasarPanduanAwalKontroler
{
    public function Tampilkan(DaftarMetodePembayaran $daftar, ReferensiBankAktif $referensiBank, InfoGerbangPembayaran $gerbang): Response
    {
        $this->OutletPanduan();

        return Inertia::render('Kelola/PanduanAwal/MetodePembayaran', [
            'Progres' => $this->Progres(),
            'MetodePembayaran' => array_map(function (array $metode): array {
                $adaGambar = $metode['AdaGambarQris'];
                unset($metode['AdaGambarQris']);

                return [
                    ...$metode,
                    'TautanGambarQris' => $adaGambar ? route('kelola.panduan-awal.metode-pembayaran.gambar-qris', ['metodePembayaran' => $metode['Uuid']]) : null,
                ];
            }, $daftar->Ambil()),
            'JenisTersedia' => array_map(
                fn (JenisMetodePembayaran $jenis): array => ['Nilai' => $jenis->value, 'Label' => $jenis->AmbilLabel()],
                JenisMetodePembayaran::AmbilJenisPanduan(),
            ),
            // X8: platform untuk metode Marketplace + batas komisinya.
            'KanalPlatform' => array_map(fn (KanalPenjualan $kanal): array => ['Nilai' => $kanal->value, 'Label' => $kanal->AmbilLabel()], KanalPenjualan::AmbilPlatform()),
            'PersenBiayaMaksimal' => ['Umum' => (string) config('pembayaran.PersenBiayaMaksimal'), 'Platform' => (string) config('pembayaran.PersenBiayaPlatformMaksimal')],
            'Bank' => array_map(fn (array $bank): array => ['Kode' => $bank['Kode'], 'Nama' => $bank['Nama'], 'Jenis' => $bank['Jenis']], $referensiBank->Ambil()),
            'BatasGambarQris' => [
                'UkuranMaksimalKb' => (int) config('pembayaran.UkuranMaksimalGambarQrisKb'),
                'Ekstensi' => array_values((array) config('pembayaran.EkstensiGambarQris')),
            ],
            // F-08: QRIS dinamis butuh gerbang pembayaran aktif dari konsol platform (tanpa kredensial).
            'GerbangPembayaran' => $gerbang->Ambil(),
        ]);
    }

    public function Simpan(SimpanMetodePembayaranPermintaan $permintaan, SimpanMetodePembayaran $simpan): RedirectResponse
    {
        $metode = $simpan->Jalankan($permintaan->AmbilData(), $permintaan->AmbilGambarQris());

        return redirect()->route('kelola.panduan-awal.metode-pembayaran')->with('Kilat', "Metode pembayaran {$metode->Nama} ditambahkan.");
    }

    public function Nonaktifkan(string $metodePembayaran, DaftarMetodePembayaran $daftar, UbahStatusMetodePembayaran $ubah): RedirectResponse
    {
        $metode = $ubah->Jalankan($this->CariMetode($daftar, $metodePembayaran), false);

        return back()->with('Kilat', "{$metode->Nama} dinonaktifkan dan tidak tampil di kasir.");
    }

    public function Aktifkan(string $metodePembayaran, DaftarMetodePembayaran $daftar, UbahStatusMetodePembayaran $ubah): RedirectResponse
    {
        $metode = $ubah->Jalankan($this->CariMetode($daftar, $metodePembayaran), true);

        return back()->with('Kilat', "{$metode->Nama} aktif kembali.");
    }

    /** F-08: batas hari wajar menunggu pencairan per metode; kosong = bawaan jenis. */
    public function UbahBatasHariMenunggu(Request $permintaan, string $metodePembayaran, DaftarMetodePembayaran $daftar, UbahBatasHariMenungguMetode $ubah): RedirectResponse
    {
        $valid = $permintaan->validate(
            ['BatasHariMenunggu' => ['nullable', 'integer', 'min:1', 'max:'.PembayaranBelumDicairkan::BATAS_HARI_MAKSIMAL]],
            ['BatasHariMenunggu.*' => 'Batas hari menunggu berupa bilangan bulat 1 sampai '.PembayaranBelumDicairkan::BATAS_HARI_MAKSIMAL.', atau kosongkan untuk memakai bawaan.'],
        );
        $metode = $ubah->Jalankan($this->CariMetode($daftar, $metodePembayaran), isset($valid['BatasHariMenunggu']) ? (int) $valid['BatasHariMenunggu'] : null);

        return back()->with('Kilat', "Batas hari menunggu pencairan {$metode->Nama} disimpan.");
    }

    /** D-189: aktifkan (teks hasil baca gambar) atau matikan (kosong) nominal otomatis metode QRIS statis. */
    public function AturTeksQris(Request $permintaan, string $metodePembayaran, DaftarMetodePembayaran $daftar, AturTeksQrisMetode $atur): RedirectResponse
    {
        $valid = $permintaan->validate(['TeksQris' => ['nullable', 'string', 'max:600']]);
        $metode = $atur->Jalankan($this->CariMetode($daftar, $metodePembayaran), $valid['TeksQris'] ?? null);

        return back()->with('Kilat', $metode->TeksQris === null ? "Nominal otomatis {$metode->Nama} dimatikan. Kasir menampilkan gambar QRIS." : "Nominal otomatis {$metode->Nama} aktif. Kasir menampilkan QRIS dengan nominal terisi.");
    }

    public function UnduhGambarQris(string $metodePembayaran, DaftarMetodePembayaran $daftar, PenyimpanGambarQris $penyimpan): StreamedResponse
    {
        $metode = $this->CariMetode($daftar, $metodePembayaran);
        abort_if($metode->PathGambarQris === null, 404);

        return $penyimpan->Unduh($metode->PathGambarQris);
    }

    private function CariMetode(DaftarMetodePembayaran $daftar, string $uuid): MetodePembayaran
    {
        return $daftar->Cari($uuid) ?? abort(404);
    }
}
