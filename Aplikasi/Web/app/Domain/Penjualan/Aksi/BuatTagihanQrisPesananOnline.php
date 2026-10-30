<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Integrasi\GerbangPembayaran\GalatGerbang;
use App\Domain\Integrasi\GerbangPembayaran\PembuatGerbangPembayaran;
use App\Domain\Integrasi\GerbangPembayaran\PermintaanQris;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Enum\StatusPesananOnline;
use App\Domain\Penjualan\Enum\StatusTagihanQris;
use App\Domain\Penjualan\Enum\SumberTagihanQris;
use App\Domain\Penjualan\Layanan\NomorPesananQris;
use App\Domain\Penjualan\Model\MetodePembayaran;
use App\Domain\Penjualan\Model\PesananOnline;
use App\Domain\Penjualan\Model\TagihanQris;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * F-17 toko online bagian 2: pelanggan meminta QRIS untuk membayar pesanannya sendiri dari web, lewat gerbang
 * pembayaran milik toko (D-19). Tagihannya ber-`Sumber` `TokoOnline` dan **tanpa perangkat**, karena tidak ada
 * kasir yang membuatnya.
 *
 * Idempoten per pesanan, bukan per Uuid peramban: satu pesanan hanya boleh punya **satu tagihan hidup**. Halaman bayar
 * yang dimuat ulang (atau dua tab) mendapat QR yang sama, sehingga pelanggan tidak pernah bisa membayar dua kali untuk
 * pesanan yang sama. Tagihan yang sudah `Lunas` dikembalikan apa adanya.
 *
 * Jumlahnya **dibulatkan ke bawah ke rupiah penuh** karena QRIS tidak mengenal sen. Dibulatkan ke bawah, bukan ke
 * atas, supaya toko tidak pernah menahan uang yang tidak ditagihkan: sisa kurang dari satu rupiah ditagih kasir
 * bersama tagihan akhirnya, sedangkan pembulatan ke atas akan meninggalkan sisa uang muka yang harus diurus manual
 * di setiap pesanan bersen.
 *
 * "Satu tagihan hidup" ditegakkan dengan kunci per pesanan: pencarian tagihan hidup sampai tagihan dibuat berlangsung
 * di dalam kunci, karena panggilan ke gerbang memakan waktu (detik) dan selama itu baris cadangan belum punya QR
 * sehingga tidak terlihat oleh permintaan kedua. Tanpa kunci, klik ganda atau dua tab membuat dua QRIS untuk satu
 * pesanan dan pembeli bisa membayar keduanya. Permintaan kedua menunggu, lalu mendapat QR yang sama.
 *
 * Galat: 409 `PesananTidakMenungguPembayaran`, 409 `GerbangBelumAktif`, 409 `MetodeQrisBelumAda`,
 * 422 `JumlahTidakValid`, 429 `SedangDiproses`, 502 `GerbangGagal`/`GerbangTidakPasti`.
 */
final class BuatTagihanQrisPesananOnline
{
    /** Cukup lama untuk membuka aplikasi bank, cukup singkat untuk tidak menahan stok pesanan lain. */
    public const MENIT_BERLAKU = 15;

    /** Umur kunci per pesanan (melebihi batas waktu panggilan gerbang) dan lama permintaan kedua menunggu. */
    private const DETIK_KUNCI = 60;

    private const DETIK_TUNGGU_KUNCI = 20;

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PembuatGerbangPembayaran $pembuatGerbang,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @return array{0: TagihanQris, 1: bool} [tagihan, baru dibuat?]
     */
    public function Jalankan(PesananOnline $pesanan): array
    {
        try {
            return Cache::lock("tagihan-qris-online:{$pesanan->IdTenant}:{$pesanan->Id}", self::DETIK_KUNCI)
                ->block(self::DETIK_TUNGGU_KUNCI, fn (): array => $this->Proses($pesanan));
        } catch (LockTimeoutException) {
            throw new PelanggaranAturanBisnis('SedangDiproses', 'QRIS pesanan ini sedang dibuat. Tunggu sebentar lalu muat ulang halaman.', 'Umum', 429);
        }
    }

    /**
     * @return array{0: TagihanQris, 1: bool}
     */
    private function Proses(PesananOnline $pesanan): array
    {
        // Dimuat ulang di dalam kunci: pesanan bisa baru saja dibatalkan atau dibayar oleh permintaan lain.
        $pesanan->refresh();
        $hidup = $this->CariHidup($pesanan);

        if ($hidup !== null) {
            return [$hidup, false];
        }

        if ($pesanan->Status !== StatusPesananOnline::MenungguPembayaran) {
            throw new PelanggaranAturanBisnis(
                'PesananTidakMenungguPembayaran',
                "Pesanan {$pesanan->Nomor} berstatus {$pesanan->Status->AmbilLabel()}, jadi tidak ada yang perlu dibayar.",
                'Umum',
                409,
            );
        }

        $idTenant = $this->konteks->Wajib();
        $gerbangTenant = $this->pembuatGerbang->AmbilAktifTenant()
            ?? throw new PelanggaranAturanBisnis('GerbangBelumAktif', 'Pembayaran QRIS sedang tidak tersedia. Hubungi toko.', 'Umum', 409);
        $gerbang = $gerbangTenant->gerbang;

        $metode = MetodePembayaran::query()->where('Jenis', JenisMetodePembayaran::QrisDinamis->value)->where('Aktif', true)->orderBy('Urutan')->first()
            ?? throw new PelanggaranAturanBisnis('MetodeQrisBelumAda', 'Pembayaran QRIS sedang tidak tersedia. Hubungi toko.', 'Umum', 409);

        $jumlah = $pesanan->AmbilTotal()->BulatkanKeKelipatan(1, RoundingMode::Down);

        if ($jumlah->Bandingkan(Uang::Dari(1)) < 0) {
            throw new PelanggaranAturanBisnis('JumlahTidakValid', 'Nilai pesanan terlalu kecil untuk dibayar lewat QRIS.', 'Umum');
        }

        $uuid = (string) Str::ulid();
        $nomor = NomorPesananQris::Buat($idTenant, $uuid);
        $keterangan = mb_substr("Pesanan {$pesanan->Nomor}", 0, 100);
        $kedaluwarsa = CarbonImmutable::now()->addMinutes(self::MENIT_BERLAKU);

        $tagihan = TagihanQris::query()->create([
            'Uuid' => $uuid,
            'IdOutlet' => $pesanan->IdOutlet,
            'IdPerangkat' => null,
            'Sumber' => SumberTagihanQris::TokoOnline,
            'IdPesananOnline' => $pesanan->Id,
            'IdMetodePembayaran' => $metode->Id,
            'NomorPesanan' => $nomor,
            'Penyedia' => $gerbang->AmbilKode(),
            'IsiQr' => '',
            'Jumlah' => $jumlah->KeString(),
            'Keterangan' => $keterangan,
            'Status' => StatusTagihanQris::Menunggu,
            'KedaluwarsaPada' => $kedaluwarsa,
        ]);

        try {
            $hasil = $gerbang->BuatQris(new PermintaanQris(
                nomorPesanan: $nomor,
                jumlah: $jumlah,
                keterangan: $keterangan,
                kedaluwarsaPada: $kedaluwarsa,
                urlNotifikasi: $gerbangTenant->urlNotifikasi,
            ));
        } catch (GalatGerbang $galat) {
            if (! $galat->tidakPasti) {
                $tagihan->delete();

                throw new PelanggaranAturanBisnis('GerbangGagal', 'Pembayaran QRIS gagal dibuat. Coba lagi.', 'Umum', 502);
            }

            $this->TandaiTidakPasti($tagihan, $galat->getMessage());

            throw self::GalatTidakPasti(502);
        } catch (Throwable $galat) {
            $this->TandaiTidakPasti($tagihan, 'Masalah tak terduga saat menghubungi gerbang.');

            throw $galat;
        }

        $tagihan->forceFill([
            'IdReferensi' => $hasil->idReferensi === '' ? null : mb_substr($hasil->idReferensi, 0, 100),
            'IsiQr' => $hasil->isiQr,
            'HalamanBayar' => $hasil->halamanBayar,
            'KedaluwarsaPada' => $hasil->kedaluwarsaPada !== null && $hasil->kedaluwarsaPada->lessThan($kedaluwarsa) ? $hasil->kedaluwarsaPada : $kedaluwarsa,
        ])->save();
        $this->riwayat->Catat(TagihanQris::JENIS_DOKUMEN, $tagihan->Id, null, StatusTagihanQris::Menunggu->value, null, "Pesanan online {$pesanan->Nomor}");
        $this->audit->Catat('tagihan-qris.buat-online', $tagihan, nilaiBaru: [
            'NomorPesanan' => $nomor,
            'NomorPesananOnline' => $pesanan->Nomor,
            'Penyedia' => $tagihan->Penyedia,
            'Jumlah' => $tagihan->Jumlah,
        ], idTenant: $pesanan->IdTenant);

        return [$tagihan, true];
    }

    /**
     * Tagihan pesanan ini yang masih berguna: sudah `Lunas` (jawaban akhir) atau `Menunggu` dengan QR siap tampil dan
     * belum lewat waktu. Tagihan yang hasilnya `TidakPasti` tidak pernah ditampilkan ke pelanggan — uangnya diurus
     * `RekonsiliasiTagihanQris`, dan pelanggan dibuatkan tagihan baru.
     */
    private function CariHidup(PesananOnline $pesanan): ?TagihanQris
    {
        $lunas = TagihanQris::query()->where('IdPesananOnline', $pesanan->Id)->where('Status', StatusTagihanQris::Lunas->value)->first();

        if ($lunas instanceof TagihanQris) {
            return $lunas;
        }

        return TagihanQris::query()
            ->where('IdPesananOnline', $pesanan->Id)
            ->where('Status', StatusTagihanQris::Menunggu->value)
            ->where('IsiQr', '!=', '')
            ->where('KedaluwarsaPada', '>', now())
            ->orderByDesc('Id')
            ->first();
    }

    /** Cadangan yang hasilnya di gerbang tidak pasti: disimpan (bukan dihapus) untuk rekonsiliasi. Idempoten. */
    private function TandaiTidakPasti(TagihanQris $tagihan, string $pesan): void
    {
        $diubah = TagihanQris::query()->whereKey($tagihan->Id)->where('Status', StatusTagihanQris::Menunggu->value)->where('IsiQr', '')
            ->update([
                'Status' => StatusTagihanQris::TidakPasti->value,
                'PesanGalatGerbang' => mb_substr($pesan, 0, 300),
                'DiubahPada' => now(),
            ]);

        if ($diubah !== 1) {
            return;
        }

        $tagihan->refresh();
        $this->riwayat->Catat(TagihanQris::JENIS_DOKUMEN, $tagihan->Id, StatusTagihanQris::Menunggu->value, StatusTagihanQris::TidakPasti->value, null, 'Hasil gerbang tidak pasti');
        $this->audit->Catat('tagihan-qris.tidak-pasti', $tagihan, nilaiBaru: [
            'NomorPesanan' => $tagihan->NomorPesanan,
            'Penyedia' => $tagihan->Penyedia,
            'Jumlah' => $tagihan->Jumlah,
        ], idTenant: $tagihan->IdTenant);
    }

    private static function GalatTidakPasti(int $statusHttp): PelanggaranAturanBisnis
    {
        return new PelanggaranAturanBisnis(
            'GerbangTidakPasti',
            'Koneksi ke penyedia pembayaran terputus sebelum QRIS diterima. Muat ulang halaman ini untuk mendapat QRIS baru. Kalau uang Anda sudah terpotong, hubungi toko.',
            'Umum',
            $statusHttp,
        );
    }
}
