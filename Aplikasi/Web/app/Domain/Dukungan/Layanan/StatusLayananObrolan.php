<?php

declare(strict_types=1);

namespace App\Domain\Dukungan\Layanan;

/**
 * Keadaan layanan obrolan untuk pelapor (PLT-12, D-163): ada petugas online atau tidak, dan kalimat jujur tentang apa
 * yang terjadi pada pesannya. Obrolan tanpa petugas tetap sah: pesan menjadi tiket biasa (SLA, email, eskalasi).
 */
final class StatusLayananObrolan
{
    public function __construct(
        private readonly KehadiranPetugasObrolan $kehadiran,
        private readonly PenghitungSlaTiket $sla,
    ) {}

    /**
     * @return array{Buka: bool, PetugasOnline: int, DalamJamKerja: bool, Pesan: string, SegarkanDetik: int, MaksPanjangPesan: int}
     */
    public function Ambil(): array
    {
        $online = $this->kehadiran->HitungOnline();
        $dalamJamKerja = $this->sla->CekDalamJamKerja(now());
        $mulai = sprintf('%02d.00', (int) config('dukungan.JamKerja.Mulai'));
        $selesai = sprintf('%02d.00', (int) config('dukungan.JamKerja.Selesai'));

        $pesan = match (true) {
            $online > 0 => 'Tim dukungan online. Tulis pesan Anda, biasanya dibalas dalam beberapa menit.',
            $dalamJamKerja => 'Belum ada petugas yang siap menjawab saat ini. Pesan Anda tetap tercatat sebagai tiket dan dibalas sesuai janji respons paket Anda.',
            default => "Di luar jam layanan (Senin sampai Jumat {$mulai}-{$selesai} WIB). Pesan Anda tercatat sebagai tiket dan dibalas pada jam kerja berikutnya.",
        };

        return [
            'Buka' => $online > 0,
            'PetugasOnline' => $online,
            'DalamJamKerja' => $dalamJamKerja,
            'Pesan' => $pesan,
            'SegarkanDetik' => (int) config('dukungan.Obrolan.SegarkanDetik'),
            'MaksPanjangPesan' => (int) config('dukungan.Obrolan.MaksPanjangPesan'),
        ];
    }
}
