<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Kueri;

use App\Domain\Akuntansi\Aksi\SimpanAnggaran;
use App\Domain\Akuntansi\Enum\TipeAkun;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\Anggaran;

/**
 * Bahan halaman "Susun anggaran" (F8): akun pendapatan, HPP, dan beban (aktif, atau nonaktif yang masih punya
 * anggaran tahun itu) dengan dua belas angka bulanan untuk satu cakupan; bulan tanpa anggaran berisi string kosong.
 */
final class DaftarAnggaran
{
    /**
     * @return list<array{Uuid: string, Kode: string, Nama: string, Jenis: string, Aktif: bool, PerBulan: list<string>}>
     */
    public function Ambil(int $tahun, ?int $idOutlet): array
    {
        $tersimpan = SimpanAnggaran::Cakupan(Anggaran::query()->where('Tahun', $tahun), $idOutlet)->get()->groupBy('IdAkun');
        $akun = Akun::query()
            ->whereIn('Jenis', array_map(fn (TipeAkun $t): string => $t->value, SimpanAnggaran::TIPE_AKUN))
            ->where(fn ($q) => $q->where('Aktif', true)->orWhereIn('Id', $tersimpan->keys()->all()))
            ->orderBy('Kode')
            ->get();

        return array_values($akun->map(function (Akun $a) use ($tersimpan): array {
            $jumlahPerBulan = $tersimpan->get($a->Id, collect())->pluck('Jumlah', 'Bulan');
            $perBulan = array_map(fn (int $bulan): string => (string) ($jumlahPerBulan->get($bulan) ?? ''), range(1, 12));

            return ['Uuid' => $a->Uuid, 'Kode' => $a->Kode, 'Nama' => $a->Nama, 'Jenis' => $a->Jenis->value, 'Aktif' => $a->Aktif, 'PerBulan' => $perBulan];
        })->all());
    }
}
