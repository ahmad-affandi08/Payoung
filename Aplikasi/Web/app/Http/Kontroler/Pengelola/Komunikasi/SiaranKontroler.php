<?php

declare(strict_types=1);

namespace App\Http\Kontroler\Pengelola\Komunikasi;

use App\Domain\Integrasi\Whatsapp\PembuatPengirimWhatsapp;
use App\Domain\Pengelola\Komunikasi\Aksi\BatalkanSiaran;
use App\Domain\Pengelola\Komunikasi\Aksi\BuatSiaran;
use App\Domain\Pengelola\Komunikasi\Aksi\KirimSiaranJatuhTempo;
use App\Domain\Pengelola\Komunikasi\Enum\KanalSiaran;
use App\Domain\Pengelola\Komunikasi\Kueri\SegmenSiaran;
use App\Domain\Pengelola\Komunikasi\Model\PenerimaSiaran;
use App\Domain\Pengelola\Komunikasi\Model\PesanSiaran;
use App\Domain\Pengelola\Tenant\Enum\KategoriKesehatan;
use App\Domain\Tenant\Enum\StatusLangganan;
use App\Domain\Tenant\Model\Paket;
use App\Domain\Tenant\Model\Tenant;
use App\Http\Kontroler\Kontroler;
use App\Http\Kontroler\Pengelola\PelakuPengelola;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Siaran Payoung ke pemilik tenant (D-210, `/komunikasi`), semuanya dengan izin `komunikasi.kelola`: daftar, buat dengan
 * segmen dan estimasi penerima, rincian per penerima, dan batalkan selama belum mulai dikirim.
 */
final class SiaranKontroler extends Kontroler
{
    use PelakuPengelola;

    private const MAKS_TENANT_PILIHAN = 200;

    public function Daftar(): Response
    {
        return Inertia::render('Pengelola/Komunikasi/Daftar', [
            'Siaran' => array_values(PesanSiaran::query()->orderByDesc('Id')->limit(100)->get()->map(fn (PesanSiaran $s): array => self::Petakan($s))->all()),
        ]);
    }

    public function Buat(Request $permintaan, PembuatPengirimWhatsapp $whatsapp): Response
    {
        $uuid = array_values(array_filter((array) $permintaan->query('tenant', []), 'is_string'));
        $tenantAwal = $uuid === [] ? [] : Tenant::query()->whereIn('Uuid', array_slice($uuid, 0, self::MAKS_TENANT_PILIHAN))->orderBy('Nama')->get(['Uuid', 'Nama'])
            ->map(fn (Tenant $t): array => ['Uuid' => $t->Uuid, 'Nama' => $t->Nama])->values()->all();
        $pengirim = $whatsapp->AmbilAktifPlatform();

        return Inertia::render('Pengelola/Komunikasi/Buat', [
            'PilihanStatus' => array_map(fn (StatusLangganan $s): array => ['Nilai' => $s->value, 'Label' => $s->AmbilLabel()], StatusLangganan::cases()),
            'PilihanKesehatan' => array_map(fn (KategoriKesehatan $k): array => ['Nilai' => $k->value, 'Label' => $k->AmbilLabel()], KategoriKesehatan::cases()),
            'PilihanPaket' => Paket::query()->orderBy('Urutan')->orderBy('Id')->get(['Kode', 'Nama'])->map(fn (Paket $p): array => ['Nilai' => $p->Kode, 'Label' => $p->Nama])->values()->all(),
            'TenantAwal' => $tenantAwal,
            'Whatsapp' => [
                'Tersedia' => $pengirim !== null && ! $pengirim->CekResmi(),
                'Alasan' => $pengirim === null
                    ? 'WhatsApp platform belum dikonfigurasi di Integrasi.'
                    : ($pengirim->CekResmi() ? 'Penyedia WhatsApp resmi hanya mengirim templat yang disetujui Meta, jadi siaran teks bebas lewat email.' : null),
                'JamMulai' => KirimSiaranJatuhTempo::JAM_MULAI_WA,
                'JamSelesai' => KirimSiaranJatuhTempo::JAM_SELESAI_WA,
            ],
        ]);
    }

    public function Estimasi(Request $permintaan, SegmenSiaran $segmen): JsonResponse
    {
        $valid = $permintaan->validate(['Kanal' => ['required', Rule::enum(KanalSiaran::class)]]);

        return response()->json($segmen->Hitung($this->AmbilSegmen($permintaan->query()), KanalSiaran::from((string) $valid['Kanal'])));
    }

    public function Simpan(Request $permintaan, BuatSiaran $buat): RedirectResponse
    {
        $valid = $permintaan->validate([
            'Judul' => ['required', 'string', 'min:3', 'max:120'],
            'Isi' => ['required', 'string', 'min:10', 'max:1000'],
            'Kanal' => ['required', Rule::enum(KanalSiaran::class)],
            'Segmen' => ['nullable', 'array'],
            // Hanya kunci yang punya aturan yang lolos `validate()`, jadi tiap bagian segmen perlu aturannya sendiri.
            'Segmen.Status' => ['nullable', 'array'],
            'Segmen.Status.*' => ['string'],
            'Segmen.Paket' => ['nullable', 'array'],
            'Segmen.Paket.*' => ['string'],
            'Segmen.Kesehatan' => ['nullable', 'array'],
            'Segmen.Kesehatan.*' => ['string'],
            'Segmen.Tenant' => ['nullable', 'array', 'max:'.self::MAKS_TENANT_PILIHAN],
            'Segmen.Tenant.*' => ['string', 'size:26'],
            'JadwalKirimPada' => ['nullable', 'date'],
        ], [
            'Judul.*' => 'Isi judul siaran, 3 sampai 120 huruf.',
            'Isi.*' => 'Isi pesan 10 sampai 1.000 huruf.',
            'Kanal.*' => 'Pilih kanal pengiriman.',
        ]);

        $jadwalMentah = $valid['JadwalKirimPada'] ?? null;
        $jadwal = is_string($jadwalMentah) && $jadwalMentah !== '' ? Carbon::parse($jadwalMentah) : null;
        $siaran = $buat->Jalankan($this->AmbilPelaku(), (string) $valid['Judul'], (string) $valid['Isi'], KanalSiaran::from((string) $valid['Kanal']), (array) ($valid['Segmen'] ?? []), $jadwal);

        return redirect()->route('pengelola.komunikasi.tampil', $siaran->Uuid)->with('Kilat', 'Siaran dijadwalkan. Pengiriman berjalan bertahap tiap lima menit.');
    }

    public function Tampil(PesanSiaran $siaran): Response
    {
        $penerima = PenerimaSiaran::query()->where('IdPesanSiaran', $siaran->Id)->orderByRaw("FIELD(Status, 'Gagal', 'Dilewati', 'Diproses', 'Menunggu', 'Terkirim')")->orderBy('Id')->limit(200)->get();

        return Inertia::render('Pengelola/Komunikasi/Tampil', [
            'Siaran' => [...self::Petakan($siaran), 'Isi' => $siaran->Isi, 'Segmen' => $siaran->Segmen],
            'Penerima' => array_values($penerima->map(fn (PenerimaSiaran $p): array => [
                'Id' => $p->Id,
                'Nama' => $p->Nama,
                'Tujuan' => $p->Tujuan,
                'Status' => $p->Status->value,
                'Alasan' => $p->Alasan,
                'DikirimPada' => $p->DikirimPada?->toIso8601String(),
            ])->all()),
        ]);
    }

    public function Batalkan(PesanSiaran $siaran, BatalkanSiaran $batalkan): RedirectResponse
    {
        $batalkan->Jalankan($this->AmbilPelaku(), $siaran);

        return back()->with('Kilat', 'Siaran dibatalkan.');
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @return array<string, mixed>
     */
    private function AmbilSegmen(array $query): array
    {
        return ['Status' => $query['Status'] ?? [], 'Paket' => $query['Paket'] ?? [], 'Kesehatan' => $query['Kesehatan'] ?? [], 'Tenant' => $query['Tenant'] ?? []];
    }

    /**
     * @return array<string, mixed>
     */
    private static function Petakan(PesanSiaran $s): array
    {
        return [
            'Uuid' => $s->Uuid,
            'Judul' => $s->Judul,
            'Kanal' => $s->Kanal->value,
            'Status' => $s->Status->value,
            'LabelStatus' => $s->Status->AmbilLabel(),
            'JadwalKirimPada' => $s->JadwalKirimPada->toIso8601String(),
            'SelesaiPada' => $s->SelesaiPada?->toIso8601String(),
            'JumlahPenerima' => $s->JumlahPenerima,
            'JumlahTerkirim' => $s->JumlahTerkirim,
            'JumlahGagal' => $s->JumlahGagal,
            'JumlahDilewati' => $s->JumlahDilewati,
        ];
    }
}
