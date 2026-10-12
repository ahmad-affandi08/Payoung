<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Tagihan\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pengelola\Tagihan\Kueri\DaftarTagihanPlatform;
use App\Domain\Pengelola\Tagihan\Surel\PembayaranLanggananDiterima;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Domain\Tenant\Enum\JenisTagihanLangganan;
use App\Domain\Tenant\Enum\StatusPembayaranLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
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
 * Keuangan/Super Admin menerima bukti transfer (P-08 langkah 3): pembayaran Diterima → tagihan Lunas → langganan
 * Aktif dengan paket, siklus, dan periode baru (Trial/Gratis/Tertunggak/Ditangguhkan → Aktif, BR-00.7).
 *
 * - Jumlah yang benar-benar masuk ke rekening wajib diisi dan harus sama dengan total tagihan.
 * - Kunci berurutan Langganan → Tagihan → Pembayaran (sama dengan `UnggahBuktiTransfer` dan penjadwal tunggakan) lalu
 *   status diperiksa ulang, sehingga dua verifikator bersamaan atau klik ganda hanya menghasilkan satu penerimaan.
 * - Keputusan & tulisannya sendiri ada di `PelunasTagihanLangganan`, yang dipakai bersama jalur gerbang pembayaran
 *   (BR-P08.11) supaya periode dan status yang dihasilkan tidak bisa berbeda antar jalur.
 * - Tanpa four-eyes: verifikasi berbasis mutasi rekening dan tercatat di audit; persetujuan kedua P-08 hanya untuk
 *   refund > Rp 1.000.000 (BR-P08.2).
 * - Email pemberitahuan ke Owner dikirim setelah transaksi tersimpan; kegagalan email tidak membatalkan verifikasi.
 */
final class TerimaPembayaranLangganan
{
    public function __construct(
        private readonly DaftarTagihanPlatform $daftar,
        private readonly PelunasTagihanLangganan $pelunas,
        private readonly PencatatAuditPengelola $audit,
    ) {}

    public function Jalankan(PenggunaPengelola $verifikator, string $uuidPembayaran, string $jumlahDiterima, ?string $catatan = null): PembayaranLangganan
    {
        $awal = $this->daftar->CariPembayaran($uuidPembayaran)
            ?? throw new PelanggaranAturanBisnis('PembayaranTidakDitemukan', 'Pembayaran tidak ditemukan.');

        [$pembayaran, $tagihan] = DB::transaction(function () use ($verifikator, $awal, $jumlahDiterima, $catatan): array {
            $langganan = Langganan::query()->where('IdTenant', $awal->IdTenant)->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('LanggananTidakAda', 'Langganan tenant ini tidak ditemukan.');
            $tagihan = DaftarTagihanPlatform::KueriTagihan()->with('Paket')->whereKey($awal->IdTagihanLangganan)->lockForUpdate()->firstOrFail();
            $pembayaran = DaftarTagihanPlatform::KueriPembayaran()->whereKey($awal->Id)->lockForUpdate()->firstOrFail();

            $diterima = Uang::Dari($jumlahDiterima);
            $hasil = $this->pelunas->Lunasi(
                $langganan,
                $tagihan,
                $pembayaran,
                $diterima,
                CarbonImmutable::now(),
                idVerifikatorPengelola: $verifikator->Id,
                mulaiPaketSebelumnya: self::AmbilMulaiPaketSebelumnya($tagihan),
            );

            $this->audit->Catat(
                'tagihan.pembayaran.terima',
                $pembayaran,
                nilaiLama: ['Pembayaran' => StatusPembayaranLangganan::Menunggu->value, 'Tagihan' => $hasil->statusTagihanLama, 'Langganan' => $hasil->langgananLama],
                nilaiBaru: [
                    'Pembayaran' => StatusPembayaranLangganan::Diterima->value,
                    'NomorTagihan' => $tagihan->Nomor,
                    'JumlahDiterima' => $diterima->KeString(),
                    'Langganan' => $hasil->LanggananBaru($tagihan->IdPaket, $tagihan->Siklus),
                ],
                alasan: $catatan,
                idPelaku: $verifikator->Id,
                idTenant: $tagihan->IdTenant,
            );

            return [$pembayaran, $tagihan];
        });

        $this->KirimPemberitahuan($pembayaran, $tagihan);

        return $pembayaran;
    }

    /** Jangkar grandfathering (BR-P04.1) diteruskan dari tagihan lunas sebelumnya untuk paket yang sama. */
    public static function AmbilMulaiPaketSebelumnya(TagihanLangganan $tagihan): ?CarbonImmutable
    {
        $sebelumnya = DaftarTagihanPlatform::KueriTagihan()
            ->where('IdTenant', $tagihan->IdTenant)
            ->where('Status', StatusTagihanLangganan::Lunas->value)
            ->where('Jenis', '!=', JenisTagihanLangganan::Addon->value)
            ->where('Id', '!=', $tagihan->Id)
            ->orderByDesc('DibayarPada')
            ->orderByDesc('Id')
            ->first();

        return $sebelumnya !== null && $sebelumnya->IdPaket === $tagihan->IdPaket && $sebelumnya->MulaiLanggananPaket !== null
            ? CarbonImmutable::instance($sebelumnya->MulaiLanggananPaket)
            : null;
    }

    private function KirimPemberitahuan(PembayaranLangganan $pembayaran, TagihanLangganan $tagihan): void
    {
        if ($pembayaran->EmailPemberitahuan === null) {
            return;
        }

        try {
            Mail::to($pembayaran->EmailPemberitahuan)->queue(new PembayaranLanggananDiterima(
                nama: $pembayaran->NamaPemberitahuan ?? 'Pemilik usaha',
                nomorTagihan: $tagihan->Nomor,
                total: $tagihan->AmbilTotal()->FormatRupiah(),
                namaPaket: $tagihan->AmbilNamaLayanan(),
                periodeSelesai: $tagihan->PeriodeSelesai?->copy()->setTimezone('Asia/Jakarta')->translatedFormat('j F Y') ?? '—',
            ));
        } catch (Throwable $galat) {
            Log::warning('Email penerimaan pembayaran langganan gagal dikirim.', ['IdTenant' => $tagihan->IdTenant, 'Nomor' => $tagihan->Nomor, 'Galat' => $galat->getMessage()]);
        }
    }
}
