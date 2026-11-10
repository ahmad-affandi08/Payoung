<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Data;

use App\Domain\Akuntansi\Layanan\PenggolongUangKas;
use App\Domain\Bersama\Nilai\Uang;

/**
 * Penampung hitungan Pemasukan & Pengeluaran satu periode (D-255): uang masuk dan keluar per baris (kelompok + label),
 * perubahan di luar hitungan usaha (modal, prive, beli aset, pindah antar akun, bertanda), dan total per tanggal
 * untuk grafik. Semua nilai `Uang` (brick/math), tidak pernah float.
 */
final class AkumulatorUangKas
{
    /** @var array<string, array{Label: string, Kelompok: string, Nilai: Uang}> */
    public array $masuk = [];

    /** @var array<string, array{Label: string, Kelompok: string, Nilai: Uang}> */
    public array $keluar = [];

    /** @var array<string, array{Label: string, Kelompok: string, Nilai: Uang}> masuk bertanda positif, keluar negatif */
    public array $diLuar = [];

    /** @var array<string, array{Masuk: Uang, Keluar: Uang}> kunci tanggal `YYYY-MM-DD`, hanya uang usaha */
    public array $perHari = [];

    /**
     * Rincian per tanggal, outlet, arah, kelompok, dan kategori (dasar lembar Transaksi di Excel). `Arah` = `Masuk`,
     * `Keluar` (nilai positif), atau `DiLuar` (nilai bertanda, masuk positif).
     *
     * @var array<string, array{Tanggal: string, IdOutlet: int|null, Arah: string, Kelompok: string, Label: string, Nilai: Uang}>
     */
    public array $rincian = [];

    /**
     * @param  array{Label: string, Kelompok: string, DiLuar: bool}  $golongan
     */
    public function Tambah(string $tanggal, bool $masuk, array $golongan, Uang $nilai, ?int $idOutlet = null): void
    {
        if ($nilai->BernilaiNol()) {
            return;
        }

        $kunci = $golongan['Kelompok'].'|'.$golongan['Label'];
        $arah = $golongan['DiLuar'] ? 'DiLuar' : ($masuk ? 'Masuk' : 'Keluar');
        $kunciRincian = $tanggal.'|'.($idOutlet ?? '').'|'.$arah.'|'.$kunci;
        $bertanda = $golongan['DiLuar'] && ! $masuk ? Uang::Nol()->Kurangi($nilai) : $nilai;
        $this->rincian[$kunciRincian] = [
            'Tanggal' => $tanggal,
            'IdOutlet' => $idOutlet,
            'Arah' => $arah,
            'Kelompok' => $golongan['Kelompok'],
            'Label' => $golongan['Label'],
            'Nilai' => ($this->rincian[$kunciRincian]['Nilai'] ?? Uang::Nol())->Tambah($bertanda),
        ];

        if ($golongan['DiLuar']) {
            $lama = $this->diLuar[$kunci]['Nilai'] ?? Uang::Nol();
            $this->diLuar[$kunci] = ['Label' => $golongan['Label'], 'Kelompok' => $golongan['Kelompok'], 'Nilai' => $lama->Tambah($masuk ? $nilai : Uang::Nol()->Kurangi($nilai))];

            return;
        }

        $lama = ($masuk ? $this->masuk : $this->keluar)[$kunci]['Nilai'] ?? Uang::Nol();
        $baru = ['Label' => $golongan['Label'], 'Kelompok' => $golongan['Kelompok'], 'Nilai' => $lama->Tambah($nilai)];

        if ($masuk) {
            $this->masuk[$kunci] = $baru;
        } else {
            $this->keluar[$kunci] = $baru;
        }

        $hari = $this->perHari[$tanggal] ?? ['Masuk' => Uang::Nol(), 'Keluar' => Uang::Nol()];
        $hari[$masuk ? 'Masuk' : 'Keluar'] = $hari[$masuk ? 'Masuk' : 'Keluar']->Tambah($nilai);
        $this->perHari[$tanggal] = $hari;
    }

    /**
     * Tambah nilai bertanda: positif = masuk, negatif = keluar.
     *
     * @param  array{Label: string, Kelompok: string, DiLuar: bool}  $golongan
     */
    public function TambahBertanda(string $tanggal, array $golongan, Uang $nilai, ?int $idOutlet = null): void
    {
        $this->Tambah($tanggal, ! $nilai->BernilaiNegatif(), $golongan, $nilai->BernilaiNegatif() ? Uang::Nol()->Kurangi($nilai) : $nilai, $idOutlet);
    }

    public function TotalMasuk(): Uang
    {
        return self::Jumlah($this->masuk);
    }

    public function TotalKeluar(): Uang
    {
        return self::Jumlah($this->keluar);
    }

    /** Σ perubahan di luar hitungan usaha (bertanda). */
    public function TotalDiLuar(): Uang
    {
        return self::Jumlah($this->diLuar);
    }

    /** Uang usaha yang belum bisa dikelompokkan (jenis sumber tidak dikenal), masuk dan keluar. */
    public function TotalBelumDikelompokkan(): Uang
    {
        $total = Uang::Nol();

        foreach ([$this->masuk, $this->keluar] as $daftar) {
            foreach ($daftar as $baris) {
                if ($baris['Kelompok'] === PenggolongUangKas::BELUM_DIKELOMPOKKAN) {
                    $total = $total->Tambah($baris['Nilai']);
                }
            }
        }

        return $total;
    }

    /**
     * @param  array<string, array{Label: string, Kelompok: string, Nilai: Uang}>  $daftar
     */
    private static function Jumlah(array $daftar): Uang
    {
        $total = Uang::Nol();

        foreach ($daftar as $baris) {
            $total = $total->Tambah($baris['Nilai']);
        }

        return $total;
    }
}
