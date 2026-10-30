<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataBatchMasuk;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Data\HasilCatatMutasi;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Persediaan\Enum\StatusStokOpname;
use App\Domain\Persediaan\Layanan\Hpp\AritmetikaHpp;
use App\Domain\Persediaan\Layanan\PemeriksaLokasiDokumen;
use App\Domain\Persediaan\Layanan\PencatatJurnalPersediaan;
use App\Domain\Persediaan\Layanan\PengunciSaldoStok;
use App\Domain\Persediaan\Layanan\PenyusunJurnalPersediaan;
use App\Domain\Persediaan\Model\BatchStok;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\NomorSeri;
use App\Domain\Persediaan\Model\StokOpname;
use App\Domain\Persediaan\Model\StokOpnameDetail;
use App\Domain\Tenant\Kueri\PengaturanPersediaanTenant;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Menyetujui stok opname (F-05b, BR-05.3, J-05.4/J-05.5): untuk setiap baris yang dihitung, selisih = fisik −
 * (snapshot + Σ mutasi sejak snapshot), dicatat sebagai `OpnameLebih` (+) atau `OpnameKurang` (−) dinilai HPP berjalan
 * (batch/nomor seri ikut). Jurnal opname kurang Dr Susut & Barang Rusak / Cr Persediaan, opname lebih Dr Persediaan /
 * Cr Selisih HPP. Tanggal posting = tanggal bisnis hari persetujuan. Izin `persediaan.penyesuaian.setujui` (rute).
 * Idempoten: opname yang sudah Disetujui dikembalikan apa adanya.
 *
 * Urutan kunci: L1 Tenant (S) → dokumen → L3 SaldoStok semua produk yang disesuaikan (urut) → Σ mutasi sejak snapshot
 * (stabil karena saldo terkunci) → buku stok → jurnal.
 */
final class SetujuiStokOpname
{
    public function __construct(
        private readonly PengaturanPersediaanTenant $pengaturan,
        private readonly PemeriksaLokasiDokumen $pemeriksaLokasi,
        private readonly InfoProdukStok $infoProduk,
        private readonly InfoGudang $infoGudang,
        private readonly PengunciSaldoStok $pengunciSaldo,
        private readonly CatatMutasiStok $catatMutasi,
        private readonly PenyusunJurnalPersediaan $penyusunJurnal,
        private readonly PencatatJurnalPersediaan $pencatatJurnal,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(StokOpname $opname, int $idPengguna): StokOpname
    {
        return DB::transaction(fn (): StokOpname => $this->Setujui($opname->Id, $idPengguna), max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }

    private function Setujui(int $idOpname, int $idPengguna): StokOpname
    {
        $this->pengaturan->AmbilDenganKunciBaca();
        $opname = StokOpname::query()->whereKey($idOpname)->lockForUpdate()->firstOrFail();

        if ($opname->Status === StatusStokOpname::Disetujui) {
            return $opname;
        }

        if ($opname->Status !== StatusStokOpname::Ditinjau) {
            throw new PelanggaranAturanBisnis('StatusTidakSesuai', "Stok opname berstatus {$opname->Status->AmbilLabel()} tidak bisa disetujui. Ajukan untuk ditinjau dulu.");
        }

        $tanggal = $this->pemeriksaLokasi->HariIni($opname->IdOutlet);
        $dihitung = StokOpnameDetail::query()->where('IdStokOpname', $opname->Id)->whereNotNull('JumlahFisik')->orderBy('Urutan')->get();
        $this->pengunciSaldo->Kunci(array_values($dihitung->map(fn (StokOpnameDetail $d): array => [$d->IdProduk, $opname->IdGudang])->all()));
        $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique($dihitung->pluck('IdProduk')->all())), true);
        $barisMutasi = [];
        $sejakTanpaPelacakan = $this->HitungMutasiTanpaPelacakan($dihitung->all(), $produk, $opname->IdGudang);

        foreach ($dihitung as $d) {
            $pelacakan = isset($produk[$d->IdProduk]) ? $produk[$d->IdProduk]->pelacakan : PelacakanProduk::Tidak;
            $this->LengkapiPelacakan($d, $opname->IdGudang);
            $sejak = $sejakTanpaPelacakan[$d->Id] ?? $this->HitungMutasiSejakSnapshot($d, $opname->IdGudang, $pelacakan);
            $selisih = Kuantitas::Dari((string) $d->JumlahFisik)->Kurangi(Kuantitas::Dari($d->JumlahSistem)->Tambah($sejak));
            $d->MutasiSelamaOpname = $sejak->KeString();
            $d->Selisih = $selisih->KeString();

            if (AritmetikaHpp::CekNol($selisih)) {
                $d->NilaiSelisih = '0.00';

                continue;
            }

            $lebih = ! $selisih->BernilaiNegatif();
            $barisMutasi[] = new DataBarisMutasi(
                kunciBaris: 'O/'.$d->Id,
                idProduk: $d->IdProduk,
                idGudang: $opname->IdGudang,
                jenisMutasi: $lebih ? JenisMutasi::OpnameLebih : JenisMutasi::OpnameKurang,
                jumlah: $selisih,
                modeNilai: ModeNilaiMutasi::Berjalan,
                idReferensiDetail: $d->Id,
                batchMasuk: $lebih && $pelacakan === PelacakanProduk::Batch
                    ? new DataBatchMasuk((string) $d->NomorBatch, $d->TanggalKedaluwarsa === null ? null : CarbonImmutable::parse($d->TanggalKedaluwarsa->format('Y-m-d')))
                    : null,
                idBatchStok: ! $lebih && $pelacakan === PelacakanProduk::Batch ? $d->IdBatchStok : null,
                nomorSeriMasuk: $lebih && $pelacakan === PelacakanProduk::Seri ? $d->NomorSeri : null,
                idNomorSeri: ! $lebih && $pelacakan === PelacakanProduk::Seri ? $d->IdNomorSeri : null,
            );
        }

        $hasil = $barisMutasi === [] ? null : $this->catatMutasi->Jalankan(new DataDokumenMutasi(
            JenisReferensiMutasi::StokOpname,
            $opname->Id,
            $opname->Uuid,
            $opname->Nomor,
            $tanggal,
            $idPengguna,
            null,
            $barisMutasi,
        ));
        [$lebih, $kurang] = $this->SimpanNilai(array_values($dihitung->all()), $hasil);
        $jurnal = null;

        if ($hasil !== null) {
            $jurnal = $this->pencatatJurnal->Posting(
                JenisSumberJurnal::StokOpname,
                $opname->Id,
                $opname->Uuid,
                $opname->Nomor,
                $tanggal,
                "Stok opname {$opname->Nomor}",
                $this->penyusunJurnal->Susun(
                    array_values(array_map(fn ($h): array => [$h, $h->jumlah->BernilaiNegatif() ? PeranAkun::SusutPersediaan : PeranAkun::SelisihHpp], $hasil->baris)),
                    $produk,
                    $this->infoGudang->AmbilBanyak([$opname->IdGudang]),
                    $opname->IdOutlet,
                ),
                $idPengguna,
            );
        }

        $opname->UbahStatus(StatusStokOpname::Disetujui);
        $opname->fill([
            'TotalNilaiLebih' => $lebih->KeString(),
            'TotalNilaiKurang' => $kurang->KeString(),
            'TanggalPosting' => $tanggal->format('Y-m-d'),
            'DisetujuiOleh' => $idPengguna,
            'DisetujuiPada' => now(),
            'DiubahOleh' => $idPengguna,
        ]);
        $opname->save();

        $this->riwayat->Catat(StokOpname::JENIS_DOKUMEN, $opname->Id, StatusStokOpname::Ditinjau->value, StatusStokOpname::Disetujui->value, $idPengguna);
        $this->audit->Catat('stok-opname.setujui', $opname, ['Status' => StatusStokOpname::Ditinjau->value], [
            'Status' => StatusStokOpname::Disetujui->value,
            'BarisDisesuaikan' => count($barisMutasi),
            'TotalNilaiLebih' => $opname->TotalNilaiLebih,
            'TotalNilaiKurang' => $opname->TotalNilaiKurang,
            'NomorJurnal' => $jurnal?->nomor,
        ], idPengguna: $idPengguna);

        return $opname;
    }

    /** Batch/nomor seri yang baru tercatat setelah baris dibuat (misal diterima selama opname) ditautkan sekarang. */
    private function LengkapiPelacakan(StokOpnameDetail $d, int $idGudang): void
    {
        if ($d->NomorBatch !== null && $d->IdBatchStok === null) {
            $d->IdBatchStok = BatchStok::query()->where('IdProduk', $d->IdProduk)->where('IdGudang', $idGudang)->where('NomorBatch', $d->NomorBatch)->value('Id');
        }

        if ($d->NomorSeri !== null && $d->IdNomorSeri === null) {
            $d->IdNomorSeri = NomorSeri::query()->where('IdProduk', $d->IdProduk)->where('Nomor', $d->NomorSeri)->value('Id');
        }
    }

    /**
     * Versi massal `HitungMutasiSejakSnapshot` untuk produk tanpa batch/nomor seri: baris yang penanda snapshot-nya sama
     * (umumnya semua baris satu opname) dihitung dalam satu kueri `GROUP BY`, bukan satu kueri per baris selagi saldo
     * semua produk terkunci. Baris produk berbatch/berseri tidak ada di hasil dan tetap dihitung per baris.
     *
     * @param  list<StokOpnameDetail>  $dihitung
     * @param  array<int, mixed>  $produk  info produk per Id (`pelacakan`)
     * @return array<int, Kuantitas> Σ mutasi per Id baris opname
     */
    private function HitungMutasiTanpaPelacakan(array $dihitung, array $produk, int $idGudang): array
    {
        $perSnapshot = [];

        foreach ($dihitung as $d) {
            $pelacakan = isset($produk[$d->IdProduk]) ? $produk[$d->IdProduk]->pelacakan : PelacakanProduk::Tidak;

            if ($pelacakan === PelacakanProduk::Tidak) {
                $perSnapshot[(int) $d->IdMutasiSnapshot][] = $d;
            }
        }

        $hasil = [];

        foreach ($perSnapshot as $snapshot => $baris) {
            $idProduk = array_values(array_unique(array_map(fn (StokOpnameDetail $d): int => $d->IdProduk, $baris)));
            $jumlah = [];

            foreach (array_chunk($idProduk, 1000) as $potongan) {
                $jumlah += MutasiStok::query()
                    ->where('IdGudang', $idGudang)
                    ->where('Id', '>', $snapshot)
                    ->whereIn('IdProduk', $potongan)
                    ->groupBy('IdProduk')
                    ->selectRaw('IdProduk, SUM(Jumlah) as Total')
                    ->pluck('Total', 'IdProduk')
                    ->all();
            }

            foreach ($baris as $d) {
                $hasil[$d->Id] = Kuantitas::Dari(BigDecimal::of((string) ($jumlah[$d->IdProduk] ?? '0'))->toScale(4));
            }
        }

        return $hasil;
    }

    /** BR-05.3: Σ mutasi produk (batch/nomor seri) di lokasi ini sesudah penanda snapshot baris. */
    private function HitungMutasiSejakSnapshot(StokOpnameDetail $d, int $idGudang, PelacakanProduk $pelacakan): Kuantitas
    {
        $jumlah = MutasiStok::query()
            ->where('IdProduk', $d->IdProduk)
            ->where('IdGudang', $idGudang)
            ->where('Id', '>', $d->IdMutasiSnapshot)
            ->when($pelacakan === PelacakanProduk::Batch, fn ($k) => $k->where('IdBatchStok', $d->IdBatchStok ?? 0))
            ->when($pelacakan === PelacakanProduk::Seri, fn ($k) => $k->where('IdNomorSeri', $d->IdNomorSeri ?? 0))
            ->sum('Jumlah');

        return Kuantitas::Dari(BigDecimal::of((string) $jumlah)->toScale(4));
    }

    /**
     * @param  list<StokOpnameDetail>  $dihitung
     * @return array{0: Uang, 1: Uang} total nilai lebih & kurang (besaran)
     */
    private function SimpanNilai(array $dihitung, ?HasilCatatMutasi $hasil): array
    {
        $lebih = Uang::Nol();
        $kurang = Uang::Nol();

        foreach ($dihitung as $d) {
            $baris = $hasil?->baris['O/'.$d->Id] ?? null;

            if ($baris !== null) {
                $d->NilaiSelisih = $baris->totalHpp->KeString();

                if ($baris->totalHpp->BernilaiNegatif()) {
                    $kurang = $kurang->Tambah(AritmetikaHpp::AmbilMutlak($baris->totalHpp));
                } else {
                    $lebih = $lebih->Tambah($baris->totalHpp);
                }
            }

            $d->save();
        }

        return [$lebih, $kurang];
    }
}
