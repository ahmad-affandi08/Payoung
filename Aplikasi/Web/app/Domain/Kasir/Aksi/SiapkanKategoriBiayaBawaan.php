<?php

declare(strict_types=1);

namespace App\Domain\Kasir\Aksi;

use App\Domain\Akuntansi\Aksi\TambahAkun;
use App\Domain\Akuntansi\Data\DataAkun;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Enum\TipeAkun;
use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Akuntansi\Layanan\PenyediaAkunPeran;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Kasir\Data\DataKategoriKas;
use App\Domain\Kasir\Enum\JenisKategoriKas;
use App\Domain\Kasir\Model\KategoriKas;
use App\Domain\Tenant\Layanan\PenguncianTenant;
use Illuminate\Support\Facades\DB;

/**
 * D-106: pengguna baru tidak mulai dari nol untuk biaya harian. Menyiapkan kategori kas keluar umum (Listrik, Air,
 * Internet & pulsa, Sewa, Transport & bensin, Parkir, Kebersihan, Perlengkapan, Perbaikan, Konsumsi) beserta akun
 * beban per jenis biaya, supaya laporan laba rugi merinci biaya, bukan satu angka.
 *
 * Satu kategori lagi bukan biaya: "Belanja stok dari uang laci" (D-256), kategori kas keluar yang uangnya masuk ke akun
 * penampung `KliringBelanjaLaci` (bukan beban), supaya kasir tetap mencatat uang laci yang dipakai belanja stok di POS
 * dan hitungan laci benar, sementara barangnya dicatat kemudian lewat Belanja stok di back-office.
 *
 * Aditif & idempoten: kategori yang namanya sudah ada tidak disentuh; akun beban dengan kode yang sama dipakai ulang;
 * kode yang bentrok dengan akun lain (nonaktif/tipe lain) membuat kategori itu dilewati, bukan menimpa. Semua lewat
 * Aksi publik domain Akuntansi dan `SimpanKategoriKas` (audit tercatat). Dipanggil dari penerapan template sektor,
 * tombol di halaman Kategori kas, dan perintah `kasir:siapkan-kategori-biaya` untuk tenant lama.
 */
final class SiapkanKategoriBiayaBawaan
{
    /**
     * Nama kategori => [kode akun beban, nama akun beban]. Urutan array = urutan tampil di kasir.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const KATEGORI = [
        'Listrik' => ['6-2100', 'Beban Listrik & Air'],
        'Air' => ['6-2100', 'Beban Listrik & Air'],
        'Internet & pulsa' => ['6-2200', 'Beban Internet & Telepon'],
        'Sewa tempat' => ['6-2300', 'Beban Sewa'],
        'Transport & bensin' => ['6-2400', 'Beban Transportasi'],
        'Parkir' => ['6-2400', 'Beban Transportasi'],
        'Kebersihan & keamanan' => ['6-2500', 'Beban Kebersihan & Keamanan'],
        'Perlengkapan toko' => ['6-2600', 'Beban Perlengkapan'],
        'Perbaikan & servis' => ['6-2700', 'Beban Perawatan & Perbaikan'],
        'Konsumsi' => ['6-2800', 'Beban Konsumsi'],
    ];

    /** D-256: kategori kas keluar untuk belanja stok memakai uang laci. Urutan 5 supaya tampil paling depan di kasir. */
    public const KATEGORI_BELANJA_LACI = 'Belanja stok dari uang laci';

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PenyediaAkunPeran $penyediaAkun,
        private readonly PenguncianTenant $penguncian,
        private readonly DaftarAkunPilihan $akun,
        private readonly TambahAkun $tambahAkun,
        private readonly SimpanKategoriKas $simpanKategori,
    ) {}

    /**
     * Untuk tenant aktif (konteks sudah diatur). Mengembalikan jumlah kategori yang ditambahkan.
     */
    public function Jalankan(): int
    {
        $idTenant = $this->konteks->Wajib();

        return DB::transaction(function () use ($idTenant): int {
            $this->penguncian->Kunci($idTenant);
            $ada = KategoriKas::query()->where('Jenis', JenisKategoriKas::Keluar->value)->pluck('Nama')->map(fn (string $n): string => mb_strtolower($n))->all();
            $uuidAkun = [];

            foreach ($this->akun->Ambil([TipeAkun::Beban]) as $akun) {
                $uuidAkun[$akun['Kode']] = $akun['Uuid'];
            }

            $baru = 0;
            $urutan = 0;

            foreach (self::KATEGORI as $nama => [$kode, $namaAkun]) {
                $urutan += 10;

                if (in_array(mb_strtolower($nama), $ada, true)) {
                    continue;
                }

                $uuid = $uuidAkun[$kode] ?? $this->BuatAkun($kode, $namaAkun);

                if ($uuid === null) {
                    continue;
                }

                $uuidAkun[$kode] = $uuid;
                $this->simpanKategori->Jalankan(new DataKategoriKas($nama, JenisKategoriKas::Keluar, $uuid, $urutan));
                $baru++;
            }

            return $baru + $this->SiapkanKategoriBelanjaLaci($ada);
        });
    }

    /**
     * @param  array<int, string>  $ada  nama kategori keluar yang sudah ada (huruf kecil)
     */
    private function SiapkanKategoriBelanjaLaci(array $ada): int
    {
        if (in_array(mb_strtolower(self::KATEGORI_BELANJA_LACI), $ada, true)) {
            return 0;
        }

        $idAkun = $this->penyediaAkun->Pastikan(PeranAkun::KliringBelanjaLaci, '1-1490', 'Uang Laci untuk Belanja Stok');
        $uuid = $this->akun->AmbilBanyak([$idAkun])[$idAkun]['Uuid'] ?? null;

        if ($uuid === null) {
            return 0;
        }

        $this->simpanKategori->Jalankan(new DataKategoriKas(self::KATEGORI_BELANJA_LACI, JenisKategoriKas::Keluar, $uuid, 5));

        return 1;
    }

    /**
     * Untuk tenant tertentu (perintah artisan): konteks diatur sementara lalu dikembalikan.
     */
    public function JalankanUntukTenant(int $idTenant): int
    {
        $sebelumnya = $this->konteks->Ambil();
        $this->konteks->Atur($idTenant);

        try {
            return $this->Jalankan();
        } finally {
            $sebelumnya === null ? $this->konteks->Kosongkan() : $this->konteks->Atur($sebelumnya);
        }
    }

    /** Uuid akun baru, atau null bila kodenya bentrok dengan akun lain yang tidak bisa dipakai. */
    private function BuatAkun(string $kode, string $nama): ?string
    {
        try {
            return $this->tambahAkun->Jalankan(new DataAkun($kode, $nama, TipeAkun::Beban, kontra: false, kasBank: false))->Uuid;
        } catch (PelanggaranAturanBisnis $galat) {
            if ($galat->kode === 'KodeAkunSudahAda') {
                return null;
            }

            throw $galat;
        }
    }
}
