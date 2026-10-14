<?php

declare(strict_types=1);

namespace App\Domain\Kasir\Aksi;

use App\Domain\Akuntansi\Aksi\SimpanTransaksiKasBank;
use App\Domain\Akuntansi\Data\DataTransaksiKasBank;
use App\Domain\Akuntansi\Enum\JenisTransaksiKasBank;
use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Akuntansi\Model\TransaksiKasBank;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Kasir\Enum\JenisKategoriKas;
use App\Domain\Kasir\Model\KategoriKas;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

/**
 * Pengeluaran cepat dari Aplikasi Owner (OWN-07): satu transaksi kas & bank jenis Pengeluaran (`KB/…`, jurnal Dr akun
 * beban kategori, Cr kas/bank sumber) dengan foto nota sebagai lampiran. Memakai `SimpanTransaksiKasBank`, jadi aturan
 * akun, periode terkunci, penomoran, dan audit sama dengan back-office. Pengeluaran lewat sini tidak terkait shift
 * kasir mana pun (beda dengan kas keluar dari laci di aplikasi Kasir).
 */
final class CatatPengeluaranPemilik
{
    public function __construct(
        private readonly SimpanTransaksiKasBank $simpan,
        private readonly DaftarAkunPilihan $akun,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis KategoriTidakDikenal, SumberTidakDikenal, serta galat `SimpanTransaksiKasBank`
     */
    public function Jalankan(string $uuidKategori, string $uuidAkunSumber, Uang $jumlah, string $keterangan, CarbonImmutable $tanggal, ?int $idOutlet, ?UploadedFile $foto, int $idPengguna): TransaksiKasBank
    {
        $kategori = KategoriKas::query()->where('Uuid', $uuidKategori)->where('Jenis', JenisKategoriKas::Keluar->value)->where('Aktif', true)->first()
            ?? throw new PelanggaranAturanBisnis('KategoriTidakDikenal', 'Kategori pengeluaran tidak ditemukan atau sudah dinonaktifkan.', 'UuidKategori');
        $sumber = $this->akun->CariKasBankDariUuid($uuidAkunSumber)
            ?? throw new PelanggaranAturanBisnis('SumberTidakDikenal', 'Akun kas atau bank sumber tidak ditemukan.', 'UuidAkunSumber');
        $tujuan = $this->akun->AmbilBanyak([$kategori->IdAkun])[$kategori->IdAkun]
            ?? throw new PelanggaranAturanBisnis('KategoriTanpaAkun', 'Kategori ini belum punya akun beban. Atur di dashboard dulu.', 'UuidKategori');

        return $this->simpan->Jalankan(new DataTransaksiKasBank(
            jenis: JenisTransaksiKasBank::Pengeluaran,
            tanggal: $tanggal,
            idOutlet: $idOutlet,
            uuidAkunSumber: $sumber['Uuid'],
            uuidAkunTujuan: $tujuan['Uuid'],
            jumlah: $jumlah,
            keterangan: $kategori->Nama.(trim($keterangan) === '' ? '' : ': '.trim($keterangan)),
            lampiran: $foto,
            idPengguna: $idPengguna,
        ));
    }
}
