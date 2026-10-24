import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanMetodePembayaran from '@/Halaman/Kelola/PanduanAwal/MetodePembayaran';
import { AturHalamanUji, RenderUji } from '@/Komponen/Katalog/TiruanInertia';
import { BacaQrDariBerkas } from '@/Pustaka/PembacaQris';
import type { PropsMetodePembayaranPanduan } from '@/Tipe/PanduanAwal';

import { BuatProgresContoh } from './DataUjiPanduan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);
vi.mock('@/Pustaka/PembacaQris', () => ({ BacaQrDariBerkas: vi.fn() }));

/*
 * Audit kemudahan pakai: halaman Metode pembayaran tetap punya rumah setelah panduan selesai (Pengaturan › Kasir &
 * struk) — tampil di tata letak back-office biasa, bukan sebagai langkah panduan.
 */
function BuatProps(selesaiPada: string | null): PropsMetodePembayaranPanduan {
    return {
        Progres: { ...BuatProgresContoh(), SelesaiPada: selesaiPada },
        MetodePembayaran: [],
        JenisTersedia: [{ Nilai: 'QrisStatis', Label: 'QRIS statis' }],
        Bank: [],
        BatasGambarQris: { UkuranMaksimalKb: 1024, Ekstensi: ['png'] },
        GerbangPembayaran: { Aktif: false, Penyedia: null },
    };
}

beforeEach(() => AturHalamanUji({}, '/kelola/panduan-awal/metode-pembayaran'));
afterEach(cleanup);

describe('Halaman metode pembayaran', () => {
    it('panduan sudah selesai: tampil sebagai halaman Pengaturan, tanpa tombol langkah panduan', () => {
        RenderUji(<HalamanMetodePembayaran {...BuatProps('2026-10-01T01:00:00Z')} />);

        expect(screen.getAllByText('Metode pembayaran').length).toBeGreaterThan(0);
        expect(screen.queryByRole('button', { name: 'Lanjutkan' })).toBeNull();
    });

    it('panduan belum selesai: tetap langkah panduan dengan tombol Lanjutkan', () => {
        RenderUji(<HalamanMetodePembayaran {...BuatProps(null)} />);

        expect(screen.getByRole('button', { name: 'Lanjutkan' })).not.toBeNull();
    });
});

/*
 * D-189: QRIS statis toko dibuat berperilaku seperti dinamis. Gambar yang dipilih dibaca di peramban; hanya QRIS statis
 * yang sah yang membuat kasir menampilkan QRIS dengan nominal terisi.
 */
describe('QRIS statis dengan nominal otomatis', () => {
    const vektor = JSON.parse(
        readFileSync(resolve(process.cwd(), '../../Spesifikasi/VektorUjiQris/QrisDinamis.json'), 'utf8'),
    ) as { Kasus: { Statis: string }[]; TidakSah: { Teks: string }[] };

    beforeEach(() => {
        URL.createObjectURL = vi.fn(() => 'blob:pratinjau');
        URL.revokeObjectURL = vi.fn();
    });

    function PilihGambar() {
        const masukan = screen.getByLabelText(/Gambar QRIS/);
        fireEvent.change(masukan, { target: { files: [new File(['x'], 'qris.png', { type: 'image/png' })] } });
    }

    it('gambar terbaca dan sah: pemilik diberi tahu nama merchant dan nominal akan terisi otomatis', async () => {
        vi.mocked(BacaQrDariBerkas).mockResolvedValue(vektor.Kasus[0]?.Statis ?? '');
        RenderUji(<HalamanMetodePembayaran {...BuatProps('2026-10-01T01:00:00Z')} />);

        PilihGambar();

        expect(await screen.findByText('QRIS terbaca, nominal akan terisi otomatis')).not.toBeNull();
        expect(screen.getByText(/TOKO SEMBAKO MAJU JAYA/)).not.toBeNull();
    });

    it('gambar tidak terbaca, atau bukan QRIS statis yang sah: peringatan jelas, gambar tetap boleh dipakai', async () => {
        vi.mocked(BacaQrDariBerkas).mockResolvedValueOnce(null);
        RenderUji(<HalamanMetodePembayaran {...BuatProps('2026-10-01T01:00:00Z')} />);
        PilihGambar();
        expect(await screen.findByText('Nominal belum bisa otomatis')).not.toBeNull();
        expect(screen.getByText(/Kode QR belum terbaca/)).not.toBeNull();

        vi.mocked(BacaQrDariBerkas).mockResolvedValueOnce(vektor.TidakSah[3]?.Teks ?? '');
        PilihGambar();
        await waitFor(() => expect(screen.getByText(/checksum tidak cocok/)).not.toBeNull());
    });

    it('daftar metode: QRIS statis lama menawarkan "Isi nominal otomatis"; yang sudah aktif menawarkan "Matikan"', () => {
        const dasar = {
            Jenis: 'QrisStatis' as const,
            LabelJenis: 'QRIS statis',
            NamaBank: null,
            NomorRekening: null,
            NamaPemilikRekening: null,
            PersenBiaya: '0',
            TautanGambarQris: null,
            Aktif: true,
            Wajib: false,
        };
        const props = BuatProps('2026-10-01T01:00:00Z');
        RenderUji(
            <HalamanMetodePembayaran
                {...props}
                MetodePembayaran={[
                    { ...dasar, Uuid: 'a', Nama: 'QRIS Toko Lama', NominalOtomatis: false },
                    { ...dasar, Uuid: 'b', Nama: 'QRIS Toko Baru', NominalOtomatis: true },
                ]}
            />,
        );

        expect(screen.getByText(/Pelanggan mengetik nominal sendiri/)).not.toBeNull();
        expect(screen.getByRole('button', { name: 'Isi nominal otomatis' })).not.toBeNull();
        expect(screen.getByText(/Nominal terisi otomatis di kasir/)).not.toBeNull();
        expect(screen.getByRole('button', { name: 'Matikan' })).not.toBeNull();
    });
});
