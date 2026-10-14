<?php

declare(strict_types=1);

namespace App\Domain\Pemenuhan\Kueri;

use App\Domain\Organisasi\Kueri\MejaOutlet;
use App\Domain\Organisasi\Kueri\ZonaWaktuOutlet;
use App\Domain\Pelanggan\Layanan\NomorHp;
use App\Domain\Pemenuhan\Model\ReservasiMeja;
use Carbon\CarbonImmutable;

/**
 * FnB reservasi meja + DP (D-145): reservasi satu tanggal (zona waktu outlet) di outlet perangkat untuk antrian aplikasi
 * kasir, urut jam mulai. Memuat Uuid meja agar kasir langsung membuka pesanan di meja itu, dan ringkasan uang muka
 * (dicatat, terpakai, sisa) agar kasir tahu berapa yang bisa dipotong dari tagihan.
 */
final class ReservasiMejaPos
{
    public function __construct(
        private readonly ZonaWaktuOutlet $zona,
        private readonly MejaOutlet $meja,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function Ambil(int $idOutlet, ?string $tanggal): array
    {
        $zona = $this->zona->Ambil($idOutlet);
        $hari = $tanggal === null ? CarbonImmutable::now($zona)->startOfDay() : CarbonImmutable::parse($tanggal, $zona)->startOfDay();
        $baris = ReservasiMeja::query()
            ->where('IdOutlet', $idOutlet)
            ->where('MulaiPada', '>=', $hari->utc())
            ->where('MulaiPada', '<', $hari->addDay()->utc())
            ->orderBy('MulaiPada')
            ->orderBy('Id')
            ->get();
        $meja = $this->meja->AmbilPerId(array_values(array_filter(array_unique($baris->pluck('IdMeja')->all()))));

        return array_values($baris->map(fn (ReservasiMeja $r): array => [
            'Uuid' => $r->Uuid,
            'Nomor' => $r->Nomor,
            'MulaiPada' => $r->MulaiPada->toIso8601ZuluString(),
            'SelesaiPada' => $r->AmbilSelesaiPada()->toIso8601ZuluString(),
            'NamaPemesan' => $r->NamaPemesan,
            'NoHp' => NomorHp::Format($r->NoHp),
            'JumlahTamu' => $r->JumlahTamu,
            'UuidMeja' => $r->IdMeja === null ? null : ($meja[$r->IdMeja]['Uuid'] ?? null),
            'NamaMeja' => $r->IdMeja === null ? null : ($meja[$r->IdMeja]['Nama'] ?? null),
            'Status' => $r->Status->value,
            'LabelStatus' => $r->Status->AmbilLabel(),
            'Catatan' => $r->Catatan,
            'UangMuka' => [
                'Dicatat' => $r->JumlahDp,
                'Terpakai' => $r->DpTerpakai,
                'Sisa' => $r->AmbilSisaUangMuka()->KeString(),
            ],
        ])->all());
    }
}
