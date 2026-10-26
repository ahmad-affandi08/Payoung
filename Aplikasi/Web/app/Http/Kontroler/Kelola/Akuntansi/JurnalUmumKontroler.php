<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Kelola\Akuntansi;

use App\Domain\Akuntansi\Aksi\BalikkanJurnalUmum;
use App\Domain\Akuntansi\Aksi\CatatJurnalUmum;
use App\Domain\Akuntansi\Enum\JenisJurnalUmum;
use App\Domain\Akuntansi\Kueri\DaftarAkunPilihan;
use App\Domain\Akuntansi\Kueri\DetailJurnalUmum;
use App\Domain\Akuntansi\Layanan\PenjagaAkunKendali;
use App\Domain\Akuntansi\Layanan\PenyimpanLampiranKasBank;
use App\Domain\Akuntansi\Model\JurnalUmum;
use App\Domain\Organisasi\Kueri\TanggalBisnisOutlet;
use App\Http\Permintaan\Kelola\Akuntansi\BalikkanJurnalUmumPermintaan;
use App\Http\Permintaan\Kelola\Akuntansi\CatatJurnalUmumPermintaan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Jurnal umum manual (FIN-05, D-131): lihat `laporan.keuangan.lihat`, catat/balik `akuntansi.kelola`. Pelaku berbatas
 * outlet wajib memilih outlet pada tiap baris dan hanya melihat jurnal yang semua barisnya di outlet aksesnya; dokumen
 * di luar akses atau tenant lain = 404.
 */
final class JurnalUmumKontroler extends DasarAkuntansiKontroler
{
    /** Halaman penuh "Catat jurnal umum": akun yang boleh dipilih dan opsi outlet saja; aturan akhir tetap di `CatatJurnalUmum`. */
    public function Buat(DaftarAkunPilihan $akun, PenjagaAkunKendali $penjaga, TanggalBisnisOutlet $tanggalBisnis): Response
    {
        return Inertia::render('Kelola/Akuntansi/JurnalUmum/Buat', [
            'UuidKlien' => Str::ulid()->toBase32(),
            'TanggalBawaan' => $tanggalBisnis->Hitung(null)->toDateString(),
            'OpsiJenis' => array_map(fn (JenisJurnalUmum $j): array => ['Nilai' => $j->value, 'Label' => $j->AmbilLabel(), 'Penjelasan' => $j->AmbilPenjelasan()], JenisJurnalUmum::cases()),
            'OpsiOutlet' => $this->AmbilOpsiOutlet(),
            'OpsiAkun' => $akun->AmbilUntukJurnalUmum(array_keys($penjaga->AmbilPeta())),
            'WajibOutlet' => $this->IdOutletBoleh() !== null,
            'Lampiran' => ['Ekstensi' => (array) config('akuntansi.EkstensiLampiran'), 'UkuranMaksimalKb' => (int) config('akuntansi.UkuranMaksimalLampiranKb')],
        ]);
    }

    public function Simpan(CatatJurnalUmumPermintaan $permintaan, CatatJurnalUmum $catat): RedirectResponse
    {
        $data = $permintaan->AmbilData(
            fn (string $uuid): int => $this->CariOutlet($uuid)->Id,
            $this->Pelaku()->Id,
            $this->IdOutletBoleh(),
        );
        $dokumen = $catat->Jalankan($data);
        $pesan = $dokumen->wasRecentlyCreated
            ? "{$dokumen->Jenis->AmbilLabel()} {$dokumen->Nomor} disimpan dan dicatat."
            : "{$dokumen->Nomor} sudah tercatat dari kiriman sebelumnya; tidak dibuat dua kali.";

        return to_route('kelola.akuntansi.jurnal-umum.detail', ['jurnalUmum' => $dokumen->Uuid])->with('Kilat', $pesan);
    }

    public function Detail(string $jurnalUmum, DetailJurnalUmum $detail): Response
    {
        $props = $detail->Ambil($jurnalUmum, $this->IdOutletBoleh());
        abort_if($props === null, 404);

        return Inertia::render('Kelola/Akuntansi/JurnalUmum/Detail', [...$props, 'Izin' => ['Kelola' => $this->CekIzinKelola()]]);
    }

    public function Balikkan(string $jurnalUmum, BalikkanJurnalUmumPermintaan $permintaan, DetailJurnalUmum $detail, BalikkanJurnalUmum $balikkan): RedirectResponse
    {
        $asal = $this->Cari($jurnalUmum, $detail);
        $pembalik = $balikkan->Jalankan($asal, $permintaan->AmbilTanggal(), $permintaan->AmbilAlasan(), $this->Pelaku()->Id);

        return to_route('kelola.akuntansi.jurnal-umum.detail', ['jurnalUmum' => $pembalik->Uuid])->with('Kilat', "{$asal->Nomor} dibalik oleh {$pembalik->Nomor}.");
    }

    public function Lampiran(string $jurnalUmum, DetailJurnalUmum $detail, PenyimpanLampiranKasBank $penyimpan): StreamedResponse
    {
        $dokumen = $this->Cari($jurnalUmum, $detail);

        return $penyimpan->UnduhBerkas($dokumen->PathLampiran, $dokumen->NamaLampiran, $dokumen->MimeLampiran);
    }

    private function Cari(string $uuid, DetailJurnalUmum $detail): JurnalUmum
    {
        $dokumen = $detail->Cari($uuid, $this->IdOutletBoleh());
        abort_unless($dokumen instanceof JurnalUmum, 404);

        return $dokumen;
    }
}
