<?php

declare(strict_types=1);

namespace App\Domain\Pelanggan\Aksi;

use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pelanggan\Enum\StatusPembayaranPiutang;
use App\Domain\Pelanggan\Model\PembayaranPiutang;
use Illuminate\Support\Facades\DB;

/**
 * Catat atau perbarui nomor bukti potong PPh pada pelunasan piutang yang memotong PPh (F-21 bagian 5, izin
 * `akuntansi.kelola`). Bukti potong biasanya datang belakangan dari pelanggan, jadi nomornya boleh dilengkapi setelah
 * pelunasan diposting. Ini keterangan administratif: jumlah, jurnal, dan alokasi dokumen tidak berubah (aturan #8).
 * Hanya pelunasan berstatus diposting yang benar-benar memotong PPh; nomor tidak boleh dikosongkan. Audit
 * `pelunasan-piutang.bukti-potong` dengan nilai lama dan baru.
 */
final class CatatBuktiPotongPelunasan
{
    public const PANJANG_MAKSIMAL = 40;

    public function __construct(private readonly PencatatAudit $audit) {}

    /**
     * @throws PelanggaranAturanBisnis BuktiPotongTidakValid | PelunasanTanpaPph | PelunasanDibatalkan
     */
    public function Jalankan(PembayaranPiutang $pembayaran, string $nomorBukti, int $idPengguna): PembayaranPiutang
    {
        $nomorBukti = trim($nomorBukti);

        if ($nomorBukti === '' || mb_strlen($nomorBukti) > self::PANJANG_MAKSIMAL) {
            throw new PelanggaranAturanBisnis('BuktiPotongTidakValid', 'Nomor bukti potong wajib diisi, paling banyak '.self::PANJANG_MAKSIMAL.' karakter.', 'NomorBuktiPotong');
        }

        return DB::transaction(function () use ($pembayaran, $nomorBukti, $idPengguna): PembayaranPiutang {
            $terkunci = PembayaranPiutang::query()->whereKey($pembayaran->Id)->lockForUpdate()->firstOrFail();

            if ($terkunci->Status === StatusPembayaranPiutang::Dibatalkan) {
                throw new PelanggaranAturanBisnis('PelunasanDibatalkan', 'Pelunasan ini sudah dibatalkan, bukti potongnya tidak perlu dicatat.');
            }

            if (Uang::Dari((string) $terkunci->PotonganPph)->BernilaiNol()) {
                throw new PelanggaranAturanBisnis('PelunasanTanpaPph', 'Pelunasan ini tidak memotong PPh, jadi tidak ada bukti potong yang perlu dicatat.');
            }

            $lama = $terkunci->NomorBuktiPotong;

            if ($lama === $nomorBukti) {
                return $terkunci;
            }

            $terkunci->forceFill(['NomorBuktiPotong' => $nomorBukti])->save();
            $this->audit->Catat('pelunasan-piutang.bukti-potong', $terkunci, ['NomorBuktiPotong' => $lama], [
                'NomorBuktiPotong' => $nomorBukti,
                'Nomor' => $terkunci->Nomor,
            ], idPengguna: $idPengguna);

            return $terkunci;
        }, 3);
    }
}
