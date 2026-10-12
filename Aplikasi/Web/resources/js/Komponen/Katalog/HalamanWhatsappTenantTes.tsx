import { cleanup, fireEvent, screen } from '@testing-library/react';
import type { ComponentProps } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import HalamanWhatsapp, {
    FormatPeriodeKuota,
    IsiAwalPengaturanWhatsapp,
    TingkatKuota,
} from '@/Halaman/Kelola/Pengaturan/Whatsapp';
import { AturHalamanUji, kirimanForm, RenderUji, tiruanRouter } from '@/Komponen/Katalog/TiruanInertia';
import type { KuotaWhatsapp } from '@/Tipe/WhatsappTenant';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

const penyedia: ComponentProps<typeof HalamanWhatsapp>['DaftarPenyedia'] = [
    {
        Nilai: 'Fonnte',
        Label: 'Fonnte',
        Keterangan: 'Tidak resmi (WhatsApp Web): murah dan mudah.',
        BidangPengaturan: [],
        BidangKredensial: [{ Kunci: 'Token', Label: 'Token perangkat Fonnte', Wajib: true }],
    },
    {
        Nilai: 'Wablas',
        Label: 'Wablas',
        Keterangan: 'Tidak resmi.',
        BidangPengaturan: [
            {
                Kunci: 'Domain',
                Label: 'Domain server Wablas',
                Jenis: 'Url',
                Wajib: true,
                Bawaan: 'https://jkt.wablas.com',
            },
        ],
        BidangKredensial: [{ Kunci: 'Token', Label: 'Token Wablas', Wajib: true }],
    },
];

const BuatKuota = (terpakai: number, batas: number | null): KuotaWhatsapp => ({
    Periode: '2026-10',
    Terpakai: terpakai,
    Batas: batas,
    Sisa: batas === null ? null : Math.max(0, batas - terpakai),
});

const BuatProps = (
    timpa: Partial<ComponentProps<typeof HalamanWhatsapp>> = {},
): ComponentProps<typeof HalamanWhatsapp> => ({
    Kuota: BuatKuota(40, 100),
    NomorPayoungTersedia: true,
    Milik: null,
    DaftarPenyedia: penyedia,
    ...timpa,
});

afterEach(cleanup);
beforeEach(() => AturHalamanUji({}, '/kelola/pengaturan/whatsapp'));

describe('D-108 fungsi kuota', () => {
    it('periode diformat bulan Indonesia, tingkat kuota aman/hampir/habis/tanpa batas', () => {
        expect(FormatPeriodeKuota('2026-10')).toBe('Oktober 2026');
        expect(TingkatKuota(BuatKuota(10, 100))).toBe('aman');
        expect(TingkatKuota(BuatKuota(80, 100))).toBe('hampir');
        expect(TingkatKuota(BuatKuota(100, 100))).toBe('habis');
        expect(TingkatKuota(BuatKuota(0, 0))).toBe('habis');
        expect(TingkatKuota(BuatKuota(5000, null))).toBe('tanpa-batas');
    });

    it('isian awal pengaturan memakai nilai tersimpan, lalu bawaan penyedia', () => {
        expect(IsiAwalPengaturanWhatsapp(penyedia[1]?.BidangPengaturan ?? [], null)).toEqual({
            Domain: 'https://jkt.wablas.com',
        });
        expect(
            IsiAwalPengaturanWhatsapp(penyedia[1]?.BidangPengaturan ?? [], { Domain: 'https://solo.wablas.com' }),
        ).toEqual({
            Domain: 'https://solo.wablas.com',
        });
    });
});

describe('D-108 halaman Pengaturan › WhatsApp', () => {
    it('menampilkan pemakaian kuota bulan ini tanpa peringatan saat masih aman', () => {
        RenderUji(<HalamanWhatsapp {...BuatProps()} />);

        expect(screen.getByText('40 / 100')).toBeTruthy();
        expect(screen.getByText(/pesan terpakai di Oktober 2026/)).toBeTruthy();
        expect(
            screen.getByRole('progressbar', { name: 'Pemakaian kuota pesan WhatsApp' }).getAttribute('aria-valuenow'),
        ).toBe('40');
        expect(screen.queryByText('Kuota bulan ini habis')).toBeNull();
        expect(screen.queryByText('Kuota hampir habis')).toBeNull();
    });

    it('memperingatkan saat hampir habis dan saat habis (dengan jalan keluar)', () => {
        RenderUji(<HalamanWhatsapp {...BuatProps({ Kuota: BuatKuota(90, 100) })} />);
        expect(screen.getByText('Kuota hampir habis')).toBeTruthy();
        cleanup();

        RenderUji(<HalamanWhatsapp {...BuatProps({ Kuota: BuatKuota(100, 100) })} />);
        expect(screen.getByText('Kuota bulan ini habis')).toBeTruthy();
        expect(screen.getByText(/pasang WhatsApp sendiri/)).toBeTruthy();
    });

    it('tanpa batas menampilkan jumlah terkirim saja; nomor Payoung belum tersambung diberi tahu', () => {
        RenderUji(<HalamanWhatsapp {...BuatProps({ Kuota: BuatKuota(1234, null), NomorPayoungTersedia: false })} />);

        expect(screen.getByText('1.234')).toBeTruthy();
        expect(screen.getByText(/tanpa batas/)).toBeTruthy();
        expect(screen.queryByRole('progressbar')).toBeNull();
        expect(screen.getByText(/Nomor WhatsApp Payoung belum tersambung/)).toBeTruthy();
    });

    it('formulir menyimpan penyedia, pengaturan, dan kredensial ke endpoint tenant', () => {
        RenderUji(<HalamanWhatsapp {...BuatProps()} />);

        fireEvent.change(screen.getAllByLabelText(/Token perangkat Fonnte/)[0] as HTMLElement, {
            target: { value: 'tokenFonnteBaru-1234' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan WhatsApp sendiri' }));

        expect(kirimanForm).toContainEqual({
            metode: 'post',
            url: '/kelola/pengaturan/whatsapp',
            data: { Penyedia: 'Fonnte', Pengaturan: {}, Kredensial: { Token: 'tokenFonnteBaru-1234' } },
        });
    });

    it('WhatsApp sendiri tersimpan: tombol aktifkan nonaktif sebelum uji berhasil, petunjuk kredensial tampil, tombol uji memanggil server', () => {
        RenderUji(
            <HalamanWhatsapp
                {...BuatProps({
                    Milik: {
                        Uuid: '01K5WA00000000000000000001',
                        Penyedia: 'Fonnte',
                        LabelPenyedia: 'Fonnte',
                        Pengaturan: {},
                        PetunjukKredensial: { Token: '••••4521' },
                        StatusUji: 'BelumDiuji',
                        LabelStatusUji: 'Belum diuji',
                        PesanUji: null,
                        DiujiPada: null,
                        Aktif: false,
                    },
                })}
            />,
        );

        expect(screen.getByText(/Tersimpan ••••4521/)).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Aktifkan WhatsApp sendiri' }).hasAttribute('disabled')).toBe(true);

        fireEvent.click(screen.getByRole('button', { name: 'Uji koneksi' }));
        expect(tiruanRouter.post).toHaveBeenCalledWith('/kelola/pengaturan/whatsapp/uji', {}, expect.anything());
    });
});
