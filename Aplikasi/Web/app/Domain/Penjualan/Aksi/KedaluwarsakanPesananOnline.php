<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Penjualan\Enum\PeristiwaPesananOnline;
use App\Domain\Penjualan\Enum\StatusPesananOnline;
use App\Domain\Penjualan\Enum\StatusTagihanQris;
use App\Domain\Penjualan\Enum\SumberPesananOnline;
use App\Domain\Penjualan\Layanan\PemberitahuPesananOnline;
use App\Domain\Penjualan\Model\PengaturanTokoOnline;
use App\Domain\Penjualan\Model\PesananOnline;
use App\Domain\Penjualan\Model\TagihanQris;
use App\Domain\Persediaan\Layanan\PencadangStok;
use App\Domain\Promo\Aksi\LepasVoucherPos;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * F-17 toko online: pesanan yang tidak pernah dikonfirmasi staf dihanguskan setelah
 * `PengaturanTokoOnline.MenitKedaluwarsa`, supaya jatah "pesanan aktif" pelanggan tidak terkunci selamanya dan
 * daftar staf tidak menumpuk pesanan mati. Hanya `MenungguKonfirmasi` yang bisa hangus: begitu staf mengonfirmasi,
 * pesanan sudah masuk antrean dapur/kemas dan hanya staf yang boleh membatalkannya.
 *
 * F-17 bagian 2: pesanan `MenungguPembayaran` juga dihanguskan, tetapi memakai batasnya sendiri —
 * `BuatTagihanQrisPesananOnline::MENIT_BERLAKU` + `MENIT_TENGGANG_BAYAR` — bukan batas konfirmasi staf. Alasannya
 * beda: yang menahan pesanan itu QR yang sudah tidak berlaku, bukan staf yang belum menjawab, dan menahannya
 * dua jam hanya membuang jatah pesanan aktif pelanggan. Pesanan yang **sudah dibayar** tidak pernah hangus, meski
 * uangnya masuk setelah QR-nya lewat waktu (`DibayarPada` diisi `PenerapPembayaranPesananOnline`).
 *
 * Batas itu dihitung dari **QR terakhir**, bukan dari pesanan dibuat: pembeli bisa membuka halaman bayar belasan menit
 * setelah memesan (QR baru dibuat saat itu), jadi pesanan yang punya tagihan QRIS masih hidup (`Menunggu` belum lewat
 * kedaluwarsanya + tenggang, atau `TidakPasti` yang masih direkonsiliasi) tidak dihanguskan.
 *
 * Tidak ada efek stok maupun jurnal: pesanan yang belum ditagihkan sebagai `Penjualan` belum pernah menyentuh
 * keduanya. Uang muka yang sudah masuk tetap menjadi kewajiban yang harus dikembalikan lewat
 * `KembalikanUangPesananOnline`; menghanguskan pesanan tidak pernah menghanguskan uangnya. Dijalankan tanpa pengguna
 * (pelaku sistem), jadi `RiwayatStatusDokumen.DiubahOleh` null.
 */
final class KedaluwarsakanPesananOnline
{
    /** Tenggang setelah QR tidak berlaku, supaya notifikasi gerbang yang sedikit terlambat tetap menang. */
    public const MENIT_TENGGANG_BAYAR = 10;

    /** F-17 bagian 4: pesanan kios yang tidak pernah dijawab kasir hangus lebih cepat; stoknya ikut dicadangkan. */
    public const MENIT_KEDALUWARSA_KIOS = 30;

    public function __construct(
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
        private readonly PemberitahuPesananOnline $pemberitahu,
        private readonly LepasVoucherPos $lepasVoucher,
        private readonly PencadangStok $pencadang,
    ) {}

    /** @return int jumlah pesanan yang dihanguskan */
    public function Jalankan(): int
    {
        $menit = PengaturanTokoOnline::query()->value('MenitKedaluwarsa');
        $jumlah = 0;

        foreach (self::BATAS as $status => $hitungMenit) {
            // Toko online memakai batas dari pengaturan toko; kios punya batas sendiri dan tidak butuh toko online aktif.
            $menitWeb = $hitungMenit ?? ($menit === null ? null : (int) $menit);
            $menitKios = $hitungMenit ?? self::MENIT_KEDALUWARSA_KIOS;

            foreach ([[SumberPesananOnline::Web, $menitWeb], [SumberPesananOnline::Kios, $menitKios]] as [$sumber, $hitung]) {
                if ($hitung === null) {
                    continue;
                }

                $batas = now()->subMinutes($hitung);
                $pesanan = PesananOnline::query()
                    ->where('Status', $status)
                    ->where('Sumber', $sumber->value)
                    ->whereNull('DibayarPada')
                    ->where('DibuatPada', '<', $batas)
                    ->orderBy('Id')
                    ->get();

                foreach ($pesanan as $satu) {
                    $jumlah += $this->Hanguskan($satu->Id, $batas);
                }
            }
        }

        return $jumlah;
    }

    /**
     * Dibaca ulang di bawah kunci: staf bisa mengonfirmasi, atau pembayaran bisa masuk, saat jadwal berjalan.
     * Pembayaran selalu menang — uang yang sudah diterima tidak boleh punya pesanan yang hangus.
     */
    private function Hanguskan(int $id, Carbon $batas): int
    {
        return DB::transaction(function () use ($id, $batas): int {
            $pesanan = PesananOnline::query()->whereKey($id)->lockForUpdate()->first();

            if (! $pesanan instanceof PesananOnline
                || ! array_key_exists($pesanan->Status->value, self::BATAS)
                || $pesanan->DibayarPada !== null
                || $pesanan->DibuatPada === null
                || $pesanan->DibuatPada->greaterThanOrEqualTo($batas)
                || $this->CekAdaTagihanQrisHidup($pesanan)) {
                return 0;
            }

            $dari = $pesanan->Status;
            $alasan = $dari === StatusPesananOnline::MenungguPembayaran
                ? 'Tidak dibayar sampai batas waktu QRIS.'
                : 'Tidak dikonfirmasi sampai batas waktu toko.';
            $pesanan->UbahStatus(StatusPesananOnline::Kedaluwarsa);
            $pesanan->Alasan = $alasan;
            $pesanan->save();

            if ($pesanan->KodeVoucher !== null) {
                $this->lepasVoucher->Jalankan($pesanan->KodeVoucher, $pesanan->Uuid);
            }
            $this->pencadang->Lepas(PencadangStok::SUMBER_PESANAN_ONLINE, $pesanan->Uuid);
            $this->riwayat->Catat(PesananOnline::JENIS_DOKUMEN, $pesanan->Id, $dari->value, StatusPesananOnline::Kedaluwarsa->value, null, $alasan);
            $this->pemberitahu->Antrekan($pesanan, PeristiwaPesananOnline::Kedaluwarsa);
            $this->audit->Catat(
                'pesanan-online.kedaluwarsa',
                $pesanan,
                ['Status' => $dari->value],
                ['Status' => StatusPesananOnline::Kedaluwarsa->value, 'Alasan' => $alasan],
                idTenant: $pesanan->IdTenant,
            );

            return 1;
        });
    }

    /**
     * Pesanan yang menunggu pembayaran dan punya tagihan QRIS yang masih bisa dibayar (atau belum pasti nasibnya)
     * tidak boleh hangus: uang bisa masuk kapan saja sampai tagihannya lewat tenggang.
     */
    private function CekAdaTagihanQrisHidup(PesananOnline $pesanan): bool
    {
        if ($pesanan->Status !== StatusPesananOnline::MenungguPembayaran) {
            return false;
        }

        return TagihanQris::query()
            ->where('IdPesananOnline', $pesanan->Id)
            ->where(function ($kueri): void {
                $kueri->where('Status', StatusTagihanQris::TidakPasti->value)
                    ->orWhere(function ($menunggu): void {
                        $menunggu->where('Status', StatusTagihanQris::Menunggu->value)
                            ->where('KedaluwarsaPada', '>=', now()->subMinutes(self::MENIT_TENGGANG_BAYAR));
                    });
            })
            ->exists();
    }

    /** Status yang bisa hangus → menit menunggu (null = `PengaturanTokoOnline.MenitKedaluwarsa`). */
    public const BATAS = [
        'MenungguPembayaran' => BuatTagihanQrisPesananOnline::MENIT_BERLAKU + self::MENIT_TENGGANG_BAYAR,
        'MenungguKonfirmasi' => null,
    ];
}
