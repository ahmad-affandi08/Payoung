import type { ReactNode } from 'react';

import type { BagianSitus } from '@/Tipe/Situs';

import BagianBelumLengkap from './BagianBelumLengkap';
import BagianFormulirProspek from './BagianFormulirProspek';
import BagianHarga from './BagianHarga';
import BagianHero from './BagianHero';
import BagianHeroGeser from './BagianHeroGeser';
import { BagianIntegrasi, BagianLangkah } from './BagianLangkahIntegrasi';
import { BagianTabUsaha } from './BagianTabUsaha';
import { BagianKeunggulan, BagianLogoMitra, BagianSektor, BagianStatistik, BagianTestimoni } from './BagianKartu';
import {
    BagianCta,
    BagianFaq,
    BagianGambarTeks,
    BagianKontak,
    BagianTeksBebas,
    BagianUnduhAplikasi,
    BagianVideo,
} from './BagianLain';
import type { LatarBagian } from './KepalaBagian';

/** Blok yang mengatur latarnya sendiri dan tidak ikut irama terang/gelap. */
const LATAR_SENDIRI = new Set<BagianSitus['Jenis']>(['Hero', 'HeroGeser', 'Statistik', 'Cta']);

/** Setelah sebanyak ini bagian terang berturut-turut, satu bagian dibuat gelap sebagai jeda baca. */
const JEDA_GELAP_SETIAP = 4;

/**
 * Blok yang tidak pantas dibalik menjadi gelap (isi panjang atau berisi formulir/kartu putih). D-39: FAQ ikut,
 * karena hampir selalu tepat di atas CTA berlatar merek; FAQ Navy + CTA biru membuat dua balok gelap menempel.
 */
const SELALU_TERANG = new Set<BagianSitus['Jenis']>([
    'Harga',
    'TeksBebas',
    'FormulirProspek',
    'Video',
    'Faq',
    'TabUsaha',
]);

export type IramaBagian = { latar: LatarBagian; garisAtas: boolean };

/** Latar yang dirender sendiri oleh blok, dipakai agar bagian sesudahnya tahu harus mulai terang atau gelap. */
function LatarSendiri(bagian: BagianSitus): LatarBagian {
    if (bagian.Jenis === 'HeroGeser') {
        return 'permukaan';
    }

    if (bagian.Jenis === 'Hero') {
        return bagian.Latar === 'Navy' ? 'navy' : bagian.Latar === 'Merek' ? 'merek' : 'permukaan';
    }

    return 'merek';
}

/**
 * Irama latar tiap blok (D-25).
 *
 * Sebelumnya bagian hanya berselang-seling token `Latar`/`Permukaan`, padahal kedua nada itu hanya beda
 * beberapa persen sehingga batas bagian praktis tidak terlihat dan halaman melebur. Sekarang:
 *
 * 1. bagian terang tetap berselang-seling, dan **setiap bagian terang yang mengikuti bagian terang lain
 *    diberi garis 1px** sehingga batasnya selalu terlihat tanpa menambah warna;
 * 2. setiap {@link JEDA_GELAP_SETIAP} bagian terang berturut-turut, satu bagian dibuat **gelap** (Navy)
 *    sebagai jeda baca — meniru blok full-bleed Square tanpa gradien;
 * 3. bagian pertama setelah blok berlatar sendiri menyesuaikan diri: setelah blok **gelap** ia mulai putih,
 *    setelah hero **terang** ia mulai `Latar`, supaya tidak ada dua permukaan putih yang menempel.
 *
 * Dua blok sejenis yang berdampingan otomatis berbeda latar karena selang-seling; bentuknya dibedakan
 * lewat `TataLetak` pada blok Keunggulan.
 */
export function HitungIrama(bagian: BagianSitus[]): IramaBagian[] {
    const hasil: IramaBagian[] = [];
    let terangBerturut = 0;
    let berikutnyaPermukaan = true;
    // null = bagian sebelumnya gelap atau mengatur latarnya sendiri, jadi tidak perlu garis pemisah.
    let terangSebelumnya: LatarBagian | null = null;

    const Putus = (latar: LatarBagian): void => {
        terangBerturut = 0;
        // Setelah blok gelap mata butuh permukaan putih; setelah hero terang mulai dari Latar agar berbeda.
        berikutnyaPermukaan = latar !== 'permukaan';
        terangSebelumnya = latar === 'permukaan' ? latar : null;
        hasil.push({ latar, garisAtas: false });
    };

    for (const b of bagian) {
        if (LATAR_SENDIRI.has(b.Jenis)) {
            Putus(LatarSendiri(b));

            continue;
        }

        if (terangBerturut >= JEDA_GELAP_SETIAP && !SELALU_TERANG.has(b.Jenis)) {
            Putus('navy');

            continue;
        }

        const latar: LatarBagian = berikutnyaPermukaan ? 'permukaan' : 'latar';
        berikutnyaPermukaan = !berikutnyaPermukaan;
        terangBerturut += 1;
        hasil.push({ latar, garisAtas: terangSebelumnya !== null });
        terangSebelumnya = latar;
    }

    return hasil;
}

/**
 * Mewarnai irama (D-93): bagian "latar" menjadi apricot lembut (peach) dan jeda gelap menjadi teal pekat, sehingga
 * deretan putih-krem-putih digantikan putih-peach-putih-teal. Nuansa teal lembut tidak dipakai sebagai pita karena palet
 * Slate Teal yang kalem membuatnya terbaca abu-abu. Irama putus/garis dari {@link HitungIrama} tidak berubah.
 */
export function WarnaiIrama(bagian: BagianSitus[], irama: IramaBagian[]): LatarBagian[] {
    return bagian.map((b, i) => {
        const dasar = irama[i]?.latar ?? 'permukaan';

        if (b.Jenis === 'Hero') {
            return dasar;
        }

        if (dasar === 'latar') {
            return 'aksen';
        }

        return dasar === 'navy' ? 'brand' : dasar;
    });
}

/** Mode penyunting editor visual (D-63): blok bisa diklik untuk memilihnya di editor. */
export type PenyuntingBlok = { terpilih: number | null; saatPilih: (indeks: number) => void };

/** Pembungkus blok di pratinjau editor: sorot saat disorot/dipilih, klik memilih blok (tautan & formulir dimatikan). */
function BlokPenyunting({
    indeks,
    penyunting,
    children,
}: {
    indeks: number;
    penyunting: PenyuntingBlok;
    children: ReactNode;
}) {
    const terpilih = penyunting.terpilih === indeks;

    return (
        <div
            data-blok={indeks}
            className={`relative cursor-pointer outline-offset-[-3px] transition-[outline-color] hover:outline-3 hover:outline-brand/40 ${
                terpilih ? 'outline-3 outline-aksen hover:outline-aksen' : ''
            }`}
            onClickCapture={(p) => {
                p.preventDefault();
                p.stopPropagation();
                penyunting.saatPilih(indeks);
            }}
            onSubmitCapture={(p) => p.preventDefault()}
        >
            {children}
        </div>
    );
}

/** Render daftar blok halaman situs (D-21). Blok Hero pertama memakai `<h1>`. */
export default function RenderBagian({ bagian, penyunting }: { bagian: BagianSitus[]; penyunting?: PenyuntingBlok }) {
    const irama = HitungIrama(bagian);
    const warna = WarnaiIrama(bagian, irama);
    const Bungkus = (i: number, isi: ReactNode) =>
        penyunting ? (
            <BlokPenyunting key={`p-${i}`} indeks={i} penyunting={penyunting}>
                {isi}
            </BlokPenyunting>
        ) : (
            isi
        );

    return (
        <>
            {bagian.map((b, i) => {
                const kunci = `${b.Jenis}-${i}`;
                const garisAtas = irama[i]?.garisAtas ?? false;
                const latar: LatarBagian = warna[i] ?? 'permukaan';

                switch (b.Jenis) {
                    case 'Hero':
                        return Bungkus(i, <BagianHero key={kunci} bagian={b} utama={i === 0} />);
                    case 'HeroGeser':
                        return Bungkus(i, <BagianHeroGeser key={kunci} bagian={b} utama={i === 0} />);
                    case 'TabUsaha':
                        return Bungkus(
                            i,
                            <BagianTabUsaha key={kunci} bagian={b} latar={latar} garisAtas={garisAtas} />,
                        );
                    case 'Langkah':
                        return Bungkus(i, <BagianLangkah key={kunci} bagian={b} latar={latar} garisAtas={garisAtas} />);
                    case 'Integrasi':
                        return Bungkus(
                            i,
                            <BagianIntegrasi key={kunci} bagian={b} latar={latar} garisAtas={garisAtas} />,
                        );
                    case 'Keunggulan':
                        return Bungkus(
                            i,
                            <BagianKeunggulan key={kunci} bagian={b} latar={latar} garisAtas={garisAtas} />,
                        );
                    case 'Sektor':
                        return Bungkus(i, <BagianSektor key={kunci} bagian={b} latar={latar} garisAtas={garisAtas} />);
                    case 'GambarTeks':
                        return Bungkus(
                            i,
                            <BagianGambarTeks key={kunci} bagian={b} latar={latar} garisAtas={garisAtas} />,
                        );
                    case 'Statistik':
                        return Bungkus(i, <BagianStatistik key={kunci} bagian={b} />);
                    case 'Testimoni':
                        return Bungkus(
                            i,
                            <BagianTestimoni key={kunci} bagian={b} latar={latar} garisAtas={garisAtas} />,
                        );
                    case 'Harga':
                        return Bungkus(i, <BagianHarga key={kunci} bagian={b} latar={latar} garisAtas={garisAtas} />);
                    case 'Faq':
                        return Bungkus(i, <BagianFaq key={kunci} bagian={b} latar={latar} garisAtas={garisAtas} />);
                    case 'Cta':
                        return Bungkus(i, <BagianCta key={kunci} bagian={b} />);
                    case 'TeksBebas':
                        return Bungkus(
                            i,
                            <BagianTeksBebas key={kunci} bagian={b} latar={latar} garisAtas={garisAtas} />,
                        );
                    case 'LogoMitra':
                        return Bungkus(
                            i,
                            <BagianLogoMitra key={kunci} bagian={b} latar={latar} garisAtas={garisAtas} />,
                        );
                    case 'Video':
                        return Bungkus(i, <BagianVideo key={kunci} bagian={b} latar={latar} garisAtas={garisAtas} />);
                    case 'UnduhAplikasi':
                        return Bungkus(
                            i,
                            <BagianUnduhAplikasi key={kunci} bagian={b} latar={latar} garisAtas={garisAtas} />,
                        );
                    case 'Kontak':
                        return Bungkus(i, <BagianKontak key={kunci} bagian={b} latar={latar} garisAtas={garisAtas} />);
                    case 'FormulirProspek':
                        return Bungkus(
                            i,
                            <BagianFormulirProspek key={kunci} bagian={b} latar={latar} garisAtas={garisAtas} />,
                        );
                    case 'BelumLengkap':
                        return Bungkus(i, <BagianBelumLengkap key={kunci} label={b.Label} />);
                    default:
                        return null;
                }
            })}
        </>
    );
}
