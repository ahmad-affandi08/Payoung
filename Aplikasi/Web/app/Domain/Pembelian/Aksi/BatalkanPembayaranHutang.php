<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Aksi;

use App\Domain\Akuntansi\Aksi\BalikkanJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberGiro;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\StatusGiro;
use App\Domain\Akuntansi\Kueri\StatusGiroSumber;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pembelian\Model\FakturPembelian;
use App\Domain\Pembelian\Model\PembayaranHutang;
use App\Domain\Pembelian\Model\PembayaranHutangAlokasi;
use App\Domain\Pembelian\Model\ReturPembelian;
use Illuminate\Support\Facades\DB;

/**
 * Membatalkan pembayaran hutang (F-04 fase 1, CLAUDE.md #8): jurnal J-04.4 dibalik (cermin, `KunciSumber =
 * Pembatalan`) bertanggal hari bisnis, sisa faktur bertambah kembali & statusnya diselaraskan. Pelunasan belanja stok
 * hanya dibatalkan lewat pembatalan belanjanya. Alasan 5–255 karakter. Audit `pembayaran-hutang.batalkan`.
 *
 * Urutan kunci: faktur (urut Id) → pembayaran.
 */
final class BatalkanPembayaranHutang
{
    public function __construct(
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly BalikkanJurnal $balikkan,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
        private readonly StatusGiroSumber $statusGiro,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis AlasanTidakValid, BagianBelanjaStok, BagianKompensasi, AdaReturBerefund
     */
    public function Jalankan(PembayaranHutang $pembayaran, string $alasan, int $idPengguna, bool $olehGiro = false): PembayaranHutang
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < 5 || mb_strlen($alasan) > 255) {
            throw new PelanggaranAturanBisnis('AlasanTidakValid', 'Alasan pembatalan wajib diisi, 5 sampai 255 karakter.', 'Alasan');
        }

        return DB::transaction(function () use ($pembayaran, $alasan, $idPengguna, $olehGiro): PembayaranHutang {
            $alokasi = PembayaranHutangAlokasi::query()->where('IdPembayaranHutang', $pembayaran->Id)->get();
            $faktur = FakturPembelian::query()->whereIn('Id', $alokasi->pluck('IdFakturPembelian')->all())->orderBy('Id')->lockForUpdate()->get()->keyBy('Id');
            $terkunci = PembayaranHutang::query()->whereKey($pembayaran->Id)->lockForUpdate()->firstOrFail();

            if ($terkunci->Status === StatusDokumenTerposting::Dibatalkan) {
                return $terkunci;
            }

            // v3.42: pembayaran dengan giro mundur dibatalkan lewat "Tolak giro" (giro ditandai ditolak); yang sudah cair
            // tidak bisa dibatalkan karena uangnya sudah masuk/keluar rekening.
            $statusGiro = $this->statusGiro->Ambil(JenisSumberGiro::PembayaranHutang, $terkunci->Id);

            if ($statusGiro === StatusGiro::Cair) {
                throw new PelanggaranAturanBisnis('GiroSudahCair', 'Giro pembayaran ini sudah cair. Koreksi lewat transaksi kas & bank.');
            }

            if ($statusGiro === StatusGiro::Menunggu && ! $olehGiro) {
                throw new PelanggaranAturanBisnis('PakaiTolakGiro', 'Pembayaran ini memakai giro yang belum cair. Batalkan lewat "Tolak giro" di halaman Giro.');
            }

            if ($terkunci->BelanjaStok) {
                throw new PelanggaranAturanBisnis('BagianBelanjaStok', 'Pembayaran ini bagian dari belanja stok. Batalkan belanja stoknya dari halaman penerimaan barang.');
            }

            // F-16c bagian 4e: potong hutang dari klaim promo sudah menyelesaikan klaimnya; koreksi dengan dokumen lain.
            if ($terkunci->Kompensasi) {
                throw new PelanggaranAturanBisnis('BagianKompensasi', 'Pembayaran ini hasil potong klaim promo pemasok dan tidak bisa dibatalkan. Koreksi selisihnya dengan jurnal atau transaksi kas/bank.');
            }

            // E8: faktur yang retur-nya sudah menerima pengembalian uang dari pemasok dihitung dari pembayaran ini; membatalkan
            // pembayaran dulu membuat hutang kembali terbuka padahal uang refund sudah diterima.
            if (ReturPembelian::query()->whereIn('IdFakturPembelian', $faktur->keys()->all())->where('Status', StatusDokumenTerposting::Diposting->value)->where('NilaiRefund', '>', 0)->exists()) {
                throw new PelanggaranAturanBisnis('AdaReturBerefund', 'Faktur ini punya retur dengan pengembalian uang dari pemasok. Batalkan returnya dulu, baru pembayaran ini.');
            }

            foreach ($alokasi as $a) {
                /** @var FakturPembelian $f */
                $f = $faktur->get($a->IdFakturPembelian);
                $asal = $f->Status;
                $f->JumlahDibayar = Uang::Dari($f->JumlahDibayar)->Kurangi(Uang::Dari($a->Jumlah))->KeString();
                $f->SelaraskanStatus();
                $f->save();

                if ($asal !== $f->Status) {
                    $this->riwayat->Catat(FakturPembelian::JENIS_DOKUMEN, $f->Id, $asal->value, $f->Status->value, $idPengguna, $alasan);
                }
            }

            $jurnal = $terkunci->IdJurnal === null ? null : $this->balikkan->Jalankan(
                $terkunci->IdJurnal,
                $this->tanggalBisnis->Hitung($terkunci->IdOutlet),
                mb_substr("Pembatalan pembayaran hutang {$terkunci->Nomor}", 0, 255),
                JenisSumberJurnal::PembayaranHutang,
                $terkunci->Id,
                'Pembatalan',
                $idPengguna,
            );

            $terkunci->UbahStatus(StatusDokumenTerposting::Dibatalkan);
            $terkunci->fill(['IdJurnalPembatalan' => $jurnal?->idJurnal, 'AlasanBatal' => $alasan, 'DibatalkanOleh' => $idPengguna, 'DibatalkanPada' => now()])->save();

            $this->riwayat->Catat(PembayaranHutang::JENIS_DOKUMEN, $terkunci->Id, StatusDokumenTerposting::Diposting->value, StatusDokumenTerposting::Dibatalkan->value, $idPengguna, $alasan);
            $this->audit->Catat('pembayaran-hutang.batalkan', $terkunci, ['Status' => StatusDokumenTerposting::Diposting->value], [
                'Status' => StatusDokumenTerposting::Dibatalkan->value,
                'Nomor' => $terkunci->Nomor,
                'Alasan' => $alasan,
                'NomorJurnalPembatalan' => $jurnal?->nomor,
            ], idPengguna: $idPengguna);

            return $terkunci;
        }, 3);
    }
}
