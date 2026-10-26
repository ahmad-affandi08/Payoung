<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Data\HasilPostingJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Kueri\KesiapanPeranAkun;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Enum\JenisDokumenBernomor;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Dokumen\Layanan\PenomorDokumen;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Katalog\Data\DataInfoProdukStok;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Organisasi\Data\DataInfoGudang;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataBarisStokAwal;
use App\Domain\Persediaan\Data\DataBatchMasuk;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Data\HasilCatatMutasi;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Persediaan\Enum\StatusStokAwal;
use App\Domain\Persediaan\Layanan\Hpp\AritmetikaHpp;
use App\Domain\Persediaan\Layanan\PemeriksaStokAwal;
use App\Domain\Persediaan\Layanan\PengunciSaldoStok;
use App\Domain\Persediaan\Layanan\PenyusunJurnalStokAwal;
use App\Domain\Persediaan\Layanan\PetaAkunPersediaan;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\StokAwal;
use App\Domain\Persediaan\Model\StokAwalDetail;
use App\Domain\Tenant\Kueri\PengaturanPersediaanTenant;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Memposting stok awal (F-05a, J-05.1, DesainF05a C.6.4): mutasi stok `StokAwal` + jurnal Dr Persediaan / Cr Ekuitas
 * Saldo Awal, dalam satu transaksi. Idempoten: dokumen yang sudah Diposting dikembalikan apa adanya (tidak ada
 * mutasi atau jurnal kedua).
 *
 * Urutan kunci (DesainF05a C.2): L1 Tenant (S) → L2 dokumen → L3 SaldoStok (urut produk, lokasi) → nomor SA (L7) →
 * buku stok (L3 reentran, L4–L6) → nomor jurnal (L7) → insert. Nomor SA diambil sebelum buku stok supaya
 * `MutasiStok.NomorReferensi` terisi (baris mutasi append-only, tidak bisa diisi belakangan); penghitung SA hanya
 * dikunci posting stok awal yang sudah memegang L3 pasangannya, sehingga tidak membuka deadlock baru.
 *
 * Pemeriksaan ulang saat posting: semua pemeriksaan draf (`PemeriksaStokAwal`), belum ada stok awal Diposting untuk
 * (produk, lokasi) yang sama (`StokAwalSudahAda`), tanggal tidak sebelum mutasi terakhir pasangan itu
 * (`TanggalSebelumMutasiTerakhir`, H-5), dan pemetaan akun siap (`PemetaanAkunBelumAda`). Stok awal setelah stok
 * minus diperbolehkan; selisih BR-04.3 masuk akun Selisih HPP (H-16).
 */
final class PostingStokAwal
{
    public function __construct(
        private readonly PengaturanPersediaanTenant $pengaturan,
        private readonly PemeriksaStokAwal $pemeriksa,
        private readonly PengunciSaldoStok $pengunciSaldo,
        private readonly KesiapanPeranAkun $kesiapanAkun,
        private readonly PetaAkunPersediaan $petaAkun,
        private readonly CatatMutasiStok $catatMutasi,
        private readonly PenomorDokumen $penomor,
        private readonly PenyusunJurnalStokAwal $penyusunJurnal,
        private readonly PostingJurnal $postingJurnal,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    public function Jalankan(StokAwal $stokAwal, int $idPengguna, bool $dariAntrean = false): StokAwal
    {
        return DB::transaction(
            fn (): StokAwal => $this->Posting($stokAwal->Id, $idPengguna, $dariAntrean),
            max(1, (int) config('persediaan.PercobaanTransaksi', 3)),
        );
    }

    private function Posting(int $idStokAwal, int $idPengguna, bool $dariAntrean): StokAwal
    {
        $this->pengaturan->AmbilDenganKunciBaca();
        $dokumen = StokAwal::query()->whereKey($idStokAwal)->lockForUpdate()->firstOrFail();

        if ($dokumen->Status === StatusStokAwal::Diposting) {
            return $dokumen;
        }

        if ($dokumen->Status === StatusStokAwal::Memproses && ! $dariAntrean) {
            throw new PelanggaranAturanBisnis('SedangDiproses', 'Stok awal ini sedang dicatat di latar belakang. Tunggu sampai selesai.');
        }

        if ($dokumen->Status !== StatusStokAwal::Draf && $dokumen->Status !== StatusStokAwal::Memproses) {
            throw new PelanggaranAturanBisnis('StatusTidakSesuai', "Stok awal berstatus {$dokumen->Status->AmbilLabel()} tidak bisa dicatat.");
        }

        $statusAwal = $dokumen->Status;
        $detail = $dokumen->Detail()->orderBy('Urutan')->get();
        $hasilPeriksa = $this->pemeriksa->Periksa($dokumen->IdGudang, CarbonImmutable::parse($dokumen->Tanggal->format('Y-m-d')), self::KeDataBaris($detail));
        $gudang = $hasilPeriksa['Gudang'];
        $produk = $hasilPeriksa['Produk'];

        $idProduk = array_values(array_unique($detail->pluck('IdProduk')->all()));
        sort($idProduk);
        $this->pengunciSaldo->Kunci(array_map(fn (int $id): array => [$id, $dokumen->IdGudang], $idProduk));
        $this->PastikanBelumAdaStokAwal($dokumen, $detail, $idProduk);
        $this->PastikanTanggalSetelahMutasiTerakhir($dokumen, $detail, $idProduk);
        $this->PastikanAkunSiap($produk, $gudang->idOutlet);

        $periode = $dokumen->Tanggal->format('Y-m');
        $nomor = $this->penomor->AmbilNomorBerikutnya(JenisDokumenBernomor::StokAwal, $periode);
        $tanggal = CarbonImmutable::parse($dokumen->Tanggal->format('Y-m-d'));

        $hasilMutasi = $this->catatMutasi->Jalankan(new DataDokumenMutasi(
            JenisReferensiMutasi::StokAwal,
            $dokumen->Id,
            $dokumen->Uuid,
            $nomor,
            $tanggal,
            $idPengguna,
            null,
            $this->SusunBarisMutasi($detail, $produk, $dokumen->IdGudang),
        ));

        // BR-04.3: stok awal setelah stok minus menghasilkan selisih HPP; akunnya diperiksa dengan pesan yang sama
        // (daftar semua peran yang belum dipetakan) sebelum jurnal disusun.
        if (! $hasilMutasi->TotalSelisih()->BernilaiNol()) {
            $this->PastikanAkunSiap($produk, $gudang->idOutlet, [PeranAkun::SelisihHpp]);
        }

        $jurnal = $this->PostingJurnal($dokumen, $nomor, $tanggal, $gudang, $produk, $hasilMutasi, $idPengguna);

        $dokumen->UbahStatus(StatusStokAwal::Diposting);
        $dokumen->fill([
            'Nomor' => $nomor,
            'IdJurnal' => $jurnal?->idJurnal,
            'TotalNilai' => $hasilMutasi->TotalNilaiDiminta()->KeString(),
            'PesanGalat' => null,
            'DipostingOleh' => $idPengguna,
            'DipostingPada' => now(),
            'DiubahOleh' => $idPengguna,
        ]);
        $dokumen->save();

        $this->riwayat->Catat(StokAwal::JENIS_DOKUMEN, $dokumen->Id, $statusAwal->value, StatusStokAwal::Diposting->value, $idPengguna);
        $this->audit->Catat('stok-awal.posting', $dokumen, ['Status' => $statusAwal->value], [
            'Status' => StatusStokAwal::Diposting->value,
            'Nomor' => $nomor,
            'IdJurnal' => $jurnal?->idJurnal,
            'NomorJurnal' => $jurnal?->nomor,
            'TotalNilai' => $dokumen->TotalNilai,
            'JumlahBaris' => $dokumen->JumlahBaris,
        ], idPengguna: $idPengguna);

        return $dokumen;
    }

    /**
     * @param  array<int, DataInfoProdukStok>  $produk
     */
    private function PostingJurnal(
        StokAwal $dokumen,
        string $nomor,
        CarbonImmutable $tanggal,
        DataInfoGudang $gudang,
        array $produk,
        HasilCatatMutasi $hasilMutasi,
        int $idPengguna,
    ): ?HasilPostingJurnal {
        $baris = $this->penyusunJurnal->Susun($hasilMutasi, $produk, $gudang);

        if ($baris === []) {
            return null;
        }

        return $this->postingJurnal->Jalankan(new DataJurnal(
            jenisSumber: JenisSumberJurnal::StokAwal,
            idSumber: $dokumen->Id,
            uuidSumber: $dokumen->Uuid,
            nomorSumber: $nomor,
            tanggal: $tanggal,
            keterangan: mb_substr("Stok awal {$nomor} di {$gudang->nama}", 0, 255),
            baris: $baris,
            idPengguna: $idPengguna,
            kunciSumber: 'Utama',
        ));
    }

    /**
     * @param  Collection<int, StokAwalDetail>  $detail
     * @param  list<int>  $idProduk
     */
    private function PastikanBelumAdaStokAwal(StokAwal $dokumen, Collection $detail, array $idProduk): void
    {
        $sudah = StokAwalDetail::query()
            ->join('StokAwal', 'StokAwal.Id', '=', 'StokAwalDetail.IdStokAwal')
            ->where('StokAwal.IdGudang', $dokumen->IdGudang)
            ->where('StokAwal.Status', StatusStokAwal::Diposting->value)
            ->where('StokAwal.Id', '!=', $dokumen->Id)
            ->whereIn('StokAwalDetail.IdProduk', $idProduk)
            ->sharedLock()
            ->get(['StokAwalDetail.IdProduk', 'StokAwal.Nomor'])
            ->mapWithKeys(fn (StokAwalDetail $b): array => [(int) $b->IdProduk => (string) $b->getAttribute('Nomor')])
            ->all();

        $galat = [];

        foreach ($detail as $baris) {
            if (isset($sudah[$baris->IdProduk])) {
                $galat[] = ['Urutan' => $baris->Urutan, 'Pesan' => "{$baris->NamaProduk} sudah punya stok awal {$sudah[$baris->IdProduk]} di lokasi ini. Koreksi lewat penyesuaian stok, atau batalkan stok awal itu dulu."];
            }
        }

        self::LemparGalatBaris('StokAwalSudahAda', $galat);
    }

    /**
     * @param  Collection<int, StokAwalDetail>  $detail
     * @param  list<int>  $idProduk
     */
    private function PastikanTanggalSetelahMutasiTerakhir(StokAwal $dokumen, Collection $detail, array $idProduk): void
    {
        $terakhir = MutasiStok::query()
            ->where('IdGudang', $dokumen->IdGudang)
            ->whereIn('IdProduk', $idProduk)
            ->groupBy('IdProduk')
            ->sharedLock()
            ->selectRaw('IdProduk, MAX(TanggalBisnis) AS TanggalTerakhir')
            ->get()
            ->mapWithKeys(fn (MutasiStok $m): array => [(int) $m->IdProduk => substr((string) $m->getAttribute('TanggalTerakhir'), 0, 10)])
            ->all();

        $tanggal = $dokumen->Tanggal->format('Y-m-d');
        $galat = [];

        foreach ($detail as $baris) {
            $akhir = $terakhir[$baris->IdProduk] ?? null;

            if ($akhir !== null && $tanggal < $akhir) {
                $galat[] = ['Urutan' => $baris->Urutan, 'Pesan' => "{$baris->NamaProduk} sudah punya mutasi stok tanggal ".CarbonImmutable::parse($akhir)->format('d/m/Y').'. Tanggal stok awal tidak boleh sebelum mutasi terakhir.'];
            }
        }

        self::LemparGalatBaris('TanggalSebelumMutasiTerakhir', $galat, 'Tanggal');
    }

    /**
     * @param  array<int, DataInfoProdukStok>  $produk
     * @param  list<PeranAkun>  $tambahan  peran lain yang dipakai jurnal (misal Selisih HPP)
     */
    private function PastikanAkunSiap(array $produk, ?int $idOutlet, array $tambahan = []): void
    {
        $peran = [];

        foreach ($produk as $info) {
            $peran[$this->petaAkun->UntukJenis($info->jenis)->value] = true;
        }

        $peran[PeranAkun::EkuitasSaldoAwal->value] = true;

        foreach ($tambahan as $satu) {
            $peran[$satu->value] = true;
        }

        $kesiapan = $this->kesiapanAkun->Periksa(array_map(fn (string $p): PeranAkun => PeranAkun::from($p), array_keys($peran)), $idOutlet);

        if (! $kesiapan['Siap']) {
            $label = implode(', ', array_map(fn (array $p): string => $p['Label'], $kesiapan['PeranBelumDipetakan']));

            throw new PelanggaranAturanBisnis(
                'PemetaanAkunBelumAda',
                "Akun untuk {$label} belum dipetakan. Tekan \"Perbaiki otomatis\" di Akuntansi › Pemetaan akun atau minta Akuntan memetakan akun.",
                detail: ['PeranBelumDipetakan' => $kesiapan['PeranBelumDipetakan']],
            );
        }
    }

    /**
     * Baris mutasi: satu per baris dokumen (`P/{IdDetail}`), atau satu per nomor seri (`P/{IdDetail}/{i}`) dengan
     * nilai baris dibagi: n−1 nomor pertama mendapat HppSatuan dibulatkan ke bawah 2 desimal, nomor terakhir sisanya
     * (DesainF05a C.3 contoh 6, `AritmetikaHpp::AlokasikanNilaiSeri`), sehingga Σ = Nilai baris.
     *
     * @param  Collection<int, StokAwalDetail>  $detail
     * @param  array<int, DataInfoProdukStok>  $produk
     * @return list<DataBarisMutasi>
     */
    private function SusunBarisMutasi(Collection $detail, array $produk, int $idGudang): array
    {
        $hasil = [];

        foreach ($detail as $baris) {
            $hpp = BigDecimal::of($baris->HppSatuan);
            $nilai = Uang::Dari($baris->Nilai);
            $seri = $baris->DaftarNomorSeri ?? [];

            if ($produk[$baris->IdProduk]->pelacakan === PelacakanProduk::Seri && $seri !== []) {
                $alokasi = AritmetikaHpp::AlokasikanNilaiSeri(count($seri), $hpp);

                foreach (array_values($seri) as $i => $nomorSeri) {
                    $nilaiSeri = $alokasi[$i];
                    $hasil[] = new DataBarisMutasi(
                        kunciBaris: 'P/'.$baris->Id.'/'.($i + 1),
                        idProduk: $baris->IdProduk,
                        idGudang: $idGudang,
                        jenisMutasi: JenisMutasi::StokAwal,
                        jumlah: Kuantitas::Dari(1),
                        modeNilai: ModeNilaiMutasi::Ditentukan,
                        nilai: $nilaiSeri,
                        hppSatuan: $hpp,
                        idReferensiDetail: $baris->Id,
                        nomorSeriMasuk: $nomorSeri,
                    );
                }

                continue;
            }

            $hasil[] = new DataBarisMutasi(
                kunciBaris: 'P/'.$baris->Id,
                idProduk: $baris->IdProduk,
                idGudang: $idGudang,
                jenisMutasi: JenisMutasi::StokAwal,
                jumlah: Kuantitas::Dari($baris->Jumlah),
                modeNilai: ModeNilaiMutasi::Ditentukan,
                nilai: $nilai,
                hppSatuan: $hpp,
                idReferensiDetail: $baris->Id,
                batchMasuk: $baris->NomorBatch === null ? null : new DataBatchMasuk(
                    $baris->NomorBatch,
                    $baris->TanggalKedaluwarsa === null ? null : CarbonImmutable::parse($baris->TanggalKedaluwarsa->format('Y-m-d')),
                ),
            );
        }

        return $hasil;
    }

    /**
     * @param  Collection<int, StokAwalDetail>  $detail
     * @return list<DataBarisStokAwal>
     */
    private static function KeDataBaris(Collection $detail): array
    {
        return array_values($detail->map(fn (StokAwalDetail $b): DataBarisStokAwal => new DataBarisStokAwal(
            $b->IdProduk,
            Kuantitas::Dari($b->Jumlah),
            BigDecimal::of($b->HppSatuan),
            $b->NomorBatch,
            $b->TanggalKedaluwarsa === null ? null : CarbonImmutable::parse($b->TanggalKedaluwarsa->format('Y-m-d')),
            array_values($b->DaftarNomorSeri ?? []),
        ))->all());
    }

    /**
     * @param  list<array{Urutan: int, Pesan: string}>  $galat
     */
    private static function LemparGalatBaris(string $kode, array $galat, string $bidang = 'Baris'): void
    {
        if ($galat === []) {
            return;
        }

        $pertama = $galat[0];

        throw new PelanggaranAturanBisnis(
            $kode,
            "Baris {$pertama['Urutan']}: {$pertama['Pesan']}".(count($galat) > 1 ? ' (dan '.(count($galat) - 1).' baris lain)' : ''),
            $bidang,
            detail: ['Baris' => $galat],
        );
    }
}
