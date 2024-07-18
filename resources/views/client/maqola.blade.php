@extends('client.base')
@section('head')
  <!-- Meta Tags for Journal Metadata -->
  <meta name="citation_title" content="{{ $article->title }}">
  <meta name="citation_author" content="{{ $article->author }}">
  <meta name="citation_abstract" content="{{ $article->abstract }}">
  <meta name="citation_keywords" content="{{ $article->keywords }}">
  <meta name="citation_journal_title" content="U-Science Journal">
  <meta name="citation_volume" content="{{ $article->volume }}">
  <meta name="citation_issue" content="">
  <meta name="citation_publication_date" content="{{ $article->created_at->format('Y-m-d') }}">
  <meta name="citation_doi" content="">
  <meta name="citation_issn" content="1234-5678"> <!-- Replace with actual ISSN -->
  <meta name="citation_pdf_url" content="{{ asset('storage/'.$article->pdf) }}">

  <!-- Add more metadata tags as needed -->
@section('content')
  <!-- Main content wrapper -->
  <div class="wrapper">
    <!-- Content Wrapper -->
    <div class="card content-wrapper2" style="margin: 2%">
      <!-- /.card-header -->
      <div class="card-body">
        <div style="text-align: center;">
          <h1>{{ $article->title }}</h1>
          <p>{{ $article->volume }}</p>
          <p>Muallifar: {{ $article->author }}</p>
          <div class="abstract">
            <p>{{ $article->abstract }}</p>
            <br/>
            <p><i><b>Kalit So`zlar:</b> {{ $article->keywords }}</i></p>
          </div>

          <a href="{{ asset('storage/'.$article->pdf) }}" download class="btn btn-primary">Download PDF</a>
        </div>
        <!--
        <iframe src="{{ Storage::url($article->pdf) }}" style="width: 100%; height: 600px;" frameborder="0"></iframe>
-->      
    </div>
      <!-- /.card-body -->
    </div>
    <!-- /.card -->
  </div>

  <!-- Footer -->
  <footer class="footer" id="footer">
    <div class="navbar-dark" style="text-align: center; color: white; padding: 10px;">
      Copyright &copy; <a href="http://urobot.uz/" target="_blank">Urobot.uz</a>
    </div>
  </footer>

  <!-- Styles -->
  <style>
    .content-wrapper2 {
      border: 1px solid #ccc;
      border-radius: 5px;
      box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
      padding: 20px;
      background-color: #fff;
      margin-bottom: 100px; /* Adjust margin bottom to accommodate footer */
    }

    .btn-primary {
      background-color: #007bff;
      color: #fff;
      border: none;
      padding: 10px 20px;
      text-decoration: none;
      border-radius: 5px;
    }

    .btn-primary:hover {
      background-color: #0056b3;
    }

    .footer {
      position: fixed;
      bottom: 0;
      width: 100%;
      text-align: center;
      background-color: #343a40;
      color: white;
      padding: 10px;
      display: none; /* Hide the footer by default */
      margin-top: 100px; /* Adjust margin bottom to accommodate footer */

    }

    /* Show footer when scrolled to the bottom */
    .show-footer #footer {
      display: block;
    }

    .abstract {
      padding: 10px;
      margin-top: 15px;
      text-align: left;
    }

    .abstract p {
      font-size: 16px;
      line-height: 1.6;
      margin-bottom: 0;
    }
  </style>
</body>
  <!-- Scripts -->
  <script>
    // Function to check if the user has scrolled to the bottom
    function checkScroll() {
      var scrollPosition = window.scrollY;
      var windowSize = window.innerHeight;
      var bodyHeight = document.body.offsetHeight;

      // Adjust the value here to determine when to show the footer
      if (scrollPosition + windowSize >= bodyHeight - 20) {
        document.body.classList.add('show-footer');
      } else {
        document.body.classList.remove('show-footer');
      }
    }

    // Listen for scroll events
    window.addEventListener('scroll', checkScroll);
  </script>
@endsection
