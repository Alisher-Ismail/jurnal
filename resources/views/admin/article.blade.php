@extends('admin.base')

@section('content')
<script>
    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });

    function material_db() {
        if (ValidateCar()){
        $("#submit").prop("disabled", true);
        var formData = new FormData($('#material')[0]); // Create FormData object

        $.ajax({
            type: "POST",
            url: "{{ route('article.store') }}",
            data: formData,
            processData: false, // Important for FormData
            contentType: false, // Important for FormData
            success: function(response) {
                $('#suss_message2').html("<center><h5 style='color:white'>" + response + " </h5></center>");
                $('#suss_message2').fadeIn().delay(1200).fadeOut();
                window.setTimeout(function() {
                    window.location.href = "{{ route('article.index') }}";
                }, 4000);
            },
            error: function(xhr, status, error) {
                console.log('Error:', xhr.responseText);
                alert('Error while request: ' + error);
            }
        });
    }
    }
//delete
//validate
function ValidateCar() 
    {
    var volume = document.material.volume;
    var title = document.material.title;
    var keyword = document.material.keyword;
    var author = document.material.author;
    var abstract = document.material.abstract;
    var pdf = document.material.pdf;

	if (A_volume(volume))
    {	
        if (A_title(title))
    {	
        if (A_keyword(keyword))
    {	
        if (A_author(author))
    {	
        if (A_abstract(abstract))
    {	
        if (A_pdf(pdf))
    {							
					return true;
	  }
    }}}}}
    return false;

    function A_pdf(pdf){
            var add = pdf.value.length;
            if (add == 0)
            {
                $('#suss_message').html("<center><h5 style='color:#ffffff;'>Pdf Faylni Tanlang!</h5></center>");
                $('#suss_message').fadeIn().delay(1200).fadeOut();
                //alert('First name must have alphabet characters only');
                pdf.focus();
                return false;
            }
            else
            {
                return true;
            }
        }
        
        function A_abstract(abstract){
            var add = abstract.value.length;
            if (add == 0)
            {
                $('#suss_message').html("<center><h5 style='color:#ffffff;'>Kiriting!</h5></center>");
                $('#suss_message').fadeIn().delay(1200).fadeOut();
                //alert('First name must have alphabet characters only');
                abstract.focus();
                return false;
            }
            else
            {
                return true;
            }
        }

    
    function A_author(author){
            var add = author.value.length;
            if (add == 0)
            {
                $('#suss_message').html("<center><h5 style='color:#ffffff;'>Kiriting!</h5></center>");
                $('#suss_message').fadeIn().delay(1200).fadeOut();
                //alert('First name must have alphabet characters only');
                author.focus();
                return false;
            }
            else
            {
                return true;
            }
        }

    function A_keyword(keyword){
            var add = keyword.value.length;
            if (add == 0)
            {
                $('#suss_message').html("<center><h5 style='color:#ffffff;'>Kiriting!</h5></center>");
                $('#suss_message').fadeIn().delay(1200).fadeOut();
                //alert('First name must have alphabet characters only');
                keyword.focus();
                return false;
            }
            else
            {
                return true;
            }
        }	
        
        function A_title(title){
            var add = title.value.length;
            if (add == 0)
            {
                $('#suss_message').html("<center><h5 style='color:#ffffff;'>Kiriting!</h5></center>");
                $('#suss_message').fadeIn().delay(1200).fadeOut();
                //alert('First name must have alphabet characters only');
                title.focus();
                return false;
            }
            else
            {
                return true;
            }
        }	

			function A_volume(volume){
            var add = volume.value.length;
            if (add == 0)
            {
                $('#suss_message').html("<center><h5 style='color:#ffffff;'>Kiriting!</h5></center>");
                $('#suss_message').fadeIn().delay(1200).fadeOut();
                //alert('First name must have alphabet characters only');
                volume.focus();
                return false;
            }
            else
            {
                return true;
            }
        }		
    }
//validate
function deleteSelectedItems() {
    var selectedIds = [];

    // Loop through all checkboxes and add the checked ones to selectedIds array
    $('.checkbox1:checked').each(function() {
        selectedIds.push($(this).val());
    });

    if (selectedIds.length === 0) {
        // Show message if no items are selected
        $('#suss_message').html("<center><h5 style='color:white'>Tanlang!</h5></center>");
        $('#suss_message').fadeIn().delay(1200).fadeOut();
    } else {
        // Send AJAX request to delete selected items
        $.ajax({
            type: 'delete',
            url: "{{ route('article.delete') }}",
            data: {
                selectedIds: selectedIds,
                _token: "{{ csrf_token() }}"
            },
            success: function(response) {
                // Handle success response
                $('#suss_message').html("<center><h5 style='color:white'>" + response + " </h5></center>");
                $('#suss_message').fadeIn().delay(1200).fadeOut();
                setTimeout(function() {
                    window.location.href = "{{ route('article.index') }}";
                }, 4000);
            },
            error: function(xhr, status, error) {
                // Handle error
                console.error(error);
                alert('Error sending selected IDs to controller: ' + error);
            }
        });
    }
}
</script>

<div class="card card-default">
    <div class="card-header">
        <h3 class="card-title">Maqolani Kiriting</h3>
    </div>
    <div id="suss_message" class="ajax_warning" style="display: none"></div>
    <div id="suss_message2" class="ajax_Message" style="display: none"></div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <form class="form-horizontal" name="material" id="material" method="POST" action="{{ route('article.store') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="form-group row">
                        <label for="volume" class="col-sm-3 col-form-label">Volume</label>
                        <div class="col-sm-9">
                            <input type="text" class="form-control" name="volume" id="volume" placeholder="Volume">
                        </div>

                        <label for="title" class="col-sm-3 col-form-label">Maqola Nomi</label>
                        <div class="col-sm-9">
                            <input type="text" class="form-control" name="title" id="title" placeholder="Maqola Nomi">
                        </div>

                        <label for="keyword" class="col-sm-3 col-form-label">Kalit So`zlar</label>
                        <div class="col-sm-9">
                            <input type="text" class="form-control" name="keyword" id="keyword" placeholder="Kalit So`zlar">
                        </div>

                        <label for="author" class="col-sm-3 col-form-label">Mualliflar</label>
                        <div class="col-sm-9">
                            <input type="text" class="form-control" name="author" id="author" placeholder="Mualliflar">
                        </div>
                    </div>
                    
                    <div class="form-group row">
                        <label for="abstract" class="col-sm-3 col-form-label">Abstract</label>
                        <div class="col-sm-9">
                            <textarea class="form-control" name="abstract" id="abstract" placeholder="Abstract"></textarea>
                        </div>
                    </div>
                    <div class="form-group row">
                        <label for="pdf" class="col-sm-3 col-form-label">Pdf</label>
                        <div class="col-sm-9">
                            <input type="file" class="form-control" name="pdf" id="pdf" placeholder="PDF">
                        </div>
                    </div>
                    
                </form>
            </div>
            
        </div>
        
    </div>
    <div class="card-footer">
                        <button type="button" class="btn btn-primary" onclick="material_db()">Saqlash</button>
                    </div>
</div>

<!--table-->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Qabul Vaqtlari</h3>
    </div>
    <!-- /.card-header -->
    <div class="card-body">
    <form action="" name="shartnoma" id="shartnoma" method="post">
    @csrf <!-- CSRF token -->

        <table id="example1" class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th style="width: 5%"><input type="checkbox" id="selecctall" /></th>
                    <th>Volume</th>
                    <th>Maqola Nomi</th>
                    <th>Kalit So`zlar</th>
                    <th>Mualliflar</th>
                    <th>Abstract</th>
                    <th>Pdf</th>
                    <th>Tahrirlash</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($article as $v)
              
                <tr>
                    <td><input class="checkbox1" type="checkbox" name="selectedItems[]" value="{{ $v->id }}"></td>
                    <td>{{ $v->volume }}</td>
                    <td>{{ $v->title }}</td>
                    <td>{{ $v->keywords }}</td>
                    <td>{{ $v->author }}</td>
                    <td>{{ Str::limit($v->abstract, 100) }}</td>
                    <td><a href="{{ asset('storage/'.$v->pdf) }}" download>Download PDF</a></td>
                    <td>
                    <a href="{{ route('article.edit', $v->id) }}" class="btn btn-secondary"><i class='fas fa-edit'></i></a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
            </form>
    </div>
    <!-- /.card-body -->
    <button style="float: right; margin-right: 1%; width: 200px" type="button" id="submit" class="btn btn-danger" onclick="deleteSelectedItems()">O`chirish</button>

</div>
@endsection
