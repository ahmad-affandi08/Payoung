<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\Dukungan;

use App\Domain\Bersama\Tabel\Data\DataPermintaanTabel;
use App\Domain\Dukungan\Enum\PrioritasTiketDukungan;
use App\Domain\Dukungan\Enum\StatusTiketDukungan;
use App\Domain\Dukungan\Enum\TingkatEskalasiTiket;
use App\Domain\Dukungan\Layanan\PenyimpanLampiran;
use App\Domain\Pengelola\Dukungan\Aksi\BalasTiketDukunganPengelola;
use App\Domain\Pengelola\Dukungan\Aksi\EskalasikanTiketDukungan;
use App\Domain\Pengelola\Dukungan\Aksi\TugaskanTiketDukungan;
use App\Domain\Pengelola\Dukungan\Aksi\UbahPrioritasTiketDukungan;
use App\Domain\Pengelola\Dukungan\Aksi\UbahStatusTiketDukungan;
use App\Domain\Pengelola\Dukungan\Aksi\UbahStatusTiketDukunganMassal;
use App\Domain\Pengelola\Dukungan\Kueri\AntreanTiketDukungan;
use App\Domain\Pengelola\Dukungan\Kueri\DetailTiketDukungan;
use App\Domain\Pengelola\TimInternal\Enum\IzinPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use App\Http\Kontroler\Kelola\BantuanKontroler;
use App\Http\Kontroler\Kontroler;
use App\Http\Kontroler\Pengelola\PelakuPengelola;
use App\Http\Permintaan\Pengelola\Dukungan\BalasTiketDukunganPengelolaPermintaan;
use App\Http\Permintaan\Pengelola\Dukungan\UbahStatusTiketDukunganPermintaan;
use App\Http\Respons\ResponsTabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Antrean & penanganan tiket dukungan di Platform Pengelola (P-09, §19.3: Dukungan & Super Admin).
 * Data tiket dibaca/diubah lintas tenant hanya lewat KonteksPengelola di dalam kueri & aksi domain.
 */
final class TiketDukunganKontroler extends Kontroler
{
    use PelakuPengelola;

    public function Daftar(Request $permintaan, AntreanTiketDukungan $antrean): Response|JsonResponse
    {
        $tabel = DataPermintaanTabel::Dari($permintaan->query(), AntreanTiketDukungan::KOLOM_URUT, '', AntreanTiketDukungan::KOLOM_SARING);

        return ResponsTabel::Kirim($permintaan, 'Pengelola/Dukungan/Antrean', 'Tiket', fn (): array => $antrean->AmbilTabel($this->AmbilPelaku(), $tabel), fn (): array => [
            'PilihanStatus' => self::AmbilPilihanStatus(),
            'PilihanPrioritas' => self::AmbilPilihanPrioritas(),
            'BolehTangani' => $this->AmbilPelaku()->PunyaIzin(IzinPengelola::DukunganTiketTangani),
        ]);
    }

    public function Tampilkan(string $tiketDukungan, DetailTiketDukungan $detail): Response
    {
        return Inertia::render('Pengelola/Dukungan/Tiket', [
            'Tiket' => $detail->Ambil($tiketDukungan),
            'Penangan' => $detail->AmbilPenangan(),
            'PilihanStatus' => self::AmbilPilihanStatus(),
            'PilihanPrioritas' => self::AmbilPilihanPrioritas(),
            'Lampiran' => BantuanKontroler::AmbilBatasLampiran(),
        ]);
    }

    public function UnduhLampiran(string $tiketDukungan, string $lampiran, DetailTiketDukungan $detail, PenyimpanLampiran $penyimpan): StreamedResponse
    {
        $data = $detail->CariLampiran($tiketDukungan, $lampiran);
        abort_if($data === null, 404);

        return $penyimpan->Unduh($data);
    }

    public function Ambil(string $tiketDukungan, TugaskanTiketDukungan $tugaskan): RedirectResponse
    {
        $pelaku = $this->AmbilPelaku();
        $tiket = $tugaskan->Jalankan($pelaku, $tiketDukungan, $pelaku);

        return back()->with('Kilat', "Tiket {$tiket->Nomor} sekarang Anda tangani.");
    }

    public function Tugaskan(string $tiketDukungan, Request $permintaan, TugaskanTiketDukungan $tugaskan): RedirectResponse
    {
        $uuid = $permintaan->validate(['UuidPenanggungJawab' => ['required', 'string', Rule::exists('PenggunaPengelola', 'Uuid')]])['UuidPenanggungJawab'];
        $penanggungJawab = PenggunaPengelola::query()->where('Uuid', $uuid)->firstOrFail();
        $tiket = $tugaskan->Jalankan($this->AmbilPelaku(), $tiketDukungan, $penanggungJawab);

        return back()->with('Kilat', "Tiket {$tiket->Nomor} ditugaskan ke {$penanggungJawab->Nama}.");
    }

    public function Balas(string $tiketDukungan, BalasTiketDukunganPengelolaPermintaan $permintaan, BalasTiketDukunganPengelola $balas): RedirectResponse
    {
        $internal = $permintaan->boolean('CatatanInternal');
        $balas->Jalankan($this->AmbilPelaku(), $tiketDukungan, $permintaan->AmbilIsi(), $internal, $permintaan->AmbilLampiran(), $permintaan->AmbilStatus());

        return back()->with('Kilat', $internal ? 'Catatan internal disimpan.' : 'Balasan terkirim ke tenant.');
    }

    public function UbahStatus(string $tiketDukungan, UbahStatusTiketDukunganPermintaan $permintaan, UbahStatusTiketDukungan $ubah): RedirectResponse
    {
        $tiket = $ubah->Jalankan($this->AmbilPelaku(), $tiketDukungan, $permintaan->AmbilStatus(), $permintaan->AmbilAlasan());

        return back()->with('Kilat', "Status tiket {$tiket->Nomor} menjadi {$tiket->Status->AmbilLabel()}.");
    }

    /** Aksi massal tiket terpilih: tandai selesai atau tutup (alasan wajib untuk menutup). */
    public function UbahStatusMassal(Request $permintaan, UbahStatusTiketDukunganMassal $ubah): RedirectResponse
    {
        $valid = $permintaan->validate([
            'Status' => ['required', 'string', Rule::in(UbahStatusTiketDukunganMassal::TUJUAN)],
            'Alasan' => ['nullable', 'string', 'max:500'],
            'Uuid' => ['required', 'array', 'min:1', 'max:'.UbahStatusTiketDukunganMassal::MAKS],
            'Uuid.*' => ['required', 'string', 'size:26'],
        ], attributes: ['Uuid' => 'tiket terpilih']);
        /** @var list<string> $uuid */
        $uuid = array_values($valid['Uuid']);
        $alasan = is_string($valid['Alasan'] ?? null) ? trim($valid['Alasan']) : null;
        $hasil = $ubah->Jalankan($this->AmbilPelaku(), $uuid, $valid['Status'], $alasan);
        $kata = $valid['Status'] === 'Ditutup' ? 'ditutup' : 'ditandai selesai';
        $rincian = [];

        foreach ($hasil['Dilewati'] as $sebab => $jumlah) {
            $rincian[] = "{$jumlah} dilewati: {$sebab}";
        }

        $pesan = trim("{$hasil['Diubah']} tiket {$kata}. ".implode(' ', $rincian));

        return $hasil['Diubah'] === 0 ? back()->withErrors(['Umum' => "Tidak ada tiket yang berubah. {$pesan}"]) : back()->with('Kilat', $pesan);
    }

    public function UbahPrioritas(string $tiketDukungan, Request $permintaan, UbahPrioritasTiketDukungan $ubah): RedirectResponse
    {
        $nilai = $permintaan->validate(['Prioritas' => ['required', 'string', Rule::enum(PrioritasTiketDukungan::class)]])['Prioritas'];
        $tiket = $ubah->Jalankan($this->AmbilPelaku(), $tiketDukungan, PrioritasTiketDukungan::from($nilai));

        return back()->with('Kilat', "Prioritas tiket {$tiket->Nomor} menjadi {$tiket->Prioritas->AmbilLabel()}.");
    }

    public function Eskalasikan(string $tiketDukungan, Request $permintaan, EskalasikanTiketDukungan $eskalasi): RedirectResponse
    {
        $valid = $permintaan->validate([
            'Tingkat' => ['required', 'string', Rule::in([TingkatEskalasiTiket::L2->value, TingkatEskalasiTiket::Teknis->value])],
            'Alasan' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $tiket = $eskalasi->Jalankan($this->AmbilPelaku(), $tiketDukungan, TingkatEskalasiTiket::from((string) $valid['Tingkat']), (string) $valid['Alasan']);

        return back()->with('Kilat', "Tiket {$tiket->Nomor} dieskalasi ke {$tiket->TingkatEskalasi->AmbilLabel()}. Tim tujuan diberi tahu lewat email.");
    }

    /**
     * @return list<array{Nilai: string, Label: string}>
     */
    private static function AmbilPilihanStatus(): array
    {
        return array_map(fn (StatusTiketDukungan $status) => ['Nilai' => $status->value, 'Label' => $status->AmbilLabel()], StatusTiketDukungan::cases());
    }

    /**
     * @return list<array{Nilai: string, Label: string}>
     */
    private static function AmbilPilihanPrioritas(): array
    {
        return array_map(fn (PrioritasTiketDukungan $prioritas) => ['Nilai' => $prioritas->value, 'Label' => $prioritas->AmbilLabel()], PrioritasTiketDukungan::cases());
    }
}
