<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tenant\Kueri;

use App\Domain\Pengelola\Tenant\Model\SkorKesehatanTenant;
use App\Domain\Pengelola\Tenant\Model\TugasTenant;
use App\Domain\Tenant\Model\Tenant;

/**
 * Skor kesehatan satu tenant untuk tampilan 360° (P-07 PGL-09): skor terbaru beserta rincian faktor, riwayat
 * `HARI_RIWAYAT` hari terakhir, dan tugas otomatis yang masih terbuka. Hanya membaca tabel catatan platform.
 */
final class RingkasanKesehatanTenant
{
    public const HARI_RIWAYAT = 14;

    /**
     * @return array{Terbaru: array{Tanggal: string, Skor: int, Kategori: string, LabelKategori: string, Faktor: list<array{Kunci: string, Nilai: int|bool, Poin: int, Maks: int}>}|null, Riwayat: list<array{Tanggal: string, Skor: int, Kategori: string}>, Tugas: list<array{Uuid: string, Jenis: string, LabelJenis: string, Ringkasan: string, DibuatPada: string}>}
     */
    public function Ambil(Tenant $tenant): array
    {
        $riwayat = SkorKesehatanTenant::query()->where('IdTenant', $tenant->Id)->orderByDesc('Tanggal')->limit(self::HARI_RIWAYAT)->get();
        $terbaru = $riwayat->first();

        return [
            'Terbaru' => $terbaru === null ? null : [
                'Tanggal' => $terbaru->Tanggal->toDateString(),
                'Skor' => $terbaru->Skor,
                'Kategori' => $terbaru->Kategori->value,
                'LabelKategori' => $terbaru->Kategori->AmbilLabel(),
                'Faktor' => array_values(array_map(
                    fn (string $kunci, array $f): array => ['Kunci' => $kunci, 'Nilai' => $f['Nilai'], 'Poin' => $f['Poin'], 'Maks' => $f['Maks']],
                    array_keys($terbaru->Faktor),
                    array_values($terbaru->Faktor),
                )),
            ],
            'Riwayat' => array_values($riwayat->map(fn (SkorKesehatanTenant $s): array => [
                'Tanggal' => $s->Tanggal->toDateString(),
                'Skor' => $s->Skor,
                'Kategori' => $s->Kategori->value,
            ])->all()),
            'Tugas' => array_values(TugasTenant::query()->where('IdTenant', $tenant->Id)->where('Status', TugasTenant::TERBUKA)->orderBy('Id')->get()->map(fn (TugasTenant $t): array => [
                'Uuid' => $t->Uuid,
                'Jenis' => $t->Jenis->value,
                'LabelJenis' => $t->Jenis->AmbilLabel(),
                'Ringkasan' => $t->Ringkasan,
                'DibuatPada' => $t->DibuatPada?->toIso8601String() ?? '',
            ])->all()),
        ];
    }
}
