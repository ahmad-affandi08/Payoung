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
use App\Domain\Penjualan\Enum\StatusPesananGrosir;
use App\Domain\Penjualan\Layanan\PenyusunJurnalGrosir;
use App\Domain\Penjualan\Model\PesananGrosir;
use App\Domain\Penjualan\Model\PesananGrosirDetail;
use App\Domain\Penjualan\Model\ReturGrosir;
use App\Domain\Penjualan\Model\SuratJalan;
use App\Domain\Penjualan\Model\SuratJalanDetail;
use App\Domain\Persediaan\Aksi\CatatMutasiStok;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Persediaan\Kueri\InfoBatchStok;
use App\Domain\Persediaan\Kueri\InfoNomorSeri;
use App\Domain\Persediaan\Kueri\MutasiDokumen;
use App\Domain\Persediaan\Layanan\Hpp\AritmetikaHpp;
use App\Domain\Persediaan\Layanan\PemeriksaLokasiDokumen;
use App\Domain\Persediaan\Layanan\PengunciSaldoStok;
use App\Domain\Persediaan\Layanan\PetaAkunPersediaan;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Membatalkan surat jalan grosir (F-12, §9.7, J-12.3). Dokumen tidak diedit dan tidak dihapus (CLAUDE.md #8):
 * barangnya dimasukkan kembali lewat mutasi pembalik (`B/{KunciBaris}`, `idMutasiAsal`, nilai = nilai asal) dan seluruh
 * baris J-12.1 dibalik sisinya, sehingga HPP, pendapatan, dan PPN keluaran yang tadi diakui ikut hilang.
 * `JumlahTerkirim` baris SO dikurangi dan status SO kembali ke SebagianDikirim atau Dikonfirmasi.
 *
 * Ditolak bila surat jalan **sudah difakturkan** (`SudahDifakturkan`): fakturnya yang harus dibatalkan lebih dulu,
 * karena PPN yang sudah dilaporkan di Faktur Pajak tidak boleh hilang hanya karena dokumen gudang dibatalkan —
 * koreksinya lewat faktur pengganti/pembatalan sesuai ketentuan faktur pajak.
 *
 * Tanggal pembalik = tanggal surat jalan aslinya, supaya periode pengakuan yang dibatalkan adalah periode yang sama;
 * bila periodenya sudah ditutup, `PostingJurnal` yang menolaknya lewat `KunciPeriode`.
 */
final class BatalkanSuratJalan
{
    public const PANJANG_ALASAN_MINIMAL = 5;

    public function __construct(
        private readonly PemeriksaLokasiDokumen $pemeriksaLokasi,
        private readonly MutasiDokumen $mutasiDokumen,
        private readonly InfoBatchStok $infoBatch,
        private readonly InfoNomorSeri $infoSeri,
        private readonly PengunciSaldoStok $pengunciSaldo,
        private readonly InfoProdukStok $infoProduk,
        private readonly PetaAkunPersediaan $petaAkunPersediaan,
        private readonly CatatMutasiStok $catatMutasi,
        private readonly PenyusunJurnalGrosir $penyusunJurnal,
        private readonly PostingJurnal $postingJurnal,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @throws PelanggaranAturanBisnis AlasanBatalWajib, SuratJalanTidakDitemukan, SudahDifakturkan, SudahAdaRetur
     */
    public function Jalankan(string $uuidSuratJalan, string $alasan, int $idPengguna): SuratJalan
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
            fn (): SuratJalan => $this->Batalkan($uuidSuratJalan, $alasan, $idPengguna),
            max(1, (int) config('persediaan.PercobaanTransaksi', 3)),
        );
    }

    private function Batalkan(string $uuid, string $alasan, int $idPengguna): SuratJalan
    {
        $awal = SuratJalan::query()->where('Uuid', $uuid)->first()
            ?? throw new PelanggaranAturanBisnis('SuratJalanTidakDitemukan', 'Surat jalan tidak ditemukan.');
        // Urutan kunci sama dengan saat pengiriman: pesanan dulu, lalu surat jalan.
        $pesanan = PesananGrosir::query()->whereKey($awal->IdPesananGrosir)->lockForUpdate()->firstOrFail();
        $suratJalan = SuratJalan::query()->whereKey($awal->Id)->lockForUpdate()->firstOrFail();

        if ($suratJalan->Status === StatusDokumenTerposting::Dibatalkan) {
            return $suratJalan;
        }

        if ($suratJalan->IdFakturPenjualan !== null) {
            throw new PelanggaranAturanBisnis(
                'SudahDifakturkan',
                "Surat jalan {$suratJalan->Nomor} sudah masuk faktur penjualan. Batalkan fakturnya dulu.",
            );
        }

        // Barang yang sudah diretur sebagian tidak boleh dimasukkan lagi lewat pembatalan penuh (stok terhitung dua kali).
        if (ReturGrosir::query()->where('IdSuratJalan', $suratJalan->Id)->where('Status', StatusDokumenTerposting::Diposting->value)->exists()) {
            throw new PelanggaranAturanBisnis(
                'SudahAdaRetur',
                "Surat jalan {$suratJalan->Nomor} sudah punya retur. Batalkan returnya dulu supaya stok tidak terhitung dua kali.",
            );
        }

        $tanggal = CarbonImmutable::parse($suratJalan->Tanggal->format('Y-m-d'));
        $asalMutasi = array_values(array_filter(
            $this->mutasiDokumen->AmbilRingkasan(JenisReferensiMutasi::SuratJalan, $suratJalan->Id),
            fn (array $m): bool => str_starts_with($m['KunciBaris'], 'P/'),
        ));
        $this->pemeriksaLokasi->AmbilLokasi($suratJalan->IdGudang, 'IdGudang');
        $pasangan = [];

        foreach ($asalMutasi as $m) {
            $pasangan[$m['IdProduk'].':'.$m['IdGudang']] = [$m['IdProduk'], $m['IdGudang']];
        }

        // Urutan kunci konsisten (urut IdProduk) supaya tidak deadlock dengan dokumen lain (aturan AkuntansiStok).
        $pasangan = array_values($pasangan);
        usort($pasangan, fn (array $a, array $b): int => $a <=> $b);
        $this->pengunciSaldo->Kunci($pasangan);
        // Produk ber-batch kembali ke batch asalnya, produk bernomor seri kembali berstatus tersedia (F-05g/F-05h).
        $batch = $this->infoBatch->AmbilBanyak(array_values(array_filter(array_map(fn (array $m): ?int => $m['IdBatchStok'], $asalMutasi))));
        $nomorSeri = $this->infoSeri->AmbilNomor(array_values(array_filter(array_map(fn (array $m): ?int => $m['IdNomorSeri'], $asalMutasi))));

        $hasilMutasi = $this->catatMutasi->Jalankan(new DataDokumenMutasi(
            jenisReferensi: JenisReferensiMutasi::SuratJalan,
            idReferensi: $suratJalan->Id,
            uuidReferensi: $suratJalan->Uuid,
            nomorReferensi: $suratJalan->Nomor,
            tanggalBisnis: $tanggal,
            idPengguna: $idPengguna,
            idPerangkat: null,
            baris: array_map(fn (array $m): DataBarisMutasi => new DataBarisMutasi(
                kunciBaris: 'B/'.$m['KunciBaris'],
                idProduk: $m['IdProduk'],
                idGudang: $m['IdGudang'],
                jenisMutasi: JenisMutasi::Penjualan,
                jumlah: Kuantitas::Dari($m['Jumlah'])->Negasi(),
                modeNilai: ModeNilaiMutasi::Ditentukan,
                // Nilai mutasi selalu besaran ≥ 0; yang dikembalikan adalah HPP yang benar-benar dibukukan saat
                // barang keluar, supaya nilai persediaan kembali ke posisi sebelum surat jalan ini.
                nilai: AritmetikaHpp::AmbilMutlak(Uang::Dari($m['TotalHpp'])),
                hppSatuan: BigDecimal::of($m['HppSatuan']),
                idReferensiDetail: $m['IdReferensiDetail'],
                batchMasuk: $m['IdBatchStok'] === null ? null : $batch[$m['IdBatchStok']],
                nomorSeriMasuk: $m['IdNomorSeri'] === null ? null : $nomorSeri[$m['IdNomorSeri']],
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
            jenisSumber: JenisSumberJurnal::SuratJalan,
            idSumber: $suratJalan->Id,
            uuidSumber: $suratJalan->Uuid,
            nomorSumber: $suratJalan->Nomor,
            tanggal: $tanggal,
            keterangan: mb_substr("Pembatalan surat jalan {$suratJalan->Nomor}", 0, 255),
            baris: $this->penyusunJurnal->BarisSuratJalan(
                $suratJalan->AmbilTotal(),
                Uang::Dari($suratJalan->Diskon),
                Uang::Dari($suratJalan->DasarPengenaanPajak)->Tambah(Uang::Dari($suratJalan->Diskon)),
                $suratJalan->AmbilRincianPajak(),
                $perubahanPersediaan,
                $suratJalan->IdOutlet,
                pembalik: true,
            ),
            idPengguna: $idPengguna,
            kunciSumber: 'Pembatalan',
            idJurnalDibalik: $suratJalan->IdJurnal,
        ));

        $this->KembalikanPesanan($pesanan, $suratJalan, $idPengguna, $alasan);

        $suratJalan->UbahStatus(StatusDokumenTerposting::Dibatalkan);
        $suratJalan->fill([
            'IdJurnalPembatalan' => $jurnal->idJurnal,
            'AlasanBatal' => $alasan,
            'DibatalkanOleh' => $idPengguna,
            'DibatalkanPada' => CarbonImmutable::now(),
            'DiubahOleh' => $idPengguna,
        ])->save();

        $this->riwayat->Catat(
            SuratJalan::JENIS_DOKUMEN,
            $suratJalan->Id,
            StatusDokumenTerposting::Diposting->value,
            StatusDokumenTerposting::Dibatalkan->value,
            $idPengguna,
            $alasan,
        );
        $this->audit->Catat('grosir.surat-jalan-batalkan', $suratJalan, nilaiLama: ['Status' => StatusDokumenTerposting::Diposting->value], nilaiBaru: [
            'Status' => StatusDokumenTerposting::Dibatalkan->value,
            'Nomor' => $suratJalan->Nomor,
            'Alasan' => $alasan,
            'NomorJurnalPembatalan' => $jurnal->nomor,
            'StatusPesanan' => $pesanan->Status->value,
        ], idPengguna: $idPengguna);

        return $suratJalan;
    }

    /**
     * `JumlahTerkirim` dikurangi, lalu status SO mundur: Dikonfirmasi bila tidak ada lagi yang terkirim,
     * SebagianDikirim bila masih ada sisa kiriman lain.
     */
    private function KembalikanPesanan(PesananGrosir $pesanan, SuratJalan $suratJalan, int $idPengguna, string $alasan): void
    {
        $detailPesanan = PesananGrosirDetail::query()
            ->where('IdPesananGrosir', $pesanan->Id)
            ->orderBy('Urutan')
            ->lockForUpdate()
            ->get()
            ->keyBy('Id');

        foreach (SuratJalanDetail::query()->where('IdSuratJalan', $suratJalan->Id)->get() as $baris) {
            $detail = $detailPesanan[$baris->IdPesananGrosirDetail] ?? null;

            if ($detail === null) {
                continue;
            }

            $sisa = $detail->AmbilJumlahTerkirim()->Kurangi($baris->AmbilJumlah());
            $detail->JumlahTerkirim = ($sisa->BernilaiNegatif() ? Kuantitas::Nol() : $sisa)->KeString();
            $detail->save();
        }

        $adaTerkirim = false;

        foreach ($detailPesanan as $detail) {
            if ($detail->AmbilJumlahTerkirim()->Bandingkan(Kuantitas::Nol()) > 0) {
                $adaTerkirim = true;

                break;
            }
        }

        $tujuan = $adaTerkirim ? StatusPesananGrosir::SebagianDikirim : StatusPesananGrosir::Dikonfirmasi;

        if ($pesanan->Status === $tujuan) {
            return;
        }

        $asal = $pesanan->Status->value;
        $pesanan->UbahStatus($tujuan);
        $pesanan->fill(['SelesaiPada' => null, 'DiubahOleh' => $idPengguna])->save();
        $this->riwayat->Catat(PesananGrosir::JENIS_DOKUMEN, $pesanan->Id, $asal, $tujuan->value, $idPengguna, $alasan);
    }
}
