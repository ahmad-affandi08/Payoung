<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Layanan;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Integrasi\Enum\JenisPesanWhatsapp;
use App\Domain\Integrasi\Model\PengaturanPesanWhatsapp;
use Illuminate\Support\Facades\DB;

/** Sakelar jenis pesan WhatsApp otomatis per tenant (D-299). Tanpa baris = nilai bawaan jenis itu. */
final class PengaturPesanWhatsapp
{
    public function __construct(private readonly PencatatAudit $audit) {}

    public function Aktif(JenisPesanWhatsapp $jenis): bool
    {
        $baris = PengaturanPesanWhatsapp::query()->where('Jenis', $jenis->value)->first();

        return $baris === null ? $jenis->BawaanAktif() : $baris->Aktif;
    }

    public function Atur(JenisPesanWhatsapp $jenis, bool $aktif, int $idTenant): void
    {
        DB::transaction(function () use ($jenis, $aktif, $idTenant): void {
            $baris = PengaturanPesanWhatsapp::query()->lockForUpdate()->where('Jenis', $jenis->value)->first();
            $lama = $baris === null ? $jenis->BawaanAktif() : $baris->Aktif;

            if ($baris === null) {
                $baris = PengaturanPesanWhatsapp::query()->create(['IdTenant' => $idTenant, 'Jenis' => $jenis, 'Aktif' => $aktif]);
            } else {
                $baris->update(['Aktif' => $aktif]);
            }

            if ($lama !== $aktif) {
                $this->audit->Catat('pesan-whatsapp.atur', $baris, ['Aktif' => $lama], ['Jenis' => $jenis->value, 'Aktif' => $aktif]);
            }
        });
    }

    /**
     * @return list<array{Kunci: string, Label: string, Keterangan: string, Kelompok: string, Aktif: bool}>
     */
    public function AmbilSemua(): array
    {
        $tersimpan = PengaturanPesanWhatsapp::query()->pluck('Aktif', 'Jenis')->all();

        return array_map(fn (JenisPesanWhatsapp $jenis): array => [
            'Kunci' => $jenis->value,
            'Label' => $jenis->AmbilLabel(),
            'Keterangan' => $jenis->AmbilKeterangan(),
            'Kelompok' => $jenis->AmbilKelompok(),
            'Aktif' => array_key_exists($jenis->value, $tersimpan) ? (bool) $tersimpan[$jenis->value] : $jenis->BawaanAktif(),
        ], JenisPesanWhatsapp::cases());
    }
}
