<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Kueri;

use App\Domain\Karyawan\Model\AturanKehadiran;

/** Aturan kehadiran tenant aktif (F-18 bagian 5); tanpa baris = bawaan model. Dibaca dalam konteks tenant. */
final class AturanKehadiranTenant
{
    /**
     * @return array{WajibJadwal: bool, MasukPalingAwalMenit: int, ToleransiTerlambatMenit: int, ToleransiPulangCepatMenit: int, LemburSetelahMenit: int, PengingatShiftAktif: bool, PengingatShiftMenitSebelum: int, PeringatanPengelolaAktif: bool, PeringatanPengelolaSetelahMenit: int, SimpanFotoBukti: bool, TolakDiLuarRadius: bool}
     */
    public function Ambil(): array
    {
        return self::Petakan(AturanKehadiran::query()->first() ?? new AturanKehadiran);
    }

    /**
     * @return array{WajibJadwal: bool, MasukPalingAwalMenit: int, ToleransiTerlambatMenit: int, ToleransiPulangCepatMenit: int, LemburSetelahMenit: int, PengingatShiftAktif: bool, PengingatShiftMenitSebelum: int, PeringatanPengelolaAktif: bool, PeringatanPengelolaSetelahMenit: int, SimpanFotoBukti: bool, TolakDiLuarRadius: bool}
     */
    public static function Petakan(AturanKehadiran $a): array
    {
        return [
            'WajibJadwal' => $a->WajibJadwal,
            'MasukPalingAwalMenit' => $a->MasukPalingAwalMenit,
            'ToleransiTerlambatMenit' => $a->ToleransiTerlambatMenit,
            'ToleransiPulangCepatMenit' => $a->ToleransiPulangCepatMenit,
            'LemburSetelahMenit' => $a->LemburSetelahMenit,
            'PengingatShiftAktif' => $a->PengingatShiftAktif,
            'PengingatShiftMenitSebelum' => $a->PengingatShiftMenitSebelum,
            'PeringatanPengelolaAktif' => $a->PeringatanPengelolaAktif,
            'PeringatanPengelolaSetelahMenit' => $a->PeringatanPengelolaSetelahMenit,
            'SimpanFotoBukti' => $a->SimpanFotoBukti,
            'TolakDiLuarRadius' => $a->TolakDiLuarRadius,
        ];
    }
}
