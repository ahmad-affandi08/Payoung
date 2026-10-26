import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import RingkasanGalatFormulir from '@/Komponen/PanduanAwal/RingkasanGalatFormulir';
import { Button } from '@/Komponen/Ui/button';
import { FieldDescription, FieldError, FieldLegend, FieldSet } from '@/Komponen/Ui/field';
import type { KategoriPajakProduk, PropsBuatKelompokPajak, PropsDaftarKelompokPajak } from '@/Tipe/Katalog';

type KelompokPajak = PropsDaftarKelompokPajak['KelompokPajak'][number];
type IsianKelompokPajak = {
    Nama: string;
    Kategori: KategoriPajakProduk;
    Pajak: { KodeJenisPajak: string; DasarPengenaan: string; KenaBiayaKirim: boolean }[];
};

const kodePpn = 'Ppn';
const kodePbjt = 'PbjtMakananMinuman';

/** Kategori tanpa rincian pajak (DesainF03 C.3 SimpanKelompokPajak). */
export function CekKategoriTanpaPajak(kategori: KategoriPajakProduk): boolean {
    return kategori === 'BebasPpn' || kategori === 'NonPajak';
}

/** Cermin aturan konsistensi KelompokPajakTidakSesuai di peramban; null = sesuai. Server tetap penentu. */
export function PeriksaKonsistensiPajak(kategori: KategoriPajakProduk, kode: string[]): string | null {
    const adaPpn = kode.includes(kodePpn);
    const adaPbjt = kode.includes(kodePbjt);

    if (new Set(kode).size !== kode.length) {
        return 'Jenis pajak yang sama tidak boleh dipilih dua kali.';
    }

    switch (kategori) {
        case 'KenaPpn':
            return adaPpn && !adaPbjt ? null : 'Kategori Kena PPN wajib memuat PPN dan tidak boleh memuat PBJT.';
        case 'KenaPbjt':
            return adaPbjt && !adaPpn
                ? null
                : 'Kategori Kena PBJT wajib memuat PBJT makanan & minuman dan tidak boleh memuat PPN.';
        case 'BebasPpn':
        case 'NonPajak':
            return kode.length === 0 ? null : 'Kategori ini tidak memakai rincian pajak.';
        case 'Lainnya':
            return adaPpn || adaPbjt ? 'Kategori Lainnya untuk pajak selain PPN dan PBJT.' : null;
    }
}

type PropsFormKelompokPajak = {
    kelompok: KelompokPajak | null;
    /** Opsi jenis pajak, kategori, dan dasar pengenaan dari server. */
    props: PropsBuatKelompokPajak;
    /** Dipanggil setelah tersimpan (panel ubah menutup diri). Halaman buat tidak memakainya: server mengarahkan. */
    saatSelesai?: () => void;
    saatBatal: () => void;
};

function BarisPajakKosong(props: PropsBuatKelompokPajak): IsianKelompokPajak['Pajak'][number] {
    return { KodeJenisPajak: '', DasarPengenaan: props.DasarPengenaan[0]?.Nilai ?? '', KenaBiayaKirim: false };
}

/** Formulir kelompok pajak (E.8): dipakai halaman penuh Tambah dan panel samping Ubah di halaman daftar. */
export default function FormKelompokPajak({ kelompok, props, saatSelesai, saatBatal }: PropsFormKelompokPajak) {
    const formulir = useForm<IsianKelompokPajak>({
        Nama: kelompok?.Nama ?? '',
        Kategori: kelompok?.Kategori ?? 'KenaPbjt',
        // Kelompok baru langsung punya satu baris pajak untuk diisi (kategori bawaan wajib memuat pajak).
        Pajak: kelompok?.Pajak.map((item) => ({
            KodeJenisPajak: item.KodeJenisPajak,
            DasarPengenaan: item.DasarPengenaan,
            KenaBiayaKirim: item.KenaBiayaKirim,
        })) ?? [BarisPajakKosong(props)],
    });
    const data = formulir.data;
    const galat = formulir.errors as Record<string, string | undefined>;
    const [periksa, AturPeriksa] = useState(false);
    const tanpaPajak = CekKategoriTanpaPajak(data.Kategori);
    const galatKonsistensi = periksa
        ? PeriksaKonsistensiPajak(
              data.Kategori,
              data.Pajak.map((item) => item.KodeJenisPajak),
          )
        : null;
    const AturPajak = (pajak: IsianKelompokPajak['Pajak']) => formulir.setData((lama) => ({ ...lama, Pajak: pajak }));

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        AturPeriksa(true);

        if (
            PeriksaKonsistensiPajak(
                data.Kategori,
                data.Pajak.map((item) => item.KodeJenisPajak),
            ) !== null
        ) {
            return;
        }

        const opsi = { preserveScroll: true, onSuccess: () => saatSelesai?.() };

        if (kelompok === null) {
            formulir.post('/kelola/kelompok-pajak', opsi);
        } else {
            formulir.put(`/kelola/kelompok-pajak/${kelompok.Uuid}`, opsi);
        }
    };

    return (
        <form
            onSubmit={Kirim}
            noValidate
            aria-label={kelompok ? `Ubah kelompok pajak ${kelompok.Nama}` : 'Tambah kelompok pajak'}
            className="flex flex-col gap-4"
        >
            <RingkasanGalatFormulir galat={{ ...galat, ...(galatKonsistensi ? { Pajak: galatKonsistensi } : {}) }} />
            <div className="grid gap-3 sm:grid-cols-2">
                <BidangTeks
                    label="Nama kelompok pajak"
                    nilai={data.Nama}
                    saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                    galat={galat.Nama}
                    keterangan="Misal Makan & minum, Barang retail, atau Jasa."
                    maxLength={100}
                    autoFocus
                    required
                />
                <BidangPilihan
                    label="Kategori pajak"
                    nilai={data.Kategori}
                    opsi={props.Kategori}
                    saatBerubah={(nilai) =>
                        formulir.setData((lama) => ({
                            ...lama,
                            Kategori: nilai as KategoriPajakProduk,
                            Pajak: CekKategoriTanpaPajak(nilai as KategoriPajakProduk)
                                ? []
                                : lama.Pajak.length === 0
                                  ? [BarisPajakKosong(props)]
                                  : lama.Pajak,
                        }))
                    }
                    galat={galat.Kategori}
                    required
                />
            </div>
            {tanpaPajak ? (
                <p className="text-isi text-teks-sekunder">Produk di kelompok ini dijual tanpa pajak.</p>
            ) : (
                <FieldSet className="gap-2">
                    <FieldLegend variant="label" className="mb-0 text-label font-semibold text-teks-utama">
                        Pajak yang dikenakan (berurutan)
                    </FieldLegend>
                    <FieldDescription className="text-keterangan">
                        Tarif pajak mengikuti ketentuan resmi dan berganti sendiri bila aturannya berubah.
                    </FieldDescription>
                    {data.Pajak.map((pajak, indeks) => (
                        <div key={indeks} className="border-t border-garis pt-3">
                            <div className="grid items-end gap-2 sm:grid-cols-[1fr_1fr_auto]">
                                <BidangPilihan
                                    label={`Jenis pajak ${String(indeks + 1)}`}
                                    nilai={pajak.KodeJenisPajak}
                                    kosong="Pilih jenis pajak"
                                    opsi={props.JenisPajak.map((item) => ({
                                        Nilai: item.Kode,
                                        Label: `${item.Nama} (${item.Cakupan})`,
                                    }))}
                                    saatBerubah={(nilai) =>
                                        AturPajak(
                                            data.Pajak.map((item, i) =>
                                                i === indeks ? { ...item, KodeJenisPajak: nilai } : item,
                                            ),
                                        )
                                    }
                                    galat={galat[`Pajak.${String(indeks)}.KodeJenisPajak`]}
                                    required
                                />
                                <BidangPilihan
                                    label={`Dasar pengenaan ${String(indeks + 1)}`}
                                    nilai={pajak.DasarPengenaan}
                                    kosong="Pilih dasar pengenaan"
                                    opsi={props.DasarPengenaan}
                                    saatBerubah={(nilai) =>
                                        AturPajak(
                                            data.Pajak.map((item, i) =>
                                                i === indeks ? { ...item, DasarPengenaan: nilai } : item,
                                            ),
                                        )
                                    }
                                    galat={galat[`Pajak.${String(indeks)}.DasarPengenaan`]}
                                    required
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => AturPajak(data.Pajak.filter((_, i) => i !== indeks))}
                                    className="h-8 pointer-coarse:h-11 text-destructive"
                                    aria-label={`Hapus pajak ${String(indeks + 1)}`}
                                >
                                    Hapus
                                </Button>
                            </div>
                            <KotakCentang
                                label={`Ongkir kena pajak ${String(indeks + 1)}`}
                                nilai={pajak.KenaBiayaKirim}
                                saatBerubah={(nilai) =>
                                    AturPajak(
                                        data.Pajak.map((item, i) =>
                                            i === indeks ? { ...item, KenaBiayaKirim: nilai } : item,
                                        ),
                                    )
                                }
                            />
                        </div>
                    ))}
                    <p>
                        <Tombol
                            type="button"
                            varian="sekunder"
                            onClick={() => AturPajak([...data.Pajak, BarisPajakKosong(props)])}
                        >
                            Tambah pajak
                        </Tombol>
                    </p>
                </FieldSet>
            )}
            <div aria-live="polite">
                {(galatKonsistensi ?? galat.Pajak) ? (
                    <FieldError className="text-keterangan font-semibold">{galatKonsistensi ?? galat.Pajak}</FieldError>
                ) : null}
            </div>
            <div className="flex flex-wrap gap-2">
                <Tombol type="submit" memproses={formulir.processing}>
                    Simpan kelompok pajak
                </Tombol>
                <Tombol type="button" varian="sekunder" onClick={saatBatal}>
                    Batal
                </Tombol>
            </div>
        </form>
    );
}
