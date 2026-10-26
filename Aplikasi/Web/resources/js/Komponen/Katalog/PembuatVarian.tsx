import { router } from '@inertiajs/react';
import { useState } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import type { AturanJenisProduk, BarisVarian, JenisProduk } from '@/Tipe/Katalog';
import type { Batas } from '@/Tipe/Organisasi';

import { AmbilGalatBerawalan, HitungKombinasiVarian } from './BantuanKatalog';
import Panel from '@/Komponen/Kelola/Panel';
import PenyuntingAtributVarian, { MaksimalKombinasi } from './PenyuntingAtributVarian';

/** Jenis yang boleh menjadi anak varian (DesainF03 C.1 `CekBolehAnakVarian`). */
const jenisAnakBoleh: JenisProduk[] = ['Stok', 'Produksi', 'Konsinyasi', 'Jasa', 'NonStok', 'Resep'];

/** Kunci kombinasi yang tidak peka huruf besar/kecil, urutan atribut mengikuti definisi. */
function KunciKombinasi(nilai: string[]): string {
    return nilai.map((item) => item.trim().toLowerCase()).join('|');
}

/** Kombinasi baru yang belum menjadi varian. Varian lama dicocokkan lewat nilai atributnya. */
export function HitungVarianBaru(atribut: { Nama: string; Nilai: string[] }[], varian: BarisVarian[]): string[][] {
    const ada = new Set(
        varian.map((baris) =>
            KunciKombinasi(
                atribut
                    .filter((item) => item.Nilai.length > 0)
                    .map(
                        (item) =>
                            baris.Atribut.find((a) => a.Nama.toLowerCase() === item.Nama.toLowerCase())?.Nilai ?? '',
                    ),
            ),
        ),
    );

    return HitungKombinasiVarian(atribut).filter((kombinasi) => !ada.has(KunciKombinasi(kombinasi)));
}

type PropsPembuatVarian = {
    uuidProduk: string;
    atributAwal: { Nama: string; Nilai: string[] }[];
    varian: BarisVarian[];
    jenis: AturanJenisProduk[];
    batasSku: Batas;
    bolehUbahHarga: boolean;
    galat: Record<string, string | undefined>;
};

/**
 * Pembuat varian (kombinasi kartesius) untuk produk bervarian. Idempoten: kombinasi yang sudah ada dilewati server.
 * BatasSku diperiksa semua-atau-tidak-sama-sekali, jadi pratinjau menyebut sisa kuota paket.
 */
export default function PembuatVarian({
    uuidProduk,
    atributAwal,
    varian,
    jenis,
    batasSku,
    bolehUbahHarga,
    galat,
}: PropsPembuatVarian) {
    const [atribut, AturAtribut] = useState(atributAwal);
    const [jenisAnak, AturJenisAnak] = useState<JenisProduk>('Stok');
    const [hargaDasar, AturHargaDasar] = useState('');
    const [memproses, AturMemproses] = useState(false);
    const baru = HitungVarianBaru(atribut, varian);
    const sisaKuota = batasSku.Batas === null ? null : Math.max(batasSku.Batas - batasSku.Terpakai, 0);
    const aturanAnak = jenis.find((item) => item.Nilai === jenisAnak);
    const melebihiKuota = sisaKuota !== null && (aturanAnak?.DihitungBatasSku ?? true) && baru.length > sisaKuota;
    const terlaluBanyak = baru.length > MaksimalKombinasi;

    const Buat = () =>
        router.post(
            `/kelola/produk/${uuidProduk}/varian`,
            { AtributVarian: atribut, JenisAnak: jenisAnak, HargaDasar: bolehUbahHarga ? hargaDasar : '' },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: () => AturHargaDasar(''),
            },
        );

    return (
        <Panel judul="Buat varian" idJudul="judul-generator-varian">
            <PenyuntingAtributVarian
                nilai={atribut}
                saatBerubah={AturAtribut}
                galat={AmbilGalatBerawalan(galat, 'AtributVarian')}
            />
            {galat.AtributVarian ? (
                <p className="text-keterangan font-semibold text-bahaya">{galat.AtributVarian}</p>
            ) : null}
            <div className="grid gap-3 sm:grid-cols-2">
                <BidangPilihan
                    label="Jenis setiap varian"
                    nilai={jenisAnak}
                    opsi={jenis
                        .filter((item) => jenisAnakBoleh.includes(item.Nilai))
                        .map((item) => ({ Nilai: item.Nilai, Label: item.Label }))}
                    saatBerubah={(nilai) => AturJenisAnak(nilai as JenisProduk)}
                    galat={galat.JenisAnak}
                    required
                />
                {bolehUbahHarga ? (
                    <BidangUang
                        label="Harga jual varian baru (opsional)"
                        nilai={hargaDasar}
                        saatBerubah={AturHargaDasar}
                        keterangan="Sama untuk semua varian baru. Ubah per varian nanti bila perlu."
                        galat={galat.HargaDasar}
                    />
                ) : (
                    <p className="text-keterangan text-teks-sekunder">
                        Varian dibuat tanpa harga. Harga diisi oleh pengguna yang boleh mengubah harga produk.
                    </p>
                )}
            </div>
            <div aria-live="polite" className="flex flex-col gap-1 text-isi">
                <p className="text-teks-utama tabular-nums">
                    {baru.length === 0
                        ? 'Tidak ada kombinasi baru. Varian yang sudah ada tidak dibuat ulang.'
                        : `${String(baru.length)} varian baru akan dibuat: ${baru
                              .slice(0, 5)
                              .map((kombinasi) => kombinasi.join(' / '))
                              .join(', ')}${baru.length > 5 ? ', …' : ''}.`}
                </p>
                {terlaluBanyak ? (
                    <Pemberitahuan jenis="bahaya">
                        Maksimal {MaksimalKombinasi} varian baru sekali buat. Kurangi nilai atribut.
                    </Pemberitahuan>
                ) : null}
                {melebihiKuota ? (
                    <Pemberitahuan jenis="bahaya">
                        Sisa kuota paket {sisaKuota} produk, kurang untuk {baru.length} varian. Tidak ada varian yang
                        dibuat sampai kuota cukup.
                    </Pemberitahuan>
                ) : null}
            </div>
            <div>
                <Tombol
                    onClick={Buat}
                    memproses={memproses}
                    disabled={baru.length === 0 || terlaluBanyak || melebihiKuota}
                >
                    Buat {baru.length > 0 ? `${String(baru.length)} ` : ''}varian
                </Tombol>
            </div>
        </Panel>
    );
}
