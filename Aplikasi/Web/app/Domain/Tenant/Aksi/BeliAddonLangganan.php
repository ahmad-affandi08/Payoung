<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Whatsapp\KetersediaanWhatsapp;
use App\Domain\Tenant\Enum\JenisTagihanLangganan;
use App\Domain\Tenant\Enum\StatusPaket;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use App\Domain\Tenant\Layanan\PenerbitTagihanLangganan;
use App\Domain\Tenant\Model\Addon;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\LanggananAddon;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Model\TagihanLanggananAddon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pemilik membeli add-on (D-49, F-19): menerbitkan tagihan `Addon` berharga prorata sampai akhir periode langganan.
 * Add-on aktif setelah tagihan lunas (`PelunasTagihanLangganan`). Hanya satu tagihan terbuka per tenant (BR-P08.4);
 * tagihan add-on yang sama yang masih terbuka dikembalikan apa adanya supaya klik ganda tidak membuat tagihan kedua.
 */
final class BeliAddonLangganan
{
    public const JUMLAH_MAKS = 50;

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PenerbitTagihanLangganan $penerbit,
        private readonly KetersediaanWhatsapp $whatsapp,
    ) {}

    public function Jalankan(int $idPengguna, string $kodeAddon, int $jumlah = 1): TagihanLangganan
    {
        $idTenant = $this->konteks->Wajib();

        return DB::transaction(function () use ($idTenant, $idPengguna, $kodeAddon, $jumlah): TagihanLangganan {
            $langganan = Langganan::query()->where('IdTenant', $idTenant)->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('LanggananTidakAda', 'Data langganan usaha ini tidak ditemukan. Hubungi tim kami.');
            $addon = Addon::query()->where('Kode', strtoupper(trim($kodeAddon)))->where('Status', StatusPaket::Aktif->value)->first()
                ?? throw new PelanggaranAturanBisnis('AddonTidakTersedia', 'Add-on ini tidak tersedia. Pilih add-on lain dari daftar.', 'KodeAddon');

            // D-333: add-on WhatsApp tidak dijual selama WhatsApp belum aktif di konsol, juga lewat form lama atau permintaan langsung.
            if ($addon->KunciFitur === PemeriksaFiturTenant::KUNCI_WHATSAPP && ! $this->whatsapp->PlatformAktif()) {
                throw new PelanggaranAturanBisnis('AddonTidakTersedia', 'Add-on ini tidak tersedia. Pilih add-on lain dari daftar.', 'KodeAddon');
            }

            // Jumlah hanya bermakna untuk add-on penambah batas (outlet/perangkat tambahan); selain itu selalu 1.
            $jumlah = $addon->TambahanBatas === null ? 1 : max(1, min(self::JUMLAH_MAKS, $jumlah));
            $milik = LanggananAddon::query()->where('IdTenant', $idTenant)->where('IdAddon', $addon->Id)->first();

            if ($milik !== null && $milik->CekAktifPada(Carbon::now())) {
                throw new PelanggaranAturanBisnis(
                    'AddonSudahAktif',
                    $milik->BerhentiPada === null
                        ? "Add-on {$addon->Nama} sudah aktif dan diperpanjang otomatis."
                        : "Add-on {$addon->Nama} masih aktif sampai akhir periode. Pilih \"Lanjutkan langganan\" bila ingin terus memakainya.",
                    'KodeAddon',
                );
            }

            $terbuka = TagihanLangganan::query()->whereIn('Status', StatusTagihanLangganan::NilaiTerbuka())->orderBy('Id')->first();

            if ($terbuka !== null) {
                $untukAddonIni = $terbuka->Jenis === JenisTagihanLangganan::Addon
                    && TagihanLanggananAddon::query()->where('IdTagihanLangganan', $terbuka->Id)->where('IdAddon', $addon->Id)->exists();

                if ($untukAddonIni) {
                    return $terbuka;
                }

                throw new PelanggaranAturanBisnis(
                    'TagihanMasihTerbuka',
                    "Masih ada tagihan {$terbuka->Nomor} yang belum dibayar. Bayar atau batalkan tagihan itu dulu.",
                );
            }

            return $this->penerbit->TerbitkanAddon($langganan, $addon, $idPengguna, $jumlah);
        });
    }
}
