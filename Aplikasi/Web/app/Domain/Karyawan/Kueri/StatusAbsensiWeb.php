<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Kueri;

use App\Domain\Karyawan\Enum\StatusWajahKaryawan;
use App\Domain\Karyawan\Model\Absensi;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\WajahKaryawan;
use App\Domain\Organisasi\Kueri\LokasiAbsensiOutlet;

/**
 * Isi halaman Absen berlogin (F-18 bagian 4, D-37, D-180): status wajah, absensi yang masih terbuka, aturan foto bukti,
 * dan beberapa absensi terakhir. Tanpa sidik wajah, foto, maupun koordinat.
 */
final class StatusAbsensiWeb
{
    public function __construct(
        private readonly LokasiAbsensiOutlet $lokasi,
        private readonly AturanKehadiranTenant $aturan,
    ) {}

    /**
     * @return array{Wajah: array{Status: string, Label: string, AlasanTolak: string|null}|null, AbsensiTerbuka: array{Uuid: string, MasukPada: string, NamaOutlet: string|null}|null, Riwayat: list<array{MasukPada: string, KeluarPada: string|null, NamaOutlet: string|null, PerluDicek: bool, KeluarOtomatis: bool}>, JumlahFotoDaftar: int, SimpanFoto: bool}
     */
    public function Ambil(Karyawan $karyawan): array
    {
        $wajah = WajahKaryawan::query()->where('IdKaryawan', $karyawan->Id)->latest('Id')->first();
        $terbuka = Absensi::query()->where('IdKaryawan', $karyawan->Id)->whereNull('KeluarPada')->latest('MasukPada')->first();
        $riwayat = Absensi::query()->where('IdKaryawan', $karyawan->Id)->latest('MasukPada')->limit(5)->get();

        return [
            'Wajah' => $wajah === null ? null : [
                'Status' => $wajah->Status->value,
                'Label' => $wajah->Status->AmbilLabel(),
                'AlasanTolak' => $wajah->Status === StatusWajahKaryawan::Ditolak ? $wajah->AlasanTolak : null,
            ],
            'AbsensiTerbuka' => $terbuka === null ? null : [
                'Uuid' => $terbuka->Uuid,
                'MasukPada' => $terbuka->MasukPada->toIso8601String(),
                'NamaOutlet' => $this->lokasi->AmbilNama($terbuka->IdOutlet),
            ],
            'Riwayat' => array_values($riwayat->map(fn (Absensi $a): array => [
                'MasukPada' => $a->MasukPada->toIso8601String(),
                'KeluarPada' => $a->KeluarPada?->toIso8601String(),
                'NamaOutlet' => $this->lokasi->AmbilNama($a->IdOutlet),
                'PerluDicek' => $a->PerluDicek,
                'KeluarOtomatis' => $a->KeluarOtomatis,
            ])->all()),
            'JumlahFotoDaftar' => (int) config('karyawan.JumlahFotoDaftarWajah'),
            // Foto hanya dikirim dari halaman bila aturan menyimpannya (wajah tetap dicocokkan lewat sidik di perangkat).
            'SimpanFoto' => $this->aturan->Ambil()['SimpanFotoBukti'],
        ];
    }
}
