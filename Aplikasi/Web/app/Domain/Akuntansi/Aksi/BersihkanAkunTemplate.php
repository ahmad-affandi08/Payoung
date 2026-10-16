<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Aksi;

use App\Domain\Akuntansi\Layanan\PenilaiPemakaianAkun;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Bersama\Data\HasilPembersihanTemplate;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;

/**
 * Ganti jenis usaha: akun sisa template sektor lama dibuang bila belum dipakai sama sekali (`HapusAkun`: tanpa jurnal,
 * pemetaan, akun anak, transaksi kas & bank, kategori kas, atau metode pembayaran). Hanya akun bertanda `Sistem` (asal
 * template) yang disentuh; akun buatan sendiri dan yang sudah dipakai dipertahankan. Dua putaran supaya induk yang
 * anaknya ikut dibuang bisa terbuang juga.
 */
final class BersihkanAkunTemplate
{
    public function __construct(
        private readonly PenilaiPemakaianAkun $pemakaian,
        private readonly HapusAkun $hapus,
    ) {}

    /**
     * @param  list<string>  $kode  kode akun sisa template lama yang tidak ada di template baru
     * @param  bool  $terapkan  false = hanya periksa (pratinjau; induk yang anaknya ikut terbuang tampil "punya akun anak")
     */
    public function Jalankan(array $kode, bool $terapkan): HasilPembersihanTemplate
    {
        if ($kode === []) {
            return new HasilPembersihanTemplate;
        }

        $sisa = Akun::query()->whereIn('Kode', $kode)->where('Sistem', true)->orderByDesc('Kode')->get()->all();
        $dihapus = [];
        $alasanTersisa = [];

        do {
            $ada = false;
            $berikutnya = [];
            $alasanTersisa = [];

            foreach ($sisa as $akun) {
                $nama = "{$akun->Kode} {$akun->Nama}";
                $alasan = $this->pemakaian->AmbilAlasanDipakai($akun);

                if ($alasan === null && $terapkan) {
                    try {
                        $this->hapus->Jalankan($akun);
                    } catch (PelanggaranAturanBisnis) {
                        $alasan = $this->pemakaian->AmbilAlasanDipakai($akun) ?? 'tidak bisa dihapus';
                    }
                }

                if ($alasan !== null) {
                    $berikutnya[] = $akun;
                    $alasanTersisa[$nama] = 'Akun ini '.$alasan.'.';

                    continue;
                }

                $dihapus[] = $nama;
                $ada = true;
            }

            $sisa = $berikutnya;
        } while ($terapkan && $ada && $sisa !== []);

        $dipertahankan = [];

        foreach ($alasanTersisa as $nama => $alasan) {
            $dipertahankan[] = ['Nama' => $nama, 'Alasan' => $alasan];
        }

        return new HasilPembersihanTemplate($dihapus, $dipertahankan);
    }
}
