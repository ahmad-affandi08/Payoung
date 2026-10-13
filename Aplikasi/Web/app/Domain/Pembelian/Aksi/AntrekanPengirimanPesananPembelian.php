<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Pembelian\Enum\StatusPengirimanPesananPembelian;
use App\Domain\Pembelian\Enum\StatusPesananPembelian;
use App\Domain\Pembelian\Model\PengirimanPesananPembelian;
use App\Domain\Pembelian\Model\PesananPembelian;
use App\Domain\Pembelian\Tugas\KirimPesananPembelianTugas;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use Illuminate\Support\Facades\DB;

/**
 * F-04 (F-e): antrekan pengiriman satu PO ke pemasok lewat WhatsApp (notifikasi tenant hanya lewat WhatsApp, D-33).
 * Syarat: PO sudah disetujui dan belum dibatalkan/ditutup, pemasok punya nomor HP Indonesia yang sah, WhatsApp aktif untuk
 * usaha ini (integrasi P-05 + fitur `integrasi.whatsapp`). Paling sering sekali per `JEDA_MENIT` per PO supaya pemasok tidak
 * dibanjiri. Pengiriman dilakukan tugas antrean setelah commit. Audit `pesanan-pembelian.kirim-whatsapp` (tanpa nomor).
 */
final class AntrekanPengirimanPesananPembelian
{
    public const JEDA_MENIT = 10;

    public function __construct(
        private readonly PembuatPengirimWhatsapp $whatsapp,
        private readonly PemeriksaFiturTenant $fitur,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis PesananBelumDisetujui, KontakTidakTersedia, WhatsappTidakAktif, PesananBaruDikirim
     */
    public function Jalankan(int $idTenant, PesananPembelian $po, int $idPengguna): PengirimanPesananPembelian
    {
        if (! in_array($po->Status, [StatusPesananPembelian::Disetujui, StatusPesananPembelian::DiterimaSebagian, StatusPesananPembelian::Diterima], true)) {
            throw new PelanggaranAturanBisnis('PesananBelumDisetujui', 'Pesanan dikirim ke pemasok setelah disetujui, dan tidak setelah dibatalkan atau ditutup.', 'Umum', 409);
        }

        $nomor = PesanWhatsapp::AmbilNomorSah($po->Pemasok->NoHp)
            ?? throw new PelanggaranAturanBisnis('KontakTidakTersedia', 'Pemasok belum punya nomor HP yang sah. Lengkapi nomornya di data pemasok (contoh 0812 3456 7890).', 'Umum', 409);

        if ($this->whatsapp->AmbilAktif() === null || ! $this->fitur->CekAktif($idTenant, PemeriksaFiturTenant::KUNCI_WHATSAPP)) {
            throw new PelanggaranAturanBisnis('WhatsappTidakAktif', 'Pengiriman WhatsApp belum aktif untuk usaha ini.', 'Umum', 409);
        }

        return DB::transaction(function () use ($idTenant, $po, $idPengguna, $nomor): PengirimanPesananPembelian {
            PesananPembelian::query()->whereKey($po->Id)->lockForUpdate()->first();

            if (PengirimanPesananPembelian::query()
                ->where('IdPesananPembelian', $po->Id)
                ->where('DibuatPada', '>', now()->subMinutes(self::JEDA_MENIT))
                ->whereIn('Status', [StatusPengirimanPesananPembelian::Diantrekan->value, StatusPengirimanPesananPembelian::Terkirim->value])
                ->exists()) {
                throw new PelanggaranAturanBisnis('PesananBaruDikirim', 'Pesanan ini baru saja dikirim ke pemasok. Coba lagi beberapa menit lagi.', 'Umum', 429);
            }

            $pengiriman = PengirimanPesananPembelian::query()->create([
                'IdPesananPembelian' => $po->Id,
                'Tujuan' => $nomor,
                'Status' => StatusPengirimanPesananPembelian::Diantrekan,
                'DikirimOleh' => $idPengguna,
            ]);
            $this->audit->Catat('pesanan-pembelian.kirim-whatsapp', $po, nilaiBaru: ['Nomor' => $po->Nomor, 'IdPemasok' => $po->IdPemasok], idPengguna: $idPengguna);
            KirimPesananPembelianTugas::dispatch($idTenant, $pengiriman->Id)->afterCommit();

            return $pengiriman;
        });
    }
}
