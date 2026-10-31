import { router } from '@inertiajs/react';
import { useState } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import GrupCentang from '@/Komponen/Formulir/GrupCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { FormatRupiah } from '@/Pustaka/Format';
import { KirimJson } from '@/Pustaka/PermintaanJson';
import type { PratinjauHargaKanal } from '@/Tipe/Katalog';

type Pilihan = { Nilai: string; Label: string };

const OpsiPlatform = [
    { nilai: 'GoFood', label: 'GoFood' },
    { nilai: 'GrabFood', label: 'GrabFood' },
    { nilai: 'ShopeeFood', label: 'ShopeeFood' },
];

const OpsiMode: Pilihan[] = [
    { Nilai: 'NaikPersen', Label: 'Lebih mahal, dalam persen (%)' },
    { Nilai: 'TurunPersen', Label: 'Lebih murah, dalam persen (%)' },
    { Nilai: 'NaikNominal', Label: 'Lebih mahal, dalam rupiah (Rp)' },
    { Nilai: 'TurunNominal', Label: 'Lebih murah, dalam rupiah (Rp)' },
];

const OpsiPembulatan: Pilihan[] = [
    { Nilai: '0', Label: 'Tanpa pembulatan' },
    { Nilai: '100', Label: 'Bulatkan ke Rp 100' },
    { Nilai: '500', Label: 'Bulatkan ke Rp 500' },
    { Nilai: '1000', Label: 'Bulatkan ke Rp 1.000' },
];

const OpsiTimpa: Pilihan[] = [
    { Nilai: 'Lewati', Label: 'Biarkan seperti sekarang' },
    { Nilai: 'Timpa', Label: 'Ganti dengan hitungan baru' },
];

const OpsiCakupanOutlet: Pilihan[] = [
    { Nilai: 'Semua', Label: 'Semua outlet' },
    { Nilai: 'Pilih', Label: 'Hanya outlet tertentu' },
];

type PropsDialogAturHargaOjol = {
    /** Outlet yang boleh dipilih pengguna (`Nilai` = Uuid). */
    Outlet: Pilihan[];
    saatTutup: () => void;
};

/**
 * "Atur harga ojol" (F-03, X8): satu dialog untuk mengisi harga GoFood/GrabFood/ShopeeFood dari harga biasa. Pemilik toko
 * tidak perlu mengerti konsep daftar harga. Alurnya: isi pilihan, "Lihat contoh" (pratinjau dari server, tidak
 * menyimpan), lalu "Terapkan". Mengubah isian apa pun membatalkan pratinjau, jadi yang diterapkan selalu sama dengan
 * yang terakhir dilihat. Hitungan memakai rumus yang sama dengan "Ubah harga" massal di daftar Produk.
 */
export default function DialogAturHargaOjol({ Outlet, saatTutup }: PropsDialogAturHargaOjol) {
    const [platform, AturPlatform] = useState<string[]>(OpsiPlatform.map((p) => p.nilai));
    const [mode, AturMode] = useState('NaikPersen');
    const [nilai, AturNilai] = useState('');
    const [pembulatan, AturPembulatan] = useState('500');
    const [timpa, AturTimpa] = useState('Lewati');
    const [cakupan, AturCakupan] = useState('Semua');
    const [outlet, AturOutlet] = useState<string[]>([]);
    const [pratinjau, AturPratinjau] = useState<PratinjauHargaKanal | null>(null);
    const [galat, AturGalat] = useState<Record<string, string>>({});
    const [memuat, AturMemuat] = useState(false);
    const [memproses, AturMemproses] = useState(false);
    const persen = mode.endsWith('Persen');
    const lebihMahal = mode.startsWith('Naik');
    const uuidOutlet = cakupan === 'Pilih' ? outlet : [];
    const masukan = {
        Kanal: platform,
        Mode: mode,
        Nilai: nilai,
        Pembulatan: Number(pembulatan),
        Timpa: timpa === 'Timpa',
        UuidOutlet: uuidOutlet,
    };
    const siap = platform.length > 0 && nilai.trim() !== '' && (cakupan === 'Semua' || outlet.length > 0);
    const galatUmum = galat.Umum ?? galat.Kanal ?? galat.UuidOutlet ?? galat.Nilai ?? galat.Mode ?? undefined;

    // Setiap perubahan isian membatalkan contoh yang sedang tampil.
    const Ubah = <T,>(Atur: (nilaiBaru: T) => void) => {
        return (nilaiBaru: T) => {
            Atur(nilaiBaru);
            AturPratinjau(null);
            AturGalat({});
        };
    };

    const LihatContoh = async () => {
        AturMemuat(true);
        AturGalat({});

        try {
            const hasil = await KirimJson<{ Data: PratinjauHargaKanal }>(
                '/kelola/daftar-harga/atur-kanal/pratinjau',
                masukan,
            );

            AturPratinjau(hasil.Data);
        } catch (galatMinta) {
            AturPratinjau(null);
            AturGalat({
                Umum: galatMinta instanceof Error ? galatMinta.message : 'Contoh belum bisa dibuat. Coba lagi.',
            });
        } finally {
            AturMemuat(false);
        }
    };

    const Terapkan = () =>
        router.post('/kelola/daftar-harga/atur-kanal', masukan, {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
            onError: (errors) => AturGalat(errors),
            onSuccess: () => saatTutup(),
        });

    const totalDiisi = pratinjau === null ? 0 : pratinjau.Kanal.reduce((jumlah, k) => jumlah + k.Diisi, 0);

    return (
        <DialogFormulir
            judul="Atur harga ojol"
            keterangan="Isi harga GoFood, GrabFood, dan ShopeeFood dari harga biasa, sekali jalan. Harga biasa di kasir tidak berubah."
            galatUmum={galatUmum}
            saatTutup={saatTutup}
            lebar="lebar"
        >
            <form
                noValidate
                className="flex flex-col gap-4"
                onSubmit={(peristiwa) => {
                    peristiwa.preventDefault();
                    void LihatContoh();
                }}
            >
                <GrupCentang
                    legenda="Untuk platform mana?"
                    opsi={OpsiPlatform}
                    terpilih={platform}
                    saatBerubah={Ubah(AturPlatform)}
                    galat={galat.Kanal}
                    required
                />
                <div className="grid gap-3 sm:grid-cols-2">
                    <BidangPilihan
                        label="Harga di platform dibanding harga biasa"
                        nilai={mode}
                        opsi={OpsiMode}
                        saatBerubah={Ubah(AturMode)}
                        galat={galat.Mode}
                        required
                    />
                    <BidangTeks
                        label={
                            persen
                                ? `${lebihMahal ? 'Lebih mahal' : 'Lebih murah'} berapa persen?`
                                : `${lebihMahal ? 'Lebih mahal' : 'Lebih murah'} berapa rupiah?`
                        }
                        nilai={nilai}
                        saatBerubah={Ubah(AturNilai)}
                        galat={galat.Nilai}
                        inputMode="decimal"
                        keterangan={persen ? 'Contoh: 30 untuk 30 persen.' : 'Contoh: 3000 untuk Rp 3.000 per produk.'}
                        autoFocus
                        required
                    />
                    <BidangPilihan
                        label="Bulatkan harga ke"
                        nilai={pembulatan}
                        opsi={OpsiPembulatan}
                        saatBerubah={Ubah(AturPembulatan)}
                        galat={galat.Pembulatan}
                    />
                    <BidangPilihan
                        label="Produk yang sudah punya harga di platform ini"
                        nilai={timpa}
                        opsi={OpsiTimpa}
                        saatBerubah={Ubah(AturTimpa)}
                    />
                </div>
                {Outlet.length > 0 ? (
                    <div className="flex flex-col gap-3">
                        <BidangPilihan
                            label="Berlaku di"
                            nilai={cakupan}
                            opsi={OpsiCakupanOutlet}
                            saatBerubah={Ubah(AturCakupan)}
                            galat={galat.UuidOutlet}
                        />
                        {cakupan === 'Pilih' ? (
                            <GrupCentang
                                legenda="Pilih outlet"
                                opsi={Outlet.map((o) => ({ nilai: o.Nilai, label: o.Label }))}
                                terpilih={outlet}
                                saatBerubah={Ubah(AturOutlet)}
                                required
                            />
                        ) : null}
                    </div>
                ) : null}

                {pratinjau !== null ? (
                    <section
                        aria-label="Contoh harga"
                        className="flex flex-col gap-3 rounded-panel border border-garis p-3"
                    >
                        <ul className="flex flex-col gap-1 text-isi text-teks-utama">
                            {pratinjau.Kanal.map((k) => (
                                <li key={k.Kanal}>
                                    <span className="font-semibold">{k.Label}</span>
                                    {': '}
                                    {k.Diisi.toLocaleString('id-ID')} produk akan diisi
                                    {k.Dilewati > 0
                                        ? `, ${k.Dilewati.toLocaleString('id-ID')} dilewati karena sudah punya harga`
                                        : ''}
                                    {k.DaftarAda ? '' : ' (daftar harganya dibuat otomatis)'}
                                </li>
                            ))}
                        </ul>
                        <div className="flex flex-col gap-1">
                            <p className="text-label font-semibold text-teks-utama">Contoh harga baru</p>
                            <ul className="flex flex-col divide-y divide-garis" aria-label="Contoh produk">
                                {pratinjau.Contoh.map((c) => (
                                    <li
                                        key={`${c.Produk}-${c.Satuan}`}
                                        className="flex flex-wrap items-baseline justify-between gap-2 py-1.5"
                                    >
                                        <span className="min-w-0 break-words text-isi text-teks-utama">
                                            {c.Produk}
                                            {c.Satuan ? (
                                                <span className="text-teks-sekunder">{` | per ${c.Satuan}`}</span>
                                            ) : null}
                                        </span>
                                        <span className="text-isi tabular-nums text-teks-utama">
                                            <span className="text-teks-sekunder">{FormatRupiah(c.HargaBiasa)}</span>
                                            {' → '}
                                            <span className="font-semibold">{FormatRupiah(c.HargaBaru)}</span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            {pratinjau.TotalSatuan > pratinjau.Contoh.length ? (
                                <p className="text-keterangan text-teks-sekunder">
                                    Dan {(pratinjau.TotalSatuan - pratinjau.Contoh.length).toLocaleString('id-ID')}{' '}
                                    produk lainnya. Harga tiap produk masih bisa diubah sesudahnya di halaman Daftar
                                    harga.
                                </p>
                            ) : null}
                        </div>
                    </section>
                ) : null}

                <p className="text-keterangan text-teks-sekunder">
                    Pastikan metode bayar untuk platform ini sudah dibuat di menu Metode pembayaran, supaya pembayaran
                    dari platform tercatat dan komisinya terhitung.
                </p>
                <DialogFooter className="sm:justify-start">
                    {pratinjau === null ? (
                        <Tombol type="submit" memproses={memuat} disabled={!siap}>
                            Lihat contoh
                        </Tombol>
                    ) : (
                        <Tombol memproses={memproses} disabled={totalDiisi === 0} onClick={Terapkan}>
                            {totalDiisi === 0 ? 'Tidak ada yang diisi' : 'Terapkan harga ojol'}
                        </Tombol>
                    )}
                    <Tombol varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
