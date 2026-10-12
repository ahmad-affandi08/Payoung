<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Pembelian\Data\DataReturPembelian;
use App\Domain\Pembelian\Enum\StatusFakturPembelian;
use App\Domain\Pembelian\Layanan\PemrosesPenerimaanBarang;
use App\Domain\Pembelian\Layanan\PengalokasiNilai;
use App\Domain\Pembelian\Layanan\PenomorPembelian;
use App\Domain\Pembelian\Layanan\PenyusunJurnalPembelian;
use App\Domain\Pembelian\Model\FakturPembelian;
use App\Domain\Pembelian\Model\FakturPembelianDetail;
use App\Domain\Pembelian\Model\PenerimaanBarang;
use App\Domain\Pembelian\Model\PenerimaanBarangDetail;
use App\Domain\Pembelian\Model\ReturPembelian;
use App\Domain\Pembelian\Model\ReturPembelianDetail;
use App\Domain\Persediaan\Aksi\CatatMutasiStok;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Persediaan\Kueri\MutasiDokumen;
use App\Domain\Tenant\Kueri\PengaturanPersediaanTenant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Retur pembelian dari satu GRN (F-04 fase 1, izin `pembelian.kelola`): jumlah (satuan dasar) ≤ diterima − sudah
 * diretur; produk batch memakai batch penerimaannya, produk seri menyebut nomor seri yang diterima di GRN itu. Stok
 * keluar `ReturPembelian` bernilai HPP penerimaan (nilai GRN sebanding jumlah; sisa terakhir = sisa nilai) lewat buku
 * stok. Hutang berkurang: GRN sudah difakturkan → sisa hutang faktur (harga faktur + ongkir + PPN sebanding jumlah,
 * bagian yang memotong sisa hutang; sisanya **pengembalian uang dari pemasok** `NilaiRefund`, diterima ke akun kas/bank
 * pilihan operator — E8, kasus faktur yang sudah dibayar dan belanja stok tunai); belum difakturkan → hutang belum
 * difakturkan. Jurnal J-04.5 dengan penyeimbang Selisih HPP: Dr Hutang Usaha (bagian pemotong) + Dr kas/bank (refund),
 * Cr Persediaan, Cr PPN Masukan. Audit `retur-pembelian.posting`.
 *
 * Urutan kunci: L1 Tenant (S) → GRN → faktur → penghitung RB (L7) → buku stok → penghitung JU.
 */
final class SimpanReturPembelian
{
    public function __construct(
        private readonly PengaturanPersediaanTenant $pengaturanPersediaan,
        private readonly DaftarAkunPilihan $akun,
        private readonly InfoGudang $infoGudang,
        private readonly InfoProdukStok $infoProduk,
        private readonly MutasiDokumen $mutasiDokumen,
        private readonly PemrosesPenerimaanBarang $pemroses,
        private readonly PengalokasiNilai $alokasi,
        private readonly PenomorPembelian $penomor,
        private readonly CatatMutasiStok $catatMutasi,
        private readonly PenyusunJurnalPembelian $penyusunJurnal,
        private readonly PostingJurnal $postingJurnal,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis PenerimaanTidakValid, AlasanTidakValid, MelebihiDiterima, AkunRefundWajib, …
     */
    public function Jalankan(DataReturPembelian $data): ReturPembelian
    {
        $alasan = trim($data->alasan);

        if (mb_strlen($alasan) < 5 || mb_strlen($alasan) > 255) {
            throw new PelanggaranAturanBisnis('AlasanTidakValid', 'Alasan retur wajib diisi, 5 sampai 255 karakter.', 'Alasan');
        }

        return DB::transaction(fn (): ReturPembelian => $this->Proses($data, $alasan), 3);
    }

    private function Proses(DataReturPembelian $data, string $alasan): ReturPembelian
    {
        $this->pengaturanPersediaan->AmbilDenganKunciBaca();
        $grn = PenerimaanBarang::query()->where('Uuid', $data->uuidPenerimaan)->lockForUpdate()->first();

        if ($grn === null || $grn->Status !== StatusDokumenTerposting::Diposting) {
            throw new PelanggaranAturanBisnis('PenerimaanTidakValid', 'Penerimaan barang tidak ditemukan atau sudah dibatalkan.', 'UuidPenerimaan');
        }

        $faktur = $grn->IdFakturPembelian === null ? null : FakturPembelian::query()->whereKey($grn->IdFakturPembelian)->lockForUpdate()->first();
        $faktur = $faktur !== null && $faktur->Status !== StatusFakturPembelian::Dibatalkan ? $faktur : null;
        $this->pemroses->PastikanTanggal($data->tanggal, $grn->IdOutlet);

        if ($data->baris === []) {
            throw new PelanggaranAturanBisnis('BarisKosong', 'Isi minimal satu barang yang diretur.', 'Baris');
        }

        $detail = PenerimaanBarangDetail::query()->where('IdPenerimaanBarang', $grn->Id)->orderBy('Urutan')->get()->keyBy('Id');
        $detailFaktur = $faktur === null ? collect() : FakturPembelianDetail::query()->where('IdFakturPembelian', $faktur->Id)->get()->keyBy('IdPenerimaanBarangDetail');
        $mutasiAsal = [];

        foreach ($this->mutasiDokumen->AmbilRingkasan(JenisReferensiMutasi::PenerimaanBarang, $grn->Id) as $m) {
            $mutasiAsal[$m['KunciBaris']] = $m;
        }

        $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique($detail->pluck('IdProduk')->all())), true);
        $olahan = [];
        $sudah = [];

        foreach ($data->baris as $i => $b) {
            /** @var PenerimaanBarangDetail|null $d */
            $d = $detail->get($b->idPenerimaanBarangDetail);

            if ($d === null) {
                throw self::Galat($i, 'Jumlah', 'PenerimaanTidakValid', 'Baris penerimaan tidak ditemukan.');
            }

            $info = $produk[$d->IdProduk];
            $jumlah = $b->jumlahDasar;
            $seri = [];

            if ($info->pelacakan === PelacakanProduk::Seri) {
                $seri = array_values(array_unique(array_filter(array_map('trim', $b->nomorSeri), fn (string $n): bool => $n !== '')));
                $jumlah = Kuantitas::Dari(count($seri));

                foreach ($seri as $n) {
                    if (! in_array($n, $d->DaftarNomorSeri ?? [], true)) {
                        throw self::Galat($i, 'NomorSeri', 'NomorSeriTidakDikenal', "Nomor seri {$n} tidak diterima di penerimaan ini.");
                    }
                }
            }

            $sisa = Kuantitas::Dari($d->JumlahDasar)->Kurangi(Kuantitas::Dari($d->JumlahDiretur))->Kurangi($sudah[$d->Id] ?? Kuantitas::Nol());

            if (! $jumlah->KeDesimal()->isPositive() || $jumlah->Bandingkan($sisa) > 0) {
                throw self::Galat($i, 'Jumlah', 'MelebihiDiterima', "Jumlah retur {$d->NamaProduk} harus lebih dari 0 dan paling banyak ".str_replace('.', ',', (string) $sisa->KeDesimal()->strippedOfTrailingZeros())." {$info->simbolSatuan} (diterima dikurangi yang sudah diretur).");
            }

            if (! $info->bolehDesimal && ! $jumlah->KeDesimal()->getFractionalPart()->isZero()) {
                throw self::Galat($i, 'Jumlah', 'JumlahTidakValid', "Jumlah retur {$d->NamaProduk} harus bilangan bulat {$info->simbolSatuan}.");
            }

            $sudah[$d->Id] = ($sudah[$d->Id] ?? Kuantitas::Nol())->Tambah($jumlah);
            $habis = $sudah[$d->Id]->Tambah(Kuantitas::Dari($d->JumlahDiretur))->SamaDengan(Kuantitas::Dari($d->JumlahDasar));
            $nilai = self::Sebanding(Uang::Dari($d->Nilai), Uang::Dari($d->NilaiDiretur), $jumlah, Kuantitas::Dari($d->JumlahDasar), $habis);
            /** @var FakturPembelianDetail|null $f */
            $f = $detailFaktur->get($d->Id);
            [$nilaiHutang, $pajak] = [$nilai, Uang::Nol()];

            if ($f !== null) {
                $dasarFaktur = Kuantitas::Dari($f->JumlahDasar);
                $habisFaktur = Kuantitas::Dari($f->JumlahDiretur)->Tambah($jumlah)->SamaDengan($dasarFaktur);
                $nilaiHutang = self::Sebanding(Uang::Dari($f->Subtotal)->Tambah(Uang::Dari($f->AlokasiOngkir)), Uang::Dari($f->NilaiDiretur), $jumlah, $dasarFaktur, $habisFaktur);
                $pajak = self::Sebanding(Uang::Dari($f->Pajak), Uang::Dari($f->PajakDiretur), $jumlah, $dasarFaktur, $habisFaktur);
            }

            $olahan[] = ['Detail' => $d, 'DetailFaktur' => $f, 'Jumlah' => $jumlah, 'Nilai' => $nilai, 'NilaiHutang' => $nilaiHutang, 'Pajak' => $pajak, 'Seri' => $seri, 'Simbol' => $info->simbolSatuan];
        }

        $nilaiBarang = array_reduce($olahan, fn (Uang $t, array $o): Uang => $t->Tambah($o['Nilai']), Uang::Nol());
        $nilaiHutang = array_reduce($olahan, fn (Uang $t, array $o): Uang => $t->Tambah($o['NilaiHutang']), Uang::Nol());
        $pajak = array_reduce($olahan, fn (Uang $t, array $o): Uang => $t->Tambah($o['Pajak']), Uang::Nol());

        // E8: bagian nilai retur yang melebihi sisa hutang faktur (sudah dibayar / belanja stok tunai) dikembalikan
        // pemasok sebagai uang; sisanya memotong hutang. Tanpa faktur tidak ada uang yang dibayar, jadi tidak ada refund.
        $nilaiFaktur = $nilaiHutang->Tambah($pajak);
        $sisaHutang = $faktur === null ? Uang::Nol() : ($faktur->AmbilSisa()->BernilaiNegatif() ? Uang::Nol() : $faktur->AmbilSisa());
        $potongHutang = $faktur === null ? Uang::Nol() : ($nilaiFaktur->Bandingkan($sisaHutang) > 0 ? $sisaHutang : $nilaiFaktur);
        $refund = $nilaiFaktur->Kurangi($potongHutang);
        $idAkunRefund = null;

        if ($faktur !== null && ! $refund->BernilaiNol()) {
            $idAkunRefund = $data->uuidAkunRefund === null || $data->uuidAkunRefund === '' ? null : ($this->akun->CariKasBankDariUuid($data->uuidAkunRefund)['Id'] ?? null);

            if ($idAkunRefund === null) {
                throw new PelanggaranAturanBisnis(
                    'AkunRefundWajib',
                    "Nilai retur {$nilaiFaktur->FormatRupiah()} melebihi sisa hutang faktur {$faktur->Nomor} ({$sisaHutang->FormatRupiah()}). Pilih akun kas/bank penerima pengembalian uang {$refund->FormatRupiah()} dari pemasok.",
                    'UuidAkunRefund',
                );
            }
        }

        $gudang = $this->infoGudang->AmbilBanyak([$grn->IdGudang])[$grn->IdGudang];
        $retur = ReturPembelian::query()->create([
            'Nomor' => $this->penomor->AmbilNomorLokasi(JenisDokumenBernomor::ReturPembelian, $data->tanggal, $gudang),
            'IdPenerimaanBarang' => $grn->Id,
            'IdPemasok' => $grn->IdPemasok,
            'IdFakturPembelian' => $faktur?->Id,
            'IdGudang' => $grn->IdGudang,
            'IdOutlet' => $grn->IdOutlet,
            'Tanggal' => $data->tanggal->toDateString(),
            'Alasan' => $alasan,
            'Status' => StatusDokumenTerposting::Diposting,
            'NilaiBarang' => $nilaiBarang->KeString(),
            'NilaiHutang' => $nilaiHutang->KeString(),
            'Pajak' => $pajak->KeString(),
            'NilaiRefund' => $refund->KeString(),
            'IdAkunRefund' => $idAkunRefund,
            'DibuatOleh' => $data->idPengguna,
        ]);

        $mutasi = [];

        foreach ($olahan as $i => $o) {
            /** @var PenerimaanBarangDetail $d */
            $d = $o['Detail'];
            $baris = ReturPembelianDetail::query()->create([
                'IdReturPembelian' => $retur->Id,
                'Urutan' => $i + 1,
                'IdPenerimaanBarangDetail' => $d->Id,
                'IdFakturPembelianDetail' => $o['DetailFaktur']?->Id,
                'IdProduk' => $d->IdProduk,
                'NamaProduk' => $d->NamaProduk,
                'SimbolSatuan' => mb_substr($o['Simbol'], 0, 20),
                'JumlahDasar' => $o['Jumlah']->KeString(),
                'Nilai' => $o['Nilai']->KeString(),
                'NilaiHutang' => $o['NilaiHutang']->KeString(),
                'Pajak' => $o['Pajak']->KeString(),
                'IdBatchStok' => $d->IdBatchStok,
                'DaftarNomorSeri' => $o['Seri'] === [] ? null : $o['Seri'],
            ]);
            $mutasi = [...$mutasi, ...$this->SusunMutasi($baris, $d, $grn->IdGudang, $o['Seri'], $mutasiAsal)];

            $d->fill([
                'JumlahDiretur' => Kuantitas::Dari($d->JumlahDiretur)->Tambah($o['Jumlah'])->KeString(),
                'NilaiDiretur' => Uang::Dari($d->NilaiDiretur)->Tambah($o['Nilai'])->KeString(),
            ])->save();

            if ($o['DetailFaktur'] instanceof FakturPembelianDetail) {
                $f = $o['DetailFaktur'];
                $f->fill([
                    'JumlahDiretur' => Kuantitas::Dari($f->JumlahDiretur)->Tambah($o['Jumlah'])->KeString(),
                    'NilaiDiretur' => Uang::Dari($f->NilaiDiretur)->Tambah($o['NilaiHutang'])->KeString(),
                    'PajakDiretur' => Uang::Dari($f->PajakDiretur)->Tambah($o['Pajak'])->KeString(),
                ])->save();
            }
        }

        $hasil = $this->catatMutasi->Jalankan(new DataDokumenMutasi(
            JenisReferensiMutasi::ReturPembelian,
            $retur->Id,
            $retur->Uuid,
            $retur->Nomor,
            $data->tanggal,
            $data->idPengguna,
            null,
            $mutasi,
        ));

        $lawan = $faktur === null
            ? [DataBarisJurnal::DariSelisih(PeranAkun::HutangBelumDifakturkan, $nilaiBarang, $grn->IdOutlet)]
            : [
                DataBarisJurnal::DariSelisih(PeranAkun::HutangUsaha, $potongHutang, $grn->IdOutlet),
                $idAkunRefund === null ? null : PenyusunJurnalPembelian::BarisAkun($idAkunRefund, $refund, $grn->IdOutlet, "Pengembalian uang retur {$retur->Nomor}"),
                $faktur->PpnDikreditkan ? DataBarisJurnal::DariSelisih(PeranAkun::PpnMasukan, Uang::Nol()->Kurangi($pajak), $grn->IdOutlet) : null,
            ];
        $jurnal = $this->postingJurnal->Jalankan(new DataJurnal(
            jenisSumber: JenisSumberJurnal::ReturPembelian,
            idSumber: $retur->Id,
            uuidSumber: $retur->Uuid,
            nomorSumber: $retur->Nomor,
            tanggal: $data->tanggal,
            keterangan: mb_substr("Retur pembelian {$retur->Nomor} atas {$grn->Nomor}", 0, 255),
            baris: PenyusunJurnalPembelian::Seimbangkan([...$this->penyusunJurnal->BarisPersediaan($hasil, $produk, $grn->IdOutlet), ...$lawan], $grn->IdOutlet),
            idPengguna: $data->idPengguna,
        ));

        $retur->IdJurnal = $jurnal->idJurnal;
        $retur->save();

        if ($faktur !== null) {
            $asal = $faktur->Status;
            // Hanya bagian pemotong hutang: bagian refund sudah dibayar, jadi tidak ada sisa tagihan untuk dikurangi.
            $faktur->JumlahRetur = Uang::Dari($faktur->JumlahRetur)->Tambah($potongHutang)->KeString();
            $faktur->SelaraskanStatus();
            $faktur->save();

            if ($asal !== $faktur->Status) {
                $this->riwayat->Catat(FakturPembelian::JENIS_DOKUMEN, $faktur->Id, $asal->value, $faktur->Status->value, $data->idPengguna);
            }
        }

        $this->riwayat->Catat(ReturPembelian::JENIS_DOKUMEN, $retur->Id, null, StatusDokumenTerposting::Diposting->value, $data->idPengguna);
        $this->audit->Catat('retur-pembelian.posting', $retur, nilaiBaru: [
            'Nomor' => $retur->Nomor,
            'NomorPenerimaan' => $grn->Nomor,
            'NomorFaktur' => $faktur?->Nomor,
            'NilaiBarang' => $retur->NilaiBarang,
            'NilaiHutang' => $retur->NilaiHutang,
            'Pajak' => $retur->Pajak,
            'NilaiRefund' => $retur->NilaiRefund,
            'Alasan' => $alasan,
            'NomorJurnal' => $jurnal->nomor,
        ], idPengguna: $data->idPengguna);

        return $retur;
    }

    /**
     * Mutasi keluar per baris retur (`P/{IdDetail}`), atau per nomor seri (`P/{IdDetail}/{k}`, nilai dibagi rata).
     *
     * @param  list<string>  $seri
     * @param  array<string, array{IdNomorSeri: int|null}>  $mutasiAsal  kunci = KunciBaris mutasi GRN
     * @return list<DataBarisMutasi>
     */
    private function SusunMutasi(ReturPembelianDetail $baris, PenerimaanBarangDetail $d, int $idGudang, array $seri, array $mutasiAsal): array
    {
        if ($seri === []) {
            return [new DataBarisMutasi(
                kunciBaris: 'P/'.$baris->Id,
                idProduk: $baris->IdProduk,
                idGudang: $idGudang,
                jenisMutasi: JenisMutasi::ReturPembelian,
                jumlah: Kuantitas::Dari($baris->JumlahDasar)->Negasi(),
                modeNilai: ModeNilaiMutasi::Ditentukan,
                nilai: Uang::Dari($baris->Nilai),
                idReferensiDetail: $baris->Id,
                idBatchStok: $d->IdBatchStok,
            )];
        }

        $bagian = $this->alokasi->Alokasikan(Uang::Dari($baris->Nilai), array_map(fn (): Uang => Uang::Dari(1), $seri));
        $posisi = array_flip($d->DaftarNomorSeri ?? []);
        $hasil = [];

        foreach ($seri as $k => $nomor) {
            $hasil[] = new DataBarisMutasi(
                kunciBaris: 'P/'.$baris->Id.'/'.($k + 1),
                idProduk: $baris->IdProduk,
                idGudang: $idGudang,
                jenisMutasi: JenisMutasi::ReturPembelian,
                jumlah: Kuantitas::Dari(-1),
                modeNilai: ModeNilaiMutasi::Ditentukan,
                nilai: $bagian[$k],
                idReferensiDetail: $baris->Id,
                idNomorSeri: $mutasiAsal['P/'.$d->Id.'/'.($posisi[$nomor] + 1)]['IdNomorSeri'] ?? null,
            );
        }

        return $hasil;
    }

    /** Nilai sebanding jumlah: bagian terakhir (retur menghabiskan sisa) = total − yang sudah terpakai. */
    private static function Sebanding(Uang $total, Uang $terpakai, Kuantitas $jumlah, Kuantitas $dasar, bool $habis): Uang
    {
        if ($habis) {
            return $total->Kurangi($terpakai);
        }

        return Uang::Dari(BigDecimal::of($total->KeString())->multipliedBy($jumlah->KeDesimal())->dividedBy($dasar->KeDesimal(), Uang::SKALA, RoundingMode::HalfUp));
    }

    private static function Galat(int $indeks, string $bidang, string $kode, string $pesan): PelanggaranAturanBisnis
    {
        return new PelanggaranAturanBisnis($kode, 'Baris '.($indeks + 1).": {$pesan}", "Baris.{$indeks}.{$bidang}", detail: ['Baris' => $indeks + 1]);
    }
}
