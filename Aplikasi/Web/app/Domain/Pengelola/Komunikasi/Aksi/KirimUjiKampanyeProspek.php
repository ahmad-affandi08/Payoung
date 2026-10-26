<?php

declare(strict_types=1);

namespace App\Domain\Pengelola\Komunikasi\Aksi;

use App\Domain\Bersama\Galat\PelanggaranAturanBisnis;
use App\Domain\Bersama\Web\AlamatDomain;
use App\Domain\Pengelola\Komunikasi\Enum\SektorProspek;
use App\Domain\Pengelola\Komunikasi\Layanan\PengirimKampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Layanan\PenyusunIsiKampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Layanan\TautanBerhentiProspek;
use App\Domain\Pengelola\Komunikasi\Model\KampanyeProspek;
use App\Domain\Pengelola\Komunikasi\Surel\PesanKampanyeProspek;
use App\Domain\Pengelola\TimInternal\Layanan\PencatatAuditPengelola;
use App\Domain\Pengelola\TimInternal\Model\PenggunaPengelola;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mengirim satu email uji kampanye ke alamat petugas sendiri (D-216), lewat pengirim kampanye yang sama dengan
 * pengiriman sungguhan, supaya tampilan dan keterkiriman (SPF/DKIM) bisa dicek sebelum kampanye dijalankan. Tujuannya
 * selalu alamat petugas yang masuk, bukan alamat bebas. Tautan berhentinya contoh (tidak menuju kontak mana pun).
 */
final class KirimUjiKampanyeProspek
{
    public function __construct(private readonly PencatatAuditPengelola $audit) {}

    public function Jalankan(PenggunaPengelola $pelaku, KampanyeProspek $kampanye): string
    {
        if (! PengirimKampanyeProspek::Tersedia()) {
            throw new PelanggaranAturanBisnis('P-12', 'Belum ada pengirim email. Atur Integrasi › Email (atau Email kampanye untuk domain pengirim terpisah).', 'Umum');
        }

        $sektor = SektorProspek::tryFrom((string) ($kampanye->Segmen['Sektor'][0] ?? '')) ?? SektorProspek::JasaUmum;
        $contoh = AlamatDomain::BuatUrlAbsolutTenant(URL::signedRoute(TautanBerhentiProspek::NAMA_RUTE, ['kontak' => (string) Str::ulid()], null, false));

        try {
            PengirimKampanyeProspek::Mailer()->to($pelaku->Email)->send(new PesanKampanyeProspek(
                '[UJI] '.PenyusunIsiKampanyeProspek::Isi($kampanye->Subjek, $sektor),
                PenyusunIsiKampanyeProspek::Isi($kampanye->Isi, $sektor),
                $contoh,
                'daftar uji (email ini hanya contoh)',
                PengirimKampanyeProspek::AlamatPengirim(),
                PengirimKampanyeProspek::NamaPengirim(),
            ));
        } catch (Throwable $galat) {
            throw new PelanggaranAturanBisnis('P-12', 'Email uji gagal dikirim: '.Str::limit($galat->getMessage(), 200, ''), 'Umum');
        }

        $this->audit->Catat('prospek.kampanye.uji', $kampanye, idPelaku: $pelaku->Id);

        return $pelaku->Email;
    }
}
