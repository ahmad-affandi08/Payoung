<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Layanan;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Integrasi\GerbangPembayaran\StatusPembayaranGerbang;
use App\Domain\Penjualan\Enum\StatusTagihanQris;
use App\Domain\Penjualan\Model\TagihanQris;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Menerapkan status dari gerbang (webhook atau cek status, F-08 BR-08.5) ke satu tagihan QRIS di transaksi DB dengan
 * kunci baris, sehingga notifikasi berulang/bersamaan idempoten:
 * - `Lunas` hanya bila jumlah yang dilaporkan gerbang (bila ada) sama dengan `Jumlah` tagihan; beda jumlah = status
 *   tidak berubah, dicatat sebagai peringatan log + `LogAudit` `tagihan-qris.jumlah-berbeda` untuk ditelusuri.
 * - `Kedaluwarsa`/`Gagal` hanya dari `Menunggu`; tagihan `Lunas` tidak pernah berubah lagi.
 * - Tagihan kedaluwarsa/gagal/dibatalkan/tidak pasti yang ternyata dibayar tetap menjadi `Lunas` (uang nyata
 *   diterima) dan ditandai `PerluTinjauan` untuk Kotak Tindakan (audit P0 F-02).
 * Setiap perubahan dicatat di `RiwayatStatusDokumen` (tanpa pengguna; sumber di alasan).
 *
 * F-17 bagian 2: tagihan milik `PesananOnline` yang menjadi `Lunas` langsung membukukan uang mukanya (J-17.1) lewat
 * `PenerapPembayaranPesananOnline` **di transaksi yang sama** (aturan #10). Pesanan yang sudah berstatus akhir
 * (hangus/dibatalkan) tetap dibukukan tanpa dihidupkan kembali: uangnya nyata diterima dan harus terlihat sebagai
 * kewajiban sampai dikembalikan.
 */
final class PenerapStatusTagihanQris
{
    public function __construct(
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
        private readonly PenerapPembayaranPesananOnline $pembayaranOnline,
    ) {}

    public function Terapkan(int $idTagihan, StatusPembayaranGerbang $statusGerbang, ?string $jumlahGerbang, string $sumber): TagihanQris
    {
        return DB::transaction(function () use ($idTagihan, $statusGerbang, $jumlahGerbang, $sumber): TagihanQris {
            $tagihan = TagihanQris::query()->whereKey($idTagihan)->lockForUpdate()->firstOrFail();
            $tujuan = match ($statusGerbang) {
                StatusPembayaranGerbang::Lunas => StatusTagihanQris::Lunas,
                StatusPembayaranGerbang::Kedaluwarsa => StatusTagihanQris::Kedaluwarsa,
                StatusPembayaranGerbang::Gagal => StatusTagihanQris::Gagal,
                StatusPembayaranGerbang::Menunggu => null,
            };

            if ($tujuan === null || ! $tagihan->Status->BisaBerubahKe($tujuan)) {
                return $tagihan;
            }

            $jumlah = Uang::Dari($tagihan->Jumlah);
            $diterima = $jumlah;

            if ($tujuan === StatusTagihanQris::Lunas && $jumlahGerbang !== null && trim($jumlahGerbang) !== '') {
                $dilaporkan = self::UraiJumlah($jumlahGerbang);

                if ($dilaporkan === null || ! $dilaporkan->SamaDengan($jumlah)) {
                    Log::warning('Jumlah QRIS dinamis dari gerbang berbeda dengan tagihan. Status tidak diubah.', [
                        'NomorPesanan' => $tagihan->NomorPesanan,
                        'Jumlah' => $tagihan->Jumlah,
                        'JumlahGerbang' => mb_substr($jumlahGerbang, 0, 30),
                        'Sumber' => $sumber,
                    ]);
                    $this->audit->Catat('tagihan-qris.jumlah-berbeda', $tagihan, nilaiBaru: [
                        'NomorPesanan' => $tagihan->NomorPesanan,
                        'Jumlah' => $tagihan->Jumlah,
                        'JumlahGerbang' => mb_substr($jumlahGerbang, 0, 30),
                        'Sumber' => $sumber,
                    ], idTenant: $tagihan->IdTenant);

                    return $tagihan;
                }

                $diterima = $dilaporkan;
            }

            $asal = $tagihan->Status;
            $tagihan->Status = $tujuan;

            if ($tujuan === StatusTagihanQris::Lunas) {
                $tagihan->LunasPada = now();
                $tagihan->JumlahDiterima = $diterima->KeString();
            }

            $tagihan->save();
            $this->riwayat->Catat(TagihanQris::JENIS_DOKUMEN, $tagihan->Id, $asal->value, $tujuan->value, null, $sumber);

            if ($asal !== StatusTagihanQris::Menunggu && $tujuan === StatusTagihanQris::Lunas) {
                Log::warning('Tagihan QRIS dinamis dibayar setelah berstatus akhir. Periksa penjualan terkait.', [
                    'NomorPesanan' => $tagihan->NomorPesanan,
                    'StatusAsal' => $asal->value,
                ]);
                $tagihan->PerluTinjauan = true;
                $tagihan->AlasanTinjauan = mb_substr("Uang QRIS diterima setelah tagihan berstatus {$asal->value}. Cocokkan dengan penjualan atau kembalikan dana ke pelanggan.", 0, 255);
                $tagihan->save();
            }

            $this->audit->Catat('tagihan-qris.status', $tagihan, ['Status' => $asal->value], ['Status' => $tujuan->value, 'Sumber' => $sumber], idTenant: $tagihan->IdTenant);

            if ($tujuan === StatusTagihanQris::Lunas) {
                $this->pembayaranOnline->Terapkan($tagihan);
            }

            return $tagihan;
        });
    }

    /**
     * Menandai `Kedaluwarsa` tagihan `Menunggu`/`TidakPasti` yang lewat batas + tenggang (tanpa kabar gerbang). Bukan
     * status final: pembayaran yang ternyata masuk tetap menjadikannya `Lunas`.
     */
    public function TandaiKedaluwarsa(int $idTagihan): TagihanQris
    {
        return DB::transaction(function () use ($idTagihan): TagihanQris {
            $tagihan = TagihanQris::query()->whereKey($idTagihan)->lockForUpdate()->firstOrFail();

            if (! in_array($tagihan->Status, [StatusTagihanQris::Menunggu, StatusTagihanQris::TidakPasti], true)) {
                return $tagihan;
            }

            $asal = $tagihan->Status;
            $tagihan->Status = StatusTagihanQris::Kedaluwarsa;
            $tagihan->save();
            $this->riwayat->Catat(TagihanQris::JENIS_DOKUMEN, $tagihan->Id, $asal->value, StatusTagihanQris::Kedaluwarsa->value, null, 'Batas waktu lewat');

            return $tagihan;
        });
    }

    private static function UraiJumlah(string $jumlah): ?Uang
    {
        try {
            return Uang::Dari(trim($jumlah));
        } catch (MathException|InvalidArgumentException) {
            return null;
        }
    }
}
