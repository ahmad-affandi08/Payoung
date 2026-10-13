<?php

declare(strict_types=1);

namespace App\Domain\Penjualan\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Layanan\PenjagaKunciPeriode;
use App\Domain\Akuntansi\Layanan\PenyediaAkunPeran;
use App\Domain\Bengkel\Layanan\PenagihPerintahKerjaPenjualan;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Dokumen\Layanan\PencatatRiwayatStatus;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Kuantitas;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Sinkron\Enum\StatusItemSinkron;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Karyawan\Data\DataBarisKomisi;
use App\Domain\Karyawan\Layanan\PencatatKomisiPenjualan;
use App\Domain\Kasir\Kueri\InfoShift;
use App\Domain\Katalog\Data\DataKebutuhanStok;
use App\Domain\Katalog\Data\DataProdukPenjualan;
use App\Domain\Katalog\Enum\JenisProduk;
use App\Domain\Katalog\Enum\PelacakanProduk;
use App\Domain\Katalog\Kueri\KomposisiPenjualan;
use App\Domain\Organisasi\Data\DataAnggotaOutlet;
use App\Domain\Organisasi\Data\DataOutletPenjualan;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AnggotaOutlet;
use App\Domain\Organisasi\Kueri\OutletPenjualan;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pajak\Kueri\TarifPajakBerlaku;
use App\Domain\Pelanggan\Kueri\IdentitasPelanggan;
use App\Domain\Pelanggan\Kueri\KreditPelanggan;
use App\Domain\Pelanggan\Layanan\PencatatDepositPenjualan;
use App\Domain\Pelanggan\Layanan\PencatatPiutangPenjualan;
use App\Domain\Pelanggan\Layanan\PencatatPoinPenjualan;
use App\Domain\Pelanggan\Layanan\PencatatSesiPenjualan;
use App\Domain\Pemenuhan\Aksi\KirimKeDapur;
use App\Domain\Pemenuhan\Aksi\SelesaikanReservasiPenjualan;
use App\Domain\Pemenuhan\Data\DataBarisKirimDapur;
use App\Domain\Pemenuhan\Data\DataKirimDapur;
use App\Domain\Pemenuhan\Layanan\PencatatLaundryPenjualan;
use App\Domain\Penjualan\Data\DataBarisPenjualanPos;
use App\Domain\Penjualan\Data\DataHargaManual;
use App\Domain\Penjualan\Data\DataPajakPenjualanPos;
use App\Domain\Penjualan\Data\DataPenjualanPos;
use App\Domain\Penjualan\Data\DataRacikanSiap;
use App\Domain\Penjualan\Enum\JenisMetodePembayaran;
use App\Domain\Penjualan\Enum\StatusPenjualan;
use App\Domain\Penjualan\Kalkulasi\DataBarisKalkulasi;
use App\Domain\Penjualan\Kalkulasi\DataKalkulasi;
use App\Domain\Penjualan\Kalkulasi\DataPajakKalkulasi;
use App\Domain\Penjualan\Kalkulasi\DataPembayaranKalkulasi;
use App\Domain\Penjualan\Kalkulasi\HasilBarisKalkulasi;
use App\Domain\Penjualan\Kalkulasi\HasilKalkulasi;
use App\Domain\Penjualan\Kalkulasi\HasilPajakKalkulasi;
use App\Domain\Penjualan\Kalkulasi\MesinKalkulasi;
use App\Domain\Penjualan\Kalkulasi\MesinPromo;
use App\Domain\Penjualan\Kalkulasi\PromoTerpakai;
use App\Domain\Penjualan\Layanan\PemeriksaDiskonPenjualan;
use App\Domain\Penjualan\Layanan\PemeriksaHargaManual;
use App\Domain\Penjualan\Layanan\PemeriksaPromoPenjualan;
use App\Domain\Penjualan\Layanan\PemeriksaSnapshotPengaturanPenjualan;
use App\Domain\Penjualan\Layanan\PemeriksaTukarBarang;
use App\Domain\Penjualan\Layanan\PenautTagihanQrisPenjualan;
use App\Domain\Penjualan\Layanan\PencatatResepPenjualan;
use App\Domain\Penjualan\Layanan\PenutupPesananPenjualan;
use App\Domain\Penjualan\Layanan\PenutupPesananTerbuka;
use App\Domain\Penjualan\Layanan\PenutupUangMukaPesananOnline;
use App\Domain\Penjualan\Layanan\PenyusunJurnalPenjualan;
use App\Domain\Penjualan\Layanan\PenyusunRacikanPenjualan;
use App\Domain\Penjualan\Model\MetodePembayaran;
use App\Domain\Penjualan\Model\Penjualan;
use App\Domain\Penjualan\Model\PenjualanDetail;
use App\Domain\Penjualan\Model\PenjualanPajak;
use App\Domain\Penjualan\Model\PenjualanPembayaran;
use App\Domain\Penjualan\Peristiwa\PenjualanDiterima;
use App\Domain\Persediaan\Aksi\CatatMutasiStok;
use App\Domain\Persediaan\Data\DataBarisMutasi;
use App\Domain\Persediaan\Data\DataDokumenMutasi;
use App\Domain\Persediaan\Data\HasilBarisMutasi;
use App\Domain\Persediaan\Enum\JenisMutasi;
use App\Domain\Persediaan\Enum\JenisReferensiMutasi;
use App\Domain\Persediaan\Enum\ModeNilaiMutasi;
use App\Domain\Persediaan\Kueri\InfoNomorSeri;
use App\Domain\Persediaan\Layanan\AlokatorBatchFefo;
use App\Domain\Persediaan\Layanan\PelacakNomorSeri;
use App\Domain\Persediaan\Layanan\PemeriksaStokMinus;
use App\Domain\Persediaan\Layanan\PencadangStok;
use App\Domain\Persediaan\Layanan\PetaAkunPersediaan;
use App\Domain\Promo\Layanan\PemakaiVoucher;
use App\Domain\Promo\Layanan\PencatatKlaimPromoPemasok;
use App\Domain\Promo\Layanan\PencatatPemakaianPromo;
use App\Domain\Tenant\Kueri\PengaturanKasirTenant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * F-07b (PRD "Rincian F-07b"): penjualan lunas yang dibuat di perangkat (bisa offline) diterima server lewat sinkron.
 * Satu transaksi DB per item; stok & jurnal di transaksi yang sama (aturan #9–#10).
 *
 * Urutan pemeriksaan: (1) Uuid sama → `Duplikat` bila Nomor & TotalAkhir sama, selain itu `UuidSudahDipakai` (juga
 * saat dua kiriman bersamaan bertabrakan di indeks unik: dibaca ulang lalu dibandingkan); (2) shift milik perangkat ini
 * (`ShiftTidakDitemukan`); (3) kasir anggota tenant (`KasirTidakDitemukan`); (4) nomor BR-07.1
 * `INV/{KodeOutlet}/{YYMMDD}/{KodePerangkat}-{SEQ≥4}`, unik per tenant (`NomorTidakValid`/`NomorSudahDipakai`); (5) produk (`ProdukTidakDikenal`/`ProdukTidakBisaDijual`/`PelacakanBelumDidukung`/
 * `SatuanTidakDikenal`); (6) tarif pajak snapshot = `TarifPajak` terbit yang berlaku (`TarifPajakTidakSah`; tarif yang baru berakhir ≤ 30 hari = diterima + `TarifPajakLama`); (9) metode
 * bayar (`MetodeBayarTidakDikenal`/`MetodeBayarBelumDidukung`/X8 `MetodeBayarBedaKanal`); (8) hitung ulang `MesinKalkulasi` = `Ringkasan`
 * (`HitunganTidakCocok`); (7) BR-07.3 diskon (`PenyetujuTidakBerwenang`); (9) pembayaran (`PembayaranTidakValid`/
 * `PembayaranKurang`).
 *
 * Keadaan yang bisa berubah setelah transaksi offline tidak menolak (§18.3, PRD v1.46 "Tindak lanjut tinjauan"):
 * penjualan diterima dan ditandai `PerluTinjauan` dengan alasan `StokTidakCukup` (mutasi tetap dicatat), F-05g
 * `BatchTidakCukup` (bagian yang tidak tertutup batch tidak mengurangi stok) dan `BatchKedaluwarsa` (batch FEFO sudah lewat tanggalnya),
 * `PilihanTidakDikenal` (bahan pilihan yang dihapus tidak dikurangi), `ProdukDihapus` (produk dihapus, hanya mungkin
 * bila belum pernah dipakai, BR-03.2), `IzinBerubah` (kasir/penyetuju masih anggota tenant tetapi tidak lagi di outlet
 * atau tanpa izin berjualan), `DiskonMelebihiBatas` (BR-07.3 dilanggar menurut batas yang berlaku saat diterima),
 * `PengaturanBerbeda`/`PajakBerbeda` (snapshot pengaturan & pajak berbeda dari pengaturan server),
 * `ShiftSudahDitutup`, `PeriodeTerkunci` (F-15/§18: tanggal bisnis di periode terkunci, jurnal & stok dibukukan
 * di hari pertama periode terbuka berikutnya), dan F-08 `QrisDinamis*` (tagihan QRIS dinamis bermasalah, lihat
 * `PenautTagihanQrisPenjualan`), serta Apotek (§9.5) `ResepTidakLengkap`/`ApotekerTidakBerwenang` (lihat
 * `PencatatResepPenjualan`; resep yang dikirim tetap dicatat di `ResepPenjualan`).
 */
final class TerimaPenjualanPos
{
    /** Tarif pajak yang baru berakhir sampai sekian hari sebelum tanggal bisnis diterima sebagai tinjauan, bukan ditolak. */
    private const TOLERANSI_HARI_TARIF_LAMA = 30;

    private const TOLERANSI_JAM_DETIK = 600;

    /** Panjang kolom `Penjualan.AlasanTinjauan`. */
    private const PANJANG_ALASAN_TINJAUAN = 1000;

    /** F-05g: percobaan membuat ulang rencana FEFO bila batch keburu diambil penjualan lain. */
    private const PERCOBAAN_BATCH = 3;

    /** F-05h: percobaan memilih ulang nomor seri bila keburu diambil penjualan lain. */
    private const PERCOBAAN_SERI = 3;

    private const JENIS_TIDAK_BISA_DIJUAL = [JenisProduk::IndukVarian, JenisProduk::BahanBaku];

    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly InfoShift $infoShift,
        private readonly OutletPenjualan $outletPenjualan,
        private readonly AnggotaOutlet $anggota,
        private readonly TanggalBisnisOutlet $tanggalBisnis,
        private readonly PenjagaKunciPeriode $penjagaPeriode,
        private readonly KomposisiPenjualan $komposisi,
        private readonly TarifPajakBerlaku $tarifBerlaku,
        private readonly PengaturanKasirTenant $pengaturanKasir,
        private readonly PemeriksaDiskonPenjualan $pemeriksaDiskon,
        private readonly PemeriksaHargaManual $pemeriksaHarga,
        private readonly PemeriksaSnapshotPengaturanPenjualan $pemeriksaSnapshot,
        private readonly MesinKalkulasi $mesin,
        private readonly CatatMutasiStok $catatMutasi,
        private readonly PetaAkunPersediaan $petaAkunPersediaan,
        private readonly PenyusunJurnalPenjualan $penyusunJurnal,
        private readonly PostingJurnal $postingJurnal,
        private readonly PencatatRiwayatStatus $riwayat,
        private readonly PencatatAudit $audit,
        private readonly PenutupPesananTerbuka $penutupPesanan,
        private readonly KirimKeDapur $kirimDapur,
        private readonly IdentitasPelanggan $identitasPelanggan,
        private readonly PencatatPoinPenjualan $poin,
        private readonly PemeriksaPromoPenjualan $pemeriksaPromo,
        private readonly PencatatPemakaianPromo $pemakaianPromo,
        private readonly PencatatKlaimPromoPemasok $klaimPemasok,
        private readonly PencadangStok $pencadangStok,
        private readonly PemakaiVoucher $voucher,
        private readonly PencatatPiutangPenjualan $piutang,
        private readonly PencatatKomisiPenjualan $komisi,
        private readonly KreditPelanggan $kredit,
        private readonly PenutupPesananPenjualan $penutupPraPesan,
        private readonly PenutupUangMukaPesananOnline $penutupUangMukaOnline,
        private readonly PemeriksaTukarBarang $pemeriksaTukar,
        private readonly SelesaikanReservasiPenjualan $reservasi,
        private readonly PencatatLaundryPenjualan $laundry,
        private readonly PenagihPerintahKerjaPenjualan $perintahKerja,
        private readonly PenautTagihanQrisPenjualan $penautQris,
        private readonly PencatatDepositPenjualan $deposit,
        private readonly PencatatSesiPenjualan $sesi,
        private readonly PenyediaAkunPeran $penyediaAkun,
        private readonly AlokatorBatchFefo $alokatorBatch,
        private readonly InfoNomorSeri $infoSeri,
        private readonly PelacakNomorSeri $pelacakSeri,
        private readonly PencatatResepPenjualan $resep,
        private readonly PenyusunRacikanPenjualan $racikan,
    ) {}

    public function Jalankan(DataPenjualanPos $data): StatusItemSinkron
    {
        if ($data->dibuatPada->greaterThan(CarbonImmutable::now()->addSeconds(self::TOLERANSI_JAM_DETIK))) {
            throw new PelanggaranAturanBisnis('WaktuTidakValid', 'Waktu penjualan ada di masa depan. Periksa jam perangkat.', 'DibuatPada');
        }

        try {
            return DB::transaction(fn (): StatusItemSinkron => Penjualan::JalankanPenerimaan(fn (): StatusItemSinkron => $this->Proses($data)));
        } catch (QueryException $galat) {
            return $this->SelesaikanBentrokUnik($galat, $data->uuid, $data->nomor, $data->ringkasan->totalAkhir);
        }
    }

    /**
     * Pelanggaran indeks unik (1062) karena dua kiriman bersamaan lolos pemeriksaan idempotensi: baca ulang penjualan
     * ber-Uuid sama; bila Nomor & TotalAkhir sama = `Duplikat` (kiriman ulang yang sah), selain itu `NomorSudahDipakai`
     * (bentrok nomor) atau `UuidSudahDipakai`. Galat database lain diteruskan.
     */
    private function SelesaikanBentrokUnik(QueryException $galat, string $uuid, string $nomor, Uang $totalAkhir): StatusItemSinkron
    {
        if (($galat->errorInfo[1] ?? null) !== 1062) {
            throw $galat;
        }

        $lama = Penjualan::query()->where('Uuid', $uuid)->first();

        if ($lama !== null && $lama->Nomor === $nomor && Uang::Dari($lama->TotalAkhir)->SamaDengan($totalAkhir)) {
            return StatusItemSinkron::Duplikat;
        }

        if ($lama === null && str_contains($galat->getMessage(), 'UniqPenjualanIdTenantNomor')) {
            throw new PelanggaranAturanBisnis('NomorSudahDipakai', "Nomor {$nomor} sudah dipakai penjualan lain.", 'Nomor', 409);
        }

        throw new PelanggaranAturanBisnis('UuidSudahDipakai', 'Kode unik penjualan ini sudah dipakai data lain. Buat ulang transaksi di aplikasi.', 'Uuid');
    }

    private function Proses(DataPenjualanPos $data): StatusItemSinkron
    {
        $idTenant = $this->konteks->Wajib();

        // (1) Idempotensi per Uuid. Sengaja baca biasa, BUKAN `lockForUpdate()`: pada Uuid yang belum ada kunci itu menjadi
        // gap lock di indeks unik, sehingga dua transaksi baru yang bersamaan sama-sama memegangnya lalu saling menunggu
        // saat INSERT (deadlock 1213 di uji beban). Pengaman kiriman ganda tetap indeks unik: dua kiriman yang lolos
        // pemeriksaan ini bertabrakan di INSERT (1062) dan `SelesaikanBentrokUnik` mengubahnya jadi `Duplikat`.
        $lama = Penjualan::query()->where('Uuid', $data->uuid)->first();

        if ($lama !== null) {
            if ($lama->Nomor === $data->nomor && Uang::Dari($lama->TotalAkhir)->SamaDengan($data->ringkasan->totalAkhir)) {
                return StatusItemSinkron::Duplikat;
            }

            throw new PelanggaranAturanBisnis('UuidSudahDipakai', 'Kode unik penjualan ini sudah dipakai data lain. Buat ulang transaksi di aplikasi.', 'Uuid');
        }

        // (2) Shift milik perangkat ini (outbox FIFO mengirim shift lebih dulu).
        $shift = $this->infoShift->CariDiPerangkat($data->uuidShift, $data->idPerangkat);

        if ($shift === null) {
            throw new PelanggaranAturanBisnis('ShiftTidakDitemukan', 'Shift penjualan ini tidak ditemukan di perangkat ini. Kirim data shift lebih dulu.', 'UuidShift');
        }

        if ($data->dibuatPada->lessThan($shift->dibukaPada->subSeconds(self::TOLERANSI_JAM_DETIK))) {
            throw new PelanggaranAturanBisnis('WaktuTidakValid', 'Waktu penjualan lebih awal dari waktu buka shift.', 'DibuatPada');
        }

        $outlet = $this->outletPenjualan->Ambil($shift->idOutlet, $data->idPerangkat);

        if ($outlet === null) {
            throw new PelanggaranAturanBisnis('ShiftTidakDitemukan', 'Outlet shift penjualan ini tidak ditemukan.', 'UuidShift');
        }

        // (3) Kasir: hanya pengguna yang bukan anggota tenant yang ditolak; izin/outlet yang berubah setelah transaksi
        // offline menjadi alasan tinjauan (PRD v1.46).
        $cariKasir = $this->anggota->CariDiTenant($idTenant, $data->uuidPengguna, $outlet->idOutlet);

        if ($cariKasir === null) {
            throw new PelanggaranAturanBisnis('KasirTidakDitemukan', 'Kasir ini bukan anggota usaha ini.', 'UuidPengguna');
        }

        [$kasir, $kasirDiOutlet] = $cariKasir;
        $tinjauan = [];
        $izinBerubah = array_values(array_filter([
            $kasirDiOutlet ? null : "{$kasir->nama} tidak lagi terdaftar di outlet ini",
            $kasir->CekIzin(IzinTenant::PenjualanBuat->value) ? null : "{$kasir->nama} tidak lagi punya izin berjualan",
        ]));

        // (4) Nomor & periode.
        $tanggalBisnis = $this->tanggalBisnis->Hitung($outlet->idOutlet, $data->dibuatPada);
        $this->PeriksaNomor($data, $outlet, $tanggalBisnis);
        // F-15/§18: periode terkunci tidak menolak penjualan offline; jurnal & stok dibukukan di periode terbuka berikutnya.
        $pergeseranPeriode = $this->penjagaPeriode->JelaskanPergeseran($tanggalBisnis);

        if ($pergeseranPeriode !== null) {
            $tinjauan['PeriodeTerkunci'] = $pergeseranPeriode;
        }

        // (5) Produk & satuan, (6) tarif pajak, (9) metode bayar.
        $produk = $this->AmbilProduk($data);
        // Apotek bagian 3: racikan (resep sementara per baris) divalidasi sebelum apa pun disimpan.
        $racikan = $this->racikan->Siapkan($data, $produk);
        $paketSesi = $this->AmbilPaketSesi($data, $produk);
        $tinjauan += $this->PeriksaTarifPajak($data, $outlet, $tanggalBisnis);
        $metode = $this->AmbilMetode($data);

        // (8) Hitung ulang.
        [$hasil, $hasilDasar, $dasarKalkulasi, $promoPerangkat] = $this->HitungUlang($data, $metode);

        // (7) BR-07.3 diskon manual (persen efektif dari hasil mesin, PRD v1.46 (c)).
        $pengaturan = $this->pengaturanKasir->Ambil();
        $pemeriksaanDiskon = $this->pemeriksaDiskon->Periksa(
            $this->KumpulkanDiskon($data, $hasilDasar),
            $kasir,
            $data->uuidPenyetujuDiskon,
            $idTenant,
            $outlet->idOutlet,
            $pengaturan,
        );
        $penyetuju = $pemeriksaanDiskon->penyetuju;

        if (isset($pemeriksaanDiskon->tinjauan['IzinBerubah'])) {
            $izinBerubah[] = substr($pemeriksaanDiskon->tinjauan['IzinBerubah'], strlen('IzinBerubah: '));
        }

        if ($izinBerubah !== []) {
            $tinjauan['IzinBerubah'] = 'IzinBerubah: '.implode('; ', $izinBerubah);
        }

        $tinjauan += array_diff_key($pemeriksaanDiskon->tinjauan, ['IzinBerubah' => true]);

        // Price engine lapis 1: harga baris yang diubah kasir (izin `pos.harga.timpa` atau PIN penyetuju).
        $pemeriksaanHarga = $this->pemeriksaHarga->Periksa(
            $this->KumpulkanHargaManual($data, $produk),
            $kasir,
            $data->uuidPenyetujuHarga,
            $idTenant,
            $outlet->idOutlet,
        );
        $penyetujuHarga = $pemeriksaanHarga->penyetuju;
        $tinjauan += $pemeriksaanHarga->tinjauan;

        // PRD v1.46 (a): snapshot pengaturan & pajak dibandingkan dengan pengaturan server pada tanggal bisnis.
        $tinjauan += $this->pemeriksaSnapshot->Periksa($data, $produk, $outlet, $pengaturan, $tanggalBisnis);

        // (9) Pembayaran.
        [$totalDibayar, $bersih] = $this->PeriksaPembayaran($data, $metode, $hasil);

        // F-07 mode meja: pesanan terbuka yang dibayar (dikunci sebelum penjualan dibuat agar IdPesananTerbuka tersimpan).
        [$pesanan, $tinjauanPesanan] = $this->penutupPesanan->Cari($data->uuidPesananTerbuka, $outlet->idOutlet);
        $tinjauan += $tinjauanPesanan;

        // F-12 bagian 2: pre-order yang diambil (dikunci sebelum penjualan dibuat). Masalah = diterima + tinjauan.
        [$praPesan, $masalahUangMuka] = $this->penutupPraPesan->Cari($data->uuidPesananPenjualan, $outlet->idOutlet);

        // F-17 bagian 2: pesanan toko online berbayar yang ditagihkan lewat penjualan ini (dikunci bersama).
        [$pesananOnline, $masalahOnline] = $this->penutupUangMukaOnline->Cari($data->uuidPesananOnline, $outlet->idOutlet);
        $masalahUangMuka = [...$masalahUangMuka, ...$masalahOnline];

        // F-16a: pelanggan dari POS (Uuid atau alias). Belum dikenal = penjualan tetap diterima tanpa pelanggan.
        $idPelanggan = $data->uuidPelanggan === null ? null : $this->identitasPelanggan->CariId($data->uuidPelanggan);

        if ($data->uuidPelanggan !== null && $idPelanggan === null) {
            $tinjauan['PelangganTidakDikenal'] = 'PelangganTidakDikenal: pelanggan belum diterima server, penjualan disimpan tanpa pelanggan';
        }

        // F-12: penjualan tempo (BR-12.1). Melebihi limit / piutang lewat jatuh tempo butuh penyetuju `penjualan.tempo.setujui`
        // (kasir sendiri ber-izin = tanpa PIN). Data cache perangkat basi = tetap diterima + tinjauan.
        $tempo = $this->AmbilJumlahTempo($data, $metode);
        $penyetujuTempo = null;

        if ($tempo !== null) {
            $masalahTempo = $idPelanggan === null
                ? ['pelanggan belum diterima server']
                : $this->kredit->Periksa($idPelanggan, $tempo, $tanggalBisnis, $pengaturan->batasHariLewatJatuhTempo);

            if ($masalahTempo !== [] && $idPelanggan !== null) {
                $calon = $data->uuidPenyetujuTempo === null ? null : $this->anggota->CariDiTenant($idTenant, $data->uuidPenyetujuTempo, $outlet->idOutlet);
                $penyetujuTempo = match (true) {
                    $calon !== null && $calon[0]->CekIzin(IzinTenant::PenjualanTempoSetujui->value) => $calon[0],
                    $kasir->CekIzin(IzinTenant::PenjualanTempoSetujui->value) => $kasir,
                    default => null,
                };

                if ($penyetujuTempo !== null) {
                    $masalahTempo = [];
                }
            }

            if ($masalahTempo !== []) {
                $tinjauan['TempoBermasalah'] = 'TempoBermasalah: '.implode('; ', $masalahTempo).' (tanpa penyetuju)';
            }
        }

        // F-16c: promo dievaluasi ulang dengan definisi server; beda dengan perangkat = diterima + tinjauan.
        // F-16c bagian 2: promo wajib voucher hanya berlaku dengan voucher yang dikirim perangkat.
        // F-16c bagian 3: metode bayar, ulang tahun, transaksi pertama & batas per pelanggan dinilai dari data server.
        $uuidPromoVoucher = $this->voucher->AmbilUuidPromo($data->kodeVoucher);
        $pemeriksaanPromo = $this->pemeriksaPromo->Periksa($data, $dasarKalkulasi, $produk, $outlet, $idPelanggan, $tanggalBisnis->toDateString(), $promoPerangkat, $uuidPromoVoucher === null ? [] : [$uuidPromoVoucher]);
        $masalahPromo = $pemeriksaanPromo->masalah;

        // K-11: retur tukar barang yang nilainya membayar penjualan ini. Masalah = diterima + tinjauan.
        $bayarTukar = self::JumlahkanMetode($data, $metode, JenisMetodePembayaran::Tukar);
        [$returTukar, $masalahTukar] = $this->pemeriksaTukar->Periksa($data->uuidReturTukar, $bayarTukar, $outlet->idOutlet);

        if ($masalahTukar !== []) {
            $tinjauan['TukarBermasalah'] = 'TukarBermasalah: '.implode('; ', $masalahTukar);
        }

        if (! $bayarTukar->BernilaiNol()) {
            $this->penyediaAkun->Pastikan(PeranAkun::KliringTukarBarang, '2-1800', 'Kliring Tukar Barang');
        }

        // Apotek (§9.5): obat keras/OWA/psikotropika/narkotika butuh apoteker berizin, obat wajib resep butuh resep.
        // Pelanggaran = diterima + tinjauan (bisa offline); resep tetap dicatat bila dikirim.
        [$apoteker, $denganResep, $tinjauanResep] = $this->resep->Periksa($data, $produk, $kasir, $idTenant, $outlet->idOutlet, $data->dibuatPada->setTimezone($outlet->zonaWaktu), array_map(
            fn (DataRacikanSiap $r): array => [$r->golongan, 'Racikan '.$r->nama],
            $racikan,
        ));
        $tinjauan += $tinjauanResep;

        // Simpan dokumen, stok, jurnal.
        $penjualan = $this->SimpanPenjualan($data, $shift->id, $outlet, $kasir, $penyetuju, $tanggalBisnis, $hasil, $totalDibayar, $pesanan?->Id, $idPelanggan, $penyetujuTempo?->id, $praPesan?->Id, $returTukar?->Id, $apoteker?->id, $penyetujuHarga?->id);
        $detail = $this->SimpanDetail($data, $penjualan, $produk, $hasil, $denganResep, $racikan);
        $this->resep->Catat($data, $penjualan->Id, $outlet->idOutlet, $apoteker);
        $this->penutupPesanan->Tutup($pesanan, $penjualan);

        // F-12 bagian 2: DP pre-order dipakai & pesanan ditandai diambil di transaksi yang sama.
        $uangMukaDipakai = self::JumlahkanMetode($data, $metode, JenisMetodePembayaran::UangMuka);

        if ($praPesan !== null) {
            $masalahUangMuka = [...$masalahUangMuka, ...$this->penutupPraPesan->Tandai($praPesan, $penjualan->Id, $uangMukaDipakai, $data->dibuatPada, $kasir->id)];
        }

        if ($pesananOnline !== null) {
            // v3.48: cadangan stok pesanan ditutup; stoknya berkurang lewat mutasi penjualan ini.
            $this->pencadangStok->Pakai(PencadangStok::SUMBER_PESANAN_ONLINE, $pesananOnline->Uuid);
            $masalahUangMuka = [...$masalahUangMuka, ...$this->penutupUangMukaOnline->Tandai($pesananOnline, $penjualan->Id, $uangMukaDipakai, $data->dibuatPada, $kasir->id)];
            // F-17 bagian 3: ongkir pesanan yang tidak ditagih kasir berarti uang yang tidak pernah masuk. Ditandai,
            // tidak ditolak (§18.3): uangnya sudah diterima, dan yang salah harus diperiksa orang.
            $bedaOngkir = $this->penutupUangMukaOnline->PeriksaOngkir($pesananOnline, $hasil->biayaKirim, $hasil->diskonKirim);

            if ($bedaOngkir !== null) {
                $tinjauan['OngkirBerbeda'] = "OngkirBerbeda: {$bedaOngkir}";
            }
        }

        if ($masalahUangMuka !== []) {
            $tinjauan['UangMukaBermasalah'] = 'UangMukaBermasalah: '.implode('; ', $masalahUangMuka);
        }

        // F-12: piutang penjualan tempo di transaksi yang sama.
        if ($tempo !== null) {
            $this->piutang->Catat($idPelanggan, $penjualan->Id, $penjualan->IdOutlet, $penjualan->Nomor, $tanggalBisnis, $tempo);

        }

        // F-16d bagian 1: pembayaran deposit memotong saldo pelanggan di transaksi yang sama (idempoten per penjualan).
        // Saldo kurang (dua perangkat memakai saldo yang sama) = tetap diterima, saldo minus, ditandai tinjauan.
        $bayarDeposit = self::JumlahkanMetode($data, $metode, JenisMetodePembayaran::Deposit);

        if (! $bayarDeposit->BernilaiNol()) {
            $masalahDeposit = $idPelanggan === null
                ? ['pelanggan belum diterima server, saldo deposit tidak dipotong']
                : $this->deposit->CatatPemakaian($idPelanggan, $penjualan->Id, $penjualan->Nomor, $bayarDeposit, $tanggalBisnis, $kasir->id);

            if ($masalahDeposit !== []) {
                $tinjauan['DepositKurang'] = 'DepositKurang: '.implode('; ', $masalahDeposit);
            }
        }

        // F-16d bagian 2: baris paket sesi membuat saldo sesi pelanggan di transaksi yang sama (J-16.2).
        $masalahSesi = [];

        foreach ($paketSesi as $indeks => $paket) {
            $h = $hasil->baris[$indeks];
            $masalahSesi = [...$masalahSesi, ...$this->sesi->CatatPembelian(
                $paket,
                $idPelanggan,
                $outlet->idOutlet,
                $penjualan->Id,
                $detail[$indeks]->Id,
                $penjualan->Nomor,
                $detail[$indeks]->NamaProduk,
                BigDecimal::of($detail[$indeks]->JumlahDasar)->toInt(),
                self::HitungNilaiBersihBaris($h),
                $tanggalBisnis,
                $kasir->id,
            )];
        }

        if ($masalahSesi !== []) {
            $tinjauan['PaketSesi'] = 'PaketSesi: '.implode('; ', $masalahSesi);
        }

        // F-07 mode service bagian 2: reservasi yang dibayar diselesaikan & ditautkan di transaksi yang sama.
        if ($data->uuidReservasi !== null) {
            $masalahReservasi = $this->reservasi->Jalankan($data->uuidReservasi, $penjualan->Id, $outlet->idOutlet, $kasir->id);

            if ($masalahReservasi !== []) {
                $tinjauan['Reservasi'] = 'Reservasi: '.implode('; ', $masalahReservasi);
            }
        }

        // Laundry (§9.9): tiket laundry dibuat bersama penjualannya (offline-first; masalah isian = tinjauan).
        if ($data->laundry !== null) {
            $masalahLaundry = $this->laundry->Buat($data->laundry, $penjualan->Id, $penjualan->Uuid, $penjualan->Nomor, $outlet->idOutlet, $idPelanggan, $data->dibuatPada, $kasir->id);

            if ($masalahLaundry !== []) {
                $tinjauan['Laundry'] = 'Laundry: '.implode('; ', $masalahLaundry);
            }
        }

        // Bengkel (§9.10): perintah kerja yang ditagih ditandai Ditagih & ditautkan di transaksi yang sama; masalah (tidak
        // dikenal, belum disetujui, sudah ditagih) = penjualan tetap diterima + tinjauan.
        $stafPerintahKerja = [];

        if ($data->uuidPerintahKerja !== null) {
            [$masalahPerintahKerja, $stafPerintahKerja] = $this->perintahKerja->Tagih(
                $data->uuidPerintahKerja,
                $penjualan->Id,
                $outlet->idOutlet,
                $kasir->id,
                array_values(array_map(fn ($b): string => $b->uuidProduk, $data->baris)),
            );

            if ($masalahPerintahKerja !== []) {
                $tinjauan['PerintahKerja'] = 'PerintahKerja: '.implode('; ', $masalahPerintahKerja);
            }
        }

        // F-18: komisi staf yang melayani baris (hanya laporan, tanpa jurnal) di transaksi yang sama. Staf yang belum dikenal
        // server tidak mendapat komisi; penjualan tetap diterima + tinjauan. Baris tanpa staf yang berasal dari perintah
        // kerja bengkel mendapat mekanik baris jasanya (urut baris per produk).
        $barisKomisi = [];

        foreach ($data->baris as $indeks => $baris) {
            $staf = $baris->uuidKaryawan;

            if ($staf === [] && ($stafPerintahKerja[$baris->uuidProduk] ?? []) !== []) {
                $staf = array_shift($stafPerintahKerja[$baris->uuidProduk]);
            }

            if ($staf === []) {
                continue;
            }

            $h = $hasil->baris[$indeks];
            $barisKomisi[] = new DataBarisKomisi(
                $detail[$indeks]->Id,
                $baris->uuidProduk,
                $produk[$baris->uuidProduk]->uuidKategori,
                $baris->jumlah,
                $h->bruto->Kurangi($h->diskon)->Kurangi($h->diskonPesanan)->Kurangi($h->pajak->Kurangi($h->pajakEksklusif)),
                $staf,
            );
        }

        $masalahKomisi = $this->komisi->Catat($penjualan->Id, $penjualan->IdOutlet, $tanggalBisnis, $barisKomisi);

        if ($masalahKomisi !== []) {
            $tinjauan['StafTidakDikenal'] = 'StafTidakDikenal: '.implode('; ', $masalahKomisi).', komisinya tidak dicatat';
        }

        // F-16c: pemakaian promo & kuota di transaksi yang sama.
        $diskonPerPromo = array_combine(
            array_map(fn (PromoTerpakai $p): string => $p->uuid, $promoPerangkat),
            array_map(fn (PromoTerpakai $p): Uang => $p->HitungTotal(), $promoPerangkat),
        );
        $masalahPromo = [...$masalahPromo, ...$this->pemakaianPromo->Catat($penjualan->Id, $idPelanggan, $tanggalBisnis, $diskonPerPromo)];

        // F-17 bagian 3: pemakaian promo gratis ongkir (kuota, batas per pelanggan, laporan efektivitas) dicatat hanya bila
        // ongkirnya benar-benar dipotong. Sengaja lewat panggilan terpisah dari `$diskonPerPromo`: klaim ke pemasok di bawah
        // dihitung dari potongan harga, dan ongkir tidak ditanggung pemasok.
        $gratisOngkir = $pemeriksaanPromo->gratisOngkir;

        if ($gratisOngkir !== null && ! $hasil->diskonKirim->BernilaiNol()) {
            $masalahPromo = [...$masalahPromo, ...$this->pemakaianPromo->Catat($penjualan->Id, $idPelanggan, $tanggalBisnis, [$gratisOngkir->uuid => $hasil->diskonKirim])];
        }
        // F-16c bagian 4b/4d: bagian potongan yang ditanggung pemasok menjadi klaim ke pemasok, diakui akrual
        // (Dr Piutang Klaim Promosi Pemasok, Cr HPP) di transaksi yang sama.
        $this->klaimPemasok->Catat($penjualan->Id, $penjualan->Uuid, $penjualan->Nomor, $penjualan->IdOutlet, $tanggalBisnis, $diskonPerPromo, $kasir->id);

        // F-16c bagian 2: voucher yang dipesan online menjadi terpakai di transaksi yang sama.
        if ($data->kodeVoucher !== null) {
            $masalahVoucher = $this->voucher->Pakai($data->kodeVoucher, $data->uuid, $penjualan->Id, $data->dibuatPada, $data->uuidPesananOnline);

            if ($masalahVoucher !== []) {
                $tinjauan['VoucherTidakBerlaku'] = 'VoucherTidakBerlaku: '.implode('; ', $masalahVoucher);
            }
        }

        // v3.46: voucher checkout pesanan online yang tidak ikut ditagih kasir dilepas supaya jatahnya kembali.
        if ($pesananOnline !== null && $pesananOnline->KodeVoucher !== null
            && ($data->kodeVoucher === null || strcasecmp(trim($data->kodeVoucher), $pesananOnline->KodeVoucher) !== 0)) {
            $this->voucher->LepasPesanan($pesananOnline->KodeVoucher, $pesananOnline->Uuid);
        }

        if ($masalahPromo !== []) {
            $tinjauan['PromoBerbeda'] = 'PromoBerbeda: '.implode('; ', $masalahPromo);
        }

        // F-16b: poin ditukar lebih dulu (poin dari penjualan ini tidak ikut ditukar), lalu perolehan; di transaksi yang
        // sama, idempoten per penjualan. Penjualan tetap diterima walau poin bermasalah (sudah terjadi di kasir).
        if ($data->poinDitukar > 0) {
            $masalahPoin = $idPelanggan === null
                ? ['pelanggan belum diterima server, poin tidak dipotong']
                : $this->poin->CatatPenukaran($idPelanggan, $penjualan->Id, $data->poinDitukar, $hasil->diskonPoin);

            if ($masalahPoin !== []) {
                $tinjauan['PenukaranPoin'] = 'PenukaranPoin: '.implode('; ', $masalahPoin);
            }
        }

        if ($idPelanggan !== null) {
            // F-16c bagian 4: promo poin berlipat (ditentukan server) mengalikan perolehan; pemakaiannya dicatat (kuota &
            // batas per pelanggan) hanya bila poin benar-benar diperoleh.
            $poinBerlipat = $pemeriksaanPromo->poinBerlipat;
            $diperoleh = $this->poin->CatatPerolehan($idPelanggan, $penjualan->Id, $hasil->totalAkhir, $tanggalBisnis, $poinBerlipat?->AmbilPengali());

            if ($poinBerlipat !== null && $diperoleh > 0) {
                $masalahPoinBerlipat = $this->pemakaianPromo->Catat($penjualan->Id, $idPelanggan, $tanggalBisnis, [$poinBerlipat->uuid => Uang::Nol()]);

                if ($masalahPoinBerlipat !== []) {
                    $tinjauan['PromoBerbeda'] = ($tinjauan['PromoBerbeda'] ?? 'PromoBerbeda:').' '.implode('; ', $masalahPoinBerlipat);
                }
            }
        }

        // F-10b mode cepat (bayar dulu): tiket dapur dari baris penjualan, di transaksi yang sama.
        if ($data->kirimDapur) {
            $this->kirimDapur->Jalankan(new DataKirimDapur(
                idOutlet: $penjualan->IdOutlet,
                idPesananTerbuka: null,
                idPenjualan: $penjualan->Id,
                nomorDokumen: $penjualan->Nomor,
                namaMeja: null,
                // v3.52: tiket mode cepat berlabel nomor antrian + nama pemesan (dipanggil saat siap), lalu catatan.
                label: self::LabelTiketDapur($penjualan),
                ronde: 1,
                dikirimPada: $data->dibuatPada,
                baris: array_map(fn (PenjualanDetail $d): DataBarisKirimDapur => new DataBarisKirimDapur(
                    $d->Uuid,
                    $d->IdProduk,
                    $d->NamaProduk,
                    (string) $d->Jumlah,
                    array_values(array_map(fn (array $p): string => $p['Nama'], $d->Pilihan ?? [])),
                    $d->Catatan,
                ), $detail),
            ));
        }
        $this->SimpanPajak($data, $penjualan, $hasil);
        // F-08 BR-08.5: pembayaran QRIS dinamis ditautkan ke tagihannya (masalah = diterima + tinjauan).
        [$tinjauanQris, $refEksternal] = $this->penautQris->Tautkan($data, $metode);
        $tinjauan += $tinjauanQris;
        $this->SimpanPembayaran($data, $penjualan, $metode, $refEksternal);

        [$tinjauanStok, $perubahanPersediaan] = $this->CatatStok($data, $penjualan, $outlet, $produk, $detail, $kasir->id, $racikan);
        $tinjauan += $tinjauanStok;
        ksort($tinjauan);
        $tinjauan = array_values($tinjauan);

        // F-11: penjualan yang tiba setelah shift ditutup tetap diterima (outbox FIFO), tetapi kas shift sudah dihitung.
        if (! $shift->aktif) {
            $tinjauan[] = 'ShiftSudahDitutup: penjualan diterima setelah shift ditutup, belum masuk hitungan kas tutup shift';
        }

        $pendapatan = [];

        foreach ($data->baris as $indeks => $baris) {
            $h = $hasil->baris[$indeks];

            // F-16d bagian 2 (J-16.2): paket sesi = Pendapatan Diterima Dimuka sebesar nilai bersih baris; diskon baris itu
            // dikeluarkan dari Diskon Penjualan (kredit pengurang) karena sudah mengurangi kewajiban.
            if (isset($paketSesi[$indeks])) {
                $pendapatan[] = [PeranAkun::PendapatanDiterimaDimuka, self::HitungNilaiBersihBaris($h)];
                $pendapatan[] = [PeranAkun::DiskonPenjualan, $h->diskon->Tambah($h->diskonPesanan)];

                continue;
            }

            $peran = $produk[$baris->uuidProduk]->jenis === JenisProduk::Jasa ? PeranAkun::PendapatanJasa : PeranAkun::Penjualan;
            $pendapatan[] = [$peran, $h->bruto->Kurangi($h->pajak->Kurangi($h->pajakEksklusif))];
        }

        $pajak = [];

        foreach ($hasil->pajak as $kode => $rincian) {
            $pajak[$kode] = $rincian->jumlah;
        }

        // F-17 bagian 3: Pendapatan Pengiriman ditambahkan setelah banyak tenant menerapkan template sektornya, jadi
        // akun & pemetaannya dipastikan ada sebelum dipakai — hanya bila memang ada ongkir, supaya penjualan biasa
        // tidak menyentuh tabel akun sama sekali.
        if (! Uang::Dari($penjualan->BiayaKirim)->BernilaiNol()) {
            $this->penyediaAkun->Pastikan(PeranAkun::PendapatanPengiriman, '4-3100', 'Pendapatan Pengiriman');
        }

        $jurnal = $this->penyusunJurnal->Susun($penjualan, $pendapatan, $pajak, $bersih, $perubahanPersediaan);
        $adaNilai = array_filter($jurnal->baris, fn ($b): bool => ! $b->debit->BernilaiNol() || ! $b->kredit->BernilaiNol()) !== [];

        if ($adaNilai) {
            $penjualan->IdJurnal = $this->postingJurnal->Jalankan($jurnal)->idJurnal;
        }

        $penjualan->TotalHpp = Uang::Nol()->Kurangi(array_reduce($perubahanPersediaan, fn (Uang $t, Uang $u): Uang => $t->Tambah($u), Uang::Nol()))->KeString();

        if ($tinjauan !== []) {
            $penjualan->PerluTinjauan = true;
            $penjualan->AlasanTinjauan = mb_substr(implode('; ', $tinjauan), 0, self::PANJANG_ALASAN_TINJAUAN);
        }

        $penjualan->save();

        $this->riwayat->Catat(Penjualan::JENIS_DOKUMEN, $penjualan->Id, null, StatusPenjualan::Lunas->value, $kasir->id);
        $this->audit->Catat('penjualan.terima', $penjualan, nilaiBaru: [
            'Nomor' => $penjualan->Nomor,
            'TotalAkhir' => $penjualan->TotalAkhir,
            'TotalDiskon' => $penjualan->TotalDiskon,
            'DisetujuiOleh' => $penyetuju?->nama,
            'PerluTinjauan' => $penjualan->PerluTinjauan,
        ], idPengguna: $kasir->id);

        // F-14a: ringkasan laporan harian dihitung ulang di antrean setelah commit (efek non-kritis, aturan #10).
        PenjualanDiterima::dispatch($penjualan->IdTenant, $penjualan->IdOutlet, $penjualan->TanggalBisnis->toDateString(), $penjualan->Id);

        return StatusItemSinkron::Diterima;
    }

    /** BR-07.1: `INV/{KodeOutlet}/{YYMMDD}/{KodePerangkat}-{SEQ≥4}`; YYMMDD = tanggal bisnis atau tanggal lokal outlet. */
    private function PeriksaNomor(DataPenjualanPos $data, DataOutletPenjualan $outlet, CarbonImmutable $tanggalBisnis): void
    {
        $pola = '#^INV/'.preg_quote($outlet->kodeOutlet, '#').'/(\d{6})/'.preg_quote($outlet->kodePerangkat, '#').'-\d{4,}$#';
        $tanggalBoleh = [$tanggalBisnis->format('ymd'), $data->dibuatPada->setTimezone($outlet->zonaWaktu)->format('ymd')];

        if (preg_match($pola, $data->nomor, $cocok) !== 1 || ! in_array($cocok[1], $tanggalBoleh, true)) {
            throw new PelanggaranAturanBisnis(
                'NomorTidakValid',
                "Nomor {$data->nomor} tidak sesuai format INV/{$outlet->kodeOutlet}/{$tanggalBoleh[0]}/{$outlet->kodePerangkat}-0001.",
                'Nomor',
                detail: ['Contoh' => "INV/{$outlet->kodeOutlet}/{$tanggalBoleh[0]}/{$outlet->kodePerangkat}-0001"],
            );
        }

        if (Penjualan::query()->where('Nomor', $data->nomor)->exists()) {
            throw new PelanggaranAturanBisnis('NomorSudahDipakai', "Nomor {$data->nomor} sudah dipakai penjualan lain.", 'Nomor', 409);
        }
    }

    /**
     * @return array<string, DataProdukPenjualan> kunci = Uuid produk
     */
    private function AmbilProduk(DataPenjualanPos $data): array
    {
        $produk = $this->komposisi->AmbilProduk(array_values(array_map(fn (DataBarisPenjualanPos $b): string => $b->uuidProduk, $data->baris)));

        foreach ($data->baris as $indeks => $baris) {
            $p = $produk[$baris->uuidProduk] ?? null;
            $bidang = "Baris.{$indeks}.UuidProduk";

            if ($p === null) {
                throw new PelanggaranAturanBisnis('ProdukTidakDikenal', 'Produk pada baris ke-'.($indeks + 1).' tidak ditemukan.', $bidang);
            }

            if (in_array($p->jenis, self::JENIS_TIDAK_BISA_DIJUAL, true)) {
                throw new PelanggaranAturanBisnis('ProdukTidakBisaDijual', "Produk {$p->nama} berjenis {$p->jenis->AmbilLabel()} belum bisa dijual di POS.", $bidang);
            }

            if ($baris->uuidProdukSatuan !== null && ! isset($p->satuan[$baris->uuidProdukSatuan])) {
                throw new PelanggaranAturanBisnis('SatuanTidakDikenal', "Satuan jual {$p->nama} tidak ditemukan.", "Baris.{$indeks}.UuidProdukSatuan");
            }
        }

        return $produk;
    }

    /**
     * F-16d bagian 2: baris yang produknya paket sesi. Wajib berpelanggan dan jumlahnya bilangan bulat positif (satuan
     * dasar); paket yang sudah tidak aktif tetap diterima (penjualan offline sudah terjadi) dan ditinjau.
     *
     * @param  array<string, DataProdukPenjualan>  $produk
     * @return array<int, array{IdPaketSesi: int, JumlahSesi: int, MasaBerlakuHari: int|null, Aktif: bool}> kunci = indeks baris
     */
    private function AmbilPaketSesi(DataPenjualanPos $data, array $produk): array
    {
        $perProduk = $this->sesi->AmbilPaketPerProduk(array_values(array_map(fn (DataProdukPenjualan $p): int => $p->id, $produk)));
        $hasil = [];

        foreach ($data->baris as $indeks => $baris) {
            $p = $produk[$baris->uuidProduk];
            $paket = $perProduk[$p->id] ?? null;

            if ($paket === null) {
                continue;
            }

            if ($data->uuidPelanggan === null) {
                throw new PelanggaranAturanBisnis('PaketSesiTanpaPelanggan', "Paket {$p->nama} wajib dijual ke pelanggan terdaftar.", 'UuidPelanggan');
            }

            $konversi = $baris->uuidProdukSatuan === null ? '1' : $p->satuan[$baris->uuidProdukSatuan]['KonversiKeDasar'];
            $jumlah = $baris->jumlah->Kali($konversi)->KeDesimal();

            if (! $jumlah->getFractionalPart()->isZero() || ! $jumlah->isPositive()) {
                throw new PelanggaranAturanBisnis('JumlahPaketSesiTidakValid', "Jumlah paket {$p->nama} harus bilangan bulat positif.", "Baris.{$indeks}.Jumlah");
            }

            $hasil[$indeks] = $paket;
        }

        return $hasil;
    }

    /** Pendapatan bersih baris: bruto − diskon baris − diskon pesanan − pajak inklusif (tanpa biaya layanan & pajak). */
    private static function HitungNilaiBersihBaris(HasilBarisKalkulasi $h): Uang
    {
        return $h->bruto->Kurangi($h->diskon)->Kurangi($h->diskonPesanan)->Kurangi($h->pajak->Kurangi($h->pajakEksklusif));
    }

    /**
     * CLAUDE.md #12: setiap pajak snapshot = TarifPajak terbit yang berlaku pada tanggal bisnis di wilayah outlet.
     * Tarif yang pernah terbit dan baru berakhir (perangkat belum memperbarui tarif, misal offline saat tarif berganti)
     * diterima sebagai tinjauan `TarifPajakLama` karena uangnya sudah dipungut; tarif karangan atau sudah lama
     * berakhir tetap ditolak (`TarifPajakTidakSah`).
     *
     * @return array<string, string> tinjauan
     */
    private function PeriksaTarifPajak(DataPenjualanPos $data, DataOutletPenjualan $outlet, CarbonImmutable $tanggalBisnis): array
    {
        $lama = [];

        foreach ($data->pajak as $indeks => $pajak) {
            $tarif = $this->tarifBerlaku->CariDataOutlet($pajak->kode, $outlet->kodeKota, $tanggalBisnis);
            $cocok = $tarif !== null
                && BigDecimal::of($tarif->tarif)->isEqualTo($pajak->tarif)
                && $tarif->pengaliDppPembilang * $pajak->pengaliDppPenyebut === $tarif->pengaliDppPenyebut * $pajak->pengaliDppPembilang;

            if ($cocok) {
                continue;
            }

            if ($this->tarifBerlaku->CekBaruBerakhirDiOutlet(
                $pajak->kode,
                $outlet->kodeKota,
                $tanggalBisnis,
                $pajak->tarif,
                $pajak->pengaliDppPembilang,
                $pajak->pengaliDppPenyebut,
                self::TOLERANSI_HARI_TARIF_LAMA,
            )) {
                $lama[] = "{$pajak->kode} {$pajak->tarif}% (berlaku sekarang: ".($tarif === null ? 'tidak ada' : $tarif->tarif).'%)';

                continue;
            }

            throw new PelanggaranAturanBisnis(
                'TarifPajakTidakSah',
                "Tarif {$pajak->kode} {$pajak->tarif}% tidak sesuai tarif yang berlaku pada {$tanggalBisnis->toDateString()}. Perbarui data aplikasi kasir.",
                "Pajak.{$indeks}",
                detail: ['Kode' => $pajak->kode, 'TarifBerlaku' => $tarif?->tarif],
            );
        }

        return $lama === [] ? [] : ['TarifPajakLama' => 'TarifPajakLama: '.implode('; ', $lama).'; perangkat belum memperbarui tarif, pajak tercatat sesuai struk pelanggan'];
    }

    /**
     * @return array<string, MetodePembayaran> kunci = Uuid metode
     */
    private function AmbilMetode(DataPenjualanPos $data): array
    {
        $uuid = array_values(array_unique(array_map(fn ($b): string => $b->uuidMetodePembayaran, $data->pembayaran)));
        $metode = MetodePembayaran::query()->whereIn('Uuid', $uuid)->get()->keyBy('Uuid')->all();

        foreach ($data->pembayaran as $indeks => $bayar) {
            $m = $metode[$bayar->uuidMetodePembayaran] ?? null;

            if (! $m instanceof MetodePembayaran) {
                throw new PelanggaranAturanBisnis('MetodeBayarTidakDikenal', 'Metode pembayaran tidak ditemukan.', "Pembayaran.{$indeks}.UuidMetodePembayaran");
            }

            // Metode yang dinonaktifkan setelah transaksi offline tetap diterima (tidak memeriksa `Aktif`).
            if (! $m->Jenis->CekDidukungPos()) {
                throw new PelanggaranAturanBisnis('MetodeBayarBelumDidukung', "Pembayaran {$m->Jenis->AmbilLabel()} belum didukung di aplikasi kasir.", "Pembayaran.{$indeks}.UuidMetodePembayaran");
            }

            // X8: metode platform (GoFood, GrabFood, …) hanya untuk penjualan kanal platform yang sama; dana dari
            // platform lain atau penjualan di toko tidak boleh tercatat sebagai pencairan platform ini.
            if ($m->Jenis === JenisMetodePembayaran::Marketplace && $m->Kanal !== $data->kanal) {
                throw new PelanggaranAturanBisnis('MetodeBayarBedaKanal', "{$m->Nama} hanya untuk penjualan kanal {$m->Kanal?->AmbilLabel()}.", "Pembayaran.{$indeks}.UuidMetodePembayaran");
            }
        }

        return $metode;
    }

    /**
     * Hitung ulang dengan mesin. Hasil: [hasil akhir (dengan promo perangkat), hasil tanpa promo (untuk batas diskon
     * manual), masukan tanpa promo, promo perangkat].
     *
     * @param  array<string, MetodePembayaran>  $metode
     * @return array{0: HasilKalkulasi, 1: HasilKalkulasi, 2: DataKalkulasi, 3: list<PromoTerpakai>}
     */
    private function HitungUlang(DataPenjualanPos $data, array $metode): array
    {
        foreach ($data->baris as $indeks => $baris) {
            $totalPilihan = array_reduce($baris->pilihan, fn (Uang $t, array $p): Uang => $t->Tambah(Uang::Dari($p['Harga'])), Uang::Nol());

            if ($baris->pilihan !== [] && ! $totalPilihan->SamaDengan($baris->hargaPilihan)) {
                throw self::GalatHitungan("Baris.{$indeks}.HargaPilihan", $baris->hargaPilihan, $totalPilihan);
            }
        }

        // F-16c: promo perangkat → potongan nominal per indeks baris (Uuid baris wajib ada di dokumen).
        $indeksBaris = array_flip(array_map(fn (DataBarisPenjualanPos $b): string => $b->uuid, $data->baris));
        $promoPerangkat = [];

        foreach ($data->promo as $p) {
            $diskonBaris = [];

            foreach ($p->diskonBaris as $uuidBaris => $jumlah) {
                if (! isset($indeksBaris[$uuidBaris])) {
                    throw new PelanggaranAturanBisnis('DataTidakValid', "Promo {$p->kode} merujuk baris yang tidak ada.", 'Promo');
                }

                $diskonBaris[$indeksBaris[$uuidBaris]] = $jumlah;
            }

            ksort($diskonBaris);
            $promoPerangkat[] = new PromoTerpakai($p->uuidPromo, $p->kode, $diskonBaris, $p->diskonPesanan);
        }

        try {
            $dasar = new DataKalkulasi(
                hargaTermasukPajak: $data->hargaTermasukPajak,
                baris: array_values(array_map(fn (DataBarisPenjualanPos $b): DataBarisKalkulasi => new DataBarisKalkulasi(
                    $b->jumlah,
                    $b->hargaSatuan,
                    $b->hargaPilihan,
                    $b->hargaTermasukPajak,
                    $b->kodePajak,
                    $b->diskonManual === null ? [] : [$b->diskonManual->KePotongan()],
                ), $data->baris)),
                pajak: array_values(array_map(fn (DataPajakPenjualanPos $p): DataPajakKalkulasi => new DataPajakKalkulasi(
                    $p->kode,
                    $p->tarif,
                    $p->dasarPengenaan,
                    $p->pengaliDppPembilang,
                    $p->pengaliDppPenyebut,
                    $p->kenaBiayaKirim,
                ), $data->pajak)),
                persenBiayaLayanan: $data->persenBiayaLayanan,
                pembulatanTunai: $data->pembulatanTunai,
                potonganPesanan: $data->diskonManualPesanan === null ? [] : [$data->diskonManualPesanan->KePotongan()],
                tukarPoin: $data->nilaiTukarPoin,
                pembayaran: array_values(array_map(fn ($b): DataPembayaranKalkulasi => new DataPembayaranKalkulasi(
                    $metode[$b->uuidMetodePembayaran]->Jenis === JenisMetodePembayaran::Tunai,
                    $b->jumlah,
                ), $data->pembayaran)),
                biayaKirim: $data->biayaKirim,
                diskonKirim: $data->diskonKirim,
            );
            $hasilDasar = $this->mesin->Hitung($dasar);
            $hasil = $this->mesin->Hitung(MesinPromo::SusunData($dasar, $promoPerangkat));
        } catch (InvalidArgumentException $galat) {
            throw new PelanggaranAturanBisnis('DataTidakValid', 'Data penjualan tidak bisa dihitung: '.$galat->getMessage(), 'Baris');
        }

        // F-16b: nilai tukar poin tidak boleh terpotong batas sisa subtotal (perangkat wajib membatasi lebih dulu).
        if ($data->nilaiTukarPoin !== null && ! $hasil->diskonPoin->SamaDengan($data->nilaiTukarPoin)) {
            throw self::GalatHitungan('TukarPoin.Nilai', $data->nilaiTukarPoin, $hasil->diskonPoin);
        }

        $r = $data->ringkasan;

        foreach ([
            'Subtotal' => [$r->subtotal, $hasil->subtotal],
            'TotalPajak' => [$r->totalPajak, $hasil->totalPajak],
            'Pembulatan' => [$r->pembulatan, $hasil->pembulatan],
            'TotalAkhir' => [$r->totalAkhir, $hasil->totalAkhir],
        ] as $bidang => [$perangkat, $server]) {
            if (! $perangkat->SamaDengan($server)) {
                throw self::GalatHitungan("Ringkasan.{$bidang}", $perangkat, $server);
            }
        }

        return [$hasil, $hasilDasar, $dasar, $promoPerangkat];
    }

    /**
     * Baris yang membawa `HargaManual` (price engine lapis 1) untuk diperiksa `PemeriksaHargaManual`.
     *
     * @param  array<string, DataProdukPenjualan>  $produk
     * @return list<array{Nama: string, Harga: Uang, Manual: DataHargaManual}>
     */
    private function KumpulkanHargaManual(DataPenjualanPos $data, array $produk): array
    {
        $hasil = [];

        foreach ($data->baris as $baris) {
            if ($baris->hargaManual !== null) {
                $hasil[] = ['Nama' => $produk[$baris->uuidProduk]->nama ?? $baris->uuidProduk, 'Harga' => $baris->hargaSatuan, 'Manual' => $baris->hargaManual];
            }
        }

        return $hasil;
    }

    /**
     * Pasangan [dasar, diskon manual] per baris ber-diskon manual dan untuk diskon pesanan (BR-07.3).
     *
     * @return list<array{0: Uang, 1: Uang}>
     */
    private function KumpulkanDiskon(DataPenjualanPos $data, HasilKalkulasi $hasil): array
    {
        $diskon = [];

        foreach ($data->baris as $indeks => $baris) {
            if ($baris->diskonManual !== null) {
                $diskon[] = [$hasil->baris[$indeks]->bruto, $hasil->baris[$indeks]->diskon];
            }
        }

        if ($data->diskonManualPesanan !== null) {
            // Diskon poin (F-16b) bukan diskon manual: tidak ikut batas diskon kasir.
            $diskon[] = [$hasil->subtotal, $hasil->diskonPesanan->Kurangi($hasil->diskonPoin)];
        }

        return $diskon;
    }

    /**
     * Σ pembayaran berjenis [jenis] (F-12 bagian 2: uang muka pre-order yang dipakai).
     *
     * @param  array<string, MetodePembayaran>  $metode
     */
    private static function JumlahkanMetode(DataPenjualanPos $data, array $metode, JenisMetodePembayaran $jenis): Uang
    {
        $total = Uang::Nol();

        foreach ($data->pembayaran as $bayar) {
            if ($metode[$bayar->uuidMetodePembayaran]->Jenis === $jenis) {
                $total = $total->Tambah($bayar->jumlah);
            }
        }

        return $total;
    }

    /**
     * F-12: jumlah pembayaran tempo (null = bukan penjualan tempo).
     *
     * @param  array<string, MetodePembayaran>  $metode
     */
    private function AmbilJumlahTempo(DataPenjualanPos $data, array $metode): ?Uang
    {
        foreach ($data->pembayaran as $bayar) {
            if ($metode[$bayar->uuidMetodePembayaran]->Jenis === JenisMetodePembayaran::Tempo) {
                return $bayar->jumlah;
            }
        }

        return null;
    }

    /**
     * Pembayaran fase 1: maksimal satu baris tunai, non-tunai tidak melebihi total, Σ pembayaran ≥ TotalAkhir, dan
     * kembalian sama dengan hitungan perangkat.
     *
     * @param  array<string, MetodePembayaran>  $metode
     * @return array{0: Uang, 1: list<array{0: MetodePembayaran, 1: Uang}>} [total dibayar, nilai bersih per pembayaran]
     */
    private function PeriksaPembayaran(DataPenjualanPos $data, array $metode, HasilKalkulasi $hasil): array
    {
        $jumlahTunai = 0;
        $nonTunai = Uang::Nol();
        $total = Uang::Nol();

        foreach ($data->pembayaran as $bayar) {
            $total = $total->Tambah($bayar->jumlah);

            if ($metode[$bayar->uuidMetodePembayaran]->Jenis === JenisMetodePembayaran::Tunai) {
                $jumlahTunai++;
            } else {
                $nonTunai = $nonTunai->Tambah($bayar->jumlah);
            }
        }

        $jumlahTempo = count(array_filter($data->pembayaran, fn ($b): bool => $metode[$b->uuidMetodePembayaran]->Jenis === JenisMetodePembayaran::Tempo));
        $jumlahTukar = count(array_filter($data->pembayaran, fn ($b): bool => $metode[$b->uuidMetodePembayaran]->Jenis === JenisMetodePembayaran::Tukar));

        // K-11: nilai tukar barang dipakai sekali per penjualan dan wajib merujuk retur asalnya.
        if ($jumlahTukar > 1) {
            throw new PelanggaranAturanBisnis('PembayaranTidakValid', 'Satu penjualan hanya boleh memakai nilai tukar barang satu kali.', 'Pembayaran');
        }

        if ($jumlahTukar === 1 && $data->uuidReturTukar === null) {
            throw new PelanggaranAturanBisnis('TukarTanpaRetur', 'Pembayaran tukar barang wajib merujuk retur asalnya.', 'UuidReturTukar');
        }
        $jumlahUangMuka = count(array_filter($data->pembayaran, fn ($b): bool => $metode[$b->uuidMetodePembayaran]->Jenis === JenisMetodePembayaran::UangMuka));

        // F-12 bagian 2: DP pre-order hanya dipakai sekali per penjualan dan wajib merujuk pesanannya.
        if ($jumlahUangMuka > 1) {
            throw new PelanggaranAturanBisnis('PembayaranTidakValid', 'Satu penjualan hanya boleh memakai uang muka satu kali.', 'Pembayaran');
        }

        if ($jumlahUangMuka === 1 && $data->uuidPesananPenjualan === null && $data->uuidPesananOnline === null) {
            throw new PelanggaranAturanBisnis('UangMukaTanpaPesanan', 'Pembayaran uang muka wajib merujuk pre-order atau pesanan online yang ditagihkan.', 'UuidPesananPenjualan');
        }

        // Dua sumber uang muka dalam satu penjualan tidak bisa dibagi adil: `UangMuka` hanya satu baris.
        if ($data->uuidPesananPenjualan !== null && $data->uuidPesananOnline !== null) {
            throw new PelanggaranAturanBisnis('UangMukaDuaSumber', 'Satu penjualan tidak bisa memakai uang muka pre-order dan pesanan online sekaligus.', 'UuidPesananOnline');
        }

        if ($jumlahTempo > 1) {
            throw new PelanggaranAturanBisnis('PembayaranTidakValid', 'Satu penjualan hanya boleh punya satu pembayaran tempo.', 'Pembayaran');
        }

        // F-16d bagian 1: deposit sekali per penjualan dan wajib atas nama pelanggan.
        $jumlahDeposit = count(array_filter($data->pembayaran, fn ($b): bool => $metode[$b->uuidMetodePembayaran]->Jenis === JenisMetodePembayaran::Deposit));

        if ($jumlahDeposit > 1) {
            throw new PelanggaranAturanBisnis('PembayaranTidakValid', 'Satu penjualan hanya boleh punya satu pembayaran deposit.', 'Pembayaran');
        }

        if ($jumlahDeposit === 1 && $data->uuidPelanggan === null) {
            throw new PelanggaranAturanBisnis('DepositTanpaPelanggan', 'Pembayaran deposit wajib memilih pelanggan.', 'UuidPelanggan');
        }

        if ($jumlahTempo === 1 && $data->uuidPelanggan === null) {
            throw new PelanggaranAturanBisnis('TempoTanpaPelanggan', 'Pembayaran tempo wajib memilih pelanggan.', 'UuidPelanggan');
        }

        if ($jumlahTunai > 1) {
            throw new PelanggaranAturanBisnis('PembayaranTidakValid', 'Satu penjualan hanya boleh punya satu pembayaran tunai.', 'Pembayaran');
        }

        if ($nonTunai->Bandingkan($hasil->totalAkhir) > 0) {
            throw new PelanggaranAturanBisnis('PembayaranTidakValid', "Pembayaran non-tunai ({$nonTunai->FormatRupiah()}) melebihi total {$hasil->totalAkhir->FormatRupiah()}.", 'Pembayaran');
        }

        if ($total->Bandingkan($hasil->totalAkhir) < 0) {
            throw new PelanggaranAturanBisnis('PembayaranKurang', "Pembayaran {$total->FormatRupiah()} kurang dari total {$hasil->totalAkhir->FormatRupiah()}.", 'Pembayaran', 422, [
                'TotalAkhir' => $hasil->totalAkhir->KeString(),
                'TotalDibayar' => $total->KeString(),
            ]);
        }

        $kembalian = $hasil->kembalian ?? Uang::Nol();

        if (! $kembalian->SamaDengan($data->ringkasan->kembalian)) {
            throw self::GalatHitungan('Ringkasan.Kembalian', $data->ringkasan->kembalian, $kembalian);
        }

        $bersih = [];

        foreach ($data->pembayaran as $bayar) {
            $m = $metode[$bayar->uuidMetodePembayaran];
            $bersih[] = [$m, $m->Jenis === JenisMetodePembayaran::Tunai ? $bayar->jumlah->Kurangi($kembalian) : $bayar->jumlah];
        }

        return [$total, $bersih];
    }

    private function SimpanPenjualan(
        DataPenjualanPos $data,
        int $idShift,
        DataOutletPenjualan $outlet,
        DataAnggotaOutlet $kasir,
        ?DataAnggotaOutlet $penyetuju,
        CarbonImmutable $tanggalBisnis,
        HasilKalkulasi $hasil,
        Uang $totalDibayar,
        ?int $idPesananTerbuka,
        ?int $idPelanggan,
        ?int $idPenyetujuTempo = null,
        ?int $idPesananPenjualan = null,
        ?int $idReturTukar = null,
        ?int $idApoteker = null,
        ?int $idPenyetujuHarga = null,
    ): Penjualan {
        return Penjualan::query()->create([
            'Uuid' => $data->uuid,
            'IdOutlet' => $outlet->idOutlet,
            'IdShift' => $idShift,
            'IdPerangkat' => $data->idPerangkat,
            'IdPesananTerbuka' => $idPesananTerbuka,
            'IdPesananPenjualan' => $idPesananPenjualan,
            'IdPelanggan' => $idPelanggan,
            'Nomor' => $data->nomor,
            'Kanal' => $data->kanal,
            'Status' => StatusPenjualan::Lunas,
            'TanggalBisnis' => $tanggalBisnis->toDateString(),
            'IdPengguna' => $kasir->id,
            'IdPenyetujuDiskon' => $penyetuju?->id,
            'IdPenyetujuTempo' => $idPenyetujuTempo,
            'IdPenyetujuHarga' => $idPenyetujuHarga,
            'IdApoteker' => $idApoteker,
            'HargaTermasukPajak' => $data->hargaTermasukPajak,
            'PersenBiayaLayanan' => (string) $data->persenBiayaLayanan->toScale(2),
            'PembulatanTunai' => $data->pembulatanTunai === null ? null : ['Kelipatan' => $data->pembulatanTunai->kelipatan, 'Arah' => $data->pembulatanTunai->arah->value],
            'Subtotal' => $hasil->subtotal->KeString(),
            'DiskonBaris' => $hasil->diskonBaris->KeString(),
            'DiskonPesanan' => $hasil->diskonPesanan->KeString(),
            'PoinDitukar' => $data->poinDitukar,
            'DiskonPoin' => $hasil->diskonPoin->KeString(),
            'TotalDiskon' => $hasil->totalDiskon->KeString(),
            'BiayaLayanan' => $hasil->biayaLayanan->KeString(),
            'BiayaKirim' => $hasil->biayaKirim->KeString(),
            'DiskonKirim' => $hasil->diskonKirim->KeString(),
            'TotalPajak' => $hasil->totalPajak->KeString(),
            'TotalPajakEksklusif' => $hasil->totalPajakEksklusif->KeString(),
            'Pembulatan' => $hasil->pembulatan->KeString(),
            'TotalAkhir' => $hasil->totalAkhir->KeString(),
            'TotalDibayar' => $totalDibayar->KeString(),
            'Kembalian' => ($hasil->kembalian ?? Uang::Nol())->KeString(),
            'TotalHpp' => '0.00',
            'Catatan' => $data->catatan === null ? null : mb_substr($data->catatan, 0, 500),
            'NomorAntrian' => $data->nomorAntrian === null ? null : mb_substr($data->nomorAntrian, 0, 10),
            'NamaPemesan' => $data->namaPemesan === null ? null : mb_substr($data->namaPemesan, 0, 60),
            'IdReturTukar' => $idReturTukar,
            'PerluTinjauan' => false,
            'DibuatOfflinePada' => $data->dibuatPada,
            'DiterimaPada' => CarbonImmutable::now(),
        ]);
    }

    /**
     * @param  array<string, DataProdukPenjualan>  $produk
     * @param  array<int, bool>  $denganResep  Apotek: baris ditutup resep, kunci = indeks baris
     * @param  array<int, DataRacikanSiap>  $racikan  Apotek bagian 3: racikan per indeks baris
     * @return list<PenjualanDetail> urutan sama dengan baris masukan
     */
    private function SimpanDetail(DataPenjualanPos $data, Penjualan $penjualan, array $produk, HasilKalkulasi $hasil, array $denganResep, array $racikan = []): array
    {
        $pajakDokumen = [];

        foreach ($data->pajak as $p) {
            $pajakDokumen[$p->kode] = $p->KeLarik();
        }

        $detail = [];

        foreach ($data->baris as $indeks => $baris) {
            $p = $produk[$baris->uuidProduk];
            [$idSatuan, $konversi] = $baris->uuidProdukSatuan === null
                ? [$p->idSatuanDasar, '1']
                : [$p->satuan[$baris->uuidProdukSatuan]['IdSatuan'], $p->satuan[$baris->uuidProdukSatuan]['KonversiKeDasar']];
            $kode = $baris->kodePajak ?? array_keys($pajakDokumen);
            $h = $hasil->baris[$indeks];

            $detail[] = PenjualanDetail::query()->create([
                'Uuid' => $baris->uuid,
                'IdPenjualan' => $penjualan->Id,
                'Urutan' => $indeks + 1,
                'IdProduk' => $p->id,
                'NamaProduk' => mb_substr($p->nama, 0, 255),
                'IdSatuan' => $idSatuan,
                'KonversiKeDasar' => $konversi,
                'Jumlah' => $baris->jumlah->KeString(),
                'JumlahDasar' => $baris->jumlah->Kali($konversi)->KeString(),
                'HargaSatuan' => $baris->hargaSatuan->KeString(),
                // Price engine lapis 1: jejak harga sebelum diubah kasir (null = harga price engine, atau tidak berubah).
                'HargaAsli' => $baris->hargaManual !== null && ! $baris->hargaManual->hargaAsli->SamaDengan($baris->hargaSatuan) ? $baris->hargaManual->hargaAsli->KeString() : null,
                'AlasanHargaManual' => $baris->hargaManual !== null && ! $baris->hargaManual->hargaAsli->SamaDengan($baris->hargaSatuan) && trim($baris->hargaManual->alasan) !== '' ? mb_substr(trim($baris->hargaManual->alasan), 0, 100) : null,
                'HargaPilihan' => $baris->hargaPilihan->KeString(),
                'Pilihan' => $baris->pilihan === [] ? null : $baris->pilihan,
                'HargaTermasukPajak' => $baris->hargaTermasukPajak ?? $data->hargaTermasukPajak,
                'SnapshotPajak' => array_values(array_filter(array_map(fn (string $k): ?array => $pajakDokumen[$k] ?? null, $kode))),
                'DiskonManual' => $baris->diskonManual?->KeLarik(),
                'Bruto' => $h->bruto->KeString(),
                'JumlahDiskon' => $h->diskon->KeString(),
                'JumlahDiskonPesanan' => $h->diskonPesanan->KeString(),
                'BiayaLayanan' => $h->biayaLayanan->KeString(),
                'BiayaKirim' => $h->biayaKirim->KeString(),
                'JumlahPajak' => $h->pajak->KeString(),
                'PajakEksklusif' => $h->pajakEksklusif->KeString(),
                'TotalBaris' => $h->totalBaris->KeString(),
                'HppSatuan' => '0',
                'TotalHpp' => '0.00',
                'Catatan' => $baris->catatan === null ? null : mb_substr($baris->catatan, 0, 255),
                // F-05h: snapshot nomor seri yang dicatat kasir & masa garansi produk (struk, kartu garansi).
                'NomorSeri' => $p->pelacakan === PelacakanProduk::Seri && $baris->nomorSeri !== [] ? $baris->nomorSeri : null,
                'MasaGaransiBulan' => $p->pelacakan === PelacakanProduk::Seri ? $p->masaGaransiBulan : null,
                // Apotek (§9.5): snapshot golongan obat & OWA saat dijual, dan apakah baris ini ditutup resep.
                // Racikan: golongan terkuat komponennya (bukan OWA) supaya ikut laporan obat wajib resep.
                'GolonganObat' => isset($racikan[$indeks]) ? $racikan[$indeks]->golongan?->value : $p->golonganObat?->value,
                'ObatWajibApotek' => ! isset($racikan[$indeks]) && $p->golonganObat !== null && $p->obatWajibApotek,
                'DenganResep' => $denganResep[$indeks] ?? false,
                'Racikan' => isset($racikan[$indeks]) ? $racikan[$indeks]->snapshot : null,
            ]);
        }

        return $detail;
    }

    private function SimpanPajak(DataPenjualanPos $data, Penjualan $penjualan, HasilKalkulasi $hasil): void
    {
        foreach ($data->pajak as $p) {
            $rincian = $hasil->pajak[$p->kode] ?? new HasilPajakKalkulasi($p->kode, Uang::Nol(), Uang::Nol());

            PenjualanPajak::query()->create([
                'IdPenjualan' => $penjualan->Id,
                'KodeJenisPajak' => $p->kode,
                'Tarif' => (string) $p->tarif->toScale(6),
                'PengaliDppPembilang' => $p->pengaliDppPembilang,
                'PengaliDppPenyebut' => $p->pengaliDppPenyebut,
                'DasarPengenaan' => $p->dasarPengenaan,
                'KenaBiayaKirim' => $p->kenaBiayaKirim,
                'Dpp' => $rincian->dpp->KeString(),
                'Jumlah' => $rincian->jumlah->KeString(),
            ]);
        }
    }

    /**
     * @param  array<string, MetodePembayaran>  $metode
     * @param  array<string, string>  $refEksternal  Uuid pembayaran → nomor pesanan gerbang (F-08 QRIS dinamis)
     */
    private function SimpanPembayaran(DataPenjualanPos $data, Penjualan $penjualan, array $metode, array $refEksternal): void
    {
        foreach ($data->pembayaran as $indeks => $bayar) {
            $m = $metode[$bayar->uuidMetodePembayaran];

            PenjualanPembayaran::query()->create([
                'Uuid' => $bayar->uuid,
                'IdPenjualan' => $penjualan->Id,
                'Urutan' => $indeks + 1,
                'IdMetodePembayaran' => $m->Id,
                'JenisMetode' => $m->Jenis,
                'NamaMetode' => mb_substr($m->Nama, 0, 100),
                'Jumlah' => $bayar->jumlah->KeString(),
                'Status' => PenjualanPembayaran::STATUS_DITERIMA,
                'Referensi' => $bayar->referensi,
                'RefEksternal' => $refEksternal[$bayar->uuid] ?? null,
                'DibayarPada' => $data->dibuatPada,
            ]);
        }
    }

    /**
     * Mutasi stok `Penjualan` dari lokasi stok Toko outlet (produk berstok; bahan resep dari lokasi produksinya, BR-05.4; komponen paket, bahan pilihan;
     * satuan dasar; komponen racikan), lalu HPP per baris dari hasil mutasi.
     *
     * @param  array<string, DataProdukPenjualan>  $produk
     * @param  list<PenjualanDetail>  $detail
     * @param  array<int, DataRacikanSiap>  $racikan
     * @return array{0: array<string, string>, 1: array<string, Uang>} [kode → alasan tinjauan, perubahan nilai per peran akun persediaan]
     */
    private function CatatStok(DataPenjualanPos $data, Penjualan $penjualan, DataOutletPenjualan $outlet, array $produk, array $detail, int $idKasir, array $racikan = []): array
    {
        $kebutuhan = $this->komposisi->AmbilKebutuhanStok(array_values(array_map(fn (DataProdukPenjualan $p): int => $p->id, $produk)));
        $uuidPilihan = [];

        foreach ($data->baris as $baris) {
            foreach ($baris->pilihan as $p) {
                $uuidPilihan[] = $p['UuidPilihan'];
            }
        }

        $pilihan = $this->komposisi->AmbilPilihan(array_values(array_unique($uuidPilihan)));
        $tinjauan = [];
        $barisMutasi = [];
        $infoPerKunci = [];
        $indeksPerKunci = [];
        $permintaanBatch = [];
        $permintaanSeri = [];
        $nomorDipakai = [];
        $lokasiPerKunci = [];

        foreach ($data->baris as $indeks => $baris) {
            $jumlahDasar = Kuantitas::Dari($detail[$indeks]->JumlahDasar);
            $daftar = [];

            foreach ($kebutuhan[$produk[$baris->uuidProduk]->id] ?? [] as $k) {
                $daftar[] = [$k, $jumlahDasar];
            }

            foreach ($baris->pilihan as $p) {
                $dataPilihan = $pilihan[$p['UuidPilihan']] ?? null;

                if ($dataPilihan === null) {
                    $tinjauan['PilihanTidakDikenal'] = "PilihanTidakDikenal: pilihan \"{$p['Nama']}\" sudah dihapus, bahannya tidak dikurangi";

                    continue;
                }

                if ($dataPilihan->bahan !== null) {
                    $daftar[] = [$dataPilihan->bahan, $baris->jumlah];
                }
            }

            // Apotek bagian 3: komponen racikan untuk satu racikan × jumlah dasar baris (obat ber-batch tetap FEFO).
            foreach (isset($racikan[$indeks]) ? $racikan[$indeks]->kebutuhan : [] as [$k, $perRacikan]) {
                $daftar[] = [$k, Kuantitas::Dari((string) $jumlahDasar->KeDesimal()->multipliedBy($perRacikan)->toScale(Kuantitas::SKALA, RoundingMode::HalfUp))];
            }

            $urutan = 0;

            foreach ($daftar as [$k, $dasar]) {
                /** @var DataKebutuhanStok $k */
                $langsung = $k->idProduk === $produk[$baris->uuidProduk]->id && $k->pembilang->isEqualTo($k->penyebut);
                $this->PastikanBahanBisaDikurangi($k, $indeks, $langsung);

                if ($k->dihapus) {
                    // Produk hanya bisa dihapus bila belum pernah dipakai (BR-03.2): tidak punya stok untuk dikurangi.
                    $tinjauan['ProdukDihapus'] = "ProdukDihapus: {$k->nama} sudah dihapus, stok tidak dikurangi";

                    continue;
                }

                $jumlah = BigDecimal::of($dasar->KeString())->multipliedBy($k->pembilang)->dividedBy($k->penyebut, Kuantitas::SKALA, RoundingMode::HalfUp);

                if ($jumlah->isZero()) {
                    continue;
                }

                $urutan++;
                $kunci = $baris->uuid.'/'.$urutan;
                $infoPerKunci[$kunci] = $k;
                $indeksPerKunci[$kunci] = $indeks;
                $lokasiPerKunci[$kunci] = $k->lokasiProduksi?->value;

                if ($k->pelacakan === PelacakanProduk::Seri) {
                    // F-05h: satu unit = satu nomor seri yang dicatat kasir; server memvalidasi ketersediaannya (CatatStokSeri).
                    $nomor = $baris->nomorSeri;
                    $bulat = $jumlah->toScale(0, RoundingMode::Down);

                    if (! $jumlah->isEqualTo($bulat) || count($nomor) !== $bulat->toInt()) {
                        throw new PelanggaranAturanBisnis('NomorSeriTidakSesuai', "{$k->nama} memakai nomor seri: isi {$bulat} nomor seri (satu per unit), bukan ".count($nomor).'.', "Baris.{$indeks}.NomorSeri");
                    }

                    foreach ($nomor as $no) {
                        $tanda = $k->idProduk.'|'.mb_strtoupper($no);

                        if (isset($nomorDipakai[$tanda])) {
                            throw new PelanggaranAturanBisnis('NomorSeriGanda', "Nomor seri {$no} dipakai dua kali di penjualan ini.", "Baris.{$indeks}.NomorSeri");
                        }

                        $nomorDipakai[$tanda] = true;
                    }

                    $permintaanSeri[$kunci] = [$nomor, $detail[$indeks]->Id];

                    continue;
                }

                if ($k->pelacakan === PelacakanProduk::Batch) {
                    // F-05g: batch dipilih FEFO di server (dirinci di CatatStokBatch), bukan oleh kasir.
                    $permintaanBatch[$kunci] = [Kuantitas::Dari($jumlah), $detail[$indeks]->Id];

                    continue;
                }

                $barisMutasi[] = new DataBarisMutasi(
                    kunciBaris: $kunci,
                    idProduk: $k->idProduk,
                    idGudang: 0,
                    jenisMutasi: JenisMutasi::Penjualan,
                    jumlah: Kuantitas::Dari($jumlah->negated()),
                    modeNilai: ModeNilaiMutasi::Berjalan,
                    idReferensiDetail: $detail[$indeks]->Id,
                );
            }
        }

        if ($barisMutasi === [] && $permintaanBatch === [] && $permintaanSeri === []) {
            return [$tinjauan, []];
        }

        if ($outlet->idGudangToko === null) {
            throw new PelanggaranAturanBisnis('LokasiStokTidakAda', "Outlet {$outlet->namaOutlet} belum punya lokasi stok Toko untuk mengurangi stok penjualan.", 'Baris');
        }

        $idGudang = (int) $outlet->idGudangToko;
        // BR-05.4: bahan resep dikurangi dari lokasi produksi (Dapur/Bar) bila outlet punya; selain itu lokasi Toko.
        $gudangPerKunci = [];

        foreach ($lokasiPerKunci as $kunci => $lokasi) {
            $gudangPerKunci[$kunci] = $lokasi === null ? $idGudang : ($this->outletPenjualan->AmbilIdGudangProduksi($outlet->idOutlet, $lokasi) ?? $idGudang);
        }

        /** @var array<string, HasilBarisMutasi> $hasilBaris */
        $hasilBaris = [];
        $kurang = [];

        if ($barisMutasi !== []) {
            $hasil = $this->catatMutasi->Jalankan($this->BuatDokumenMutasi($data, $penjualan, $idKasir, array_map(fn (DataBarisMutasi $b): DataBarisMutasi => new DataBarisMutasi(
                kunciBaris: $b->kunciBaris,
                idProduk: $b->idProduk,
                idGudang: $gudangPerKunci[$b->kunciBaris] ?? $idGudang,
                jenisMutasi: $b->jenisMutasi,
                jumlah: $b->jumlah,
                modeNilai: $b->modeNilai,
                idReferensiDetail: $b->idReferensiDetail,
            ), $barisMutasi), abaikanBatasMinus: true));
            $hasilBaris = $hasil->baris;

            foreach ($hasil->AmbilBarisStokTidakCukup() as $b) {
                $kurang[$b->idProduk] = $infoPerKunci[$b->kunciBaris]->nama.' (sisa '.PemeriksaStokMinus::FormatJumlah($b->saldoSetelah).')';
            }
        }

        $tambahan = [];

        if ($permintaanBatch !== []) {
            $tambahan[] = $this->CatatStokBatch($data, $penjualan, $idKasir, $gudangPerKunci, $permintaanBatch, $infoPerKunci);
        }

        if ($permintaanSeri !== []) {
            $tambahan[] = $this->CatatStokSeri($data, $penjualan, $idKasir, $idGudang, $permintaanSeri, $infoPerKunci);
        }

        foreach ($tambahan as [$hasilTambahan, $asalKunci, $tinjauanTambahan]) {
            foreach ($hasilTambahan as $kunciBaru => $b) {
                $hasilBaris[$kunciBaru] = $b;
                $infoPerKunci[$kunciBaru] = $infoPerKunci[$asalKunci[$kunciBaru]];
                $indeksPerKunci[$kunciBaru] = $indeksPerKunci[$asalKunci[$kunciBaru]];
            }

            $tinjauan = [...$tinjauan, ...$tinjauanTambahan];
        }

        $this->IsiHppBaris($hasilBaris, $detail, $indeksPerKunci);

        if ($kurang !== []) {
            $tinjauan['StokTidakCukup'] = 'StokTidakCukup: '.implode(', ', $kurang);
        }

        $perubahan = [];

        foreach ($hasilBaris as $kunci => $b) {
            $peran = $this->petaAkunPersediaan->UntukJenis($infoPerKunci[$kunci]->jenis)->value;
            $perubahan[$peran] = ($perubahan[$peran] ?? Uang::Nol())->Tambah($b->totalHpp);
        }

        return [$tinjauan, $perubahan];
    }

    /**
     * @param  list<DataBarisMutasi>  $baris
     */
    private function BuatDokumenMutasi(DataPenjualanPos $data, Penjualan $penjualan, int $idKasir, array $baris, bool $abaikanBatasMinus): DataDokumenMutasi
    {
        return new DataDokumenMutasi(
            jenisReferensi: JenisReferensiMutasi::Penjualan,
            idReferensi: $penjualan->Id,
            uuidReferensi: $penjualan->Uuid,
            nomorReferensi: $penjualan->Nomor,
            tanggalBisnis: CarbonImmutable::parse($penjualan->TanggalBisnis->toDateString()),
            idPengguna: $idKasir,
            idPerangkat: $data->idPerangkat,
            baris: $baris,
            abaikanBatasMinus: $abaikanBatasMinus,
        );
    }

    /**
     * F-05g: penjualan produk ber-batch. Server memilih batch secara FEFO (kedaluwarsa terdekat lebih dulu, `AlokatorBatchFefo`)
     * dan satu baris penjualan bisa dipecah ke beberapa batch (kunci `{kunci}#{n}`). Batch tidak pernah minus, sehingga
     * bagian yang tidak tertutup batch mana pun **tidak dikurangi** dan penjualan ditandai `BatchTidakCukup` untuk
     * dikoreksi pemilik lewat penyesuaian/opname (penjualan sudah terjadi di kasir, BR-07.x: tidak ditolak). Batch yang
     * sudah lewat kedaluwarsa pada tanggal bisnis tetap tercatat tetapi ditandai `BatchKedaluwarsa`.
     *
     * Rencana dibuat dari bacaan tanpa kunci; bila penjualan lain mengambil batch yang sama lebih dulu, mesin buku stok menolak
     * dengan `StokBatchTidakCukup` dan rencana dibuat ulang dari data terkini (savepoint, paling banyak 3 percobaan).
     *
     * @param  array<string, int>  $gudangPerKunci  kunci → Id lokasi stok (BR-05.4: lokasi produksi bahan resep, selain itu Toko)
     * @param  array<string, array{0: Kuantitas, 1: int}>  $permintaan  kunci → [jumlah dasar positif, Id baris penjualan]
     * @param  array<string, DataKebutuhanStok>  $infoPerKunci
     * @return array{0: array<string, HasilBarisMutasi>, 1: array<string, string>, 2: array<string, string>} [hasil per kunci pecahan, kunci pecahan → kunci asal, tinjauan]
     */
    private function CatatStokBatch(DataPenjualanPos $data, Penjualan $penjualan, int $idKasir, array $gudangPerKunci, array $permintaan, array $infoPerKunci): array
    {
        $tanggalBisnis = CarbonImmutable::parse($penjualan->TanggalBisnis->toDateString());
        $daftarPermintaan = [];

        foreach ($permintaan as $kunci => [$jumlah]) {
            $daftarPermintaan[] = ['Kunci' => $kunci, 'IdProduk' => $infoPerKunci[$kunci]->idProduk, 'IdGudang' => $gudangPerKunci[$kunci], 'Jumlah' => $jumlah];
        }

        for ($percobaan = 1; ; $percobaan++) {
            $rencana = $this->alokatorBatch->Susun($daftarPermintaan, $percobaan > 1);
            $barisMutasi = [];
            $asalKunci = [];

            foreach ($rencana['Alokasi'] as $kunci => $daftarAlokasi) {
                foreach (array_values($daftarAlokasi) as $n => $a) {
                    $kunciBaru = $kunci.'#'.($n + 1);
                    $asalKunci[$kunciBaru] = $kunci;
                    $barisMutasi[] = new DataBarisMutasi(
                        kunciBaris: $kunciBaru,
                        idProduk: $infoPerKunci[$kunci]->idProduk,
                        idGudang: $gudangPerKunci[$kunci],
                        jenisMutasi: JenisMutasi::Penjualan,
                        jumlah: $a['Jumlah']->Negasi(),
                        modeNilai: ModeNilaiMutasi::Berjalan,
                        idReferensiDetail: $permintaan[$kunci][1],
                        idBatchStok: $a['IdBatchStok'],
                    );
                }
            }

            $hasilBaris = [];

            if ($barisMutasi !== []) {
                try {
                    $hasilBaris = DB::transaction(fn (): array => $this->catatMutasi->Jalankan($this->BuatDokumenMutasi($data, $penjualan, $idKasir, $barisMutasi, abaikanBatasMinus: false))->baris);
                } catch (PelanggaranAturanBisnis $e) {
                    if ($e->kode === 'StokBatchTidakCukup' && $percobaan < self::PERCOBAAN_BATCH) {
                        continue;
                    }

                    throw $e;
                }
            }

            break;
        }

        $tinjauan = [];
        $kurang = [];
        $kedaluwarsa = [];

        foreach ($rencana['Sisa'] as $kunci => $sisa) {
            $kurang[$infoPerKunci[$kunci]->idProduk] = $infoPerKunci[$kunci]->nama.' (kurang '.PemeriksaStokMinus::FormatJumlah($sisa).')';
        }

        foreach ($rencana['Alokasi'] as $kunci => $daftarAlokasi) {
            foreach ($daftarAlokasi as $a) {
                if ($a['TanggalKedaluwarsa'] !== null && $a['TanggalKedaluwarsa']->lessThan($tanggalBisnis)) {
                    $kedaluwarsa[] = $infoPerKunci[$kunci]->nama.' batch '.$a['NomorBatch'].' (kedaluwarsa '.$a['TanggalKedaluwarsa']->toDateString().')';
                }
            }
        }

        if ($kurang !== []) {
            $tinjauan['BatchTidakCukup'] = 'BatchTidakCukup: '.implode(', ', $kurang).'; bagian itu tidak mengurangi stok batch mana pun';
        }

        if ($kedaluwarsa !== []) {
            $tinjauan['BatchKedaluwarsa'] = 'BatchKedaluwarsa: '.implode(', ', array_values(array_unique($kedaluwarsa)));
        }

        return [$hasilBaris, $asalKunci, $tinjauan];
    }

    /**
     * F-05h: penjualan produk bernomor seri. Kasir mencatat nomor seri/IMEI tiap unit (ketik atau pindai); server hanya
     * memvalidasi ketersediaannya di lokasi stok Toko. Nomor yang tidak `Tersedia` (belum pernah diterima, sudah terjual,
     * di lokasi lain) tidak ditolak (barangnya sudah di tangan pembeli, §18.3): unit itu tidak mengurangi stok dan penjualan
     * ditandai `SerialBermasalah` untuk dikoreksi pemilik. Nomor yang tersedia dicatat sebagai mutasi satu unit (`{kunci}#{n}`),
     * status `Terjual`, dan ditautkan ke baris penjualan (riwayat & garansi). Nomor yang keburu diambil penjualan lain
     * saat penguncian memicu pemilihan ulang dari data terkini (savepoint, ≤ 3 percobaan).
     *
     * @param  array<string, array{0: list<string>, 1: int}>  $permintaan  kunci → [nomor seri, Id baris penjualan]
     * @param  array<string, DataKebutuhanStok>  $infoPerKunci
     * @return array{0: array<string, HasilBarisMutasi>, 1: array<string, string>, 2: array<string, string>} [hasil per kunci pecahan, kunci pecahan → kunci asal, tinjauan]
     */
    private function CatatStokSeri(DataPenjualanPos $data, Penjualan $penjualan, int $idKasir, int $idGudang, array $permintaan, array $infoPerKunci): array
    {
        for ($percobaan = 1; ; $percobaan++) {
            $barisMutasi = [];
            $asalKunci = [];
            $bermasalah = [];

            foreach ($permintaan as $kunci => [$nomor, $idDetail]) {
                $tersedia = $this->infoSeri->CariTersedia($infoPerKunci[$kunci]->idProduk, $idGudang, $nomor, $percobaan > 1);

                foreach ($nomor as $n => $no) {
                    if (! isset($tersedia[mb_strtoupper($no)])) {
                        $bermasalah[] = $infoPerKunci[$kunci]->nama.' '.$no;

                        continue;
                    }

                    $kunciBaru = $kunci.'#'.($n + 1);
                    $asalKunci[$kunciBaru] = $kunci;
                    $barisMutasi[] = new DataBarisMutasi(
                        kunciBaris: $kunciBaru,
                        idProduk: $infoPerKunci[$kunci]->idProduk,
                        idGudang: $idGudang,
                        jenisMutasi: JenisMutasi::Penjualan,
                        jumlah: Kuantitas::Dari('-1'),
                        modeNilai: ModeNilaiMutasi::Berjalan,
                        idReferensiDetail: $idDetail,
                        idNomorSeri: $tersedia[mb_strtoupper($no)],
                    );
                }
            }

            $hasilBaris = [];

            if ($barisMutasi !== []) {
                try {
                    $hasilBaris = DB::transaction(fn (): array => $this->catatMutasi->Jalankan($this->BuatDokumenMutasi($data, $penjualan, $idKasir, $barisMutasi, abaikanBatasMinus: false))->baris);
                } catch (PelanggaranAturanBisnis $e) {
                    if (in_array($e->kode, ['NomorSeriTidakTersedia', 'NomorSeriSudahAda'], true) && $percobaan < self::PERCOBAAN_SERI) {
                        continue;
                    }

                    throw $e;
                }
            }

            break;
        }

        $detailPerKunci = [];

        foreach ($hasilBaris as $kunciBaru => $b) {
            $detailPerKunci[$permintaan[$asalKunci[$kunciBaru]][1]][] = (int) $b->idNomorSeri;
        }

        foreach ($detailPerKunci as $idDetail => $idSeri) {
            $this->pelacakSeri->TautkanPenjualanDetail($idSeri, $idDetail);
        }

        $tinjauan = $bermasalah === [] ? [] : ['SerialBermasalah' => 'SerialBermasalah: '.implode(', ', $bermasalah).'; nomor itu tidak tercatat tersedia di stok, stok tidak dikurangi'];

        return [$hasilBaris, $asalKunci, $tinjauan];
    }

    private function PastikanBahanBisaDikurangi(DataKebutuhanStok $k, int $indeks, bool $langsung): void
    {
        if ($k->pelacakan === PelacakanProduk::Seri && ! $langsung) {
            throw new PelanggaranAturanBisnis('PelacakanBelumDidukung', "{$k->nama} ({$k->jalur}) memakai {$k->pelacakan->AmbilLabel()}; nomor seri hanya bisa dijual sebagai barang langsung, bukan bahan resep, komponen paket, atau bahan pilihan.", "Baris.{$indeks}.UuidProduk");
        }
    }

    /**
     * HPP baris = −Σ TotalHpp mutasi baris itu; HPP per satuan jual = TotalHpp ÷ Jumlah (skala 6).
     *
     * @param  array<string, HasilBarisMutasi>  $hasilBaris
     * @param  list<PenjualanDetail>  $detail
     * @param  array<string, int>  $indeksPerKunci
     */
    private function IsiHppBaris(array $hasilBaris, array $detail, array $indeksPerKunci): void
    {
        $total = [];

        foreach ($hasilBaris as $kunci => $b) {
            $indeks = $indeksPerKunci[$kunci];
            $total[$indeks] = ($total[$indeks] ?? Uang::Nol())->Kurangi($b->totalHpp);
        }

        foreach ($total as $indeks => $hpp) {
            $d = $detail[$indeks];
            $d->TotalHpp = $hpp->KeString();
            $d->HppSatuan = (string) BigDecimal::of($hpp->KeString())->dividedBy($d->Jumlah, 6, RoundingMode::HalfUp);
            $d->save();
        }
    }

    private static function GalatHitungan(string $bidang, Uang $perangkat, Uang $server): PelanggaranAturanBisnis
    {
        return new PelanggaranAturanBisnis(
            'HitunganTidakCocok',
            "Hitungan {$bidang} di perangkat ({$perangkat->FormatRupiah()}) berbeda dengan hitungan server ({$server->FormatRupiah()}). Perbarui aplikasi kasir.",
            $bidang,
            422,
            ['Perangkat' => $perangkat->KeString(), 'Server' => $server->KeString()],
        );
    }

    /** Label tiket dapur penjualan bayar-dulu: `#042 Budi`, nama/nomor saja, atau catatan (maks. 60 karakter). */
    private static function LabelTiketDapur(Penjualan $penjualan): ?string
    {
        $antrian = trim(implode(' ', array_filter([
            $penjualan->NomorAntrian === null ? null : '#'.$penjualan->NomorAntrian,
            $penjualan->NamaPemesan,
        ], fn (?string $b): bool => $b !== null && $b !== '')));
        $label = $antrian !== '' ? $antrian : $penjualan->Catatan;

        return $label === null ? null : mb_substr($label, 0, 60);
    }
}
