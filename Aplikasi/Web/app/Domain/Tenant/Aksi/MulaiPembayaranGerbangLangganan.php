<?php

declare(strict_types=1);

namespace App\Domain\Tenant\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Integrasi\Billing\HasilPembayaranBilling;
use App\Domain\Integrasi\Billing\NomorPesananBilling;
use App\Domain\Integrasi\Billing\PenyediaTagihanPlatform;
use App\Domain\Integrasi\GerbangPembayaran\GalatGerbang;
use App\Domain\Integrasi\Snap\Enum\MetodeBayarSnap;
use App\Domain\Tenant\Enum\MetodePembayaranLangganan;
use App\Domain\Tenant\Enum\StatusPembayaranLangganan;
use App\Domain\Tenant\Model\Langganan;
use App\Domain\Tenant\Model\PembayaranLangganan;
use App\Domain\Tenant\Model\TagihanLangganan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owner membayar tagihan langganan lewat gerbang pembayaran platform (P-08 langkah 3, BR-P08.11).
 *
 * Alurnya dua tahap dan sengaja tidak dibungkus satu transaksi: baris pembayaran dibuat & di-commit lebih dulu,
 * baru transaksi DOKU Checkout dibuat. Kalau panggilan HTTP ikut di dalam transaksi, kunci baris `Langganan`
 * ditahan selama jaringan lambat — dan yang lebih berbahaya, transaksi bisa dibatalkan **setelah** DOKU
 * menerimanya, sehingga ada tagihan di gerbang yang tidak punya pasangan baris pembayaran dan uang tenant masuk
 * tanpa bisa dicocokkan.
 *
 * Tagihan hanya lunas dari notifikasi webhook bertanda tangan (atau rekonsiliasi status), bukan dari kembalinya peramban
 * tenant dari halaman bayar DOKU.
 */
final class MulaiPembayaranGerbangLangganan
{
    /** Pembayaran SNAP tanpa kode bayar yang lebih muda dari ini masih dianggap sedang diproses, bukan yatim. */
    private const MENIT_TUNGGU_YATIM = 5;

    public function __construct(
        private readonly PenyediaTagihanPlatform $penyedia,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @param  string  $urlKembali  halaman tagihan tenant, tempat DOKU mengarahkan peramban setelah halaman bayar selesai
     */
    public function Jalankan(string $uuidTagihan, int $idPengguna, string $namaPengguna, string $emailPengguna, string $urlKembali): HasilPembayaranBilling
    {
        if (! $this->penyedia->CekCheckoutAktif()) {
            throw new PelanggaranAturanBisnis('GerbangBillingTidakAktif', 'Pembayaran online belum tersedia. Silakan transfer manual dan unggah buktinya.');
        }

        $pembayaran = $this->Siapkan($uuidTagihan, $idPengguna, $namaPengguna, $emailPengguna, null, null)[0];

        return $this->BuatDiGerbang($pembayaran, $namaPengguna, $emailPengguna, $urlKembali);
    }

    /**
     * D-159: pembayaran lewat SNAP (QRIS, virtual account, DANA, ShopeePay), ditampilkan di halaman bayar Payoung.
     * Pembayaran SNAP yang masih berlaku untuk tagihan ini dikembalikan apa adanya (tidak membuat kode bayar kedua).
     *
     * @param  string|null  $ipPelanggan  IPv4 peramban, dibutuhkan e-wallet
     */
    public function JalankanSnap(
        string $uuidTagihan,
        int $idPengguna,
        string $namaPengguna,
        string $emailPengguna,
        string $urlKembali,
        MetodeBayarSnap $metode,
        ?string $kanal,
        ?string $ipPelanggan,
    ): PembayaranLangganan {
        if (! $this->penyedia->CekHalamanBayarAktif()) {
            throw new PelanggaranAturanBisnis('GerbangBillingTidakAktif', 'Pembayaran online belum tersedia. Silakan transfer manual dan unggah buktinya.');
        }

        [$pembayaran, $baru] = $this->Siapkan($uuidTagihan, $idPengguna, $namaPengguna, $emailPengguna, $metode->value, $kanal);

        if (! $baru) {
            return $pembayaran;
        }

        try {
            $hasil = $this->penyedia->BuatPembayaranHalamanBayar($metode, $kanal, (string) $pembayaran->RefGateway, $pembayaran->AmbilJumlah(), $namaPengguna, $emailPengguna, $urlKembali, $ipPelanggan);
        } catch (GalatGerbang $galat) {
            // Sama dengan Checkout: hanya galat yang pasti tidak membuat apa pun yang boleh menutup pembayaran.
            if (! $galat->tidakPasti) {
                $pembayaran->update([
                    'Status' => StatusPembayaranLangganan::Ditolak,
                    'AlasanTolak' => 'Gerbang pembayaran menolak permintaan: '.$galat->getMessage(),
                ]);
            }

            throw new PelanggaranAturanBisnis('GerbangMenolak', $galat->getMessage());
        }

        $pembayaran->update(['RincianBayar' => $hasil->rincian, 'KedaluwarsaPada' => $hasil->kedaluwarsaPada]);

        return $pembayaran;
    }

    /**
     * Membuat baris pembayaran (di-commit sebelum gerbang dipanggil) atau mengembalikan yang masih berlaku.
     *
     * @return array{0: PembayaranLangganan, 1: bool} pembayaran dan apakah baru dibuat
     */
    private function Siapkan(string $uuidTagihan, int $idPengguna, string $namaPengguna, string $emailPengguna, ?string $metodeBayar, ?string $kanal): array
    {
        return DB::transaction(function () use ($uuidTagihan, $idPengguna, $namaPengguna, $emailPengguna, $metodeBayar, $kanal): array {
            $awal = TagihanLangganan::query()->where('Uuid', $uuidTagihan)->first()
                ?? throw new PelanggaranAturanBisnis('TagihanTidakDitemukan', 'Tagihan tidak ditemukan.');
            // Urutan kunci Langganan → Tagihan, sama dengan unggah bukti, verifikasi, dan penjadwal tunggakan.
            Langganan::query()->where('IdTenant', $awal->IdTenant)->lockForUpdate()->first();
            $tagihan = TagihanLangganan::query()->whereKey($awal->Id)->lockForUpdate()->firstOrFail();

            if (! $tagihan->Status->CekTerbuka()) {
                throw new PelanggaranAturanBisnis('TagihanTidakTerbuka', "Tagihan {$tagihan->Nomor} sudah {$tagihan->Status->AmbilLabel()}.");
            }

            // Bukti transfer yang sedang diverifikasi didahulukan: membayar online saat itu berisiko bayar dua kali.
            $adaBuktiManual = PembayaranLangganan::query()
                ->where('IdTagihanLangganan', $tagihan->Id)
                ->where('Metode', MetodePembayaranLangganan::TransferManual->value)
                ->where('Status', StatusPembayaranLangganan::Menunggu->value)
                ->exists();

            if ($adaBuktiManual) {
                throw new PelanggaranAturanBisnis('PembayaranMasihDiverifikasi', 'Bukti transfer tagihan ini sedang diverifikasi. Tunggu hasilnya sebelum membayar online.');
            }

            $berjalan = PembayaranLangganan::query()
                ->where('IdTagihanLangganan', $tagihan->Id)
                ->where('Metode', MetodePembayaranLangganan::Gateway->value)
                ->where('Status', StatusPembayaranLangganan::Menunggu->value)
                ->orderByDesc('Id')
                ->first();

            if ($berjalan !== null && ($metodeBayar !== null || $berjalan->MetodeBayar !== null)) {
                if ($this->CekMasihBerlaku($berjalan)) {
                    // Pembayaran SNAP yang masih berlaku ditampilkan lagi, bukan dibuatkan yang baru: dua kode bayar
                    // untuk satu tagihan berisiko dibayar dua kali. Checkout yang masih berjalan menahan SNAP baru.
                    if ($berjalan->MetodeBayar !== null && $berjalan->RincianBayar !== null) {
                        return [$berjalan->setRelation('TagihanLangganan', $tagihan), false];
                    }

                    if ($berjalan->MetodeBayar === null || $berjalan->DibuatPada === null || $berjalan->DibuatPada->gt(CarbonImmutable::now()->subMinutes(self::MENIT_TUNGGU_YATIM))) {
                        throw new PelanggaranAturanBisnis('PembayaranBerjalan', 'Masih ada pembayaran online untuk tagihan ini yang belum selesai. Selesaikan dulu atau tunggu sampai kedaluwarsa.');
                    }
                }

                // Pembayaran SNAP tanpa rincian (gerbang tidak terjawab sebelum kode bayar tersimpan) yang sudah lama atau
                // sudah lewat batas: ditutup supaya tagihan bisa dibayar ulang.
                if ($berjalan->MetodeBayar !== null && $berjalan->RincianBayar === null) {
                    $berjalan->update(['Status' => StatusPembayaranLangganan::Ditolak, 'AlasanTolak' => 'Kode bayar tidak terbentuk di gerbang pembayaran.']);
                }
            }

            // ULID dibuat di sini, bukan dibiarkan terisi otomatis, supaya nomor pesanan gerbang ikut dalam satu
            // INSERT: tidak ada celah di mana baris pembayaran ada tanpa `RefGateway` pasangannya.
            $uuid = (string) Str::ulid();
            $pembayaran = PembayaranLangganan::query()->create([
                'Uuid' => $uuid,
                'IdTenant' => $tagihan->IdTenant,
                'IdTagihanLangganan' => $tagihan->Id,
                'Metode' => MetodePembayaranLangganan::Gateway,
                'Status' => StatusPembayaranLangganan::Menunggu,
                'Jumlah' => $tagihan->Total,
                'RefGateway' => NomorPesananBilling::Buat($tagihan->IdTenant, $uuid),
                'MetodeBayar' => $metodeBayar,
                'KanalBayar' => $kanal,
                'IdPenggunaPengunggah' => $idPengguna,
                'EmailPemberitahuan' => $emailPengguna,
                'NamaPemberitahuan' => $namaPengguna,
            ]);

            $this->audit->Catat('langganan.pembayaran-gerbang-mulai', $pembayaran, nilaiBaru: [
                'NomorTagihan' => $tagihan->Nomor, 'Jumlah' => $tagihan->Total, 'RefGateway' => $pembayaran->RefGateway,
            ]);

            return [$pembayaran->setRelation('TagihanLangganan', $tagihan), true];
        });
    }

    private function CekMasihBerlaku(PembayaranLangganan $pembayaran): bool
    {
        $batas = $pembayaran->KedaluwarsaPada ?? $pembayaran->DibuatPada?->copy()->addMinutes($this->penyedia->AmbilMenitKedaluwarsaHalamanBayar());

        return $batas !== null && $batas->isFuture();
    }

    /**
     * @throws PelanggaranAturanBisnis
     */
    private function BuatDiGerbang(PembayaranLangganan $pembayaran, string $namaPengguna, string $emailPengguna, string $urlKembali): HasilPembayaranBilling
    {
        try {
            return $this->penyedia->BuatCheckout(
                (string) $pembayaran->RefGateway,
                $pembayaran->AmbilJumlah(),
                $namaPengguna,
                $emailPengguna,
                $urlKembali,
            );
        } catch (GalatGerbang $galat) {
            // Hanya galat yang pasti tidak membuat apa pun di gerbang boleh ditutup. Yang `tidakPasti` dibiarkan
            // `Menunggu` supaya notifikasi webhook yang mungkin menyusul masih menemukan pasangannya.
            if (! $galat->tidakPasti) {
                $pembayaran->update([
                    'Status' => StatusPembayaranLangganan::Ditolak,
                    'AlasanTolak' => 'Gerbang pembayaran menolak permintaan: '.$galat->getMessage(),
                ]);
            }

            throw new PelanggaranAturanBisnis('GerbangMenolak', $galat->getMessage());
        }
    }
}
