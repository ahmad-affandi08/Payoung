import { cleanup, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { RenderUji, kirimanForm } from '@/Komponen/Katalog/TiruanInertia';

import PanelObservabilitasLanjutan, {
    AlamatRunbookAlert,
    FormTautanRunbook,
    TeksP95Rute,
    type DataLanjutan,
} from './PanelObservabilitasLanjutan';

vi.mock('@inertiajs/react', async () => (await import('@/Komponen/Katalog/TiruanInertia')).TiruanInertia);

/*
 * PGL-20 lanjutan: tiga tabel observabilitas (rute terlambat, penolakan sinkron, webhook gagal) dan alamat runbook.
 */

const data: DataLanjutan = {
    JamJendela: 24,
    MinimumPermintaanRute: 20,
    RuteTerlambat: [
        {
            Kelompok: 'Pos',
            NamaRute: 'pos.sinkron.kirim',
            Jumlah: 1200,
            GalatServer: 4,
            RataRataMs: 758,
            P95Ms: null,
            P95Lebih: true,
        },
        {
            Kelompok: 'Pemilik',
            NamaRute: 'pemilik.dasbor',
            Jumlah: 300,
            GalatServer: 0,
            RataRataMs: 120,
            P95Ms: 250,
            P95Lebih: false,
        },
    ],
    GalatSinkron: [{ JenisItem: 'Penjualan.Buat', KodeGalat: 'StokTidakCukup', Jumlah: 87 }],
    WebhookGagal: [{ NamaTenant: 'Toko Beta Webhook', JumlahGagal: 3, TerakhirPada: '2026-10-14T03:00:00Z' }],
};

describe('PanelObservabilitasLanjutan (PGL-20)', () => {
    afterEach(() => {
        cleanup();
        kirimanForm.length = 0;
    });

    it('menampilkan rute terlambat dengan p95 yang jujur (batas atas kelompok, bukan angka tepat)', () => {
        RenderUji(<PanelObservabilitasLanjutan data={data} />);
        const tabel = screen.getByRole('table', { name: 'Rute API paling lambat' });

        expect(within(tabel).getByText('pos.sinkron.kirim')).toBeTruthy();
        expect(within(tabel).getByText('lebih dari 1.600 ms')).toBeTruthy();
        expect(within(tabel).getByText('paling lama 250 ms')).toBeTruthy();
        expect(within(tabel).getByText('API Owner')).toBeTruthy();
        expect(within(tabel).getByText('758 ms')).toBeTruthy();
        expect(screen.getByText(/paling sedikit 20/)).toBeTruthy();
    });

    it('menampilkan penolakan sinkron dan webhook gagal; keadaan kosong tersedia', () => {
        RenderUji(<PanelObservabilitasLanjutan data={data} />);

        expect(
            within(screen.getByRole('table', { name: 'Penolakan sinkron terbanyak' })).getByText('StokTidakCukup'),
        ).toBeTruthy();
        expect(within(screen.getByRole('table', { name: 'Penolakan sinkron terbanyak' })).getByText('87')).toBeTruthy();
        expect(
            within(screen.getByRole('table', { name: 'Webhook keluar gagal per tenant' })).getByText(
                'Toko Beta Webhook',
            ),
        ).toBeTruthy();

        cleanup();
        RenderUji(
            <PanelObservabilitasLanjutan data={{ ...data, RuteTerlambat: [], GalatSinkron: [], WebhookGagal: [] }} />,
        );
        expect(screen.getByText('Belum ada rute dengan permintaan cukup banyak.')).toBeTruthy();
        expect(screen.getByText('Tidak ada penolakan sinkron.')).toBeTruthy();
        expect(screen.getByText('Tidak ada webhook keluar yang gagal.')).toBeTruthy();
    });

    it('tautan runbook: jangkar ditempel di belakang alamat dasar; kosong = tanpa tautan', () => {
        expect(AlamatRunbookAlert('https://wiki.payoung.id/runbook', 'outbox-macet')).toBe(
            'https://wiki.payoung.id/runbook#outbox-macet',
        );
        expect(AlamatRunbookAlert(null, 'outbox-macet')).toBeNull();
        expect(AlamatRunbookAlert('', 'outbox-macet')).toBeNull();
        expect(TeksP95Rute({ P95Ms: 400, P95Lebih: false })).toBe('paling lama 400 ms');
        expect(TeksP95Rute({ P95Ms: null, P95Lebih: false })).toBe('—');
    });

    it('form alamat runbook mengirim nilai ke server', () => {
        RenderUji(<FormTautanRunbook tautan="https://wiki.payoung.id/runbook" />);
        const bidang = screen.getByLabelText('Alamat runbook');

        expect((bidang as HTMLInputElement).value).toBe('https://wiki.payoung.id/runbook');
        fireEvent.change(bidang, { target: { value: 'https://wiki.payoung.id/baru' } });
        fireEvent.click(screen.getByRole('button', { name: 'Simpan alamat runbook' }));

        expect(kirimanForm.at(-1)).toMatchObject({
            url: '/operasional/tautan-runbook',
            data: { TautanRunbook: 'https://wiki.payoung.id/baru' },
        });
    });
});
