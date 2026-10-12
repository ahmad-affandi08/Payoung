<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Akuntansi\Aksi\BalikkanJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Penjualan\Enum\StatusTagihanQris;
use App\Domain\Penjualan\Model\TagihanQris;
use Illuminate\Support\Facades\DB;

/**
 * Membuka kembali penutupan uang QRIS tanpa penjualan (E8, izin `akuntansi.kelola`), mis. salah memilih hasil. Jurnal
 * pengakuan pendapatan (bila ada) dibalik pada tanggal bisnis hari ini — jurnal asal tidak diedit (aturan #8) — dan
 * tagihan kembali `PerluTinjauan` sehingga muncul lagi di Kotak Tindakan. Alasan 5–255 karakter. Audit
 * `tagihan-qris.buka-kembali`.
 */
final class BukaKembaliTagihanQris
{
    public function __construct(
        private readonly BalikkanJurnal $balikkan,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis AlasanTidakValid, TagihanTidakDitemukan, BelumDitutup
     */
    public function Jalankan(string $uuidTagihan, string $alasan, int $idPengguna): TagihanQris
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < 5 || mb_strlen($alasan) > 255) {
            throw new PelanggaranAturanBisnis('AlasanTidakValid', 'Alasan membuka kembali wajib diisi, 5 sampai 255 karakter.', 'Alasan');
        }

        return DB::transaction(function () use ($uuidTagihan, $alasan, $idPengguna): TagihanQris {
            $tagihan = TagihanQris::query()->where('Uuid', $uuidTagihan)->lockForUpdate()->first()
                ?? throw new PelanggaranAturanBisnis('TagihanTidakDitemukan', 'Tagihan QRIS tidak ditemukan.');

            if ($tagihan->DitutupPada === null) {
                throw new PelanggaranAturanBisnis('BelumDitutup', "Tagihan {$tagihan->NomorPesanan} belum ditutup.");
            }

            $jurnal = $tagihan->IdJurnalPenutupan === null ? null : $this->balikkan->Jalankan(
                $tagihan->IdJurnalPenutupan,
                $this->tanggalBisnis->Hitung(null),
                mb_substr("Pembatalan pengakuan uang QRIS tanpa penjualan {$tagihan->NomorPesanan}", 0, 255),
                JenisSumberJurnal::TagihanQris,
                $tagihan->Id,
                'Pembatalan-'.$tagihan->IdJurnalPenutupan,
                $idPengguna,
            );

            $hasilAsal = $tagihan->HasilPenutupan?->value;
            $tagihan->DitutupPada = null;
            $tagihan->DitutupOleh = null;
            $tagihan->HasilPenutupan = null;
            $tagihan->CatatanPenutupan = null;
            $tagihan->IdJurnalPenutupan = null;
            $tagihan->PerluTinjauan = true;
            $tagihan->save();

            $this->riwayat->Catat(TagihanQris::JENIS_DOKUMEN, $tagihan->Id, StatusTagihanQris::Lunas->value, StatusTagihanQris::Lunas->value, $idPengguna, "Penutupan dibuka kembali. {$alasan}");
            $this->audit->Catat('tagihan-qris.buka-kembali', $tagihan, ['Hasil' => $hasilAsal], [
                'NomorPesanan' => $tagihan->NomorPesanan,
                'Alasan' => $alasan,
                'NomorJurnalPembatalan' => $jurnal?->nomor,
            ], idPengguna: $idPengguna);

            return $tagihan;
        }, max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }
}
