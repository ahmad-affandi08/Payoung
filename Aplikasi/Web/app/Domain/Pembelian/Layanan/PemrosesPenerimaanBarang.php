<?php

declare(strict_types=1);

namespace App\Domain\Pembelian\Layanan;

use App\Domain\Akuntansi\Layanan\PenjagaKunciPeriode;
use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Data\DataInfoProdukStok;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Organisasi\Data\DataInfoGudang;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pembelian\Data\DataBarisPenerimaanBarang;
use App\Domain\Pembelian\Data\DataBarisTerhitung;
use App\Domain\Pembelian\Data\DataPajakPembelian;
use App\Domain\Pembelian\Enum\StatusPesananPembelian;
use App\Domain\Pembelian\Model\Pemasok;
use App\Domain\Pembelian\Model\PenerimaanBarang;
use App\Domain\Pembelian\Model\PenerimaanBarangDetail;
use App\Domain\Pembelian\Model\PesananPembelian;
use App\Domain\Pembelian\Model\PesananPembelianDetail;
use App\Domain\Pembelian\Peristiwa\PenerimaanBarangDicatat;
use App\Domain\Persediaan\Aksi\CatatMutasiStok;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataBatchMasuk;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Data\HasilCatatMutasi;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Tenant\Kueri\PengaturanPembelianTenant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Inti penerimaan barang (GRN) F-04 fase 1, dipakai `TerimaBarang` dan `SimpanBelanjaStok` di dalam transaksi
 * pemanggil (L1 tenant & L2 PO sudah dipegang):
 *
 * 1. Tanggal tidak di masa depan (tanggal bisnis outlet) dan periodenya terbuka.
 * 2. Baris dari PO: produk/satuan/harga dari baris PO, diskon sebanding jumlah; BR-04.1 Σ diterima ≤ jumlah PO ×
 *    (1 + `ToleransiPenerimaanPersen`). Tanpa PO: `PenyelesaiBarisPembelian`.
 * 3. Batch wajib nomor batch untuk produk batch; seri wajib nomor seri sebanyak jumlah dasar (bulat, unik).
 * 4. PPN masukan (pemasok PKP, `TarifPajak` pada tanggal GRN); biaya = ongkir + PPN yang tidak dapat dikreditkan,
 *    dialokasikan sebanding subtotal. Nilai baris = subtotal + alokasi = harga landed (BR-04.2); PPN yang dapat
 *    dikreditkan tidak masuk nilai persediaan.
 * 5. Dokumen `GR/{OUTLET}/{YYMM}/{SEQ4}` + baris, lalu buku stok `CatatMutasiStok` (`PenerimaanPembelian`,
 *    `Ditentukan`); HPP rata-rata BR-04.2 & stok minus BR-04.3 dihitung buku stok. Batch tercatat di baris.
 * 6. Baris PO: `JumlahDiterima` bertambah; status PO → DiterimaSebagian/Diterima.
 */
final class PemrosesPenerimaanBarang
{
    public const MAKS_BARIS = 500;

    public const MAKS_NOMOR_SERI_PER_BARIS = 1000;

    public function __construct(
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PenjagaKunciPeriode $penjagaPeriode,
        private readonly PengaturanPembelianTenant $pengaturanPembelian,
        private readonly InfoProdukStok $infoProduk,
        private readonly PenyelesaiBarisPembelian $penyelesai,
        private readonly PenghitungPajakPembelian $pajak,
        private readonly PengalokasiNilai $alokasi,
        private readonly PenomorPembelian $penomor,
        private readonly CatatMutasiStok $catatMutasi,
        private readonly PencatatRiwayatStatus $riwayat,
    ) {}

    /**
     * @param  list<DataBarisPenerimaanBarang>  $masukan
     * @param  array{PathLampiran: string, NamaLampiran: string, MimeLampiran: string, UkuranLampiran: int}|null  $lampiran
     * @return array{Dokumen: PenerimaanBarang, Mutasi: HasilCatatMutasi, Produk: array<int, DataInfoProdukStok>, Pajak: DataPajakPembelian}
     */
    public function Buat(
        ?PesananPembelian $po,
        ?Pemasok $pemasok,
        DataInfoGudang $gudang,
        CarbonImmutable $tanggal,
        array $masukan,
        Uang $ongkir,
        ?string $nomorSuratJalan,
        ?string $catatan,
        ?array $lampiran,
        bool $belanjaStok,
        int $idPengguna,
    ): array {
        $this->PastikanTanggal($tanggal, $gudang->idOutlet);

        if ($masukan === [] || count($masukan) > self::MAKS_BARIS) {
            throw new PelanggaranAturanBisnis('BarisKosong', 'Isi 1 sampai '.self::MAKS_BARIS.' baris barang yang diterima.', 'Baris');
        }

        if ($ongkir->BernilaiNegatif()) {
            throw new PelanggaranAturanBisnis('HargaTidakValid', 'Ongkir tidak boleh negatif.', 'Ongkir');
        }

        [$baris, $detailPo] = $po === null ? [$this->HitungTanpaPo($masukan), []] : $this->HitungDariPo($po, $masukan);
        $this->PeriksaPelacakan($baris, $masukan);

        $subtotal = array_reduce($baris, fn (Uang $t, DataBarisTerhitung $b): Uang => $t->Tambah($b->subtotal), Uang::Nol());
        $pajak = $this->pajak->Hitung($pemasok !== null && $pemasok->Pkp, $gudang->idOutlet, $tanggal, $subtotal);
        $biaya = $ongkir->Tambah($pajak->dikreditkan ? Uang::Nol() : $pajak->pajak);
        $alokasiBiaya = $this->alokasi->Alokasikan($biaya, array_map(fn (DataBarisTerhitung $b): Uang => $b->subtotal, $baris));
        $totalNilai = $subtotal->Tambah($biaya);

        $dokumen = PenerimaanBarang::query()->create([
            'Nomor' => $this->penomor->AmbilNomorLokasi(JenisDokumenBernomor::PenerimaanBarang, $tanggal, $gudang),
            'IdPesananPembelian' => $po?->Id,
            'IdPemasok' => $pemasok?->Id,
            'IdGudang' => $gudang->id,
            'IdOutlet' => $gudang->idOutlet,
            'Tanggal' => $tanggal->toDateString(),
            'Status' => StatusDokumenTerposting::Diposting,
            'NomorSuratJalan' => self::Bersihkan($nomorSuratJalan, 60),
            'Catatan' => self::Bersihkan($catatan, 500),
            'TerminHari' => $po !== null ? $po->TerminHari : ($pemasok !== null ? $pemasok->TerminHari : 0),
            'Pkp' => $pemasok !== null && $pemasok->Pkp,
            'TarifPpn' => $pajak->tarif,
            'PengaliDppPembilang' => $pajak->pengaliDppPembilang,
            'PengaliDppPenyebut' => $pajak->pengaliDppPenyebut,
            'PpnDikreditkan' => $pajak->dikreditkan,
            'Subtotal' => $subtotal->KeString(),
            'Ongkir' => $ongkir->KeString(),
            'Pajak' => $pajak->pajak->KeString(),
            'TotalNilai' => $totalNilai->KeString(),
            'BelanjaStok' => $belanjaStok,
            'DibuatOleh' => $idPengguna,
            ...($lampiran ?? []),
        ]);

        $detail = [];

        foreach ($baris as $i => $b) {
            $nilai = $b->subtotal->Tambah($alokasiBiaya[$i]);
            $m = $masukan[$i];
            $pelacakan = $b->produk->pelacakan;
            $detail[$i] = PenerimaanBarangDetail::query()->create([
                'IdPenerimaanBarang' => $dokumen->Id,
                'Urutan' => $i + 1,
                'IdPesananPembelianDetail' => $po === null ? null : $m->idPesananPembelianDetail,
                'IdProduk' => $b->produk->id,
                'NamaProduk' => mb_substr($b->produk->nama, 0, 150),
                'Sku' => $b->produk->sku,
                'IdProdukSatuan' => $b->idProdukSatuan,
                'SimbolSatuan' => mb_substr($b->simbolSatuan, 0, 20),
                'Konversi' => $b->konversi->KeString(),
                'Jumlah' => $b->jumlah->KeString(),
                'JumlahDasar' => $b->jumlahDasar->KeString(),
                'Harga' => $b->harga->KeString(),
                'Diskon' => $b->diskon->KeString(),
                'Subtotal' => $b->subtotal->KeString(),
                'AlokasiBiaya' => $alokasiBiaya[$i]->KeString(),
                'Nilai' => $nilai->KeString(),
                'HppSatuan' => (string) BigDecimal::of($nilai->KeString())->dividedBy($b->jumlahDasar->KeDesimal(), 6, RoundingMode::HalfUp),
                'NomorBatch' => $pelacakan === PelacakanProduk::Batch ? trim((string) $m->nomorBatch) : null,
                'TanggalKedaluwarsa' => $pelacakan === PelacakanProduk::Batch ? $m->tanggalKedaluwarsa?->toDateString() : null,
                'DaftarNomorSeri' => $pelacakan === PelacakanProduk::Seri ? array_values(array_map('trim', $m->nomorSeri)) : null,
            ]);
        }

        $hasil = $this->catatMutasi->Jalankan(new DataDokumenMutasi(
            JenisReferensiMutasi::PenerimaanBarang,
            $dokumen->Id,
            $dokumen->Uuid,
            $dokumen->Nomor,
            $tanggal,
            $idPengguna,
            null,
            $this->SusunMutasi($detail, $baris, $gudang->id),
        ));

        foreach ($detail as $d) {
            $idBatch = $hasil->baris['P/'.$d->Id]->idBatchStok ?? null;

            if ($idBatch !== null) {
                $d->IdBatchStok = $idBatch;
                $d->save();
            }
        }

        if ($po !== null) {
            $this->PerbaruiPo($po, $detailPo, $baris, $masukan, $idPengguna);
        }

        $produk = [];

        foreach ($baris as $b) {
            $produk[$b->produk->id] = $b->produk;
        }

        // D-266: harga beli terakhir produk-produk ini berubah; aturan harga otomatis (domain Katalog) diperiksa setelah commit.
        PenerimaanBarangDicatat::dispatch($dokumen->IdTenant, $dokumen->Id, array_keys($produk));

        return ['Dokumen' => $dokumen, 'Mutasi' => $hasil, 'Produk' => $produk, 'Pajak' => $pajak];
    }

    /**
     * @throws PelanggaranAturanBisnis TanggalMasaDepan, PeriodeTerkunci
     */
    public function PastikanTanggal(CarbonImmutable $tanggal, ?int $idOutlet): void
    {
        $hariIni = $this->tanggalBisnis->Hitung($idOutlet);

        if ($tanggal->toDateString() > $hariIni->toDateString()) {
            throw new PelanggaranAturanBisnis('TanggalMasaDepan', 'Tanggal tidak boleh setelah hari ini ('.$hariIni->format('d/m/Y').').', 'Tanggal');
        }

        $this->penjagaPeriode->PastikanTerbuka($tanggal);
    }

    /**
     * @param  list<DataBarisPenerimaanBarang>  $masukan
     * @return list<DataBarisTerhitung>
     */
    private function HitungTanpaPo(array $masukan): array
    {
        $produk = $this->penyelesai->AmbilProduk(array_values(array_unique(array_filter(array_map(fn (DataBarisPenerimaanBarang $b): ?string => $b->uuidProduk, $masukan), 'is_string'))));
        $hasil = [];

        foreach ($masukan as $i => $b) {
            if ($b->uuidProduk === null || $b->harga === null) {
                throw PenyelesaiBarisPembelian::Galat($i, 'Produk', 'ProdukTidakDikenal', 'Pilih produk dan isi harganya.');
            }

            $hasil[] = $this->penyelesai->Hitung($i, $produk, $b->uuidProduk, $b->uuidProdukSatuan, $b->jumlah, $b->harga, $b->diskon ?? Uang::Nol());
        }

        return $hasil;
    }

    /**
     * Baris dari PO: satuan, harga, dan diskon (sebanding jumlah) dari baris PO; BR-04.1 per baris PO.
     *
     * @param  list<DataBarisPenerimaanBarang>  $masukan
     * @return array{0: list<DataBarisTerhitung>, 1: array<int, PesananPembelianDetail>}
     */
    private function HitungDariPo(PesananPembelian $po, array $masukan): array
    {
        if (! $po->Status->CekBolehDiterima()) {
            throw new PelanggaranAturanBisnis('StatusTidakSesuai', "Pesanan pembelian berstatus {$po->Status->AmbilLabel()} tidak bisa menerima barang. Hanya PO Disetujui atau Diterima sebagian.", 'UuidPesananPembelian');
        }

        $detailPo = PesananPembelianDetail::query()->where('IdPesananPembelian', $po->Id)->orderBy('Id')->lockForUpdate()->get()->keyBy('Id')->all();
        $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique(array_map(fn (PesananPembelianDetail $d): int => $d->IdProduk, $detailPo))), true);
        $toleransi = $this->pengaturanPembelian->Ambil()->toleransiPenerimaanPersen;
        $hasil = [];
        /** @var array<int, Kuantitas> $diterima */
        $diterima = [];

        foreach ($masukan as $i => $b) {
            $d = $b->idPesananPembelianDetail === null ? null : ($detailPo[$b->idPesananPembelianDetail] ?? null);

            if ($d === null) {
                throw PenyelesaiBarisPembelian::Galat($i, 'Produk', 'BarisPoTidakDikenal', 'Baris pesanan pembelian tidak ditemukan.');
            }

            $jumlahPo = Kuantitas::Dari($d->Jumlah);
            $diskonPo = BigDecimal::of($d->Diskon);
            $diskon = $diskonPo->isZero() ? Uang::Nol() : Uang::Dari($diskonPo->multipliedBy($b->jumlah->KeDesimal())->dividedBy($jumlahPo->KeDesimal(), Uang::SKALA, RoundingMode::HalfUp));
            $bruto = BigDecimal::of($d->Harga)->multipliedBy($b->jumlah->KeDesimal())->toScale(Uang::SKALA, RoundingMode::HalfUp);

            if ($diskon->Bandingkan(Uang::Dari($bruto)) > 0) {
                $diskon = Uang::Dari($bruto);
            }

            $hasil[] = PenyelesaiBarisPembelian::HitungNilai($i, $produk[$d->IdProduk], $d->IdProdukSatuan, $d->SimbolSatuan, Kuantitas::Dari($d->Konversi), $b->jumlah, Uang::Dari($d->Harga), $diskon);
            $diterima[$d->Id] = ($diterima[$d->Id] ?? Kuantitas::Dari($d->JumlahDiterima))->Tambah($b->jumlah);
            $batas = $jumlahPo->KeDesimal()->multipliedBy(BigDecimal::of(100)->plus($toleransi))->dividedBy(100, Kuantitas::SKALA, RoundingMode::Down);

            if ($diterima[$d->Id]->KeDesimal()->isGreaterThan($batas)) {
                $sisa = $batas->minus(Kuantitas::Dari($d->JumlahDiterima)->KeDesimal());

                throw PenyelesaiBarisPembelian::Galat($i, 'Jumlah', 'MelebihiPesanan', "{$d->NamaProduk} melebihi pesanan: sisa yang boleh diterima ".self::FormatJumlah($sisa)." {$d->SimbolSatuan}"
                    .($toleransi->isZero() ? '' : " (termasuk toleransi {$toleransi}%)").'. Ubah toleransi penerimaan di pengaturan pembelian bila memang dikirim lebih.');
            }
        }

        return [$hasil, $detailPo];
    }

    /**
     * @param  list<DataBarisTerhitung>  $baris
     * @param  list<DataBarisPenerimaanBarang>  $masukan
     */
    private function PeriksaPelacakan(array $baris, array $masukan): void
    {
        $seriDokumen = [];

        foreach ($baris as $i => $b) {
            $m = $masukan[$i];

            if ($b->produk->pelacakan === PelacakanProduk::Batch && trim((string) $m->nomorBatch) === '') {
                throw PenyelesaiBarisPembelian::Galat($i, 'NomorBatch', 'BatchWajib', "{$b->produk->nama} memakai batch: isi nomor batch.");
            }

            if ($b->produk->pelacakan !== PelacakanProduk::Seri) {
                continue;
            }

            $nomor = array_values(array_filter(array_map('trim', $m->nomorSeri), fn (string $n): bool => $n !== ''));

            if (! $b->jumlahDasar->KeDesimal()->getFractionalPart()->isZero() || count($nomor) !== $b->jumlahDasar->KeDesimal()->toInt() || count($nomor) > self::MAKS_NOMOR_SERI_PER_BARIS) {
                throw PenyelesaiBarisPembelian::Galat($i, 'NomorSeri', 'NomorSeriWajib', "{$b->produk->nama} memakai nomor seri: isi tepat ".self::FormatJumlah($b->jumlahDasar->KeDesimal()).' nomor seri (satu per unit, maks. '.self::MAKS_NOMOR_SERI_PER_BARIS.').');
            }

            foreach ($nomor as $n) {
                $kunci = $b->produk->id.':'.mb_strtolower($n);

                if (isset($seriDokumen[$kunci])) {
                    throw PenyelesaiBarisPembelian::Galat($i, 'NomorSeri', 'NomorSeriGanda', "Nomor seri {$n} tercatat lebih dari sekali di penerimaan ini.");
                }

                $seriDokumen[$kunci] = true;
            }
        }
    }

    /**
     * Satu baris mutasi per baris GRN (`P/{IdDetail}`), atau satu per nomor seri (`P/{IdDetail}/{i}`) dengan nilai
     * baris dibagi rata (sisa terbesar) sehingga Σ = Nilai baris.
     *
     * @param  array<int, PenerimaanBarangDetail>  $detail
     * @param  list<DataBarisTerhitung>  $baris
     * @return list<DataBarisMutasi>
     */
    private function SusunMutasi(array $detail, array $baris, int $idGudang): array
    {
        $hasil = [];

        foreach ($detail as $i => $d) {
            $nilai = Uang::Dari($d->Nilai);
            $seri = $d->DaftarNomorSeri ?? [];

            if ($baris[$i]->produk->pelacakan === PelacakanProduk::Seri) {
                $bagian = $this->alokasi->Alokasikan($nilai, array_map(fn (): Uang => Uang::Dari(1), $seri));

                foreach (array_values($seri) as $k => $nomor) {
                    $hasil[] = new DataBarisMutasi(
                        kunciBaris: 'P/'.$d->Id.'/'.($k + 1),
                        idProduk: $d->IdProduk,
                        idGudang: $idGudang,
                        jenisMutasi: JenisMutasi::PenerimaanPembelian,
                        jumlah: Kuantitas::Dari(1),
                        modeNilai: ModeNilaiMutasi::Ditentukan,
                        nilai: $bagian[$k],
                        idReferensiDetail: $d->Id,
                        nomorSeriMasuk: $nomor,
                    );
                }

                continue;
            }

            $hasil[] = new DataBarisMutasi(
                kunciBaris: 'P/'.$d->Id,
                idProduk: $d->IdProduk,
                idGudang: $idGudang,
                jenisMutasi: JenisMutasi::PenerimaanPembelian,
                jumlah: Kuantitas::Dari($d->JumlahDasar),
                modeNilai: ModeNilaiMutasi::Ditentukan,
                nilai: $nilai,
                idReferensiDetail: $d->Id,
                batchMasuk: $d->NomorBatch === null ? null : new DataBatchMasuk($d->NomorBatch, $d->TanggalKedaluwarsa === null ? null : CarbonImmutable::parse($d->TanggalKedaluwarsa->format('Y-m-d'))),
            );
        }

        return $hasil;
    }

    /**
     * @param  array<int, PesananPembelianDetail>  $detailPo
     * @param  list<DataBarisTerhitung>  $baris
     * @param  list<DataBarisPenerimaanBarang>  $masukan
     */
    private function PerbaruiPo(PesananPembelian $po, array $detailPo, array $baris, array $masukan, int $idPengguna): void
    {
        foreach ($baris as $i => $b) {
            $d = $detailPo[(int) $masukan[$i]->idPesananPembelianDetail];
            $d->JumlahDiterima = Kuantitas::Dari($d->JumlahDiterima)->Tambah($b->jumlah)->KeString();
            $d->save();
        }

        self::SelaraskanStatusPo($po, $detailPo, $idPengguna, $this->riwayat);
    }

    /**
     * Status PO dari jumlah diterima: semua baris ≥ jumlah → Diterima; ada yang diterima → DiterimaSebagian; tidak ada
     * → Disetujui. PO Ditutup/Dibatalkan tidak berubah.
     *
     * @param  array<int, PesananPembelianDetail>  $detailPo
     */
    public static function SelaraskanStatusPo(PesananPembelian $po, array $detailPo, int $idPengguna, PencatatRiwayatStatus $riwayat): void
    {
        if ($po->Status === StatusPesananPembelian::Ditutup || $po->Status === StatusPesananPembelian::Dibatalkan) {
            return;
        }

        $semua = true;
        $ada = false;

        foreach ($detailPo as $d) {
            $terima = Kuantitas::Dari($d->JumlahDiterima);
            $ada = $ada || $terima->KeDesimal()->isPositive();
            $semua = $semua && $terima->Bandingkan(Kuantitas::Dari($d->Jumlah)) >= 0;
        }

        $tujuan = $semua && $ada ? StatusPesananPembelian::Diterima : ($ada ? StatusPesananPembelian::DiterimaSebagian : StatusPesananPembelian::Disetujui);

        if ($tujuan === $po->Status) {
            return;
        }

        $asal = $po->Status;
        $po->UbahStatus($tujuan);
        $po->DiubahOleh = $idPengguna;
        $po->save();
        $riwayat->Catat(PesananPembelian::JENIS_DOKUMEN, $po->Id, $asal->value, $tujuan->value, $idPengguna);
    }

    private static function Bersihkan(?string $teks, int $maks): ?string
    {
        $teks = $teks === null ? '' : trim($teks);

        return $teks === '' ? null : mb_substr($teks, 0, $maks);
    }

    private static function FormatJumlah(BigDecimal $jumlah): string
    {
        return str_replace('.', ',', (string) $jumlah->strippedOfTrailingZeros());
    }
}
