<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Integrasi\Whatsapp\PesanWhatsapp;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Penjualan\Enum\JenisPengirimanInvoice;
use App\Domain\Penjualan\Enum\StatusInvoice;
use App\Domain\Penjualan\Enum\StatusPengirimanInvoice;
use App\Domain\Penjualan\Model\Invoice;
use App\Domain\Penjualan\Model\PengirimanInvoice;
use App\Domain\Penjualan\Tugas\KirimInvoiceTugas;
use App\Domain\Tenant\Layanan\PemeriksaFiturTenant;
use Illuminate\Support\Facades\DB;

/**
 * F-21: antrekan pengiriman satu invoice (atau pengingat tagihannya) ke pelanggan lewat WhatsApp (notifikasi tenant
 * hanya lewat WhatsApp, D-33). Syarat: invoice sudah terbit dan belum dibatalkan, pelanggan punya nomor HP Indonesia
 * yang sah, WhatsApp aktif untuk usaha ini (integrasi P-05 + fitur `integrasi.whatsapp`). Pengingat hanya untuk invoice
 * yang masih punya sisa tagihan. Kiriman manual dibatasi sekali per `JEDA_MENIT` per invoice dan jenis supaya pelanggan
 * tidak dibanjiri; pengingat otomatis (`tahap` terisi) dibatasi sekali per tahap per invoice. Pengiriman dilakukan tugas
 * antrean setelah commit. Audit `invoice.kirim` (tanpa nomor).
 */
final class AntrekanPengirimanInvoice
{
    public const JEDA_MENIT = 10;

    public function __construct(
        private readonly PembuatPengirimWhatsapp $whatsapp,
        private readonly PemeriksaFiturTenant $fitur,
        private readonly IdentitasPelanggan $identitas,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis InvoiceBelumTerbit, InvoiceSudahLunas, KontakTidakTersedia, WhatsappTidakAktif, InvoiceBaruDikirim
     */
    public function Jalankan(int $idTenant, Invoice $invoice, ?int $idPengguna, JenisPengirimanInvoice $jenis = JenisPengirimanInvoice::Invoice, ?string $tahap = null): PengirimanInvoice
    {
        if ($invoice->Status !== StatusInvoice::Terbit) {
            throw new PelanggaranAturanBisnis('InvoiceBelumTerbit', 'Invoice dikirim setelah diterbitkan, dan tidak setelah dibatalkan.', 'Umum', 409);
        }

        if ($jenis === JenisPengirimanInvoice::Pengingat && ! $this->MasihAdaSisa($invoice)) {
            throw new PelanggaranAturanBisnis('InvoiceSudahLunas', 'Invoice ini tidak punya sisa tagihan, jadi tidak perlu diingatkan.', 'Umum', 409);
        }

        $kontak = $this->identitas->AmbilKontak($invoice->IdPelanggan);
        $nomor = $kontak === null ? null : PesanWhatsapp::AmbilNomorSah($kontak['NoHp']);

        if ($nomor === null) {
            throw new PelanggaranAturanBisnis('KontakTidakTersedia', 'Pelanggan belum punya nomor HP yang sah. Lengkapi nomornya di data pelanggan (contoh 0812 3456 7890).', 'Umum', 409);
        }

        if ($this->whatsapp->AmbilAktif() === null || ! $this->fitur->CekAktif($idTenant, PemeriksaFiturTenant::KUNCI_WHATSAPP)) {
            throw new PelanggaranAturanBisnis('WhatsappTidakAktif', 'Pengiriman WhatsApp belum aktif untuk usaha ini.', 'Umum', 409);
        }

        return DB::transaction(function () use ($idTenant, $invoice, $idPengguna, $jenis, $tahap, $nomor): PengirimanInvoice {
            Invoice::query()->whereKey($invoice->Id)->lockForUpdate()->first();

            $aktif = PengirimanInvoice::query()
                ->where('IdInvoice', $invoice->Id)
                ->whereIn('Status', [StatusPengirimanInvoice::Diantrekan->value, StatusPengirimanInvoice::Terkirim->value]);

            if ($tahap !== null) {
                if ((clone $aktif)->where('Tahap', $tahap)->exists()) {
                    throw new PelanggaranAturanBisnis('PengingatSudahDikirim', 'Pengingat tahap ini sudah dikirim.', 'Umum', 409);
                }
            } elseif ((clone $aktif)->where('Jenis', $jenis->value)->whereNull('Tahap')->where('DibuatPada', '>', now()->subMinutes(self::JEDA_MENIT))->exists()) {
                throw new PelanggaranAturanBisnis('InvoiceBaruDikirim', 'Invoice ini baru saja dikirim ke pelanggan. Coba lagi beberapa menit lagi.', 'Umum', 429);
            }

            $pengiriman = PengirimanInvoice::query()->create([
                'IdInvoice' => $invoice->Id,
                'Jenis' => $jenis,
                'Tahap' => $tahap,
                'Tujuan' => $nomor,
                'Status' => StatusPengirimanInvoice::Diantrekan,
                'DikirimOleh' => $idPengguna,
            ]);
            $this->audit->Catat('invoice.kirim', $invoice, nilaiBaru: ['Nomor' => $invoice->Nomor, 'Jenis' => $jenis->value, 'Tahap' => $tahap], idPengguna: $idPengguna);
            KirimInvoiceTugas::dispatch($idTenant, $pengiriman->Id)->afterCommit();

            return $pengiriman;
        });
    }

    private function MasihAdaSisa(Invoice $invoice): bool
    {
        $piutang = Piutang::query()->where('IdInvoice', $invoice->Id)->first();

        return $piutang !== null && $piutang->Status->CekTerbuka();
    }
}
