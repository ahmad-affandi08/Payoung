import { router } from '@inertiajs/react';
import { useState } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import PilihanCari from '@/Komponen/Formulir/PilihanCari';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import PemilihProduk from '@/Komponen/Katalog/PemilihProduk';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import type { AturanHargaOtomatis, PropsHargaOtomatis } from '@/Tipe/Katalog';

const AlamatAturan = '/kelola/produk/harga-otomatis/aturan';

/** Label pembulatan sama dengan dialog harga massal supaya pemilik toko tidak menemukan dua cara menyebut hal yang sama. */
export function LabelPembulatan(nilai: number): string {
    return nilai === 0 ? 'Tanpa pembulatan' : `Dibulatkan ke Rp ${nilai.toLocaleString('id-ID')} terdekat`;
}

/** Persen tanpa ekor nol: "20.0000" → "20", "7.5000" → "7,5". */
export function RapikanPersen(nilai: string): string {
    const bersih = nilai.includes('.') ? nilai.replace(/\.?0+$/, '') : nilai;

    return bersih.replace('.', ',');
}

type PropsDialogAturan = {
    /** Untung khusus yang diubah; null = baru. */
    aturan: AturanHargaOtomatis | null;
    opsi: Pick<PropsHargaOtomatis, 'OpsiKategori'>;
    /** Pembulatan dan arah selalu sama dengan pengaturan utama, jadi tidak ditanyakan lagi di sini. */
    umum: PropsHargaOtomatis['Umum'];
    saatTutup: () => void;
};

/**
 * Satu aturan harga otomatis (D-266): cakupan (semua produk, satu kategori, atau satu produk), untung dalam persen dari
 * modal, pembulatan, dan arah perubahan. Menyimpan aturan tidak mengubah harga apa pun, harga baru berubah di
 * penerimaan barang berikutnya atau lewat "Terapkan sekarang".
 */
export default function DialogAturanHargaOtomatis({ aturan, opsi, umum, saatTutup }: PropsDialogAturan) {
    const [cakupan, AturCakupan] = useState<string>(aturan?.Cakupan ?? 'Kategori');
    const [uuidKategori, AturUuidKategori] = useState(aturan?.UuidKategori ?? '');
    const [produk, AturProduk] = useState<{ Uuid: string; Nama: string } | null>(
        aturan?.Produk ? { Uuid: aturan.Produk.Uuid, Nama: aturan.Produk.Nama } : null,
    );
    const [persen, AturPersen] = useState(aturan === null ? '20' : aturan.PersenUntung.replace(/\.?0+$/, ''));
    const [galat, AturGalat] = useState<Record<string, string>>({});
    const [memproses, AturMemproses] = useState(false);
    const baru = aturan === null;
    const siap =
        persen.trim() !== '' &&
        (cakupan === 'Semua' ||
            (cakupan === 'Kategori' && uuidKategori !== '') ||
            (cakupan === 'Produk' && produk !== null));

    const Simpan = () => {
        const isi = {
            Cakupan: cakupan,
            UuidKategori: cakupan === 'Kategori' ? uuidKategori : null,
            UuidProduk: cakupan === 'Produk' ? (produk?.Uuid ?? null) : null,
            PersenUntung: persen,
            Pembulatan: umum.Pembulatan,
            Arah: umum.Arah,
            Aktif: true,
        };
        const pilihan = {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
            onError: (errors: Record<string, string>) => AturGalat(errors),
            onSuccess: () => saatTutup(),
        };

        if (baru) {
            router.post(AlamatAturan, isi, pilihan);
        } else {
            router.put(`${AlamatAturan}/${aturan.Uuid}`, isi, pilihan);
        }
    };

    return (
        <DialogFormulir
            judul={baru ? 'Tambah untung khusus' : 'Ubah untung khusus'}
            keterangan="Untuk kategori atau produk yang untungnya berbeda dari pengaturan utama."
            galatUmum={galat.Umum}
            saatTutup={saatTutup}
        >
            <form
                noValidate
                className="flex flex-col gap-3"
                onSubmit={(peristiwa) => {
                    peristiwa.preventDefault();
                    Simpan();
                }}
            >
                <BidangPilihan
                    label="Berlaku untuk"
                    nilai={cakupan}
                    opsi={[
                        { Nilai: 'Kategori', Label: 'Satu kategori' },
                        { Nilai: 'Produk', Label: 'Satu produk' },
                    ]}
                    saatBerubah={AturCakupan}
                    galat={galat.Cakupan}
                    disabled={!baru}
                    required
                />
                {cakupan === 'Kategori' ? (
                    <PilihanCari
                        label="Kategori"
                        nilai={uuidKategori}
                        opsi={opsi.OpsiKategori.map((k) => ({ Nilai: k.Uuid, Label: k.Nama }))}
                        placeholder="Pilih kategori"
                        aria-label="Kategori"
                        saatBerubah={AturUuidKategori}
                        galat={galat.UuidKategori}
                        disabled={!baru}
                        required
                    />
                ) : null}
                {cakupan === 'Kategori' ? (
                    <p className="-mt-2 text-keterangan text-teks-sekunder">
                        Subkategori ikut aturan ini kecuali punya aturan sendiri.
                    </p>
                ) : null}
                {cakupan === 'Produk' ? (
                    <div className="flex flex-col gap-1">
                        <PemilihProduk
                            label={produk === null ? 'Produk' : `Produk: ${produk.Nama}`}
                            jenis={['Stok']}
                            saatPilih={(terpilih) => AturProduk({ Uuid: terpilih.Uuid, Nama: terpilih.Nama })}
                            galat={galat.UuidProduk}
                            disabled={!baru}
                            keterangan="Hanya produk berstok yang harganya tidak diketik kasir."
                        />
                    </div>
                ) : null}
                <BidangJumlah
                    label="Untung dari harga beli"
                    nilai={persen}
                    saatBerubah={AturPersen}
                    desimal={2}
                    digitBulat={4}
                    akhiran="%"
                    galat={galat.PersenUntung}
                    required
                    keterangan="Harga beli Rp 10.000 dengan untung 25% menjadi harga jual Rp 12.500."
                />
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" memproses={memproses} disabled={!siap}>
                        Simpan
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
