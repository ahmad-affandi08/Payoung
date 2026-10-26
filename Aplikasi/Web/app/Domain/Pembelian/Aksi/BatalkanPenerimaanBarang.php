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
use App\Domain\Pembelian\Layanan\PemrosesPenerimaanBarang;
use App\Domain\Pembelian\Layanan\PenyusunJurnalPembelian;
use App\Domain\Pembelian\Model\BiayaTambahanPembelian;
use App\Domain\Pembelian\Model\FakturPembelian;
use App\Domain\Pembelian\Model\PembayaranHutang;
use App\Domain\Pembelian\Model\PembayaranHutangAlokasi;
use App\Domain\Pembelian\Model\PenerimaanBarang;
use App\Domain\Pembelian\Model\PenerimaanBarangDetail;
use App\Domain\Pembelian\Model\PesananPembelian;
use App\Domain\Pembelian\Model\PesananPembelianDetail;
use App\Domain\Pembelian\Model\ReturPembelian;
use App\Domain\Persediaan\Aksi\CatatMutasiStok;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\MetodeHpp;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Persediaan\Kueri\MutasiDokumen;
use App\Domain\Persediaan\Kueri\SaldoStokPasangan;
use App\Domain\Tenant\Kueri\PengaturanPersediaanTenant;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Membatalkan penerimaan barang (F-04 fase 1, CLAUDE.md #8): dokumen tidak diedit, melainkan dibalik lewat mutasi
 * pembalik (`B/{KunciBaris}`, `idMutasiAsal`, nilai = nilai asal yang diminta) dan jurnal pembalik (`KunciSumber =
 * Pembatalan`, `IdJurnalDibalik`) bertanggal hari bisnis outlet. Jumlah diterima PO dikurangi dan status PO
 * diselaraskan. Belanja stok dibatalkan utuh: faktur & pembayaran belanja ikut Dibatalkan, jurnal J-04.3 dibalik.
 *
 * Ditolak bila: sudah difakturkan (`SudahDifakturkan`, batalkan fakturnya dulu), ada retur aktif (`SudahDiretur`),
 * atau stok penerimaan sudah terpakai (`StokSudahTerpakai`: saldo < jumlah diterima untuk rata-rata bergerak; FIFO,
 * batch, dan nomor seri dijaga buku stok). Alasan 5–255 karakter. Audit `penerimaan-barang.batalkan`.
 */
final class BatalkanPenerimaanBarang
{
    public function __construct(
        private readonly PengaturanPersediaanTenant $pengaturanPersediaan,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly MutasiDokumen $mutasiDokumen,
        private readonly SaldoStokPasangan $saldo,
        private readonly InfoProdukStok $infoProduk,
        private readonly CatatMutasiStok $catatMutasi,
        private readonly PenyusunJurnalPembelian $penyusunJurnal,
        private readonly PostingJurnal $postingJurnal,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis AlasanTidakValid, StatusTidakSesuai, SudahDifakturkan, SudahDiretur, StokSudahTerpakai
     */
    public function Jalankan(PenerimaanBarang $penerimaan, string $alasan, int $idPengguna): PenerimaanBarang
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < 5 || mb_strlen($alasan) > 255) {
            throw new PelanggaranAturanBisnis('AlasanTidakValid', 'Alasan pembatalan wajib diisi, 5 sampai 255 karakter.', 'Alasan');
        }

        return DB::transaction(fn (): PenerimaanBarang => $this->Batalkan($penerimaan->Id, $alasan, $idPengguna), 3);
    }

    private function Batalkan(int $id, string $alasan, int $idPengguna): PenerimaanBarang
    {
        $pengaturan = $this->pengaturanPersediaan->AmbilDenganKunciBaca();
        $awal = PenerimaanBarang::query()->whereKey($id)->firstOrFail();
        $po = $awal->IdPesananPembelian === null ? null : PesananPembelian::query()->whereKey($awal->IdPesananPembelian)->lockForUpdate()->firstOrFail();
        $dokumen = PenerimaanBarang::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        if ($dokumen->Status === StatusDokumenTerposting::Dibatalkan) {
            return $dokumen;
        }

        $faktur = $dokumen->IdFakturPembelian === null ? null : FakturPembelian::query()->whereKey($dokumen->IdFakturPembelian)->lockForUpdate()->firstOrFail();

        if ($faktur !== null && ! $dokumen->BelanjaStok && $faktur->Status !== StatusFakturPembelian::Dibatalkan) {
            throw new PelanggaranAturanBisnis('SudahDifakturkan', "Penerimaan ini sudah difakturkan di {$faktur->Nomor}. Batalkan fakturnya dulu.");
        }

        if (ReturPembelian::query()->where('IdPenerimaanBarang', $dokumen->Id)->where('Status', StatusDokumenTerposting::Diposting->value)->exists()) {
            throw new PelanggaranAturanBisnis('SudahDiretur', 'Penerimaan ini punya retur pembelian aktif. Batalkan returnya dulu.');
        }

        // v3.41: biaya tambahan menilai ulang stok penerimaan ini; batalkan dulu supaya nilainya ikut kembali.
        if (BiayaTambahanPembelian::query()->where('IdPenerimaanBarang', $dokumen->Id)->where('Status', StatusDokumenTerposting::Diposting->value)->exists()) {
            throw new PelanggaranAturanBisnis('AdaBiayaTambahan', 'Penerimaan ini punya biaya tambahan aktif. Batalkan biaya tambahannya dulu.');
        }

        $asal = array_values(array_filter($this->mutasiDokumen->AmbilRingkasan(JenisReferensiMutasi::PenerimaanBarang, $dokumen->Id), fn (array $m): bool => str_starts_with($m['KunciBaris'], 'P/')));

        if ($pengaturan->metodeHpp === MetodeHpp::RataRata) {
            $this->PastikanStokBelumTerpakai($asal);
        }

        $tanggal = $this->tanggalBisnis->Hitung($dokumen->IdOutlet);
        $hasil = $this->catatMutasi->Jalankan(new DataDokumenMutasi(
            JenisReferensiMutasi::PenerimaanBarang,
            $dokumen->Id,
            $dokumen->Uuid,
            $dokumen->Nomor,
            $tanggal,
            $idPengguna,
            null,
            array_map(fn (array $m): DataBarisMutasi => new DataBarisMutasi(
                kunciBaris: 'B/'.$m['KunciBaris'],
                idProduk: $m['IdProduk'],
                idGudang: $m['IdGudang'],
                jenisMutasi: JenisMutasi::PenerimaanPembelian,
                jumlah: Kuantitas::Dari($m['Jumlah'])->Negasi(),
                modeNilai: ModeNilaiMutasi::Ditentukan,
                nilai: Uang::Dari($m['TotalHpp'])->Kurangi(Uang::Dari($m['SelisihHpp'])),
                hppSatuan: BigDecimal::of($m['HppSatuan']),
                idReferensiDetail: $m['IdReferensiDetail'],
                idBatchStok: $m['IdBatchStok'],
                idNomorSeri: $m['IdNomorSeri'],
                idMutasiAsal: $m['Id'],
            ), $asal),
        ));

        $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique(array_column($asal, 'IdProduk'))), true);
        $lawan = $dokumen->BelanjaStok
            ? $this->BarisLawanBelanja($dokumen, $faktur)
            : [DataBarisJurnal::DariSelisih(PeranAkun::HutangBelumDifakturkan, Uang::Nol()->Kurangi($hasil->TotalNilaiDiminta()), $dokumen->IdOutlet)];
        $baris = PenyusunJurnalPembelian::Seimbangkan([...$this->penyusunJurnal->BarisPersediaan($hasil, $produk, $dokumen->IdOutlet), ...$lawan], $dokumen->IdOutlet);
        $jurnal = $baris === [] ? null : $this->postingJurnal->Jalankan(new DataJurnal(
            jenisSumber: JenisSumberJurnal::PenerimaanBarang,
            idSumber: $dokumen->Id,
            uuidSumber: $dokumen->Uuid,
            nomorSumber: $dokumen->Nomor,
            tanggal: $tanggal,
            keterangan: mb_substr("Pembatalan penerimaan barang {$dokumen->Nomor}", 0, 255),
            baris: $baris,
            idPengguna: $idPengguna,
            kunciSumber: 'Pembatalan',
            idJurnalDibalik: $dokumen->IdJurnal,
        ));

        if ($po !== null) {
            $this->KembalikanPo($po, $dokumen, $idPengguna);
        }

        if ($dokumen->BelanjaStok && $faktur !== null) {
            $this->BatalkanBagianBelanja($faktur, $jurnal?->idJurnal, $alasan, $idPengguna);
        }

        $dokumen->UbahStatus(StatusDokumenTerposting::Dibatalkan);
        $dokumen->fill(['IdJurnalPembatalan' => $jurnal?->idJurnal, 'AlasanBatal' => $alasan, 'DibatalkanOleh' => $idPengguna, 'DibatalkanPada' => now()])->save();

        $this->riwayat->Catat(PenerimaanBarang::JENIS_DOKUMEN, $dokumen->Id, StatusDokumenTerposting::Diposting->value, StatusDokumenTerposting::Dibatalkan->value, $idPengguna, $alasan);
        $this->audit->Catat('penerimaan-barang.batalkan', $dokumen, ['Status' => StatusDokumenTerposting::Diposting->value], [
            'Status' => StatusDokumenTerposting::Dibatalkan->value,
            'Nomor' => $dokumen->Nomor,
            'Alasan' => $alasan,
            'NomorJurnalPembatalan' => $jurnal?->nomor,
            'BelanjaStok' => $dokumen->BelanjaStok,
        ], idPengguna: $idPengguna);

        return $dokumen;
    }

    /**
     * @param  list<array{IdProduk: int, IdGudang: int, Jumlah: string}>  $asal
     */
    private function PastikanStokBelumTerpakai(array $asal): void
    {
        $perPasangan = [];

        foreach ($asal as $m) {
            $k = $m['IdProduk'].':'.$m['IdGudang'];
            $perPasangan[$k] = [$m['IdProduk'], $m['IdGudang'], ($perPasangan[$k][2] ?? Kuantitas::Nol())->Tambah(Kuantitas::Dari($m['Jumlah']))];
        }

        uasort($perPasangan, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $saldo = $this->saldo->Ambil(array_values(array_map(fn (array $p): array => [$p[0], $p[1]], $perPasangan)), true);
        foreach ($perPasangan as $k => [$idProduk, , $jumlah]) {
            $tersedia = $saldo[$k] ?? Kuantitas::Nol();

            if ($tersedia->Bandingkan($jumlah) < 0) {
                $nama = PenerimaanBarangDetail::query()->where('IdProduk', $idProduk)->value('NamaProduk');
                $format = fn (Kuantitas $q): string => str_replace('.', ',', (string) $q->KeDesimal()->strippedOfTrailingZeros());

                throw new PelanggaranAturanBisnis(
                    'StokSudahTerpakai',
                    "Stok {$nama} sudah berkurang (tersedia {$format($tersedia)}, diterima {$format($jumlah)}). Penerimaan tidak bisa dibatalkan. Kembalikan barang lewat retur pembelian.",
                    detail: ['Tersedia' => $tersedia->KeString(), 'Diterima' => $jumlah->KeString()],
                );
            }
        }
    }

    /**
     * Pembalik J-04.3: Dr kas/bank sebesar total dibayar, Cr PPN masukan (bila dikreditkan).
     *
     * @return list<DataBarisJurnal|null>
     */
    private function BarisLawanBelanja(PenerimaanBarang $dokumen, ?FakturPembelian $faktur): array
    {
        $pembayaran = $faktur === null ? null : PembayaranHutang::query()
            ->whereIn('Id', PembayaranHutangAlokasi::query()->where('IdFakturPembelian', $faktur->Id)->select('IdPembayaranHutang'))
            ->where('BelanjaStok', true)
            ->first();

        if ($faktur === null || $pembayaran === null) {
            return [];
        }

        return [
            $faktur->PpnDikreditkan ? DataBarisJurnal::DariSelisih(PeranAkun::PpnMasukan, Uang::Nol()->Kurangi(Uang::Dari($faktur->Pajak)), $dokumen->IdOutlet) : null,
            PenyusunJurnalPembelian::BarisAkun($pembayaran->IdAkun, Uang::Dari($pembayaran->Jumlah), $dokumen->IdOutlet, "Pembatalan belanja stok {$dokumen->Nomor}"),
        ];
    }

    private function BatalkanBagianBelanja(FakturPembelian $faktur, ?int $idJurnal, string $alasan, int $idPengguna): void
    {
        $status = $faktur->Status;
        $faktur->UbahStatus(StatusFakturPembelian::Dibatalkan);
        $faktur->fill(['IdJurnalPembatalan' => $idJurnal, 'AlasanBatal' => $alasan, 'DibatalkanOleh' => $idPengguna, 'DibatalkanPada' => now()])->save();
        $this->riwayat->Catat(FakturPembelian::JENIS_DOKUMEN, $faktur->Id, $status->value, StatusFakturPembelian::Dibatalkan->value, $idPengguna, $alasan);

        $pembayaran = PembayaranHutang::query()
            ->whereIn('Id', PembayaranHutangAlokasi::query()->where('IdFakturPembelian', $faktur->Id)->select('IdPembayaranHutang'))
            ->where('Status', StatusDokumenTerposting::Diposting->value)
            ->lockForUpdate()
            ->get();

        foreach ($pembayaran as $p) {
            $p->UbahStatus(StatusDokumenTerposting::Dibatalkan);
            $p->fill(['IdJurnalPembatalan' => $idJurnal, 'AlasanBatal' => $alasan, 'DibatalkanOleh' => $idPengguna, 'DibatalkanPada' => now()])->save();
            $this->riwayat->Catat(PembayaranHutang::JENIS_DOKUMEN, $p->Id, StatusDokumenTerposting::Diposting->value, StatusDokumenTerposting::Dibatalkan->value, $idPengguna, $alasan);
        }
    }

    private function KembalikanPo(PesananPembelian $po, PenerimaanBarang $dokumen, int $idPengguna): void
    {
        $detailPo = PesananPembelianDetail::query()->where('IdPesananPembelian', $po->Id)->orderBy('Id')->lockForUpdate()->get()->keyBy('Id')->all();

        foreach (PenerimaanBarangDetail::query()->where('IdPenerimaanBarang', $dokumen->Id)->whereNotNull('IdPesananPembelianDetail')->get() as $d) {
            $baris = $detailPo[(int) $d->IdPesananPembelianDetail] ?? null;

            if ($baris !== null) {
                $sisa = Kuantitas::Dari($baris->JumlahDiterima)->Kurangi(Kuantitas::Dari($d->Jumlah));
                $baris->JumlahDiterima = ($sisa->BernilaiNegatif() ? Kuantitas::Nol() : $sisa)->KeString();
                $baris->save();
            }
        }

        PemrosesPenerimaanBarang::SelaraskanStatusPo($po, $detailPo, $idPengguna, $this->riwayat);
    }
}
