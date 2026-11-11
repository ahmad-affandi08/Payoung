import { router } from '@inertiajs/react';
import { useState } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import { LabelPembulatan, RapikanPersen } from '@/Pustaka/HargaOtomatis';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { FormatRupiah } from '@/Pustaka/Format';
import { KirimJson } from '@/Pustaka/PermintaanJson';
import type { PratinjauHargaDariModal, StatusHargaDariModal } from '@/Tipe/Katalog';

const Alamat = '/kelola/produk/harga-dari-modal';
const PilihanPembulatan = [0, 100, 500, 1000];

/** Alasan produk yang tidak ikut berubah, dalam kalimat sehari-hari. */
const AlasanDilewati: Record<Exclude<StatusHargaDariModal, 'Berubah'>, string> = {
    Sama: 'Harganya sudah pas',
    TanpaHargaBeli: 'Belum pernah dibeli',
    TanpaHarga: 'Belum punya harga jual',
    DilewatiJenis: 'Bukan produk berstok biasa',
};

/** Perubahan harga bertanda: "+9,1%" atau "-12,5%". */
function FormatPerubahan(nilai: string): string {
    return nilai.startsWith('-') ? `${RapikanPersen(nilai)}%` : `+${RapikanPersen(nilai)}%`;
}

type PropsDialogHargaDariModal = {
    /** Uuid produk terpilih. */
    uuid: string[];
    saatTutup: () => void;
    saatSelesai: () => void;
};

/**
 * "Harga dari harga beli" (D-266): harga jual produk terpilih dihitung dari harga beli terakhir ditambah untung persen.
 * Alurnya: isi untung dan pembulatan, "Lihat contoh" (pratinjau dari server, tidak menyimpan), lalu "Terapkan".
 * Mengubah isian apa pun membatalkan contoh, jadi yang diterapkan selalu persis yang terlihat.
 */
export default function DialogHargaDariModal({ uuid, saatTutup, saatSelesai }: PropsDialogHargaDariModal) {
    const [persen, AturPersen] = useState('20');
    const [pembulatan, AturPembulatan] = useState('500');
    const [pratinjau, AturPratinjau] = useState<PratinjauHargaDariModal | null>(null);
    const [galat, AturGalat] = useState<Record<string, string>>({});
    const [memuat, AturMemuat] = useState(false);
    const [memproses, AturMemproses] = useState(false);
    const masukan = { Uuid: uuid, PersenUntung: persen, Pembulatan: Number(pembulatan) };
    const berubah = pratinjau?.Ringkasan.Berubah ?? 0;
    const galatUmum = galat.Umum ?? galat.Uuid ?? galat.PersenUntung ?? galat.Pembulatan ?? undefined;

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
            const hasil = await KirimJson<{ Data: PratinjauHargaDariModal }>(`${Alamat}/pratinjau`, masukan);

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
        router.post(Alamat, masukan, {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
            onError: (errors) => AturGalat(errors),
            onSuccess: () => saatSelesai(),
        });

    return (
        <DialogFormulir
            judul={`Harga dari harga beli, ${uuid.length.toLocaleString('id-ID')} produk`}
            keterangan="Harga jual dihitung dari harga beli terakhir ditambah untung. Hanya harga dasar yang berubah, harga bertingkat dan daftar harga khusus tidak ikut. Setiap perubahan tercatat di riwayat harga."
            galatUmum={galatUmum}
            saatTutup={saatTutup}
            lebar="lebar"
        >
            <form
                noValidate
                className="flex flex-col gap-3"
                onSubmit={(peristiwa) => {
                    peristiwa.preventDefault();

                    if (pratinjau === null) {
                        void LihatContoh();
                    } else {
                        Terapkan();
                    }
                }}
            >
                <div className="grid gap-3 sm:grid-cols-2">
                    <BidangJumlah
                        label="Untung dari modal"
                        nilai={persen}
                        saatBerubah={Ubah(AturPersen)}
                        desimal={2}
                        digitBulat={4}
                        akhiran="%"
                        galat={galat.PersenUntung}
                        required
                        keterangan="Modal Rp 10.000 dengan untung 20% menjadi Rp 12.000."
                    />
                    <BidangPilihan
                        label="Pembulatan harga baru"
                        nilai={pembulatan}
                        opsi={PilihanPembulatan.map((n) => ({ Nilai: String(n), Label: LabelPembulatan(n) }))}
                        saatBerubah={Ubah(AturPembulatan)}
                        galat={galat.Pembulatan}
                    />
                </div>

                {pratinjau !== null ? (
                    <section aria-label="Contoh hasil" className="flex flex-col gap-2">
                        <p className="text-isi text-teks-utama">
                            {berubah > 0
                                ? `${berubah.toLocaleString('id-ID')} produk akan berubah harganya.`
                                : 'Tidak ada produk yang berubah harganya.'}
                        </p>
                        <ul
                            className="flex max-h-64 flex-col divide-y divide-garis overflow-y-auto"
                            aria-label="Contoh harga"
                        >
                            {pratinjau.Baris.map((b) => (
                                <li
                                    key={b.Uuid}
                                    className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2"
                                >
                                    <span className="min-w-0 flex-1 text-isi text-teks-utama">{b.Nama}</span>
                                    {b.Status === 'Berubah' &&
                                    b.HargaLama !== null &&
                                    b.HargaBaru !== null &&
                                    b.Modal !== null ? (
                                        <span className="text-keterangan tabular-nums text-teks-sekunder">
                                            Modal {FormatRupiah(b.Modal)} | {FormatRupiah(b.HargaLama)} ke{' '}
                                            <span className="font-semibold text-teks-utama">
                                                {FormatRupiah(b.HargaBaru)}
                                            </span>
                                            {b.PerubahanPersen !== null
                                                ? ` (${FormatPerubahan(b.PerubahanPersen)})`
                                                : ''}
                                        </span>
                                    ) : (
                                        <span className="text-keterangan text-teks-sekunder">
                                            {AlasanDilewati[b.Status as Exclude<StatusHargaDariModal, 'Berubah'>]}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}

                <DialogFooter className="sm:justify-start">
                    {pratinjau === null ? (
                        <Tombol type="submit" memproses={memuat} disabled={persen.trim() === ''}>
                            Lihat contoh
                        </Tombol>
                    ) : (
                        <Tombol type="submit" memproses={memproses} disabled={berubah === 0}>
                            Terapkan harga baru
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
