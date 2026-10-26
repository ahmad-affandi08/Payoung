<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Akuntansi;

use App\Domain\Akuntansi\Aksi\CocokkanMutasiBankOtomatis;
use App\Domain\Akuntansi\Aksi\ImporMutasiBank;
use App\Domain\Akuntansi\Aksi\PutuskanMutasiBank;
use App\Domain\Akuntansi\Enum\StatusMutasiBank;
use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Akuntansi\Kueri\RekonsiliasiBank;
use App\Domain\Akuntansi\Model\MutasiBank;
use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Inertia\Response;

/**
 * Rekonsiliasi bank (FIN-09, `/kelola/akuntansi/rekonsiliasi/{akun}`): lihat `laporan.keuangan.lihat`; impor rekening
 * koran, cocokkan, abaikan `akuntansi.kelola`. Dibuka dari halaman Kas & bank (menu Akuntansi sudah penuh 7 butir).
 */
final class RekonsiliasiBankKontroler extends DasarAkuntansiKontroler
{
    /** Tanpa akun: ke akun kas/bank pertama (urut kode). */
    public function Awal(DaftarAkunPilihan $akun): RedirectResponse
    {
        $pertama = $akun->AmbilKasBank()[0]['Uuid'] ?? null;
        abort_if($pertama === null, 404);

        return to_route('kelola.akuntansi.rekonsiliasi', ['akun' => $pertama]);
    }

    public function Tampilkan(string $akun, Request $permintaan, RekonsiliasiBank $rekon, DaftarAkunPilihan $pilihan): Response|JsonResponse
    {
        $terpilih = $pilihan->CariKasBankDariUuid(strtoupper($akun)) ?? abort(404);
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), RekonsiliasiBank::KOLOM_URUT, RekonsiliasiBank::URUT_BAWAAN, RekonsiliasiBank::KOLOM_SARING);
        $hasil = $permintaan->session()->get('HasilImporMutasi');

        return ResponsTabel::Kirim($permintaan, 'Kelola/Akuntansi/Rekonsiliasi', 'Mutasi', fn (): array => $rekon->AmbilMutasi($tabel, $terpilih['Id']), fn (): array => [
            'Akun' => ['Uuid' => $terpilih['Uuid'], 'Kode' => $terpilih['Kode'], 'Nama' => $terpilih['Nama']],
            'OpsiAkun' => array_map(fn (array $a): array => ['Uuid' => $a['Uuid'], 'Kode' => $a['Kode'], 'Nama' => $a['Nama']], $pilihan->AmbilKasBank()),
            'Ringkasan' => $rekon->AmbilRingkasan($terpilih['Id']),
            'BukuBelumCocok' => $rekon->AmbilBukuBelumCocok($terpilih['Id']),
            'OpsiStatus' => array_map(fn (StatusMutasiBank $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusMutasiBank::cases()),
            'HasilImpor' => is_array($hasil) ? $hasil : null,
            'Izin' => ['Kelola' => $this->CekIzinKelola()],
        ]);
    }

    public function Impor(string $akun, Request $permintaan, ImporMutasiBank $impor, DaftarAkunPilihan $pilihan, CocokkanMutasiBankOtomatis $cocokkan): RedirectResponse
    {
        $data = $permintaan->validate(['Berkas' => ['required', 'file', 'max:5120']], [
            'Berkas.required' => 'Pilih berkas rekening koran (.csv atau .xlsx).',
            'Berkas.max' => 'Ukuran berkas paling besar 5 MB.',
            'Berkas.*' => 'Berkas tidak bisa dibaca. Unggah ulang.',
        ]);
        /** @var UploadedFile $berkas */
        $berkas = $data['Berkas'];
        $hasil = $impor->Jalankan((string) $berkas->getRealPath(), $berkas->getClientOriginalName(), strtoupper($akun), $this->Pelaku()->Id);

        // Mutasi yang jelas pasangannya langsung dicocokkan; yang ambigu tetap menunggu keputusan manual.
        $dicocokkan = 0;

        if ($hasil['Impor']->Baru > 0) {
            $terpilih = $pilihan->CariKasBankDariUuid(strtoupper($akun));
            $dicocokkan = $terpilih === null ? 0 : $cocokkan->Jalankan($terpilih['Id'], $this->Pelaku()->Id);
        }

        $kilat = "{$hasil['Impor']->Baru} mutasi baru diimpor, {$hasil['Impor']->Duplikat} sudah pernah diimpor.";

        if ($dicocokkan > 0) {
            $kilat .= " {$dicocokkan} mutasi dicocokkan otomatis.";
        }

        return back()
            ->with('Kilat', $kilat)
            ->with('HasilImporMutasi', ['Baru' => $hasil['Impor']->Baru, 'Duplikat' => $hasil['Impor']->Duplikat, 'Bermasalah' => $hasil['Bermasalah'], 'Dicocokkan' => $dicocokkan]);
    }

    public function CocokkanOtomatis(string $akun, DaftarAkunPilihan $pilihan, CocokkanMutasiBankOtomatis $cocokkan): RedirectResponse
    {
        $terpilih = $pilihan->CariKasBankDariUuid(strtoupper($akun)) ?? abort(404);
        $jumlah = $cocokkan->Jalankan($terpilih['Id'], $this->Pelaku()->Id);

        return back()->with('Kilat', $jumlah === 0 ? 'Tidak ada mutasi yang bisa dicocokkan otomatis. Sisanya pilih manual.' : "{$jumlah} mutasi dicocokkan otomatis.");
    }

    public function Putuskan(string $mutasiBank, Request $permintaan, PutuskanMutasiBank $putuskan): RedirectResponse
    {
        $mutasi = MutasiBank::query()->where('Uuid', strtoupper($mutasiBank))->firstOrFail();
        $data = $permintaan->validate([
            'Keputusan' => ['required', Rule::in(['Cocok', 'Abaikan', 'Batal'])],
            'Jurnal' => ['nullable', 'string', 'max:40'],
            'Alasan' => ['nullable', 'string', 'max:255'],
        ]);
        $putuskan->Jalankan($mutasi, (string) $data['Keputusan'], is_string($data['Jurnal'] ?? null) ? $data['Jurnal'] : null, is_string($data['Alasan'] ?? null) ? $data['Alasan'] : null, $this->Pelaku()->Id);

        return back()->with('Kilat', match ($data['Keputusan']) {
            'Cocok' => 'Mutasi dicocokkan.',
            'Abaikan' => 'Mutasi diabaikan.',
            default => 'Keputusan dibatalkan. Mutasi kembali belum cocok.',
        });
    }
}
