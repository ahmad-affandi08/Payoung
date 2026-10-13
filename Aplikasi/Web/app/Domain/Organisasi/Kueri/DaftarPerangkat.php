<?php

declare(strict_types=1);

namespace App\Domain\Organisasi\Kueri;

use App\Domain\Organisasi\Model\Outlet;
use App\Domain\Organisasi\Model\Perangkat;
use Carbon\CarbonInterface;

/**
 * Daftar perangkat di tenant aktif untuk halaman Perangkat back-office (F-02b), dibatasi outlet yang boleh diakses
 * pelaku. Perangkat aktif & belum diaktifkan lebih dulu, yang dicabut di akhir.
 */
final class DaftarPerangkat
{
    /**
     * @param  list<int>|null  $idOutletBoleh  null = semua outlet
     * @return list<array<string, mixed>>
     */
    public function Ambil(?array $idOutletBoleh): array
    {
        $outlet = Outlet::query()->get(['Id', 'Uuid', 'Kode', 'Nama'])->keyBy('Id');

        return array_values(Perangkat::query()
            ->when($idOutletBoleh !== null, fn ($kueri) => $kueri->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->orderByRaw('DicabutPada IS NOT NULL')
            ->orderBy('Kode')
            ->get()
            ->map(fn (Perangkat $perangkat): array => [
                'Uuid' => $perangkat->Uuid,
                'Kode' => $perangkat->Kode,
                'Nama' => $perangkat->Nama,
                'Jenis' => $perangkat->Jenis->value,
                'LabelJenis' => $perangkat->Jenis->AmbilLabel(),
                'UuidOutlet' => $outlet->get($perangkat->IdOutlet)?->Uuid,
                'NamaOutlet' => $outlet->get($perangkat->IdOutlet)?->Nama,
                'Status' => $perangkat->AmbilStatus(),
                'Platform' => $perangkat->Platform?->value,
                'VersiAplikasi' => $perangkat->VersiAplikasi,
                'PerangkatKeras' => self::RingkasProfilHardware($perangkat->ProfilHardware),
                'DiaktifkanPada' => $perangkat->DiaktifkanPada?->toIso8601String(),
                'TerakhirAktifPada' => $perangkat->TerakhirAktifPada?->toIso8601String(),
                'DicabutPada' => $perangkat->DicabutPada?->toIso8601String(),
            ])
            ->all());
    }

    /**
     * OWN-08: status perangkat untuk Aplikasi Owner (online terakhir, outbox tertunda, versi aplikasi), dibatasi outlet
     * akses. Urutan sama dengan `Ambil` (aktif lebih dulu, yang dicabut di akhir).
     *
     * @param  list<int>|null  $idOutletBoleh  null = semua outlet
     * @return list<array{Uuid: string, Kode: string, Nama: string, Jenis: string, Outlet: string, Status: string, TerakhirAktifPada: string|null, JumlahOutboxTertunda: int, VersiAplikasi: string|null}>
     */
    public function AmbilStatus(?array $idOutletBoleh): array
    {
        $outlet = Outlet::query()->get(['Id', 'Nama'])->keyBy('Id');

        return array_values(Perangkat::query()
            ->when($idOutletBoleh !== null, fn ($kueri) => $kueri->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->orderByRaw('DicabutPada IS NOT NULL')
            ->orderBy('Kode')
            ->get()
            ->map(fn (Perangkat $perangkat): array => self::BarisStatus($perangkat, (string) $outlet->get($perangkat->IdOutlet)?->Nama))
            ->all());
    }

    /**
     * OWN-08: satu baris status (bentuk sama dengan `AmbilStatus`), dipakai jawaban `POST perangkat/{uuid}/cabut`.
     *
     * @return array{Uuid: string, Kode: string, Nama: string, Jenis: string, Outlet: string, Status: string, TerakhirAktifPada: string|null, JumlahOutboxTertunda: int, VersiAplikasi: string|null}
     */
    public function StatusSatu(Perangkat $perangkat): array
    {
        return self::BarisStatus($perangkat, (string) Outlet::query()->whereKey($perangkat->IdOutlet)->value('Nama'));
    }

    /**
     * @return array{Uuid: string, Kode: string, Nama: string, Jenis: string, Outlet: string, Status: string, TerakhirAktifPada: string|null, JumlahOutboxTertunda: int, VersiAplikasi: string|null}
     */
    private static function BarisStatus(Perangkat $perangkat, string $namaOutlet): array
    {
        return [
            'Uuid' => $perangkat->Uuid,
            'Kode' => $perangkat->Kode,
            'Nama' => $perangkat->Nama,
            'Jenis' => $perangkat->Jenis->value,
            'Outlet' => $namaOutlet,
            'Status' => $perangkat->AmbilStatus(),
            'TerakhirAktifPada' => $perangkat->TerakhirAktifPada?->utc()->toIso8601ZuluString(),
            'JumlahOutboxTertunda' => $perangkat->JumlahOutboxTertunda,
            'VersiAplikasi' => $perangkat->VersiAplikasi,
        ];
    }

    /**
     * OWN-02: perangkat aktif (sudah diaktifkan, belum dicabut) yang tidak menghubungi server sejak `$batas`.
     *
     * @param  list<int>|null  $idOutletBoleh  null = semua outlet
     * @return list<array{Nama: string, Kode: string, IdOutlet: int, TerakhirAktifPada: CarbonInterface|null}>
     */
    public function AmbilTidakAktif(?array $idOutletBoleh, CarbonInterface $batas): array
    {
        return array_values(Perangkat::query()
            ->when($idOutletBoleh !== null, fn ($kueri) => $kueri->whereIn('IdOutlet', $idOutletBoleh ?? []))
            ->whereNull('DicabutPada')
            ->whereNotNull('DiaktifkanPada')
            ->where(fn ($kueri) => $kueri->whereNull('TerakhirAktifPada')->orWhere('TerakhirAktifPada', '<', $batas))
            ->orderBy('Kode')
            ->get(['Id', 'IdOutlet', 'Kode', 'Nama', 'TerakhirAktifPada'])
            ->map(fn (Perangkat $perangkat): array => [
                'Nama' => $perangkat->Nama,
                'Kode' => $perangkat->Kode,
                'IdOutlet' => $perangkat->IdOutlet,
                'TerakhirAktifPada' => $perangkat->TerakhirAktifPada,
            ])
            ->all());
    }

    /**
     * v1.96: ringkasan `ProfilHardware` untuk daftar perangkat, misal "SUNMI V2s | Printer bawaan (58 mm) | uji lolos".
     *
     * @param  array<string, mixed>|null  $profil
     */
    public static function RingkasProfilHardware(?array $profil): ?string
    {
        if ($profil === null) {
            return null;
        }

        $teks = fn (mixed $nilai): string => is_string($nilai) ? trim($nilai) : '';
        $printer = is_array($profil['Printer'] ?? null) ? $profil['Printer'] : [];
        $uji = is_array($profil['Uji'] ?? null) ? array_filter($profil['Uji'], fn (mixed $h): bool => in_array($h, ['Lolos', 'Gagal'], true)) : [];
        $bagian = [
            trim($teks($profil['Produsen'] ?? null).' '.$teks($profil['Model'] ?? null)),
            $teks($printer['Nama'] ?? null) === '' ? '' : $teks($printer['Nama']).($teks($printer['Lebar'] ?? null) === '' ? '' : ' ('.$teks($printer['Lebar']).')'),
            $uji === [] ? '' : (in_array('Gagal', $uji, true) ? 'uji ada yang gagal' : 'uji lolos'),
        ];
        $hasil = implode(' | ', array_filter($bagian, fn (string $b): bool => $b !== ''));

        return $hasil === '' ? null : $hasil;
    }

    /**
     * Label perangkat per Id untuk tampilan domain lain (F-06 shift): `Kode — Nama`.
     *
     * @param  list<int>  $id
     * @return array<int, string>
     */
    public function AmbilLabel(array $id): array
    {
        $hasil = [];

        foreach (Perangkat::query()->whereIn('Id', array_values(array_unique($id)))->get(['Id', 'Kode', 'Nama']) as $perangkat) {
            $hasil[$perangkat->Id] = "{$perangkat->Kode} — {$perangkat->Nama}";
        }

        return $hasil;
    }

    /** Perangkat tenant aktif berdasarkan Uuid di satu outlet; null bila tidak ada (termasuk milik tenant/outlet lain). */
    public function CariDiOutlet(string $uuid, int $idOutlet): ?Perangkat
    {
        return Perangkat::query()->where('Uuid', $uuid)->where('IdOutlet', $idOutlet)->first();
    }
}
