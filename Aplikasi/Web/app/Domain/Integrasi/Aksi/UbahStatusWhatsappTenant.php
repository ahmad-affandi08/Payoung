<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Integrasi\Enum\StatusUjiGerbang;
use App\Domain\Integrasi\Model\WhatsappTenant;
use Illuminate\Support\Facades\DB;

/**
 * Mengaktifkan atau menonaktifkan WhatsApp milik tenant (D-108). Aktif hanya bila uji koneksi terakhir berhasil
 * setelah perubahan terakhir (pola BR-P05.4). Saat nonaktif, pesan kembali lewat nomor Payoung dan kuota paket.
 */
final class UbahStatusWhatsappTenant
{
    public function __construct(private readonly PencatatAudit $audit) {}

    public function Jalankan(bool $aktif): WhatsappTenant
    {
        return DB::transaction(function () use ($aktif): WhatsappTenant {
            $baris = WhatsappTenant::query()->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('WhatsappBelumDiatur', 'Simpan pengaturan WhatsApp dulu.');

            if ($aktif && $baris->StatusUji !== StatusUjiGerbang::Berhasil) {
                throw new PelanggaranAturanBisnis('UjiDulu', 'Uji koneksi sampai berhasil sebelum mengaktifkan WhatsApp sendiri.');
            }

            if ($baris->Aktif === $aktif) {
                throw new PelanggaranAturanBisnis('StatusTidakSesuai', $aktif ? 'WhatsApp sendiri sudah aktif.' : 'WhatsApp sendiri sudah nonaktif.');
            }

            $baris->update(['Aktif' => $aktif]);
            $this->audit->Catat(
                $aktif ? 'whatsapp-tenant.aktifkan' : 'whatsapp-tenant.nonaktifkan',
                $baris,
                ['Aktif' => ! $aktif],
                ['Aktif' => $aktif, 'Penyedia' => $baris->Penyedia->value],
            );

            return $baris;
        });
    }
}
