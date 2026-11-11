@extends('Surel.TataLetak')

@section('Judul', 'Tiket baru '.$Nomor)
@section('Pratinjau', '['.$Prioritas.'] '.$Judul.' dari '.$NamaTenant.'. Batas respons pertama '.$BatasSla.'.')

@section('Isi')
    <p style="margin:0 0 20px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Halo Tim Dukungan, ada tiket baru yang perlu ditangani.</p>

    @include('Surel.Komponen.Rincian', ['Baris' => [
        'Nomor' => $Nomor,
        'Judul' => $Judul,
        'Tenant' => $NamaTenant,
        'Kategori' => $Kategori,
        'Prioritas' => $Prioritas,
        'Batas respons pertama (SLA)' => $BatasSla,
    ]])

    @include('Surel.Komponen.Tombol', ['Url' => $Tautan, 'Label' => 'Buka tiket di Platform Pengelola'])
@endsection
