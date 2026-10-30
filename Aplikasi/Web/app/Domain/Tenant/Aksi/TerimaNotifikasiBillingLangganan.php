<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\Billing\NotifikasiBilling;
use App\Domain\Integrasi\GerbangPembayaran\StatusPembayaranGerbang;
use App\Domain\Tenant\Enum\JenisTagihanLangganan;
use App\Domain\Tenant\Enum\MetodePembayaranLangganan;
use App\Domain\Tenant\Enum\StatusPembayaranLangganan;
use App\Domain\Tenant\Enum\StatusTagihanLangganan;
use App\Domain\Tenant\Layanan\PelunasTagihanLangganan;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\PembayaranLangganan;
use App\Domain\Tenant\Model\TagihanLangganan;
use App\Domain\Tenant\Peristiwa\TagihanLanggananDilunasiGerbang;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Notifikasi gerbang billing platform (P-08 langkah 3, BR-P08.11): satu-satunya jalan tagihan langganan menjadi
 * `Lunas` lewat pembayaran online. Tanda tangannya sudah diverifikasi pemanggil.
 *
 * - Pelunasannya memakai `PelunasTagihanLangganan`, layanan yang sama dengan verifikasi transfer manual, sehingga
 *   periode & status yang dihasilkan kedua jalur tidak bisa berbeda.
 * - **Idempoten:** DOKU mengirim notifikasi berulang sampai dijawab 200. Pembayaran yang statusnya bukan lagi
 *   `Menunggu` dijawab "sudah diproses" tanpa menyentuh apa pun, jadi periode langganan tidak pernah diperpanjang
 *   dua kali untuk satu pembayaran.
 * - Kunci berurutan Langganan → Tagihan → Pembayaran, sama dengan jalur manual dan penjadwal tunggakan.
 * - **Tenant ditetapkan dari nomor pesanan lalu semua kueri lewat scope `MilikTenant` seperti biasa** (pola yang
 *   sama dengan `PencariGerbangWebhook`), jadi tidak ada kueri lintas tenant: pembayaran tenant lain secara struktural
 *   tidak terlihat, bukan hanya dibandingkan.
 * - Pelakunya sistem, bukan orang, jadi audit ditulis ke `LogAudit` tenant tanpa pengguna — bukan ke
 *   `LogAuditPengelola` yang dipakai jalur manual, karena di sana memang ada verifikator yang bertanggung jawab.
 */
final class TerimaNotifikasiBillingLangganan
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PelunasTagihanLangganan $pelunas,
        private readonly PencatatAudit $audit,
    ) {}

    /** @return bool `false` = notifikasi tidak dikenal atau tidak bisa diproses; tetap dijawab 200 agar tidak diulang. */
    public function Jalankan(NotifikasiBilling $notifikasi): bool
    {
        $this->konteks->Atur($notifikasi->idTenant);
        // Pelakunya sistem, jadi konteks audit dikosongkan **secara eksplisit**. Tanpa ini pelaku dari request atau job
        // lain di proses yang sama bisa terbawa dan tercatat sebagai orang yang melunasi tagihan — persis yang
        // tertangkap test: pemilik yang menekan "Bayar online" ikut tercatat di audit notifikasi webhook.
        $this->audit->AturKonteks(null, null, null);
        $awal = PembayaranLangganan::query()->where('RefGateway', $notifikasi->nomorPesanan)->first();

        if ($awal === null || $awal->Metode !== MetodePembayaranLangganan::Gateway) {
            Log::warning('Notifikasi gerbang billing tanpa pembayaran yang cocok.', ['NomorPesanan' => $notifikasi->nomorPesanan, 'Status' => $notifikasi->statusAsli]);

            return false;
        }

        // `PENDING` dan status lain yang belum final: belum ada uang yang pasti masuk.
        if ($notifikasi->status === StatusPembayaranGerbang::Menunggu) {
            return true;
        }

        try {
            return DB::transaction(fn (): bool => $this->Proses($awal, $notifikasi));
        } catch (PelanggaranAturanBisnis $galat) {
            // Misal jumlah yang dibayar tidak sama dengan total tagihan: tidak boleh dilunasi otomatis, dan tidak ada
            // gunanya diulang. Pembayaran dibiarkan `Menunggu` supaya muncul di antrean verifikasi manual.
            Log::error('Notifikasi gerbang billing tidak bisa diproses otomatis.', [
                'NomorPesanan' => $notifikasi->nomorPesanan,
                'IdTenant' => $awal->IdTenant,
                'Status' => $notifikasi->statusAsli,
                'Jumlah' => $notifikasi->jumlah,
                'Kode' => $galat->kode,
                'Pesan' => $galat->getMessage(),
            ]);

            return false;
        }
    }

    private function Proses(PembayaranLangganan $awal, NotifikasiBilling $notifikasi): bool
    {
        $langganan = Langganan::query()->where('IdTenant', $awal->IdTenant)->lockForUpdate()->first();
        $tagihan = TagihanLangganan::query()->with('Paket')->whereKey($awal->IdTagihanLangganan)->lockForUpdate()->firstOrFail();
        $pembayaran = PembayaranLangganan::query()->whereKey($awal->Id)->lockForUpdate()->firstOrFail();

        // Notifikasi ulang untuk pembayaran yang sudah selesai: sudah diproses, tidak ada yang perlu diubah.
        if ($pembayaran->Status !== StatusPembayaranLangganan::Menunggu) {
            // Uang yang masuk untuk pembayaran yang sudah kami tutup `Ditolak` (kedaluwarsa atau gagal lebih dulu, lalu
            // pembeli tetap membayar) tidak boleh hilang tanpa jejak.
            if ($notifikasi->status !== StatusPembayaranGerbang::Lunas || $pembayaran->Status !== StatusPembayaranLangganan::Ditolak) {
                return true;
            }

            $penerus = $this->BuatPembayaranPenerus($pembayaran, $tagihan, $notifikasi);

            if ($penerus === null) {
                return true;
            }

            // Pembayaran yang sudah `Ditolak` bersifat final (tidak boleh dibuka kembali), jadi uang yang datang terlambat
            // dilunasi lewat pembayaran penerus bernilai sama untuk tagihan yang sama.
            $pembayaran = $penerus;
        }

        if ($notifikasi->status !== StatusPembayaranGerbang::Lunas) {
            $pembayaran->update([
                'Status' => StatusPembayaranLangganan::Ditolak,
                'AlasanTolak' => "Pembayaran online tidak selesai (status gerbang: {$notifikasi->statusAsli}).",
            ]);
            $this->audit->Catat(
                'langganan.pembayaran-gerbang-gagal',
                $pembayaran,
                nilaiLama: ['Pembayaran' => StatusPembayaranLangganan::Menunggu->value],
                nilaiBaru: ['Pembayaran' => StatusPembayaranLangganan::Ditolak->value, 'StatusGerbang' => $notifikasi->statusAsli, 'IdTransaksiGerbang' => $notifikasi->idTransaksi],
                idTenant: $pembayaran->IdTenant,
            );

            return true;
        }

        if ($langganan === null) {
            throw new PelanggaranAturanBisnis('LanggananTidakAda', 'Langganan tenant ini tidak ditemukan.');
        }

        // Respons status DOKU yang tidak memuat jumlah: transaksi dibuat dengan jumlah tetap dari pembayaran ini.
        $diterima = $notifikasi->jumlah === '' ? $pembayaran->AmbilJumlah() : Uang::Dari($notifikasi->jumlah);
        $hasil = $this->pelunas->Lunasi(
            $langganan,
            $tagihan,
            $pembayaran,
            $diterima,
            CarbonImmutable::now(),
            idVerifikatorPengelola: null,
            mulaiPaketSebelumnya: $this->AmbilMulaiPaketSebelumnya($tagihan),
        );

        $this->audit->Catat(
            'langganan.pembayaran-gerbang-lunas',
            $pembayaran,
            nilaiLama: ['Pembayaran' => StatusPembayaranLangganan::Menunggu->value, 'Tagihan' => $hasil->statusTagihanLama, 'Langganan' => $hasil->langgananLama],
            nilaiBaru: [
                'Pembayaran' => StatusPembayaranLangganan::Diterima->value,
                'NomorTagihan' => $tagihan->Nomor,
                'JumlahDiterima' => $diterima->KeString(),
                'IdTransaksiGerbang' => $notifikasi->idTransaksi,
                'Langganan' => $hasil->LanggananBaru($tagihan->IdPaket, $tagihan->Siklus),
            ],
            idTenant: $tagihan->IdTenant,
        );

        $this->BeritahuOwner($pembayaran, $tagihan);

        return true;
    }

    /**
     * Uang masuk untuk pembayaran yang sudah ditutup `Ditolak` (pembayaran final, tidak boleh dibuka kembali).
     * - Tagihan masih terbuka: dibuat pembayaran penerus (`RefGateway` = referensi lama + `+T`, jumlah sama) berstatus
     *   `Menunggu`, lalu dilunasi lewat jalur biasa. Jumlah tetap diperiksa `PelunasTagihanLangganan`; bila tidak cocok
     *   seluruh transaksi dibatalkan dan pembayaran lama tetap `Ditolak`.
     * - Tagihan sudah lunas atau ditutup (pembeli membayar dua kali): tidak ada yang diubah, tetapi kejadiannya dicatat di
     *   audit dan log galat supaya Keuangan bisa mengembalikan uangnya.
     * - Penerus sudah pernah dibuat (notifikasi diulang gerbang): tanpa efek dan tanpa catatan tambahan.
     */
    private function BuatPembayaranPenerus(PembayaranLangganan $lama, TagihanLangganan $tagihan, NotifikasiBilling $notifikasi): ?PembayaranLangganan
    {
        $refPenerus = $lama->RefGateway.'+T';

        if (PembayaranLangganan::query()->where('RefGateway', $refPenerus)->exists()) {
            return null;
        }

        if ($tagihan->Status->CekTerbuka()) {
            $penerus = PembayaranLangganan::query()->create([
                'Uuid' => (string) Str::ulid(),
                'IdTenant' => $lama->IdTenant,
                'IdTagihanLangganan' => $tagihan->Id,
                'Metode' => MetodePembayaranLangganan::Gateway,
                'Status' => StatusPembayaranLangganan::Menunggu,
                'Jumlah' => $lama->Jumlah,
                'RefGateway' => $refPenerus,
                'MetodeBayar' => $lama->MetodeBayar,
                'KanalBayar' => $lama->KanalBayar,
                'IdPenggunaPengunggah' => $lama->IdPenggunaPengunggah,
                'EmailPemberitahuan' => $lama->EmailPemberitahuan,
                'NamaPemberitahuan' => $lama->NamaPemberitahuan,
            ]);
            $this->audit->Catat(
                'langganan.pembayaran-gerbang-lunas-terlambat',
                $penerus,
                nilaiLama: ['PembayaranDitolak' => $lama->RefGateway],
                nilaiBaru: ['RefGateway' => $refPenerus, 'StatusGerbang' => $notifikasi->statusAsli, 'IdTransaksiGerbang' => $notifikasi->idTransaksi],
                idTenant: $lama->IdTenant,
            );

            return $penerus;
        }

        Log::error('Pembayaran gerbang lunas masuk untuk tagihan yang sudah tidak terbuka. Uang perlu dikembalikan.', [
            'NomorPesanan' => $notifikasi->nomorPesanan,
            'IdTenant' => $lama->IdTenant,
            'NomorTagihan' => $tagihan->Nomor,
            'StatusTagihan' => $tagihan->Status->value,
            'Jumlah' => $notifikasi->jumlah,
            'IdTransaksiGerbang' => $notifikasi->idTransaksi,
        ]);
        $this->audit->Catat(
            'langganan.pembayaran-gerbang-lunas-tak-terpakai',
            $lama,
            nilaiBaru: [
                'Pembayaran' => $lama->Status->value,
                'NomorTagihan' => $tagihan->Nomor,
                'StatusTagihan' => $tagihan->Status->value,
                'JumlahDiterimaGerbang' => $notifikasi->jumlah,
                'IdTransaksiGerbang' => $notifikasi->idTransaksi,
            ],
            idTenant: $lama->IdTenant,
        );

        return null;
    }

    /** Jangkar grandfathering (BR-P04.1) diteruskan dari tagihan lunas sebelumnya untuk paket yang sama. */
    private function AmbilMulaiPaketSebelumnya(TagihanLangganan $tagihan): ?CarbonImmutable
    {
        $sebelumnya = TagihanLangganan::query()
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

    private function BeritahuOwner(PembayaranLangganan $pembayaran, TagihanLangganan $tagihan): void
    {
        if ($pembayaran->EmailPemberitahuan === null) {
            return;
        }

        TagihanLanggananDilunasiGerbang::dispatch(
            $tagihan->IdTenant,
            $tagihan->Nomor,
            $pembayaran->EmailPemberitahuan,
            $pembayaran->NamaPemberitahuan ?? 'Pemilik usaha',
            $tagihan->AmbilTotal()->FormatRupiah(),
            $tagihan->AmbilNamaLayanan(),
            $tagihan->PeriodeSelesai?->copy()->setTimezone('Asia/Jakarta')->translatedFormat('j F Y') ?? '—',
        );
    }
}
