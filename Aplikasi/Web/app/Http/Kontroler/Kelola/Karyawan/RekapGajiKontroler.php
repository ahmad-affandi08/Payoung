<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Karyawan;

use App\Domain\Akuntansi\Enum\TipeAkun;
use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Bersama\Laporan\JenisKolom;
use App\Domain\Bersama\Laporan\KolomLaporan;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Karyawan\Aksi\KelolaRekapGaji;
use App\Domain\Karyawan\Enum\SiklusGaji;
use App\Domain\Karyawan\Enum\StatusRekapGaji;
use App\Domain\Karyawan\Kueri\DaftarRekapGaji;
use App\Domain\Karyawan\Model\RekapGaji;
use App\Domain\Tenant\Kueri\ProfilTenant;
use App\Http\Kontroler\Kelola\DasarKelolaKontroler;
use App\Http\Respons\ResponsTabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Rekap gaji bulanan atau mingguan (F-18 bagian 3, D-219, `/kelola/karyawan/gaji`). Seluruhnya butuh `karyawan.kelola` karena memuat gaji.
 */
final class RekapGajiKontroler extends DasarKelolaKontroler
{
    private const ATURAN_UANG = ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'];

    public function Daftar(Request $permintaan, DaftarRekapGaji $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarRekapGaji::KOLOM_URUT, DaftarRekapGaji::URUT_BAWAAN, DaftarRekapGaji::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Karyawan/Gaji', 'Rekap', fn (): array => $daftar->Ambil($tabel), fn (): array => [
            'OpsiPeriode' => $daftar->AmbilOpsiPeriode(),
            'UsulanMingguan' => $daftar->AmbilUsulanMingguan(),
            'OpsiSiklus' => array_map(fn (SiklusGaji $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], SiklusGaji::cases()),
            'OpsiStatus' => array_map(fn (StatusRekapGaji $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusRekapGaji::cases()),
        ]);
    }

    public function Simpan(Request $permintaan, KelolaRekapGaji $kelola): RedirectResponse
    {
        $data = $permintaan->validate([
            'Siklus' => ['nullable', 'string', Rule::enum(SiklusGaji::class)],
            'Periode' => ['required', 'string', 'regex:/^\d{4}-\d{2}(-\d{2})?$/'],
        ], ['Siklus.*' => 'Pilih siklus gaji: bulanan atau mingguan.', 'Periode.*' => 'Pilih periode bulan atau isi tanggal mulai minggu.']);
        $siklus = SiklusGaji::tryFrom((string) ($data['Siklus'] ?? '')) ?? SiklusGaji::Bulanan;
        $rekap = $kelola->Buat($data['Periode'], $this->Pelaku()->Id, $siklus);

        return to_route('kelola.karyawan.gaji.detail', ['rekap' => $rekap->Uuid])->with('Kilat', "Draf rekap gaji {$rekap->AmbilLabel()} dibuat.");
    }

    public function Detail(string $rekap, DaftarRekapGaji $daftar, DaftarAkunPilihan $akun): Response
    {
        $model = RekapGaji::query()->where('Uuid', $rekap)->firstOrFail();
        $pilihan = fn (array $a): array => ['Uuid' => $a['Uuid'], 'Kode' => $a['Kode'], 'Nama' => $a['Kode'].' '.$a['Nama']];

        return Inertia::render('Kelola/Karyawan/DetailGaji', $daftar->AmbilDetail($model) + [
            'OpsiAkunKasBank' => array_map($pilihan, $akun->AmbilKasBank()),
            'OpsiAkunBeban' => array_map($pilihan, $akun->Ambil([TipeAkun::Beban])),
        ]);
    }

    public function UbahBaris(string $rekap, string $karyawan, Request $permintaan, KelolaRekapGaji $kelola): RedirectResponse
    {
        $data = $permintaan->validate([
            'Tambahan' => self::ATURAN_UANG,
            'PotonganKasbon' => self::ATURAN_UANG,
            'PotonganLain' => self::ATURAN_UANG,
            'Lembur' => ['nullable', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'PotonganTerlambat' => ['nullable', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'PotonganTidakMasuk' => ['nullable', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'HariMasuk' => ['nullable', 'integer', 'min:0', 'max:31'],
            'Catatan' => ['nullable', 'string', 'max:255'],
        ], [
            'HariMasuk.*' => 'Isi hari masuk dengan angka bulat, misal 6.',
            'Tambahan.*' => 'Isi tambahan dalam rupiah, misal 0 atau 150000.',
            'PotonganKasbon.*' => 'Isi potongan kasbon dalam rupiah.',
            'PotonganLain.*' => 'Isi potongan lain dalam rupiah.',
            'Lembur.*' => 'Isi lembur dalam rupiah.',
            'PotonganTerlambat.*' => 'Isi potongan terlambat dalam rupiah.',
            'PotonganTidakMasuk.*' => 'Isi potongan tidak masuk dalam rupiah.',
            'Catatan.*' => 'Catatan paling panjang 255 karakter.',
        ]);
        $catatan = is_string($data['Catatan'] ?? null) && trim($data['Catatan']) !== '' ? trim($data['Catatan']) : null;
        $model = RekapGaji::query()->where('Uuid', $rekap)->firstOrFail();
        $kelola->UbahBaris($model, $karyawan, Uang::Dari($data['Tambahan']), Uang::Dari($data['PotonganKasbon']), Uang::Dari($data['PotonganLain']), $catatan, self::UangAtauNull($data['Lembur'] ?? null), self::UangAtauNull($data['PotonganTerlambat'] ?? null), self::UangAtauNull($data['PotonganTidakMasuk'] ?? null), isset($data['HariMasuk']) && $data['HariMasuk'] !== '' ? (int) $data['HariMasuk'] : null);

        return to_route('kelola.karyawan.gaji.detail', ['rekap' => $model->Uuid])->with('Kilat', 'Baris gaji diperbarui.');
    }

    /** Aksi massal draf rekap: tambahan (bonus/THR) yang sama untuk karyawan terpilih. */
    public function TambahanMassal(string $rekap, Request $permintaan, KelolaRekapGaji $kelola): RedirectResponse
    {
        $data = $permintaan->validate([
            'Tambahan' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'Catatan' => ['nullable', 'string', 'max:255'],
            'Uuid' => ['required', 'array', 'min:1', 'max:'.KelolaRekapGaji::MAKS_TAMBAHAN_MASSAL],
            'Uuid.*' => ['required', 'ulid'],
        ], [
            'Tambahan.*' => 'Isi tambahan dalam rupiah, misal 150000.',
            'Catatan.*' => 'Catatan paling panjang 255 karakter.',
        ], ['Uuid' => 'karyawan terpilih']);
        $model = RekapGaji::query()->where('Uuid', $rekap)->firstOrFail();
        $catatan = is_string($data['Catatan'] ?? null) && trim($data['Catatan']) !== '' ? trim($data['Catatan']) : null;
        /** @var list<string> $uuid */
        $uuid = array_values($data['Uuid']);
        $jumlah = $kelola->TambahanMassal($model, $uuid, Uang::Dari($data['Tambahan']), $catatan);

        return back()->with('Kilat', "Tambahan ditambahkan ke {$jumlah} karyawan.");
    }

    public function Bayar(string $rekap, Request $permintaan, KelolaRekapGaji $kelola): RedirectResponse
    {
        $data = $permintaan->validate([
            'Tanggal' => ['required', 'date_format:Y-m-d'],
            'AkunKasBank' => ['required', 'string', 'size:26'],
            'AkunBeban' => ['required', 'string', 'size:26'],
        ], [
            'Tanggal.*' => 'Isi tanggal bayar.',
            'AkunKasBank.*' => 'Pilih akun kas atau bank.',
            'AkunBeban.*' => 'Pilih akun beban gaji.',
        ]);
        $model = RekapGaji::query()->where('Uuid', $rekap)->firstOrFail();
        $tanggal = CarbonImmutable::createFromFormat('!Y-m-d', $data['Tanggal']) ?: CarbonImmutable::today();
        $kelola->Bayar($model, $tanggal, $data['AkunKasBank'], $data['AkunBeban'], $this->Pelaku()->Id);

        return to_route('kelola.karyawan.gaji.detail', ['rekap' => $model->Uuid])->with('Kilat', "Gaji {$model->AmbilLabel()} dibayar dan dijurnal.");
    }

    public function Hapus(string $rekap, KelolaRekapGaji $kelola): RedirectResponse
    {
        $model = RekapGaji::query()->where('Uuid', $rekap)->firstOrFail();
        $kelola->Hapus($model);

        return to_route('kelola.karyawan.gaji')->with('Kilat', "Draf rekap gaji {$model->AmbilLabel()} dihapus.");
    }

    /**
     * v3.35: slip gaji per karyawan untuk dicetak atau disimpan PDF (satu slip per halaman kertas). Tanpa `?karyawan=`
     * semua karyawan rekap ini. Draf tetap bisa dicetak sebagai pratinjau, bertanda DRAF.
     */
    public function Slip(string $rekap, Request $permintaan, DaftarRekapGaji $daftar, ProfilTenant $profil): Response
    {
        $model = RekapGaji::query()->where('Uuid', $rekap)->firstOrFail();
        $detail = $daftar->AmbilDetail($model);
        $pilih = $permintaan->query('karyawan');
        $baris = is_string($pilih) && $pilih !== ''
            ? array_values(array_filter($detail['Baris'], fn (array $b): bool => $b['UuidKaryawan'] === strtoupper($pilih)))
            : $detail['Baris'];
        abort_if($baris === [], 404);
        $usaha = $profil->Ambil($this->IdTenant());

        return Inertia::render('Kelola/Karyawan/SlipGaji', [
            'Rekap' => $detail['Rekap'],
            'Baris' => $baris,
            'Usaha' => ['Nama' => $usaha['Nama'], 'Npwp' => $usaha['Npwp']],
        ]);
    }

    public function Ekspor(Request $permintaan, string $rekap, DaftarRekapGaji $daftar): SymfonyResponse
    {
        $model = RekapGaji::query()->where('Uuid', $rekap)->firstOrFail();
        $detail = $daftar->AmbilDetail($model);
        $kunci = ['Nama', 'Jabatan', 'GajiPokok', 'HariMasuk', 'Komisi', 'Tambahan', 'LemburMenit', 'Lembur', 'Kotor', 'TerlambatMenit', 'PotonganTerlambat', 'HariTidakMasuk', 'PotonganTidakMasuk', 'PotonganKasbon', 'PotonganLain', 'Bersih', 'Catatan'];
        $kolom = [
            new KolomLaporan('Nama'), new KolomLaporan('Jabatan'),
            new KolomLaporan('Gaji pokok', JenisKolom::Uang, jumlahkan: true), new KolomLaporan('Hari masuk', JenisKolom::Teks, 12), new KolomLaporan('Komisi', JenisKolom::Uang, jumlahkan: true),
            new KolomLaporan('Tambahan', JenisKolom::Uang, jumlahkan: true),
            new KolomLaporan('Lembur (menit)', JenisKolom::Bilangan, jumlahkan: true), new KolomLaporan('Lembur', JenisKolom::Uang, jumlahkan: true),
            new KolomLaporan('Kotor', JenisKolom::Uang, jumlahkan: true),
            new KolomLaporan('Terlambat (menit)', JenisKolom::Bilangan, jumlahkan: true), new KolomLaporan('Potongan terlambat', JenisKolom::Uang, jumlahkan: true),
            new KolomLaporan('Tidak masuk (hari)', JenisKolom::Bilangan, jumlahkan: true), new KolomLaporan('Potongan tidak masuk', JenisKolom::Uang, jumlahkan: true),
            new KolomLaporan('Potongan kasbon', JenisKolom::Uang, jumlahkan: true), new KolomLaporan('Potongan lain', JenisKolom::Uang, jumlahkan: true),
            new KolomLaporan('Bersih', JenisKolom::Uang, jumlahkan: true), new KolomLaporan('Catatan', JenisKolom::Teks, 30),
        ];
        $isi = array_map(fn (array $b): array => array_map(fn (string $k): string => (string) ($b[$k] ?? ''), $kunci), $detail['Baris']);

        return $this->SajikanLaporan(
            $permintaan,
            'Rekap Gaji',
            "rekap-gaji-{$model->Periode}",
            $kolom,
            $isi,
            [['Periode', $model->AmbilLabel()], ['Siklus', $model->Siklus->AmbilLabel()]],
        );
    }

    private static function UangAtauNull(mixed $nilai): ?Uang
    {
        return is_string($nilai) && $nilai !== '' ? Uang::Dari($nilai) : null;
    }
}
