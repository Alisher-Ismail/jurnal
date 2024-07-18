@extends('client.base')
@section('head')
  <!-- Meta Tags for Journal Metadata -->
  <meta name="citation_title" content="{{ $articl->title }}">
  <meta name="citation_author" content="{{ $articl->author }}">
  <meta name="citation_abstract" content="{{ $articl->abstract }}">
  <meta name="citation_keywords" content="{{ $articl->keywords }}">
  <meta name="citation_journal_title" content="U-Science Journal">
  <meta name="citation_volume" content="{{ $articl->volume }}">
  <meta name="citation_issue" content="">
  <meta name="citation_publication_date" content="{{ $articl->created_at->format('Y-m-d') }}">
  <meta name="citation_doi" content="">
  <meta name="citation_issn" content="1234-5678"> <!-- Replace with actual ISSN -->
  <meta name="citation_pdf_url" content="{{ asset('storage/'.$articl->pdf) }}">
@section('content')
  <!-- /.navbar -->
  <!-- Main content wrapper -->
  <div class="wrapper">
    <!-- Content Wrapper -->
    <div class="card content-wrapper2" style="margin: 2%">
      <div class="card-header">
        <h3 class="card-title">U-Science Jurnalidagi Maqolalar</h3>
      </div>
      <!-- /.card-header -->
      <div class="card-body">
        <form action="" name="shartnoma" id="shartnoma" method="post">
          <table id="example1" class="navbar-dark table table-bordered table-striped">
            <thead>
              <tr style="color: #343a40;">
                    <th>Volume</th>
                    <th>Maqola Nomi</th>
                    <th>Kalit So`zlar</th>
                    <th>Mualliflar</th>
                    <th>Annotatsiya/Abstrakt</th>
                    <th>Pdf</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($article as $v)
              <tr>
                    <td><a href="{{ route('maqola', $v->id) }}" target="_blank">{{ $v->volume }}</a></td>
                    <td><a href="{{ route('maqola', $v->id) }}" target="_blank">{{ $v->title }}</a></td>
                    <td><a href="{{ route('maqola', $v->id) }}" target="_blank">{{ $v->keywords }}</a></td>
                    <td><a href="{{ route('maqola', $v->id) }}" target="_blank">{{ $v->author }}</a></td>
                    <td><a href="{{ route('maqola', $v->id) }}" target="_blank">{{ Str::limit($v->abstract, 150) }}</a></td>
                    <td><a href="{{ asset('storage/'.$v->pdf) }}" download>Download PDF</a></td>
              </tr>
              @endforeach
            </tbody>
          </table>
        </form>
      </div>
      <!-- /.card-body -->
    </div>
    <!-- /.card -->

    <!-- Footer -->
    
  </div>

  <!-- Scripts -->
  
<div class="navbar-dark" style="position: fixed; bottom: 0; width: 100%; text-align: center; color: white; padding: 10px;">Copyright <a href="http://urobot.uz/" target="_blank" >Urobot.uz </div>
<style>
 a {
      color: white; /* Text color set to white for all <a> tags */
      text-decoration: none; /* Remove default underline */
    }

    a:hover {
      color: #e0e0e0; /* Lighter color on hover */
    }
  </style>
</body>
@endsection
