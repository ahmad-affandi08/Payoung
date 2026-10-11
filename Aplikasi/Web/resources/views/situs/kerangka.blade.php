{{--
    Kerangka server untuk <div id="app"> situs pemasaran (lihat KerangkaHalamanSitus). Kelas Tailwind yang dipakai
    harus sama dengan komponen React-nya (BagianHero/BagianHeroGeser, TataLetakSitus) supaya peralihan nyaris tak terlihat;
    berkas ini tercakup @source di Gaya/Situs.css. React mengganti seluruh isi #app begitu halaman siap.
--}}
<div data-kerangka class="bg-latar">
    <header class="border-b border-garis bg-permukaan">
        <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:h-20">
            <a href="/" class="text-judul font-bold text-teks-utama">{{ $namaSitus }}</a>
            <a href="{{ $tombolDaftar['Tautan'] }}" class="inline-flex min-h-11 items-center justify-center rounded-full bg-aksen px-4 text-isi font-semibold text-teks-utama">{{ $tombolDaftar['Label'] }}</a>
        </div>
    </header>
    @if ($pembuka)
        <main class="bg-brand-gelap">
            <div class="mx-auto grid max-w-6xl items-center gap-8 px-4 pt-12 pb-10 sm:pt-20 sm:pb-12 lg:grid-cols-[0.9fr_1.2fr] lg:gap-10">
                <div class="flex min-w-0 flex-col items-start gap-5">
                    @if ($pembuka['Label'])
                        <p class="rounded-full bg-aksen px-3 py-1 text-label font-semibold text-teks-utama">{{ $pembuka['Label'] }}</p>
                    @endif
                    <h1 class="text-sorotan-hp font-bold text-permukaan sm:text-sorotan">{{ $pembuka['Judul'] }}</h1>
                    @if ($pembuka['Teks'])
                        <p class="text-pengantar max-w-xl whitespace-pre-line text-brand-gelap-teks">{{ $pembuka['Teks'] }}</p>
                    @endif
                    @if ($pembuka['TombolUtama'] || $pembuka['TombolKedua'])
                        <div class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row">
                            @if ($pembuka['TombolUtama'])
                                <a href="{{ $pembuka['TombolUtama']['Tautan'] }}" class="inline-flex min-h-12 items-center justify-center rounded-full bg-aksen px-6 text-subjudul font-semibold text-teks-utama">{{ $pembuka['TombolUtama']['Label'] }}</a>
                            @endif
                            @if ($pembuka['TombolKedua'])
                                <a href="{{ $pembuka['TombolKedua']['Tautan'] }}" class="inline-flex min-h-12 items-center justify-center rounded-full border border-brand-gelap-teks px-6 text-subjudul font-semibold text-permukaan">{{ $pembuka['TombolKedua']['Label'] }}</a>
                            @endif
                        </div>
                    @endif
                </div>
                <div class="flex aspect-[4/3] w-full items-center justify-center overflow-hidden rounded-[2rem] bg-brand p-3 sm:aspect-[16/11] sm:p-6">
                    @php($urlGambar = $pembuka['Gambar'] ? ($pembuka['Gambar']['Url'] ?? \App\Domain\Situs\Layanan\KerangkaHalamanSitus::UrlBerkas($pembuka['Gambar']['Berkas'] ?? null)) : null)
                    @if ($urlGambar)
                        <img src="{{ $urlGambar }}" alt="{{ $pembuka['Gambar']['Alt'] }}" @if ($pembuka['Gambar']['Lebar']) width="{{ $pembuka['Gambar']['Lebar'] }}" @endif @if ($pembuka['Gambar']['Tinggi']) height="{{ $pembuka['Gambar']['Tinggi'] }}" @endif fetchpriority="high" decoding="async" class="max-h-full w-auto max-w-full rounded-kontrol object-contain">
                    @endif
                </div>
            </div>
        </main>
    @endif
    <noscript>
        <div class="mx-auto max-w-3xl px-4 py-10">{!! $ringkasan !!}</div>
    </noscript>
</div>
