<?php

declare(strict_types=1);

use App\Domain\Bersama\Audit\Layanan\KamusPeristiwaAudit;
use App\Domain\Bersama\Audit\Layanan\PenerjemahPerubahanAudit;

describe('KamusPeristiwaAudit', function (): void {
    it('setiap kunci peristiwa yang dicatat di kode punya kalimat di kamus', function (): void {
        $akar = dirname(__DIR__, 3).'/app';
        $belumAda = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($akar, FilesystemIterator::SKIP_DOTS)) as $berkas) {
            if ($berkas->getExtension() !== 'php') {
                continue;
            }

            $isi = (string) file_get_contents($berkas->getPathname());
            preg_match_all('/->(?:Catat|CatatSesi)\(\s*(?:peristiwa:\s*|aksi:\s*)?[\'"]([a-z0-9]+(?:[.-][a-z0-9]+)+)[\'"]/', $isi, $cocok);

            foreach ($cocok[1] as $kunci) {
                if (! KamusPeristiwaAudit::CekDikenal($kunci)) {
                    $belumAda[$kunci] = $berkas->getBasename();
                }
            }
        }

        expect($belumAda)->toBe([], 'Tambahkan kalimatnya di KamusPeristiwaAudit::LABEL.');
    });

    it('kalimat tidak kosong dan tidak memuat titik pemisah kunci', function (): void {
        foreach (KamusPeristiwaAudit::LABEL as $kunci => $kalimat) {
            expect(trim($kalimat))->not->toBe('', $kunci)
                ->and($kalimat)->not->toContain('.', $kunci);
        }
    });

    it('Label memakai kamus, kunci tak dikenal dibaca lewat cadangan', function (): void {
        expect(KamusPeristiwaAudit::Label('sesi.masuk'))->toBe('Pengguna masuk')
            ->and(KamusPeristiwaAudit::Label('gudang-baru.dibekukan'))->toBe('Dibekukan gudang baru');
    });

    it('CariKunci mencari lewat kalimat tanpa membedakan huruf besar/kecil', function (): void {
        expect(KamusPeristiwaAudit::CariKunci('PENGGUNA MASUK'))->toContain('sesi.masuk')
            ->and(KamusPeristiwaAudit::CariKunci('   '))->toBe([]);
    });

    it('LabelObjek menulis nama model sebagai kalimat', function (): void {
        expect(KamusPeristiwaAudit::LabelObjek('TiketDukungan', 12))->toBe('Tiket dukungan #12')
            ->and(KamusPeristiwaAudit::LabelObjek('Outlet', null))->toBe('Outlet')
            ->and(KamusPeristiwaAudit::LabelObjek(null, 3))->toBeNull();
    });
});

describe('PenerjemahPerubahanAudit', function (): void {
    it('hanya mengembalikan bidang yang berubah, dengan label dan nilai terbaca', function (): void {
        $hasil = PenerjemahPerubahanAudit::Terjemahkan(
            ['Nama' => 'Kopi', 'Aktif' => true, 'IdPenanggungJawab' => 4],
            ['Nama' => 'Kopi Susu', 'Aktif' => false, 'IdPenanggungJawab' => 4],
        );

        expect($hasil)->toBe([
            ['Bidang' => 'Nama', 'Lama' => 'Kopi', 'Baru' => 'Kopi Susu'],
            ['Bidang' => 'Aktif', 'Lama' => 'Ya', 'Baru' => 'Tidak'],
        ]);
    });

    it('bidang baru tidak punya nilai lama (null), dan tanpa data hasilnya kosong', function (): void {
        expect(PenerjemahPerubahanAudit::Terjemahkan(null, ['IdPenanggungJawab' => 7]))
            ->toBe([['Bidang' => 'Penanggung jawab', 'Lama' => null, 'Baru' => '7']])
            ->and(PenerjemahPerubahanAudit::Terjemahkan(null, null))->toBe([]);
    });

    it('rahasia tidak pernah ditampilkan dan tanggal ISO ditulis dalam WIB', function (): void {
        $hasil = PenerjemahPerubahanAudit::Terjemahkan(
            ['KataSandi' => 'lama123', 'BerlakuSampai' => '2026-10-01T00:00:00+00:00'],
            ['KataSandi' => 'baru456', 'BerlakuSampai' => '2026-10-02T03:00:00+00:00'],
        );

        expect(json_encode($hasil))->not->toContain('lama123')->not->toContain('baru456')
            ->and($hasil[0]['Baru'])->toBe('••••')
            ->and($hasil[1]['Baru'])->toContain('10.00 WIB');
    });

    it('nilai panjang dipotong dan jumlah baris dibatasi', function (): void {
        $lama = [];
        $baru = [];
        for ($i = 1; $i <= 30; $i++) {
            $baru["Bidang{$i}"] = str_repeat('x', 400);
        }

        $hasil = PenerjemahPerubahanAudit::Terjemahkan($lama, $baru);

        expect($hasil)->toHaveCount(20)
            ->and(mb_strlen($hasil[0]['Baru']))->toBeLessThanOrEqual(163);
    });
});
