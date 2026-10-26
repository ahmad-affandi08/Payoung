<?php

declare(strict_types=1);

namespace App\Domain\Persediaan\Aksi;

use App\Domain\Akuntansi\Layanan\PenjagaKunciPeriode;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Katalog\Data\DataInfoProdukStok;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Organisasi\Data\DataInfoGudang;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Data\HasilBarisMutasi;
use App\Domain\Persediaan\Data\HasilCatatMutasi;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\MetodeHpp;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Persediaan\Enum\StatusNomorSeri;
use App\Domain\Persediaan\Layanan\Hpp\AritmetikaHpp;
use App\Domain\Persediaan\Layanan\Hpp\HasilHpp;
use App\Domain\Persediaan\Layanan\Hpp\HppFifo;
use App\Domain\Persediaan\Layanan\Hpp\HppRataRataBergerak;
use App\Domain\Persediaan\Layanan\Hpp\KeadaanHpp;
use App\Domain\Persediaan\Layanan\Hpp\LapisanHpp;
use App\Domain\Persediaan\Layanan\Hpp\MasukanHpp;
use App\Domain\Persediaan\Layanan\Hpp\StrategiHpp;
use App\Domain\Persediaan\Layanan\PelacakBatchStok;
use App\Domain\Persediaan\Layanan\PelacakNomorSeri;
use App\Domain\Persediaan\Layanan\PemberitahuStokMenipis;
use App\Domain\Persediaan\Layanan\PemeriksaStokMinus;
use App\Domain\Persediaan\Layanan\PengunciSaldoStok;
use App\Domain\Persediaan\Model\BatchStok;
use App\Domain\Persediaan\Model\LapisanFifo;
use App\Domain\Persediaan\Model\MutasiStok;
use App\Domain\Persediaan\Model\NomorSeri;
use App\Domain\Persediaan\Model\SaldoStok;
use App\Domain\Tenant\Kueri\PengaturanPersediaanTenant;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aksi publik buku stok (BR-05.1, DesainF05a C.2): mencatat baris MutasiStok satu dokumen, menilai HPP (C.3),
 * menegakkan stok minus BR-05.2 dan pelacakan batch/seri (C.4), lalu memperbarui SaldoStok, LapisanFifo, BatchStok,
 * dan NomorSeri. Dipanggil di dalam transaksi Aksi pemanggil (menjadi savepoint), sehingga dokumen, stok, dan jurnal
 * ter-commit bersama (aturan #10). Tidak menulis `LogAudit` (pemanggil yang mencatat).
 *
 * Urutan kunci global (C.2): L1 Tenant (S) → [L2 dokumen sumber, milik pemanggil] → idempotensi MutasiStok →
 * L3 SaldoStok urut (IdProduk, IdGudang) → L4 BatchStok urut (IdProduk, IdGudang, NomorBatch) → L5 NomorSeri urut
 * (IdProduk, Nomor) → L6 LapisanFifo urut (IdProduk, IdGudang, Id). Idempoten per (JenisReferensi, IdReferensi,
 * KunciBaris): dokumen yang sama dikirim ulang dikembalikan apa adanya (`sudahAda`).
 */
final class CatatMutasiStok
{
    private const PANJANG_KUNCI = 80;

    private const PANJANG_NOMOR_BATCH = 60;

    private const PANJANG_NOMOR_SERI = 100;

    private const UKURAN_POTONGAN = 500;

    /** decimal(18,4): 14 digit bulat. */
    private const BATAS_JUMLAH = '100000000000000';

    /** decimal(18,2): 16 digit bulat. */
    private const BATAS_NILAI = '10000000000000000';

    /** decimal(19,6): 13 digit bulat. */
    private const BATAS_HPP = '10000000000000';

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly PengaturanPersediaanTenant $pengaturanTenant,
        private readonly PenjagaKunciPeriode $penjagaKunciPeriode,
        private readonly InfoProdukStok $infoProduk,
        private readonly InfoGudang $infoGudang,
        private readonly PengunciSaldoStok $pengunciSaldo,
        private readonly PemeriksaStokMinus $pemeriksaStokMinus,
        private readonly PelacakBatchStok $pelacakBatch,
        private readonly PelacakNomorSeri $pelacakSeri,
        private readonly PemberitahuStokMenipis $pemberitahuMenipis,
    ) {}

    public function Jalankan(DataDokumenMutasi $dokumen): HasilCatatMutasi
    {
        $this->ValidasiMasukan($dokumen);

        return DB::transaction(fn (): HasilCatatMutasi => $this->Proses($dokumen), self::AmbilPercobaan());
    }

    private function Proses(DataDokumenMutasi $dokumen): HasilCatatMutasi
    {
        // L1: kunci S Tenant; metode HPP tidak bisa berubah selama dokumen ini dicatat.
        $pengaturan = $this->pengaturanTenant->AmbilDenganKunciBaca();

        // Pemutaran ulang dokumen yang sudah tercatat dikembalikan apa adanya, juga bila periodenya kini terkunci
        // (kirim ulang sinkron POS/F-07 setelah tutup buku tidak boleh berubah menjadi PeriodeTerkunci).
        $ulang = $this->PeriksaIdempotensi($dokumen);

        if ($ulang !== null) {
            return $ulang;
        }

        // F-15/§18: dokumen POS di periode terkunci dibukukan di hari pertama periode terbuka berikutnya.
        $dokumen = $dokumen->DenganTanggal($this->penjagaKunciPeriode->SesuaikanTanggalPosting($dokumen->tanggalBisnis));

        [$produk, $gudang] = $this->AmbilProdukDanGudang($dokumen);
        $this->PeriksaMutasiAsal($dokumen);

        // L3 → L4 → L5 → L6.
        $saldo = $this->pengunciSaldo->Kunci(array_map(fn (DataBarisMutasi $b): array => [$b->idProduk, $b->idGudang], $dokumen->baris));
        [$batchMasuk, $batch] = $this->KunciBatch($dokumen);
        [$seriMasuk, $seri] = $this->KunciSeri($dokumen);
        $keadaan = $this->MuatKeadaan($saldo, $pengaturan->metodeHpp);
        $saldoAwal = array_map(fn (KeadaanHpp $k): Kuantitas => $k->jumlah, $keadaan);

        $strategi = self::PilihStrategi($pengaturan->metodeHpp);
        $sisaBatch = array_map(fn (BatchStok $b): Kuantitas => Kuantitas::Dari($b->JumlahSisa), $batch);
        $posisiSeri = array_map(fn (NomorSeri $s): ?int => $s->Status === StatusNomorSeri::Tersedia ? $s->IdGudang : null, $seri);
        $tindakanSeri = [];
        $olahan = [];

        foreach ($dokumen->baris as $baris) {
            $kunciPasangan = SaldoStok::BuatKunciPasangan($baris->idProduk, $baris->idGudang);
            $infoProduk = $produk[$baris->idProduk];
            $infoGudang = $gudang[$baris->idGudang];
            $keadaanPasangan = $keadaan[$kunciPasangan];
            $keluar = $baris->jumlah->BernilaiNegatif();
            $idBatchStok = null;
            $idNomorSeri = null;
            $stokTidakCukup = false;

            if ($keluar && $dokumen->abaikanBatasMinus) {
                // F-07b (§18.3): transaksi yang sudah terjadi di perangkat tetap dicatat; pelanggaran dilaporkan.
                $stokTidakCukup = $this->pemeriksaStokMinus->CekTidakCukup($infoProduk, $pengaturan, $keadaanPasangan->jumlah, $baris->jumlah->Negasi());
            } elseif ($keluar) {
                // BR-05.2 (C.4): stok minus hanya untuk produk tanpa pelacakan yang diizinkan.
                $this->pemeriksaStokMinus->Pastikan($infoProduk, $infoGudang, $pengaturan, $keadaanPasangan->jumlah, $baris->jumlah->Negasi());
            }

            if ($infoProduk->pelacakan === PelacakanProduk::Batch) {
                $idBatchStok = $keluar ? (int) $baris->idBatchStok : $batchMasuk[self::BuatKunciBatch($baris)]->Id;
                $sisaBaru = $sisaBatch[$idBatchStok]->Tambah($baris->jumlah);

                if ($sisaBaru->BernilaiNegatif()) {
                    throw new PelanggaranAturanBisnis(
                        'StokBatchTidakCukup',
                        "Stok batch {$batch[$idBatchStok]->NomorBatch} untuk {$infoProduk->nama} di {$infoGudang->nama} tidak cukup: tersedia "
                            .PemeriksaStokMinus::FormatJumlah($sisaBatch[$idBatchStok]).', dibutuhkan '.PemeriksaStokMinus::FormatJumlah($baris->jumlah->Negasi()).'.',
                        'Jumlah',
                        detail: ['KunciBaris' => $baris->kunciBaris, 'NomorBatch' => $batch[$idBatchStok]->NomorBatch, 'Tersedia' => $sisaBatch[$idBatchStok]->KeString()],
                    );
                }

                $sisaBatch[$idBatchStok] = $sisaBaru;
            }

            if ($infoProduk->pelacakan === PelacakanProduk::Seri) {
                $idNomorSeri = $keluar ? (int) $baris->idNomorSeri : $seriMasuk[self::BuatKunciSeri($baris)]->Id;
                $this->TerapkanSeri($baris, $infoProduk, $infoGudang, $seri[$idNomorSeri], $posisiSeri, $tindakanSeri);
            }

            $hasil = $strategi->Terapkan($keadaanPasangan, new MasukanHpp(
                $baris->jumlah,
                $baris->modeNilai,
                $baris->nilai,
                $baris->hppSatuan,
                $idBatchStok,
                $baris->idMutasiAsal,
                $baris->kunciBaris,
            ));
            self::PastikanDalamBatasKolom($baris->kunciBaris, $hasil, $keadaanPasangan);

            $olahan[] = [
                'Baris' => $baris,
                'Hasil' => $hasil,
                'IdBatchStok' => $idBatchStok,
                'IdNomorSeri' => $idNomorSeri,
                'SaldoSetelah' => $keadaanPasangan->jumlah,
                'NilaiSetelah' => $keadaanPasangan->nilai,
                'HppRataRataSetelah' => $keadaanPasangan->hppRataRata,
                'StokTidakCukup' => $stokTidakCukup,
            ];
        }

        $idMutasi = $this->SimpanMutasi($dokumen, $olahan);
        $this->SimpanLapisan($keadaan, $idMutasi, $dokumen);
        $this->SimpanSaldo($keadaan, $olahan, $idMutasi);
        $this->SimpanPelacakan($batch, $sisaBatch, $seri, $tindakanSeri);
        $this->BeritahuStokMenipis($dokumen, $saldo, $saldoAwal, $keadaan, $produk, $gudang);

        $hasilBaris = [];

        foreach ($olahan as $o) {
            /** @var DataBarisMutasi $b */
            $b = $o['Baris'];
            /** @var HasilHpp $h */
            $h = $o['Hasil'];
            $hasilBaris[$b->kunciBaris] = new HasilBarisMutasi(
                $idMutasi[$b->kunciBaris],
                $b->kunciBaris,
                $b->idProduk,
                $b->idGudang,
                $b->jumlah,
                self::KeSkalaHpp($h->hppSatuan),
                $h->totalHpp,
                $h->nilaiDiminta,
                $h->selisihHpp,
                $o['SaldoSetelah'],
                $o['IdBatchStok'],
                $o['IdNomorSeri'],
                $h->hppTidakDiketahui,
                $o['StokTidakCukup'],
            );
        }

        return new HasilCatatMutasi($hasilBaris, false);
    }

    /**
     * X7 `stok.menipis` (v4.07): pasangan yang saldonya turun melewati stok minimum diberitahukan setelah commit.
     *
     * @param  array<string, SaldoStok>  $saldo
     * @param  array<string, Kuantitas>  $saldoAwal
     * @param  array<string, KeadaanHpp>  $keadaan
     * @param  array<int, DataInfoProdukStok>  $produk
     * @param  array<int, DataInfoGudang>  $gudang
     */
    private function BeritahuStokMenipis(DataDokumenMutasi $dokumen, array $saldo, array $saldoAwal, array $keadaan, array $produk, array $gudang): void
    {
        $perubahan = [];

        foreach ($saldo as $kunci => $s) {
            $perubahan[$kunci] = ['IdProduk' => $s->IdProduk, 'IdGudang' => $s->IdGudang, 'Awal' => $saldoAwal[$kunci], 'Akhir' => $keadaan[$kunci]->jumlah];
        }

        $this->pemberitahuMenipis->Periksa(
            (int) $this->konteks->Ambil(),
            $perubahan,
            $produk,
            $gudang,
            $dokumen->jenisReferensi->value.'-'.$dokumen->idReferensi,
        );
    }

    /**
     * Validasi bentuk masukan tanpa database (C.2 langkah 1).
     */
    private function ValidasiMasukan(DataDokumenMutasi $dokumen): void
    {
        if ($dokumen->baris === []) {
            throw self::Galat('JumlahTidakValid', 'Dokumen mutasi stok tidak punya baris.');
        }

        $kunci = [];
        $batasJumlah = BigDecimal::of(self::BATAS_JUMLAH);
        $batasNilai = BigDecimal::of(self::BATAS_NILAI);
        $batasHpp = BigDecimal::of(self::BATAS_HPP);

        foreach ($dokumen->baris as $baris) {
            $k = $baris->kunciBaris;

            if ($k === '' || mb_strlen($k) > self::PANJANG_KUNCI) {
                throw self::Galat('JumlahTidakValid', 'Kunci baris mutasi wajib diisi dan maksimal '.self::PANJANG_KUNCI.' karakter.', $k);
            }

            if (isset($kunci[$k])) {
                throw self::Galat('JumlahTidakValid', "Kunci baris mutasi {$k} ganda di satu dokumen.", $k);
            }

            $kunci[$k] = true;
            $q = $baris->jumlah->KeDesimal();

            if ($q->isZero() || $q->abs()->isGreaterThanOrEqualTo($batasJumlah)) {
                throw self::Galat('JumlahTidakValid', 'Jumlah mutasi stok tidak boleh 0 dan harus di bawah 100 triliun.', $k);
            }

            $masuk = $q->isPositive();

            if ($baris->idMutasiAsal === null && ($masuk ? ! $baris->jenisMutasi->CekBolehMasuk() : ! $baris->jenisMutasi->CekBolehKeluar())) {
                throw self::Galat(
                    'ArahMutasiTidakSesuai',
                    "Mutasi {$baris->jenisMutasi->AmbilLabel()} tidak boleh ".($masuk ? 'menambah' : 'mengurangi').' stok.',
                    $k,
                );
            }

            if ($baris->modeNilai === ModeNilaiMutasi::Ditentukan) {
                if ($baris->nilai === null || $baris->nilai->BernilaiNegatif() || AritmetikaHpp::KeDesimal($baris->nilai)->isGreaterThanOrEqualTo($batasNilai)) {
                    throw self::Galat('HppTidakValid', 'Nilai mutasi wajib diisi, tidak negatif, dan dalam batas.', $k);
                }
            } elseif ($baris->nilai !== null || $baris->hppSatuan !== null) {
                throw self::Galat('HppTidakValid', 'Mutasi bernilai berjalan tidak menerima nilai atau HPP dari pemanggil.', $k);
            }

            if ($baris->hppSatuan !== null
                && ($baris->hppSatuan->isNegative() || $baris->hppSatuan->strippedOfTrailingZeros()->getScale() > AritmetikaHpp::SKALA_HPP || $baris->hppSatuan->isGreaterThanOrEqualTo($batasHpp))) {
                throw self::Galat('HppTidakValid', 'HPP per satuan tidak boleh negatif dan maksimal 6 desimal.', $k);
            }

            $this->ValidasiBentukPelacakan($baris, $masuk);
        }
    }

    private function ValidasiBentukPelacakan(DataBarisMutasi $baris, bool $masuk): void
    {
        $k = $baris->kunciBaris;
        $adaBatch = $baris->batchMasuk !== null || $baris->idBatchStok !== null;
        $adaSeri = $baris->nomorSeriMasuk !== null || $baris->idNomorSeri !== null;

        if ($adaBatch && $adaSeri) {
            throw self::Galat('JumlahTidakValid', 'Satu baris mutasi tidak boleh sekaligus ber-batch dan ber-nomor seri.', $k);
        }

        if (($masuk && ($baris->idBatchStok !== null || $baris->idNomorSeri !== null))
            || (! $masuk && ($baris->batchMasuk !== null || $baris->nomorSeriMasuk !== null))) {
            throw self::Galat('JumlahTidakValid', 'Batch/nomor seri masuk hanya untuk mutasi masuk. Batch/nomor seri keluar hanya untuk mutasi keluar.', $k);
        }

        if ($baris->batchMasuk !== null) {
            $nomor = trim($baris->batchMasuk->nomorBatch);

            if ($nomor === '' || mb_strlen($nomor) > self::PANJANG_NOMOR_BATCH) {
                throw self::Galat('JumlahTidakValid', 'Nomor batch wajib diisi, maksimal '.self::PANJANG_NOMOR_BATCH.' karakter.', $k);
            }
        }

        if ($baris->nomorSeriMasuk !== null) {
            $nomor = trim($baris->nomorSeriMasuk);

            if ($nomor === '' || mb_strlen($nomor) > self::PANJANG_NOMOR_SERI) {
                throw self::Galat('JumlahTidakValid', 'Nomor seri wajib diisi, maksimal '.self::PANJANG_NOMOR_SERI.' karakter.', $k);
            }
        }

        if ($adaSeri && ! $baris->jumlah->KeDesimal()->abs()->isEqualTo(1)) {
            throw self::Galat('JumlahTidakValid', 'Baris ber-nomor seri harus berjumlah tepat 1.', $k);
        }
    }

    /**
     * C.2 langkah 4: semua kunci sudah tercatat → pemutaran ulang; sebagian → `MutasiGanda`; tidak ada → lanjut.
     *
     * Audit F-07: dibaca tanpa `FOR UPDATE`. Kunci baca pada baris yang belum ada memasang kunci celah indeks
     * (JenisReferensi, IdReferensi, KunciBaris); dua dokumen berbeda yang dicatat bersamaan lalu saling menunggu saat
     * menyisipkan mutasi (terbukti deadlock di test dua koneksi). Dokumen yang sama sudah diserialkan kunci dokumen
     * pemanggil (L2), dan indeks unik tetap menjadi pengaman terakhir.
     */
    private function PeriksaIdempotensi(DataDokumenMutasi $dokumen): ?HasilCatatMutasi
    {
        $kunci = array_map(fn (DataBarisMutasi $b): string => $b->kunciBaris, $dokumen->baris);
        $ada = [];

        foreach (array_chunk($kunci, self::UKURAN_POTONGAN) as $potongan) {
            $baris = MutasiStok::query()
                ->where('JenisReferensi', $dokumen->jenisReferensi->value)
                ->where('IdReferensi', $dokumen->idReferensi)
                ->whereIn('KunciBaris', $potongan)
                ->orderBy('Id')
                ->get();

            foreach ($baris as $m) {
                $ada[$m->KunciBaris] = $m;
            }
        }

        if ($ada === []) {
            return null;
        }

        if (count($ada) !== count($kunci)) {
            throw new PelanggaranAturanBisnis(
                'MutasiGanda',
                'Sebagian baris dokumen ini sudah pernah dicatat ke buku stok. Muat ulang dokumen lalu coba lagi.',
                'Baris',
                detail: ['KunciBarisSudahAda' => array_slice(array_keys($ada), 0, 20)],
            );
        }

        $hasil = [];

        foreach ($kunci as $k) {
            $m = $ada[$k];
            $total = Uang::Dari($m->TotalHpp);
            $selisih = Uang::Dari($m->SelisihHpp);
            $hasil[$k] = new HasilBarisMutasi(
                $m->Id,
                $k,
                $m->IdProduk,
                $m->IdGudang,
                Kuantitas::Dari($m->Jumlah),
                BigDecimal::of($m->HppSatuan),
                $total,
                $total->Kurangi($selisih),
                $selisih,
                Kuantitas::Dari($m->SaldoSetelah),
                $m->IdBatchStok,
                $m->IdNomorSeri,
                false,
            );
        }

        return new HasilCatatMutasi($hasil, true);
    }

    /**
     * C.2 langkah 5: produk & lokasi stok tenant aktif (tenant lain = tidak dikenal), jenis berstok, satuan desimal,
     * dan kelengkapan batch/seri sesuai pelacakan produk.
     *
     * @return array{0: array<int, DataInfoProdukStok>, 1: array<int, DataInfoGudang>}
     */
    private function AmbilProdukDanGudang(DataDokumenMutasi $dokumen): array
    {
        $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique(array_map(fn (DataBarisMutasi $b): int => $b->idProduk, $dokumen->baris))), true);
        $gudang = $this->infoGudang->AmbilBanyak(array_values(array_unique(array_map(fn (DataBarisMutasi $b): int => $b->idGudang, $dokumen->baris))));

        foreach ($dokumen->baris as $baris) {
            $k = $baris->kunciBaris;
            $p = $produk[$baris->idProduk] ?? null;

            if ($p === null || ($p->dihapus && $baris->idMutasiAsal === null)) {
                throw self::Galat('ProdukTidakDikenal', 'Produk tidak ditemukan.', $k, 'Produk');
            }

            if (! isset($gudang[$baris->idGudang])) {
                throw self::Galat('GudangTidakDikenal', 'Lokasi stok tidak ditemukan.', $k, 'Gudang');
            }

            if (! $p->jenis->CekPunyaStok()) {
                throw self::Galat('ProdukTanpaStok', "Produk {$p->nama} berjenis {$p->jenis->AmbilLabel()} tidak punya stok.", $k, 'Produk');
            }

            if (! $p->bolehDesimal && $baris->jumlah->KeDesimal()->getFractionalPart()->isZero() === false) {
                throw self::Galat('JumlahTidakValid', "Jumlah {$p->nama} harus bilangan bulat ({$p->simbolSatuan}).", $k, 'Jumlah');
            }

            $keluar = $baris->jumlah->BernilaiNegatif();

            // `abaikanBatasMinus` hanya untuk produk tanpa pelacakan: stok batch/seri tidak pernah boleh minus.
            if ($dokumen->abaikanBatasMinus && $p->pelacakan !== PelacakanProduk::Tidak) {
                throw self::Galat('PelacakanBelumDidukung', "Produk {$p->nama} memakai {$p->pelacakan->AmbilLabel()}. Pencatatan tanpa batas stok minus hanya untuk produk tanpa batch/nomor seri.", $k, 'Produk');
            }

            $cocok = match ($p->pelacakan) {
                PelacakanProduk::Tidak => $baris->batchMasuk === null && $baris->idBatchStok === null && $baris->nomorSeriMasuk === null && $baris->idNomorSeri === null,
                PelacakanProduk::Batch => $keluar ? $baris->idBatchStok !== null : $baris->batchMasuk !== null,
                PelacakanProduk::Seri => $keluar ? $baris->idNomorSeri !== null : $baris->nomorSeriMasuk !== null,
            };

            if (! $cocok) {
                $pesan = match ($p->pelacakan) {
                    PelacakanProduk::Tidak => "Produk {$p->nama} tidak memakai batch atau nomor seri.",
                    PelacakanProduk::Batch => "Produk {$p->nama} wajib menyebut batch.",
                    PelacakanProduk::Seri => "Produk {$p->nama} wajib menyebut nomor seri.",
                };

                throw self::Galat('JumlahTidakValid', $pesan, $k);
            }
        }

        return [$produk, $gudang];
    }

    /**
     * Baris pembalik (`idMutasiAsal`): baris asal ada di tenant ini, produk & lokasi sama, jenis sama, tanda kebalikan.
     */
    private function PeriksaMutasiAsal(DataDokumenMutasi $dokumen): void
    {
        $idAsal = array_values(array_unique(array_filter(array_map(fn (DataBarisMutasi $b): ?int => $b->idMutasiAsal, $dokumen->baris), fn (?int $id): bool => $id !== null)));

        if ($idAsal === []) {
            return;
        }

        $asal = MutasiStok::query()->whereIn('Id', $idAsal)->get()->keyBy('Id');

        foreach ($dokumen->baris as $baris) {
            if ($baris->idMutasiAsal === null) {
                continue;
            }

            $m = $asal->get($baris->idMutasiAsal);

            if (! $m instanceof MutasiStok
                || $m->IdProduk !== $baris->idProduk
                || $m->IdGudang !== $baris->idGudang
                || $m->JenisMutasi !== $baris->jenisMutasi
                || Kuantitas::Dari($m->Jumlah)->BernilaiNegatif() === $baris->jumlah->BernilaiNegatif()) {
                throw self::Galat(
                    'ArahMutasiTidakSesuai',
                    'Baris pembalik harus membalik mutasi asal yang ada: produk, lokasi stok, dan jenis sama dengan tanda kebalikan.',
                    $baris->kunciBaris,
                );
            }
        }
    }

    /**
     * L4: BatchStok urut (IdProduk, IdGudang, NomorBatch) lewat PelacakBatchStok (Tim D).
     *
     * @return array{0: array<string, BatchStok>, 1: array<int, BatchStok>} [per kunci batch masuk, per Id]
     */
    private function KunciBatch(DataDokumenMutasi $dokumen): array
    {
        $idKeluar = [];

        foreach ($dokumen->baris as $b) {
            if ($b->idBatchStok !== null) {
                $idKeluar[] = $b->idBatchStok;
            }
        }

        // NomorBatch tidak pernah berubah; dibaca tanpa kunci hanya untuk menentukan urutan kunci.
        $nomorKeluar = $idKeluar === [] ? [] : BatchStok::query()->whereIn('Id', array_values(array_unique($idKeluar)))->pluck('NomorBatch', 'Id')->all();
        $antrean = [];

        foreach ($dokumen->baris as $b) {
            if ($b->batchMasuk !== null) {
                $kunciBatch = self::BuatKunciBatch($b);
                $sebelumnya = $antrean[$kunciBatch][3] ?? null;

                // Batch sama di satu dokumen wajib berkedaluwarsa sama (baris kedua tidak lewat PelacakBatchStok).
                if ($sebelumnya instanceof DataBarisMutasi
                    && $sebelumnya->batchMasuk?->tanggalKedaluwarsa?->toDateString() !== $b->batchMasuk->tanggalKedaluwarsa?->toDateString()) {
                    throw self::Galat('BatchKedaluwarsaBerbeda', 'Batch '.trim($b->batchMasuk->nomorBatch).' tercatat dengan dua tanggal kedaluwarsa berbeda di dokumen ini.', $b->kunciBaris, 'TanggalKedaluwarsa');
                }

                $antrean[$kunciBatch] ??= [$b->idProduk, $b->idGudang, trim($b->batchMasuk->nomorBatch), $b];
            } elseif ($b->idBatchStok !== null) {
                $antrean['#'.$b->idBatchStok] = [$b->idProduk, $b->idGudang, (string) ($nomorKeluar[$b->idBatchStok] ?? ''), $b];
            }
        }

        uasort($antrean, fn (array $x, array $y): int => [$x[0], $x[1], $x[2]] <=> [$y[0], $y[1], $y[2]]);
        $perKunci = [];
        $perId = [];

        foreach ($antrean as $kunci => [$idProduk, $idGudang, , $b]) {
            /** @var DataBarisMutasi $b */
            $batch = $b->batchMasuk !== null
                ? $this->pelacakBatch->KunciMasuk($idProduk, $idGudang, $b->batchMasuk, self::AmbilHppMasuk($b))
                : $this->pelacakBatch->KunciKeluar((int) $b->idBatchStok, $idProduk, $idGudang);

            $perKunci[(string) $kunci] = $batch;
            $perId[$batch->Id] ??= $batch;
        }

        foreach ($perKunci as $kunci => $batch) {
            $perKunci[$kunci] = $perId[$batch->Id];
        }

        return [$perKunci, $perId];
    }

    /**
     * L5: NomorSeri urut (IdProduk, Nomor) lewat PelacakNomorSeri (Tim D).
     *
     * @return array{0: array<string, NomorSeri>, 1: array<int, NomorSeri>} [per kunci seri masuk, per Id]
     */
    private function KunciSeri(DataDokumenMutasi $dokumen): array
    {
        $idKeluar = [];

        foreach ($dokumen->baris as $b) {
            if ($b->idNomorSeri !== null) {
                $idKeluar[] = $b->idNomorSeri;
            }
        }

        // Nomor seri tidak pernah berubah; dibaca tanpa kunci hanya untuk menentukan urutan kunci.
        $nomorKeluar = $idKeluar === [] ? [] : NomorSeri::query()->whereIn('Id', array_values(array_unique($idKeluar)))->pluck('Nomor', 'Id')->all();
        $antrean = [];

        foreach ($dokumen->baris as $b) {
            if ($b->nomorSeriMasuk !== null) {
                $kunci = self::BuatKunciSeri($b);

                if (isset($antrean[$kunci])) {
                    throw self::Galat('NomorSeriSudahAda', 'Nomor seri '.trim($b->nomorSeriMasuk).' tercatat masuk lebih dari sekali di dokumen ini.', $b->kunciBaris, 'NomorSeri');
                }

                $antrean[$kunci] = [$b->idProduk, trim($b->nomorSeriMasuk), $b];
            } elseif ($b->idNomorSeri !== null) {
                $antrean['#'.$b->idNomorSeri] = [$b->idProduk, (string) ($nomorKeluar[$b->idNomorSeri] ?? ''), $b];
            }
        }

        uasort($antrean, fn (array $x, array $y): int => [$x[0], $x[1]] <=> [$y[0], $y[1]]);
        $perKunci = [];
        $perId = [];

        foreach ($antrean as $kunci => [$idProduk, $nomor, $b]) {
            /** @var DataBarisMutasi $b */
            $seri = $b->nomorSeriMasuk !== null
                ? $this->pelacakSeri->KunciMasuk($idProduk, $b->idGudang, $nomor)
                : $this->pelacakSeri->KunciKeluar((int) $b->idNomorSeri, $idProduk, $b->idGudang);

            $perKunci[(string) $kunci] = $seri;
            $perId[$seri->Id] ??= $seri;
        }

        foreach ($perKunci as $kunci => $seri) {
            $perKunci[$kunci] = $perId[$seri->Id];
        }

        return [$perKunci, $perId];
    }

    /**
     * Seri masuk: belum tersedia di mana pun; seri keluar: tersedia di lokasi stok ini (C.4).
     *
     * @param  array<int, int|null>  $posisiSeri  Id → IdGudang tempat seri tersedia (null = tidak tersedia)
     * @param  array<int, array{0: bool, 1: int|null, 2: JenisMutasi}>  $tindakanSeri  Id → [masuk?, IdGudang, jenis]
     */
    private function TerapkanSeri(DataBarisMutasi $baris, DataInfoProdukStok $produk, DataInfoGudang $gudang, NomorSeri $seri, array &$posisiSeri, array &$tindakanSeri): void
    {
        $posisi = $posisiSeri[$seri->Id] ?? null;

        if ($baris->jumlah->BernilaiNegatif()) {
            if ($posisi !== $baris->idGudang) {
                throw self::Galat('NomorSeriTidakTersedia', "Nomor seri {$seri->Nomor} untuk {$produk->nama} tidak tersedia di {$gudang->nama}.", $baris->kunciBaris, 'NomorSeri');
            }

            $posisiSeri[$seri->Id] = null;
            $tindakanSeri[$seri->Id] = [false, null, $baris->jenisMutasi];

            return;
        }

        if ($posisi !== null) {
            throw self::Galat('NomorSeriSudahAda', "Nomor seri {$seri->Nomor} untuk {$produk->nama} sudah tersedia di stok.", $baris->kunciBaris, 'NomorSeri');
        }

        $posisiSeri[$seri->Id] = $baris->idGudang;
        $tindakanSeri[$seri->Id] = [true, $baris->idGudang, $baris->jenisMutasi];
    }

    /**
     * Keadaan HPP per pasangan dari SaldoStok terkunci; FIFO juga memuat lapisan terbuka (L6, `FOR UPDATE` urut
     * (IdProduk, IdGudang, Id)) dan HPP lapisan terbaru.
     *
     * @param  array<string, SaldoStok>  $saldo
     * @return array<string, KeadaanHpp>
     */
    private function MuatKeadaan(array $saldo, MetodeHpp $metode): array
    {
        $keadaan = [];

        foreach ($saldo as $kunci => $s) {
            $keadaan[$kunci] = new KeadaanHpp(
                Kuantitas::Dari($s->JumlahTersedia),
                Uang::Dari($s->NilaiPersediaan),
                $s->HppRataRata === null ? null : BigDecimal::of($s->HppRataRata),
            );
        }

        if ($metode !== MetodeHpp::Fifo || $saldo === []) {
            return $keadaan;
        }

        $pasangan = array_values(array_map(fn (SaldoStok $s): array => [$s->IdProduk, $s->IdGudang], $saldo));

        foreach (array_chunk($pasangan, self::UKURAN_POTONGAN) as $potongan) {
            $klausa = PengunciSaldoStok::BuatKlausaPasangan(count($potongan));
            $ikatan = array_merge(...$potongan);

            $lapisan = LapisanFifo::query()
                ->whereRaw($klausa, $ikatan)
                ->where('Habis', false)
                ->orderBy('IdProduk')
                ->orderBy('IdGudang')
                ->orderBy('Id')
                ->lockForUpdate()
                ->get();

            foreach ($lapisan as $l) {
                $keadaan[SaldoStok::BuatKunciPasangan($l->IdProduk, $l->IdGudang)]->lapisan[] = new LapisanHpp(
                    $l->Id,
                    $l->IdMutasiSumber,
                    null,
                    $l->IdBatchStok,
                    Kuantitas::Dari($l->JumlahAwal),
                    Kuantitas::Dari($l->JumlahSisa),
                    BigDecimal::of($l->HppSatuan),
                    Uang::Dari($l->NilaiAwal),
                    Uang::Dari($l->NilaiSisa),
                );
            }

            $terbaru = LapisanFifo::query()
                ->whereIn('Id', LapisanFifo::query()->selectRaw('MAX(Id)')->whereRaw($klausa, $ikatan)->groupBy('IdProduk', 'IdGudang'))
                ->get(['IdProduk', 'IdGudang', 'HppSatuan']);

            foreach ($terbaru as $l) {
                $keadaan[SaldoStok::BuatKunciPasangan($l->IdProduk, $l->IdGudang)]->hppLapisanTerakhir = BigDecimal::of($l->HppSatuan);
            }
        }

        return $keadaan;
    }

    /**
     * Sisipan massal MutasiStok per potongan (urut masukan → Id naik, rantai SaldoSetelah terjaga), lalu baca Id-nya.
     *
     * @param  list<array<string, mixed>>  $olahan
     * @return array<string, int> KunciBaris → Id
     */
    private function SimpanMutasi(DataDokumenMutasi $dokumen, array $olahan): array
    {
        $idTenant = $this->konteks->Wajib();
        $sekarang = Carbon::now();
        $tanggal = $dokumen->tanggalBisnis->toDateString();
        $baris = [];

        foreach ($olahan as $o) {
            /** @var DataBarisMutasi $b */
            $b = $o['Baris'];
            /** @var HasilHpp $h */
            $h = $o['Hasil'];
            /** @var Kuantitas $saldoSetelah */
            $saldoSetelah = $o['SaldoSetelah'];
            /** @var Uang $nilaiSetelah */
            $nilaiSetelah = $o['NilaiSetelah'];
            /** @var BigDecimal|null $rataRata */
            $rataRata = $o['HppRataRataSetelah'];

            $baris[] = [
                'IdTenant' => $idTenant,
                'IdProduk' => $b->idProduk,
                'IdGudang' => $b->idGudang,
                'IdBatchStok' => $o['IdBatchStok'],
                'IdNomorSeri' => $o['IdNomorSeri'],
                'JenisMutasi' => $b->jenisMutasi->value,
                'Jumlah' => $b->jumlah->KeString(),
                'HppSatuan' => (string) self::KeSkalaHpp($h->hppSatuan),
                'TotalHpp' => $h->totalHpp->KeString(),
                'SelisihHpp' => $h->selisihHpp->KeString(),
                'SaldoSetelah' => $saldoSetelah->KeString(),
                'NilaiSetelah' => $nilaiSetelah->KeString(),
                'HppRataRataSetelah' => $rataRata === null ? null : (string) self::KeSkalaHpp($rataRata),
                'JenisReferensi' => $dokumen->jenisReferensi->value,
                'IdReferensi' => $dokumen->idReferensi,
                'IdReferensiDetail' => $b->idReferensiDetail,
                'UuidReferensi' => $dokumen->uuidReferensi,
                'NomorReferensi' => $dokumen->nomorReferensi,
                'KunciBaris' => $b->kunciBaris,
                'IdMutasiAsal' => $b->idMutasiAsal,
                'TanggalBisnis' => $tanggal,
                'DibuatOleh' => $dokumen->idPengguna,
                'IdPerangkat' => $dokumen->idPerangkat,
                'DibuatPada' => $sekarang,
                'DiubahPada' => $sekarang,
            ];
        }

        $id = [];

        foreach (array_chunk($baris, self::UKURAN_POTONGAN) as $potongan) {
            MutasiStok::query()->insert($potongan);

            $id += array_map('intval', MutasiStok::query()
                ->where('JenisReferensi', $dokumen->jenisReferensi->value)
                ->where('IdReferensi', $dokumen->idReferensi)
                ->whereIn('KunciBaris', array_column($potongan, 'KunciBaris'))
                ->pluck('Id', 'KunciBaris')
                ->all());
        }

        return $id;
    }

    /**
     * Lapisan FIFO baru (tertaut ke baris mutasinya) disisipkan; lapisan lama yang terkonsumsi diperbarui.
     *
     * @param  array<string, KeadaanHpp>  $keadaan
     * @param  array<string, int>  $idMutasi
     */
    private function SimpanLapisan(array $keadaan, array $idMutasi, DataDokumenMutasi $dokumen): void
    {
        $idTenant = $this->konteks->Wajib();
        $sekarang = Carbon::now();
        $baru = [];

        foreach ($keadaan as $kunciPasangan => $k) {
            [$idProduk, $idGudang] = array_map('intval', explode(':', (string) $kunciPasangan));

            foreach ($k->lapisan as $l) {
                if (! $l->berubah) {
                    continue;
                }

                if ($l->id === null) {
                    $idSumber = $idMutasi[(string) $l->kunciBarisSumber];
                    $baru[] = [
                        'IdTenant' => $idTenant,
                        'IdProduk' => $idProduk,
                        'IdGudang' => $idGudang,
                        'IdBatchStok' => $l->idBatchStok,
                        'IdMutasiSumber' => $idSumber,
                        'TanggalMasuk' => $dokumen->tanggalBisnis->toDateString(),
                        'JumlahAwal' => $l->jumlahAwal->KeString(),
                        'JumlahSisa' => $l->jumlahSisa->KeString(),
                        'HppSatuan' => (string) self::KeSkalaHpp($l->hppSatuan),
                        'NilaiAwal' => $l->nilaiAwal->KeString(),
                        'NilaiSisa' => $l->nilaiSisa->KeString(),
                        'Habis' => $l->CekHabis(),
                        'DibuatPada' => $sekarang,
                        'DiubahPada' => $sekarang,
                    ];

                    continue;
                }

                LapisanFifo::query()->whereKey($l->id)->update([
                    'JumlahSisa' => $l->jumlahSisa->KeString(),
                    'NilaiSisa' => $l->nilaiSisa->KeString(),
                    'Habis' => $l->CekHabis(),
                ]);
            }
        }

        foreach (array_chunk($baru, self::UKURAN_POTONGAN) as $potongan) {
            LapisanFifo::query()->insert($potongan);
        }
    }

    /**
     * Saldo akhir tiap pasangan yang tersentuh (baris sudah terkunci L3) dalam satu upsert per potongan.
     *
     * @param  array<string, KeadaanHpp>  $keadaan
     * @param  list<array<string, mixed>>  $olahan
     * @param  array<string, int>  $idMutasi
     */
    private function SimpanSaldo(array $keadaan, array $olahan, array $idMutasi): void
    {
        $idTerakhir = [];

        foreach ($olahan as $o) {
            /** @var DataBarisMutasi $b */
            $b = $o['Baris'];
            $idTerakhir[SaldoStok::BuatKunciPasangan($b->idProduk, $b->idGudang)] = $idMutasi[$b->kunciBaris];
        }

        $idTenant = $this->konteks->Wajib();
        $sekarang = Carbon::now();
        $baris = [];

        foreach ($idTerakhir as $kunci => $idMutasiTerakhir) {
            [$idProduk, $idGudang] = array_map('intval', explode(':', $kunci));
            $k = $keadaan[$kunci];
            $baris[] = [
                'IdTenant' => $idTenant,
                'IdProduk' => $idProduk,
                'IdGudang' => $idGudang,
                'JumlahTersedia' => $k->jumlah->KeString(),
                'NilaiPersediaan' => $k->nilai->KeString(),
                'HppRataRata' => $k->hppRataRata === null ? null : (string) self::KeSkalaHpp($k->hppRataRata),
                'IdMutasiStokTerakhir' => $idMutasiTerakhir,
                'DibuatPada' => $sekarang,
                'DiubahPada' => $sekarang,
            ];
        }

        foreach (array_chunk($baris, self::UKURAN_POTONGAN) as $potongan) {
            SaldoStok::query()->toBase()->upsert(
                $potongan,
                ['IdTenant', 'IdProduk', 'IdGudang'],
                ['JumlahTersedia', 'NilaiPersediaan', 'HppRataRata', 'IdMutasiStokTerakhir', 'DiubahPada'],
            );
        }
    }

    /**
     * @param  array<int, BatchStok>  $batch
     * @param  array<int, Kuantitas>  $sisaBatch
     * @param  array<int, NomorSeri>  $seri
     * @param  array<int, array{0: bool, 1: int|null, 2: JenisMutasi}>  $tindakanSeri
     */
    private function SimpanPelacakan(array $batch, array $sisaBatch, array $seri, array $tindakanSeri): void
    {
        foreach ($batch as $id => $b) {
            $selisih = $sisaBatch[$id]->Kurangi(Kuantitas::Dari($b->JumlahSisa));

            if (! AritmetikaHpp::CekNol($selisih)) {
                $this->pelacakBatch->Terapkan($b, $selisih);
            }
        }

        foreach ($tindakanSeri as $id => [$masuk, $idGudang, $jenis]) {
            if ($masuk) {
                $this->pelacakSeri->TandaiMasuk($seri[$id], (int) $idGudang);
            } else {
                $this->pelacakSeri->TandaiKeluar($seri[$id], $jenis === JenisMutasi::Penjualan ? StatusNomorSeri::Terjual : StatusNomorSeri::Keluar);
            }
        }
    }

    /**
     * Hasil HPP dan saldo setelah baris ini harus muat di kolom DECIMAL: jumlah (18,4), nilai (18,2), HPP (19,6).
     * Masukan per baris sudah dibatasi, tetapi jumlahan beberapa baris ke saldo atau HPP = nilai ÷ jumlah kecil bisa
     * melampauinya; tanpa pemeriksaan ini MySQL menolak dengan galat SQL (500) di tengah transaksi.
     */
    private static function PastikanDalamBatasKolom(string $kunciBaris, HasilHpp $hasil, KeadaanHpp $keadaan): void
    {
        if ($keadaan->jumlah->KeDesimal()->abs()->isGreaterThanOrEqualTo(self::BATAS_JUMLAH)) {
            throw self::Galat('JumlahTidakValid', 'Saldo stok setelah mutasi ini melampaui batas 100 triliun.', $kunciBaris);
        }

        $batasNilai = BigDecimal::of(self::BATAS_NILAI);
        $batasHpp = BigDecimal::of(self::BATAS_HPP);
        $nilaiLewat = array_filter(
            [$keadaan->nilai, $hasil->totalHpp, $hasil->nilaiDiminta, $hasil->selisihHpp],
            fn (Uang $nilai): bool => AritmetikaHpp::KeDesimal($nilai)->abs()->isGreaterThanOrEqualTo($batasNilai),
        );
        $hppLewat = $hasil->hppSatuan->abs()->isGreaterThanOrEqualTo($batasHpp)
            || ($keadaan->hppRataRata !== null && $keadaan->hppRataRata->abs()->isGreaterThanOrEqualTo($batasHpp));

        if ($nilaiLewat !== [] || $hppLewat) {
            throw self::Galat('HppTidakValid', 'Nilai persediaan atau HPP per satuan setelah mutasi ini melampaui batas yang bisa dicatat.', $kunciBaris);
        }
    }

    private static function PilihStrategi(MetodeHpp $metode): StrategiHpp
    {
        return match ($metode) {
            MetodeHpp::RataRata => new HppRataRataBergerak,
            MetodeHpp::Fifo => new HppFifo,
        };
    }

    /** HPP per satuan penerimaan (informasi `BatchStok.HppSatuan` saat batch pertama dibuat). */
    private static function AmbilHppMasuk(DataBarisMutasi $baris): ?BigDecimal
    {
        if ($baris->hppSatuan !== null) {
            return $baris->hppSatuan;
        }

        return $baris->modeNilai === ModeNilaiMutasi::Ditentukan && $baris->nilai !== null
            ? AritmetikaHpp::Hpp($baris->nilai, $baris->jumlah)
            : null;
    }

    private static function BuatKunciBatch(DataBarisMutasi $baris): string
    {
        return $baris->idProduk.':'.$baris->idGudang.':'.mb_strtolower(trim((string) $baris->batchMasuk?->nomorBatch));
    }

    private static function BuatKunciSeri(DataBarisMutasi $baris): string
    {
        return $baris->idProduk.':'.trim((string) $baris->nomorSeriMasuk);
    }

    private static function KeSkalaHpp(BigDecimal $hpp): BigDecimal
    {
        return $hpp->toScale(AritmetikaHpp::SKALA_HPP);
    }

    /**
     * @return int<1, max>
     */
    private static function AmbilPercobaan(): int
    {
        $nilai = config('persediaan.PercobaanTransaksi', 3);

        return is_int($nilai) && $nilai > 0 ? $nilai : 3;
    }

    private static function Galat(string $kode, string $pesan, ?string $kunciBaris = null, string $bidang = 'Baris'): PelanggaranAturanBisnis
    {
        return new PelanggaranAturanBisnis($kode, $pesan, $bidang, detail: $kunciBaris === null ? [] : ['KunciBaris' => $kunciBaris]);
    }
}
