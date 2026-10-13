import {
    BagianRingkasan as Bagian,
    BarisRingkasan as Baris,
    TampilYaTidak,
} from '@/Komponen/Formulir/Wizard/RingkasanWizard';
import { LabelTigaKeadaan } from '@/Komponen/Katalog/BantuanKatalog';
import { RingkasBarisHarga } from '@/Komponen/Katalog/TabelHargaBertingkat';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatMasukanJumlah } from '@/Pustaka/MasukanJumlah';
import {
    labelGolonganObat,
    type AturanJenisProduk,
    type FormProduk,
    type OpsiKategori,
    type OpsiKelompokPajak,
    type OpsiSatuan,
    type OpsiStokAwalProduk,
} from '@/Tipe/Katalog';

import type { KunciLangkah, LangkahProduk } from './LangkahProduk';

type PropsRingkasanProduk = {
    data: FormProduk;
    aturan: AturanJenisProduk | undefined;
    langkah: LangkahProduk[];
    kategori: OpsiKategori[];
    opsiSatuan: OpsiSatuan[];
    kelompokPajak: OpsiKelompokPajak[];
    pengaturan: { HargaTermasukPajakOutlet: string; StokBolehMinus: boolean };
    lokasiStok: OpsiStokAwalProduk['Lokasi'];
    /** Isian "Jual sebagai paket sesi" berlaku untuk produk ini. */
    bolehPaketSesi: boolean;
    /** Isian "Stok sekarang" berlaku untuk produk ini. */
    bolehStokAwal: boolean;
    saatUbah: (kunci: KunciLangkah) => void;
};

const labelPelacakan = { Tidak: 'Tidak dilacak', Batch: 'Nomor batch', Seri: 'Nomor seri' } as const;

/**
 * Ringkasan baca-saja langkah "Tinjau & simpan": semua isian penting per langkah, dengan tombol "Ubah" yang kembali
 * ke langkah itu. Hanya langkah yang berlaku untuk jenis produk ini yang tampil.
 */
export default function RingkasanProduk({
    data,
    aturan,
    langkah,
    kategori,
    opsiSatuan,
    kelompokPajak,
    pengaturan,
    lokasiStok,
    bolehPaketSesi,
    bolehStokAwal,
    saatUbah,
}: PropsRingkasanProduk) {
    const CariLangkah = (kunci: KunciLangkah) => langkah.find((item) => item.Kunci === kunci);
    const AmbilNamaSatuan = (uuid: string) => {
        const opsi = opsiSatuan.find((item) => item.Uuid === uuid);

        return opsi ? `${opsi.Nama} (${opsi.Simbol})` : 'Belum dipilih';
    };
    const dijual = (aturan?.BisaDijual ?? false) && aturan?.Nilai !== 'IndukVarian';
    const stok = data.StokAwal ?? null;
    const lokasi = lokasiStok.find((item) => item.Uuid === stok?.UuidGudang);
    const pajak = kelompokPajak.find((item) => item.Uuid === data.UuidKelompokPajak);
    const langkahJenis = CariLangkah('JenisNama');
    const langkahSatuan = CariLangkah('Satuan');
    const langkahHarga = CariLangkah('HargaStok');
    const langkahVarian = CariLangkah('Varian');
    const langkahPajak = CariLangkah('Pajak');

    return (
        <div className="flex flex-col gap-4">
            {langkahJenis ? (
                <Bagian langkah={langkahJenis} saatUbah={saatUbah}>
                    <Baris nama="Jenis produk">{aturan?.Label ?? data.Jenis}</Baris>
                    <Baris nama="Nama produk">{data.Nama}</Baris>
                    {data.NamaStruk.trim() !== '' ? <Baris nama="Nama di struk">{data.NamaStruk}</Baris> : null}
                    <Baris nama="SKU">
                        {data.Sku.trim() !== '' ? <span className="font-mono">{data.Sku}</span> : 'Dibuat otomatis'}
                    </Baris>
                    {data.Merek.trim() !== '' ? <Baris nama="Merek">{data.Merek}</Baris> : null}
                    <Baris nama="Kategori">
                        {kategori.find((item) => item.Uuid === data.UuidKategori)?.Jalur ?? 'Tanpa kategori'}
                    </Baris>
                </Bagian>
            ) : null}
            {langkahSatuan ? (
                <Bagian langkah={langkahSatuan} saatUbah={saatUbah}>
                    <Baris nama="Satuan dasar">{AmbilNamaSatuan(data.UuidSatuanDasar)}</Baris>
                    {data.Satuan.slice(1).map((baris, indeks) => (
                        <Baris
                            key={`${baris.UuidSatuan}-${String(indeks)}`}
                            nama={`Satuan alternatif ${String(indeks + 1)}`}
                        >
                            {AmbilNamaSatuan(baris.UuidSatuan)}, isi {FormatMasukanJumlah(baris.KonversiKeDasar)}{' '}
                            {opsiSatuan.find((item) => item.Uuid === data.UuidSatuanDasar)?.Simbol ?? ''}
                        </Baris>
                    ))}
                    {data.Satuan.map((baris, indeks) =>
                        baris.Barcode.length > 0 ? (
                            <Baris
                                key={`barcode-${baris.UuidSatuan}-${String(indeks)}`}
                                nama={`Barcode ${opsiSatuan.find((item) => item.Uuid === baris.UuidSatuan)?.Simbol ?? ''}`}
                            >
                                <span className="font-mono break-all">{baris.Barcode.join(', ')}</span>
                            </Baris>
                        ) : null,
                    )}
                    {data.Satuan.every((baris) => baris.Barcode.length === 0) ? (
                        <Baris nama="Barcode">Belum ada</Baris>
                    ) : null}
                </Bagian>
            ) : null}
            {langkahHarga ? (
                <Bagian langkah={langkahHarga} saatUbah={saatUbah}>
                    {dijual
                        ? data.Satuan.map((baris, indeks) => {
                              const opsi = opsiSatuan.find((item) => item.Uuid === baris.UuidSatuan);

                              return baris.HargaAwal.length > 0 ? (
                                  <Baris
                                      key={`harga-${baris.UuidSatuan}-${String(indeks)}`}
                                      nama={`Harga jual per ${opsi?.Simbol ?? 'satuan'}`}
                                  >
                                      {baris.HargaAwal.map((tingkat) => (
                                          <span key={tingkat.JumlahMinimum} className="block tabular-nums">
                                              {data.PaketSesi ? 'Harga paket: ' : ''}
                                              {baris.HargaAwal.length === 1 && tingkat.JumlahMinimum === '1'
                                                  ? FormatRupiah(tingkat.Harga)
                                                  : RingkasBarisHarga(tingkat, opsi?.Simbol ?? 'satuan')}
                                          </span>
                                      ))}
                                  </Baris>
                              ) : null;
                          })
                        : null}
                    {dijual && data.HargaTerbuka ? <Baris nama="Harga diketik kasir">Ya</Baris> : null}
                    {dijual && data.Satuan.every((baris) => baris.HargaAwal.length === 0) ? (
                        <Baris nama="Harga jual">
                            {data.HargaTerbuka ? 'Diketik kasir saat menjual' : 'Belum diisi'}
                        </Baris>
                    ) : null}
                    {bolehPaketSesi && data.PaketSesi ? (
                        <Baris nama="Paket sesi">
                            {data.PaketSesi.JumlahSesi} sesi
                            {data.PaketSesi.MasaBerlakuHari !== ''
                                ? `, berlaku ${data.PaketSesi.MasaBerlakuHari} hari`
                                : ', tanpa masa berlaku'}
                        </Baris>
                    ) : null}
                    {aturan?.BolehPelacakan ? (
                        <Baris nama="Pelacakan stok">{labelPelacakan[data.Pelacakan]}</Baris>
                    ) : null}
                    {data.Jenis === 'Resep' && data.LokasiProduksi ? (
                        <Baris nama="Lokasi produksi">{data.LokasiProduksi}</Baris>
                    ) : null}
                    {data.Pelacakan === 'Seri' && data.MasaGaransiBulan ? (
                        <Baris nama="Masa garansi">{data.MasaGaransiBulan} bulan</Baris>
                    ) : null}
                    {data.GolonganObat ? (
                        <Baris nama="Golongan obat">{labelGolonganObat[data.GolonganObat]}</Baris>
                    ) : null}
                    {bolehStokAwal && stok !== null && stok.Jumlah.trim() !== '' ? (
                        <Baris nama="Stok sekarang">
                            <span className="tabular-nums">
                                {FormatMasukanJumlah(stok.Jumlah)}{' '}
                                {opsiSatuan.find((item) => item.Uuid === data.UuidSatuanDasar)?.Simbol ?? ''}
                            </span>
                            {lokasi ? ` di ${lokasi.NamaOutlet ? `${lokasi.NamaOutlet} | ` : ''}${lokasi.Nama}` : ''}
                            <span className="block tabular-nums text-teks-sekunder">
                                Harga beli {FormatRupiah(stok.HargaBeli === '' ? '0' : stok.HargaBeli)} per satuan
                            </span>
                        </Baris>
                    ) : null}
                </Bagian>
            ) : null}
            {langkahVarian ? (
                <Bagian langkah={langkahVarian} saatUbah={saatUbah}>
                    {data.AtributVarian.length === 0 ? (
                        <Baris nama="Atribut">Belum ada. Atribut bisa ditambah setelah produk disimpan.</Baris>
                    ) : (
                        data.AtributVarian.map((atribut, indeks) => (
                            <Baris key={`${atribut.Nama}-${String(indeks)}`} nama={atribut.Nama}>
                                {atribut.Nilai.filter((nilai) => nilai.trim() !== '').join(', ')}
                            </Baris>
                        ))
                    )}
                </Bagian>
            ) : null}
            {langkahPajak ? (
                <Bagian langkah={langkahPajak} saatUbah={saatUbah}>
                    <Baris nama="Kelompok pajak">
                        {pajak ? `${pajak.Nama} | ${pajak.LabelKategori}` : 'Belum dipilih'}
                    </Baris>
                    <Baris nama="Harga sudah termasuk pajak">
                        {LabelTigaKeadaan(
                            data.HargaTermasukPajak,
                            `Ikuti pengaturan outlet (${pengaturan.HargaTermasukPajakOutlet})`,
                        )}
                    </Baris>
                    {aturan?.PunyaStok ? (
                        <Baris nama="Boleh dijual saat stok kosong">
                            {LabelTigaKeadaan(
                                data.BolehMinus,
                                `Ikuti pengaturan usaha (${pengaturan.StokBolehMinus ? 'boleh' : 'tidak boleh'})`,
                            )}
                        </Baris>
                    ) : null}
                    <Baris nama="Tampil di kasir (POS)">{TampilYaTidak(data.TampilDiPos)}</Baris>
                    {data.TampilDiPos ? <Baris nama="Favorit di kasir">{TampilYaTidak(data.Favorit)}</Baris> : null}
                    <Baris nama="Tampil di toko online">{TampilYaTidak(data.TampilOnline)}</Baris>
                    {data.DurasiMenit ? <Baris nama="Durasi layanan">{data.DurasiMenit} menit</Baris> : null}
                    {data.KodeBarangJasaCoretax ? (
                        <Baris nama="Kode barang/jasa Coretax">
                            <span className="font-mono">{data.KodeBarangJasaCoretax}</span>
                        </Baris>
                    ) : null}
                    {data.KodeUnitCoretax ? (
                        <Baris nama="Kode satuan Coretax">
                            <span className="font-mono">{data.KodeUnitCoretax}</span>
                        </Baris>
                    ) : null}
                </Bagian>
            ) : null}
        </div>
    );
}
