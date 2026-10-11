<?php

declare(strict_types=1);

namespace App\Domain\PanduanAwal\Kueri;

use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Katalog\Kueri\PemakaianSku;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Kueri\DaftarPinAnggota;
use App\Domain\Organisasi\Kueri\PemakaianBatasOrganisasi;
use App\Domain\Organisasi\Kueri\PemakaianPerangkat;
use App\Domain\Penjualan\Kueri\DaftarMetodePembayaran;
use App\Domain\Persediaan\Kueri\RingkasanStokAwal;

/**
 * Checklist "Langkah Berikutnya" di beranda back-office (F-01 langkah 7). Item hanya tampil bila anggota punya izin
 * membuka tautannya; status Selesai dihitung dari data (bukan dicentang manual). Semua selesai = daftar kosong
 * (bagian disembunyikan). "Isi stok awal" (F-05a) hanya tampil bila tenant punya produk berstok dan anggota boleh
 * mengelola persediaan; selesai begitu ada stok awal yang diposting.
 */
final class LangkahBerikutnya
{
    public function __construct(
        private readonly AksesPengguna $akses,
        private readonly ProgresPanduan $progres,
        private readonly PemakaianSku $pemakaianSku,
        private readonly DaftarMetodePembayaran $metodePembayaran,
        private readonly PemakaianPerangkat $pemakaianPerangkat,
        private readonly PemakaianBatasOrganisasi $pemakaianOrganisasi,
        private readonly DaftarPinAnggota $pinAnggota,
        private readonly InfoProdukStok $infoProdukStok,
        private readonly RingkasanStokAwal $ringkasanStokAwal,
    ) {}

    /**
     * @return list<array{Kunci: string, Judul: string, Keterangan: string, Tautan: string, Selesai: bool}>
     */
    public function Ambil(int $idTenant, int $idPengguna): array
    {
        $boleh = fn (IzinTenant $izin): bool => $this->akses->CekIzin($idTenant, $idPengguna, $izin);
        $item = [];

        if ($boleh(IzinTenant::PanduanAwalKelola)) {
            $item[] = [
                'Kunci' => 'PanduanAwal',
                'Judul' => 'Selesaikan panduan awal',
                'Keterangan' => 'Profil usaha, jenis usaha, pajak, produk, pembayaran, dan perangkat kasir.',
                'Tautan' => route('kelola.panduan-awal'),
                'Selesai' => $this->progres->AmbilBaris()?->SelesaiPada !== null,
            ];
            $item[] = [
                'Kunci' => 'TambahProduk',
                'Judul' => 'Tambah produk',
                'Keterangan' => 'Produk yang dijual muncul di aplikasi kasir.',
                'Tautan' => route('kelola.panduan-awal.produk'),
                'Selesai' => $this->pemakaianSku->Hitung() > 0,
            ];
            $item[] = [
                'Kunci' => 'AturMetodePembayaran',
                'Judul' => 'Atur metode pembayaran',
                'Keterangan' => 'Tambahkan QRIS, kartu (EDC), atau transfer selain tunai.',
                'Tautan' => route('kelola.panduan-awal.metode-pembayaran'),
                'Selesai' => $this->metodePembayaran->HitungAktifSelainTunai() > 0,
            ];
        }

        if ($boleh(IzinTenant::PersediaanKelola) && $this->infoProdukStok->HitungBerstok() > 0) {
            $item[] = [
                'Kunci' => 'StokAwal',
                'Judul' => 'Isi stok awal',
                'Keterangan' => 'Jumlah & harga modal barang yang sudah ada, supaya stok dan HPP benar.',
                'Tautan' => route('kelola.persediaan.stok-awal.daftar'),
                'Selesai' => $this->ringkasanStokAwal->CekAdaDiposting(),
            ];
        }

        if ($boleh(IzinTenant::PerangkatKelola)) {
            $item[] = [
                'Kunci' => 'AktifkanPerangkat',
                'Judul' => 'Aktifkan perangkat kasir',
                'Keterangan' => 'Pasang aplikasi kasir, lalu masukkan kode aktivasi.',
                'Tautan' => route('kelola.perangkat.daftar'),
                'Selesai' => $this->pemakaianPerangkat->HitungDiaktifkan() > 0,
            ];
        }

        if ($boleh(IzinTenant::PenggunaUndang)) {
            $item[] = [
                'Kunci' => 'UndangStaf',
                'Judul' => 'Undang staf',
                'Keterangan' => 'Kasir dan admin masuk dengan akun masing-masing.',
                'Tautan' => route('kelola.tim.daftar'),
                'Selesai' => $this->pemakaianOrganisasi->HitungPengguna($idTenant) > 1,
            ];
        }

        $item[] = [
            'Kunci' => 'AturPin',
            'Judul' => 'Atur PIN kasir Anda',
            'Keterangan' => 'PIN dipakai untuk masuk dan menyetujui di aplikasi kasir.',
            'Tautan' => route('kelola.keamanan.pin'),
            'Selesai' => $this->pinAnggota->CekPinDiatur($idTenant, $idPengguna),
        ];

        if (array_filter($item, fn (array $baris) => ! $baris['Selesai']) === []) {
            return [];
        }

        // Yang belum selesai lebih dulu, urutan asli dipertahankan.
        usort($item, fn (array $a, array $b): int => (int) $a['Selesai'] <=> (int) $b['Selesai']);

        return $item;
    }
}
