<?php

declare(strict_types=1);

namespace App\Domain\Karyawan\Aksi;

use App\Domain\Akuntansi\Aksi\PostingJurnal;
use App\Domain\Akuntansi\Data\DataBarisJurnal;
use App\Domain\Akuntansi\Data\DataJurnal;
use App\Domain\Akuntansi\Enum\JenisSumberJurnal;
use App\Domain\Akuntansi\Enum\PeranAkun;
use App\Domain\Akuntansi\Enum\TipeAkun;
use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Bersama\Audit\Layanan\PencatatAudit;
use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tenant\KonteksTenant;
use App\Domain\Karyawan\Enum\DasarGaji;
use App\Domain\Karyawan\Enum\SiklusGaji;
use App\Domain\Karyawan\Enum\StatusKaryawan;
use App\Domain\Karyawan\Enum\StatusRekapGaji;
use App\Domain\Karyawan\Kueri\DaftarKasbon;
use App\Domain\Karyawan\Kueri\LaporanKomisi;
use App\Domain\Karyawan\Kueri\RekapKehadiranPeriode;
use App\Domain\Karyawan\Model\Karyawan;
use App\Domain\Karyawan\Model\RekapGaji;
use App\Domain\Karyawan\Model\RekapGajiBaris;
use App\Domain\Tenant\Kueri\ProfilTenant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * F-18 bagian 3 rekap gaji (EMP-06), bulanan atau mingguan (D-219):
 * - **Buat** draf `Bulanan` periode `YYYY-MM` (tidak setelah bulan berjalan, satu per periode) atau `Mingguan` rentang
 *   tujuh hari sejak tanggal mulai `YYYY-MM-DD` (tidak setelah hari ini, tidak boleh beririsan dengan rekap mingguan
 *   lain): baris untuk karyawan aktif **bersiklus sama** yang punya gaji pokok atau komisi di rentang itu. Karyawan
 *   berdasar `PerHariMasuk` digaji upah per hari × hari masuk (absensi + cuti berbayar berjadwal, bisa diubah selama
 *   draf) dan tidak kena potongan tidak masuk. Komisi = komisi bersih (komisi − dibatalkan) tanggal bisnis periode.
 *   Potongan kasbon awal = min(sisa kasbon aktif, gaji kotor). Audit `rekap-gaji.buat`.
 * - **Ubah baris** (draf): tambahan (lembur/tunjangan), potongan kasbon (≤ sisa kasbon aktif), potongan lain, catatan;
 *   gaji bersih tidak boleh minus. Audit `rekap-gaji.ubah`.
 * - **Hapus** draf. Audit `rekap-gaji.hapus`.
 * - **Bayar**: satu jurnal seimbang per outlet utama karyawan: Dr akun beban (bawaan 6-1000 Beban Gaji & Komisi) Σ
 *   kotor, Cr Piutang Karyawan Σ potongan kasbon, Cr Pendapatan Lain Σ potongan lain, Cr kas/bank Σ bersih. Potongan
 *   kasbon dicatat sebagai pelunasan kasbon terlama dulu. Status Dibayar (append-only). Audit `rekap-gaji.bayar`.
 * - **Lembur & potongan kehadiran (F-18 bagian 5, D-44):** saat draf dibuat, `RekapKehadiranPeriode` menghitung menit
 *   lembur, menit terlambat, dan hari tidak masuk dari absensi vs jadwal; dikalikan tarif di `Karyawan` (lembur per jam,
 *   potongan per menit terlambat, potongan per hari tidak masuk; tarif kosong = tidak dihitung). Lembur menambah gaji
 *   kotor; potongan kehadiran dibatasi sampai gaji kotor dan, seperti potongan lain, dikreditkan ke Pendapatan Lain di
 *   J-18.2. Semua nilai bisa disesuaikan pengelola selama draf.
 * Komisi yang dibatalkan setelah gaji dibayar (retur bulan berikutnya) tidak dipotong otomatis.
 */
final class KelolaRekapGaji
{
    public function __construct(
        private readonly KonteksTenant $konteks,
        private readonly ProfilTenant $profil,
        private readonly LaporanKomisi $komisi,
        private readonly RekapKehadiranPeriode $kehadiran,
        private readonly DaftarKasbon $kasbon,
        private readonly KelolaKasbon $kelolaKasbon,
        private readonly DaftarAkunPilihan $akun,
        private readonly PostingJurnal $posting,
        private readonly PencatatAudit $audit,
    ) {}

    public function Buat(string $periode, int $idPengguna, SiklusGaji $siklus = SiklusGaji::Bulanan): RekapGaji
    {
        [$awal, $akhir] = $this->TentukanRentang($periode, $siklus);

        return DB::transaction(function () use ($periode, $awal, $akhir, $idPengguna, $siklus): RekapGaji {
            if (RekapGaji::query()->where('Periode', $periode)->lockForUpdate()->exists()) {
                throw new PelanggaranAturanBisnis('RekapSudahAda', "Rekap gaji {$periode} sudah dibuat.", 'Periode');
            }

            if ($siklus === SiklusGaji::Mingguan) {
                $bertabrakan = RekapGaji::query()->where('Siklus', SiklusGaji::Mingguan->value)
                    ->where('TanggalMulai', '<=', $akhir->toDateString())->where('TanggalSelesai', '>=', $awal->toDateString())
                    ->lockForUpdate()->first();

                if ($bertabrakan !== null) {
                    throw new PelanggaranAturanBisnis('RentangBertabrakan', 'Rentang ini beririsan dengan rekap gaji mingguan '.$bertabrakan->AmbilLabel().'. Pilih tanggal mulai setelah rekap itu.', 'Periode');
                }
            }

            $komisi = $this->komisi->AmbilBersihPerKaryawan($awal->toDateString(), $akhir->toDateString());
            $karyawan = Karyawan::query()
                ->where('SiklusGaji', $siklus->value)
                ->where(fn ($k) => $k->where('Status', StatusKaryawan::Aktif->value)->orWhereIn('Id', array_keys($komisi)))
                ->orderBy('Nama')
                ->get();
            $hadir = $this->kehadiran->Hitung($awal->toDateString(), $akhir->toDateString(), $this->HariIni());
            $hariMasuk = $karyawan->contains(fn (Karyawan $k): bool => $k->DasarGaji === DasarGaji::PerHariMasuk)
                ? $this->kehadiran->HitungHariMasuk($awal->toDateString(), $akhir->toDateString())
                : [];
            $jumlahHari = (int) $awal->diffInDays($akhir) + 1;
            $sisaKasbon = $this->HitungSisaKasbon(array_values(array_map('intval', $karyawan->pluck('Id')->all())));
            $rekap = RekapGaji::query()->create([
                'Periode' => $periode,
                'Siklus' => $siklus,
                'TanggalMulai' => $awal->toDateString(),
                'TanggalSelesai' => $akhir->toDateString(),
                'Status' => StatusRekapGaji::Draf,
                'DibuatOleh' => $idPengguna,
            ]);

            foreach ($karyawan as $k) {
                $pokok = Uang::Dari($k->GajiPokok ?? '0');
                $upahHarian = null;
                $hari = null;

                if ($k->DasarGaji === DasarGaji::PerHariMasuk) {
                    $upahHarian = $pokok;
                    $hari = min($hariMasuk[$k->Id] ?? 0, $jumlahHari);
                    $pokok = $upahHarian->Kali($hari);
                }

                $nilaiKomisi = Uang::Dari($komisi[$k->Id] ?? '0');
                $h = $hadir[$k->Id] ?? ['TerlambatMenit' => 0, 'LemburMenit' => 0, 'HariTidakMasuk' => 0];
                $lembur = $k->TarifLemburPerJam === null ? Uang::Nol() : self::HitungPerJam($k->TarifLemburPerJam, $h['LemburMenit']);

                // Berupah harian tetap masuk rekap walau hari masuknya 0 (belum ada absensi): pengelola mengisi hari masuk selama draf.
                if ($pokok->BernilaiNol() && $nilaiKomisi->BernilaiNol() && $lembur->BernilaiNol() && ($upahHarian === null || $upahHarian->BernilaiNol())) {
                    continue;
                }

                $kotor = $pokok->Tambah($nilaiKomisi)->Tambah($lembur);
                $potTerlambat = $k->PotonganTerlambatPerMenit === null ? Uang::Nol() : Uang::Dari($k->PotonganTerlambatPerMenit)->Kali($h['TerlambatMenit']);
                $potTidakMasuk = $k->PotonganTidakMasukPerHari === null || $upahHarian !== null ? Uang::Nol() : Uang::Dari($k->PotonganTidakMasukPerHari)->Kali($h['HariTidakMasuk']);
                // Potongan kehadiran tidak boleh membuat gaji minus: terlambat dulu, lalu tidak masuk, sampai sebatas gaji kotor.
                $potTerlambat = $potTerlambat->Bandingkan($kotor) > 0 ? $kotor : $potTerlambat;
                $potTidakMasuk = $potTerlambat->Tambah($potTidakMasuk)->Bandingkan($kotor) > 0 ? $kotor->Kurangi($potTerlambat) : $potTidakMasuk;
                $sisaKotor = $kotor->Kurangi($potTerlambat)->Kurangi($potTidakMasuk);
                $sisa = $sisaKasbon[$k->Id] ?? Uang::Nol();
                $potong = $sisa->Bandingkan($sisaKotor) > 0 ? $sisaKotor : $sisa;
                RekapGajiBaris::query()->create([
                    'IdRekapGaji' => $rekap->Id,
                    'IdKaryawan' => $k->Id,
                    'GajiPokok' => $pokok->KeString(),
                    'UpahHarian' => $upahHarian?->KeString(),
                    'HariMasuk' => $hari,
                    'Komisi' => $nilaiKomisi->KeString(),
                    'Tambahan' => '0.00',
                    'LemburMenit' => $h['LemburMenit'],
                    'Lembur' => $lembur->KeString(),
                    'TerlambatMenit' => $h['TerlambatMenit'],
                    'PotonganTerlambat' => $potTerlambat->KeString(),
                    'HariTidakMasuk' => $h['HariTidakMasuk'],
                    'PotonganTidakMasuk' => $potTidakMasuk->KeString(),
                    'PotonganKasbon' => $potong->KeString(),
                    'PotonganLain' => '0.00',
                    'Bersih' => $sisaKotor->Kurangi($potong)->KeString(),
                ]);
            }

            $this->HitungTotal($rekap);
            $this->audit->Catat('rekap-gaji.buat', $rekap, nilaiBaru: ['Periode' => $periode, 'Siklus' => $siklus->value]);

            return $rekap;
        });
    }

    public function UbahBaris(RekapGaji $rekap, string $uuidKaryawan, Uang $tambahan, Uang $potonganKasbon, Uang $potonganLain, ?string $catatan, ?Uang $lembur = null, ?Uang $potonganTerlambat = null, ?Uang $potonganTidakMasuk = null, ?int $hariMasuk = null): RekapGajiBaris
    {
        foreach (['Tambahan' => $tambahan, 'PotonganKasbon' => $potonganKasbon, 'PotonganLain' => $potonganLain, 'Lembur' => $lembur, 'PotonganTerlambat' => $potonganTerlambat, 'PotonganTidakMasuk' => $potonganTidakMasuk] as $bidang => $nilai) {
            if ($nilai?->BernilaiNegatif() === true) {
                throw new PelanggaranAturanBisnis('JumlahTidakValid', 'Jumlah tidak boleh minus.', $bidang);
            }
        }

        return DB::transaction(function () use ($rekap, $uuidKaryawan, $tambahan, $potonganKasbon, $potonganLain, $catatan, $lembur, $potonganTerlambat, $potonganTidakMasuk, $hariMasuk): RekapGajiBaris {
            $rekap = $this->KunciDraf($rekap);
            $idKaryawan = Karyawan::query()->where('Uuid', $uuidKaryawan)->value('Id');
            $baris = $idKaryawan === null ? null : RekapGajiBaris::query()->where('IdRekapGaji', $rekap->Id)->where('IdKaryawan', $idKaryawan)->first();

            if ($baris === null) {
                throw new PelanggaranAturanBisnis('BarisTidakDikenal', 'Karyawan ini tidak ada di rekap gaji.');
            }

            $sisa = $this->HitungSisaKasbon([$baris->IdKaryawan])[$baris->IdKaryawan] ?? Uang::Nol();

            if ($potonganKasbon->Bandingkan($sisa) > 0) {
                throw new PelanggaranAturanBisnis('MelebihiSisaKasbon', 'Potongan kasbon melebihi sisa kasbon '.$sisa->FormatRupiah().'.', 'PotonganKasbon');
            }

            $kolomAudit = ['HariMasuk', 'Tambahan', 'Lembur', 'PotonganKasbon', 'PotonganLain', 'PotonganTerlambat', 'PotonganTidakMasuk', 'Catatan'];
            $lama = $baris->only($kolomAudit);
            $baris->fill([
                'Tambahan' => $tambahan->KeString(),
                'Lembur' => ($lembur ?? Uang::Dari($baris->Lembur))->KeString(),
                'PotonganKasbon' => $potonganKasbon->KeString(),
                'PotonganLain' => $potonganLain->KeString(),
                'PotonganTerlambat' => ($potonganTerlambat ?? Uang::Dari($baris->PotonganTerlambat))->KeString(),
                'PotonganTidakMasuk' => ($potonganTidakMasuk ?? Uang::Dari($baris->PotonganTidakMasuk))->KeString(),
                'Catatan' => $catatan,
            ]);

            if ($hariMasuk !== null) {
                if ($baris->UpahHarian === null) {
                    throw new PelanggaranAturanBisnis('BukanUpahHarian', 'Karyawan ini digaji tetap, jadi tidak punya hari masuk.', 'HariMasuk');
                }

                if ($hariMasuk < 0 || $hariMasuk > $rekap->HitungJumlahHari()) {
                    throw new PelanggaranAturanBisnis('HariMasukTidakValid', 'Hari masuk harus antara 0 dan '.$rekap->HitungJumlahHari().'.', 'HariMasuk');
                }

                $baris->HariMasuk = $hariMasuk;
                $baris->GajiPokok = Uang::Dari($baris->UpahHarian)->Kali($hariMasuk)->KeString();
            }

            $bersih = $baris->HitungKotor()->Kurangi($baris->HitungPotongan());

            if ($bersih->BernilaiNegatif()) {
                throw new PelanggaranAturanBisnis('GajiBersihMinus', 'Potongan melebihi gaji kotor. Kurangi potongan.', 'PotonganLain');
            }

            $baris->Bersih = $bersih->KeString();
            $baris->save();
            $this->HitungTotal($rekap);
            $this->audit->Catat('rekap-gaji.ubah', $rekap, nilaiLama: $lama, nilaiBaru: $baris->only($kolomAudit));

            return $baris;
        });
    }

    public const MAKS_TAMBAHAN_MASSAL = 500;

    private const MAKS_HARI_MUNDUR_MINGGUAN = 366;

    /**
     * Aksi massal draf rekap (audit kemudahan pakai): menambah `Tambahan` (bonus, THR, tunjangan) pada karyawan
     * terpilih dengan jumlah yang sama. Tiap baris lewat [UbahBaris] sehingga validasi (gaji bersih tidak minus) dan
     * audit per baris sama. Semua atau tidak sama sekali: satu karyawan gagal membatalkan seluruhnya. Catatan, bila
     * diisi, menggantikan catatan baris.
     *
     * @param  list<string>  $uuidKaryawan
     * @return int jumlah baris yang diubah
     *
     * @throws PelanggaranAturanBisnis PilihanKosong, TerlaluBanyak, JumlahTidakValid, BarisTidakDikenal, GajiBersihMinus, RekapSudahDibayar
     */
    public function TambahanMassal(RekapGaji $rekap, array $uuidKaryawan, Uang $tambahan, ?string $catatan): int
    {
        $uuidKaryawan = array_values(array_unique($uuidKaryawan));

        if ($uuidKaryawan === []) {
            throw new PelanggaranAturanBisnis('PilihanKosong', 'Pilih minimal satu karyawan.', 'Uuid');
        }

        if (count($uuidKaryawan) > self::MAKS_TAMBAHAN_MASSAL) {
            throw new PelanggaranAturanBisnis('TerlaluBanyak', 'Maksimal '.self::MAKS_TAMBAHAN_MASSAL.' karyawan sekali proses.', 'Uuid');
        }

        if ($tambahan->BernilaiNegatif() || $tambahan->BernilaiNol()) {
            throw new PelanggaranAturanBisnis('JumlahTidakValid', 'Isi tambahan lebih dari 0.', 'Tambahan');
        }

        return DB::transaction(function () use ($rekap, $uuidKaryawan, $tambahan, $catatan): int {
            $rekap = $this->KunciDraf($rekap);
            $idKaryawan = Karyawan::query()->whereIn('Uuid', $uuidKaryawan)->pluck('Id', 'Uuid');
            $baris = RekapGajiBaris::query()->where('IdRekapGaji', $rekap->Id)->whereIn('IdKaryawan', $idKaryawan->values())->get()->keyBy('IdKaryawan');

            foreach ($uuidKaryawan as $uuid) {
                $b = $baris->get($idKaryawan->get($uuid));

                if ($b === null) {
                    throw new PelanggaranAturanBisnis('BarisTidakDikenal', 'Sebagian karyawan tidak ada di rekap gaji. Muat ulang halaman.', 'Uuid');
                }

                $this->UbahBaris(
                    $rekap,
                    $uuid,
                    Uang::Dari($b->Tambahan)->Tambah($tambahan),
                    Uang::Dari($b->PotonganKasbon),
                    Uang::Dari($b->PotonganLain),
                    $catatan ?? $b->Catatan,
                );
            }

            return count($uuidKaryawan);
        });
    }

    public function Hapus(RekapGaji $rekap): void
    {
        DB::transaction(function () use ($rekap): void {
            $rekap = $this->KunciDraf($rekap);
            RekapGajiBaris::query()->where('IdRekapGaji', $rekap->Id)->delete();
            $this->audit->Catat('rekap-gaji.hapus', $rekap, nilaiLama: ['Periode' => $rekap->Periode, 'TotalBersih' => $rekap->TotalBersih]);
            $rekap->delete();
        });
    }

    public function Bayar(RekapGaji $rekap, CarbonImmutable $tanggal, string $uuidAkunKasBank, string $uuidAkunBeban, int $idPengguna): RekapGaji
    {
        if ($tanggal->toDateString() > $this->HariIni()->toDateString()) {
            throw new PelanggaranAturanBisnis('TanggalDiMasaDepan', 'Tanggal bayar tidak boleh setelah hari ini.', 'Tanggal');
        }

        $akunKas = $this->akun->CariKasBankDariUuid($uuidAkunKasBank)['Id'] ?? throw new PelanggaranAturanBisnis('AkunKasBankWajib', 'Pilih akun kas atau bank.', 'AkunKasBank');
        $akunBeban = $this->akun->CariDariUuid($uuidAkunBeban, [TipeAkun::Beban])['Id'] ?? throw new PelanggaranAturanBisnis('AkunBebanWajib', 'Pilih akun beban gaji.', 'AkunBeban');

        return DB::transaction(function () use ($rekap, $tanggal, $akunKas, $akunBeban, $idPengguna): RekapGaji {
            $rekap = $this->KunciDraf($rekap);
            $baris = RekapGajiBaris::query()->where('IdRekapGaji', $rekap->Id)->get();

            if ($baris->isEmpty() || Uang::Dari($rekap->TotalKotor)->BernilaiNol()) {
                throw new PelanggaranAturanBisnis('RekapKosong', 'Rekap gaji ini belum berisi gaji untuk dibayar.');
            }

            $outlet = Karyawan::query()->whereKey($baris->pluck('IdKaryawan')->all())->pluck('IdOutlet', 'Id');
            /** @var array<int, array{Kotor: Uang, Kasbon: Uang, Lain: Uang, Bersih: Uang}> $perOutlet kunci 0 = tanpa outlet */
            $perOutlet = [];

            foreach ($baris as $b) {
                $kunci = (int) ($outlet[$b->IdKaryawan] ?? 0);
                $nilai = $perOutlet[$kunci] ?? ['Kotor' => Uang::Nol(), 'Kasbon' => Uang::Nol(), 'Lain' => Uang::Nol(), 'Bersih' => Uang::Nol()];
                $perOutlet[$kunci] = [
                    'Kotor' => $nilai['Kotor']->Tambah($b->HitungKotor()),
                    'Kasbon' => $nilai['Kasbon']->Tambah(Uang::Dari($b->PotonganKasbon)),
                    'Lain' => $nilai['Lain']->Tambah(Uang::Dari($b->PotonganLain))->Tambah(Uang::Dari($b->PotonganTerlambat))->Tambah(Uang::Dari($b->PotonganTidakMasuk)),
                    'Bersih' => $nilai['Bersih']->Tambah(Uang::Dari($b->Bersih)),
                ];
            }

            $barisJurnal = [];

            foreach ($perOutlet as $kunci => $n) {
                $idOutlet = $kunci === 0 ? null : $kunci;
                $barisJurnal[] = new DataBarisJurnal(peran: null, idAkun: $akunBeban, idOutlet: $idOutlet, debit: $n['Kotor'], kredit: Uang::Nol(), memo: "Gaji {$rekap->AmbilLabel()}");
                $barisJurnal[] = DataBarisJurnal::Kredit(PeranAkun::PiutangKaryawan, $n['Kasbon'], $idOutlet, 'Potongan kasbon');
                $barisJurnal[] = DataBarisJurnal::Kredit(PeranAkun::PendapatanLain, $n['Lain'], $idOutlet, 'Potongan lain & kehadiran gaji');
                $barisJurnal[] = new DataBarisJurnal(peran: null, idAkun: $akunKas, idOutlet: $idOutlet, debit: Uang::Nol(), kredit: $n['Bersih'], memo: 'Gaji dibayar');
            }

            $hasil = $this->posting->Jalankan(new DataJurnal(
                jenisSumber: JenisSumberJurnal::RekapGaji,
                idSumber: $rekap->Id,
                uuidSumber: $rekap->Uuid,
                nomorSumber: $rekap->Periode,
                tanggal: $tanggal,
                keterangan: "Rekap gaji {$rekap->AmbilLabel()}",
                baris: $barisJurnal,
                idPengguna: $idPengguna,
            ));

            $this->PotongKasbon($rekap, array_values($baris->all()), $tanggal, $idPengguna);
            $rekap->fill([
                'Status' => StatusRekapGaji::Dibayar,
                'TanggalBayar' => $tanggal->toDateString(),
                'IdAkunKasBank' => $akunKas,
                'IdAkunBeban' => $akunBeban,
                'IdJurnal' => $hasil->idJurnal,
                'DibayarOleh' => $idPengguna,
                'DibayarPada' => now(),
            ])->save();
            $this->audit->Catat('rekap-gaji.bayar', $rekap, nilaiBaru: ['Periode' => $rekap->Periode, 'TotalBersih' => $rekap->TotalBersih, 'Nomor' => $hasil->nomor]);

            return $rekap;
        });
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} tanggal mulai & selesai (termasuk) rentang rekap
     */
    private function TentukanRentang(string $periode, SiklusGaji $siklus): array
    {
        $hariIni = $this->HariIni();

        if ($siklus === SiklusGaji::Bulanan) {
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periode) !== 1) {
                throw new PelanggaranAturanBisnis('PeriodeTidakValid', 'Periode harus berformat tahun-bulan, misal 2026-09.', 'Periode');
            }

            if ($periode > $hariIni->format('Y-m')) {
                throw new PelanggaranAturanBisnis('PeriodeBelumMulai', 'Rekap gaji hanya untuk bulan berjalan atau sebelumnya.', 'Periode');
            }

            $awal = CarbonImmutable::createFromFormat('!Y-m', $periode) ?: throw new PelanggaranAturanBisnis('PeriodeTidakValid', 'Periode tidak valid.', 'Periode');

            return [$awal, $awal->endOfMonth()->startOfDay()];
        }

        $awal = preg_match('/^\d{4}-\d{2}-\d{2}$/', $periode) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $periode) : false;

        if ($awal === false || $awal->format('Y-m-d') !== $periode) {
            throw new PelanggaranAturanBisnis('PeriodeTidakValid', 'Tanggal mulai minggu harus berformat tahun-bulan-tanggal, misal 2026-10-19.', 'Periode');
        }

        if ($awal > $hariIni) {
            throw new PelanggaranAturanBisnis('PeriodeBelumMulai', 'Rekap gaji mingguan tidak bisa dimulai setelah hari ini.', 'Periode');
        }

        if ($awal < $hariIni->subDays(self::MAKS_HARI_MUNDUR_MINGGUAN)) {
            throw new PelanggaranAturanBisnis('PeriodeTerlaluLama', 'Tanggal mulai terlalu lama. Rekap mingguan hanya sampai satu tahun ke belakang.', 'Periode');
        }

        return [$awal, $awal->addDays(6)];
    }

    /**
     * @param  list<RekapGajiBaris>  $baris
     */
    private function PotongKasbon(RekapGaji $rekap, array $baris, CarbonImmutable $tanggal, int $idPengguna): void
    {
        $kasbon = $this->kasbon->AmbilAktifPerKaryawan(array_map(fn (RekapGajiBaris $b): int => $b->IdKaryawan, $baris));

        foreach ($baris as $b) {
            $sisaPotong = Uang::Dari($b->PotonganKasbon);

            foreach ($kasbon[$b->IdKaryawan] ?? [] as $k) {
                if ($sisaPotong->BernilaiNol()) {
                    break;
                }

                $sisaKasbon = Uang::Dari($k->Sisa);
                $potong = $sisaPotong->Bandingkan($sisaKasbon) > 0 ? $sisaKasbon : $sisaPotong;
                $this->kelolaKasbon->PotongDariGaji($k, $tanggal, $potong, $rekap->Id, $idPengguna);
                $sisaPotong = $sisaPotong->Kurangi($potong);
            }

            if (! $sisaPotong->BernilaiNol()) {
                throw new PelanggaranAturanBisnis('MelebihiSisaKasbon', 'Potongan kasbon melebihi sisa kasbon karyawan. Muat ulang rekap lalu sesuaikan potongan.');
            }
        }
    }

    /** Tarif per jam × menit ÷ 60, dibulatkan ke sen (HalfUp); satu pembulatan di akhir supaya tidak menumpuk. */
    private static function HitungPerJam(string $tarifPerJam, int $menit): Uang
    {
        return Uang::Dari(BigDecimal::of($tarifPerJam)->multipliedBy($menit)->dividedBy(60, 2, RoundingMode::HalfUp));
    }

    private function KunciDraf(RekapGaji $rekap): RekapGaji
    {
        $rekap = RekapGaji::query()->whereKey($rekap->Id)->lockForUpdate()->firstOrFail();

        if ($rekap->Status !== StatusRekapGaji::Draf) {
            throw new PelanggaranAturanBisnis('RekapSudahDibayar', 'Rekap gaji yang sudah dibayar tidak bisa diubah.');
        }

        return $rekap;
    }

    private function HitungTotal(RekapGaji $rekap): void
    {
        $kotor = Uang::Nol();
        $potongan = Uang::Nol();
        $bersih = Uang::Nol();

        foreach (RekapGajiBaris::query()->where('IdRekapGaji', $rekap->Id)->get() as $b) {
            $kotor = $kotor->Tambah($b->HitungKotor());
            $potongan = $potongan->Tambah($b->HitungPotongan());
            $bersih = $bersih->Tambah(Uang::Dari($b->Bersih));
        }

        $rekap->fill(['TotalKotor' => $kotor->KeString(), 'TotalPotongan' => $potongan->KeString(), 'TotalBersih' => $bersih->KeString()])->save();
    }

    /**
     * @param  list<int>  $idKaryawan
     * @return array<int, Uang>
     */
    private function HitungSisaKasbon(array $idKaryawan): array
    {
        $hasil = [];

        foreach ($this->kasbon->AmbilAktifPerKaryawan($idKaryawan) as $id => $daftar) {
            $hasil[$id] = array_reduce($daftar, fn (Uang $t, $k): Uang => $t->Tambah(Uang::Dari($k->Sisa)), Uang::Nol());
        }

        return $hasil;
    }

    private function HariIni(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now($this->profil->Ambil($this->konteks->Wajib())['ZonaWaktu'])->toDateString());
    }
}
