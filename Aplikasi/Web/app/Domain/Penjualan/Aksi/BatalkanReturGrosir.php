<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Pelanggan\Layanan\PencatatPiutangPenjualan;
use App\Domain\Penjualan\Layanan\PenyusunJurnalGrosir;
use App\Domain\Penjualan\Model\ReturGrosir;
use App\Domain\Penjualan\Model\ReturGrosirDetail;
use App\Domain\Penjualan\Model\SuratJalanDetail;
use App\Domain\Persediaan\Aksi\CatatMutasiStok;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Persediaan\Kueri\MutasiDokumen;
use App\Domain\Persediaan\Layanan\Hpp\AritmetikaHpp;
use App\Domain\Persediaan\Layanan\PengunciSaldoStok;
use App\Domain\Persediaan\Layanan\PetaAkunPersediaan;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Membatalkan retur grosir (F-12, §9.7, J-12.4 pembalik). Dokumen tidak diedit dan tidak dihapus (CLAUDE.md #8):
 * barangnya keluar kembali lewat mutasi pembalik pada nilai yang sama, seluruh baris J-12.4 dibalik sisinya, nota
 * kreditnya dicabut (sisa tagihan `Piutang` dipulihkan), dan `JumlahDiretur` baris surat jalan dikurangi.
 *
 * Bila barang returnya sudah terjual lagi, `CatatMutasiStok` yang menolaknya lewat BR-05.2 (`StokTidakCukup`) — dan itu
 * memang jawaban yang benar: stok yang sudah keluar tidak bisa ditarik kembali oleh pembatalan dokumen.
 *
 * Bila fakturnya sudah dibatalkan lebih dulu, pengurangan tagihannya **tidak** dipulihkan: piutang itu sudah dihapus
 * seluruhnya oleh pembatalan faktur, dan menambahkannya kembali akan membuat tagihan tanpa dokumen dasar.
 */
final class BatalkanReturGrosir
{
    public const PANJANG_ALASAN_MINIMAL = 5;

    public function __construct(
        private readonly MutasiDokumen $mutasiDokumen,
        private readonly PengunciSaldoStok $pengunciSaldo,
        private readonly InfoProdukStok $infoProduk,
        private readonly PetaAkunPersediaan $petaAkunPersediaan,
        private readonly CatatMutasiStok $catatMutasi,
        private readonly PenyusunJurnalGrosir $penyusunJurnal,
        private readonly PostingJurnal $postingJurnal,
        private readonly PencatatPiutangPenjualan $pencatatPiutang,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis AlasanBatalWajib, ReturTidakDitemukan, StokTidakCukup
     */
    public function Jalankan(string $uuidRetur, string $alasan, int $idPengguna): ReturGrosir
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < self::PANJANG_ALASAN_MINIMAL || mb_strlen($alasan) > 255) {
            throw new PelanggaranAturanBisnis(
                'AlasanBatalWajib',
                'Alasan pembatalan wajib diisi, '.self::PANJANG_ALASAN_MINIMAL.' sampai 255 karakter.',
                'Alasan',
            );
        }

        return DB::transaction(
            fn (): ReturGrosir => $this->Batalkan($uuidRetur, $alasan, $idPengguna),
            max(1, (int) config('persediaan.PercobaanTransaksi', 3)),
        );
    }

    private function Batalkan(string $uuid, string $alasan, int $idPengguna): ReturGrosir
    {
        $retur = ReturGrosir::query()->where('Uuid', $uuid)->lockForUpdate()->first()
            ?? throw new PelanggaranAturanBisnis('ReturTidakDitemukan', 'Retur grosir tidak ditemukan.');

        if ($retur->Status === StatusDokumenTerposting::Dibatalkan) {
            return $retur;
        }

        $tanggal = CarbonImmutable::parse($retur->Tanggal->format('Y-m-d'));
        $asalMutasi = array_values(array_filter(
            $this->mutasiDokumen->AmbilRingkasan(JenisReferensiMutasi::ReturGrosir, $retur->Id),
            fn (array $m): bool => str_starts_with($m['KunciBaris'], 'R/'),
        ));
        $pasangan = [];

        foreach ($asalMutasi as $m) {
            $pasangan[$m['IdProduk'].':'.$m['IdGudang']] = [$m['IdProduk'], $m['IdGudang']];
        }

        $pasangan = array_values($pasangan);
        usort($pasangan, fn (array $a, array $b): int => $a <=> $b);
        $this->pengunciSaldo->Kunci($pasangan);

        $hasilMutasi = $this->catatMutasi->Jalankan(new DataDokumenMutasi(
            jenisReferensi: JenisReferensiMutasi::ReturGrosir,
            idReferensi: $retur->Id,
            uuidReferensi: $retur->Uuid,
            nomorReferensi: $retur->Nomor,
            tanggalBisnis: $tanggal,
            idPengguna: $idPengguna,
            idPerangkat: null,
            baris: array_map(fn (array $m): DataBarisMutasi => new DataBarisMutasi(
                kunciBaris: 'B/'.$m['KunciBaris'],
                idProduk: $m['IdProduk'],
                idGudang: $m['IdGudang'],
                jenisMutasi: JenisMutasi::ReturPenjualan,
                jumlah: Kuantitas::Dari($m['Jumlah'])->Negasi(),
                modeNilai: ModeNilaiMutasi::Ditentukan,
                nilai: AritmetikaHpp::AmbilMutlak(Uang::Dari($m['TotalHpp'])),
                hppSatuan: BigDecimal::of($m['HppSatuan']),
                idReferensiDetail: $m['IdReferensiDetail'],
                idBatchStok: $m['IdBatchStok'],
                idNomorSeri: $m['IdNomorSeri'],
                idMutasiAsal: $m['Id'],
            ), $asalMutasi),
        ));

        $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique(array_column($asalMutasi, 'IdProduk'))), true);
        $perubahanPersediaan = [];

        foreach ($hasilMutasi->baris as $mutasi) {
            $peran = $this->petaAkunPersediaan->UntukJenis($produk[$mutasi->idProduk]->jenis)->value;
            $perubahanPersediaan[$peran] = ($perubahanPersediaan[$peran] ?? Uang::Nol())->Tambah($mutasi->totalHpp);
        }

        $jurnal = $this->postingJurnal->Jalankan(new DataJurnal(
            jenisSumber: JenisSumberJurnal::ReturGrosir,
            idSumber: $retur->Id,
            uuidSumber: $retur->Uuid,
            nomorSumber: $retur->Nomor,
            tanggal: $tanggal,
            keterangan: mb_substr("Pembatalan retur grosir {$retur->Nomor}", 0, 255),
            baris: $this->penyusunJurnal->BarisReturGrosir(
                $retur->AmbilTotal(),
                Uang::Dari($retur->Diskon),
                Uang::Dari($retur->DasarPengenaanPajak)->Tambah(Uang::Dari($retur->Diskon)),
                $retur->AmbilRincianPajak(),
                $perubahanPersediaan,
                $retur->IdOutlet,
                $retur->MengurangiPiutang,
                pembalik: true,
                refund: Uang::Dari($retur->NilaiRefund),
                idAkunRefund: $retur->IdAkunRefund,
            ),
            idPengguna: $idPengguna,
            kunciSumber: 'Pembatalan',
            idJurnalDibalik: $retur->IdJurnal,
        ));

        if ($retur->MengurangiPiutang && $retur->IdFakturPenjualan !== null) {
            $this->pencatatPiutang->BatalkanPenguranganFaktur(
                $retur->IdFakturPenjualan,
                $retur->AmbilTotal()->Kurangi(Uang::Dari($retur->NilaiRefund)),
                $idPengguna,
                "Pembatalan retur grosir {$retur->Nomor}",
            );
        }

        $detailKirim = SuratJalanDetail::query()
            ->whereIn('Id', ReturGrosirDetail::query()->where('IdReturGrosir', $retur->Id)->select('IdSuratJalanDetail'))
            ->lockForUpdate()
            ->get()
            ->keyBy('Id');

        foreach (ReturGrosirDetail::query()->where('IdReturGrosir', $retur->Id)->get() as $baris) {
            $detail = $detailKirim[$baris->IdSuratJalanDetail] ?? null;

            if ($detail === null) {
                continue;
            }

            $sisa = $detail->AmbilJumlahDiretur()->Kurangi($baris->AmbilJumlah());
            $detail->JumlahDiretur = ($sisa->BernilaiNegatif() ? Kuantitas::Nol() : $sisa)->KeString();
            $detail->save();
        }

        $retur->UbahStatus(StatusDokumenTerposting::Dibatalkan);
        $retur->fill([
            'IdJurnalPembatalan' => $jurnal->idJurnal,
            'AlasanBatal' => $alasan,
            'DibatalkanOleh' => $idPengguna,
            'DibatalkanPada' => CarbonImmutable::now(),
            'DiubahOleh' => $idPengguna,
        ])->save();

        $this->riwayat->Catat(
            ReturGrosir::JENIS_DOKUMEN,
            $retur->Id,
            StatusDokumenTerposting::Diposting->value,
            StatusDokumenTerposting::Dibatalkan->value,
            $idPengguna,
            $alasan,
        );
        $this->audit->Catat('grosir.retur-batalkan', $retur, nilaiLama: ['Status' => StatusDokumenTerposting::Diposting->value], nilaiBaru: [
            'Status' => StatusDokumenTerposting::Dibatalkan->value,
            'Nomor' => $retur->Nomor,
            'Alasan' => $alasan,
            'NomorJurnalPembatalan' => $jurnal->nomor,
            'MengurangiPiutang' => $retur->MengurangiPiutang,
        ], idPengguna: $idPengguna);

        return $retur;
    }
}
