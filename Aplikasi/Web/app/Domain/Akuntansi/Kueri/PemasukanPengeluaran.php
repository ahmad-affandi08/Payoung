<?php

declare(strict_types=1);

namespace App\Domain\Akuntansi\Kueri;

use App\Domain\Akuntansi\Data\AkumulatorUangKas;
use App\Domain\Akuntansi\Data\HitunganUangKas;
use App\Domain\Akuntansi\Data\SaringLaporanKeuangan;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Enum\TipeAkun;
use App\Domain\Akuntansi\Layanan\PenggolongUangKas;
use App\Domain\Akuntansi\Model\Akun;
use App\Domain\Akuntansi\Model\Jurnal;
use App\Domain\Akuntansi\Model\JurnalDetail;
use App\Domain\Akuntansi\Model\PemetaanAkun;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Pembelian\Layanan\PemisahBelanjaBahanBaku;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as KueriDasar;
use Illuminate\Support\Facades\DB;

/**
 * Laporan Pemasukan & Pengeluaran (D-255): uang yang benar-benar masuk dan keluar akun kas & bank dalam satu periode,
 * dibandingkan dengan periode sebelumnya yang sama panjang. Bukan laba rugi: belanja stok dihitung penuh sebagai uang
 * keluar dan HPP tidak dipakai, supaya angkanya bisa dicocokkan dengan saldo rekening.
 *
 * Dasarnya perubahan kas bersih tiap jurnal (Σ debit − kredit baris kas & bank). Jurnal yang kasnya nol (pindah antar
 * akun kas) tidak tampil. Jurnal penjualan dipisah dari biaya layanan, jurnal pencairan dana non-tunai dirinci menjadi
 * dana kotor dan potongan platform, dan jurnal kas & bank dirinci per akun lawan. Modal, prive, pinjaman, dan beli aset
 * dipisah ke "di luar hitungan usaha" supaya tidak mengubah selisih. Selisih + di luar usaha = perubahan saldo kas.
 */
final class PemasukanPengeluaran
{
    public function __construct(private readonly PemisahBelanjaBahanBaku $pemisahBelanja) {}

    /** Sumber yang dirinci per akun lawan, bukan per jenis dokumen. */
    private const SUMBER_PER_AKUN = [JenisSumberJurnal::TransaksiKasBank, JenisSumberJurnal::MutasiKas];

    /** Sumber belanja stok: uang keluarnya dipecah menjadi bahan baku dan dagangan menurut isi dokumennya. */
    private const SUMBER_BELANJA = [JenisSumberJurnal::PenerimaanBarang, JenisSumberJurnal::PembayaranHutang, JenisSumberJurnal::FakturPembelian];

    /** Rentang (hari) paling panjang yang grafiknya per hari, selebihnya per bulan. */
    private const MAKS_HARI_GRAFIK_HARIAN = 62;

    /**
     * @return array<string, mixed>
     */
    public function Ambil(SaringLaporanKeuangan $saring): array
    {
        $h = $this->Siapkan($saring);

        return $this->Susun($h->saring, $h->dariSebelumnya, $h->sampaiSebelumnya, $h->jenisPembanding, $h->kini, $h->lalu, $h->kenaikanKas, $h->catatan, $h->adaAkunKas);
    }

    /**
     * Menghitung periode ini dan pembandingnya beserta rinciannya (dipakai halaman dan buku kerja Excel).
     */
    public function Siapkan(SaringLaporanKeuangan $saring): HitunganUangKas
    {
        // Hari yang belum terjadi tidak punya uang. Memotong sampai hari ini membuat pembanding adil (1 sampai 10 Nov
        // dibanding 1 sampai 10 Okt, bukan seluruh Oktober).
        $hariIni = CarbonImmutable::today()->toDateString();

        if ($saring->sampai > $hariIni && $saring->dari <= $hariIni) {
            $saring = new SaringLaporanKeuangan($saring->dari, $hariIni, $saring->idOutlet, $saring->idOutletBoleh);
        }

        [$dariLalu, $sampaiLalu, $jenisPembanding] = self::HitungPembanding($saring->dari, $saring->sampai);
        $idKas = array_values(Akun::query()->where('KasBank', true)->pluck('Id')->map(fn ($id): int => (int) $id)->all());

        if ($idKas === []) {
            return new HitunganUangKas($saring, $dariLalu, $sampaiLalu, $jenisPembanding, new AkumulatorUangKas, new AkumulatorUangKas, Uang::Nol(), [], false);
        }

        $idPajak = $this->IdAkunPeran(PeranAkun::PpnKeluaran, PeranAkun::PpnMasukan, PeranAkun::HutangPbjt);
        $idLayanan = $this->IdAkunPeran(PeranAkun::PendapatanBiayaLayanan);
        $kini = $this->Hitung($saring, $idKas, $idPajak, $idLayanan, $saring->dari, $saring->sampai);
        $lalu = $this->Hitung($saring, $idKas, $idPajak, $idLayanan, $dariLalu, $sampaiLalu);
        $kenaikan = $this->JumlahkanKas($saring, $idKas, $saring->dari, $saring->sampai);

        $catatan = [
            'Hutang' => $this->Saldo($saring, $this->IdAkunPeran(PeranAkun::HutangUsaha, PeranAkun::HutangBelumDifakturkan), $saring->sampai, true)->KeString(),
            'Piutang' => $this->Saldo($saring, $this->IdAkunPeran(PeranAkun::PiutangUsaha, PeranAkun::PiutangBelumDifakturkan), $saring->sampai, false)->KeString(),
            'MenungguCair' => $this->Saldo($saring, $this->IdAkunPeran(PeranAkun::PiutangPencairan), $saring->sampai, false)->KeString(),
        ];

        return new HitunganUangKas($saring, $dariLalu, $sampaiLalu, $jenisPembanding, $kini, $lalu, $kenaikan, $catatan, true);
    }

    /**
     * Periode pembanding yang adil: hari lawan hari, pekan berjalan lawan pekan lalu pada hari yang sama, tanggal 1
     * sampai hari ini lawan tanggal yang sama bulan lalu (atau tahun lalu), bulan atau tahun penuh lawan yang
     * sebelumnya. Rentang lain dibandingkan dengan rentang sama panjang tepat sebelumnya. Jenis: `Kemarin`,
     * `MingguLalu`, `BulanLalu`, `TahunLalu`, atau `PeriodeSebelumnya`.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function HitungPembanding(string $dari, string $sampai): array
    {
        $awal = CarbonImmutable::parse($dari)->startOfDay();
        $akhir = CarbonImmutable::parse($sampai)->startOfDay();
        $hari = (int) $awal->diffInDays($akhir) + 1;

        if ($hari === 1) {
            return [$awal->subDay()->toDateString(), $awal->subDay()->toDateString(), 'Kemarin'];
        }

        if ($awal->isMonday() && $hari <= 7) {
            return [$awal->subDays(7)->toDateString(), $akhir->subDays(7)->toDateString(), 'MingguLalu'];
        }

        if ($awal->day === 1 && $awal->month === 1 && $akhir->year === $awal->year && ! ($akhir->month === 12 && $akhir->day === 31) && $akhir->month > 1) {
            return [$awal->subYear()->toDateString(), $akhir->subYearNoOverflow()->toDateString(), 'TahunLalu'];
        }

        if ($awal->day === 1 && $akhir->isSameMonth($awal) && ! $akhir->isLastOfMonth()) {
            $bulanLalu = $awal->subMonthNoOverflow();

            return [$bulanLalu->toDateString(), $bulanLalu->setDay(min($akhir->day, $bulanLalu->daysInMonth))->toDateString(), 'BulanLalu'];
        }

        [$dariLalu, $sampaiLalu] = LabaRugi::HitungPeriodeSebelumnya($dari, $sampai);
        $bulanPenuh = $awal->day === 1 && $akhir->isLastOfMonth();
        $jumlahBulan = ($akhir->year * 12 + $akhir->month) - ($awal->year * 12 + $awal->month) + 1;
        $jenis = match (true) {
            $bulanPenuh && $jumlahBulan === 1 => 'BulanLalu',
            $bulanPenuh && $jumlahBulan === 12 && $awal->month === 1 => 'TahunLalu',
            default => 'PeriodeSebelumnya',
        };

        return [$dariLalu, $sampaiLalu, $jenis];
    }

    /**
     * @param  list<int>  $idKas
     * @param  list<int>  $idPajak
     * @param  list<int>  $idLayanan
     */
    private function Hitung(SaringLaporanKeuangan $saring, array $idKas, array $idPajak, array $idLayanan, string $dari, string $sampai): AkumulatorUangKas
    {
        $a = new AkumulatorUangKas;
        $perJurnal = $this->KueriPerJurnal($saring, $idKas, $dari, $sampai, null);
        $sumberAkun = array_map(fn (JenisSumberJurnal $j): string => $j->value, self::SUMBER_PER_AKUN);
        $sumberBelanja = array_map(fn (JenisSumberJurnal $j): string => $j->value, self::SUMBER_BELANJA);

        // 1) Jurnal yang digolongkan menurut jenis dokumen sumbernya, dijumlah per tanggal dan arah di SQL.
        $baris = DB::query()->fromSub($perJurnal, 't')
            ->whereNotIn('t.JenisSumber', [...$sumberAkun, ...$sumberBelanja])
            ->selectRaw('`t`.`Tgl` AS `Tgl`, `t`.`IdOutlet` AS `IdOutlet`, `t`.`JenisSumber` AS `JenisSumber`, CASE WHEN `t`.`N` > 0 THEN 1 ELSE 0 END AS `Masuk`, CAST(SUM(ABS(`t`.`N`)) AS DECIMAL(20,2)) AS `Nilai`')
            ->groupBy('Tgl', 'IdOutlet', 'JenisSumber', 'Masuk')
            ->get();
        /** @var array<string, Uang> $penjualanMasuk */
        $penjualanMasuk = [];
        /** @var array<string, Uang> $pencairan */
        $pencairan = [];

        foreach ($baris as $satu) {
            $jenis = JenisSumberJurnal::tryFrom((string) $satu->JenisSumber);
            $masuk = (int) $satu->Masuk === 1;
            $nilai = Uang::Dari((string) $satu->Nilai);
            $tanggal = (string) $satu->Tgl;
            $idOutlet = $satu->IdOutlet === null ? null : (int) $satu->IdOutlet;
            $kunci = $tanggal.'|'.($idOutlet ?? '');

            if ($jenis === JenisSumberJurnal::Penjualan && $masuk) {
                $penjualanMasuk[$kunci] = ($penjualanMasuk[$kunci] ?? Uang::Nol())->Tambah($nilai);

                continue;
            }

            if ($jenis === JenisSumberJurnal::Pencairan) {
                $pencairan[$kunci] = ($pencairan[$kunci] ?? Uang::Nol())->Tambah($masuk ? $nilai : Uang::Nol()->Kurangi($nilai));

                continue;
            }

            $a->Tambah($tanggal, $masuk, PenggolongUangKas::DariSumber($jenis, $masuk, (string) $satu->JenisSumber), $nilai, $idOutlet);
        }

        $this->PisahkanBelanja($a, $perJurnal, $sumberBelanja);
        $this->PisahkanBiayaLayanan($a, $saring, $idKas, $idLayanan, $dari, $sampai, $penjualanMasuk);
        $this->PisahkanPotonganPencairan($a, $saring, $dari, $sampai, $pencairan);
        $this->GolongkanPerAkunLawan($a, $perJurnal, $idKas, $idPajak, $sumberAkun);

        return $a;
    }

    /**
     * Belanja stok dipecah menjadi bahan baku dan dagangan menurut isi dokumen yang dibayar. Penerimaan barang tunai
     * memakai barisnya sendiri, pembayaran hutang dan faktur memakai komposisi fakturnya. Jumlahnya tetap sama dengan
     * uang yang keluar: bagian dagangan adalah sisa setelah bagian bahan baku dibulatkan. Pembatalan (uang kembali)
     * tidak dipecah.
     *
     * @param  list<string>  $sumberBelanja
     */
    private function PisahkanBelanja(AkumulatorUangKas $a, KueriDasar $perJurnal, array $sumberBelanja): void
    {
        $jurnal = DB::query()->fromSub($perJurnal, 't')
            ->join('Jurnal', 'Jurnal.Id', '=', 't.IdJurnal')
            ->whereIn('t.JenisSumber', $sumberBelanja)
            ->get(['t.IdOutlet', 't.Tgl', 't.JenisSumber', 't.N', 'Jurnal.IdSumber']);

        if ($jurnal->isEmpty()) {
            return;
        }

        $idPerJenis = [];

        foreach ($jurnal as $satu) {
            $idPerJenis[(string) $satu->JenisSumber][] = (int) $satu->IdSumber;
        }

        $unik = fn (JenisSumberJurnal $j): array => array_values(array_unique($idPerJenis[$j->value] ?? []));
        $porsi = [
            JenisSumberJurnal::PenerimaanBarang->value => $this->pemisahBelanja->PorsiPenerimaan($unik(JenisSumberJurnal::PenerimaanBarang)),
            JenisSumberJurnal::FakturPembelian->value => $this->pemisahBelanja->PorsiFaktur($unik(JenisSumberJurnal::FakturPembelian)),
            JenisSumberJurnal::PembayaranHutang->value => $this->pemisahBelanja->PorsiPembayaranHutang($unik(JenisSumberJurnal::PembayaranHutang)),
        ];

        foreach ($jurnal as $satu) {
            $bersih = Uang::Dari((string) $satu->N);
            $masuk = ! $bersih->BernilaiNegatif();
            $nilai = $masuk ? $bersih : Uang::Nol()->Kurangi($bersih);
            $idOutlet = $satu->IdOutlet === null ? null : (int) $satu->IdOutlet;
            $golongan = PenggolongUangKas::DariSumber(JenisSumberJurnal::tryFrom((string) $satu->JenisSumber), $masuk, (string) $satu->JenisSumber);

            if ($masuk) {
                $a->Tambah((string) $satu->Tgl, true, $golongan, $nilai, $idOutlet);

                continue;
            }

            $bahan = $nilai->Kali($porsi[(string) $satu->JenisSumber][(int) $satu->IdSumber] ?? '0');
            $a->Tambah((string) $satu->Tgl, false, PenggolongUangKas::Hasil($golongan['Label'], PenggolongUangKas::KELUAR_BELANJA_BAHAN), $bahan, $idOutlet);
            $a->Tambah((string) $satu->Tgl, false, $golongan, $nilai->Kurangi($bahan), $idOutlet);
        }
    }

    /**
     * Uang masuk dari penjualan dipisah dari biaya layanan (service charge) yang ikut tertagih. Dibatasi per jurnal
     * sebesar kas yang benar-benar diterima, jadi penjualan tempo atau non-tunai tidak membuat angka melebihi uangnya.
     *
     * @param  list<int>  $idKas
     * @param  list<int>  $idLayanan
     * @param  array<string, Uang>  $penjualanMasuk
     */
    private function PisahkanBiayaLayanan(AkumulatorUangKas $a, SaringLaporanKeuangan $saring, array $idKas, array $idLayanan, string $dari, string $sampai, array $penjualanMasuk): void
    {
        $layanan = [];

        if ($idLayanan !== [] && $penjualanMasuk !== []) {
            $kas = $this->KueriPerJurnal($saring, $idKas, $dari, $sampai, JenisSumberJurnal::Penjualan);
            $biaya = JurnalDetail::query()
                ->whereIn('IdAkun', $idLayanan)
                ->whereBetween('Tanggal', [$dari, $sampai])
                ->groupBy('IdJurnal')
                ->selectRaw('`IdJurnal`, CAST(SUM(`Kredit` - `Debit`) AS DECIMAL(20,2)) AS `S`')
                ->toBase();

            $hasil = DB::query()->fromSub($kas, 'c')
                ->leftJoinSub($biaya, 's', 's.IdJurnal', '=', 'c.IdJurnal')
                ->selectRaw('`c`.`Tgl` AS `Tgl`, `c`.`IdOutlet` AS `IdOutlet`, CAST(SUM(LEAST(GREATEST(`c`.`N`, 0), COALESCE(`s`.`S`, 0))) AS DECIMAL(20,2)) AS `Layanan`')
                ->groupBy('c.Tgl', 'c.IdOutlet')
                ->get();

            foreach ($hasil as $satu) {
                $layanan[(string) $satu->Tgl.'|'.($satu->IdOutlet ?? '')] = Uang::Dari((string) $satu->Layanan);
            }
        }

        foreach ($penjualanMasuk as $kunci => $nilai) {
            [$tanggal, $idOutlet] = self::PecahKunci($kunci);
            $biayaLayanan = $layanan[$kunci] ?? Uang::Nol();

            if ($biayaLayanan->Bandingkan($nilai) > 0) {
                $biayaLayanan = $nilai;
            }

            $a->Tambah($tanggal, true, PenggolongUangKas::DariSumber(JenisSumberJurnal::Penjualan, true), $nilai->Kurangi($biayaLayanan), $idOutlet);
            $a->Tambah($tanggal, true, PenggolongUangKas::Hasil('Biaya layanan (service charge)', PenggolongUangKas::MASUK_PENJUALAN), $biayaLayanan, $idOutlet);
        }
    }

    /**
     * Pencairan dana non-tunai tiba di bank sudah dipotong platform. Dirinci menjadi dana kotor yang cair (masuk) dan
     * potongan platform (keluar), selisihnya tetap sama dengan uang yang tiba di rekening.
     *
     * @param  array<string, Uang>  $pencairan  perubahan kas bersih per tanggal (bertanda)
     */
    private function PisahkanPotonganPencairan(AkumulatorUangKas $a, SaringLaporanKeuangan $saring, string $dari, string $sampai, array $pencairan): void
    {
        if ($pencairan === []) {
            return;
        }

        $kueri = JurnalDetail::query()
            ->join('Jurnal', fn ($j) => $j->on('Jurnal.Id', '=', 'JurnalDetail.IdJurnal')->on('Jurnal.IdTenant', '=', 'JurnalDetail.IdTenant'))
            ->join('Akun', fn ($j) => $j->on('Akun.Id', '=', 'JurnalDetail.IdAkun')->on('Akun.IdTenant', '=', 'JurnalDetail.IdTenant'))
            ->where('Jurnal.JenisSumber', JenisSumberJurnal::Pencairan->value)
            ->where('Akun.Jenis', TipeAkun::Beban->value)
            ->whereBetween('JurnalDetail.Tanggal', [$dari, $sampai]);
        $potongan = [];

        foreach ($saring->TerapkanOutlet($kueri, 'JurnalDetail.IdOutlet')
            ->groupByRaw('DATE(`JurnalDetail`.`Tanggal`), `JurnalDetail`.`IdOutlet`')
            ->selectRaw('DATE(`JurnalDetail`.`Tanggal`) AS `Tgl`, `JurnalDetail`.`IdOutlet` AS `IdOutlet`, CAST(SUM(`JurnalDetail`.`Debit` - `JurnalDetail`.`Kredit`) AS DECIMAL(20,2)) AS `Potongan`')
            ->toBase()
            ->get() as $satu) {
            $potongan[(string) $satu->Tgl.'|'.($satu->IdOutlet ?? '')] = Uang::Dari((string) $satu->Potongan);
        }

        foreach ($pencairan as $kunci => $bersih) {
            [$tanggal, $idOutlet] = self::PecahKunci($kunci);
            $potong = $potongan[$kunci] ?? Uang::Nol();

            if ($potong->BernilaiNegatif() || $potong->BernilaiNol()) {
                $a->TambahBertanda($tanggal, PenggolongUangKas::DariSumber(JenisSumberJurnal::Pencairan, true), $bersih, $idOutlet);

                continue;
            }

            $a->Tambah($tanggal, false, PenggolongUangKas::DariSumber(JenisSumberJurnal::Pencairan, false), $potong, $idOutlet);
            $a->TambahBertanda($tanggal, PenggolongUangKas::DariSumber(JenisSumberJurnal::Pencairan, true), $bersih->Tambah($potong), $idOutlet);
        }
    }

    /**
     * Jurnal kas & bank dan kas kasir digolongkan menurut akun lawannya (satu akun non-kas), misalnya Beban Sewa atau
     * Modal Pemilik. Jurnal tanpa akun lawan non-kas dianggap pindah uang antar akun, yang lebih dari satu akun lawan
     * digolongkan menurut jenis sumbernya.
     *
     * @param  list<int>  $idKas
     * @param  list<int>  $idPajak
     * @param  list<string>  $sumberAkun
     */
    private function GolongkanPerAkunLawan(AkumulatorUangKas $a, KueriDasar $perJurnal, array $idKas, array $idPajak, array $sumberAkun): void
    {
        $jurnal = DB::query()->fromSub($perJurnal, 't')->whereIn('t.JenisSumber', $sumberAkun)->get(['t.IdJurnal', 't.IdOutlet', 't.Tgl', 't.JenisSumber', 't.N']);

        if ($jurnal->isEmpty()) {
            return;
        }

        $lawan = collect();

        foreach ($jurnal->pluck('IdJurnal')->chunk(1000) as $potongan) {
            $lawan = $lawan->concat(JurnalDetail::query()
                ->whereIn('IdJurnal', $potongan->all())
                ->whereNotIn('IdAkun', $idKas)
                ->toBase()
                ->get(['IdJurnal', 'IdAkun']));
        }

        $lawan = $lawan->groupBy('IdJurnal');
        $idLaci = $this->IdAkunPeran(PeranAkun::KliringBelanjaLaci);
        /** @var array<int, array{Bahan: Uang, Dagangan: Uang}>|null $alokasiLaci */
        $alokasiLaci = null;
        $akun = Akun::query()->whereKey($lawan->flatten(1)->pluck('IdAkun')->unique()->values()->all())->get()->keyBy('Id');

        foreach ($jurnal as $satu) {
            $bersih = Uang::Dari((string) $satu->N);
            $masuk = ! $bersih->BernilaiNegatif();
            $nilai = $masuk ? $bersih : Uang::Nol()->Kurangi($bersih);
            $barisLawan = $lawan->get($satu->IdJurnal);
            $idLawan = $barisLawan === null ? collect() : $barisLawan->pluck('IdAkun')->unique()->values();
            $akunLawan = $idLawan->count() === 1 ? $akun->get($idLawan->first()) : null;

            if ($akunLawan instanceof Akun && in_array($akunLawan->Id, $idLaci, true)) {
                if ($masuk) {
                    $golongan = PenggolongUangKas::DariUangLaci(true);
                } else {
                    $alokasiLaci ??= $this->AlokasikanUangLaci($idLaci);
                    $this->TambahUangLaciKeluar($a, (string) $satu->Tgl, $satu->IdOutlet === null ? null : (int) $satu->IdOutlet, $nilai, $alokasiLaci[(int) $satu->IdJurnal] ?? null);

                    continue;
                }
            } elseif ($akunLawan instanceof Akun) {
                $golongan = PenggolongUangKas::DariAkun($akunLawan, $masuk, in_array($akunLawan->Id, $idPajak, true));
            } elseif ($idLawan->isEmpty()) {
                $golongan = PenggolongUangKas::Hasil($masuk ? 'Uang masuk dari akun kas lain' : 'Uang keluar ke akun kas lain', PenggolongUangKas::DI_LUAR_PINDAH);
            } else {
                $golongan = PenggolongUangKas::DariSumber(JenisSumberJurnal::tryFrom((string) $satu->JenisSumber), $masuk, (string) $satu->JenisSumber);
            }

            $a->Tambah((string) $satu->Tgl, $masuk, $golongan, $nilai, $satu->IdOutlet === null ? null : (int) $satu->IdOutlet);
        }
    }

    /**
     * Kas keluar kasir ke penampung uang laci dipecah menurut belanja stok yang sudah mencatat barangnya (D-257): bagian
     * bahan baku, bagian dagangan, dan sisanya "belum dirinci" (barangnya belum dicatat). Jumlahnya tetap sama dengan
     * uang yang keluar.
     *
     * @param  array{Bahan: Uang, Dagangan: Uang}|null  $alokasi
     */
    private function TambahUangLaciKeluar(AkumulatorUangKas $a, string $tanggal, ?int $idOutlet, Uang $nilai, ?array $alokasi): void
    {
        $bahan = $alokasi === null ? Uang::Nol() : ($alokasi['Bahan']->Bandingkan($nilai) > 0 ? $nilai : $alokasi['Bahan']);
        $sisa = $nilai->Kurangi($bahan);
        $dagangan = $alokasi === null ? Uang::Nol() : ($alokasi['Dagangan']->Bandingkan($sisa) > 0 ? $sisa : $alokasi['Dagangan']);
        $belumDirinci = $sisa->Kurangi($dagangan);
        foreach ([
            [$bahan, PenggolongUangKas::DariUangLaciTerpakai(true)],
            [$dagangan, PenggolongUangKas::DariUangLaciTerpakai(false)],
            [$belumDirinci, PenggolongUangKas::DariUangLaci(false)],
        ] as [$bagian, $golongan]) {
            if (! $bagian->BernilaiNol()) {
                $a->Tambah($tanggal, false, $golongan, $bagian, $idOutlet);
            }
        }
    }

    /**
     * Mencocokkan kas keluar kasir ke penampung uang laci dengan belanja stok yang dibayar dari penampung itu, urut
     * tanggal per outlet (yang lebih dulu keluar, lebih dulu dipakai). Belanja stok mengambil bahan bakunya dulu lalu
     * dagangannya dari ember kas keluar. Hasilnya per jurnal kas keluar. Dihitung dari seluruh riwayat, bukan hanya
     * periode laporan, supaya kas keluar bulan lalu yang barangnya baru dicatat bulan ini tetap terpecah benar.
     *
     * @param  list<int>  $idLaci
     * @return array<int, array{Bahan: Uang, Dagangan: Uang}> Id jurnal kas keluar => bagian terpakai
     */
    private function AlokasikanUangLaci(array $idLaci): array
    {
        $rincian = $this->pemisahBelanja->RincianBelanjaDariAkun($idLaci);

        if ($rincian === [] || $idLaci === []) {
            return [];
        }

        /** @var array<string, list<array{0: int, 1: Uang}>> $ember kunci outlet => [IdJurnal, sisa] */
        $ember = [];

        foreach (JurnalDetail::query()
            ->whereIn('IdAkun', $idLaci)
            ->where('Debit', '>', 0)
            ->whereIn('IdJurnal', Jurnal::query()->where('JenisSumber', JenisSumberJurnal::MutasiKas->value)->select('Id'))
            ->orderBy('Tanggal')
            ->orderBy('IdJurnal')
            ->toBase()
            ->get(['IdJurnal', 'IdOutlet', 'Debit']) as $baris) {
            $ember[(string) $baris->IdOutlet][] = [(int) $baris->IdJurnal, Uang::Dari((string) $baris->Debit)];
        }

        $hasil = [];

        foreach ($rincian as $belanja) {
            $kunci = (string) $belanja['IdOutlet'];
            $butuhBahan = $belanja['Bahan'];
            $butuhDagangan = $belanja['Total']->Kurangi($belanja['Bahan']);

            foreach ($ember[$kunci] ?? [] as $i => [$idJurnal, $sisa]) {
                if ($butuhBahan->BernilaiNol() && $butuhDagangan->BernilaiNol()) {
                    break;
                }

                $ambilBahan = $butuhBahan->Bandingkan($sisa) > 0 ? $sisa : $butuhBahan;
                $sisa = $sisa->Kurangi($ambilBahan);
                $ambilDagangan = $butuhDagangan->Bandingkan($sisa) > 0 ? $sisa : $butuhDagangan;
                $sisa = $sisa->Kurangi($ambilDagangan);
                $butuhBahan = $butuhBahan->Kurangi($ambilBahan);
                $butuhDagangan = $butuhDagangan->Kurangi($ambilDagangan);
                $ember[$kunci][$i] = [$idJurnal, $sisa];
                $hasil[$idJurnal] = [
                    'Bahan' => ($hasil[$idJurnal]['Bahan'] ?? Uang::Nol())->Tambah($ambilBahan),
                    'Dagangan' => ($hasil[$idJurnal]['Dagangan'] ?? Uang::Nol())->Tambah($ambilDagangan),
                ];
            }
        }

        return $hasil;
    }

    /**
     * Perubahan kas bersih tiap jurnal pada rentang tanggal (hanya yang tidak nol), dengan tanggal dan jenis sumbernya.
     *
     * @param  list<int>  $idKas
     */
    private function KueriPerJurnal(SaringLaporanKeuangan $saring, array $idKas, string $dari, string $sampai, ?JenisSumberJurnal $hanya): KueriDasar
    {
        $kueri = JurnalDetail::query()
            ->join('Jurnal', fn ($j) => $j->on('Jurnal.Id', '=', 'JurnalDetail.IdJurnal')->on('Jurnal.IdTenant', '=', 'JurnalDetail.IdTenant'))
            ->whereIn('JurnalDetail.IdAkun', $idKas)
            ->whereBetween('JurnalDetail.Tanggal', [$dari, $sampai]);

        if ($hanya !== null) {
            $kueri->where('Jurnal.JenisSumber', $hanya->value);
        }

        return $saring->TerapkanOutlet($kueri, 'JurnalDetail.IdOutlet')
            ->groupBy('JurnalDetail.IdJurnal', 'JurnalDetail.Tanggal', 'JurnalDetail.IdOutlet', 'Jurnal.JenisSumber')
            ->selectRaw('`JurnalDetail`.`IdJurnal` AS `IdJurnal`, `JurnalDetail`.`IdOutlet` AS `IdOutlet`, DATE(`JurnalDetail`.`Tanggal`) AS `Tgl`, `Jurnal`.`JenisSumber` AS `JenisSumber`, CAST(SUM(`JurnalDetail`.`Debit` - `JurnalDetail`.`Kredit`) AS DECIMAL(20,2)) AS `N`')
            ->havingRaw('SUM(`JurnalDetail`.`Debit` - `JurnalDetail`.`Kredit`) <> 0')
            ->toBase();
    }

    /**
     * @param  list<int>  $idKas
     */
    private function JumlahkanKas(SaringLaporanKeuangan $saring, array $idKas, string $dari, string $sampai): Uang
    {
        $kueri = JurnalDetail::query()->whereIn('IdAkun', $idKas)->whereBetween('Tanggal', [$dari, $sampai]);
        $nilai = $saring->TerapkanOutlet($kueri)
            ->selectRaw('CAST(COALESCE(SUM(`Debit` - `Kredit`), 0) AS DECIMAL(20,2)) AS `Nilai`')
            ->toBase()
            ->value('Nilai');

        return Uang::Dari((string) ($nilai ?? '0'));
    }

    /**
     * Saldo akun sampai tanggal: debit − kredit, atau kredit − debit bila `$kredit`.
     *
     * @param  list<int>  $idAkun
     */
    private function Saldo(SaringLaporanKeuangan $saring, array $idAkun, string $sampai, bool $kredit): Uang
    {
        if ($idAkun === []) {
            return Uang::Nol();
        }

        /** @var Builder<JurnalDetail> $kueri */
        $kueri = JurnalDetail::query()->whereIn('IdAkun', $idAkun)->where('Tanggal', '<=', $sampai);
        $nilai = $saring->TerapkanOutlet($kueri)
            ->selectRaw('CAST(COALESCE(SUM(`Debit` - `Kredit`), 0) AS DECIMAL(20,2)) AS `Nilai`')
            ->toBase()
            ->value('Nilai');
        $saldo = Uang::Dari((string) ($nilai ?? '0'));

        return $kredit ? Uang::Nol()->Kurangi($saldo) : $saldo;
    }

    /**
     * Id akun yang dipetakan ke peran (termasuk kunci lama), semua outlet.
     *
     * @return list<int>
     */
    private function IdAkunPeran(PeranAkun ...$peran): array
    {
        $kunci = [];

        foreach ($peran as $satu) {
            $kunci[] = $satu->value;
            array_push($kunci, ...array_keys(array_filter(PeranAkun::KUNCI_LAMA, fn (string $baru): bool => $baru === $satu->value)));
        }

        return array_values(array_unique(PemetaanAkun::query()->whereIn('Kunci', $kunci)->pluck('IdAkun')->map(fn ($id): int => (int) $id)->all()));
    }

    /**
     * @param  array<string, string>  $catatan
     * @return array<string, mixed>
     */
    private function Susun(SaringLaporanKeuangan $saring, string $dariLalu, string $sampaiLalu, string $jenisPembanding, AkumulatorUangKas $kini, AkumulatorUangKas $lalu, Uang $kenaikan, array $catatan, bool $adaAkunKas): array
    {
        $masuk = $kini->TotalMasuk();
        $keluar = $kini->TotalKeluar();
        $selisih = $masuk->Kurangi($keluar);
        $masukLalu = $lalu->TotalMasuk();
        $keluarLalu = $lalu->TotalKeluar();
        $selisihLalu = $masukLalu->Kurangi($keluarLalu);
        $diLuar = $kini->TotalDiLuar();
        $selisihKas = $selisih->Tambah($diLuar)->Kurangi($kenaikan);

        return [
            'Periode' => [
                'Dari' => $saring->dari,
                'Sampai' => $saring->sampai,
                'DariSebelumnya' => $dariLalu,
                'SampaiSebelumnya' => $sampaiLalu,
                'Pembanding' => $jenisPembanding,
                'Hari' => (int) CarbonImmutable::parse($saring->dari)->diffInDays(CarbonImmutable::parse($saring->sampai)) + 1,
            ],
            'Ringkasan' => [
                'Masuk' => $masuk->KeString(),
                'Keluar' => $keluar->KeString(),
                'Selisih' => $selisih->KeString(),
                'MasukSebelumnya' => $masukLalu->KeString(),
                'KeluarSebelumnya' => $keluarLalu->KeString(),
                'SelisihSebelumnya' => $selisihLalu->KeString(),
                'PerubahanMasuk' => self::PerubahanPersen($masuk, $masukLalu),
                'PerubahanKeluar' => self::PerubahanPersen($keluar, $keluarLalu),
                'PerubahanSelisih' => self::PerubahanPersen($selisih, $selisihLalu),
                'DiLuarUsaha' => $diLuar->KeString(),
                'PerubahanKas' => $kenaikan->KeString(),
            ],
            'Masuk' => $this->Gabung($kini->masuk, $lalu->masuk, $masuk, PenggolongUangKas::URUT_MASUK),
            'Keluar' => $this->Gabung($kini->keluar, $lalu->keluar, $keluar, PenggolongUangKas::URUT_KELUAR),
            'KelompokMasuk' => $this->Kelompokkan($kini->masuk, $lalu->masuk, $masuk, PenggolongUangKas::URUT_MASUK),
            'KelompokKeluar' => $this->Kelompokkan($kini->keluar, $lalu->keluar, $keluar, PenggolongUangKas::URUT_KELUAR),
            'DiLuarUsahaRincian' => array_values(array_map(
                fn (array $b): array => ['Label' => $b['Label'], 'Kelompok' => $b['Kelompok'], 'Nilai' => $b['Nilai']->KeString()],
                array_filter($kini->diLuar, fn (array $b): bool => ! $b['Nilai']->BernilaiNol()),
            )),
            'Grafik' => $this->SusunGrafik($saring, $kini),
            'Catatan' => $catatan === [] ? ['Hutang' => '0.00', 'Piutang' => '0.00', 'MenungguCair' => '0.00'] : $catatan,
            'BelumDikelompokkan' => $kini->TotalBelumDikelompokkan()->KeString(),
            'Kecocokan' => ['Cocok' => $selisihKas->BernilaiNol(), 'Selisih' => $selisihKas->KeString()],
            'AdaAkunKas' => $adaAkunKas,
        ];
    }

    /**
     * Baris per label, periode ini dan sebelumnya, diurut menurut kelompok lalu nilai terbesar.
     *
     * @param  array<string, array{Label: string, Kelompok: string, Nilai: Uang}>  $kini
     * @param  array<string, array{Label: string, Kelompok: string, Nilai: Uang}>  $lalu
     * @param  list<string>  $urutKelompok
     * @return list<array<string, string|null>>
     */
    private function Gabung(array $kini, array $lalu, Uang $total, array $urutKelompok): array
    {
        $baris = [];

        foreach (array_unique([...array_keys($kini), ...array_keys($lalu)]) as $kunci) {
            $nilai = $kini[$kunci]['Nilai'] ?? Uang::Nol();
            $nilaiLalu = $lalu[$kunci]['Nilai'] ?? Uang::Nol();

            if ($nilai->BernilaiNol() && $nilaiLalu->BernilaiNol()) {
                continue;
            }

            $sumber = $kini[$kunci] ?? $lalu[$kunci];
            $baris[] = [
                'Label' => $sumber['Label'],
                'Kelompok' => $sumber['Kelompok'],
                'Nilai' => $nilai->KeString(),
                'NilaiSebelumnya' => $nilaiLalu->KeString(),
                'Porsi' => self::Persen($nilai, $total),
            ];
        }

        usort($baris, function (array $a, array $b) use ($urutKelompok): int {
            $urut = array_search($a['Kelompok'], $urutKelompok, true) <=> array_search($b['Kelompok'], $urutKelompok, true);

            return $urut !== 0 ? $urut : BigDecimal::of((string) $b['Nilai'])->compareTo((string) $a['Nilai']);
        });

        return $baris;
    }

    /**
     * Total per kelompok (Penjualan, Belanja bahan baku, Belanja barang dagangan, …) untuk dua panel utama.
     *
     * @param  array<string, array{Label: string, Kelompok: string, Nilai: Uang}>  $kini
     * @param  array<string, array{Label: string, Kelompok: string, Nilai: Uang}>  $lalu
     * @param  list<string>  $urutKelompok
     * @return list<array<string, string|null>>
     */
    private function Kelompokkan(array $kini, array $lalu, Uang $total, array $urutKelompok): array
    {
        $hasil = [];

        foreach ($urutKelompok as $kelompok) {
            $nilai = Uang::Nol();
            $nilaiLalu = Uang::Nol();

            foreach ($kini as $b) {
                if ($b['Kelompok'] === $kelompok) {
                    $nilai = $nilai->Tambah($b['Nilai']);
                }
            }

            foreach ($lalu as $b) {
                if ($b['Kelompok'] === $kelompok) {
                    $nilaiLalu = $nilaiLalu->Tambah($b['Nilai']);
                }
            }

            if ($nilai->BernilaiNol() && $nilaiLalu->BernilaiNol()) {
                continue;
            }

            $hasil[] = ['Kelompok' => $kelompok, 'Nilai' => $nilai->KeString(), 'NilaiSebelumnya' => $nilaiLalu->KeString(), 'Porsi' => self::Persen($nilai, $total)];
        }

        return $hasil;
    }

    /**
     * Uang masuk dan keluar per hari, atau per bulan bila rentangnya panjang. Hari atau bulan tanpa uang tetap tampil
     * bernilai nol supaya grafiknya tidak melompat.
     *
     * @return array{Satuan: string, Titik: list<array{Kunci: string, Masuk: string, Keluar: string}>}
     */
    private function SusunGrafik(SaringLaporanKeuangan $saring, AkumulatorUangKas $kini): array
    {
        $dari = CarbonImmutable::parse($saring->dari);
        $sampai = CarbonImmutable::parse($saring->sampai);
        $harian = $dari->diffInDays($sampai) < self::MAKS_HARI_GRAFIK_HARIAN;
        /** @var array<string, array{Masuk: Uang, Keluar: Uang}> $titik */
        $titik = [];

        for ($hari = $dari; $hari->lte($sampai); $hari = $harian ? $hari->addDay() : $hari->addMonthNoOverflow()->startOfMonth()) {
            $titik[$harian ? $hari->toDateString() : $hari->format('Y-m')] = ['Masuk' => Uang::Nol(), 'Keluar' => Uang::Nol()];
        }

        foreach ($kini->perHari as $tanggal => $nilai) {
            $kunci = $harian ? $tanggal : substr($tanggal, 0, 7);

            if (isset($titik[$kunci])) {
                $titik[$kunci] = ['Masuk' => $titik[$kunci]['Masuk']->Tambah($nilai['Masuk']), 'Keluar' => $titik[$kunci]['Keluar']->Tambah($nilai['Keluar'])];
            }
        }

        $hasil = [];

        foreach ($titik as $kunci => $nilai) {
            $hasil[] = ['Kunci' => (string) $kunci, 'Masuk' => $nilai['Masuk']->KeString(), 'Keluar' => $nilai['Keluar']->KeString()];
        }

        return ['Satuan' => $harian ? 'Hari' : 'Bulan', 'Titik' => $hasil];
    }

    /**
     * Kunci `tanggal|idOutlet` (outlet kosong = tanpa outlet) menjadi pasangan tanggal dan id outlet.
     *
     * @return array{0: string, 1: int|null}
     */
    private static function PecahKunci(string $kunci): array
    {
        [$tanggal, $outlet] = explode('|', $kunci, 2) + ['', ''];

        return [$tanggal, $outlet === '' ? null : (int) $outlet];
    }

    /** Porsi `bagian` dari `total` dalam persen satu desimal; null bila total nol. */
    private static function Persen(Uang $bagian, Uang $total): ?string
    {
        if ($total->BernilaiNol()) {
            return null;
        }

        return (string) BigDecimal::of($bagian->KeString())->multipliedBy(100)->dividedBy($total->KeString(), 1, RoundingMode::HalfUp);
    }

    /** Perubahan `kini` terhadap `lalu` dalam persen satu desimal; null bila `lalu` nol. */
    private static function PerubahanPersen(Uang $kini, Uang $lalu): ?string
    {
        if ($lalu->BernilaiNol()) {
            return null;
        }

        return (string) BigDecimal::of($kini->Kurangi($lalu)->KeString())->multipliedBy(100)->dividedBy(BigDecimal::of($lalu->KeString())->abs(), 1, RoundingMode::HalfUp);
    }
}
