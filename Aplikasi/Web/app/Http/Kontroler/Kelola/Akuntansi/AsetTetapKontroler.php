<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Akuntansi;

use App\Domain\Akuntansi\Aksi\BatalkanAsetTetap;
use App\Domain\Akuntansi\Aksi\CatatAsetTetap;
use App\Domain\Akuntansi\Aksi\LepasAsetTetap;
use App\Domain\Akuntansi\Aksi\SusutkanAsetTetap;
use App\Domain\Akuntansi\Data\DataAsetTetap;
use App\Domain\Akuntansi\Enum\KelompokAsetTetap;
use App\Domain\Akuntansi\Enum\StatusAsetTetap;
use App\Domain\Akuntansi\Enum\SumberDanaAsetTetap;
use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Akuntansi\Kueri\DaftarAsetTetap;
use App\Domain\Akuntansi\Model\AsetTetap;
use App\Domain\Bersama\Nilai\Uang;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Organisasi\Kueri\PetaUuidOutlet;
use App\Http\Respons\ResponsTabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Aset tetap & penyusutan (FIN-10, `/kelola/akuntansi/aset-tetap`): lihat `laporan.keuangan.lihat`; catat, susutkan,
 * lepas, dan batalkan `akuntansi.kelola`.
 */
final class AsetTetapKontroler extends DasarAkuntansiKontroler
{
    private const ATURAN_UANG = ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'];

    public function Daftar(Request $permintaan, DaftarAsetTetap $daftar): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), DaftarAsetTetap::KOLOM_URUT, DaftarAsetTetap::URUT_BAWAAN, DaftarAsetTetap::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Kelola/Akuntansi/AsetTetap/Daftar', 'Aset', fn (): array => $daftar->Ambil($tabel), fn (): array => [
            'OpsiKelompok' => self::OpsiKelompok(),
            'OpsiStatus' => array_map(fn (StatusAsetTetap $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusAsetTetap::cases()),
            'Izin' => ['Kelola' => $this->CekIzinKelola()],
        ]);
    }

    public function Buat(DaftarAkunPilihan $akun): Response
    {
        return Inertia::render('Kelola/Akuntansi/AsetTetap/Buat', [
            'OpsiKelompok' => self::OpsiKelompok(),
            'OpsiOutlet' => $this->AmbilOpsiOutlet(),
            'OpsiAkun' => $akun->AmbilKasBank(),
            'UmurMaksimal' => CatatAsetTetap::UMUR_MAKSIMAL_BULAN,
        ]);
    }

    public function Simpan(Request $permintaan, CatatAsetTetap $catat, PetaUuidOutlet $outlet): RedirectResponse
    {
        $data = $permintaan->validate([
            'Nama' => ['required', 'string', 'max:150'],
            'Kelompok' => ['required', Rule::enum(KelompokAsetTetap::class)],
            'Outlet' => ['nullable', 'ulid'],
            'TanggalPerolehan' => ['required', 'date_format:Y-m-d'],
            'HargaPerolehan' => self::ATURAN_UANG,
            'NilaiSisa' => ['nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
            'UmurBulan' => ['required', 'integer', 'min:0', 'max:'.CatatAsetTetap::UMUR_MAKSIMAL_BULAN],
            'SumberDana' => ['required', Rule::enum(SumberDanaAsetTetap::class)],
            'AkunKasBank' => ['nullable', 'string', 'size:26'],
            'AkumulasiAwal' => ['nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
            'Catatan' => ['nullable', 'string', 'max:500'],
        ], [
            'HargaPerolehan.*' => 'Isi harga perolehan dalam rupiah, misal 12500000.',
            'NilaiSisa.*' => 'Isi nilai sisa dalam rupiah (boleh 0).',
            'AkumulasiAwal.*' => 'Isi akumulasi penyusutan dalam rupiah (boleh 0).',
            'UmurBulan.*' => 'Masa manfaat 0 sampai 600 bulan.',
        ]);
        $idOutlet = null;

        if (is_string($data['Outlet'] ?? null) && $data['Outlet'] !== '') {
            $uuid = strtoupper($data['Outlet']);
            $idOutlet = $outlet->AmbilIdDariUuid([$uuid])[$uuid] ?? null;
            abort_if($idOutlet === null, 404);
        }

        $aset = $catat->Jalankan(new DataAsetTetap(
            nama: (string) $data['Nama'],
            kelompok: KelompokAsetTetap::from((string) $data['Kelompok']),
            idOutlet: $idOutlet,
            tanggalPerolehan: CarbonImmutable::createFromFormat('!Y-m-d', (string) $data['TanggalPerolehan']) ?: CarbonImmutable::today(),
            hargaPerolehan: Uang::Dari((string) $data['HargaPerolehan']),
            nilaiSisa: Uang::Dari((string) ($data['NilaiSisa'] ?? '0') ?: '0'),
            umurBulan: (int) $data['UmurBulan'],
            sumberDana: SumberDanaAsetTetap::from((string) $data['SumberDana']),
            uuidAkunKasBank: is_string($data['AkunKasBank'] ?? null) ? $data['AkunKasBank'] : null,
            akumulasiAwal: Uang::Dari((string) ($data['AkumulasiAwal'] ?? '0') ?: '0'),
            catatan: is_string($data['Catatan'] ?? null) ? $data['Catatan'] : null,
            idPengguna: $this->Pelaku()->Id,
        ));

        return to_route('kelola.akuntansi.aset-tetap.detail', ['asetTetap' => $aset->Uuid])->with('Kilat', "Aset {$aset->Nomor} dicatat dan dijurnal.");
    }

    public function Detail(string $asetTetap, DaftarAsetTetap $daftar, DaftarAkunPilihan $akun): Response
    {
        $aset = $this->CariAset($asetTetap);

        return Inertia::render('Kelola/Akuntansi/AsetTetap/Detail', $daftar->AmbilDetail($aset) + [
            'OpsiAkun' => $akun->AmbilKasBank(),
            'Izin' => ['Kelola' => $this->CekIzinKelola()],
        ]);
    }

    /** Tombol "Susutkan sekarang": semua aset aktif sampai bulan yang dipilih (bawaan bulan berjalan). */
    public function Susutkan(Request $permintaan, SusutkanAsetTetap $susutkan): RedirectResponse
    {
        $data = $permintaan->validate(['Periode' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']], ['Periode.*' => 'Pilih bulan.']);
        $hasil = $susutkan->Jalankan((string) $data['Periode'], $this->Pelaku()->Id);

        return back()->with('Kilat', $hasil['Dicatat'] === 0
            ? 'Tidak ada penyusutan baru: semua aset sudah disusutkan sampai bulan itu.'
            : "{$hasil['Dicatat']} penyusutan bulanan dijurnal, total ".Uang::Dari($hasil['Total'])->FormatRupiah().'.');
    }

    public function Lepas(string $asetTetap, Request $permintaan, LepasAsetTetap $lepas): RedirectResponse
    {
        $aset = $this->CariAset($asetTetap);
        $data = $permintaan->validate([
            'Tanggal' => ['required', 'date_format:Y-m-d'],
            'NilaiJual' => self::ATURAN_UANG,
            'AkunKasBank' => ['nullable', 'string', 'size:26'],
            'Catatan' => ['nullable', 'string', 'max:500'],
        ], ['NilaiJual.*' => 'Isi nilai jual dalam rupiah (0 bila dibuang/rusak).']);
        $aset = $lepas->Jalankan(
            $aset,
            CarbonImmutable::createFromFormat('!Y-m-d', (string) $data['Tanggal']) ?: CarbonImmutable::today(),
            Uang::Dari((string) $data['NilaiJual']),
            is_string($data['AkunKasBank'] ?? null) ? $data['AkunKasBank'] : null,
            is_string($data['Catatan'] ?? null) ? $data['Catatan'] : null,
            $this->Pelaku()->Id,
        );

        return to_route('kelola.akuntansi.aset-tetap.detail', ['asetTetap' => $aset->Uuid])->with('Kilat', "Aset {$aset->Nomor} dilepas dan dijurnal.");
    }

    public function Batalkan(string $asetTetap, Request $permintaan, BatalkanAsetTetap $batalkan): RedirectResponse
    {
        $aset = $this->CariAset($asetTetap);
        $data = $permintaan->validate(['Alasan' => ['required', 'string', 'min:5', 'max:500']]);
        $aset = $batalkan->Jalankan($aset, (string) $data['Alasan'], $this->Pelaku()->Id);

        return to_route('kelola.akuntansi.aset-tetap.detail', ['asetTetap' => $aset->Uuid])->with('Kilat', "Aset {$aset->Nomor} dibatalkan. Jurnal perolehannya dibalik.");
    }

    /** @return list<array{Nilai: string, Label: string, UmurBulan: int}> */
    private static function OpsiKelompok(): array
    {
        return array_map(fn (KelompokAsetTetap $k): array => ['Nilai' => $k->value, 'Label' => $k->AmbilLabel(), 'UmurBulan' => $k->AmbilUmurBawaanBulan()], KelompokAsetTetap::cases());
    }

    private function CariAset(string $uuid): AsetTetap
    {
        return AsetTetap::query()->where('Uuid', strtoupper($uuid))->firstOrFail();
    }
}
