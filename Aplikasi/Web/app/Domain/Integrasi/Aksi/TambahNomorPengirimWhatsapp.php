<?php

declare(strict_types=1);

namespace App\Domain\Integrasi\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Enum\FungsiNomorWhatsapp;
use App\Domain\Integrasi\Model\NomorPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\KetersediaanWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Integrasi\Whatsapp\Sesi\PembuatGerbangSesiWhatsapp;
use Illuminate\Support\Facades\DB;

/**
 * D-299: mendaftarkan nomor pengirim WhatsApp (per outlet atau tingkat usaha). Sesi perangkat dibuat di penyedia
 * (di luar transaksi DB), baru barisnya disimpan. Nomor langsung berstatus `MenungguPindai` dan belum aktif sampai
 * QR dipindai. Maksimal 6 nomor per outlet, satu nomor hanya didaftarkan sekali per usaha.
 */
final class TambahNomorPengirimWhatsapp
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly KetersediaanWhatsapp $ketersediaan,
        private readonly PembuatGerbangSesiWhatsapp $pembuatGerbang,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(?int $idOutlet, string $label, FungsiNomorWhatsapp $fungsi, string $nomor): NomorPengirimWhatsapp
    {
        $idTenant = $this->konteks->Wajib();

        if (! $this->ketersediaan->TersediaUntuk($idTenant)) {
            throw new PelanggaranAturanBisnis('WhatsappTidakTersedia', 'Layanan WhatsApp belum aktif untuk usaha ini.', statusHttp: 409);
        }

        $gerbang = $this->pembuatGerbang->Ambil()
            ?? throw new PelanggaranAturanBisnis('SesiWhatsappBelumSiap', 'Layanan nomor pengirim belum siap. Hubungi dukungan Payoung.', statusHttp: 409);

        $nomorSah = PesanWhatsapp::AmbilNomorSah($nomor)
            ?? throw new PelanggaranAturanBisnis('NomorTidakSah', 'Nomor WhatsApp tidak sah. Contoh: 0812 3456 7890.', 'NomorTelepon');
        $label = trim($label);

        $this->PeriksaBatas($idOutlet, $nomorSah);

        $hasil = $gerbang->BuatSesi($label, $nomorSah);

        if (! $hasil->berhasil || $hasil->tokenPerangkat === null) {
            throw new PelanggaranAturanBisnis('SesiWhatsappGagal', $hasil->pesan, 'NomorTelepon', 409);
        }

        return DB::transaction(function () use ($idTenant, $idOutlet, $label, $fungsi, $nomorSah, $gerbang, $hasil): NomorPengirimWhatsapp {
            $this->PeriksaBatas($idOutlet, $nomorSah);

            $baris = NomorPengirimWhatsapp::query()->create([
                'IdTenant' => $idTenant, 'IdOutlet' => $idOutlet, 'Label' => $label, 'Fungsi' => $fungsi, 'NomorTelepon' => $nomorSah,
                'Penyedia' => $gerbang->AmbilKode(), 'TokenPerangkat' => $hasil->tokenPerangkat,
            ]);
            $this->audit->Catat('nomor-whatsapp.tambah', $baris, nilaiBaru: ['Label' => $label, 'Fungsi' => $fungsi->value, 'IdOutlet' => $idOutlet]);

            return $baris;
        });
    }

    private function PeriksaBatas(?int $idOutlet, string $nomor): void
    {
        if (NomorPengirimWhatsapp::query()->where('NomorTelepon', $nomor)->exists()) {
            throw new PelanggaranAturanBisnis('NomorSudahAda', 'Nomor ini sudah terdaftar sebagai pengirim.', 'NomorTelepon');
        }

        $jumlah = NomorPengirimWhatsapp::query()
            ->when($idOutlet === null, fn ($kueri) => $kueri->whereNull('IdOutlet'), fn ($kueri) => $kueri->where('IdOutlet', $idOutlet))
            ->count();

        if ($jumlah >= NomorPengirimWhatsapp::MAKS_PER_OUTLET) {
            throw new PelanggaranAturanBisnis('BatasNomor', 'Satu outlet paling banyak '.NomorPengirimWhatsapp::MAKS_PER_OUTLET.' nomor pengirim.', 'NomorTelepon');
        }
    }
}
