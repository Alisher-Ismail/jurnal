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
                type: "put",
                url: "{{ route('volume.update') }}",
                cache: false,
                data: $('#material').serialize(),
                success: function(response) {
                    try {
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
                    console.log('Error:', xhr.responseText);
                    console.log('Status:', status);
                    console.log('Error:', error);
                    alert('Error while request: ' + error);
                }
            });
        }
    }

    function ValidateCar() {
        var Vaqt = document.material.volume;
        if (V_vaqt(Vaqt)) {							
            return true;
        }
        return false;

        function V_vaqt(Vaqt){
            var add = Vaqt.value.length;
            if (add == 0) {
                $('#suss_message').html("<center><h5 style='color:#ffffff;'>Kiriting!</h5></center>");
                $('#suss_message').fadeIn().delay(1200).fadeOut();
                Vaqt.focus();
                return false;
            } else {
                return true;
            }
        }		
    }

    function deleteSelectedItems() {
        var selectedIds = [];

        $('.checkbox1:checked').each(function() {
            selectedIds.push($(this).val());
        });

        if (selectedIds.length === 0) {
            $('#suss_message').html("<center><h5 style='color:white'>Tanlang!</h5></center>");
            $('#suss_message').fadeIn().delay(1200).fadeOut();
        } else {
            $.ajax({
                type: 'delete',
                url: "{{ route('volume.delete') }}",
                data: {
                    selectedIds: selectedIds,
                    _token: "{{ csrf_token() }}"
                },
                success: function(response) {
                    $('#suss_message').html("<center><h5 style='color:white'>" + response + " </h5></center>");
                    $('#suss_message').fadeIn().delay(1200).fadeOut();
                    setTimeout(function() {
                        window.location.href = "{{ route('volume.index') }}";
                    }, 4000);
                },
                error: function(xhr, status, error) {
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
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <form class="form-horizontal" name="material" id="material">
                    @csrf
                    <div class="form-group">
                        <label>Volume Nomerini Kiriting</label>
                        <input type="text" name="volume" id="volume" value="{{$volum->name}}" class="form-control" min="1">
                        <input type="hidden" name="vid" value="{{$volum->id}}">
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="card-footer">
        <button type="button" id="submit" class="btn btn-primary" onclick="material_db()">Saqlash</button>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Qabul Vaqtlari</h3>
    </div>
    <div class="card-body">
        <form action="" name="shartnoma" id="shartnoma" method="post">
            @csrf
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
    <button style="float: right; margin-right: 1%; width: 200px" type="button" id="submit" class="btn btn-danger" onclick="deleteSelectedItems()">O`chirish</button>
</div>
@endsection
