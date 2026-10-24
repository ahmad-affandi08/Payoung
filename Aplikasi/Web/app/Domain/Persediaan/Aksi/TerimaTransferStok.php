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
use App\Domain\Katalog\Data\DataInfoProdukStok;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Kueri\InfoProdukStok;
use App\Domain\Organisasi\Kueri\InfoGudang;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataBatchMasuk;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Data\DataTerimaTransfer;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Persediaan\Enum\StatusTransferStok;
use App\Domain\Persediaan\Layanan\Hpp\AritmetikaHpp;
use App\Domain\Persediaan\Layanan\PemeriksaLokasiDokumen;
use App\Domain\Persediaan\Layanan\PencatatJurnalPersediaan;
use App\Domain\Persediaan\Layanan\PengunciSaldoStok;
use App\Domain\Persediaan\Layanan\PenyusunJurnalPersediaan;
use App\Domain\Persediaan\Model\TransferStok;
use App\Domain\Persediaan\Model\TransferStokDetail;
use App\Domain\Persediaan\Tugas\BeritahuTransferStokDiterimaTugas;
use App\Domain\Tenant\Kueri\PengaturanPersediaanTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Menerima transfer stok, boleh sebagian dan boleh dibagi per barang (F-05b, J-05.3, D-105). Per baris penerima
 * memilih berapa yang:
 * - diterima: `TransferKeluar` dari lokasi dalam perjalanan sebesar nilai kirim proporsional (sisa terakhir = seluruh
 *   sisa nilai), lalu `TransferMasuk` ke lokasi tujuan sebesar nilai yang benar-benar keluar (HPP kirim). Jurnal Dr
 *   Persediaan tujuan / Cr Persediaan Dalam Perjalanan (kunci `Terima{n}`).
 * - rusak: diterima fisik tetapi tidak layak jual; mutasi `Susut` dari lokasi dalam perjalanan, jurnal J-05.4 Dr Susut
 *   & Barang Rusak / Cr Persediaan Dalam Perjalanan (kunci `Rusak{n}`). Stok tujuan tidak bertambah.
 * - ditolak: dikembalikan ke lokasi asal; `TransferKeluar` dari lokasi dalam perjalanan lalu `TransferMasuk` ke lokasi
 *   asal pada nilai yang sama (kunci jurnal `Tolak{n}`).
 * Nilai dibagi berurutan (diterima, rusak, ditolak) dari sisa nilai baris; bagian yang menghabiskan sisa jumlah
 * mengambil seluruh sisa nilai, jadi nilai dalam perjalanan selalu habis tepat nol. Semua sisa selesai = status
 * Diterima; selain itu DiterimaSebagian (sisa ditutup lewat `TutupTransferStok`). Total ketiganya melebihi sisa =
 * `JumlahMelebihiSisa`.
 */
final class TerimaTransferStok
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

    /**
     * @param  list<DataTerimaTransfer>  $terima
     */
    public function Jalankan(TransferStok $transfer, array $terima, CarbonImmutable $tanggal, int $idPengguna): TransferStok
    {
        return DB::transaction(fn (): TransferStok => $this->Terima($transfer->Id, $terima, $tanggal, $idPengguna), max(1, (int) config('persediaan.PercobaanTransaksi', 3)));
    }

    /**
     * @param  list<DataTerimaTransfer>  $terima
     */
    private function Terima(int $idTransfer, array $terima, CarbonImmutable $tanggal, int $idPengguna): TransferStok
    {
        $this->pengaturan->AmbilDenganKunciBaca();
        $transfer = TransferStok::query()->whereKey($idTransfer)->lockForUpdate()->firstOrFail();

        if (! $transfer->Status->CekDalamPerjalanan()) {
            throw new PelanggaranAturanBisnis('StatusTidakSesuai', "Transfer berstatus {$transfer->Status->AmbilLabel()} tidak bisa diterima.");
        }

        $tujuan = $this->pemeriksaLokasi->AmbilLokasi($transfer->IdGudangTujuan, 'UuidGudangTujuan');
        $this->pemeriksaLokasi->PastikanBukanMasaDepan($tanggal, $tujuan->idOutlet);

        if ($tanggal->format('Y-m-d') < $transfer->Tanggal->format('Y-m-d')) {
            throw new PelanggaranAturanBisnis('TanggalSebelumKirim', 'Tanggal terima tidak boleh sebelum tanggal kirim '.$transfer->Tanggal->format('d/m/Y').'.', 'Tanggal');
        }

        $idTransit = (int) $transfer->IdGudangTransit;
        $detail = $transfer->Detail()->orderBy('Urutan')->get()->keyBy('Urutan');
        $produk = $this->infoProduk->AmbilBanyak(array_values(array_unique($detail->pluck('IdProduk')->all())), true);
        $rencana = $this->PeriksaJumlah($terima, $detail->all(), $produk);

        if ($rencana === []) {
            throw new PelanggaranAturanBisnis('BarisKosong', 'Isi jumlah diterima, rusak, atau ditolak minimal satu barang.', 'Baris');
        }

        $adaTolak = array_filter($rencana, fn (array $r): bool => ! AritmetikaHpp::CekNol($r['Tolak'])) !== [];
        // Lokasi asal hanya diperiksa bila ada barang yang dikembalikan; lokasi yang sudah dinonaktifkan menolak stok masuk.
        if ($adaTolak) {
            $this->pemeriksaLokasi->AmbilLokasi($transfer->IdGudangAsal, 'UuidGudangAsal');
        }

        $nilai = [];
        $pasangan = [];

        foreach ($rencana as $urutan => $r) {
            /** @var TransferStokDetail $d */
            $d = $detail[$urutan];
            $nilai[$urutan] = $this->AlokasikanNilai($d, $r);
            $pasangan[] = [$d->IdProduk, $idTransit];

            if (! AritmetikaHpp::CekNol($r['Terima'])) {
                $pasangan[] = [$d->IdProduk, $tujuan->id];
            }

            if (! AritmetikaHpp::CekNol($r['Tolak'])) {
                $pasangan[] = [$d->IdProduk, $transfer->IdGudangAsal];
            }
        }

        $this->pengunciSaldo->Kunci($pasangan);
        $n = $transfer->JumlahPenerimaan + 1;

        $barisKeluar = [];

        foreach ($rencana as $urutan => $r) {
            /** @var TransferStokDetail $d */
            $d = $detail[$urutan];

            foreach ([['Terima', "T{$n}/{$d->Id}", JenisMutasi::TransferKeluar], ['Tolak', "RK{$n}/{$d->Id}", JenisMutasi::TransferKeluar], ['Rusak', "X{$n}/{$d->Id}", JenisMutasi::Susut]] as [$jenis, $kunci, $mutasi]) {
                if (AritmetikaHpp::CekNol($r[$jenis])) {
                    continue;
                }

                $barisKeluar[] = new DataBarisMutasi(
                    kunciBaris: $kunci,
                    idProduk: $d->IdProduk,
                    idGudang: $idTransit,
                    jenisMutasi: $mutasi,
                    jumlah: $r[$jenis]->Negasi(),
                    modeNilai: ModeNilaiMutasi::Ditentukan,
                    nilai: $nilai[$urutan][$jenis],
                    idReferensiDetail: $d->Id,
                    idBatchStok: $d->IdBatchStokTransit,
                    idNomorSeri: $d->IdNomorSeri,
                );
            }
        }

        $keluar = $this->catatMutasi->Jalankan($this->Dokumen($transfer, $tanggal, $idPengguna, $barisKeluar));

        $barisMasuk = [];

        foreach ($rencana as $urutan => $r) {
            /** @var TransferStokDetail $d */
            $d = $detail[$urutan];
            $pelacakan = $produk[$d->IdProduk]->pelacakan;

            foreach ([['Terima', "T{$n}/{$d->Id}", "M{$n}/{$d->Id}", $tujuan->id], ['Tolak', "RK{$n}/{$d->Id}", "RM{$n}/{$d->Id}", $transfer->IdGudangAsal]] as [$jenis, $kunciKeluar, $kunciMasuk, $idGudang]) {
                if (AritmetikaHpp::CekNol($r[$jenis])) {
                    continue;
                }

                $hasil = $keluar->baris[$kunciKeluar];
                $barisMasuk[] = new DataBarisMutasi(
                    kunciBaris: $kunciMasuk,
                    idProduk: $d->IdProduk,
                    idGudang: $idGudang,
                    jenisMutasi: JenisMutasi::TransferMasuk,
                    jumlah: $r[$jenis],
                    modeNilai: ModeNilaiMutasi::Ditentukan,
                    nilai: AritmetikaHpp::AmbilMutlak($hasil->totalHpp),
                    hppSatuan: $hasil->hppSatuan,
                    idReferensiDetail: $d->Id,
                    batchMasuk: $pelacakan === PelacakanProduk::Batch ? new DataBatchMasuk((string) $d->NomorBatch, $d->TanggalKedaluwarsa === null ? null : CarbonImmutable::parse($d->TanggalKedaluwarsa->format('Y-m-d'))) : null,
                    nomorSeriMasuk: $pelacakan === PelacakanProduk::Seri ? $d->NomorSeri : null,
                );
            }
        }

        $masuk = $barisMasuk === [] ? null : $this->catatMutasi->Jalankan($this->Dokumen($transfer, $tanggal, $idPengguna, $barisMasuk));

        $totalDiterima = Uang::Dari($transfer->TotalNilaiDiterima);
        $totalSusut = Uang::Dari($transfer->TotalNilaiSusut);
        $semuaSelesai = true;

        foreach ($detail as $urutan => $d) {
            if (isset($rencana[$urutan])) {
                $r = $rencana[$urutan];
                $nilaiTerima = AritmetikaHpp::CekNol($r['Terima']) ? Uang::Nol() : AritmetikaHpp::AmbilMutlak($keluar->baris["T{$n}/{$d->Id}"]->totalHpp);
                $nilaiRusak = AritmetikaHpp::CekNol($r['Rusak']) ? Uang::Nol() : AritmetikaHpp::AmbilMutlak($keluar->baris["X{$n}/{$d->Id}"]->totalHpp);
                $nilaiTolak = AritmetikaHpp::CekNol($r['Tolak']) ? Uang::Nol() : AritmetikaHpp::AmbilMutlak($keluar->baris["RK{$n}/{$d->Id}"]->totalHpp);

                $d->JumlahDiterima = Kuantitas::Dari($d->JumlahDiterima)->Tambah($r['Terima'])->KeString();
                $d->NilaiDiterima = Uang::Dari($d->NilaiDiterima)->Tambah($nilaiTerima)->KeString();
                $d->JumlahSusut = Kuantitas::Dari($d->JumlahSusut)->Tambah($r['Rusak'])->KeString();
                $d->NilaiSusut = Uang::Dari($d->NilaiSusut)->Tambah($nilaiRusak)->KeString();
                $d->JumlahDitolak = Kuantitas::Dari($d->JumlahDitolak)->Tambah($r['Tolak'])->KeString();
                $d->NilaiDitolak = Uang::Dari($d->NilaiDitolak)->Tambah($nilaiTolak)->KeString();
                $d->save();
                $totalDiterima = $totalDiterima->Tambah($nilaiTerima);
                $totalSusut = $totalSusut->Tambah($nilaiRusak);
            }

            $semuaSelesai = $semuaSelesai && AritmetikaHpp::CekNol($d->AmbilSisa());
        }

        $gudang = $this->infoGudang->AmbilBanyak([$idTransit, $tujuan->id, $transfer->IdGudangAsal]);
        $nomorJurnal = [];
        $barisTerima = array_values(array_filter($barisKeluar, fn (DataBarisMutasi $b): bool => str_starts_with($b->kunciBaris, 'T')));
        $barisTolak = array_values(array_filter($barisKeluar, fn (DataBarisMutasi $b): bool => str_starts_with($b->kunciBaris, 'RK')));
        $barisRusak = array_values(array_filter($barisKeluar, fn (DataBarisMutasi $b): bool => str_starts_with($b->kunciBaris, 'X')));
        $hasilKeluar = fn (array $baris): array => array_map(fn (DataBarisMutasi $b) => $keluar->baris[$b->kunciBaris], $baris);
        $hasilMasuk = fn (string $awalan): array => $masuk === null ? [] : array_values(array_filter($masuk->baris, fn ($h, string $kunci): bool => str_starts_with($kunci, $awalan), ARRAY_FILTER_USE_BOTH));

        if ($barisTerima !== []) {
            $nomorJurnal[] = $this->pencatatJurnal->Posting(
                JenisSumberJurnal::TransferStok,
                $transfer->Id,
                $transfer->Uuid,
                (string) $transfer->Nomor,
                $tanggal,
                "Transfer {$transfer->Nomor} diterima di {$tujuan->nama} (penerimaan {$n})",
                $this->penyusunJurnal->Susun(array_values(array_map(fn ($h): array => [$h, PeranAkun::SelisihHpp], [...$hasilKeluar($barisTerima), ...$hasilMasuk('M')])), $produk, $gudang, $tujuan->idOutlet),
                $idPengguna,
                'Terima'.$n,
            )?->nomor;
        }

        if ($barisRusak !== []) {
            $nomorJurnal[] = $this->pencatatJurnal->Posting(
                JenisSumberJurnal::TransferStok,
                $transfer->Id,
                $transfer->Uuid,
                (string) $transfer->Nomor,
                $tanggal,
                "Barang rusak saat transfer {$transfer->Nomor} diterima di {$tujuan->nama} (penerimaan {$n})",
                $this->penyusunJurnal->Susun(array_values(array_map(fn ($h): array => [$h, PeranAkun::SusutPersediaan], $hasilKeluar($barisRusak))), $produk, $gudang, $transfer->IdOutletAsal),
                $idPengguna,
                'Rusak'.$n,
            )?->nomor;
        }

        if ($barisTolak !== []) {
            $nomorJurnal[] = $this->pencatatJurnal->Posting(
                JenisSumberJurnal::TransferStok,
                $transfer->Id,
                $transfer->Uuid,
                (string) $transfer->Nomor,
                $tanggal,
                "Barang transfer {$transfer->Nomor} ditolak di {$tujuan->nama} dan dikembalikan ke ".($gudang[$transfer->IdGudangAsal]->nama ?? 'lokasi asal')." (penerimaan {$n})",
                $this->penyusunJurnal->Susun(array_values(array_map(fn ($h): array => [$h, PeranAkun::SelisihHpp], [...$hasilKeluar($barisTolak), ...$hasilMasuk('RM')])), $produk, $gudang, $transfer->IdOutletAsal),
                $idPengguna,
                'Tolak'.$n,
            )?->nomor;
        }

        $statusAwal = $transfer->Status;
        $statusBaru = $semuaSelesai ? StatusTransferStok::Diterima : StatusTransferStok::DiterimaSebagian;

        if ($statusBaru !== $statusAwal) {
            $transfer->UbahStatus($statusBaru);
        }

        $transfer->fill([
            'JumlahPenerimaan' => $n,
            'TotalNilaiDiterima' => $totalDiterima->KeString(),
            'TotalNilaiSusut' => $totalSusut->KeString(),
            'DiterimaPada' => now(),
            'DiubahOleh' => $idPengguna,
        ]);

        if ($semuaSelesai) {
            $transfer->DitutupOleh = $idPengguna;
            $transfer->DitutupPada = now();
        }

        $transfer->save();

        if ($statusBaru !== $statusAwal) {
            $this->riwayat->Catat(TransferStok::JENIS_DOKUMEN, $transfer->Id, $statusAwal->value, $statusBaru->value, $idPengguna);
        }

        $this->audit->Catat('transfer-stok.terima', $transfer, ['Status' => $statusAwal->value], [
            'Status' => $statusBaru->value,
            'Penerimaan' => $n,
            'Tanggal' => $tanggal->format('Y-m-d'),
            'Baris' => array_map(fn (int $u): array => [
                'Urutan' => $u,
                'Jumlah' => $rencana[$u]['Terima']->KeString(),
                'Rusak' => $rencana[$u]['Rusak']->KeString(),
                'Ditolak' => $rencana[$u]['Tolak']->KeString(),
            ], array_keys($rencana)),
            'NomorJurnal' => array_values(array_filter($nomorJurnal)),
        ], idPengguna: $idPengguna);

        // Kabar non-kritis (aturan #10): antrean, setelah commit (D-187).
        BeritahuTransferStokDiterimaTugas::dispatch($transfer->IdTenant, $transfer->Id, $n, $idPengguna)->afterCommit();

        return $transfer;
    }

    /**
     * Membagi sisa nilai baris ke bagian diterima, rusak, lalu ditolak (urutan tetap). Bagian yang menghabiskan sisa
     * jumlah mengambil seluruh sisa nilai; bagian lain proporsional HPP kirim, dibatasi sisa nilai.
     *
     * @param  array{Terima: Kuantitas, Rusak: Kuantitas, Tolak: Kuantitas}  $jumlah
     * @return array{Terima: Uang, Rusak: Uang, Tolak: Uang}
     */
    private function AlokasikanNilai(TransferStokDetail $d, array $jumlah): array
    {
        $sisaJumlah = $d->AmbilSisa();
        $sisaNilai = $d->AmbilSisaNilai();
        $hppKirim = AritmetikaHpp::Hpp(Uang::Dari($d->NilaiKirim), Kuantitas::Dari($d->JumlahDikirim));
        $hasil = ['Terima' => Uang::Nol(), 'Rusak' => Uang::Nol(), 'Tolak' => Uang::Nol()];

        foreach (array_keys($hasil) as $jenis) {
            $q = $jumlah[$jenis];

            if (AritmetikaHpp::CekNol($q)) {
                continue;
            }

            $nilai = $q->SamaDengan($sisaJumlah)
                ? $sisaNilai
                : AritmetikaHpp::AmbilMinimum(AritmetikaHpp::Nilai($q, $hppKirim), $sisaNilai);
            $hasil[$jenis] = $nilai;
            $sisaJumlah = $sisaJumlah->Kurangi($q);
            $sisaNilai = AritmetikaHpp::AmbilMaksimum($sisaNilai->Kurangi($nilai), Uang::Nol());
        }

        return $hasil;
    }

    /**
     * @param  list<DataTerimaTransfer>  $terima
     * @param  array<int, TransferStokDetail>  $detail  kunci = Urutan
     * @param  array<int, DataInfoProdukStok>  $produk
     * @return array<int, array{Terima: Kuantitas, Rusak: Kuantitas, Tolak: Kuantitas}> Urutan → pembagian jumlah (total > 0), urut Urutan
     */
    private function PeriksaJumlah(array $terima, array $detail, array $produk): array
    {
        $hasil = [];
        $label = ['Terima' => ['Jumlah', 'diterima'], 'Rusak' => ['Rusak', 'rusak'], 'Tolak' => ['Ditolak', 'ditolak']];

        foreach ($terima as $t) {
            $bagian = ['Terima' => $t->jumlah, 'Rusak' => $t->AmbilRusak(), 'Tolak' => $t->AmbilDitolak()];
            $total = Kuantitas::Nol();

            foreach ($bagian as $q) {
                $total = $total->Tambah($q);
            }

            if ($total->KeDesimal()->isZero()) {
                continue;
            }

            $d = $detail[$t->urutan] ?? null;

            if ($d === null || isset($hasil[$t->urutan])) {
                throw new PelanggaranAturanBisnis('BarisTidakValid', "Baris {$t->urutan} tidak ada di transfer ini atau disebut dua kali.", 'Baris');
            }

            $info = $produk[$d->IdProduk] ?? null;

            foreach ($bagian as $jenis => $jumlah) {
                $q = $jumlah->KeDesimal();

                if ($q->isNegative() || $q->strippedOfTrailingZeros()->getScale() > 4 || ($info !== null && ! $info->bolehDesimal && ! $q->getFractionalPart()->isZero())) {
                    throw new PelanggaranAturanBisnis('JumlahTidakValid', "Baris {$t->urutan}: jumlah {$label[$jenis][1]} {$d->NamaProduk} tidak valid.", "Baris.{$t->urutan}.{$label[$jenis][0]}");
                }
            }

            if ($total->Bandingkan($d->AmbilSisa()) > 0) {
                throw new PelanggaranAturanBisnis(
                    'JumlahMelebihiSisa',
                    "Baris {$t->urutan}: {$d->NamaProduk} (diterima + rusak + ditolak) melebihi sisa dalam perjalanan (".str_replace('.', ',', (string) $d->AmbilSisa()->KeDesimal()->strippedOfTrailingZeros()).').',
                    "Baris.{$t->urutan}.Jumlah",
                );
            }

            $hasil[$t->urutan] = $bagian;
        }

        ksort($hasil);

        return $hasil;
    }

    /**
     * @param  list<DataBarisMutasi>  $baris
     */
    private function Dokumen(TransferStok $transfer, CarbonImmutable $tanggal, int $idPengguna, array $baris): DataDokumenMutasi
    {
        return new DataDokumenMutasi(JenisReferensiMutasi::TransferStok, $transfer->Id, $transfer->Uuid, $transfer->Nomor, $tanggal, $idPengguna, null, array_values($baris));
    }
}
