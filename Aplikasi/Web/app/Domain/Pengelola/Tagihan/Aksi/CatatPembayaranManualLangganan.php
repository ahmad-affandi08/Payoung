<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tagihan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pengelola\Tagihan\Kueri\DaftarTagihanPlatform;
use App\Domain\Pengelola\Tagihan\Surel\PembayaranLanggananDiterima;
use App\Domain\Pengelola\Tenant\Kueri\PemilikTenant;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\MetodePembayaranLangganan;
use App\Domain\Tenant\Enum\StatusPembayaranLangganan;
use App\Domain\Tenant\Layanan\PelunasTagihanLangganan;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\PembayaranLangganan;
use App\Domain\Tenant\Model\TagihanLangganan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Keuangan mencatat pembayaran tagihan manual yang uangnya sudah masuk ke rekening tanpa Owner mengunggah bukti
 * (P-08, D-112, audit E6). Pembayaran dibuat dan langsung diterima di transaksi yang sama lewat
 * `PelunasTagihanLangganan`, sehingga periode dan status yang dihasilkan sama persis dengan verifikasi bukti (BR-P08.9).
 * Hanya untuk tagihan manual; tagihan biasa dibayar lewat gerbang (D-82). Jumlah yang masuk harus sama dengan total.
 */
final class CatatPembayaranManualLangganan
{
    public function __construct(
        private readonly DaftarTagihanPlatform $daftar,
        private readonly PelunasTagihanLangganan $pelunas,
        private readonly PencatatAuditPengelola $audit,
        private readonly PemilikTenant $pemilik,
    ) {}

    public function Jalankan(
        PenggunaPengelola $pelaku,
        string $uuidTagihan,
        string $jumlahDiterima,
        CarbonImmutable $tanggalTransfer,
        string $namaPengirim,
        ?string $bankPengirim,
        ?string $catatan = null,
    ): PembayaranLangganan {
        $awal = $this->daftar->CariTagihan($uuidTagihan)
            ?? throw new PelanggaranAturanBisnis('TagihanTidakDitemukan', 'Tagihan tidak ditemukan.');

        if ($tanggalTransfer->toDateString() > now('Asia/Jakarta')->toDateString()) {
            throw new PelanggaranAturanBisnis('TanggalTransferDiMasaDepan', 'Tanggal transfer tidak boleh di masa depan.', 'TanggalTransfer');
        }

        [$pembayaran, $tagihan] = DB::transaction(function () use ($pelaku, $awal, $jumlahDiterima, $tanggalTransfer, $namaPengirim, $bankPengirim, $catatan): array {
            $langganan = Langganan::query()->where('IdTenant', $awal->IdTenant)->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('LanggananTidakAda', 'Langganan tenant ini tidak ditemukan.');
            $tagihan = DaftarTagihanPlatform::KueriTagihan()->with('Paket')->whereKey($awal->Id)->lockForUpdate()->firstOrFail();

            if (! $tagihan->Manual) {
                throw new PelanggaranAturanBisnis('BukanTagihanManual', 'Pembayaran hanya bisa dicatat langsung untuk tagihan manual. Tagihan ini dibayar Owner lewat pembayaran online.');
            }

            if (! $tagihan->Status->CekTerbuka()) {
                throw new PelanggaranAturanBisnis('TagihanTidakTerbuka', "Tagihan {$tagihan->Nomor} sudah {$tagihan->Status->AmbilLabel()}.");
            }

            $menunggu = DaftarTagihanPlatform::KueriPembayaran()
                ->where('IdTagihanLangganan', $tagihan->Id)
                ->where('Status', StatusPembayaranLangganan::Menunggu->value)
                ->exists();

            if ($menunggu) {
                throw new PelanggaranAturanBisnis('PembayaranMasihDiverifikasi', 'Ada bukti transfer tagihan ini yang menunggu verifikasi. Terima atau tolak bukti itu dari antrean verifikasi.');
            }

            $diterima = Uang::Dari($jumlahDiterima);
            $pembayaran = PembayaranLangganan::query()->create([
                'IdTenant' => $tagihan->IdTenant,
                'IdTagihanLangganan' => $tagihan->Id,
                'Metode' => MetodePembayaranLangganan::TransferManual,
                'Status' => StatusPembayaranLangganan::Menunggu,
                'Jumlah' => $tagihan->Total,
                'TanggalTransfer' => $tanggalTransfer->toDateString(),
                'BankPengirim' => $bankPengirim,
                'NamaPengirim' => $namaPengirim,
            ]);
            $hasil = $this->pelunas->Lunasi(
                $langganan,
                $tagihan,
                $pembayaran,
                $diterima,
                CarbonImmutable::now(),
                idVerifikatorPengelola: $pelaku->Id,
                mulaiPaketSebelumnya: TerimaPembayaranLangganan::AmbilMulaiPaketSebelumnya($tagihan),
            );

            $this->audit->Catat(
                'tagihan.pembayaran.catat-langsung',
                $pembayaran,
                nilaiLama: ['Tagihan' => $hasil->statusTagihanLama, 'Langganan' => $hasil->langgananLama],
                nilaiBaru: [
                    'Pembayaran' => StatusPembayaranLangganan::Diterima->value,
                    'NomorTagihan' => $tagihan->Nomor,
                    'JumlahDiterima' => $diterima->KeString(),
                    'TanggalTransfer' => $tanggalTransfer->toDateString(),
                    'NamaPengirim' => $namaPengirim,
                    'Langganan' => $hasil->LanggananBaru($tagihan->IdPaket, $tagihan->Siklus),
                ],
                alasan: $catatan,
                idPelaku: $pelaku->Id,
                idTenant: $tagihan->IdTenant,
            );

            return [$pembayaran, $tagihan];
        });

        $this->KirimPemberitahuan($tagihan);

        return $pembayaran;
    }

    private function KirimPemberitahuan(TagihanLangganan $tagihan): void
    {
        foreach ($this->pemilik->Ambil($tagihan->IdTenant) as $pemilik) {
            try {
                Mail::to($pemilik['Email'])->queue(new PembayaranLanggananDiterima(
                    nama: $pemilik['Nama'],
                    nomorTagihan: $tagihan->Nomor,
                    total: $tagihan->AmbilTotal()->FormatRupiah(),
                    namaPaket: $tagihan->AmbilNamaLayanan(),
                    periodeSelesai: $tagihan->PeriodeSelesai?->copy()->setTimezone('Asia/Jakarta')->translatedFormat('j F Y') ?? '—',
                ));
            } catch (Throwable $galat) {
                Log::warning('Email pembayaran langsung langganan gagal dikirim.', ['IdTenant' => $tagihan->IdTenant, 'Nomor' => $tagihan->Nomor, 'Galat' => $galat->getMessage()]);
            }
        }
    }
}
