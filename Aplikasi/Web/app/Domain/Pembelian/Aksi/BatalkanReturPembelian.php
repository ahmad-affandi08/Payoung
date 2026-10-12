<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pembelian\Enum\StatusFakturPembelian;
use App\Domain\Pembelian\Layanan\PenyusunJurnalPembelian;
use App\Domain\Pembelian\Model\FakturPembelian;
use App\Domain\Pembelian\Model\FakturPembelianDetail;
use App\Domain\Pembelian\Model\PenerimaanBarang;
use App\Domain\Pembelian\Model\PenerimaanBarangDetail;
use App\Domain\Pembelian\Model\ReturPembelian;
use App\Domain\Pembelian\Model\ReturPembelianDetail;
use App\Domain\Persediaan\Aksi\CatatMutasiStok;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataBatchMasuk;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Persediaan\Kueri\MutasiDokumen;
use App\Domain\Tenant\Kueri\PengaturanPersediaanTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Membatalkan retur pembelian (F-04 fase 1, CLAUDE.md #8): stok masuk kembali lewat mutasi pembalik (`B/{KunciBaris}`,
 * `idMutasiAsal`, nilai = nilai keluar asal; batch & nomor seri yang sama), pengurang hutang dikembalikan ke faktur
 * atau hutang belum difakturkan, jurnal pembalik (`KunciSumber = Pembatalan`, `IdJurnalDibalik`) bertanggal hari
 * bisnis. Retur sebelum faktur tidak bisa dibatalkan setelah GRN-nya difakturkan (`SudahDifakturkan`). Alasan 5–255
 * karakter. Audit `retur-pembelian.batalkan`.
 *
 * Urutan kunci: L1 Tenant (S) → GRN → faktur → retur → buku stok → penghitung JU.
 */
final class BatalkanReturPembelian
{
    public function __construct(
        private readonly PengaturanPersediaanTenant $pengaturanPersediaan,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly MutasiDokumen $mutasiDokumen,
        private readonly InfoProdukStok $infoProduk,
        private readonly CatatMutasiStok $catatMutasi,
        private readonly PenyusunJurnalPembelian $penyusunJurnal,
        private readonly PostingJurnal $postingJurnal,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis AlasanTidakValid, SudahDifakturkan
     */
    public function Jalankan(ReturPembelian $retur, string $alasan, int $idPengguna): ReturPembelian
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < 5 || mb_strlen($alasan) > 255) {
            throw new PelanggaranAturanBisnis('AlasanTidakValid', 'Alasan pembatalan wajib diisi, 5 sampai 255 karakter.', 'Alasan');
        }

        return DB::transaction(fn (): ReturPembelian => $this->Batalkan($retur, $alasan, $idPengguna), 3);
    }

    private function Batalkan(ReturPembelian $awal, string $alasan, int $idPengguna): ReturPembelian
    {
        $this->pengaturanPersediaan->AmbilDenganKunciBaca();
        $grn = PenerimaanBarang::query()->whereKey($awal->IdPenerimaanBarang)->lockForUpdate()->firstOrFail();
        $faktur = $awal->IdFakturPembelian === null ? null : FakturPembelian::query()->whereKey($awal->IdFakturPembelian)->lockForUpdate()->firstOrFail();
        $retur = ReturPembelian::query()->whereKey($awal->Id)->lockForUpdate()->firstOrFail();

        if ($retur->Status === StatusDokumenTerposting::Dibatalkan) {
            return $retur;
        }

        if ($faktur === null && $grn->IdFakturPembelian !== null) {
            throw new PelanggaranAturanBisnis('SudahDifakturkan', 'Penerimaan retur ini sudah difakturkan setelah retur dicatat. Batalkan fakturnya dulu.');
        }

        $detail = ReturPembelianDetail::query()->where('IdReturPembelian', $retur->Id)->orderBy('Urutan')->get()->keyBy('Id');
        $detailGrn = PenerimaanBarangDetail::query()->whereIn('Id', $detail->pluck('IdPenerimaanBarangDetail')->all())->get()->keyBy('Id');
        $asal = array_values(array_filter($this->mutasiDokumen->AmbilRingkasan(JenisReferensiMutasi::ReturPembelian, $retur->Id), fn (array $m): bool => str_starts_with($m['KunciBaris'], 'P/')));
        $tanggal = $this->tanggalBisnis->Hitung($retur->IdOutlet);

        $hasil = $this->catatMutasi->Jalankan(new DataDokumenMutasi(
            JenisReferensiMutasi::ReturPembelian,
            $retur->Id,
            $retur->Uuid,
            $retur->Nomor,
            $tanggal,
            $idPengguna,
            null,
            array_map(function (array $m) use ($detail, $detailGrn): DataBarisMutasi {
                /** @var ReturPembelianDetail $r */
                $r = $detail->get((int) $m['IdReferensiDetail']);
                /** @var PenerimaanBarangDetail $g */
                $g = $detailGrn->get($r->IdPenerimaanBarangDetail);
                $k = explode('/', $m['KunciBaris'])[2] ?? null;

                return new DataBarisMutasi(
                    kunciBaris: 'B/'.$m['KunciBaris'],
                    idProduk: $m['IdProduk'],
                    idGudang: $m['IdGudang'],
                    jenisMutasi: JenisMutasi::ReturPembelian,
                    jumlah: Kuantitas::Dari($m['Jumlah'])->Negasi(),
                    modeNilai: ModeNilaiMutasi::Ditentukan,
                    nilai: Uang::Nol()->Kurangi(Uang::Dari($m['TotalHpp'])->Kurangi(Uang::Dari($m['SelisihHpp']))),
                    idReferensiDetail: $r->Id,
                    batchMasuk: $g->NomorBatch === null ? null : new DataBatchMasuk($g->NomorBatch, $g->TanggalKedaluwarsa === null ? null : CarbonImmutable::parse($g->TanggalKedaluwarsa->format('Y-m-d'))),
                    nomorSeriMasuk: $k === null ? null : ($r->DaftarNomorSeri[(int) $k - 1] ?? null),
                    idMutasiAsal: $m['Id'],
                );
            }, $asal),
        ));

        $nilaiHutang = Uang::Dari($retur->NilaiHutang);
        $pajak = Uang::Dari($retur->Pajak);
        // E8: retur dengan pengembalian uang membalik dua sisi — hutang yang tadi dipotong, dan uang yang tadi diterima.
        $refund = Uang::Dari($retur->NilaiRefund);
        $potongHutang = $nilaiHutang->Tambah($pajak)->Kurangi($refund);
        $lawan = $faktur === null
            ? [DataBarisJurnal::DariSelisih(PeranAkun::HutangBelumDifakturkan, Uang::Nol()->Kurangi(Uang::Dari($retur->NilaiBarang)), $retur->IdOutlet)]
            : [
                DataBarisJurnal::DariSelisih(PeranAkun::HutangUsaha, Uang::Nol()->Kurangi($potongHutang), $retur->IdOutlet),
                $retur->IdAkunRefund === null ? null : PenyusunJurnalPembelian::BarisAkun($retur->IdAkunRefund, Uang::Nol()->Kurangi($refund), $retur->IdOutlet, "Pembatalan pengembalian uang retur {$retur->Nomor}"),
                $faktur->PpnDikreditkan ? DataBarisJurnal::DariSelisih(PeranAkun::PpnMasukan, $pajak, $retur->IdOutlet) : null,
            ];
        $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique($detail->pluck('IdProduk')->all())), true);
        $jurnal = $this->postingJurnal->Jalankan(new DataJurnal(
            jenisSumber: JenisSumberJurnal::ReturPembelian,
            idSumber: $retur->Id,
            uuidSumber: $retur->Uuid,
            nomorSumber: $retur->Nomor,
            tanggal: $tanggal,
            keterangan: mb_substr("Pembatalan retur pembelian {$retur->Nomor}", 0, 255),
            baris: PenyusunJurnalPembelian::Seimbangkan([...$this->penyusunJurnal->BarisPersediaan($hasil, $produk, $retur->IdOutlet), ...$lawan], $retur->IdOutlet),
            idPengguna: $idPengguna,
            kunciSumber: 'Pembatalan',
            idJurnalDibalik: $retur->IdJurnal,
        ));

        foreach ($detail as $r) {
            /** @var PenerimaanBarangDetail $g */
            $g = $detailGrn->get($r->IdPenerimaanBarangDetail);
            $g->fill([
                'JumlahDiretur' => Kuantitas::Dari($g->JumlahDiretur)->Kurangi(Kuantitas::Dari($r->JumlahDasar))->KeString(),
                'NilaiDiretur' => Uang::Dari($g->NilaiDiretur)->Kurangi(Uang::Dari($r->Nilai))->KeString(),
            ])->save();

            if ($r->IdFakturPembelianDetail !== null) {
                $f = FakturPembelianDetail::query()->whereKey($r->IdFakturPembelianDetail)->firstOrFail();
                $f->fill([
                    'JumlahDiretur' => Kuantitas::Dari($f->JumlahDiretur)->Kurangi(Kuantitas::Dari($r->JumlahDasar))->KeString(),
                    'NilaiDiretur' => Uang::Dari($f->NilaiDiretur)->Kurangi(Uang::Dari($r->NilaiHutang))->KeString(),
                    'PajakDiretur' => Uang::Dari($f->PajakDiretur)->Kurangi(Uang::Dari($r->Pajak))->KeString(),
                ])->save();
            }
        }

        if ($faktur !== null) {
            $status = $faktur->Status;
            $faktur->JumlahRetur = Uang::Dari($faktur->JumlahRetur)->Kurangi($potongHutang)->KeString();

            if ($faktur->Status !== StatusFakturPembelian::Dibatalkan) {
                $faktur->SelaraskanStatus();
            }

            $faktur->save();

            if ($status !== $faktur->Status) {
                $this->riwayat->Catat(FakturPembelian::JENIS_DOKUMEN, $faktur->Id, $status->value, $faktur->Status->value, $idPengguna, $alasan);
            }
        }

        $retur->UbahStatus(StatusDokumenTerposting::Dibatalkan);
        $retur->fill(['IdJurnalPembatalan' => $jurnal->idJurnal, 'AlasanBatal' => $alasan, 'DibatalkanOleh' => $idPengguna, 'DibatalkanPada' => now()])->save();

        $this->riwayat->Catat(ReturPembelian::JENIS_DOKUMEN, $retur->Id, StatusDokumenTerposting::Diposting->value, StatusDokumenTerposting::Dibatalkan->value, $idPengguna, $alasan);
        $this->audit->Catat('retur-pembelian.batalkan', $retur, ['Status' => StatusDokumenTerposting::Diposting->value], [
            'Status' => StatusDokumenTerposting::Dibatalkan->value,
            'Nomor' => $retur->Nomor,
            'Alasan' => $alasan,
            'NomorJurnalPembatalan' => $jurnal->nomor,
        ], idPengguna: $idPengguna);

        return $retur;
    }
}
