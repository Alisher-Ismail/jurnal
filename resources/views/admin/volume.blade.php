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
            $.ajax({
                type: "post",
                url: "{{ route('volume.store') }}",
                cache: false,
                data: $('#material').serialize(),
                success: function(response) {
                    try {
                        //alert(response);
                        $('#suss_message2').html("<center><h5 style='color:white'>" + response + " </h5></center>");
                        $('#suss_message2').fadeIn().delay(1200).fadeOut();
                        window.setTimeout(function() {
                            window.location.href = "{{ route('volume.index') }}";
                        }, 4000);
                    } catch (e) {
                        alert('Exception while request1: ' + e);
                    }
                },
                error: function(xhr, status, error) {
                console.log('Error:', xhr.responseText); // Log the full response text
                console.log('Status:', status); // Log the status
                console.log('Error:', error); // Log any additional error message
                alert('Error while request: ' + error); // Display an alert with the error message
}

            });
        }
        }

    function ValidateCar() 
    {
    var Vaqt = document.material.volume;

	if (V_vaqt(Vaqt))
    {							
					return true;
	  }
    return false;

			function V_vaqt(Vaqt){
            var add = Vaqt.value.length;
            if (add == 0)
            {
                $('#suss_message').html("<center><h5 style='color:#ffffff;'>Kiriting!</h5></center>");
                $('#suss_message').fadeIn().delay(1200).fadeOut();
                //alert('First name must have alphabet characters only');
                Vaqt.focus();
                return false;
            }
            else
            {
                return true;
            }
        }		
    }
//delete
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
            url: "{{ route('volume.delete') }}",
            data: {
                selectedIds: selectedIds,
                _token: "{{ csrf_token() }}"
            },
            success: function(response) {
                // Handle success response
                $('#suss_message').html("<center><h5 style='color:white'>" + response + " </h5></center>");
                $('#suss_message').fadeIn().delay(1200).fadeOut();
                setTimeout(function() {
                    window.location.href = "{{ route('volume.index') }}";
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
            <h3 class="card-title">Volume Nomerini Kiriting</h3>
          </div>
          <div id="suss_message" class="ajax_warning" style="display: none"></div>
        <div id="suss_message2" class="ajax_Message" style="display: none"></div>
          <!-- /.card-header -->
          <div class="card-body">
            <div class="row">
              <div class="col-md-6">
              <form class="form-horizontal" name="material" id="material">
              @csrf <!-- CSRF token -->
               
            <!-- /.form-group -->
            <div class="form-group">
                <label>Volume Nomerini Kiriting</label>
                <input type="text" name="volume" id="volume" class="form-control" min="1">
            </div>
            </div>             

            <!-- /.col -->
            </div>
                  <!-- /.row -->
          </div>
          <!-- /.card-body -->
          <div class="card-footer">
          <button type="button" class="btn btn-primary" onclick="material_db()">Saqlash</button>
          </div>
  </form>
        </div>

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
                    <th>Tahrirlash</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($volume as $v)
              
                <tr>
                    <td><input class="checkbox1" type="checkbox" name="selectedItems[]" value="{{ $v->id }}"></td>
                    <td>{{ $v->name }}</td>
                    <td>
                    <a href="{{ route('volume.edit', $v->id) }}" class="btn btn-secondary"><i class='fas fa-edit'></i></a>
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
