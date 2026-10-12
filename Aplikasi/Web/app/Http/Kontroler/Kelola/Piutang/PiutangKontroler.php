<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Piutang;

use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Bersama\Dokumen\Enum\StatusDokumenTerposting;
use App\Domain\Bersama\Laporan\JenisKolom;
use App\Domain\Bersama\Laporan\KolomLaporan;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Organisasi\Enum\IzinTenant;
use App\Domain\Organisasi\Kueri\AksesPengguna;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Domain\Pelanggan\Aksi\AntrekanPengingatPiutang;
use App\Domain\Pelanggan\Aksi\AntrekanPengingatPiutangMassal;
use App\Domain\Pelanggan\Aksi\BatalkanHapusBukuPiutang;
use App\Domain\Pelanggan\Aksi\BatalkanPembayaranPiutang;
use App\Domain\Pelanggan\Aksi\CatatHapusBukuPiutang;
use App\Domain\Pelanggan\Aksi\SimpanPembayaranPiutang;
use App\Domain\Pelanggan\Aksi\SimpanPengaturanPengingatPiutang;
use App\Domain\Pelanggan\Enum\JenisPengingatPiutang;
use App\Domain\Pelanggan\Enum\KelompokUmurPiutang;
use App\Domain\Pelanggan\Enum\StatusPembayaranPiutang;
use App\Domain\Pelanggan\Kueri\DaftarPiutang;
use App\Domain\Pelanggan\Kueri\DetailPembayaranPiutang;
use App\Domain\Pelanggan\Kueri\PengaturanPengingatPiutangTenant;
use App\Domain\Pelanggan\Layanan\NomorHp;
use App\Domain\Pelanggan\Model\Pelanggan;
use App\Domain\Pelanggan\Model\PembayaranPiutang;
use App\Domain\Pelanggan\Model\Piutang;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use App\Http\Permintaan\Kelola\Pembelian\AlasanPembelianPermintaan;
use App\Http\Permintaan\Kelola\Piutang\HapusBukuPiutangPermintaan;
use App\Http\Permintaan\Kelola\Piutang\SimpanPembayaranPiutangPermintaan;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Piutang pelanggan (F-12): daftar piutang terbuka dengan umur 0–30/31–60/61–90/>90 hari (`/kelola/piutang`),
 * daftar/form/detail/pembatalan pelunasan (`/kelola/piutang/pelunasan`). Lihat: `pelanggan.lihat`; pelunasan &
 * pembatalan: `akuntansi.kelola`. Data tenant lain = 404 (`MilikTenant`).
 */
final class PiutangKontroler extends DasarKelolaKontroler
{
    public function Piutang(Request $permintaan, DaftarPiutang $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarPiutang::KOLOM_URUT, DaftarPiutang::URUT_BAWAAN, DaftarPiutang::KOLOM_SARING);
        $hariIni = app(TanggalBisnisOutlet::class)->Hitung(null);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Piutang/Daftar', 'Piutang', fn (): array => $daftar->Terbuka($tabel, $hariIni, $this->IdOutletBoleh()), fn (): array => [
            'OpsiUmur' => array_map(fn (KelompokUmurPiutang $k): array => ['Nilai' => $k->value, 'Label' => $k->AmbilLabel()], KelompokUmurPiutang::cases()),
            'OpsiPelanggan' => $daftar->AmbilOpsiPelanggan(),
            'HariIni' => $hariIni->format('Y-m-d'),
            'Izin' => $this->AmbilIzin(),
            'Pengingat' => app(PengaturanPengingatPiutangTenant::class)->Ambil(),
        ]);
    }

    /** Ekspor umur piutang sesuai saringan & cari yang aktif di tabel (maks. 5.000 baris). */
    public function EksporPiutang(Request $permintaan, DaftarPiutang $daftar): SymfonyResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarPiutang::KOLOM_URUT, DaftarPiutang::URUT_BAWAAN, DaftarPiutang::KOLOM_SARING);
        $hariIni = app(TanggalBisnisOutlet::class)->Hitung(null);
        $idOutlet = $this->IdOutletBoleh();
        $baris = $this->AmbilSemuaBarisTabel($tabel, fn (DataPermintaanTabel $t): array => $daftar->Terbuka($t, $hariIni, $idOutlet));
        $kolom = [
            new KolomLaporan('Nomor penjualan', JenisKolom::Teks, 24), new KolomLaporan('Pelanggan', JenisKolom::Teks, 28),
            new KolomLaporan('Tanggal', JenisKolom::Tanggal), new KolomLaporan('Jatuh tempo', JenisKolom::Tanggal),
            new KolomLaporan('Hari lewat', JenisKolom::Bilangan), new KolomLaporan('Umur', JenisKolom::Teks, 18),
            new KolomLaporan('Status', JenisKolom::Teks, 16), new KolomLaporan('Jumlah', JenisKolom::Uang, jumlahkan: true),
            new KolomLaporan('Sisa', JenisKolom::Uang, jumlahkan: true),
        ];
        $isi = array_map(fn (array $b): array => [
            $b['Nomor'], $b['NamaPelanggan'] ?? '', $b['Tanggal'], $b['JatuhTempo'], $b['HariLewat'], $b['LabelUmur'], $b['LabelStatus'], $b['Jumlah'], $b['Sisa'],
        ], $baris);

        return $this->SajikanLaporan($permintaan, 'Laporan Umur Piutang', 'umur-piutang', $kolom, $isi, [['Per tanggal', $hariIni->format('d/m/Y')]]);
    }

    /**
     * v3.37 nota tagihan pelanggan (F-12): semua piutang terbuka satu pelanggan per tanggal bisnis hari ini, untuk
     * dicetak/PDF lalu diberikan ke pelanggan. Pengguna terbatas outlet hanya melihat piutang outletnya.
     */
    public function NotaTagihan(string $pelanggan, DaftarPiutang $daftar, ProfilTenant $profil): Response
    {
        $model = Pelanggan::query()->where('Uuid', strtoupper($pelanggan))->firstOrFail();
        $hariIni = app(TanggalBisnisOutlet::class)->Hitung(null);
        $nota = $daftar->AmbilNotaTagihan($model->Id, $hariIni, $this->IdOutletBoleh());
        $usaha = $profil->Ambil($this->IdTenant());

        return Inertia::render('Kelola/Piutang/NotaTagihan', $nota + [
            'Pelanggan' => ['Nama' => $model->Nama, 'NoHp' => NomorHp::Format($model->NoHp), 'Alamat' => $model->Alamat],
            'Usaha' => ['Nama' => $usaha['Nama'], 'Npwp' => $usaha['Npwp']],
            'Tanggal' => $hariIni->format('Y-m-d'),
        ]);
    }

    /** D-23 D: kirim pengingat piutang ke pelanggan sekarang lewat WhatsApp (D-33). */
    public function KirimPengingat(string $piutang, AntrekanPengingatPiutang $antrekan): RedirectResponse
    {
        // Parameter string (bukan binding model): konteks tenant baru ditetapkan setelah binding rute.
        $piutang = Piutang::query()->where('Uuid', $piutang)->firstOrFail();
        abort_if(($idOutlet = $this->IdOutletBoleh()) !== null && ! in_array($piutang->IdOutlet, $idOutlet, true), 404);
        $antrekan->Jalankan($this->IdTenant(), $piutang, JenisPengingatPiutang::Manual, $this->Pelaku()->Id);

        return back()->with('Kilat', "Pengingat {$piutang->Nomor} sedang dikirim lewat WhatsApp.");
    }

    /** Aksi massal: pengingat WhatsApp untuk piutang terpilih; yang tidak bisa dikirim dilewati dengan alasannya. */
    public function KirimPengingatMassal(Request $permintaan, AntrekanPengingatPiutangMassal $antrekan): RedirectResponse
    {
        $valid = $permintaan->validate([
            'Uuid' => ['required', 'array', 'min:1', 'max:'.AntrekanPengingatPiutangMassal::MAKS],
            'Uuid.*' => ['required', 'ulid'],
        ], attributes: ['Uuid' => 'piutang terpilih']);
        /** @var list<string> $uuid */
        $uuid = array_values($valid['Uuid']);
        $hasil = $antrekan->Jalankan($this->IdTenant(), $uuid, $this->Pelaku()->Id, $this->IdOutletBoleh());
        $dilewati = [];

        foreach ($hasil['Dilewati'] as $alasan => $jumlah) {
            $dilewati[] = "{$jumlah} dilewati: {$alasan}";
        }

        $rincian = implode(' ', $dilewati);

        if ($hasil['Diantrekan'] === 0) {
            return back()->withErrors(['Umum' => trim("Tidak ada pengingat yang dikirim. {$rincian}")]);
        }

        return back()->with('Kilat', trim("{$hasil['Diantrekan']} pengingat sedang dikirim lewat WhatsApp. {$rincian}"));
    }

    /** D-23 D: pengaturan pengingat piutang otomatis. */
    public function SimpanPengingat(Request $permintaan, SimpanPengaturanPengingatPiutang $simpan): RedirectResponse
    {
        $valid = $permintaan->validate([
            'Aktif' => ['required', 'boolean'],
            'HariSebelum' => ['required', 'integer', 'min:0', 'max:'.SimpanPengaturanPengingatPiutang::MAKS_HARI_SEBELUM],
            'IngatkanSaatLewat' => ['required', 'boolean'],
        ], attributes: ['HariSebelum' => 'hari sebelum jatuh tempo', 'IngatkanSaatLewat' => 'pengingat saat lewat jatuh tempo']);
        $simpan->Jalankan((bool) $valid['Aktif'], (int) $valid['HariSebelum'], (bool) $valid['IngatkanSaatLewat'], $this->Pelaku()->Id);

        return back()->with('Kilat', $valid['Aktif'] ? 'Pengingat piutang otomatis aktif.' : 'Pengingat piutang otomatis dimatikan.');
    }

    /** E8: hapus-buku sisa piutang yang tidak akan tertagih (izin `akuntansi.kelola`). */
    public function HapusBuku(HapusBukuPiutangPermintaan $permintaan, string $piutang, CatatHapusBukuPiutang $catat): RedirectResponse
    {
        $model = Piutang::query()->where('Uuid', $piutang)->firstOrFail();
        abort_if(($idOutlet = $this->IdOutletBoleh()) !== null && ! in_array($model->IdOutlet, $idOutlet, true), 404);
        $dokumen = $catat->Jalankan($model->Uuid, $permintaan->AmbilTanggal(), $permintaan->AmbilAlasan(), $this->Pelaku()->Id);

        return to_route('kelola.piutang.hapus-buku.daftar', ['cari' => $dokumen->Uuid])->with('Kilat', "{$dokumen->Nomor} diposting. Piutang {$model->Nomor} dihapus-buku sebagai beban.");
    }

    public function DaftarHapusBuku(Request $permintaan, DaftarPiutang $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarPiutang::KOLOM_URUT, DaftarPiutang::URUT_BAWAAN_HAPUS_BUKU, DaftarPiutang::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Piutang/HapusBuku/Daftar', 'HapusBuku', fn (): array => $daftar->HapusBuku($tabel), fn (): array => [
            'OpsiStatus' => array_map(fn (StatusDokumenTerposting $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusDokumenTerposting::cases()),
            'Izin' => $this->AmbilIzin(),
        ]);
    }

    public function BatalkanHapusBuku(AlasanPembelianPermintaan $permintaan, string $hapusBuku, BatalkanHapusBukuPiutang $batalkan): RedirectResponse
    {
        $dokumen = $batalkan->Jalankan($hapusBuku, $permintaan->AmbilAlasan(), $this->Pelaku()->Id);

        return to_route('kelola.piutang.hapus-buku.daftar', ['cari' => $dokumen->Uuid])->with('Kilat', "{$dokumen->Nomor} dibatalkan. Piutang kembali menjadi tagihan terbuka.");
    }

    public function Daftar(Request $permintaan, DaftarPiutang $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarPiutang::KOLOM_URUT, DaftarPiutang::URUT_BAWAAN_PELUNASAN, DaftarPiutang::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Piutang/Pelunasan/Daftar', 'Pelunasan', fn (): array => $daftar->Pelunasan($tabel), fn (): array => [
            'OpsiStatus' => array_map(fn (StatusPembayaranPiutang $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusPembayaranPiutang::cases()),
            'Izin' => $this->AmbilIzin(),
        ]);
    }

    public function Buat(Request $permintaan, DaftarPiutang $daftar, DaftarAkunPilihan $akun): Response
    {
        $uuidPelanggan = $permintaan->query('pelanggan');
        $terpilih = is_string($uuidPelanggan) && $uuidPelanggan !== '' ? Pelanggan::query()->where('Uuid', $uuidPelanggan)->firstOrFail() : null;

        return Inertia::render('Kelola/Piutang/Pelunasan/Form', [
            'OpsiPelanggan' => $daftar->AmbilOpsiPelanggan(),
            'OpsiAkun' => array_map(fn (array $a): array => ['Uuid' => $a['Uuid'], 'Kode' => $a['Kode'], 'Nama' => $a['Nama']], $akun->AmbilKasBank()),
            'UuidPelanggan' => $terpilih?->Uuid,
            'NamaPelanggan' => $terpilih?->Nama,
            'UuidPiutangAwal' => is_string($permintaan->query('piutang')) ? $permintaan->query('piutang') : null,
            'Piutang' => $terpilih === null ? [] : $daftar->AmbilTerbukaPelanggan($terpilih->Id),
            'HariIni' => app(TanggalBisnisOutlet::class)->Hitung(null)->format('Y-m-d'),
        ]);
    }

    public function Simpan(SimpanPembayaranPiutangPermintaan $permintaan, SimpanPembayaranPiutang $simpan): RedirectResponse
    {
        $p = $simpan->Jalankan($permintaan->AmbilData($this->Pelaku()->Id));

        return to_route('kelola.piutang.pelunasan.detail', ['pelunasan' => $p->Uuid])->with('Kilat', "{$p->Nomor} diposting. Sisa piutang sudah berkurang.");
    }

    public function Detail(string $pelunasan, DetailPembayaranPiutang $detail): Response
    {
        $p = PembayaranPiutang::query()->where('Uuid', $pelunasan)->firstOrFail();
        $izin = $this->AmbilIzin();

        return Inertia::render('Kelola/Piutang/Pelunasan/Detail', [
            ...$detail->Ambil($p),
            'Izin' => $izin,
            'Tindakan' => ['Batalkan' => $izin['Kelola'] && $p->Status === StatusPembayaranPiutang::Diposting],
        ]);
    }

    public function Batalkan(AlasanPembelianPermintaan $permintaan, string $pelunasan, BatalkanPembayaranPiutang $batalkan): RedirectResponse
    {
        $p = $batalkan->Jalankan(PembayaranPiutang::query()->where('Uuid', $pelunasan)->firstOrFail(), $permintaan->AmbilAlasan(), $this->Pelaku()->Id);

        return to_route('kelola.piutang.pelunasan.detail', ['pelunasan' => $p->Uuid])->with('Kilat', "{$p->Nomor} dibatalkan. Sisa piutang dikembalikan.");
    }

    /**
     * @return array{Kelola: bool, LihatJurnal: bool}
     */
    private function AmbilIzin(): array
    {
        $akses = app(AksesPengguna::class);

        return [
            'Kelola' => $akses->CekIzin($this->IdTenant(), $this->Pelaku()->Id, IzinTenant::AkuntansiKelola),
            'LihatJurnal' => $akses->CekIzin($this->IdTenant(), $this->Pelaku()->Id, IzinTenant::LaporanKeuanganLihat),
            'Ingatkan' => $akses->CekIzin($this->IdTenant(), $this->Pelaku()->Id, IzinTenant::PelangganKelola),
        ];
    }
}
