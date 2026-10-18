import { KeAkurasiMeter, KeTeksKoordinat } from '@/Fitur/Absensi/SidikWajah';

export type Lokasi = { Lintang: string; Bujur: string; AkurasiMeter: number };

/** Lama maksimal menunggu GPS mencapai akurasi yang baik sebelum memakai hasil terbaik yang ada. */
const BATAS_TUNGGU_GPS_MS = 15_000;
const AKURASI_CUKUP_METER = 25;

/** Lokasi GPS terbaik dalam [BATAS_TUNGGU_GPS_MS]; selesai lebih cepat begitu akurasinya cukup. */
export function AmbilLokasi(): Promise<Lokasi> {
    return new Promise((berhasil, gagal) => {
        if (!('geolocation' in navigator)) {
            gagal(new Error('HP ini tidak mendukung lokasi. Pakai browser lain (Chrome atau Safari).'));

            return;
        }

        let terbaik: GeolocationPosition | null = null;
        const Selesai = () => {
            navigator.geolocation.clearWatch(pantau);
            clearTimeout(batas);

            if (terbaik) {
                berhasil({
                    Lintang: KeTeksKoordinat(terbaik.coords.latitude),
                    Bujur: KeTeksKoordinat(terbaik.coords.longitude),
                    AkurasiMeter: KeAkurasiMeter(terbaik.coords.accuracy),
                });
            } else {
                gagal(new Error('Lokasi tidak ditemukan. Nyalakan GPS, lalu coba lagi di dekat pintu atau jendela.'));
            }
        };
        const pantau = navigator.geolocation.watchPosition(
            (posisi) => {
                if (!terbaik || posisi.coords.accuracy < terbaik.coords.accuracy) {
                    terbaik = posisi;
                }

                if (posisi.coords.accuracy <= AKURASI_CUKUP_METER) {
                    Selesai();
                }
            },
            (kesalahan) => {
                if (kesalahan.code === kesalahan.PERMISSION_DENIED) {
                    navigator.geolocation.clearWatch(pantau);
                    clearTimeout(batas);
                    gagal(
                        new Error(
                            'Izin lokasi ditolak. Izinkan lokasi untuk situs ini di pengaturan browser, lalu coba lagi.',
                        ),
                    );
                }
            },
            { enableHighAccuracy: true, maximumAge: 0, timeout: BATAS_TUNGGU_GPS_MS },
        );
        const batas = setTimeout(Selesai, BATAS_TUNGGU_GPS_MS);
    });
}
