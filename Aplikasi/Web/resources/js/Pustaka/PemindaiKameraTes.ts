import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { KameraTersedia, MulaiPemindaian, PesanGalatKamera } from './PemindaiKamera';

const zxing = vi.hoisted(() => ({
    reset: vi.fn(),
    decodeFromStream: vi.fn(),
    petunjuk: null as Map<string, number[]> | null,
}));

vi.mock('@zxing/library', () => ({
    BarcodeFormat: {
        EAN_13: 1,
        EAN_8: 2,
        UPC_A: 3,
        UPC_E: 4,
        CODE_128: 5,
        CODE_39: 6,
        ITF: 7,
        CODABAR: 8,
        QR_CODE: 9,
        DATA_MATRIX: 10,
    },
    DecodeHintType: { POSSIBLE_FORMATS: 'F', TRY_HARDER: 'T' },
    BrowserMultiFormatReader: class {
        reset = zxing.reset;
        decodeFromStream = zxing.decodeFromStream;

        constructor(petunjuk: Map<string, number[]>) {
            zxing.petunjuk = petunjuk;
        }
    },
}));

type TiruanJalur = { stop: ReturnType<typeof vi.fn>; getSettings: () => { deviceId: string } };

function SiapkanKamera(): { jalur: TiruanJalur; GetUserMedia: ReturnType<typeof vi.fn>; video: HTMLVideoElement } {
    const jalur: TiruanJalur = { stop: vi.fn(), getSettings: () => ({ deviceId: 'KAM-1' }) };
    const stream = { getTracks: () => [jalur], getVideoTracks: () => [jalur] };
    const GetUserMedia = vi.fn().mockResolvedValue(stream);
    const video = document.createElement('video');

    Object.defineProperty(navigator, 'mediaDevices', { value: { getUserMedia: GetUserMedia }, configurable: true });
    video.play = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(video, 'readyState', { value: 4, configurable: true });

    return { jalur, GetUserMedia, video };
}

const formatDiminta: { daftar: string[] } = { daftar: [] };

function PasangPendeteksi(formatDidukung: string[], hasil: () => { rawValue: string }[]): void {
    class Pendeteksi {
        static getSupportedFormats = () => Promise.resolve(formatDidukung);
        detect = () => Promise.resolve(hasil());

        constructor(opsi: { formats: string[] }) {
            formatDiminta.daftar = opsi.formats;
        }
    }

    (globalThis as { BarcodeDetector?: unknown }).BarcodeDetector = Pendeteksi;
}

describe('Pustaka/PemindaiKamera (F-03 BR-03.1: pindai barcode dengan kamera)', () => {
    beforeEach(() => {
        zxing.reset.mockClear();
        zxing.decodeFromStream.mockReset();
    });

    afterEach(() => {
        delete (globalThis as { BarcodeDetector?: unknown }).BarcodeDetector;
        vi.useRealTimers();
    });

    it('KameraTersedia: butuh navigator.mediaDevices.getUserMedia (halaman https)', () => {
        Object.defineProperty(navigator, 'mediaDevices', { value: undefined, configurable: true });
        expect(KameraTersedia()).toBe(false);

        SiapkanKamera();
        expect(KameraTersedia()).toBe(true);
    });

    it('masalah kamera diterjemahkan ke bahasa sehari-hari dengan langkah yang bisa dilakukan', () => {
        expect(PesanGalatKamera(new DOMException('x', 'NotAllowedError'))).toContain('Izin kamera ditolak');
        expect(PesanGalatKamera(new DOMException('x', 'NotFoundError'))).toContain('Kamera tidak ditemukan');
        expect(PesanGalatKamera(new DOMException('x', 'NotReadableError'))).toContain('dipakai aplikasi lain');
        expect(PesanGalatKamera(new DOMException('x', 'NotSupportedError'))).toContain('https');
        expect(PesanGalatKamera(new Error('lain'))).toContain('Kamera tidak bisa dibuka');
        expect(PesanGalatKamera('bukan masalah')).toContain('Kamera tidak bisa dibuka');
    });

    it('tanpa kamera/halaman aman: melempar NotSupportedError sebelum menyentuh perangkat', async () => {
        Object.defineProperty(navigator, 'mediaDevices', { value: undefined, configurable: true });

        await expect(
            MulaiPemindaian({ video: document.createElement('video'), saatTerbaca: vi.fn() }),
        ).rejects.toMatchObject({
            name: 'NotSupportedError',
        });
    });

    it('izin kamera ditolak: masalah diteruskan dan tidak ada kamera yang tertinggal menyala', async () => {
        const { GetUserMedia, video } = SiapkanKamera();

        GetUserMedia.mockRejectedValueOnce(new DOMException('ditolak', 'NotAllowedError'));
        await expect(MulaiPemindaian({ video, saatTerbaca: vi.fn() })).rejects.toMatchObject({
            name: 'NotAllowedError',
        });
    });

    it('BarcodeDetector bawaan: minta kamera belakang, baca barcode, matikan kamera saat Berhenti', async () => {
        const { jalur, GetUserMedia, video } = SiapkanKamera();
        const Terbaca = vi.fn();

        PasangPendeteksi(['ean_13', 'code_128', 'qr_code'], () => [{ rawValue: ' 8991234567890 ' }]);

        const pemindai = await MulaiPemindaian({ video, saatTerbaca: Terbaca });

        await vi.waitFor(() => expect(Terbaca).toHaveBeenCalledWith('8991234567890'));
        expect(GetUserMedia.mock.calls[0]?.[0]).toMatchObject({ video: { facingMode: { ideal: 'environment' } } });
        expect(pemindai.IdPerangkat).toBe('KAM-1');
        expect(zxing.decodeFromStream).not.toHaveBeenCalled();

        pemindai.Berhenti();
        pemindai.Berhenti();
        expect(jalur.stop).toHaveBeenCalledTimes(1);
        expect(video.srcObject).toBeNull();

        const jumlah = Terbaca.mock.calls.length;
        await new Promise((selesai) => setTimeout(selesai, 400));
        expect(Terbaca.mock.calls.length).toBe(jumlah);
    });

    it('kamera tertentu dipilih dengan deviceId persis', async () => {
        const { GetUserMedia, video } = SiapkanKamera();

        PasangPendeteksi(['ean_13'], () => []);
        (await MulaiPemindaian({ video, idPerangkat: 'KAM-DEPAN', saatTerbaca: vi.fn() })).Berhenti();

        expect(GetUserMedia.mock.calls[0]?.[0]).toMatchObject({ video: { deviceId: { exact: 'KAM-DEPAN' } } });
    });

    it('peramban tanpa BarcodeDetector (iPhone/Safari) memakai ZXing yang dimuat malas', async () => {
        const { jalur, video } = SiapkanKamera();
        const Terbaca = vi.fn();

        zxing.decodeFromStream.mockImplementation(
            (_stream: unknown, _video: unknown, panggil: (h?: { getText: () => string }) => void) => {
                panggil(undefined);
                panggil({ getText: () => '2000000000015' });

                return Promise.resolve();
            },
        );

        const pemindai = await MulaiPemindaian({ video, saatTerbaca: Terbaca });

        expect(zxing.decodeFromStream).toHaveBeenCalledTimes(1);
        expect(Terbaca).toHaveBeenCalledTimes(1);
        expect(Terbaca).toHaveBeenCalledWith('2000000000015');

        pemindai.Berhenti();
        expect(zxing.reset).toHaveBeenCalled();
        expect(jalur.stop).toHaveBeenCalledTimes(1);
    });

    it('BarcodeDetector tanpa EAN-13 (mesin tidak lengkap) jatuh ke ZXing, bukan pindai yang tak pernah terbaca', async () => {
        const { video } = SiapkanKamera();

        PasangPendeteksi(['qr_code'], () => []);
        zxing.decodeFromStream.mockResolvedValue(undefined);
        (await MulaiPemindaian({ video, saatTerbaca: vi.fn() })).Berhenti();

        expect(zxing.decodeFromStream).toHaveBeenCalledTimes(1);
    });

    it('jenis Seri: BarcodeDetector diminta membaca QR, DataMatrix, dan Codabar selain barcode barang', async () => {
        const { video } = SiapkanKamera();

        PasangPendeteksi(['code_128', 'ean_13', 'qr_code', 'data_matrix', 'codabar', 'aztec'], () => []);
        (await MulaiPemindaian({ video, jenisKode: 'Seri', saatTerbaca: vi.fn() })).Berhenti();

        expect(formatDiminta.daftar).toEqual(expect.arrayContaining(['code_128', 'qr_code', 'data_matrix', 'codabar']));
        expect(formatDiminta.daftar).not.toContain('aztec');
        expect(zxing.decodeFromStream).not.toHaveBeenCalled();
    });

    it('jenis Produk: QR tidak diminta (hanya barcode 1D barang)', async () => {
        const { video } = SiapkanKamera();

        PasangPendeteksi(['ean_13', 'code_128', 'qr_code'], () => []);
        (await MulaiPemindaian({ video, saatTerbaca: vi.fn() })).Berhenti();

        expect(formatDiminta.daftar).not.toContain('qr_code');
    });

    it('jenis Seri: mesin tanpa Code 128 (hanya QR) jatuh ke ZXing, yang diberi petunjuk format nomor seri', async () => {
        const { video } = SiapkanKamera();

        PasangPendeteksi(['qr_code'], () => []);
        zxing.decodeFromStream.mockResolvedValue(undefined);
        (await MulaiPemindaian({ video, jenisKode: 'Seri', saatTerbaca: vi.fn() })).Berhenti();

        // BarcodeFormat tiruan: CODE_128 = 5, CODABAR = 8, QR_CODE = 9, DATA_MATRIX = 10.
        expect(zxing.decodeFromStream).toHaveBeenCalledTimes(1);
        expect(zxing.petunjuk?.get('F')).toEqual(expect.arrayContaining([5, 8, 9, 10]));
    });
});
